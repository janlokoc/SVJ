<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Nápověda (app/routes_help.php): každý zapsaný článek má soubor, články k funkcím beta programu se skryjí, když funkce není vidět. */
final class HelpTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('help_visible')) {
            $route = function (): void {};
            require_once dirname(__DIR__, 2) . '/app/routes_help.php';
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            $v === null ? pq('DELETE FROM platform_settings WHERE key = ?', [$k]) : pset_save($k, $v);
        }
        pset('__reset');
        parent::tearDown();
    }

    private function beta(string $key, string $stage): void
    {
        $k = 'beta_' . $key;
        if (!array_key_exists($k, $this->saved)) {
            $v = pq('SELECT value FROM platform_settings WHERE key = ?', [$k])->fetchColumn();
            $this->saved[$k] = $v === false ? null : (string) $v;
        }
        pset_save($k, $stage);
    }

    public function testEveryArticleHasFileAndValidShape(): void
    {
        foreach (HELP as $slug => $a) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
            $this->assertFileExists(dirname(__DIR__, 2) . "/app/help/$slug.php", $slug);
            $this->assertIsBool($a[2], $slug);
            foreach ($a[4] ?? [] as $key) {
                $this->assertArrayHasKey($key, BETA_FEATURES, "$slug: unknown beta feature $key");
            }
        }
        foreach (glob(dirname(__DIR__, 2) . '/app/help/*.php') as $f) {
            $this->assertArrayHasKey(basename($f, '.php'), HELP, 'article file is registered: ' . basename($f));
        }
    }

    public function testBetaArticleHiddenUntilFeatureVisible(): void
    {
        if (!platform_enabled()) {
            $this->markTestSkipped('beta program works only on the platform');
        }
        $this->beta('sms', 'off');
        $this->assertFalse(help_visible('sms-zpravy'));
        $this->assertSame('', help_link('sms-zpravy'));
        $this->beta('sms', 'on');
        $this->assertTrue(help_visible('sms-zpravy'));
        $this->assertStringContainsString('/admin/napoveda/sms-zpravy', help_link('sms-zpravy'));
        // Článek ke třem funkcím je vidět, když je vidět aspoň jedna z nich.
        foreach (['katastr', 'insolvence', 'exekuce'] as $k) {
            $this->beta($k, 'off');
        }
        $this->assertFalse(help_visible('katastr-a-rejstriky'));
        $this->beta('exekuce', 'on');
        $this->assertTrue(help_visible('katastr-a-rejstriky'));
        // Články bez beta funkce jsou vidět vždy, neznámý článek nikdy.
        $this->assertTrue(help_visible('plan-oprav'));
        $this->assertFalse(help_visible('neexistuje'));
        $this->assertSame('', help_link('neexistuje'));
    }
}
