<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Předplatné, DPH, platby, faktury, storno a doporučení (app/platform_lib.php, tenant_edition/edition_price z plan_lib.php). */
final class PlatformBillingTest extends TestCase
{
    private const NBSP = "\u{a0}";

    /** @var array<string, ?string> */
    private array $savedPsets = [];

    protected function tearDown(): void
    {
        foreach ($this->savedPsets as $key => $old) {
            if ($old === null) {
                pq('DELETE FROM platform_settings WHERE key = ?', [$key]);
                pset('__reset');
            } else {
                pset_save($key, $old);
            }
        }
        $this->savedPsets = [];
        parent::tearDown();
    }

    private function setPset(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->savedPsets)) {
            $v = pq('SELECT value FROM platform_settings WHERE key = ?', [$key])->fetchColumn();
            $this->savedPsets[$key] = $v === false ? null : (string) $v;
        }
        pset_save($key, $value);
    }

    private function newTenant(array $extra = []): array
    {
        $slug = 'tb-' . bin2hex(random_bytes(3));
        tenant_create($extra + ['slug' => $slug, 'name' => 'SVJ Test ' . $slug, 'title' => 'Test', 'billing' => 1, 'owner_email' => $slug . '@example.cz']);
        return tenant_row($slug);
    }

    /** Zachycené e-maily s dekódovaným předmětem a tělem (zachytává se hotová MIME zpráva). */
    private function decodedMails(): array
    {
        return array_map(fn($m) => ['subject' => mb_decode_mimeheader((string) $m['subject']), 'body' => quoted_printable_decode((string) $m['body'])] + $m, $this->mails());
    }

    private function payment(int $id): array
    {
        return pq('SELECT * FROM payments WHERE id = ?', [$id])->fetch();
    }

    private static function day(string $rel): string
    {
        return date('Y-m-d', strtotime($rel));
    }

    private function invoicesThisYear(): int
    {
        return (int) pq('SELECT COUNT(*) FROM payments WHERE invoice_no LIKE ?', [date('Y') . '%'])->fetchColumn();
    }

    // ---------- Částky a DPH ----------

    public function testMoneyFormatting(): void
    {
        $n = self::NBSP;
        $this->assertSame("0{$n}Kč", money_czk(0));
        $this->assertSame("999{$n}Kč", money_czk(999));
        $this->assertSame("2{$n}990{$n}Kč", money_czk(2990));
        $this->assertSame("1{$n}234{$n}567{$n}Kč", money_czk(1234567));
        $this->assertSame("3{$n}617,90{$n}Kč", money_czk2(3617.9));
        $this->assertSame("0,50{$n}Kč", money_czk2(0.5));
        $this->assertSame("1,01{$n}Kč", money_czk2(1.005 + 0.0001));
    }

    public function testVatSplit(): void
    {
        $this->assertSame(['base' => 100.0, 'vat' => 21.0, 'total' => 121.0, 'rate' => 21], vat_split(100, 21));
        $this->assertSame(['base' => 2990.0, 'vat' => 627.9, 'total' => 3617.9, 'rate' => 21], vat_split(2990, 21));
        $this->assertSame(['base' => 999.0, 'vat' => 119.88, 'total' => 1118.88, 'rate' => 12], vat_split(999, 12));
        $this->assertSame(['base' => 500.0, 'vat' => 0.0, 'total' => 500.0, 'rate' => 0], vat_split(500, 0));
        $this->assertSame(0.21, vat_split(1, 21)['vat']);
        $this->assertSame(0.01, vat_split(0.05, 21)['vat'], 'daň se zaokrouhluje na haléře');
    }

    public function testPayTotalAndMoney(): void
    {
        $n = self::NBSP;
        $this->assertSame(2990, pay_total(['amount' => 2990, 'vat_rate' => 0]));
        $this->assertSame(2990, pay_total(['amount' => '2990']), 'chybějící sazba = neplátce');
        $this->assertSame(3617.9, pay_total(['amount' => 2990, 'vat_rate' => 21]));
        $this->assertSame("2{$n}990{$n}Kč", pay_money(['amount' => 2990, 'vat_rate' => 0]));
        $this->assertSame("3{$n}617,90{$n}Kč", pay_money(['amount' => 2990, 'vat_rate' => 21]));
        $this->assertSame('', pay_vat_hint(['amount' => 2990, 'vat_rate' => 0]));
        $this->assertStringContainsString("2{$n}990{$n}Kč + DPH 21{$n}%", pay_vat_hint(['amount' => 2990, 'vat_rate' => 21]));
    }

    public function testVatPayerAndNote(): void
    {
        $this->setPset('vat_payer', '0');
        $this->assertFalse(vat_payer());
        $this->assertSame(0, vat_rate_now());
        $this->assertSame('', vat_note());
        $this->setPset('vat_payer', '1');
        $this->setPset('vat_rate', '21');
        $this->assertTrue(vat_payer());
        $this->assertSame(21, vat_rate_now());
        $this->assertStringContainsString('bez DPH', vat_note());
        $this->assertStringContainsString('DPH 21' . self::NBSP . '%', vat_note());
        $this->setPset('vat_rate', '12');
        $this->assertSame(12, vat_rate_now());
        $this->setPset('vat_rate', '250');
        $this->assertSame(100, vat_rate_now(), 'sazba je shora omezená na 100');
        $this->setPset('vat_payer', 'ano');
        $this->assertFalse(vat_payer(), 'plátce jen při hodnotě 1');
    }

    // ---------- QR platba ----------

    public function testSpaydFormat(): void
    {
        $this->assertSame('SPD*1.0*ACC:CZ6508000000192000145399*AM:2990.00*CC:CZK*X-VS:26000012*MSG:WEB SVJ TABOR',
            spayd('CZ6508000000192000145399', 2990, '26000012', 'Web SVJ tabor'));
        $this->assertSame('SPD*1.0*ACC:CZ1*AM:3617.90*CC:CZK*X-VS:1*PT:IP*MSG:ZPRAVA*RN:PAN NOVAK',
            spayd('CZ1', 3617.9, '1', 'zpráva', 'Pan Novák', true));
        // Bez variabilního symbolu a bez okamžité platby se pole vynechají.
        $s = spayd('CZ1', 10, '', 'x');
        $this->assertStringNotContainsString('X-VS', $s);
        $this->assertStringNotContainsString('PT:IP', $s);
        $this->assertStringNotContainsString('RN:', $s);
        // Hvězdička je oddělovač – ze zprávy zmizí; délky se zkracují (zpráva 60, příjemce 35).
        $s = spayd('CZ1', 1, '', 'a*b ' . str_repeat('x', 100), str_repeat('y', 50));
        $this->assertStringContainsString('*MSG:A B ' . str_repeat('X', 56) . '*RN:' . str_repeat('Y', 35), $s);
        $this->assertStringEndsWith(str_repeat('Y', 35), $s);
        // Pořadí: VS před PT:IP, PT:IP před zprávou.
        $s = spayd('CZ1', 1, '123', 'm', '', true);
        $this->assertLessThan(strpos($s, '*PT:IP'), strpos($s, '*X-VS:123'));
        $this->assertLessThan(strpos($s, '*MSG:'), strpos($s, '*PT:IP'));
    }

    public function testCzIban(): void
    {
        $this->assertSame('CZ6508000000192000145399', cz_iban('19-2000145399/0800'));
        $this->assertSame('CZ6508000000192000145399', cz_iban('CZ65 0800 0000 1920 0014 5399'));
        $this->assertNull(cz_iban('19-2000145398/0800'), 'chybný kontrolní součet');
        $this->assertNull(cz_iban('CZ6608000000192000145399'));
        $this->assertNull(cz_iban(''));
    }

    // ---------- Edice a stav předplatného ----------

    public function testTenantEdition(): void
    {
        $base = ['billing' => 1, 'demo' => 0, 'plan' => '', 'paid_until' => '', 'trial_ends' => ''];
        $this->assertSame('premium', tenant_edition(null), 'web mimo platformu');
        $this->assertSame('premium', tenant_edition(['billing' => 0] + $base), 'web bez placení');
        $this->assertSame('free', tenant_edition($base));
        $this->assertSame('standard', tenant_edition(['trial_ends' => self::day('+1 day')] + $base));
        $this->assertSame('standard', tenant_edition(['trial_ends' => date('Y-m-d')] + $base), 'zkušební doba platí i v poslední den');
        $this->assertSame('free', tenant_edition(['trial_ends' => self::day('-1 day')] + $base));
        $this->assertSame('premium', tenant_edition(['plan' => 'premium', 'paid_until' => self::day('+30 day')] + $base));
        $this->assertSame('standard', tenant_edition(['plan' => '', 'paid_until' => self::day('+30 day')] + $base), 'zaplaceno bez edice = Standard');
        $this->assertSame('standard', tenant_edition(['plan' => 'free', 'paid_until' => self::day('+30 day')] + $base));
        $this->assertSame('standard', tenant_edition(['plan' => 'nesmysl', 'paid_until' => self::day('+30 day')] + $base));
        // Lhůta po splatnosti (grace_days).
        $grace = pset_int('grace_days', 0, 365);
        $this->assertSame('premium', tenant_edition(['plan' => 'premium', 'paid_until' => self::day('-' . $grace . ' day')] + $base));
        $this->assertSame('free', tenant_edition(['plan' => 'premium', 'paid_until' => self::day('-' . ($grace + 1) . ' day')] + $base));
        // Ukázka: edice podle přepínače v relaci.
        $demo = ['demo' => 1] + $base;
        $this->assertSame('premium', tenant_edition($demo));
        $_SESSION['demo_edition'] = 'free';
        $this->assertSame('free', tenant_edition($demo));
        $_SESSION['demo_edition'] = 'nesmysl';
        $this->assertSame('premium', tenant_edition($demo));
    }

    public function testBillingStateModes(): void
    {
        $base = ['billing' => 1, 'demo' => 0, 'plan' => '', 'paid_until' => '', 'trial_ends' => ''];
        $this->assertSame(['mode' => 'free', 'until' => '', 'days' => 0, 'edition' => 'premium'], billing_state(null));
        $this->assertSame(['mode' => 'free', 'until' => '', 'days' => 0, 'edition' => 'premium'], billing_state(['billing' => 0] + $base));
        $_SESSION['demo_edition'] = 'standard';
        $this->assertSame('standard', billing_state(['billing' => 0, 'demo' => 1] + $base)['edition']);
        unset($_SESSION['demo_edition']);

        $this->assertSame(['mode' => 'basic', 'until' => '', 'days' => 0, 'edition' => 'free'], billing_state($base));

        $s = billing_state(['trial_ends' => self::day('+10 day')] + $base);
        $this->assertSame(['mode' => 'trial', 'until' => self::day('+10 day'), 'days' => 10, 'edition' => 'standard'], $s);

        $s = billing_state(['plan' => 'premium', 'paid_until' => self::day('+40 day')] + $base);
        $this->assertSame(['mode' => 'paid', 'until' => self::day('+40 day'), 'days' => 40, 'edition' => 'premium'], $s);
        $s = billing_state(['plan' => 'standard', 'paid_until' => date('Y-m-d')] + $base);
        $this->assertSame('paid', $s['mode']);
        $this->assertSame(0, $s['days']);

        // Zaplacené období skončilo, ale zkušební doba ještě běží: until je pozdější z obou dnů.
        $s = billing_state(['plan' => 'premium', 'paid_until' => self::day('-2 day'), 'trial_ends' => self::day('+5 day')] + $base);
        $this->assertSame('trial', $s['mode']);
        $this->assertSame(self::day('+5 day'), $s['until']);

        $grace = pset_int('grace_days', 0, 365);
        if ($grace >= 3) {
            $s = billing_state(['plan' => 'premium', 'paid_until' => self::day('-3 day')] + $base);
            $this->assertSame(['mode' => 'grace', 'until' => self::day('-3 day'), 'days' => -3, 'edition' => 'premium'], $s);
        }
        $s = billing_state(['plan' => 'premium', 'paid_until' => self::day('-' . ($grace + 5) . ' day')] + $base);
        $this->assertSame(['mode' => 'basic', 'until' => self::day('-' . ($grace + 5) . ' day'), 'days' => -($grace + 5), 'edition' => 'free'], $s);
    }

    public function testBillingSet(): void
    {
        $t = $this->newTenant();
        $this->assertFalse(billing_set($t, 'gold', self::day('+1 year')));
        $this->assertFalse(billing_set($t, 'premium', 'zitra'));
        $this->assertTrue(billing_set($t, 'premium', self::day('+1 year')));
        $r = tenant_row($t['slug']);
        $this->assertSame('premium', $r['plan']);
        $this->assertSame(self::day('+1 year'), $r['paid_until']);
        $this->assertSame('paid', billing_state($r)['mode']);
        $this->assertTrue(billing_set($r, 'free', ''));
        $r = tenant_row($t['slug']);
        $this->assertSame('', $r['paid_until']);
        $this->assertSame('basic', billing_state($r)['mode']);
        $this->assertTrue(billing_set($r, 'unlimited', ''));
        $this->assertSame('free', billing_state(tenant_row($t['slug']))['mode']);
    }

    // ---------- Objednávka, kredit za nižší edici ----------

    public function testBillingOrderProblem(): void
    {
        $t = $this->newTenant();
        $this->assertNull(billing_order_problem($t, 'standard'));
        $this->assertNull(billing_order_problem($t, 'premium'));
        billing_set($t, 'premium', self::day('+100 day'));
        $t = tenant_row($t['slug']);
        $msg = billing_order_problem($t, 'standard');
        $this->assertIsString($msg);
        $this->assertStringContainsString('Edici Premium máte zaplacenou do ' . cz_date(self::day('+100 day')), $msg);
        $this->assertStringContainsString('Na edici Standard', $msg);
        $this->assertNull(billing_order_problem($t, 'premium'), 'prodloužit stejnou edici jde');
        $this->assertNotNull(billing_order_problem($t, 'free'));
        $this->assertNull(billing_order_problem($t, 'nesmysl'));
        $this->assertNull(billing_order($t, 'standard'), 'objednávka nižší edice v zaplaceném období se nevytvoří');
        // Po skončení zaplaceného období přejít jde.
        billing_set($t, 'premium', self::day('-1 day'));
        $this->assertNull(billing_order_problem(tenant_row($t['slug']), 'standard'));
    }

    public function testBillingCredit(): void
    {
        $t = $this->newTenant();
        $this->assertSame(0, billing_credit($t, 'premium'), 'nic nezaplaceno');
        $from = self::day('-100 day');
        $to = self::day('+99 day');
        billing_set($t, 'standard', $to);
        $t = tenant_row($t['slug']);
        $this->assertSame(0, billing_credit($t, 'premium'), 'období nastavené ručně – bez platby není z čeho počítat');
        pq("INSERT INTO payments (tenant, vs, amount, months, status, created_at, paid_at, period_from, period_to, kind, item) VALUES (?, '1', 2990, 12, 'paid', ?, ?, ?, ?, 'service', 'standard')",
            [$t['slug'], $from . ' 10:00:00', $from . ' 10:00:00', $from, $to]);
        // 200 dní celkem, 99 zbývá: floor(2990 * 99 / 200) = 1480.
        $this->assertSame(1480, billing_credit($t, 'premium'));
        $this->assertSame(0, billing_credit($t, 'standard'), 'stejná edice');
        $this->assertSame(0, billing_credit($t, 'free'), 'nižší edice');
        $this->assertSame(0, billing_credit($t, 'nesmysl'));
        $this->assertSame(0, billing_credit(['paid_until' => self::day('-1 day')] + $t, 'premium'), 'po skončení období');
    }

    public function testBillingOrderCreatesAndUpdatesPayment(): void
    {
        $this->setPset('vat_payer', '0');
        $t = $this->newTenant();
        $p = billing_order($t, 'standard');
        $this->assertNotNull($p);
        $this->assertSame('pending', $p['status']);
        $this->assertSame('service', $p['kind']);
        $this->assertSame('standard', $p['item']);
        $this->assertSame(12, (int) $p['months']);
        $this->assertSame(edition_price('standard'), (int) $p['amount']);
        $this->assertSame(0, (int) $p['vat_rate']);
        $this->assertSame(date('y') . sprintf('%06d', $p['id']), $p['vs']);
        $this->assertSame($p['id'], billing_open($t)['id']);
        // Změna edice upraví otevřenou výzvu, novou nevytvoří.
        $p2 = billing_order($t, 'premium');
        $this->assertSame($p['id'], $p2['id']);
        $this->assertSame('premium', $p2['item']);
        $this->assertSame(edition_price('premium'), (int) $p2['amount']);
        $this->assertSame(1, (int) pq('SELECT COUNT(*) FROM payments WHERE tenant = ?', [$t['slug']])->fetchColumn());
        // Nahlášenou platbu už objednávka nemění.
        pq("UPDATE payments SET status = 'claimed' WHERE id = ?", [$p['id']]);
        $p3 = billing_order($t, 'standard');
        $this->assertSame('premium', $p3['item']);
        $this->assertNull(billing_order($t, 'free'));
        $this->assertNull(billing_order($t, 'nesmysl'));
    }

    public function testBillingOrderWithVatAndCredit(): void
    {
        $this->setPset('vat_payer', '1');
        $this->setPset('vat_rate', '21');
        $t = $this->newTenant();
        pq('UPDATE tenants SET credit = 150 WHERE slug = ?', [$t['slug']]);
        $t = tenant_row($t['slug']);
        $p = billing_order($t, 'standard');
        $this->assertSame(21, (int) $p['vat_rate']);
        $this->assertSame(150, (int) $p['discount']);
        $this->assertSame(edition_price('standard') - 150, (int) $p['amount']);
        $this->assertSame(vat_split(edition_price('standard') - 150, 21)['total'], pay_total($p));
        // Kredit vyšší než cena: platí se 0.
        pq('UPDATE tenants SET credit = 1000000 WHERE slug = ?', [$t['slug']]);
        $p = billing_order(tenant_row($t['slug']), 'standard');
        $this->assertSame(0, (int) $p['amount']);
        $this->assertSame(edition_price('standard'), (int) $p['discount']);
    }

    // ---------- Potvrzení platby a faktura ----------

    public function testBillingConfirmNewAndRenewal(): void
    {
        $this->setPset('vat_payer', '0');
        $t = $this->newTenant();
        pq('UPDATE tenants SET credit = 100 WHERE slug = ?', [$t['slug']]);
        $t = tenant_row($t['slug']);
        $p = billing_order($t, 'standard');
        $before = $this->invoicesThisYear();
        $r = billing_confirm($p, 'test@example.cz');
        $this->assertSame(['from' => date('Y-m-d'), 'to' => self::day('+12 month -1 day')], $r);
        $paid = $this->payment((int) $p['id']);
        $this->assertSame('paid', $paid['status']);
        $this->assertSame(date('Y') . sprintf('%04d', $before + 1), $paid['invoice_no']);
        $this->assertSame('test@example.cz', $paid['confirmed_by']);
        $this->assertSame(date('Y-m-d'), $paid['period_from']);
        $this->assertSame(self::day('+12 month -1 day'), $paid['period_to']);
        $data = json_decode($paid['invoice_data'], true);
        $this->assertArrayHasKey('supplier', $data);
        $this->assertSame($t['full'], $data['customer']['name']);
        $row = tenant_row($t['slug']);
        $this->assertSame(self::day('+12 month -1 day'), $row['paid_until']);
        $this->assertSame('standard', $row['plan']);
        $this->assertSame(0, (int) $row['credit'], 'uplatněná sleva se odečte z kreditu');
        $this->assertSame('standard', tenant_edition($row));
        $this->assertSame('paid', billing_state($row)['mode']);
        // E-mail kontaktní osobě.
        $mails = array_values(array_filter($this->decodedMails(), fn($m) => $m['to'] === $t['owner_email']));
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('Platba přijata', $mails[0]['subject']);
        $this->assertStringContainsString($paid['invoice_no'], $mails[0]['body']);
        $this->assertStringContainsString($p['vs'], $mails[0]['body']);
        // Už zaplacenou platbu znovu potvrdit nejde.
        $this->assertNull(billing_confirm($paid, 'x'));

        // Prodloužení stejné edice navazuje na konec dosavadního období.
        $p2 = billing_order($row, 'standard');
        $this->assertNotSame($p['id'], $p2['id']);
        $r2 = billing_confirm($p2, 'test@example.cz');
        $from2 = date('Y-m-d', strtotime($row['paid_until'] . ' +1 day'));
        $this->assertSame(['from' => $from2, 'to' => date('Y-m-d', strtotime($from2 . ' +12 month -1 day'))], $r2);
        $this->assertSame($r2['to'], tenant_row($t['slug'])['paid_until']);
        $this->assertSame(date('Y') . sprintf('%04d', $before + 2), $this->payment((int) $p2['id'])['invoice_no']);
    }

    public function testBillingConfirmUpgradeStartsToday(): void
    {
        $t = $this->newTenant();
        billing_set($t, 'standard', self::day('+50 day'));
        $t = tenant_row($t['slug']);
        $p = billing_order($t, 'premium');
        $r = billing_confirm($p, 'x');
        $this->assertSame(date('Y-m-d'), $r['from'], 'jiná edice začíná dnes');
        $row = tenant_row($t['slug']);
        $this->assertSame('premium', $row['plan']);
        $this->assertSame(self::day('+12 month -1 day'), $row['paid_until']);
    }

    public function testBillingConfirmRejects(): void
    {
        $this->assertNull(billing_confirm(['tenant' => 'neexistuje-' . bin2hex(random_bytes(3)), 'status' => 'pending', 'kind' => 'service'], 'x'));
        $t = $this->newTenant();
        $p = billing_order($t, 'standard');
        pq("UPDATE payments SET status = 'cancelled' WHERE id = ?", [$p['id']]);
        $this->assertNull(billing_confirm($this->payment((int) $p['id']), 'x'));
        $this->assertSame('', tenant_row($t['slug'])['paid_until']);
    }

    public function testInvoiceView(): void
    {
        $this->setPset('vat_payer', '0');
        $this->setPset('account', '19-2000145399/0800');
        $this->setPset('invoice_due_days', '14');
        $t = $this->newTenant();
        $p = billing_order($t, 'premium');
        $v = invoice_view($p);
        $this->assertNotNull($v);
        $this->assertFalse($v['paid']);
        $this->assertFalse($v['storno']);
        $this->assertFalse($v['credit']);
        $this->assertSame('Web společenství – edice Premium na 1 rok', $v['item']);
        $this->assertSame(date('Y-m-d'), $v['issued']);
        $this->assertSame(self::day('+14 day'), $v['due']);
        $this->assertSame($t['slug'] . '.test.invalid', $v['site']);
        $this->assertSame('CZ6508000000192000145399', $v['parties']['supplier']['iban']);
        $this->assertNotSame('', $v['qr'], 'zálohová faktura má QR kód');

        billing_confirm($p, 'x');
        $paid = $this->payment((int) $p['id']);
        // Údaje dodavatele se na zaplacené faktuře nemění, i když se nastavení později změní.
        $this->setPset('account', '');
        $v = invoice_view($paid);
        $this->assertTrue($v['paid']);
        $this->assertSame('', $v['qr'], 'zaplacená faktura nemá QR kód');
        $this->assertSame(substr($paid['paid_at'], 0, 10), $v['issued']);
        $this->assertSame('CZ6508000000192000145399', $v['parties']['supplier']['iban']);

        $this->assertNull(invoice_view(['status' => 'cancelled'] + $paid));
        $this->assertSame('Vlastní doména svj.cz – provoz webu na této doméně', invoice_view(['kind' => 'domain', 'item' => 'svj.cz'] + $p)['item']);
        $this->assertSame('Úložiště navíc 500 MB na 1 rok', invoice_view(['kind' => 'storage', 'item' => '500'] + $p)['item']);
        $this->assertNull(invoice_view(['item' => ''] + $p)['item'], 'neznámá položka');
    }

    // ---------- Storno a opravný doklad ----------

    public function testBillingStornoFirstPayment(): void
    {
        $t = $this->newTenant();
        $p = billing_order($t, 'standard');
        $this->assertNull(billing_storno($p, 'x', 'test'), 'nezaplacenou platbu stornovat nejde');
        $this->assertNull(credit_view($p));
        billing_confirm($p, 'x');
        $paid = $this->payment((int) $p['id']);
        $this->assertNull(credit_view($paid), 'opravný doklad jen ke stornované faktuře');

        $countBefore = (int) pq('SELECT COUNT(*) FROM payments WHERE storno_no LIKE ?', ['D' . date('Y') . '%'])->fetchColumn();
        $r = billing_storno($paid, 'spravce@example.cz', '  chybná platba  ');
        $this->assertNotNull($r);
        [$no, $notes] = $r;
        $this->assertSame('D' . date('Y') . sprintf('%04d', $countBefore + 1), $no);
        $this->assertNotEmpty($notes);
        $this->assertStringContainsString('končí', $notes[0]);

        $st = $this->payment((int) $p['id']);
        $this->assertSame('storno', $st['status']);
        $this->assertSame($no, $st['storno_no']);
        $this->assertSame('chybná platba', $st['storno_reason']);
        $this->assertSame('spravce@example.cz', $st['storno_by']);
        $this->assertSame('', tenant_row($t['slug'])['paid_until'], 'období začalo dnes – placená edice končí');

        $mails = array_values(array_filter($this->decodedMails(), fn($m) => str_starts_with($m['subject'], 'Opravný doklad')));
        $this->assertCount(1, $mails);
        $this->assertStringContainsString($no, $mails[0]['body']);
        $this->assertStringContainsString('Důvod: chybná platba', $mails[0]['body']);

        $this->assertNull(billing_storno($st, 'x', ''), 'podruhé stornovat nejde');

        $c = credit_view($st);
        $this->assertNotNull($c);
        $this->assertTrue($c['credit']);
        $this->assertTrue($c['storno']);
        $this->assertTrue($c['paid']);
        $this->assertSame('', $c['qr']);
        $this->assertSame(substr($st['storno_at'], 0, 10), $c['issued']);
    }

    public function testBillingStornoRenewalRevertsToPreviousPeriod(): void
    {
        $t = $this->newTenant();
        pq('UPDATE tenants SET credit = 200 WHERE slug = ?', [$t['slug']]);
        $p1 = billing_order(tenant_row($t['slug']), 'standard');
        billing_confirm($p1, 'x');
        $firstTo = tenant_row($t['slug'])['paid_until'];
        $p2 = billing_order(tenant_row($t['slug']), 'standard');
        billing_confirm($p2, 'x');
        $this->assertGreaterThan($firstTo, tenant_row($t['slug'])['paid_until']);

        // Storno první platby: období mezitím prodloužila druhá – nic se nemění, jen upozornění.
        [, $notes] = billing_storno($this->payment((int) $p1['id']), 'x', '');
        $this->assertStringContainsString('mezitím prodloužila jiná platba', $notes[0]);
        $this->assertStringContainsString('kredit se nevrací', end($notes), 'první platba čerpala slevu');
        // Storno druhé platby vrátí konec období na konec první.
        [, $notes] = billing_storno($this->payment((int) $p2['id']), 'x', '');
        $this->assertSame($firstTo, tenant_row($t['slug'])['paid_until']);
        $this->assertSame('Edice je zaplacená jen do ' . cz_date($firstTo) . '.', $notes[0]);
    }

    public function testBillingStornoAiCredits(): void
    {
        $t = $this->newTenant();
        pq("INSERT INTO payments (tenant, vs, amount, months, status, created_at, kind, item) VALUES (?, '9', 190, 0, 'pending', ?, 'aicredits', '200')", [$t['slug'], date('Y-m-d H:i:s')]);
        $id = (int) platform_db()->lastInsertId();
        $this->assertSame(['from' => date('Y-m-d'), 'to' => date('Y-m-d')], billing_confirm($this->payment($id), 'x'));
        $this->assertSame(200, (int) tenant_row($t['slug'])['ai_credits']);
        pq('UPDATE tenants SET ai_credits = 50 WHERE slug = ?', [$t['slug']]);
        [, $notes] = billing_storno($this->payment($id), 'x', '');
        $this->assertSame(0, (int) tenant_row($t['slug'])['ai_credits'], 'kredity neklesnou pod nulu');
        $this->assertStringContainsString('200 AI kreditů', $notes[0]);
    }

    // ---------- Doporučení ----------

    public function testReferralOwnerAndRegister(): void
    {
        $ref = $this->newTenant();
        $code = referral_code($ref);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{8}$/', $code);
        $this->assertSame($code, referral_code(tenant_row($ref['slug'])), 'kód se vytvoří jen jednou');
        $this->assertSame($ref['slug'], referral_owner($code)['slug'] ?? null);
        $this->assertNull(referral_owner('kratky'));
        $this->assertNull(referral_owner($code . 'x'));
        $this->assertNull(referral_owner('ab-d+fgh'));

        // Ukázkový web nemůže doporučovat.
        pq('UPDATE tenants SET demo = 2 WHERE slug = ?', [$ref['slug']]);
        $this->assertNull(referral_owner($code));
        pq('UPDATE tenants SET demo = 0 WHERE slug = ?', [$ref['slug']]);

        // Samo sebe (stejný e-mail, bez ohledu na velikost písmen) doporučit nejde.
        $self = $this->newTenant(['owner_email' => strtoupper($ref['owner_email'])]);
        referral_register($code, $self);
        $this->assertSame(0, (int) tenant_row($self['slug'])['credit']);
        $this->assertSame('', tenant_row($self['slug'])['referred_by']);
        referral_register($code, $ref);
        $this->assertSame(0, (int) tenant_row($ref['slug'])['credit']);

        $new = $this->newTenant();
        referral_register($code, $new);
        $row = tenant_row($new['slug']);
        $this->assertSame($ref['slug'], $row['referred_by']);
        $this->assertSame(pset_int('referral_welcome', 0, 100000), (int) $row['credit']);
        $this->assertSame(1, (int) pq('SELECT COUNT(*) FROM referrals WHERE referrer = ? AND referred = ?', [$ref['slug'], $new['slug']])->fetchColumn());
        referral_register('neplatny', $this->newTenant());
        $this->assertSame(1, (int) pq('SELECT COUNT(*) FROM referrals WHERE referrer = ?', [$ref['slug']])->fetchColumn());

        $o = referral_overview(tenant_row($ref['slug']));
        $this->assertSame(1, $o['registered']);
        $this->assertSame('registered', $o['rows'][0]['state']);

        // První zaplacená edice doporučeného připíše odměnu doporučiteli (jen jednou).
        $p = billing_order($row, 'standard');
        $this->assertSame('ordered', referral_overview(tenant_row($ref['slug']))['rows'][0]['state']);
        billing_confirm($p, 'x');
        $reward = pset_int('referral_reward', 0, 100000);
        $this->assertSame($reward, (int) tenant_row($ref['slug'])['credit']);
        $o = referral_overview(tenant_row($ref['slug']));
        $this->assertSame('converted', $o['rows'][0]['state']);
        $this->assertSame($reward, $o['earned']);
        $this->assertNotEmpty(array_filter($this->decodedMails(), fn($m) => $m['to'] === $ref['owner_email'] && str_starts_with($m['subject'], 'Odměna za doporučení')));
        $p2 = billing_order(tenant_row($new['slug']), 'standard');
        billing_confirm($p2, 'x');
        $this->assertSame($reward, (int) tenant_row($ref['slug'])['credit'], 'odměna jen jednou');
    }
}
