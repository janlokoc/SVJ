<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Napojení na programy: přehled na veřejných stránkách a sběr požadavků (INTEGRATIONS, integration_request_add). */
final class IntegrationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int(1, 250);
    }

    protected function tearDown(): void
    {
        pq("DELETE FROM integration_requests WHERE ip LIKE '192.0.2.%'");
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        parent::tearDown();
    }

    public function testStatusList(): void
    {
        $this->assertSame('done', INTEGRATIONS['pohoda'][1]);
        $this->assertSame('work', INTEGRATIONS['domus'][1]);
        $this->assertSame('work', INTEGRATIONS['integri'][1]);
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/platform/_integrations.php');
        $this->assertStringContainsString("'pracujeme na tom'", $view);
        foreach (['for_managers', 'features'] as $f) {
            $this->assertStringContainsString("_integrations.php", (string) file_get_contents(dirname(__DIR__, 2) . "/app/views/platform/$f.php"), $f);
        }
        $this->assertContains('napojeni', PLATFORM_PATHS, 'adresu nejde zabrat jako název webu');
    }

    public function testRequestValidationAndLimit(): void
    {
        $this->assertSame('Vyberte program.', integration_request_add('nic', '', '', ''));
        $this->assertStringContainsString('o jaký program', (string) integration_request_add('other', ' ', '', ''));
        $this->assertStringContainsString('E-mail', (string) integration_request_add('domus', '', '', 'spatne'));
        $this->assertNull(integration_request_add('domus', '', 'Předpisy a saldo', 'spravce@example.cz'));
        $this->assertNull(integration_request_add('other', 'Programko', '', ''));
        $row = pq('SELECT * FROM integration_requests WHERE ip = ? ORDER BY id DESC LIMIT 1', [$_SERVER['REMOTE_ADDR']])->fetch();
        $this->assertSame(['other', 'Programko'], [$row['program'], $row['other']]);
        for ($i = 0; $i < 3; $i++) {
            $this->assertNull(integration_request_add('ides', '', '', ''));
        }
        $this->assertStringContainsString('několik požadavků', (string) integration_request_add('ides', '', '', ''), 'nejvýš 5 za den z adresy');
    }
}
