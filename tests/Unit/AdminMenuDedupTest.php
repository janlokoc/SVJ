<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Insolvence vlastníků jsou součástí „Katastr a rejstříky“ – v menu administrace nesmí být dvakrát (chyba z 11/2026). */
final class AdminMenuDedupTest extends TestCase
{
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            $v === null ? pq('DELETE FROM platform_settings WHERE key = ?', [$k]) : pset_save($k, $v);
        }
        pset('__reset');
        parent::tearDown();
    }

    private function pset(string $k, string $v): void
    {
        if (!array_key_exists($k, $this->saved)) {
            $o = pq('SELECT value FROM platform_settings WHERE key = ?', [$k])->fetchColumn();
            $this->saved[$k] = $o === false ? null : (string) $o;
        }
        pset_save($k, $v);
    }

    private function adminLayout(string $path): string
    {
        $this->loginAs('admin');
        $GLOBALS['PATH'] = $path;
        $content = '<p>obsah</p>';
        $title = 'Test';
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/layout.php';
        return (string) ob_get_clean();
    }

    public function testInsolvencyNotDuplicatedWhenRegistriesInMenu(): void
    {
        $this->pset('beta_insolvence', 'on');
        $this->assertTrue(rej_nav_visible() && beta_visible('insolvence'));
        $html = $this->adminLayout('/admin/insolvence');
        $this->assertStringContainsString('/admin/rejstriky"', $html);
        $this->assertStringNotContainsString('>Insolvence vlastníků<', $html, 'jen jednou – přes Katastr a rejstříky');
        $this->assertMatchesRegularExpression('~href="[^"]*/admin/rejstriky" aria-current="page"~', $html, 'na stránce insolvencí je zvýrazněná položka rejstříků');
    }

    public function testInsolvencyPageLinksBackToRegistries(): void
    {
        $s = (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/admin/insolvency.php');
        $this->assertStringContainsString("url('/admin/rejstriky')", $s);
    }
}
