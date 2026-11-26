<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

require_once __DIR__ . '/ModEHelper.php';

/** Modul E – insolvence fyzických osob (app/ext/rej_isir_lib.php). Rejstřík je podstrčený, síť se nevolá. */
final class ModEIsirTest extends TestCase
{
    use ModEHelper;

    private static function isirResponse(array $rows, string $code = 'OK'): string
    {
        $data = '';
        foreach ($rows as $r) {
            $data .= '<data>';
            foreach ($r as $k => $v) {
                $data .= "<$k>" . htmlspecialchars($v) . "</$k>";
            }
            $data .= '</data>';
        }
        return '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:getIsirWsCuzkDataResponse xmlns:ns2="http://isirws.cca.cz/types/">'
            . $data . '<stav><kodChyby>' . $code . '</kodChyby><textChyby>' . ($code === 'WS2' ? 'Nebyl nalezen žádný záznam' : '') . '</textChyby></stav></ns2:getIsirWsCuzkDataResponse></soap:Body></soap:Envelope>';
    }

    private static function hit(string $birth, string $bc = '1234'): array
    {
        return ['nazevOsoby' => 'Dvořák', 'jmeno' => 'Tomáš', 'datumNarozeni' => $birth, 'cisloSenatu' => '27', 'druhVec' => 'INS', 'bcVec' => $bc, 'rocnik' => '2026',
            'nazevOrganizace' => 'Krajský soud v Brně', 'druhStavKonkursu' => 'MORATORIUM', 'urlDetailRizeni' => 'https://isir.justice.cz/detail'];
    }

    public function testPersonValidation(): void
    {
        $this->assertNull(rej_person_error('Dvořák', 'Tomáš', '1970-05-01'));
        $this->assertNull(rej_person_error('Dvořák', '', '1970-05-01'), 'first name is optional');
        $this->assertNotNull(rej_person_error('', 'Tomáš', '1970-05-01'));
        $this->assertNotNull(rej_person_error('D', 'Tomáš', '1970-05-01'));
        $this->assertNotNull(rej_person_error('Dvořák1', 'Tomáš', '1970-05-01'));
        $this->assertNotNull(rej_person_error('Dvořák', '<b>', '1970-05-01'));
        $this->assertNotNull(rej_person_error('Dvořák', 'Tomáš', '1970-02-30'));
        $this->assertNotNull(rej_person_error('Dvořák', 'Tomáš', '01.05.1970'));
        $this->assertNotNull(rej_person_error('Dvořák', 'Tomáš', date('Y-m-d', strtotime('-10 years'))), 'child');
        $this->assertNotNull(rej_person_error('Dvořák', 'Tomáš', '1899-12-31'));
    }

    public function testOwnerPeopleSuggestions(): void
    {
        $this->assertSame([['surname' => 'Novák', 'first' => 'Petr'], ['surname' => 'Nováková', 'first' => 'Marie']], rej_owner_people('Novák Petr a Nováková Marie (SJM)'));
        $this->assertSame([['surname' => 'Novák', 'first' => 'Petr'], ['surname' => 'Nováková', 'first' => 'Marie']], rej_owner_people('SJM Novák Petr a Nováková Marie'));
        $this->assertSame([['surname' => 'Marek', 'first' => 'Pavel'], ['surname' => 'Marková', 'first' => 'Jitka']], rej_owner_people('Ing. Marek Pavel (1/2); Marková Jitka (1/2)'));
        $this->assertSame([['surname' => 'Horák', 'first' => 'Jiří']], rej_owner_people('Horák Jiří, Lipová 5, Brno'));
        $this->assertSame([], rej_owner_people('Stavby Novák s.r.o.'), 'companies are skipped');
        $this->assertSame([], rej_owner_people('Novák'), 'surname alone is not enough');
        $this->assertSame([], rej_owner_people(''));
    }

    public function testAddDuplicateAndDelete(): void
    {
        $this->assertNull(rej_person_add(3, 'Dvořák', 'Tomáš', '1970-05-01', 1));
        $this->assertSame('Tuto osobu už u jednotky hlídáte.', rej_person_add(3, 'Dvořák', 'Tomáš', '1970-05-01', 1));
        $this->assertSame('Jednotka neexistuje.', rej_person_add(99999, 'Dvořák', 'Tomáš', '1970-05-01', 1));
        $this->assertNotNull(rej_person_add(3, '', 'Tomáš', '1970-05-01', 1));
        $p = rej_persons();
        $this->assertCount(1, $p);
        $this->assertSame(['3', 'A', 'Dvořák', '1970-05-01'], [(string) $p[0]['unit_id'], $p[0]['entrance'], $p[0]['surname'], $p[0]['birth_date']]);

        q("INSERT INTO rej_exekuce (at, person_id, subject, query_kind) VALUES (?,?,?,?)", [date('Y-m-d H:i:s'), $p[0]['id'], 'x', 'F']);
        $this->assertTrue(rej_person_delete((int) $p[0]['id']));
        $this->assertSame([], rej_persons());
        $this->assertSame(0, (int) val('SELECT COUNT(*) FROM rej_exekuce'), 'execution checks of the person are deleted too');
        $this->assertFalse(rej_person_delete((int) $p[0]['id']));
    }

    public function testCheckPersonSendsNameAndBirthAndFiltersOtherPeople(): void
    {
        $this->fakeHttp(fn() => ['status' => 200, 'body' => self::isirResponse([self::hit('1970-05-01'), self::hit('1985-01-01', '999')])]);
        $r = rej_isir_check_person('Dvořák', 'Tomáš', '1970-05-01');
        $this->assertTrue($r['ok']);
        $this->assertCount(1, $r['found'], 'same name, other birth date is ignored');
        $this->assertSame('27 INS 1234/2026', $r['found'][0]['sz']);
        $this->assertSame('Krajský soud v Brně', $r['found'][0]['court']);
        $body = $this->calls[0]['body'];
        $this->assertStringContainsString('<nazevOsoby>Dvořák</nazevOsoby><jmeno>Tomáš</jmeno><datumNarozeni>1970-05-01</datumNarozeni>', $body);
        $this->assertStringContainsString('<filtrAktualniRizeni>T</filtrAktualniRizeni>', $body);
        $this->assertStringNotContainsString('<rc>', $body, 'birth number is never sent');
        $this->assertSame(ISIR_URL, $this->calls[0]['url']);
    }

    public function testCheckPersonValidatesBeforeNetworkAndHandlesErrors(): void
    {
        $this->assertFalse(rej_isir_check_person('', 'X', '1970-05-01')['ok']);
        $this->assertSame([], $this->calls);
        $this->fakeHttp(fn() => ['status' => 200, 'body' => self::isirResponse([], 'WS2')]);
        $this->assertSame(['ok' => true, 'found' => [], 'error' => ''], rej_isir_check_person('Dvořák', 'Tomáš', '1970-05-01'), 'WS2 = nothing found');
        $this->fakeHttp(fn() => ['status' => 200, 'body' => self::isirResponse([], 'WS9')]);
        $this->assertStringContainsString('kód WS9', rej_isir_check_person('Dvořák', 'Tomáš', '1970-05-01')['error']);
        $this->fakeHttp(fn() => ['status' => 500, 'body' => '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault><faultstring>Chybný požadavek</faultstring></s:Fault></s:Body></s:Envelope>']);
        $this->assertSame('Rejstřík vrátil chybu: Chybný požadavek', rej_isir_check_person('Dvořák', 'Tomáš', '1970-05-01')['error']);
        $this->fakeHttp(fn() => ['status' => 0, 'body' => '', 'error' => 'timeout']);
        $this->assertStringContainsString('neodpovídá', rej_isir_check_person('Dvořák', 'Tomáš', '1970-05-01')['error']);
        $this->fakeHttp(fn() => ['status' => 200, 'body' => 'nic']);
        $this->assertStringContainsString('není XML', rej_isir_check_person('Dvořák', 'Tomáš', '1970-05-01')['error']);
    }

    public function testRunStoresResultsAndMailsOnlyNewProceedings(): void
    {
        rej_person_add(3, 'Dvořák', 'Tomáš', '1970-05-01', 1);
        rej_person_add(5, 'Horák', 'Jiří', '1960-02-02', 1);
        $this->fakeHttp(fn($m, $u, $h, $body) => ['status' => 200, 'body' => str_contains($body, 'Dvořák') ? self::isirResponse([self::hit('1970-05-01')]) : self::isirResponse([], 'WS2')]);
        $this->assertSame([2, 1, ''], rej_isir_run());
        $mails = $this->decodedMails();
        $this->assertCount(count(rej_admin_emails()), $mails);
        $this->assertStringContainsString('Insolvence vlastníka jednotky', $mails[0]['subject']);
        $this->assertStringContainsString('Dvořák Tomáš', $mails[0]['body']);
        $this->assertStringContainsString('27 INS 1234/2026', $mails[0]['body']);
        $this->assertStringNotContainsString('1970', $mails[0]['body'], 'birth date is not sent by e-mail');
        $p = array_column(rej_persons(), null, 'surname');
        $this->assertSame('27 INS 1234/2026', json_decode($p['Dvořák']['isir_found'], true)[0]['sz']);
        $this->assertSame('', $p['Horák']['isir_found']);
        $this->assertStringContainsString('|ok|ověřeno osob 2, řízení 1', setting('rej_isir_last'));

        $n = count($this->mails());
        rej_isir_run();
        $this->assertCount($n, $this->mails(), 'known proceeding is not mailed again');
    }

    public function testRunStopsOnServiceErrorAndKeepsResults(): void
    {
        rej_person_add(3, 'Dvořák', 'Tomáš', '1970-05-01', 1);
        q("UPDATE rej_persons SET isir_found = ?, isir_checked = '2026-01-01 10:00'", ['[{"sz":"1 INS 1/2026"}]']);
        $this->fakeHttp(fn() => ['status' => 0, 'body' => '', 'error' => 'offline']);
        [$checked, $hits, $error] = rej_isir_run();
        $this->assertSame([0, 0], [$checked, $hits]);
        $this->assertStringContainsString('neodpovídá', $error);
        $this->assertSame('[{"sz":"1 INS 1/2026"}]', rej_persons()[0]['isir_found']);
        $this->assertStringContainsString('|chyba|', setting('rej_isir_last'));
    }

    public function testDailyGating(): void
    {
        $this->pset('beta_insolvence', 'off');
        rej_person_add(3, 'Dvořák', 'Tomáš', '1970-05-01', 1);
        rej_isir_daily();
        $this->assertSame('', setting('rej_isir_last'), 'insolvency watch switched off');

        $this->pset('beta_insolvence', 'on');
        rej_set('rej_isir_last', date('Y-m-d H:i', time() - 3 * 86400) . '|ok|x');
        rej_isir_daily();
        $this->assertSame([], $this->calls, 'checked this week');

        rej_set('rej_isir_last', '');
        $this->fakeHttp(fn() => ['status' => 200, 'body' => self::isirResponse([], 'WS2')]);
        rej_isir_daily();
        $this->assertCount(1, $this->calls);

        q('DELETE FROM rej_persons');
        rej_set('rej_isir_last', '');
        $this->calls = [];
        rej_isir_daily();
        $this->assertSame([], $this->calls, 'nobody to check');
    }
}
