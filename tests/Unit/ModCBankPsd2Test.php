<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Modul C – propojení s bankami přes GoCardless Bank Account Data (app/ext/psd2_lib.php).
 * Agregátor nahrazuje falešný HTTP transport s odpověďmi podle dokumentace (tests/Unit/fixtures/modc).
 */
final class ModCBankPsd2Test extends TestCase
{
    private const BASE = 'https://gc.test/api/v2';
    private array $calls = [];
    private string $acc = '';
    private string $reqStatus = 'LN';
    private ?int $txStatus = null;
    private array $vs = [];
    private ?string $savedBeta = null;

    private static function fx(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/modc/' . $name);
    }
    protected function setUp(): void
    {
        parent::setUp();
        $r = pq("SELECT value FROM platform_settings WHERE key = 'beta_bank-psd2'")->fetch();
        $this->savedBeta = $r ? (string) $r['value'] : null;
        pset_save('beta_bank-psd2', 'on');
        @unlink(ROOT_STORAGE . '/psd2-token.json');
        @unlink(ROOT_STORAGE . '/psd2-institutions-cz.json');
        psd2_config(['gocardless_id' => 'sid', 'gocardless_key' => 'skey', 'gocardless_url' => self::BASE]);
        $this->acc = 'acc-' . bin2hex(random_bytes(6));
        $this->vs = array_map(fn($v) => bank_vs((string) $v), array_column(all("SELECT vs FROM unit_accounts WHERE vs <> '' ORDER BY unit_id LIMIT 2"), 'vs'));
        psd2_transport(fn(string $m, string $u, array $h, ?string $b) => $this->fake($m, $u, $h, $b));
        psd2_ready();
        q('DELETE FROM psd2_accounts');
        q('DELETE FROM psd2_links');
    }
    protected function tearDown(): void
    {
        psd2_transport(null, true);
        psd2_config(null, true);
        if ($this->savedBeta === null) {
            pq("DELETE FROM platform_settings WHERE key = 'beta_bank-psd2'");
        } else {
            pset_save('beta_bank-psd2', $this->savedBeta);
        }
        pset('__reset');
        @unlink(ROOT_STORAGE . '/psd2-token.json');
        @unlink(ROOT_STORAGE . '/psd2-institutions-cz.json');
    }
    private function fake(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = [$method, $url, $headers, $body === null ? null : json_decode($body, true)];
        $path = substr($url, strlen(self::BASE));
        $ok = fn(string $json, int $status = 200) => ['status' => $status, 'body' => $json, 'headers' => []];
        $authed = in_array('Authorization: Bearer ACCESS-1', $headers, true) || in_array('Authorization: Bearer ACCESS-2', $headers, true);
        if ($method === 'POST' && $path === '/token/new/') {
            return $ok('{"access":"ACCESS-1","access_expires":86400,"refresh":"REFRESH-1","refresh_expires":2592000}');
        }
        if ($method === 'POST' && $path === '/token/refresh/') {
            return $ok('{"access":"ACCESS-2","access_expires":86400}');
        }
        if (!$authed) {
            return $ok('{"summary":"Invalid token","detail":"Token is invalid or expired","status_code":401}', 401);
        }
        if ($method === 'GET' && $path === '/institutions/?country=cz') {
            return $ok(self::fx('institutions_cz.json'));
        }
        if ($method === 'POST' && $path === '/agreements/enduser/') {
            return $ok('{"id":"2dea1b84-97b0-4cb4-8805-302c227587c8","max_historical_days":90,"access_valid_for_days":90,"access_scope":["details","transactions"],"accepted":""}', 201);
        }
        if ($method === 'POST' && $path === '/requisitions/') {
            return $ok(self::fx('requisition_created.json'), 201);
        }
        if ($method === 'GET' && preg_match('~^/requisitions/[\w-]+/$~', $path)) {
            return $ok(json_encode(['id' => '8126e9fb-93c9-4228-937c-68f0383c2df7', 'status' => $this->reqStatus, 'accounts' => $this->reqStatus === 'LN' ? [$this->acc] : []]));
        }
        if ($method === 'DELETE' && preg_match('~^/requisitions/[\w-]+/$~', $path)) {
            return $ok('{"summary":"Requisition deleted","detail":"Requisition 8126e9fb deleted with all its End User Agreements"}');
        }
        if ($method === 'GET' && $path === '/accounts/' . $this->acc . '/') {
            return $ok(str_replace('ACCOUNT-ID', $this->acc, self::fx('account.json')));
        }
        if ($method === 'GET' && str_starts_with($path, '/accounts/' . $this->acc . '/transactions/')) {
            if ($this->txStatus !== null) {
                return $ok('{"summary":"Error","detail":"Rate limit / access","status_code":' . $this->txStatus . '}', $this->txStatus);
            }
            return $ok(strtr(self::fx('transactions.json'), ['{VS1}' => $this->vs[0], '{VS2}' => $this->vs[1]]));
        }
        return $ok('{"summary":"Not found"}', 404);
    }
    private function connectAndLink(): array
    {
        $r = psd2_connect('CSOB_CEKOCZPP', ['name' => 'Jana Správcová']);
        $this->assertArrayHasKey('link', $r, $r['error'] ?? '');
        $ref = (string) val('SELECT reference FROM psd2_links ORDER BY id DESC LIMIT 1');
        return [$ref, psd2_callback($ref)];
    }
    private function txCalls(): int
    {
        return count(array_filter($this->calls, fn($c) => str_contains($c[1], '/transactions/')));
    }

    // ---------- převody ----------

    public function testIbanToCzechAccount(): void
    {
        $this->assertSame('123456789/0300', psd2_iban_to_cz('CZ65 0300 0000 0001 2345 6789'));
        $this->assertSame('19-123457/0100', psd2_iban_to_cz('CZ1201000000190000123457'));
        $this->assertSame('SK3112000000198742637541', psd2_iban_to_cz('SK3112000000198742637541'));
    }
    public function testVsExtraction(): void
    {
        $this->assertSame('4801', psd2_vs(['endToEndId' => '/VS4801/SS/KS0308']));
        $this->assertSame('4801', psd2_vs(['endToEndId' => '0000004801']));
        $this->assertSame('4802', psd2_vs(['remittanceInformationStructured' => '4802']));
        $this->assertSame('4803', psd2_vs(['remittanceInformationUnstructured' => 'Zaloha VS: 0004803 byt 12']));
        $this->assertSame('4804', psd2_vs(['remittanceInformationUnstructuredArray' => ['x', 'vs=4804']]));
        $this->assertSame('', psd2_vs(['endToEndId' => 'NOTPROVIDED', 'remittanceInformationUnstructured' => 'bez symbolu']));
        $this->assertSame('', psd2_vs(['remittanceInformationUnstructured' => 'ADVS 12']), 'VS musí být samostatné slovo');
        $this->assertSame('', psd2_vs(['endToEndId' => '12345678901']), 'víc než 10 číslic není VS');
    }
    public function testMapTransactions(): void
    {
        $data = json_decode(strtr(self::fx('transactions.json'), ['{VS1}' => '4801', '{VS2}' => '0004802']), true);
        $rows = array_values(array_filter(array_map(fn($t) => psd2_map_tx($t, 'acc-1'), $data['transactions']['booked'])));
        $this->assertCount(3, $rows, 'odchozí platba a cizí měna se přeskočí');
        $this->assertSame(['date' => '2026-11-20', 'amount' => 2500.0, 'vs' => '4801', 'account' => '1234567899/0800', 'name' => 'Jan Novák', 'message' => 'Zaloha listopad'],
            array_diff_key($rows[0], ['id' => 1]));
        $this->assertStringStartsWith('psd2:' . substr(md5('acc-1'), 0, 6) . ':CSOB-0001', $rows[0]['id']);
        $this->assertSame('4802', $rows[1]['vs']);
        $this->assertSame('19-123457/0100', $rows[1]['account']);
        $this->assertStringEndsWith(':int-77', $rows[2]['id']);
        $this->assertSame('99999', $rows[2]['vs']);
        $this->assertSame('Platba VS: 0099999 nevim', $rows[2]['message']);
        $this->assertNull(psd2_map_tx(['transactionAmount' => ['amount' => '10', 'currency' => 'CZK']], 'a'), 'bez data');
        $long = psd2_map_tx(['transactionId' => str_repeat('x', 120), 'bookingDate' => '2026-01-01', 'transactionAmount' => ['amount' => '1']], 'a');
        $this->assertLessThanOrEqual(80, strlen($long['id']));
    }

    // ---------- tok propojení ----------

    public function testInstitutionsAreListedAndCached(): void
    {
        $list = psd2_institutions();
        $this->assertIsArray($list);
        $this->assertSame(['Air Bank', 'Česká spořitelna', 'ČSOB', 'Komerční banka'], array_column($list, 'name'));
        $this->assertSame(730, $list[2]['days']);
        $n = count($this->calls);
        psd2_institutions();
        $this->assertCount($n, $this->calls, 'seznam se bere z mezipaměti');
        $this->assertSame(['POST', self::BASE . '/token/new/'], [$this->calls[0][0], $this->calls[0][1]]);
        $this->assertSame(['secret_id' => 'sid', 'secret_key' => 'skey'], $this->calls[0][3]);
    }
    public function testNotConfigured(): void
    {
        psd2_config([]);
        $this->assertFalse(psd2_configured());
        $this->assertIsString(psd2_institutions());
        $this->assertSame(0, psd2_daily());
    }
    public function testConnectCreatesAgreementAndRequisition(): void
    {
        $r = psd2_connect('CSOB_CEKOCZPP', ['name' => 'Jana Správcová']);
        $this->assertSame('https://ob.gocardless.com/psd2/start/3fa85f64-5717-4562-b3fc-2c963f66afa6/CSOB_CEKOCZPP', $r['link']);
        $agr = array_values(array_filter($this->calls, fn($c) => str_ends_with($c[1], '/agreements/enduser/')))[0][3];
        $this->assertSame(['institution_id' => 'CSOB_CEKOCZPP', 'max_historical_days' => 90, 'access_valid_for_days' => 90, 'access_scope' => ['details', 'transactions']], $agr);
        $req = array_values(array_filter($this->calls, fn($c) => str_ends_with($c[1], '/requisitions/')))[0][3];
        $this->assertStringEndsWith('/admin/platby/banky/navrat', $req['redirect']);
        $this->assertMatchesRegularExpression('/^svj-[a-f0-9]{20}$/', $req['reference']);
        $this->assertSame('CS', $req['user_language']);
        $l = one('SELECT * FROM psd2_links ORDER BY id DESC LIMIT 1');
        $this->assertSame(['CR', 'ČSOB', $req['reference'], date('Y-m-d', strtotime('+90 day'))], [$l['status'], $l['institution_name'], $l['reference'], $l['valid_until']]);
        $this->assertSame(['error' => 'Vyberte banku ze seznamu.'], psd2_connect('NEEXISTUJE', []));
    }
    public function testCallbackLinksAccountsAndImportsPayments(): void
    {
        [, [$ok, $msg]] = $this->connectAndLink();
        $this->assertTrue($ok, $msg);
        $this->assertStringContainsString('Nových plateb 3, spárováno 2', $msg);
        $a = one('SELECT * FROM psd2_accounts WHERE account_id = ?', [$this->acc]);
        $this->assertSame(['CZ6503000000000123456789', 1, date('Y-m-d')], [$a['iban'], (int) $a['fetch_count'], $a['fetch_day']]);
        $row = one('SELECT * FROM bank_tx WHERE tx_id = ?', ['psd2:' . substr(md5($this->acc), 0, 6) . ':CSOB-0001']);
        $this->assertSame(['psd2', 2500.0, $this->vs[0]], [$row['source'], (float) $row['amount'], $row['vs']]);
        $this->assertGreaterThan(0, (int) $row['unit_id']);
        $txUrl = array_values(array_filter($this->calls, fn($c) => str_contains($c[1], '/transactions/')))[0][1];
        $this->assertStringContainsString('date_from=' . date('Y-m-d', strtotime('-89 day')), $txUrl);
        // znovu stažené pohyby se neuloží dvakrát
        [$new] = psd2_refresh((int) $a['link_id']);
        $this->assertSame(0, $new);
    }
    public function testCallbackNotFinished(): void
    {
        $this->reqStatus = 'RJ';
        [$ref, [$ok, $msg]] = $this->connectAndLink();
        $this->assertFalse($ok);
        $this->assertStringContainsString('odmítla', $msg);
        $this->assertSame('RJ', val('SELECT status FROM psd2_links WHERE reference = ?', [$ref]));
        $this->assertSame([false, 'Propojení jsme nenašli. Zkuste banku připojit znovu.'], psd2_callback('svj-' . str_repeat('0', 20)));
        [$ok2, $msg2] = psd2_callback($ref, 'UserCancelledSession');
        $this->assertFalse($ok2);
        $this->assertStringContainsString('UserCancelledSession', $msg2);
    }
    public function testDailyLimitFourPerAccount(): void
    {
        [$ref] = $this->connectAndLink();
        $id = (int) val('SELECT id FROM psd2_links WHERE reference = ?', [$ref]);
        psd2_refresh($id);
        psd2_refresh($id);
        psd2_refresh($id);
        $this->assertSame(4, $this->txCalls());
        [, , $notes] = psd2_refresh($id);
        $this->assertSame(4, $this->txCalls(), 'páté stažení v jednom dni se nevolá');
        $this->assertStringContainsString('nejvýš 4', str_replace(PSD2_DAILY_LIMIT . '×', 'nejvýš 4', $notes[0]));
        // další den se počítá znovu
        q("UPDATE psd2_accounts SET fetch_day = '2000-01-01'");
        psd2_refresh($id);
        $this->assertSame(5, $this->txCalls());
    }
    public function testRateLimitResponseStopsForTheDay(): void
    {
        [$ref] = $this->connectAndLink();
        $id = (int) val('SELECT id FROM psd2_links WHERE reference = ?', [$ref]);
        $this->txStatus = 429;
        [, , $notes] = psd2_refresh($id);
        $this->assertStringContainsString('Zkusíme to zítra', $notes[0]);
        $this->assertSame(PSD2_DAILY_LIMIT, (int) val('SELECT fetch_count FROM psd2_accounts WHERE link_id = ?', [$id]));
        $n = $this->txCalls();
        psd2_refresh($id);
        $this->assertSame($n, $this->txCalls());
    }
    public function testRevokedAccessMarksExpired(): void
    {
        [$ref] = $this->connectAndLink();
        $id = (int) val('SELECT id FROM psd2_links WHERE reference = ?', [$ref]);
        $this->txStatus = 403;
        psd2_refresh($id);
        $this->assertSame('EX', val('SELECT status FROM psd2_links WHERE id = ?', [$id]));
        $this->assertSame([0, 0, ['Propojení není aktivní.']], psd2_refresh($id));
    }
    public function testTokenIsCachedAndRefreshed(): void
    {
        psd2_institutions();
        $this->assertFileExists(ROOT_STORAGE . '/psd2-token.json');
        $this->assertSame('600', substr(sprintf('%o', fileperms(ROOT_STORAGE . '/psd2-token.json')), -3));
        $c = json_decode((string) file_get_contents(ROOT_STORAGE . '/psd2-token.json'), true);
        $c['access_until'] = time() - 10;   // přístupový token vypršel, obnovovací platí
        file_put_contents(ROOT_STORAGE . '/psd2-token.json', json_encode($c));
        @unlink(ROOT_STORAGE . '/psd2-institutions-cz.json');
        $this->calls = [];
        $this->assertIsArray(psd2_institutions());
        $this->assertSame(self::BASE . '/token/refresh/', $this->calls[0][1]);
        $this->assertSame(['refresh' => 'REFRESH-1'], $this->calls[0][3]);
        $this->assertContains('Authorization: Bearer ACCESS-2', $this->calls[1][2]);
    }
    public function testExpiryWarningsAndDaily(): void
    {
        [$ref] = $this->connectAndLink();
        $id = (int) val('SELECT id FROM psd2_links WHERE reference = ?', [$ref]);
        q("UPDATE psd2_accounts SET fetch_day = '2000-01-01'");
        q('UPDATE psd2_links SET valid_until = ? WHERE id = ?', [date('Y-m-d', strtotime('+10 day')), $id]);
        $this->assertSame(1, psd2_daily());
        $mails = array_filter($this->mails(), fn($m) => str_contains($m['subject'], 'Obnovte'));
        $this->assertNotEmpty($mails);
        $this->assertStringContainsString('/admin/platby/banky', array_values($mails)[0]['body']);
        $this->assertSame(14, (int) val('SELECT warned FROM psd2_links WHERE id = ?', [$id]));
        $count = count($mails);
        psd2_daily();
        $this->assertCount($count, array_filter($this->mails(), fn($m) => str_contains($m['subject'], 'Obnovte')), 'upozornění jen jednou');
        q('UPDATE psd2_links SET valid_until = ? WHERE id = ?', [date('Y-m-d', strtotime('+2 day')), $id]);
        psd2_daily();
        $this->assertSame(3, (int) val('SELECT warned FROM psd2_links WHERE id = ?', [$id]));
        $this->assertGreaterThan($count, count(array_filter($this->mails(), fn($m) => str_contains($m['subject'], 'Obnovte'))));
        q('UPDATE psd2_links SET valid_until = ? WHERE id = ?', [date('Y-m-d', strtotime('-1 day')), $id]);
        psd2_daily();
        $this->assertSame('EX', val('SELECT status FROM psd2_links WHERE id = ?', [$id]));
    }
    public function testBankDailyRunsPsd2Hook(): void
    {
        [$ref] = $this->connectAndLink();
        q("UPDATE psd2_accounts SET fetch_day = '2000-01-01', fetch_count = 0");
        $n = $this->txCalls();
        bank_daily();
        $this->assertSame($n + 1, $this->txCalls());
    }
    public function testBetaGatingAndDelete(): void
    {
        $this->assertArrayHasKey('bank-psd2', BETA_FEATURES);
        $this->assertTrue(psd2_available());
        pset_save('beta_bank-psd2', 'off');
        $this->assertFalse(psd2_available());
        $this->assertSame(0, psd2_daily());
        pq("DELETE FROM platform_settings WHERE key = 'beta_bank-psd2'");
        pset('__reset');
        $this->assertSame('off', beta_stage('bank-psd2'), 'výchozí stav je vypnuto');
        pset_save('beta_bank-psd2', 'on');
        [$ref] = $this->connectAndLink();
        $id = (int) val('SELECT id FROM psd2_links WHERE reference = ?', [$ref]);
        psd2_delete($id);
        $this->assertNull(one('SELECT * FROM psd2_links WHERE id = ?', [$id]));
        $this->assertSame(0, (int) val('SELECT COUNT(*) FROM psd2_accounts WHERE link_id = ?', [$id]));
        $this->assertSame('DELETE', end($this->calls)[0]);
        $this->assertGreaterThan(0, (int) val("SELECT COUNT(*) FROM bank_tx WHERE source = 'psd2'"), 'stažené platby zůstávají');
    }

    public function testViewsRender(): void
    {
        $this->loginAs('admin');
        $this->connectAndLink();
        q('UPDATE psd2_links SET valid_until = ?', [date('Y-m-d', strtotime('+5 day'))]);
        $links = psd2_links();
        extract(['links' => $links, 'institutions' => psd2_institutions(), 'instError' => null]);
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/bank/psd2.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('ČSOB', $html);
        $this->assertStringContainsString('123456789/0300', $html);
        $this->assertStringContainsString('Souhlas vyprší', $html);
        $this->assertStringContainsString('Komerční banka', $html);
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/bank/_panel.php';
        $panel = (string) ob_get_clean();
        $this->assertStringContainsString('Propojené účty: 1', $panel);
        pset_save('beta_bank-psd2', 'off');
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/bank/_panel.php';
        $this->assertSame('', trim((string) ob_get_clean()));
    }
}
