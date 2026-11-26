<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

require_once __DIR__ . '/ModEHelper.php';

/** Modul E – sledování změn v katastru (app/ext/rej_katastr_lib.php): čtení výpisu LV, porovnání, WSDP přes podstrčenou síť. */
final class ModEKatastrTest extends TestCase
{
    use ModEHelper;

    // ---------- čtení výpisu LV ----------

    public function testParseLvFixture(): void
    {
        $p = rej_katastr_parse_lv(self::fixture('lv_budova.xml'));
        $this->assertSame('', $p['error']);
        $this->assertSame('4321', $p['lv']);
        $this->assertSame(['1234/1', '1234/2', '1234/3', '1234/4', '1235/1', '1235/2', '1235/3'], array_keys($p['units']));
        $this->assertSame(['cp' => '1234', 'number' => '1', 'share' => '203/2500', 'owners' => [['name' => 'Výborová Jana', 'share' => '1/1']], 'lv' => '5001'], $p['units']['1234/1']);
        $this->assertSame('SJM Novák Petr a Nováková Marie', $p['units']['1234/2']['owners'][0]['name']);
        $this->assertSame('Malý Ondřej', $p['units']['1234/3']['owners'][0]['name'], 'titles are not part of the name');
        $this->assertSame('Stavby Novák s.r.o.', $p['units']['1234/4']['owners'][0]['name']);
        $this->assertSame('89/1000', $p['units']['1235/1']['share'], 'fraction is reduced');
        $this->assertSame([['name' => 'Marek Pavel', 'share' => '1/2'], ['name' => 'Marková Jitka', 'share' => '1/2']], $p['units']['1235/3']['owners']);
        $this->assertSame(['Budova: Z-777/2026-701', 'Řízení V-5678/2026-701'], $p['plomby']);
    }

    public function testParseLvRejectsGarbage(): void
    {
        foreach (['', 'není xml', '<a><b></a>'] as $bad) {
            $p = rej_katastr_parse_lv($bad);
            $this->assertSame([], $p['units']);
            $this->assertNotSame('', $p['error']);
        }
        $p = rej_katastr_parse_lv('<VypisZKatastruNemovitosti><LIST_TEXT/></VypisZKatastruNemovitosti>');
        $this->assertSame('', $p['error']);
        $this->assertSame([], $p['units']);
    }

    public function testParseUnitWithPlombaAndNumberWithoutBuilding(): void
    {
        $xml = '<VypisZKatastruNemovitosti><JEDNOTKY><JEDNOTKA><PLOMBA_JEDNOTKY>V-12/2026-701</PLOMBA_JEDNOTKY>'
            . '<CISLO_JEDNOTKY><jednotka><id>1</id><cp_ident>č.p. 88</cp_ident><jed_ident>7</jed_ident></jednotka></CISLO_JEDNOTKY></JEDNOTKA></JEDNOTKY></VypisZKatastruNemovitosti>';
        $p = rej_katastr_parse_lv($xml);
        $this->assertSame(['88/7'], array_keys($p['units']));
        $this->assertSame(['Jednotka 88/7: V-12/2026-701'], $p['plomby']);
    }

    // ---------- pomocné funkce ----------

    public function testOwnerNoteRoundTrip(): void
    {
        $owners = [['name' => 'Marek Pavel', 'share' => '1/2'], ['name' => 'Marková Jitka', 'share' => '1/2']];
        $this->assertSame('Marek Pavel (1/2); Marková Jitka (1/2)', rej_owner_note($owners));
        $this->assertSame($owners, rej_owners_from_note('Marek Pavel (1/2); Marková Jitka (1 / 2)'));
        $this->assertSame('Výborová Jana', rej_owner_note([['name' => 'Výborová Jana', 'share' => '1/1']]), 'single owner without share');
        $this->assertSame([], rej_owners_from_note(''));
    }

    public function testNameKeyIgnoresCaseTitlesAndOrder(): void
    {
        $this->assertSame(rej_name_key('Novák Petr'), rej_name_key('Ing. Petr NOVÁK'));
        $this->assertSame(rej_name_key('SJM Novák Petr a Nováková Marie'), rej_name_key('Novák Petr a Nováková Marie (SJM)'));
        $this->assertNotSame(rej_name_key('Novák Petr'), rej_name_key('Nováková Petra'));
    }

    public function testFractionNorm(): void
    {
        $this->assertSame('203/2500', rej_fraction_norm('812/10000'));
        $this->assertSame('1/2', rej_fraction_norm(' 2 / 4 '));
        $this->assertSame('', rej_fraction_norm('1/0'));
        $this->assertSame('', rej_fraction_norm('půl'));
        $this->assertSame('', rej_fraction_norm(''));
    }

    public function testTargetValidation(): void
    {
        $this->assertNull(rej_katastr_target_error('610372', '4321'));
        $this->assertNull(rej_katastr_target_error('610372', '4321, 4322'));
        $this->assertNotNull(rej_katastr_target_error('61037', '4321'));
        $this->assertNotNull(rej_katastr_target_error('610372', ''));
        $this->assertNotNull(rej_katastr_target_error('610372', '12a'));
        $this->assertNotNull(rej_katastr_target_error('610372', '1,2,3,4,5,6'));
        rej_set('rej_katastr_ku', '610372');
        rej_set('rej_katastr_lv', '4321,abc,4321,77');
        $this->assertSame(['ku' => '610372', 'lv' => ['4321', '77']], rej_katastr_target(), 'invalid and duplicate LV numbers are dropped');
    }

    public function testMapUsesEntranceLettersByHouseNumber(): void
    {
        $units = rej_katastr_parse_lv(self::fixture('lv_budova.xml'))['units'];
        $entr = rej_katastr_entrances($units);
        $this->assertSame('A', $entr['1234/1']);
        $this->assertSame('B', $entr['1235/3']);
        $map = rej_katastr_map($units);
        $this->assertSame((int) val("SELECT id FROM units WHERE entrance = 'A' AND number = '3'"), $map['1234/3']);
        $this->assertSame((int) val("SELECT id FROM units WHERE entrance = 'B' AND number = '1'"), $map['1235/1']);
        // více čísel popisných než vchodů → vchod = č.p. (jako u importu z katastru)
        $three = ['1/1' => ['cp' => '1', 'number' => '1'], '2/1' => ['cp' => '2', 'number' => '1'], '3/1' => ['cp' => '3', 'number' => '1']];
        $this->assertSame(['1/1' => '1', '2/1' => '2', '3/1' => '3'], rej_katastr_entrances($three));
        $this->assertSame(['1/1' => 0, '2/1' => 0, '3/1' => 0], rej_katastr_map($three));
    }

    // ---------- porovnání ----------

    public function testFirstDiffAgainstUnitEvidence(): void
    {
        $now = rej_katastr_parse_lv(self::fixture('lv_budova.xml'));
        $base = rej_katastr_baseline_from_units($now['units']);
        $this->assertTrue($base['from_units']);
        $this->assertCount(7, $base['units']);
        $changes = rej_katastr_diff($base, $now);
        $got = array_map(fn($c) => $c['unit_key'] . ' ' . $c['kind'] . ': ' . $c['old'] . ' → ' . $c['new'], $changes);
        $this->assertSame([
            '1234/3 owner: Dvořák Tomáš → Malý Ondřej',
            '1234/4 owner: Svobodová Eva → Stavby Novák s.r.o.',
            '1235/2 share: 1010/10000 → 1/10',
            '1235/3 owner: Marek Pavel → Marek Pavel (1/2); Marková Jitka (1/2)',
            ' plomba:  → Budova: Z-777/2026-701',
            ' plomba:  → Řízení V-5678/2026-701',
        ], $got, 'SJM in another notation and equal shares (445/5000 = 890/10000) are not changes');
    }

    public function testDiffBetweenTwoCadastreStates(): void
    {
        $base = rej_katastr_parse_lv(self::fixture('lv_budova.xml'));
        $now = $base;
        unset($now['units']['1234/4']);
        $now['units']['1234/5'] = ['cp' => '1234', 'number' => '5', 'share' => '1/100', 'owners' => [['name' => 'Nový Adam', 'share' => '1/1']], 'lv' => ''];
        $now['units']['1235/3']['owners'][0]['share'] = '1/4';
        $now['units']['1235/3']['owners'][1]['share'] = '3/4';
        $now['plomby'] = ['Řízení V-5678/2026-701'];   // plomba budovy zmizela – není to změna k vyřízení
        $kinds = array_map(fn($c) => $c['unit_key'] . ' ' . $c['kind'], rej_katastr_diff($base, $now));
        $this->assertSame(['1235/3 owner', '1234/5 new_unit', '1234/4 gone_unit'], $kinds);
        $this->assertSame([], rej_katastr_diff($base, $base), 'same state, no changes');
    }

    // ---------- WSDP ----------

    public function testEnvelopeEscapesCredentialsAndValues(): void
    {
        $this->katastrConfig();
        $xml = rej_wsdp_envelope('sestavy', 'GenerujLVZjednoduseneRequest', ['katastrUzemiKod' => '610372', 'lvCislo' => '4321', 'format' => 'xml']);
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        $this->assertStringContainsString('xmlns:typ="urn:cz:gov:cuzk:iskn:types:wsdp:sestavy:3.1"', $xml);
        $this->assertSame('heslo<&>', $dom->getElementsByTagName('Password')->item(0)->textContent);
        $this->assertStringContainsString('#PasswordText', $xml);
        $this->assertSame('4321', $dom->getElementsByTagNameNS('urn:cz:gov:cuzk:iskn:types:wsdp:sestavy:3.1', 'lvCislo')->item(0)->textContent);
    }

    public function testCallWithoutAccountDoesNotTouchNetwork(): void
    {
        $GLOBALS['REJ_CONFIG']['katastr'] = [];
        $this->assertFalse(rej_katastr_configured());
        $r = rej_wsdp_call('sestavy', 'stavWS', 'StavWSRequest', []);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('není nastavený', $r['error']);
        $this->assertSame([], $this->calls);
    }

    public function testCallReportsFaultsAndServerErrors(): void
    {
        $this->katastrConfig();
        $this->fakeHttp(fn() => ['status' => 500, 'body' => '<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body><env:Fault><faultcode>wsse:FailedAuthentication</faultcode>'
            . '<faultstring>Failed to assert identity with UsernameToken.</faultstring></env:Fault></env:Body></env:Envelope>']);
        $r = rej_wsdp_call('sestavy', 'stavWS', 'StavWSRequest', []);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('přihlašovací údaje', $r['error']);
        $this->assertSame('https://wsdp.test.invalid/ws/wsdp/3.1/sestavy', $this->calls[0]['url']);
        $this->assertContains('SOAPAction: "urn:cz:gov:cuzk:iskn:services:wsdp:sestavy:stavWS"', $this->calls[0]['headers']);

        $this->fakeHttp(fn() => ['status' => 200, 'body' => '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><r xmlns:c="urn:x"><c:zprava kod="110" uroven="CHYBA">Parametr castObceKod je povinný!</c:zprava></r></s:Body></s:Envelope>']);
        $r = rej_wsdp_call('sestavy', 'stavWS', 'StavWSRequest', []);
        $this->assertFalse($r['ok']);
        $this->assertSame('Katastr: Parametr castObceKod je povinný! (kód 110)', $r['error']);
        $this->assertSame([[110, 'CHYBA', 'Parametr castObceKod je povinný!']], $r['messages']);

        $this->fakeHttp(fn() => ['status' => 0, 'body' => '', 'error' => 'timeout']);
        $this->assertStringContainsString('neodpovídá', rej_wsdp_call('sestavy', 'stavWS', 'StavWSRequest', [])['error']);
        $this->fakeHttp(fn() => ['status' => 502, 'body' => '<html>Bad gateway']);
        $this->assertStringContainsString('není XML', rej_wsdp_call('sestavy', 'stavWS', 'StavWSRequest', [])['error']);
    }

    public function testReportAttachmentViaMtom(): void
    {
        $this->katastrConfig();
        $lv = self::fixture('lv_budova.xml');
        $root = '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><x:GenerujSestavuResponse xmlns:x="urn:cz:gov:cuzk:iskn:types:wsdp:sestavy:3.1">'
            . '<x:reportList><x:report><x:id>9</x:id><x:stav>zpracován</x:stav><x:cena>100.00</x:cena><x:souborSestavy><xop:Include xmlns:xop="http://www.w3.org/2004/08/xop/include" href="cid:lv%40cuzk"/></x:souborSestavy>'
            . '</x:report></x:reportList></x:GenerujSestavuResponse></s:Body></s:Envelope>';
        $body = "--MIMEBoundary\r\nContent-Type: application/xop+xml; charset=UTF-8\r\nContent-ID: <root>\r\n\r\n" . $root
            . "\r\n--MIMEBoundary\r\nContent-Type: application/octet-stream\r\nContent-Transfer-Encoding: binary\r\nContent-ID: <lv@cuzk>\r\n\r\n" . $lv . "\r\n--MIMEBoundary--\r\n";
        $this->fakeHttp(fn() => ['status' => 200, 'body' => $body]);
        $rep = rej_wsdp_report(rej_wsdp_call('sestavy', 'vratSestavu', 'VratSestavuRequest', ['idSestavy' => '9']));
        $this->assertSame(['id' => '9', 'stav' => 'zpracován', 'cena' => 100, 'content' => $lv], $rep);
        $this->assertCount(2, rej_mime_parts($body));
        $this->assertSame([['headers' => [], 'body' => 'plain']], rej_mime_parts('plain'));
    }

    // ---------- celá kontrola ----------

    private function prepareTarget(): void
    {
        $this->katastrConfig();
        rej_set('rej_katastr_ku', '610372');
        rej_set('rej_katastr_lv', '4321');
    }

    public function testRunRecordsChangesMailsAdminsAndUsage(): void
    {
        $this->prepareTarget();
        $lv = self::fixture('lv_budova.xml');
        $ret = 0;
        $this->fakeHttp(function ($m, $url, $h, $body) use ($lv, &$ret) {
            if (str_contains($body, 'GenerujLVZjednoduseneRequest')) {
                return ['status' => 200, 'body' => self::wsdpReport('555', 'čeká')];
            }
            $ret++;
            return ['status' => 200, 'body' => $ret === 1 ? self::wsdpReport('555', 'vytváří se') : self::wsdpReport('555', 'zaúčtován', $lv, '100')];
        });
        $r = rej_katastr_run();
        $this->assertSame(['ok' => true, 'new' => 6, 'pending' => false, 'error' => ''], $r);
        $this->assertSame(3, count($this->calls), 'generate + 2× return');
        $this->assertStringContainsString('<typ:katastrUzemiKod>610372</typ:katastrUzemiKod><typ:lvCislo>4321</typ:lvCislo><typ:format>xml</typ:format>', $this->calls[0]['body']);
        $this->assertStringContainsString('<typ:idSestavy>555</typ:idSestavy>', $this->calls[1]['body']);

        $rows = all('SELECT * FROM rej_katastr_changes ORDER BY id');
        $this->assertCount(6, $rows);
        $this->assertSame('new', $rows[0]['status']);
        $this->assertSame((int) val("SELECT id FROM units WHERE entrance = 'A' AND number = '3'"), (int) $rows[0]['unit_id']);
        $mails = $this->decodedMails();
        $this->assertCount(count(rej_admin_emails()), $mails);
        $this->assertStringContainsString('Změny v katastru', $mails[0]['subject']);
        $this->assertStringContainsString('Dvořák Tomáš → Malý Ondřej', $mails[0]['body']);
        $this->assertStringStartsWith(date('Y-m-d'), setting('rej_katastr_last'));
        $this->assertStringContainsString('|ok|', setting('rej_katastr_last'));
        $usage = pq("SELECT * FROM rej_usage WHERE kind = 'katastr_lv'")->fetchAll();
        $this->assertCount(1, $usage);
        $this->assertSame(100, (int) $usage[0]['cost']);
        $this->assertSame(0, (int) $usage[0]['price'], 'LV extracts are covered by the yearly price');

        // druhá kontrola se stejným výpisem: nic nového, žádný e-mail
        $ret = 0;
        $before = count($this->mails());
        $this->assertSame(0, rej_katastr_run()['new']);
        $this->assertCount($before, $this->mails());
        $this->assertSame(6, (int) val('SELECT COUNT(*) FROM rej_katastr_changes'));
    }

    public function testRunKeepsPendingReportForNextTime(): void
    {
        $this->prepareTarget();
        $this->fakeHttp(fn($m, $u, $h, $body) => ['status' => 200, 'body' => str_contains($body, 'Generuj') ? self::wsdpReport('777', 'čeká') : self::wsdpReport('777', 'vytváří se')]);
        $r = rej_katastr_run();
        $this->assertTrue($r['pending']);
        $this->assertSame(['4321' => '777'], json_decode(setting('rej_katastr_pending'), true));
        $this->assertStringContainsString('|pending|', setting('rej_katastr_last'));
        $this->assertSame(0, (int) val('SELECT COUNT(*) FROM rej_katastr_changes'));

        $this->calls = [];
        $lv = self::fixture('lv_budova.xml');
        $this->fakeHttp(fn($m, $u, $h, $body) => ['status' => 200, 'body' => self::wsdpReport('777', 'zpracován', $lv)]);
        $r = rej_katastr_run(false);
        $this->assertFalse($r['pending']);
        $this->assertCount(1, $this->calls, 'pending report is only collected, not generated again');
        $this->assertStringContainsString('VratSestavuRequest', $this->calls[0]['body']);
        $this->assertSame('', setting('rej_katastr_pending'));
    }

    public function testRunFailsCleanlyWithoutTargetOrOnError(): void
    {
        $this->katastrConfig();
        $r = rej_katastr_run();
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('katastrálního území', $r['error']);
        $this->assertSame([], $this->calls);

        $this->prepareTarget();
        $this->fakeHttp(fn() => ['status' => 200, 'body' => '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><r><zprava kod="301" uroven="CHYBA">LV neexistuje</zprava></r></s:Body></s:Envelope>']);
        $r = rej_katastr_run();
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('LV neexistuje', $r['error']);
        $this->assertStringContainsString('|chyba|', setting('rej_katastr_last'));
        $this->assertSame('', setting('rej_katastr_base'), 'baseline untouched on error');
    }

    // ---------- vyřízení změn ----------

    private function storeFixtureChanges(): array
    {
        $this->prepareTarget();
        $lv = self::fixture('lv_budova.xml');
        $this->fakeHttp(fn($m, $u, $h, $body) => ['status' => 200, 'body' => str_contains($body, 'Generuj') ? self::wsdpReport('1', 'čeká') : self::wsdpReport('1', 'zpracován', $lv)]);
        rej_katastr_run(false);
        return array_column(all('SELECT * FROM rej_katastr_changes'), null, 'unit_key');
    }

    public function testApplyOwnerShareAndDismiss(): void
    {
        $ch = $this->storeFixtureChanges();
        $unitA3 = (int) val("SELECT id FROM units WHERE entrance = 'A' AND number = '3'");
        rej_person_add($unitA3, 'Dvořák', 'Tomáš', '1970-01-01', 1);

        $msg = rej_katastr_handle((int) $ch['1234/3']['id'], 'apply', 'Správce');
        $this->assertStringContainsString('převzatý', $msg);
        $this->assertStringContainsString('bývalého vlastníka', $msg);
        $this->assertSame('Malý Ondřej', val('SELECT owner_note FROM units WHERE id = ?', [$unitA3]));
        $this->assertSame(0, (int) val('SELECT COUNT(*) FROM rej_persons WHERE unit_id = ?', [$unitA3]), 'former owner data removed');
        $row = one('SELECT * FROM rej_katastr_changes WHERE id = ?', [$ch['1234/3']['id']]);
        $this->assertSame('applied', $row['status']);
        $this->assertSame('Správce', $row['handled_by']);
        $this->assertSame('Změna už je vyřízená.', rej_katastr_handle((int) $ch['1234/3']['id'], 'apply', 'X'));

        rej_katastr_handle((int) $ch['1235/2']['id'], 'apply', 'Správce');
        $this->assertSame('1/10', val("SELECT share FROM units WHERE entrance = 'B' AND number = '2'"));

        $plomba = one("SELECT * FROM rej_katastr_changes WHERE kind = 'plomba' LIMIT 1");
        $this->assertStringContainsString('nic nemění', rej_katastr_handle((int) $plomba['id'], 'apply', 'Správce'));
        $before = val("SELECT owner_note FROM units WHERE entrance = 'A' AND number = '4'");
        rej_katastr_handle((int) $ch['1234/4']['id'], 'dismiss', 'Správce');
        $this->assertSame('dismissed', val('SELECT status FROM rej_katastr_changes WHERE id = ?', [$ch['1234/4']['id']]));
        $this->assertSame($before, val("SELECT owner_note FROM units WHERE entrance = 'A' AND number = '4'"), 'dismiss does not touch evidence');
    }

    public function testApplyNewUnitCreatesItOnce(): void
    {
        q("INSERT INTO rej_katastr_changes (found_at, unit_key, unit_id, kind, old_value, new_value, data, status) VALUES (?,?,?,?,?,?,?,?)",
            [date('Y-m-d H:i:s'), '1234/9', 0, 'new_unit', '', 'Nový Adam', json_encode(['cp' => '1234', 'number' => '9', 'share' => '1/100', 'entrance' => 'A']), 'new']);
        $id = (int) db()->lastInsertId();
        $this->assertStringContainsString('založená', rej_katastr_handle($id, 'apply', 'S'));
        $this->assertSame(['share' => '1/100', 'owner_note' => 'Nový Adam'], one("SELECT share, owner_note FROM units WHERE entrance = 'A' AND number = '9'"));
        q("INSERT INTO rej_katastr_changes (found_at, unit_key, unit_id, kind, old_value, new_value, data, status) VALUES (?,?,?,?,?,?,?,?)",
            [date('Y-m-d H:i:s'), '1234/9', 0, 'new_unit', '', 'Nový Adam', json_encode(['number' => '9', 'entrance' => 'A']), 'new']);
        $this->assertSame('Jednotka už v evidenci je.', rej_katastr_handle((int) db()->lastInsertId(), 'apply', 'S'));
        $this->assertSame(1, (int) val("SELECT COUNT(*) FROM units WHERE entrance = 'A' AND number = '9'"));
    }

    public function testOwnerChangeForUnknownUnitIsNotApplied(): void
    {
        q("INSERT INTO rej_katastr_changes (found_at, unit_key, unit_id, kind, old_value, new_value, data, status) VALUES (?,?,?,?,?,?,?,?)",
            [date('Y-m-d H:i:s'), '9999/1', 0, 'owner', 'A', 'B', '{}', 'new']);
        $id = (int) db()->lastInsertId();
        $this->assertStringContainsString('nenašel', rej_katastr_handle($id, 'apply', 'S'));
        $this->assertSame('new', val('SELECT status FROM rej_katastr_changes WHERE id = ?', [$id]));
    }

    // ---------- zapnutí, roční poplatek, denní úloha ----------

    public function testActivateRecordsYearlyFee(): void
    {
        $this->pset('rej_price_katastr_unit', '25');
        $units = (int) val('SELECT COUNT(*) FROM units');
        $this->assertSame(25 * $units, rej_katastr_activate());
        $this->assertSame(date('Y-m-d'), setting('rej_katastr_on'));
        $u = pq("SELECT * FROM rej_usage WHERE kind = 'katastr_year'")->fetch();
        $this->assertSame([$units, 25 * $units, 'dum'], [(int) $u['qty'], (int) $u['price'], $u['tenant']]);
        rej_katastr_deactivate();
        $this->assertSame('', setting('rej_katastr_on'));
    }

    public function testDailyRespectsBetaActivationAndInterval(): void
    {
        $this->prepareTarget();
        $this->pset('beta_katastr', 'off');
        rej_set('rej_katastr_on', '2026-01-01');
        rej_katastr_daily();
        $this->assertSame([], $this->calls, 'beta off → nothing');

        $this->pset('beta_katastr', 'on');
        rej_set('rej_katastr_on', '');
        rej_katastr_daily();
        $this->assertSame([], $this->calls, 'not activated by the house → nothing');

        rej_set('rej_katastr_on', date('Y-m-d'));
        rej_set('rej_katastr_year', date('Y-m-d'));
        rej_set('rej_katastr_last', date('Y-m-d H:i', time() - 2 * 86400) . '|ok|x');
        rej_katastr_daily();
        $this->assertSame([], $this->calls, 'checked 2 days ago, interval 7 days');

        $this->pset('rej_katastr_days', '1');
        $this->fakeHttp(fn() => ['status' => 0, 'body' => '', 'error' => 'offline']);
        rej_katastr_daily();
        $this->assertNotSame([], $this->calls, 'interval of 1 day → checks again');
        $this->assertStringContainsString('|chyba|', setting('rej_katastr_last'));
    }

    public function testDailyChargesNextYear(): void
    {
        $this->prepareTarget();
        $this->pset('beta_katastr', 'on');
        rej_set('rej_katastr_on', '2025-01-01');
        rej_set('rej_katastr_year', date('Y-m-d', strtotime('-1 year -1 day')));
        rej_set('rej_katastr_last', date('Y-m-d H:i') . '|ok|x');
        rej_katastr_daily();
        $this->assertSame(1, (int) pq("SELECT COUNT(*) FROM rej_usage WHERE kind = 'katastr_year'")->fetchColumn());
        $this->assertSame(date('Y-m-d'), setting('rej_katastr_year'));
        rej_katastr_daily();
        $this->assertSame(1, (int) pq("SELECT COUNT(*) FROM rej_usage WHERE kind = 'katastr_year'")->fetchColumn(), 'only once a year');
        $this->assertSame([], $this->calls);
    }
}
