<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Modul C – SMS (app/ext/sms_lib.php): čísla, kódování a počet částí, cena, placení z kreditu služeb, SMS v ceně Premium, brány s falešným HTTP, odeslání a beta. */
final class ModCSmsTest extends TestCase
{
    private array $savedPset = [];
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pset('beta_sms', 'on');
        $this->pset('sms_provider', 'log');
        $this->pset('sms_price_h', '150');
        $this->pset('sms_free_premium', '0');   // „dum“ má Premium – volné SMS zkouší zvláštní test
        $this->pset('credit_low_h', '0');
    }
    protected function tearDown(): void
    {
        foreach ($this->savedPset as $k => $v) {
            if ($v === null) {
                pq('DELETE FROM platform_settings WHERE key = ?', [$k]);
            } else {
                pset_save($k, $v);
            }
        }
        pset('__reset');
        foreach ($this->users as $id) {
            q('DELETE FROM users WHERE id = ?', [$id]);
        }
        sms_ledger_ready();
        pq('DELETE FROM sms_ledger WHERE tenant = ?', [TENANT]);
        pq('DELETE FROM credit_ledger WHERE tenant = ?', [TENANT]);
        pq('DELETE FROM credit_state WHERE tenant = ?', [TENANT]);
        sms_transport(null, true);
        unset($GLOBALS['SMS_SOURCE']);
    }
    private function pset(string $key, ?string $value): void
    {
        if (!array_key_exists($key, $this->savedPset)) {
            $r = pq('SELECT value FROM platform_settings WHERE key = ?', [$key])->fetch();
            $this->savedPset[$key] = $r ? (string) $r['value'] : null;
        }
        if ($value === null) {
            pq('DELETE FROM platform_settings WHERE key = ?', [$key]);
            pset('__reset');
        } else {
            pset_save($key, $value);
        }
    }
    private function owner(string $phone, string $kind = 'owner', int $active = 1): int
    {
        q("INSERT INTO users (email, name, password_hash, role, kind, phone, active) VALUES (?,?,?,?,?,?,?)", ['sms-' . bin2hex(random_bytes(4)) . '@example.cz', 'Test SMS', 'x', 'resident', $kind, $phone, $active]);
        return $this->users[] = (int) db()->lastInsertId();
    }
    /** Připíše kredit služeb na $n SMS (po 1,50 Kč). */
    private function credit(int $n): void
    {
        $this->assertNotNull(credit_add(TENANT, $n * 150, 'fix', 'test'));
    }
    private function logFile(): string
    {
        return STORAGE_DIR . '/sms-log.jsonl';
    }
    private function logLines(): array
    {
        return is_file($this->logFile()) ? array_map(fn($l) => json_decode($l, true), file($this->logFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
    }

    // ---------- čísla ----------

    public function testNormalizeCzechNumbers(): void
    {
        $this->assertSame('+420777123456', sms_normalize('777 123 456'));
        $this->assertSame('+420777123456', sms_normalize('+420 777-123-456'));
        $this->assertSame('+420777123456', sms_normalize('00420777123456'));
        $this->assertSame('+420777123456', sms_normalize('420777123456'));
        $this->assertSame('+420602123456', sms_normalize('(602) 123 456'));
        $this->assertSame('+421905123456', sms_normalize('+421 905 123 456'));
        $this->assertSame('+4915112345678', sms_normalize('+49 151 12345678'));
    }
    public function testNormalizeRejectsInvalid(): void
    {
        foreach (['', 'abc', '12345', '77712345', '7771234567', '+420 77712345', '+4207771234567', '+0123456789', '777 123 456 ext', '+1234567890123456'] as $bad) {
            $this->assertNull(sms_normalize($bad), $bad);
        }
    }
    public function testMobileDetection(): void
    {
        $this->assertTrue(sms_is_mobile('+420777123456'));
        $this->assertTrue(sms_is_mobile('+420605123456'));
        $this->assertFalse(sms_is_mobile('+420541123456'));   // pevná linka Brno
        $this->assertFalse(sms_is_mobile('+420800123456'));
        $this->assertTrue(sms_is_mobile('+421905123456'));
    }

    // ---------- kódování a části ----------

    public function testGsm7Segments(): void
    {
        $this->assertSame(['encoding' => 'GSM-7', 'units' => 0, 'segments' => 0, 'per_segment' => 160], sms_segments(''));
        $this->assertSame(1, sms_segments(str_repeat('a', 160))['segments']);
        $s = sms_segments(str_repeat('a', 161));
        $this->assertSame(['GSM-7', 161, 2, 153], [$s['encoding'], $s['units'], $s['segments'], $s['per_segment']]);
        $this->assertSame(2, sms_segments(str_repeat('a', 306))['segments']);
        $this->assertSame(3, sms_segments(str_repeat('a', 307))['segments']);
        $this->assertSame('UCS-2', sms_segments('Cafe – bar')['encoding']);   // pomlčka „–“ není v GSM-7
        $this->assertSame('GSM-7', sms_segments("Cafe e Zürich, Ñ ß @ £ \$ é\n")['encoding']);
    }
    public function testExtensionCharsCountTwice(): void
    {
        $s = sms_segments('€[]');
        $this->assertSame('GSM-7', $s['encoding']);
        $this->assertSame(6, $s['units']);
        $this->assertSame(2, sms_segments(str_repeat('€', 81))['segments']);   // 162 jednotek
        $this->assertSame(1, sms_segments(str_repeat('€', 80))['segments']);
    }
    public function testUcs2Segments(): void
    {
        $s = sms_segments('Příliš žluťoučký kůň');
        $this->assertSame('UCS-2', $s['encoding']);
        $this->assertSame(20, $s['units']);
        $this->assertSame(1, sms_segments(str_repeat('č', 70))['segments']);
        $this->assertSame(2, sms_segments(str_repeat('č', 71))['segments']);
        $this->assertSame(67, sms_segments(str_repeat('č', 71))['per_segment']);
        $this->assertSame(3, sms_segments(str_repeat('č', 135))['segments']);
        $this->assertSame(3, sms_segments('a😀')['units']);   // emoji = 2 jednotky UTF-16
    }
    public function testPlainRemovesDiacriticsAndFitsGsm(): void
    {
        $this->assertSame('Prilis zlutoucky kun upel dabelské ody', sms_plain('Příliš žluťoučký kůň úpěl ďábelské ódy'));
        $this->assertSame('ZATOPENI SKLEPA - "pozor" ...', sms_plain('ZATOPENÍ SKLEPA – „pozor“ …'));
        $this->assertSame('GSM-7', sms_segments(sms_plain('Odstávka vody v úterý 8–12 h, Výbor SVJ Zahradní 12'))['encoding']);
        $this->assertSame('café', sms_plain('café'));   // é je v GSM-7
    }
    public function testCostEstimate(): void
    {
        $this->pset('sms_price_h', '150');
        $this->assertSame(150, sms_price_h());
        $this->assertSame([1, 10, 15.0], sms_cost('Kratka zprava', 10));
        $this->assertSame([2, 6, 9.0], sms_cost(str_repeat('č', 80), 3));
        $this->assertSame([1, 0, 0.0], sms_cost('x', 0));
        $this->pset('sms_price_h', 'xx');
        $this->assertSame((int) SMS_DEFAULTS['sms_price_h'], sms_price_h());
        $this->assertSame('1,50 Kč', str_replace("\u{a0}", ' ', sms_money(1.5)));
        $this->assertSame('300 Kč', str_replace("\u{a0}", ' ', sms_money(300.0)));
    }

    // ---------- placení z kreditu služeb ----------

    public function testBalanceCountsCreditInSms(): void
    {
        $this->assertSame(0, sms_balance());
        $this->credit(100);
        $this->assertSame(100, sms_balance());
        credit_add(TENANT, 149, 'fix', 'drobné');
        $this->assertSame(100, sms_balance(), 'neúplná SMS se nepočítá');
        $this->assertNotNull(sms_charge(TENANT, 100, 'test'));
        $this->assertNull(sms_charge(TENANT, 1, 'test'), 'na další SMS nestačí');
        $this->assertSame(149, credit_balance_h(TENANT));
        $row = credit_statement(TENANT, 1)[0];
        $this->assertSame(['sms', -15000, 100], [$row['kind'], (int) $row['delta_h'], (int) $row['qty']]);
    }
    public function testPremiumFreeSmsUsedBeforeCredit(): void
    {
        $this->pset('sms_free_premium', '3');
        $this->assertSame('premium', tenant_edition(tenant_row(TENANT)));
        $this->assertSame(3, sms_free_allowance(TENANT));
        $this->credit(2);
        $this->assertSame(5, sms_balance());
        $ref = sms_charge(TENANT, 2, 'první');
        $this->assertNotNull($ref);
        $this->assertSame([1, 300], [sms_free_left(TENANT), credit_balance_h(TENANT)], 'nejdřív volné SMS');
        $this->assertNotNull(sms_charge(TENANT, 2, 'druhá'));
        $this->assertSame([0, 150], [sms_free_left(TENANT), credit_balance_h(TENANT)], '1 volná + 1 z kreditu');
        // vrácení neodeslané zprávy vrátí i volné SMS
        credit_reverse(TENANT, $ref, 'neodesláno');
        $this->assertSame([2, 150], [sms_free_left(TENANT), credit_balance_h(TENANT)]);
        // volné SMS z loňska se nepočítají
        pq('UPDATE credit_ledger SET at = ? WHERE tenant = ? AND delta_h = 0', [(date('Y') - 1) . '-12-31 10:00:00', TENANT]);
        $this->assertSame(3, sms_free_left(TENANT), 'nový kalendářní rok = nový příděl');
    }
    public function testFreeSmsOnlyInPremium(): void
    {
        $this->pset('sms_free_premium', '100');
        $saved = pq('SELECT billing, plan, paid_until, trial_ends FROM tenants WHERE slug = ?', [TENANT])->fetch();
        plan_tenant();   // edice webu je v procesu uložená – změna řádku ji neovlivní
        try {
            pq("UPDATE tenants SET billing = 1, plan = 'standard', paid_until = ? WHERE slug = ?", [date('Y-m-d', strtotime('+100 day')), TENANT]);
            $this->assertSame(0, sms_free_allowance(TENANT));
            $this->assertNull(sms_charge(TENANT, 1, 'Standard bez kreditu'));
        } finally {
            pq('UPDATE tenants SET billing = ?, plan = ?, paid_until = ?, trial_ends = ? WHERE slug = ?', [...array_values($saved), TENANT]);
        }
        $this->assertSame(100, sms_free_allowance(TENANT));
    }

    // ---------- odeslání ----------

    public function testSendDeductsSegmentsAndLogs(): void
    {
        $this->credit(5);
        $before = count($this->logLines());
        $GLOBALS['SMS_SOURCE'] = 'hlasovani';
        $this->assertTrue(sms_send('777 123 456', 'Hlasovani konci zitra.'));
        $this->assertSame(4, sms_balance());
        $this->assertSame(600, credit_balance_h(TENANT));
        $lines = $this->logLines();
        $this->assertCount($before + 1, $lines);
        $this->assertSame('+420777123456', end($lines)['to']);
        $row = one('SELECT * FROM sms_log ORDER BY id DESC LIMIT 1');
        $this->assertSame(['sent', 'log', 'hlasovani', 1], [$row['status'], $row['provider'], $row['source'], (int) $row['segments']]);
        // dvoudílná zpráva strhne 2
        $r = sms_send_ex('+420777123456', str_repeat('č', 80));
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['segments']);
        $this->assertSame(2, sms_balance());
        $last = credit_statement(TENANT, 1)[0];
        $this->assertSame(['sms', -300, 2], [$last['kind'], (int) $last['delta_h'], (int) $last['qty']]);
    }
    public function testSendUsesPremiumFreeSmsWithoutCredit(): void
    {
        $this->pset('sms_free_premium', '1');
        $this->assertTrue(sms_send('777123456', 'Zdarma'));
        $this->assertSame(0, credit_balance_h(TENANT));
        $this->assertFalse(sms_send('777123456', 'Uz ne'), 'volné SMS došly a kredit není');
    }
    public function testSendRefusedWithoutCredit(): void
    {
        $before = count($this->logLines());
        $r = sms_send_ex('777123456', 'Bez kreditu');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('kredit', $r['error']);
        $this->assertCount($before, $this->logLines(), 'nic se nesmí odeslat');
        $this->assertSame('refused', one('SELECT status FROM sms_log ORDER BY id DESC LIMIT 1')['status']);
        $this->assertSame(0, sms_balance());
        $this->assertSame([], credit_statement(TENANT));
    }
    public function testSendValidation(): void
    {
        $this->credit(100);
        $this->assertFalse(sms_send('123', 'x'));
        $this->assertFalse(sms_send('777123456', '   '));
        $this->assertFalse(sms_send('777123456', str_repeat('č', 67 * SMS_MAX_SEGMENTS + 1)));
        $this->assertSame(100, sms_balance());
    }
    public function testBetaOffBlocksSending(): void
    {
        $this->credit(10);
        $this->pset('beta_sms', 'off');
        $this->assertFalse(beta_visible('sms'));
        $this->assertFalse(sms_send('777123456', 'x'));
        $this->assertSame(10, sms_balance());
        $this->pset('beta_sms', 'beta');
        $this->assertTrue(beta_visible('sms'));   // ukázkový dům má Premium
        $this->assertArrayHasKey('sms', BETA_FEATURES);
        $this->assertArrayHasKey('/admin/sms', $GLOBALS['EXT_ADMIN_NAV']);
        $this->pset('beta_sms', null);
        $this->assertSame('off', beta_stage('sms'), 'výchozí stav je vypnuto');
        $this->assertFalse(($GLOBALS['EXT_ADMIN_NAV']['/admin/sms'][2])());
    }
    public function testFailedProviderRefundsCredit(): void
    {
        $this->pset('sms_provider', 'bulkgate');   // v config.php nejsou klíče → brána odmítne
        $this->credit(3);
        $r = sms_send_ex('777123456', 'x');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('application_id', $r['error']);
        $this->assertSame(3, sms_balance());
        $last = credit_statement(TENANT, 2);
        $this->assertSame(['sms', 150], [$last[0]['kind'], (int) $last[0]['delta_h']], 'vráceno do kreditu');
        $this->assertSame((int) $last[1]['lot_id'], (int) $last[0]['lot_id'], 'do téže šarže');
        $this->assertStringContainsString('vráceno', $last[0]['note']);
        $this->assertSame('failed', one('SELECT status FROM sms_log ORDER BY id DESC LIMIT 1')['status']);
    }
    public function testOldSmsCreditIsMigratedToMoney(): void
    {
        sms_ledger_ready();
        pq("INSERT INTO sms_ledger (tenant, at, delta, kind) VALUES (?, '2026-01-01 10:00:00', 200, 'credit'), (?, '2026-02-01 10:00:00', -30, 'send')", [TENANT, TENANT]);
        pq("INSERT INTO sms_ledger (tenant, at, delta, kind) VALUES ('jiny-dum', '2026-01-01 10:00:00', 5, 'credit'), ('jiny-dum', '2026-01-02 10:00:00', -5, 'send')");
        $this->pset('sms_price_h', '120');
        $done = credit_migrate_sms(platform_db());
        $this->assertSame([TENANT => 170 * 120], $done, 'jen domy se zůstatkem');
        $this->assertSame(170 * 120, credit_balance_h(TENANT));
        $row = credit_statement(TENANT, 1)[0];
        $this->assertSame(['migrate', 170], [$row['kind'], (int) $row['qty']]);
        $this->assertSame((int) $row['id'], (int) $row['lot_id'], 'převod je nová šarže');
        $this->assertSame(date('Y-m-d', strtotime('+24 month')), $row['expires_at']);
        $this->assertSame(0, (int) pq('SELECT SUM(delta) FROM sms_ledger WHERE tenant = ?', [TENANT])->fetchColumn(), 'historie zůstává, zůstatek vynulován');
        $this->assertSame(3, (int) pq('SELECT COUNT(*) FROM sms_ledger WHERE tenant = ?', [TENANT])->fetchColumn());
        $this->assertSame([], credit_migrate_sms(platform_db()), 'podruhé se nic nepřevede');
        pq("DELETE FROM sms_ledger WHERE tenant = 'jiny-dum'");
    }

    public function testStagingAlwaysUsesLogProvider(): void
    {
        $this->assertSame('log', sms_provider_for('gosms', true));
        $this->assertSame('log', sms_provider_for('bulkgate', true));
        $this->assertSame('gosms', sms_provider_for('gosms', false));
        $this->assertSame('log', sms_provider_for('neznama', false));
        $this->assertSame(['gosms_client_id', 'gosms_client_secret', 'gosms_channel'], sms_config_missing('gosms'));
        $this->assertSame([], sms_config_missing('log'));
        $this->assertInstanceOf(\SmsLogProvider::class, sms_provider('log'));
    }

    // ---------- brány (falešné HTTP) ----------

    public function testGoSmsSendsWithToken(): void
    {
        $calls = [];
        $http = function (string $m, string $u, array $h, ?string $b) use (&$calls) {
            $calls[] = [$m, $u, $h, $b];
            if (str_contains($u, '/oauth/v2/token')) {
                return ['status' => 200, 'body' => '{"access_token":"TOK","expires_in":3600,"token_type":"bearer","scope":"user"}'];
            }
            return ['status' => 201, 'body' => '{"recipients":{"invalid":[]},"link":"api/v1/messages/42"}'];
        };
        $p = new \SmsGoSmsProvider('cid', 'sec', 7, $http);
        $r = $p->send('+420777123456', 'Ahoj', false);
        $this->assertSame(['ok' => true, 'id' => 'gosms:42', 'error' => ''], $r);
        $this->assertSame('GET', $calls[0][0]);
        $this->assertStringContainsString('grant_type=client_credentials', $calls[0][1]);
        $this->assertSame(['POST', 'https://app.gosms.eu/api/v1/messages'], [$calls[1][0], $calls[1][1]]);
        $this->assertContains('Authorization: Bearer TOK', $calls[1][2]);
        $this->assertSame(['message' => 'Ahoj', 'recipients' => '+420777123456', 'channel' => 7], json_decode($calls[1][3], true));
        $p->send('+420777123457', 'Znovu', false);
        $this->assertCount(3, $calls, 'token se použije znovu');
    }
    public function testGoSmsErrors(): void
    {
        $bad = fn() => ['status' => 400, 'body' => '{"error":"invalid_client","error_description":"The client credentials are invalid"}'];
        $this->assertStringContainsString('přihlášení', (new \SmsGoSmsProvider('a', 'b', 1, $bad))->send('+420777123456', 'x', false)['error']);
        $problem = fn(string $m, string $u) => str_contains($u, 'oauth') ? ['status' => 200, 'body' => '{"access_token":"T"}']
            : ['status' => 400, 'body' => '{"type":"","status":400,"title":"Non-existent channel","detail":"Channel \'1234\' doesn\'t exist in your organization"}'];
        $r = (new \SmsGoSmsProvider('a', 'b', 1234, $problem))->send('+420777123456', 'x', false);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Non-existent channel', $r['error']);
        $invalid = fn(string $m, string $u) => str_contains($u, 'oauth') ? ['status' => 200, 'body' => '{"access_token":"T"}'] : ['status' => 201, 'body' => '{"recipients":{"invalid":["+420777123456"]},"link":"api/v1/messages/1"}'];
        $this->assertFalse((new \SmsGoSmsProvider('a', 'b', 1, $invalid))->send('+420777123456', 'x', false)['ok']);
        $this->assertStringContainsString('chybí', (new \SmsGoSmsProvider('', '', 0, $bad))->send('+420777123456', 'x', false)['error']);
        $down = fn() => ['status' => 0, 'body' => ''];
        $this->assertFalse((new \SmsGoSmsProvider('a', 'b', 1, $down))->send('+420777123456', 'x', false)['ok']);
    }
    public function testBulkGateRequestAndResponses(): void
    {
        $sent = null;
        $ok = function (string $m, string $u, array $h, ?string $b) use (&$sent) {
            $sent = [$m, $u, json_decode($b, true)];
            return ['status' => 200, 'body' => '{"data":{"status":"accepted","sms_id":"tmpde1bcd4b1d1","part_id":["tmpde1bcd4b1d1_1"],"number":"420777123456"}}'];
        };
        $r = (new \SmsBulkGateProvider('451', 'tok', 'gText', 'SVJ', $ok))->send('+420777123456', 'Příliš', true);
        $this->assertSame(['ok' => true, 'id' => 'bulkgate:tmpde1bcd4b1d1', 'error' => ''], $r);
        $this->assertSame('https://portal.bulkgate.com/api/1.0/simple/transactional', $sent[1]);
        $this->assertSame(['application_id' => 451, 'application_token' => 'tok', 'number' => '420777123456', 'text' => 'Příliš', 'unicode' => true, 'sender_id' => 'gText', 'sender_id_value' => 'SVJ'], $sent[2]);
        $err = fn() => ['status' => 400, 'body' => '{"type":"invalid_phone_number","code":400,"error":"Invalid phone number","detail":null}'];
        $r = (new \SmsBulkGateProvider('451', 'tok', '', '', $err))->send('+420777123456', 'x', false);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Invalid phone number', $r['error']);
        $unauth = fn() => ['status' => 401, 'body' => '{"type":"unknown_identity","code":401,"error":"Unknown identity / unauthorized / empty application_id","detail":null}'];
        $this->assertStringContainsString('401', (new \SmsBulkGateProvider('1', 't', '', '', $unauth))->send('+420777123456', 'x', false)['error']);
    }
    public function testSendThroughInjectedTransport(): void
    {
        // Konfigurovaná brána bez klíčů v config.php nic neodešle a transport se ani nezavolá.
        $called = false;
        sms_transport(function () use (&$called) {
            $called = true;
            return ['status' => 500, 'body' => ''];
        });
        $this->pset('sms_provider', 'gosms');
        $this->credit(2);
        $this->assertFalse(sms_send('777123456', 'x'));
        $this->assertFalse($called);
        $this->assertSame(2, sms_balance());
    }

    // ---------- příjemci a hromadná zpráva ----------

    public function testOwnerRecipients(): void
    {
        [$base, $baseBad] = sms_owner_recipients();
        $a = $this->owner('777 111 222');
        $this->owner('+420777111222');           // stejné číslo – jen jednou
        $this->owner('541 123 456');             // pevná linka
        $this->owner('nevim');                   // neplatné
        $this->owner('777 333 444', 'tenant');   // nájemník
        $this->owner('777 555 666', 'owner', 0); // neaktivní
        [$ok, $bad] = sms_owner_recipients();
        $mine = array_values(array_filter($ok, fn($r) => in_array($r['id'], $this->users, true)));
        $this->assertSame([['id' => $a, 'name' => 'Test SMS', 'phone' => '+420777111222']], $mine);
        $this->assertCount(count($base) + 1, $ok);
        $this->assertCount(count($baseBad) + 2, $bad);
        $this->assertNotContains('+420777333444', array_column($ok, 'phone'));
    }
    public function testSendOwnersChecksCreditForAll(): void
    {
        $this->owner('777 111 001');
        $this->owner('777 111 002');
        $this->owner('777 111 003');
        $n = count(sms_owner_recipients()[0]);
        $this->credit($n - 1);
        [$sent, $failed, $err] = sms_send_owners('Havarie vody');
        $this->assertSame([0, 0], [$sent, $failed]);
        $this->assertStringContainsString('potřebuje ' . $n . ' SMS', $err);
        $this->credit(1);
        $before = count($this->logLines());
        $this->assertSame([$n, 0, null], sms_send_owners('Havarie vody'));
        $this->assertSame(0, sms_balance());
        $this->assertCount($before + $n, $this->logLines());
    }
    public function testOrderFlowIsReplacedByCreditTopUp(): void
    {
        $this->assertFalse(function_exists('sms_order'), 'balíčky SMS se už neobjednávají – dobíjí se kredit služeb');
        $this->assertArrayNotHasKey('sms_pack', SMS_DEFAULTS);
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/ext/routes_sms.php');
        $this->assertStringContainsString("redirect('/admin/kredit#dobit')", $src);
    }

    // ---------- obrazovky ----------

    private function view(string $view, array $data): string
    {
        extract($data);
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/' . $view . '.php';
        return (string) ob_get_clean();
    }
    public function testAdminViewShowsPreviewAndCredit(): void
    {
        $this->loginAs('admin');
        $text = sms_plain('Havárie vody – sklep zatopený');
        [$seg, $total, $czk] = sms_cost($text, 2);
        $html = $this->view('ext/sms/admin', ['text' => $text, 'plain' => true, 'preview' => ['text' => $text, 'info' => sms_segments($text), 'recipients' => 2, 'total' => $total, 'czk' => $czk],
            'recipients' => [[], []], 'bad' => [], 'managed' => true, 'balance' => 1, 'creditH' => 150, 'freeLeft' => 0, 'freeYear' => 100,
            'price' => 150, 'log' => [['at' => '2026-11-26 10:00:00', 'phone' => '+420777123456', 'segments' => 1, 'status' => 'sent', 'error' => '', 'text' => 'x']], 'staging' => true]);
        $this->assertStringContainsString('Havarie vody - sklep zatopeny', $html);
        $this->assertStringContainsString('Kredit služeb nestačí', $html);
        $this->assertStringContainsString('/admin/kredit#dobit', $html, 'místo odeslání odkaz na dobití');
        $this->assertStringContainsString('0 z 100 SMS v ceně edice Premium', $html);
        $this->assertStringNotContainsString('value="send"', $html, 'bez kreditu nejde odeslat');
        $this->assertStringNotContainsString('name="packs"', $html, 'balíčky SMS se neobjednávají');
        $this->assertStringContainsString('+420777123•••', $html);
        $this->assertStringContainsString('Zkušební režim', $html);
        $this->assertStringNotContainsString('<script', $html);
    }
    public function testConsoleViewRenders(): void
    {
        $html = $this->view('ext/sms/console', ['provider' => 'gosms', 'effective' => 'log', 'price' => 150, 'free' => 100, 'stage' => 'beta']);
        $this->assertStringContainsString('sms.gosms_client_id', $html);
        $this->assertStringContainsString('Kredit služeb', $html);
        $this->assertStringContainsString('100 SMS ročně v ceně', $html);
        $this->assertStringNotContainsString('name="pack"', $html);
        $this->assertStringContainsString('value="1.5"', $html);
        $this->assertStringContainsString('Zkušební prostředí', $html);
    }
}
