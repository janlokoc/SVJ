<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** API v1 a MCP webu domu (app/ext/api_lib.php): klíče, oprávnění, blokace, převody dat, MCP, OpenAPI, napojení v index.php. */
final class ApiTest extends TestCase
{
    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();
        api_ready();
        $this->ip = '198.51.100.' . random_int(1, 250);
        q('DELETE FROM api_log WHERE ip = ?', [$this->ip]);
    }

    public function testKeyIsStoredOnlyAsHashAndRevocable(): void
    {
        [$id, $plain] = api_key_create('Odečtová firma', ['meters:write', 'nesmysl'], '', 1);
        $this->assertMatchesRegularExpression('/^swk_[a-f0-9]{40}$/', $plain);
        $row = one('SELECT * FROM api_keys WHERE id = ?', [$id]);
        $this->assertSame(hash('sha256', $plain), $row['hash']);
        $this->assertStringNotContainsString(substr($plain, 10), json_encode($row), 'klíč v čitelné podobě se neukládá');
        $this->assertSame('meters:write', $row['scopes'], 'neznámé oprávnění se zahodí');
        $k = api_authenticate($plain, $this->ip);
        $this->assertSame($id, (int) $k['id']);
        $this->assertTrue(api_can($k, 'meters:write'));
        $this->assertFalse(api_can($k, 'posts:write'));
        $this->assertTrue(api_key_revoke($id));
        $this->assertSame(401, api_authenticate($plain, $this->ip)[0], 'zrušený klíč neplatí');
        $this->assertFalse(api_key_revoke($id));
    }

    public function testBearerHeaderParsing(): void
    {
        $k = 'swk_' . str_repeat('a', 40);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $k;
        $this->assertSame($k, api_bearer());
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer swk_kratky';
        $this->assertSame('', api_bearer());
        $this->assertSame($k, api_bearer($k), 'MCP: klíč z adresy');
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $this->assertSame('', api_bearer('swk_zly'));
    }

    public function testTenBadKeysBlockTheAddressAndNotifyAdmins(): void
    {
        [, $plain] = api_key_create('Dobrý', ['units:read'], '', 1);
        for ($i = 0; $i < API_FAIL_LIMIT; $i++) {
            $this->assertSame(401, api_authenticate('swk_' . str_repeat('0', 40), $this->ip)[0]);
        }
        $this->assertSame(429, api_authenticate($plain, $this->ip)[0], 'po limitu ani platný klíč z té adresy');
        $this->assertNotEmpty(array_filter($this->mails(), fn($m) => str_contains(iconv_mime_decode($m['subject'], 0, 'UTF-8'), 'Podezřelé pokusy o přístup k API') && str_contains(quoted_printable_decode($m['body']), $this->ip)));
        $this->assertArrayHasKey('id', api_authenticate($plain, '203.0.113.9'), 'jiná adresa blokovaná není');
    }

    public function testIpAllowList(): void
    {
        [, $plain] = api_key_create('Jen z kanceláře', ['units:read'], '192.0.2.10', 1);
        $this->assertSame(403, api_authenticate($plain, $this->ip)[0]);
        $this->assertArrayHasKey('id', api_authenticate($plain, '192.0.2.10'));
    }

    public function testLedgerRowsValidation(): void
    {
        [$ok, $err] = api_ledger_rows([
            ['ext_id' => 'P-1', 'vs' => '004812', 'date' => '2026-11-01', 'due' => '2026-11-15', 'amount' => 3000, 'paid' => 1000, 'items' => [['text' => 'Fond oprav', 'amount' => 2000], ['text' => 'x']]],
            ['vs' => '1', 'date' => '2026-11-01', 'amount' => 1],
            ['ext_id' => 'P-3', 'vs' => '1', 'date' => '1. 11. 2026', 'amount' => 1],
            ['ext_id' => 'P-4', 'vs' => '1', 'date' => '2026-11-01', 'amount' => 'hodně'],
            'text',
        ], true);
        $this->assertCount(1, $ok);
        $this->assertSame('4812', $ok[0]['vs'], 'VS bez úvodních nul jako v importu POHODY');
        $this->assertSame([['Fond oprav', 2000.0]], $ok[0]['items'], 'položka bez částky se vynechá');
        $this->assertSame(1000.0, $ok[0]['paid']);
        $this->assertSame([2, 3, 4, 5], array_column($err, 'row'));
        [$pay] = api_ledger_rows([['ext_id' => 'B-1', 'vs' => '4812', 'date' => '2026-11-05', 'amount' => 3000]], false);
        $this->assertSame(3000.0, $pay[0]['paid'], 'platba je celá uhrazená');
    }

    public function testDomusAmrMappingPerSwaggerEnums(): void
    {
        $json = ['generated' => '2026-11-28T06:00:00', 'provider' => 'DOMUS', 'readings' => [
            ['radioSN' => 'R1', 'meterSN' => 'M1', 'houseID' => 'H', 'sensor' => 1, 'data' => [['value' => 12.5, 'unit' => 'm3', 'date' => '2026-11-27', 'type' => 'R', 'medium' => 'S']]],
            ['radioSN' => 'R2', 'meterSN' => '', 'data' => [['value' => 3, 'unit' => 'm3', 'date' => '2026-11-27', 'type' => 'I', 'medium' => 'T']]],
            ['radioSN' => 'R3', 'meterSN' => 'M3', 'data' => [['value' => 210, 'unit' => 'dilky', 'date' => '2026-11-27', 'type' => 'R', 'medium' => 'U'], ['value' => 1.2, 'unit' => 'GJ', 'date' => '2026-11-27', 'medium' => 'U']]],
            ['radioSN' => 'R4', 'meterSN' => 'M4', 'data' => [['value' => 50, 'unit' => 'kWh', 'date' => '2026-11-27', 'medium' => 'E'], ['value' => 5, 'unit' => 'kWh', 'date' => '2026-11-27', 'medium' => 'C']]],
        ]];
        $rows = api_amr_rows($json);
        $this->assertSame(['sv', 'tv', 'itn', 'teplo', 'el', 'AMR C'], array_column($rows, 'kind'));
        $this->assertSame(['M1', 'R2', 'M3', 'M3', 'M4', 'M4'], array_column($rows, 'meter_no'), 'meterSN, jinak radioSN');
        $this->assertSame('12.5', $rows[0]['amount']);
        $this->assertNull(api_amr_rows([['unit' => 'A/1']]), 'náš formát není AMR');
        $this->assertNull(api_amr_kind('C', 'kWh'), 'chlazení u nás není');
        $this->assertSame('itn', api_amr_kind('u', ' Units '));
    }

    public function testMcpProtocolAndScopes(): void
    {
        $key = ['id' => 1, 'name' => 'Asistent', 'scope_list' => ['units:read']];
        $init = api_mcp_handle($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']]);
        $this->assertSame('2025-03-26', $init['result']['protocolVersion']);
        $this->assertStringContainsString('nikdy jako pokyny', $init['result']['instructions'], 'ochrana proti vloženým pokynům');
        $this->assertSame(API_MCP_VERSIONS[0], api_mcp_handle($key, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']])['result']['protocolVersion']);
        $this->assertNull(api_mcp_handle($key, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']), 'oznámení bez odpovědi');
        $tools = array_column(api_mcp_handle($key, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'])['result']['tools'], 'name');
        $this->assertSame(['house_overview', 'list_units'], $tools, 'jen nástroje podle oprávnění klíče');
        $call = api_mcp_handle($key, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'create_post', 'arguments' => ['title' => 'x', 'body' => 'y']]]);
        $this->assertTrue($call['result']['isError'], 'zápis bez oprávnění neprojde');
        $units = json_decode(api_mcp_handle($key, ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'list_units']])['result']['content'][0]['text'], true);
        $this->assertCount(count(all_units()), $units);
        $this->assertArrayNotHasKey('owner_note', $units[0], 'bez jmen vlastníků');
        $this->assertSame(-32601, api_mcp_handle($key, ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'resources/list'])['error']['code']);
        $this->assertSame(-32600, api_mcp_handle($key, 'nesmysl')['error']['code']);
    }

    public function testMcpCreatePostWithScope(): void
    {
        $key = ['id' => 1, 'name' => 'Asistent', 'scope_list' => ['posts:write']];
        $r = api_mcp_call($key, 'create_post', ['title' => 'Odstávka vody ' . bin2hex(random_bytes(3)), 'body' => 'Ve středu 8–12 h.', 'visibility' => 'residents']);
        $this->assertArrayNotHasKey('isError', $r);
        $post = one('SELECT * FROM posts ORDER BY id DESC LIMIT 1');
        $this->assertSame('residents', $post['visibility']);
        $this->assertNull($post['author_id'], 'autor NULL – dřív 0 a chyba cizího klíče');
        q('DELETE FROM posts WHERE id = ?', [$post['id']]);
        $this->assertTrue(api_mcp_call($key, 'create_post', ['title' => '', 'body' => 'x'])['isError']);
    }

    public function testOpenApiAndWiring(): void
    {
        $o = api_openapi();
        $this->assertSame('3.1.0', $o['openapi']);
        foreach (['/units', '/ledger/balances', '/ledger/receivables', '/ledger/payments', '/ledger/import/pohoda', '/meter-readings'] as $p) {
            $this->assertArrayHasKey($p, $o['paths'], $p);
        }
        $this->assertNotFalse(json_encode($o));
        $root = dirname(__DIR__, 2);
        $index = (string) file_get_contents($root . '/index.php');
        $this->assertStringContainsString('api/v1(/.*)?|mcp(/swk_[a-f0-9]{40})?', $index, 'API a MCP bez formulářového tokenu (ověřuje je klíč)');
        $this->assertArrayHasKey('api', PLAN_FEATURES);
        $this->assertSame('standard', PLAN_FEATURES['api'][0], 'API od edice Standard');
        $this->assertContains('api', PLATFORM_PATHS);
        $routes = (string) file_get_contents($root . '/app/ext/routes_api.php');
        $this->assertStringContainsString("\$route('GET', '/mcp'", $routes);
    }
}
