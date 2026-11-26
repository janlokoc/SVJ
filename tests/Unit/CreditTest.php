<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Kredit služeb (app/ext/credit_lib.php): pohyby a zůstatek, atomické čerpání bez záporného zůstatku, šarže FIFO s platností,
 * upozornění na nízký zůstatek, dobití zálohovou fakturou (přímo i přes partnera), konzole a ceník;
 * doplňky k ročnímu předplatnému (app/platform_lib.php: addon_*), včetně faktury partnera.
 */
final class CreditTest extends TestCase
{
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) {
                pq('DELETE FROM platform_settings WHERE key = ?', [$k]);
            } else {
                pq('INSERT INTO platform_settings (key, value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$k, $v]);
            }
        }
        pset('__reset');
        $this->saved = [];
        pq('DELETE FROM credit_ledger WHERE tenant = ?', [TENANT]);
        pq('DELETE FROM credit_state WHERE tenant = ?', [TENANT]);
    }

    private function pset(string $key, ?string $value): void
    {
        if (!array_key_exists($key, $this->saved)) {
            $r = pq('SELECT value FROM platform_settings WHERE key = ?', [$key])->fetch();
            $this->saved[$key] = $r ? (string) $r['value'] : null;
        }
        if ($value === null) {
            pq('DELETE FROM platform_settings WHERE key = ?', [$key]);
            pset('__reset');
        } else {
            pset_save($key, $value);
        }
    }

    private function house(array $over = []): array
    {
        $slug = 'kr-' . bin2hex(random_bytes(3));
        tenant_create($over + ['slug' => $slug, 'name' => 'SVJ ' . $slug, 'title' => $slug, 'billing' => 1, 'owner_email' => $slug . '@example.cz']);
        return tenant_row($slug);
    }

    private function partner(): array
    {
        $u = bin2hex(random_bytes(3));
        pq('INSERT INTO partners (name, ico, address, email, created_at) VALUES (?,?,?,?,?)', ['Správa ' . $u . ' s.r.o.', '12345678', 'Hlavní 1', 'firma.' . $u . '@example.org', date('Y-m-d H:i:s')]);
        return partner_row((int) platform_db()->lastInsertId());
    }

    private function attach(array $p, array $t): array
    {
        pq("UPDATE tenants SET partner_id = ?, partner_status = 'active', partner_billing = 1 WHERE slug = ?", [$p['id'], $t['slug']]);
        return tenant_row($t['slug']);
    }

    private function decodedMails(): array
    {
        return array_map(fn($m) => ['to' => strtolower((string) $m['to']), 'subject' => mb_decode_mimeheader((string) $m['subject']), 'body' => quoted_printable_decode((string) $m['body'])], $this->mails());
    }

    private function rows(string $tenant): array
    {
        return pq('SELECT * FROM credit_ledger WHERE tenant = ? ORDER BY id', [$tenant])->fetchAll();
    }

    // ---------- Schéma a nastavení ----------

    public function testSchemaAndDefaults(): void
    {
        $this->assertGreaterThanOrEqual(28, (int) platform_db()->query('PRAGMA user_version')->fetchColumn());
        $cols = array_column(platform_db()->query('PRAGMA table_info(credit_ledger)')->fetchAll(), 'name');
        foreach (['id', 'tenant', 'at', 'delta_h', 'kind', 'note', 'by_whom', 'lot_id', 'expires_at', 'ref', 'qty'] as $c) {
            $this->assertContains($c, $cols);
        }
        $this->assertContains('addons', array_column(platform_db()->query('PRAGMA table_info(payments)')->fetchAll(), 'name'));
        $this->assertSame(['credit_valid_months' => 24, 'credit_low_h' => 10000, 'bankid_price' => 19, 'price_online_meeting' => 490, 'sms_free_premium' => 100], CREDIT_DEFAULTS);
        foreach (array_keys(CREDIT_DEFAULTS) as $k) {
            $this->pset($k, null);
            $this->assertSame(CREDIT_DEFAULTS[$k], credit_setting($k));
        }
        $this->pset('bankid_price', '25');
        $this->assertSame(25, credit_setting('bankid_price'));
        $this->pset('bankid_price', 'nesmysl');
        $this->assertSame(19, credit_setting('bankid_price'), 'neplatná hodnota = výchozí');
        $this->assertSame(['topup', 'sms', 'exekuce', 'bankid', 'online', 'refund', 'fix', 'expire', 'migrate'], array_keys(CREDIT_KINDS));
        $this->assertSame('1 234,50 Kč', str_replace("\u{a0}", ' ', credit_money(123450)));
        $this->assertSame('99 Kč', str_replace("\u{a0}", ' ', credit_money(9900)));
        $this->assertSame('−1,50 Kč', str_replace("\u{a0}", ' ', credit_money(-150)));
    }

    // ---------- Zůstatek a čerpání ----------

    public function testAddCreatesLotValidForConfiguredMonths(): void
    {
        $id = credit_add(TENANT, 50000, 'topup', 'faktura 1', 'op');
        $row = $this->rows(TENANT)[0];
        $this->assertSame([$id, 50000, 'topup', date('Y-m-d', strtotime('+24 month'))], [(int) $row['lot_id'], (int) $row['delta_h'], $row['kind'], $row['expires_at']]);
        $this->pset('credit_valid_months', '6');
        credit_add(TENANT, 100, 'fix', 'x');
        $this->assertSame(date('Y-m-d', strtotime('+6 month')), $this->rows(TENANT)[1]['expires_at']);
        $this->assertSame(50100, credit_balance_h(TENANT));
        $this->assertNull(credit_add(TENANT, 0, 'topup'));
        $this->assertNull(credit_add(TENANT, -5, 'topup'));
        $this->assertNull(credit_add(TENANT, 100, 'sms'), 'služba kredit nepřipisuje (vrácení jde přes credit_reverse)');
        $this->assertNull(credit_add('neexistuje', 100, 'topup'));
        $this->assertCount(2, $this->rows(TENANT));
    }

    public function testChargeIsAtomicAndNeverNegative(): void
    {
        credit_add(TENANT, 1000, 'topup', 'x');
        $this->assertFalse(credit_charge(TENANT, 1001, 'sms', 'víc než zůstatek'));
        $this->assertCount(1, $this->rows(TENANT), 'odmítnuté čerpání nic nezapíše');
        $this->assertTrue(credit_charge(TENANT, 0, 'sms', 'nula'));
        $this->assertFalse(credit_charge(TENANT, -1, 'sms', 'záporné'));
        $this->assertFalse(credit_charge(TENANT, 1, 'neznamy', 'druh'));
        $this->assertTrue(credit_charge(TENANT, 1000, 'exekuce', 'vše', 'ref-1', 1));
        $this->assertSame(0, credit_balance_h(TENANT));
        $this->assertFalse(credit_charge(TENANT, 1, 'sms', 'nic nezbývá'));
        $this->assertSame(0, credit_balance_h(TENANT));
        $this->assertTrue(credit_enough(TENANT, 0));
        $this->assertFalse(credit_enough(TENANT, 1));
        // Čerpání ve vnější transakci, která se odvolá, nezanechá nic.
        credit_add(TENANT, 500, 'topup', 'y');
        $r = credit_tx(function (): bool {
            credit_charge(TENANT, 200, 'sms', 'a');
            return credit_charge(TENANT, 400, 'sms', 'b');   // nestačí → celé zpět
        });
        $this->assertFalse($r);
        $this->assertSame(500, credit_balance_h(TENANT), 'odvolaná transakce vrátí i první čerpání');
    }

    public function testChargeTakesSoonestExpiringLotFirstAndReverseReturnsToSameLots(): void
    {
        $late = credit_add(TENANT, 500, 'topup', 'pozdější');
        $soon = credit_add(TENANT, 300, 'migrate', 'dřívější');
        pq('UPDATE credit_ledger SET expires_at = ? WHERE id = ?', [date('Y-m-d', strtotime('+3 month')), $soon]);
        $this->assertSame([$soon, $late], array_map('intval', array_column(credit_lots(TENANT), 'lot_id')), 'FIFO podle konce platnosti');
        $this->assertTrue(credit_charge(TENANT, 600, 'online', 'shromáždění', 'online:7', 1));
        $debits = array_values(array_filter($this->rows(TENANT), fn($r) => (int) $r['delta_h'] < 0));
        $this->assertSame([[$soon, -300, 1], [$late, -300, 0]], array_map(fn($r) => [(int) $r['lot_id'], (int) $r['delta_h'], (int) $r['qty']], $debits));
        $this->assertSame(600, credit_ref_charged(TENANT, 'online:7'));
        $this->assertSame(600, credit_reverse(TENANT, 'online:7', 'vráceno'));
        $this->assertSame(0, credit_ref_charged(TENANT, 'online:7'));
        $lots = array_column(credit_lots(TENANT), 'left_h', 'lot_id');
        $this->assertSame([$soon => 300, $late => 500], array_map('intval', $lots), 'vrácení do týchž šarží');
        $this->assertSame(0, credit_reverse(TENANT, 'online:7', 'podruhé'), 'vrátit jde jen jednou');
        $this->assertSame(0, credit_reverse(TENANT, '', 'bez ref'));
    }

    public function testExpiredRemainderIsWrittenOffAndCannotBeUsed(): void
    {
        $old = credit_add(TENANT, 500, 'topup', 'stará');
        credit_add(TENANT, 200, 'topup', 'nová');
        $this->assertTrue(credit_charge(TENANT, 100, 'sms', 'z nejstarší'));
        pq('UPDATE credit_ledger SET expires_at = ? WHERE lot_id = ?', [date('Y-m-d', strtotime('-1 day')), $old]);
        $this->assertFalse(credit_charge(TENANT, 300, 'sms', 'propadlé nejde použít'), 'zbývá jen 200 platných');
        $exp = array_values(array_filter($this->rows(TENANT), fn($r) => $r['kind'] === 'expire'));
        $this->assertSame([[$old, -400]], array_map(fn($r) => [(int) $r['lot_id'], (int) $r['delta_h']], $exp), 'propadne jen nevyčerpaný zbytek');
        $this->assertSame(200, credit_balance_h(TENANT));
        $this->assertSame(0, credit_expire(TENANT), 'podruhé už nic');
        credit_daily();
        $this->assertSame(200, credit_balance_h(TENANT));
        $this->assertSame([], credit_expiring(TENANT, 60));
        pq('UPDATE credit_ledger SET expires_at = ? WHERE lot_id <> ?', [date('Y-m-d', strtotime('+10 day')), $old]);
        $this->assertCount(1, credit_expiring(TENANT, 60));
    }

    public function testLowBalanceMailOnceAndAgainAfterTopUp(): void
    {
        $t = $this->house();
        $this->pset('credit_low_h', '10000');
        credit_add($t['slug'], 15000, 'topup', 'x');
        $low = fn() => array_values(array_filter($this->decodedMails(), fn($m) => str_starts_with($m['subject'], 'Dochází kredit služeb') && str_contains($m['subject'], $t['name'])));
        credit_charge($t['slug'], 4000, 'sms', 'a');
        $this->assertSame([], $low(), '110 Kč – ještě ne');
        credit_charge($t['slug'], 2000, 'sms', 'b');
        $this->assertNotEmpty($low());
        $this->assertContains($t['owner_email'], array_column($low(), 'to'));
        $this->assertStringContainsString('90 Kč', str_replace("\u{a0}", ' ', $low()[0]['body']));
        $this->assertStringContainsString('/admin/kredit', $low()[0]['body']);
        $n = count($low());
        credit_charge($t['slug'], 1000, 'sms', 'c');
        $this->assertCount($n, $low(), 'jen jednou');
        credit_add($t['slug'], 5000, 'topup', 'dobití');
        $this->assertSame('', credit_state($t['slug'])['low_warned'], 'po dobití nad hranici se upozornění obnoví');
        credit_charge($t['slug'], 5000, 'sms', 'd');
        $this->assertCount(2 * $n, $low());
        $this->pset('credit_low_h', '0');
        credit_add($t['slug'], 50000, 'topup', 'y');
        credit_charge($t['slug'], 59000, 'sms', 'e');
        $this->assertCount(2 * $n, $low(), 'hranice 0 = neupozorňovat');
    }

    // ---------- Dobití ----------

    public function testTopUpOrderConfirmInvoiceAndStorno(): void
    {
        $this->pset('account', '19-2000145399/0800');
        $this->pset('vat_payer', '1');
        $t = $this->house();
        $this->assertSame('Dobít lze 300 až 100 000 Kč.', str_replace("\u{a0}", ' ', (string) credit_topup_order($t, 299)));
        $this->assertIsString(credit_topup_order($t, 100001));
        $p = credit_topup_order($t, 500);
        $this->assertSame(['credit', 500, '500', 21], [$p['kind'], (int) $p['amount'], $p['item'], (int) $p['vat_rate']]);
        $this->assertSame(date('y') . sprintf('%06d', $p['id']), $p['vs']);
        $p2 = credit_topup_order($t, 1000);
        $this->assertSame($p['id'], $p2['id'], 'nezaplacená objednávka se přepíše');
        $this->assertSame(1000, (int) $p2['amount']);
        $v = invoice_view($p2);
        $this->assertStringStartsWith('Kredit služeb 1', $v['item']);
        $this->assertSame([], $v['lines']);
        $this->assertStringContainsString('<svg', $v['qr']);
        pq("UPDATE payments SET status = 'claimed' WHERE id = ?", [$p['id']]);
        $this->assertStringContainsString('nahlásili', (string) credit_topup_order($t, 300));
        $this->assertSame(0, credit_balance_h($t['slug']), 'do potvrzení platby se nic nepřipíše');

        $period = billing_confirm(pq('SELECT * FROM payments WHERE id = ?', [$p['id']])->fetch(), 'Admin');
        $this->assertSame(['from' => date('Y-m-d'), 'to' => date('Y-m-d')], $period);
        $paid = pq('SELECT * FROM payments WHERE id = ?', [$p['id']])->fetch();
        $this->assertSame('paid', $paid['status']);
        $this->assertMatchesRegularExpression('/^' . date('Y') . '\d{4}$/', $paid['invoice_no']);
        $this->assertSame(100000, credit_balance_h($t['slug']), 'kredit bez DPH');
        $this->assertStringContainsString($paid['invoice_no'], $this->rows($t['slug'])[0]['note']);
        $mail = array_values(array_filter($this->decodedMails(), fn($m) => $m['to'] === $t['owner_email'] && str_starts_with($m['subject'], 'Kredit služeb dobit')));
        $this->assertCount(1, $mail);
        $this->assertStringContainsString('1 210,00 Kč', str_replace("\u{a0}", ' ', $mail[0]['body']), 'zaplaceno s DPH');
        $this->assertNull(billing_confirm($paid, 'Admin'), 'podruhé nejde');
        $this->assertNull(credit_topup_payment($t));

        // Storno: odebere se nejvýš to, co zbývá.
        credit_charge($t['slug'], 30000, 'sms', 'spotřeba');
        [$no, $notes] = billing_storno($paid, 'Admin', 'omyl');
        $this->assertStringStartsWith('D', $no);
        $this->assertStringContainsString('odebráno 700 Kč', str_replace("\u{a0}", ' ', implode(' ', $notes)));
        $this->assertSame(0, credit_balance_h($t['slug']));
    }

    public function testTopUpForPartnerBilledHouseGoesOnPartnerInvoice(): void
    {
        $this->pset('partner_discounts', '1:10');
        $p = $this->partner();
        $h = $this->attach($p, $this->house());
        $other = $this->attach($p, $this->house());
        $this->assertTrue(credit_partner_billed($h));
        $pay = credit_topup_order($h, 800);
        $this->assertSame(['partner', (int) $p['id'], 800, 0], [$pay['kind'], (int) $pay['partner_id'], (int) $pay['amount'], (int) $pay['discount']], 'na kredit se sleva nevztahuje');
        $this->assertSame([['kind' => 'credit', 'price' => 800, 'slug' => $h['slug'], 'name' => $h['name']]], partner_lines($pay));
        $this->assertNull(credit_topup_payment($h), 'dům nedostane vlastní zálohovou fakturu');
        $this->assertSame(800, (int) credit_partner_pending($h)[1]['price']);
        $mail = array_values(array_filter($this->decodedMails(), fn($m) => $m['to'] === strtolower($p['email'])));
        $this->assertStringContainsString('Kredit služeb', $mail[0]['body']);
        // nová objednávka téhož domu řádek nahradí
        $pay = credit_topup_order($h, 1000);
        $this->assertCount(1, partner_lines($pay));
        $this->assertSame(1000, (int) $pay['amount']);
        // partner vystaví fakturu za edice – řádek kreditu zůstává, sleva jen z edic
        $std = edition_price('standard', $other);
        $pay = partner_order($p, [$other['slug'] => 'standard']);
        $disc = (int) round($std * 10 / 100);
        $this->assertSame([$std - $disc + 1000, $disc], [(int) $pay['amount'], (int) $pay['discount']]);
        $lines = partner_lines($pay);
        $this->assertSame(['standard', 'credit'], [$lines[0]['edition'], $lines[1]['kind']]);
        $v = partner_invoice_view($pay);
        $this->assertStringStartsWith('Kredit služeb pro ' . $h['name'], $v['lines'][1][0]);
        $this->assertSame((int) $pay['amount'], array_sum(array_column($v['lines'], 1)));
        // zaplaceno: edice prodloužena, kredit připsán
        partner_billing_confirm($pay, 'Admin');
        $this->assertSame(100000, credit_balance_h($h['slug']));
        $this->assertNotSame('', tenant_row($other['slug'])['paid_until']);
        $this->assertSame('', tenant_row($h['slug'])['paid_until'], 'dům, který jen dobil, se neprodlužuje');
        $paid = pq('SELECT * FROM payments WHERE id = ?', [$pay['id']])->fetch();
        $done = array_values(array_filter($this->decodedMails(), fn($m) => $m['to'] === strtolower($p['email']) && str_starts_with($m['subject'], 'Platba přijata')));
        $this->assertStringContainsString('1 domů', $done[0]['body']);
        $this->assertStringContainsString('Připsali jsme: kredit', $done[0]['body']);
        // storno faktury partnera kredit odebere
        $notes = partner_storno_revert($paid);
        $this->assertStringContainsString('Z kreditu služeb odebráno', implode(' ', $notes));
        $this->assertSame(0, credit_balance_h($h['slug']));
        // partner už nahlásil platbu → dům musí počkat
        $open = credit_topup_order($h, 300);
        pq("UPDATE payments SET status = 'claimed' WHERE id = ?", [$open['id']]);
        $this->assertStringContainsString('už nahlásil platbu', (string) credit_topup_order($h, 500));
    }

    public function testDemoHouseCannotTopUp(): void
    {
        $this->assertIsString(credit_topup_order(['slug' => 'demo', 'demo' => 1, 'partner_id' => 0], 500));
    }

    // ---------- Konzole ----------

    public function testConsoleFixRefundAndReports(): void
    {
        $t = $this->house();
        $this->assertNotNull(credit_fix('neexistuje', 100, 'x', 'op'));
        $this->assertNotNull(credit_fix($t['slug'], 0, 'x', 'op'));
        $this->assertNotNull(credit_fix($t['slug'], 100, '  ', 'op'), 'poznámka je povinná');
        $this->assertNull(credit_fix($t['slug'], 20000, 'kompenzace', 'op@example.cz'));
        $this->assertStringContainsString('pod nulu', (string) credit_fix($t['slug'], -20001, 'oprava', 'op'));
        $this->assertNull(credit_fix($t['slug'], -5000, 'oprava', 'op'));
        $this->assertSame(15000, credit_balance_h($t['slug']));
        credit_charge($t['slug'], 300, 'sms', 'sms', 'r1', 2);
        credit_charge($t['slug'], 9900, 'exekuce', 'lustrace', 'r2', 1);
        credit_charge($t['slug'], 150, 'sms', 'sms', 'r3', 1);
        credit_reverse($t['slug'], 'r3', 'neodesláno');
        $from = date('Y-m-01');
        $to = date('Y-m-d', strtotime($from . ' +1 month'));
        $rep = credit_usage_report($from, $to)[$t['slug']];
        $this->assertSame(['h' => 300, 'qty' => 2, 'n' => 2], $rep['sms'], 'vrácená SMS se odečte');
        $this->assertSame(['h' => 9900, 'qty' => 1, 'n' => 1], $rep['exekuce']);
        $this->assertSame([], credit_usage_report('2000-01-01', '2000-02-01'));
        $this->assertSame(4800, (int) credit_balances()[$t['slug']]['h']);
        $this->assertSame(4800, credit_refund_all($t['slug'], 'zrušení webu', 'op'));
        $this->assertSame(0, credit_balance_h($t['slug']));
        $this->assertSame('refund', $this->rows($t['slug'])[count($this->rows($t['slug'])) - 1]['kind']);
        $this->assertSame(0, credit_refund_all($t['slug'], 'podruhé', 'op'));
        $this->assertSame(-4800, credit_totals($from, $to)['refund'] ?? null);
        // nastavení cen v konzoli: klíče a aktuální hodnoty
        $f = credit_console_fields();
        foreach (['sms_price_h', 'rej_price_exekuce', 'bankid_price', 'price_online_meeting', 'rej_price_katastr_house', 'rej_price_katastr_lv_extra', 'credit_valid_months', 'credit_low_h', 'sms_free_premium'] as $k) {
            $this->assertArrayHasKey($k, $f);
        }
        $this->assertSame(490, ($f['price_online_meeting'][2])());
    }

    // ---------- Obrazovky ----------

    public function testMenuItemOnlyForHousesThatCanUseIt(): void
    {
        foreach (['sms', 'exekuce', 'bankid', 'shromazdeni-online'] as $k) {
            $this->pset('beta_' . $k, 'off');
        }
        $nav = $GLOBALS['EXT_ADMIN_NAV']['/admin/kredit'];
        $this->assertSame(['Kredit služeb', 'Správa webu'], [$nav[0], $nav[1]]);
        $this->assertFalse(($nav[2])());
        credit_add(TENANT, 100, 'fix', 'x');
        $this->assertTrue(($nav[2])(), 'dům, který kredit má, ho vidí vždy');
        pq('DELETE FROM credit_ledger WHERE tenant = ?', [TENANT]);
        $this->pset('beta_sms', 'beta');
        $this->assertTrue(($nav[2])());
    }

    public function testAdminPageRendersBalanceStatementAndTopUp(): void
    {
        $this->loginAs('admin');
        credit_add(TENANT, 50000, 'topup', 'faktura 1');
        credit_charge(TENANT, 150, 'sms', 'SMS admin', 'x', 1);
        $t = tenant_row(TENANT);
        extract(['t' => $t, 'balance' => credit_balance_h(TENANT), 'rows' => credit_statement(TENANT), 'payment' => null, 'qr' => '', 'partner' => null, 'partnerPending' => null,
            'expiring' => [], 'prices' => credit_price_list(), 'months' => 24, 'freeLeft' => 3, 'freeYear' => 100]);
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/credit/admin.php';
        $html = str_replace("\u{a0}", ' ', (string) ob_get_clean());
        $this->assertStringContainsString('498,50 Kč', $html);
        $this->assertStringContainsString('3 z 100 SMS', $html);
        $this->assertStringContainsString('SMS zprávy', $html);
        $this->assertStringContainsString('−1,50 Kč', $html);
        foreach (CREDIT_TOPUPS as $a) {
            $this->assertStringContainsString('value="' . $a . '"', $html);
        }
        $this->assertStringContainsString('name="custom" min="300"', $html);
        $this->assertStringContainsString('Lustrace v Centrální evidenci exekucí', $html);
    }

    public function testPublicPricingShowsServicesWithAndWithoutVat(): void
    {
        $this->pset('vat_payer', '1');
        $this->pset('vat_rate', '21');
        $this->pset('beta_exekuce', 'on');
        $this->pset('beta_bankid', 'off');
        $this->pset('beta_katastr', 'beta');
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/credit/pricing_rows.php';
        $html = str_replace("\u{a0}", ' ', (string) ob_get_clean());
        $this->assertStringContainsString('Služby z kreditu a doplňky', $html);
        $this->assertMatchesRegularExpression('~Lustrace v Centrální evidenci exekucí</strong><br>~', $html, 'spuštěná funkce bez štítku');
        $this->assertMatchesRegularExpression('~Ověření hlasu přes Bank iD</strong> <span class="tag">připravujeme</span>~', $html);
        $this->assertStringContainsString('99 Kč', $html);
        $this->assertStringContainsString('119,79 Kč', $html, '99 Kč + 21 % DPH');
        $this->assertStringContainsString('592,90 Kč', $html, 'online shromáždění 490 Kč s DPH');
        $this->assertStringContainsString('1 490 Kč', $html);
        $this->assertStringContainsString('1 802,90 Kč', $html, 'katastr s DPH');
        $this->assertStringContainsString('1,50 Kč', $html);
        $this->assertStringContainsString('Hlídání insolvencí vlastníků', $html);
        $this->assertStringContainsString('v ceně – Premium', $html);
        $this->assertStringContainsString('v ceně – všechny edice', $html);
        $this->assertStringContainsString('300 Kč', $html, 'nejmenší dobití');
    }

    // ---------- Doplňky k předplatnému ----------

    public function testProrateMath(): void
    {
        $until = '2027-06-30';
        $this->assertSame(1490, addon_prorate(1490, $until, '2026-07-01'), 'celý rok');
        $this->assertSame((int) round(1490 * 1 / 365), addon_prorate(1490, $until, $until), 'poslední den');
        $this->assertSame((int) round(1490 * 181 / 365), addon_prorate(1490, $until, '2027-01-01'), 'leden–červen = 181 dní');
        $this->assertSame(1490, addon_prorate(1490, $until, '2025-01-01'), 'nejvýš celá roční cena');
        $this->assertSame(0, addon_prorate(1490, $until, '2027-07-01'), 'po skončení období nic');
        $this->assertSame(0, addon_prorate(0, $until, '2027-01-01'));
        $this->assertSame(0, addon_prorate(1490, '', '2027-01-01'));
    }

    public function testAddonOnlyBilledForSubscribedHouses(): void
    {
        $this->assertFalse(addon_billable(null));
        $this->assertFalse(addon_billable(['billing' => 0, 'demo' => 0]));
        $this->assertFalse(addon_billable(['billing' => 1, 'demo' => 1]));
        $this->assertTrue(addon_billable(['billing' => 1, 'demo' => 0]));
        $t = $this->house(['billing' => 0]);
        $this->assertNull(addon_set($t, 'katastr', 2));
        $this->assertSame(2, (int) addon_row($t['slug'], 'katastr')['qty']);
        $this->assertSame([], addon_year_lines(tenant_row($t['slug'])), 'bez předplatného ani na roční faktuře');
        $this->assertNull(addon_set($t, 'neznamy', 1));
        $this->assertNull(addon_row($t['slug'], 'neznamy'));
    }

    public function testAddonWithoutPaidPeriodGoesOnNextSubscriptionInvoice(): void
    {
        $t = $this->house();   // edice Zdarma, bez zaplaceného období
        $this->assertNull(addon_set($t, 'katastr', 1), 'není z čeho počítat poměrnou část');
        $this->assertSame([['addon' => 'katastr', 'qty' => 1, 'name' => 'Sledování změn v katastru (1 LV)', 'price' => 1490, 'year' => true]], addon_year_lines(tenant_row($t['slug'])));
        $p = billing_order(tenant_row($t['slug']), 'premium');
        $prem = edition_price('premium', $t);
        $this->assertSame($prem + 1490, (int) $p['amount']);
        $this->assertSame(['Web společenství – edice Premium na 1 rok', $prem], invoice_view($p)['lines'][0]);
        billing_confirm($p, 'Admin');
        $row = tenant_row($t['slug']);
        $a = addon_row($t['slug'], 'katastr');
        $this->assertSame([$row['paid_until'], 1], [$a['paid_until'], (int) $a['paid_qty']], 'doplněk zaplacený s předplatným');
        $paid = pq('SELECT * FROM payments WHERE id = ?', [$p['id']])->fetch();
        $this->assertSame('Sledování změn v katastru (1 LV) v období ' . cz_date($paid['period_from']) . ' – ' . cz_date($paid['period_to']), invoice_view($paid)['lines'][1][0]);
        [, $notes] = billing_storno($paid, 'Admin', 'test');
        $this->assertStringContainsString('nezaplacený', implode(' ', $notes));
        $this->assertSame('', addon_row($t['slug'], 'katastr')['paid_until']);
    }

    public function testAddonWithReferralDiscountKeepsLinesConsistent(): void
    {
        $t = $this->house();
        pq('UPDATE tenants SET credit = 300 WHERE slug = ?', [$t['slug']]);
        addon_set(tenant_row($t['slug']), 'katastr', 2);
        $p = billing_order(tenant_row($t['slug']), 'standard');
        $lines = invoice_view($p)['lines'];
        $this->assertSame(['Sleva (kredit za doporučení)', -300], end($lines));
        $this->assertSame((int) $p['amount'], array_sum(array_column($lines, 1)));
    }

    public function testPartnerAnnualInvoiceIncludesAddonsAndProratedLine(): void
    {
        $this->pset('partner_discounts', '1:10');
        $p = $this->partner();
        $h = $this->attach($p, $this->house());
        $until = date('Y-m-d', strtotime('+100 day'));
        pq("UPDATE tenants SET paid_until = ?, plan = 'premium' WHERE slug = ?", [$until, $h['slug']]);
        // zapnutí v zaplaceném období → řádek na faktuře partnera, ne vlastní výzva domu
        $pay = addon_set(tenant_row($h['slug']), 'katastr', 1);
        $this->assertSame('partner', $pay['kind']);
        $this->assertNull(addon_payment($h, 'katastr'));
        $line = partner_lines($pay)[0];
        $this->assertSame(['addon', 'katastr', addon_prorate(1490, $until), $until], [$line['kind'], $line['addon'], (int) $line['price'], $line['to']]);
        $this->assertSame((int) $line['price'], (int) $pay['amount'], 'na doplněk se sleva nevztahuje');
        // roční faktura partnera: edice + doplněk, předchozí řádek za zbytek období zůstává
        $pay = partner_order($p, [$h['slug'] => 'premium']);
        $lines = partner_lines($pay);
        $this->assertSame([null, 'addon', 'addon'], array_map(fn($l) => $l['kind'] ?? null, $lines));
        $this->assertTrue($lines[1]['year']);
        $this->assertSame(1490, (int) $lines[1]['price']);
        $prem = edition_price('premium', $h);
        $disc = (int) round($prem * 10 / 100);
        $this->assertSame($prem - $disc + 1490 + (int) $line['price'], (int) $pay['amount']);
        $v = partner_invoice_view($pay);
        $this->assertSame($h['name'] . ': Sledování změn v katastru (1 LV) na 1 rok', $v['lines'][1][0]);
        $this->assertSame((int) $pay['amount'], array_sum(array_column($v['lines'], 1)));
        partner_billing_confirm($pay, 'Admin');
        $row = tenant_row($h['slug']);
        $this->assertSame(date('Y-m-d', strtotime($until . ' +12 month')), $row['paid_until']);
        $this->assertSame($row['paid_until'], addon_row($h['slug'], 'katastr')['paid_until'], 'doplněk zaplacený do konce nového období');
        $paid = pq('SELECT * FROM payments WHERE id = ?', [$pay['id']])->fetch();
        $this->assertStringContainsString(' v období ', partner_invoice_view($paid)['lines'][1][0]);
        $notes = partner_storno_revert($paid);
        $this->assertStringContainsString('nezaplacený', implode(' ', $notes));
    }

    public function testConsoleAndBillingCardsRender(): void
    {
        $t = $this->house();
        pq('UPDATE tenants SET paid_until = ?, plan = ? WHERE slug = ?', [date('Y-m-d', strtotime('+50 day')), 'premium', $t['slug']]);
        $t = tenant_row($t['slug']);
        $pay = addon_set($t, 'katastr', 2);
        credit_add($t['slug'], 30000, 'topup', 'faktura');
        credit_charge($t['slug'], 9900, 'exekuce', 'lustrace', 'z', 1);
        $month = date('Y-m');
        $from = $month . '-01';
        $to = date('Y-m-d', strtotime($from . ' +1 month'));
        $render = function (string $view, array $vars): string {
            extract($vars);
            ob_start();
            require dirname(__DIR__, 2) . '/app/views/' . $view . '.php';
            return str_replace("\u{a0}", ' ', (string) ob_get_clean());
        };
        $html = $render('ext/credit/console', ['month' => $month, 'names' => [$t['slug'] => $t['name']], 'balances' => credit_balances(), 'usage' => credit_usage_report($from, $to),
            'totals' => credit_totals($from, $to), 'rej' => [], 'fields' => credit_console_fields(),
            'addons' => pq('SELECT a.*, t.name FROM tenant_addons a LEFT JOIN tenants t ON t.slug = a.tenant WHERE a.active = 1 AND a.tenant = ?', [$t['slug']])->fetchAll(),
            'ledger' => credit_statement($t['slug'])]);
        $this->assertStringContainsString('201 Kč', $html, 'zůstatek 300 − 99 Kč');
        $this->assertStringContainsString('Lustrace exekucí', $html);
        $this->assertStringContainsString('Sledování změn v katastru (2 LV)', $html);
        $this->assertStringContainsString('2 480 Kč', $html);
        $this->assertStringContainsString('name="price_online_meeting"', $html);
        $this->assertStringContainsString('value="1.5"', $html, 'cena SMS v Kč');
        $this->assertStringContainsString('Vrátit zůstatek', $html);
        $html = $render('ext/credit/addons_card', ['t' => $t, 'iban' => null]);
        $this->assertStringContainsString('Doplňky k předplatnému', $html);
        $this->assertStringContainsString((string) $pay['vs'], $html);
        $this->assertStringContainsString('/admin/predplatne/doplnek', $html);
        $this->assertStringContainsString('/admin/predplatne/doklad/' . $pay['id'], $html);
    }
}
