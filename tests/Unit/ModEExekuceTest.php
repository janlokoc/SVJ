<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

require_once __DIR__ . '/ModEHelper.php';

/** Modul E – lustrace v Centrální evidenci exekucí (app/ext/rej_exekuce_lib.php) s podstrčeným API. */
final class ModEExekuceTest extends TestCase
{
    use ModEHelper;

    /** Vzorová odpověď /search podle dokumentace https://www.ceecr.cz/dev (API v4). */
    private static function searchBody(int $n = 1): string
    {
        $data = [];
        for ($i = 0; $i < $n; $i++) {
            $data[] = ['datetime_zalozeni' => '2023-10-18', 'datetime_upravy' => '2025-01-09', 'ex_cislo' => '999EX12' . $i . '/23', 'datum_zapisu' => '2023-10-18',
                'subjects' => [['typ_osoby' => 'F', 'jmeno' => 'Jan Novák', 'datum_narozeni' => '1989-11-11', 'subject_id' => 'Ywd'], ['typ_osoby' => 'F', 'jmeno' => 'Cizí Osoba', 'datum_narozeni' => '1950-01-01']],
                'datum_soudniho_narizeni' => '2023-10-18', 'subject_count' => 2, 'cause_id' => 'OV', 'posledni_uprava' => 'Pravomocná exekuce', 'date_vydano' => '2023-10-18'];
        }
        return json_encode(['data' => $data, 'info' => ['debug' => ['found' => $n], 'zbyva_dotazu' => ['placenych' => 10], 'cena' => ['zaplaceno' => '15 Kč', 'kredit' => 600]],
            'data_source' => 'regular', 'status' => 200], JSON_UNESCAPED_UNICODE);
    }

    private function fakeCee(callable $search, ?callable $auth = null): void
    {
        $this->fakeHttp(function ($m, $url, $h, $b) use ($search, $auth) {
            if (str_contains($url, '/auth?')) {
                return $auth ? $auth($url) : ['status' => 200, 'body' => json_encode(['token_value' => 'TOK1', 'valid_for' => '127.0.0.1', 'valid_till' => date('Y-m-d H:i:s', time() + 3600), 'data_source' => 'regular'])];
            }
            return $search($url);
        });
    }

    public function testNotConfiguredNeverCallsNetwork(): void
    {
        $GLOBALS['REJ_CONFIG']['exekuce'] = [];
        $this->assertFalse(rej_cee_configured());
        $r = rej_cee_search('P', '', '27082440');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('není nastavená', $r['error']);
        $this->assertSame([], $this->calls);
    }

    public function testInputValidationBeforeNetwork(): void
    {
        $this->ceeConfig();
        $this->assertStringContainsString('datum narození', rej_cee_search('F', 'Jan Novák', '1989-13-01')['error']);
        $this->assertStringContainsString('datum narození', rej_cee_search('F', '', '1989-11-11')['error']);
        $this->assertSame('IČO není platné.', rej_cee_search('P', '', '27082441')['error']);
        $this->assertSame([], $this->calls);
    }

    public function testPersonSearchUsesTokenAndParsesCauses(): void
    {
        $this->ceeConfig();
        $this->fakeCee(fn($url) => ['status' => 200, 'body' => self::searchBody(2)]);
        $r = rej_cee_search('F', 'Jan Novák', '1989-11-11');
        $this->assertTrue($r['ok']);
        $this->assertSame('15 Kč', $r['paid']);
        $this->assertSame([['ex_cislo' => '999EX120/23', 'zapsano' => '2023-10-18', 'uprava' => 'Pravomocná exekuce', 'vydano' => '2023-10-18', 'urad' => ''],
            ['ex_cislo' => '999EX121/23', 'zapsano' => '2023-10-18', 'uprava' => 'Pravomocná exekuce', 'vydano' => '2023-10-18', 'urad' => '']], $r['causes']);
        $this->assertStringStartsWith('https://cee.test.invalid/api/v4/auth?api_key=KEY&api_secret=SECRET', $this->calls[0]['url']);
        $this->assertSame('https://cee.test.invalid/api/v4/search?token=TOK1&name=Jan%20Nov%C3%A1k&birth=1989-11-11&person=F', $this->calls[1]['url']);

        // token se použije znovu, dokud platí
        rej_cee_search('P', '', '27082440');
        $this->assertCount(3, $this->calls);
        $this->assertSame('https://cee.test.invalid/api/v4/search?token=TOK1&ico=27082440&person=P', $this->calls[2]['url']);
        $this->assertSame('TOK1', json_decode(pset('rej_cee_token'), true)['token']);
    }

    public function testExpiredTokenIsRenewedOnce(): void
    {
        $this->ceeConfig();
        $this->pset('rej_cee_token', json_encode(['token' => 'OLD', 'till' => date('Y-m-d H:i:s', time() + 3600)]));
        $this->fakeCee(fn($url) => str_contains($url, 'token=OLD')
            ? ['status' => 401, 'body' => json_encode(['status' => 401, 'error' => ['code' => 5506, 'message' => 'Váš API TOKEN není platný!']])]
            : ['status' => 200, 'body' => self::searchBody(0)]);
        $r = rej_cee_search('P', '', '27082440');
        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['causes']);
        $this->assertCount(3, $this->calls, 'search, auth, search again');
    }

    public function testErrorsAreReadable(): void
    {
        $this->assertSame('', rej_cee_error(['status' => 200, 'data' => []], 200));
        $this->assertSame('Evidence exekucí: Chybí API KEY! (kód 5501)', rej_cee_error(['error' => ['code' => 5501, 'message' => 'Chybí API KEY!']], 400));
        $this->assertSame('Evidence exekucí: Nedostatek kreditu', rej_cee_error(['message' => 'Nedostatek kreditu', 'status' => 402], 402));
        $this->assertSame('Evidence exekucí: a b', rej_cee_error(['errors' => [['message' => 'a'], 'b']], 422));
        $this->assertSame('Evidence exekucí: chyba (HTTP 500)', rej_cee_error([], 500));
        $this->assertStringContainsString('není JSON', rej_cee_error(null, 200));

        $this->ceeConfig();
        $this->fakeCee(fn() => ['status' => 200, 'body' => '{}'], fn() => ['status' => 403, 'body' => json_encode(['error' => ['code' => 5504, 'message' => 'Váš API přístup byl vypnut administrátorem.']])]);
        $r = rej_cee_search('P', '', '27082440');
        $this->assertSame('Evidence exekucí: Váš API přístup byl vypnut administrátorem. (kód 5504)', $r['error']);
    }

    public function testCheckStoresResultUsageAndAudit(): void
    {
        $this->ceeConfig();
        $this->pset('rej_price_exekuce', '120');
        rej_person_add(3, 'Novák', 'Jan', '1989-11-11', 1);
        $subjects = rej_exekuce_subjects();
        $key = array_key_first($subjects);
        $this->assertSame(['kind' => 'F', 'label' => 'Jan Novák', 'id' => '1989-11-11'], array_intersect_key($subjects[$key], ['kind' => 1, 'label' => 1, 'id' => 1]));
        $this->fakeCee(fn() => ['status' => 200, 'body' => self::searchBody(1)]);
        $row = rej_exekuce_check($subjects[$key], 'Správce');
        $this->assertSame(1, (int) $row['found']);
        $this->assertSame(120, (int) $row['price']);
        $this->assertSame('', $row['error']);
        $stored = json_decode($row['result'], true);
        $this->assertSame('999EX120/23', $stored[0]['ex_cislo']);
        $this->assertStringNotContainsString('Cizí Osoba', $row['result'], 'other debtors of the cause are not stored');
        $this->assertStringNotContainsString('1950', $row['result']);
        $u = pq("SELECT * FROM rej_usage WHERE kind = 'exekuce'")->fetch();
        $this->assertSame([120, 15], [(int) $u['price'], (int) $u['cost']], 'customer price from settings, cost from the API answer');
        $this->assertSame($row['id'], rej_exekuce_latest()[$key]['id']);
        $this->assertSame('Exekuce: lustrace', val('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1'));
    }

    public function testFailedCheckIsStoredWithoutCharge(): void
    {
        $this->ceeConfig();
        q('UPDATE units SET owner_note = ?, owner_contact = ? WHERE id = 1', ['Stavby Novák s.r.o.', 'IČO 27082440']);
        $subjects = rej_exekuce_subjects();
        $this->assertArrayHasKey('P1', $subjects);
        $this->assertSame(['kind' => 'P', 'id' => '27082440'], array_intersect_key($subjects['P1'], ['kind' => 1, 'id' => 1]));
        $this->fakeCee(fn() => ['status' => 0, 'body' => '', 'error' => 'offline']);
        $row = rej_exekuce_check($subjects['P1'], 'Správce');
        $this->assertStringContainsString('neodpovídá', $row['error']);
        $this->assertSame(0, (int) $row['price']);
        $this->assertSame(0, (int) pq("SELECT COUNT(*) FROM rej_usage WHERE kind = 'exekuce'")->fetchColumn());
    }
}
