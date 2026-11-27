<?php
declare(strict_types=1);

namespace Tests\Unit;

/**
 * Společné pomůcky testů modulu E (katastr a rejstříky): podstrčená síť, nastavení a úklid.
 * Žádný test nevolá skutečnou síť – rej_http() místo ní zavolá $GLOBALS['REJ_HTTP'].
 */
trait ModEHelper
{
    /** Zaznamenaná volání sítě: [['method', 'url', 'headers', 'body'], …]. */
    protected array $calls = [];
    private array $modeUnits = [];
    private array $modePset = [];

    protected function setUp(): void
    {
        parent::setUp();
        rej_ready();
        rej_usage_ready();   // tabulka spotřeby platformy i pro test, který v náhodném pořadí běží jako první
        $this->modeUnits = all('SELECT * FROM units ORDER BY id');
        $this->calls = [];
        $this->pset('rej_cee_token', null);
        $GLOBALS['REJ_SLEEP'] = fn(int $s) => null;
        $GLOBALS['REJ_HTTP'] = function () {
            throw new \RuntimeException('Test zavolal síť bez podstrčené odpovědi.');
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['REJ_HTTP'], $GLOBALS['REJ_SLEEP'], $GLOBALS['REJ_CONFIG']);
        foreach (['rej_persons', 'rej_katastr_changes', 'rej_exekuce'] as $t) {
            q("DELETE FROM $t");
        }
        q("DELETE FROM settings WHERE `key` LIKE 'rej\\_%' ESCAPE '\\' OR `key` IN ('isir_ico', 'isir_results', 'isir_last')");
        setting('__reset');
        // Jednotky: vrátit údaje a smazat jen ty, které test přidal (mazání všech by přes cizí klíče smazalo přiřazení obyvatel).
        $ids = array_map(fn($u) => (int) $u['id'], $this->modeUnits);
        q('DELETE FROM units WHERE id NOT IN (' . ($ids ? implode(',', $ids) : '0') . ')');
        foreach ($this->modeUnits as $u) {
            $id = $u['id'];
            unset($u['id']);
            q('UPDATE units SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($u))) . ' WHERE id = ?', [...array_values($u), $id]);
        }
        if (rej_usage_ready()) {
            pq('DELETE FROM rej_usage');
        }
        // Kredit služeb a doplňky k předplatnému (lustrace a sledování katastru se platí přes ně).
        pq('DELETE FROM credit_ledger WHERE tenant = ?', [TENANT]);
        pq('DELETE FROM credit_state WHERE tenant = ?', [TENANT]);
        pq('DELETE FROM tenant_addons WHERE tenant = ?', [TENANT]);
        foreach ($this->modePset as $key => $value) {
            if ($value === null) {
                pq('DELETE FROM platform_settings WHERE key = ?', [$key]);
            } else {
                pq('INSERT INTO platform_settings (key, value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, $value]);
            }
        }
        pset('__reset');
        $this->modePset = [];
        parent::tearDown();
    }

    /** Nastavení platformy (vrátí se v tearDown). */
    protected function pset(string $key, ?string $value): void
    {
        if (!array_key_exists($key, $this->modePset)) {
            $r = pq('SELECT value FROM platform_settings WHERE key = ?', [$key])->fetch();
            $this->modePset[$key] = $r ? (string) $r['value'] : null;
        }
        if ($value === null) {
            pq('DELETE FROM platform_settings WHERE key = ?', [$key]);
            pset('__reset');
        } else {
            pset_save($key, $value);
        }
    }

    /** Podstrčí síť: $responder(method, url, headers, body) → ['status' => …, 'body' => …]. */
    protected function fakeHttp(callable $responder): void
    {
        $GLOBALS['REJ_HTTP'] = function (string $method, string $url, array $headers, string $body) use ($responder) {
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            return $responder($method, $url, $headers, $body);
        };
    }

    /** Zachycené e-maily s dekódovaným předmětem a textem. */
    protected function decodedMails(): array
    {
        return array_map(fn($m) => ['to' => $m['to'], 'subject' => mb_decode_mimeheader($m['subject']), 'body' => quoted_printable_decode($m['body'])], $this->mails());
    }

    protected static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/ModE/' . $name);
    }

    /** Odpověď WSDP se sestavou (report); $content = obsah souboru (base64 se doplní). */
    protected static function wsdpReport(string $id, string $stav, ?string $content = null, string $cena = '100'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            . '<ns2:GenerujSestavuResponse xmlns:ns2="urn:cz:gov:cuzk:iskn:types:wsdp:sestavy:3.1" xmlns:ns3="urn:cz:gov:cuzk:iskn:types:common:3.1">'
            . '<ns2:vysledek><ns3:zprava kod="0" uroven="INFORMACE">Požadavek byl úspěšně zpracován.</ns3:zprava></ns2:vysledek>'
            . '<ns2:reportList><ns2:report><ns2:id>' . $id . '</ns2:id><ns2:nazev>Výpis z katastru nemovitostí</ns2:nazev><ns2:cena>' . $cena . '</ns2:cena>'
            . '<ns2:datumPozadavku>2026-11-26T10:00:00</ns2:datumPozadavku><ns2:stav>' . $stav . '</ns2:stav><ns2:format>xml</ns2:format>'
            . ($content !== null ? '<ns2:souborSestavy>' . base64_encode($content) . '</ns2:souborSestavy>' : '')
            . '</ns2:report></ns2:reportList></ns2:GenerujSestavuResponse></soap:Body></soap:Envelope>';
    }

    protected function katastrConfig(): void
    {
        $GLOBALS['REJ_CONFIG']['katastr'] = ['user' => 'WSUSER', 'password' => 'heslo<&>', 'endpoint' => 'https://wsdp.test.invalid/ws/wsdp/3.1/'];
    }

    protected function ceeConfig(): void
    {
        $GLOBALS['REJ_CONFIG']['exekuce'] = ['api_key' => 'KEY', 'api_secret' => 'SECRET', 'url' => 'https://cee.test.invalid/api/v4'];
    }
}
