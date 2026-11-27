<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Každé rozvržení s menu (web domu, veřejný web služby, konzole, partnerský portál) má na telefonu tlačítko „Menu“ – jinak menu na úzké obrazovce nejde otevřít. */
final class LayoutNavTest extends TestCase
{
    public function testEveryLayoutWithMenuHasMobileToggle(): void
    {
        $root = dirname(__DIR__, 2) . '/app/views/';
        $checked = 0;
        foreach (['layout.php', 'platform/layout.php', 'platform/site_layout.php', 'partner/layout.php'] as $f) {
            $s = (string) file_get_contents($root . $f);
            $this->assertStringContainsString('class="site-nav" id="hlavni-menu"', $s, $f);
            $this->assertMatchesRegularExpression('~<button class="nav-toggle"[^>]*aria-controls="hlavni-menu"~', $s, $f);
            $this->assertLessThan(strpos($s, 'class="site-nav"'), strpos($s, 'class="nav-toggle"'), "$f: toggle before menu");
            $checked++;
        }
        $this->assertSame(4, $checked);
    }

    /** Položka menu funkce v betě má výrazný štítek Beta (ne jen text v názvu). */
    public function testAdminMenuShowsBetaBadge(): void
    {
        $s = (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/layout.php');
        $this->assertStringContainsString('<?php if (isset($adminBeta[$href])): ?> <span class="plan-tag tag-beta">Beta</span>', $s);
        $this->assertStringNotContainsString("' · beta'", $s);
        $this->assertStringContainsString('.admin-nav .plan-tag.tag-beta', (string) file_get_contents(dirname(__DIR__, 2) . '/assets/style.css'));
    }

    /** Konzole: nahoře nejvýš 5 hlavních položek, ostatní pod „Další“ (menu se vejde na jeden řádek). */
    public function testConsoleMenuHasMoreDropdown(): void
    {
        $s = (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/platform/layout.php');
        $this->assertStringContainsString('<summary>Další</summary>', $s);
        $this->assertSame(1, preg_match('~\$consoleMain = \[(.*?)\];\n~s', $s, $m));
        $this->assertLessThanOrEqual(5, substr_count($m[1], "['/"));
        foreach (['/vyuziti', '/kredit', '/rejstriky', '/zajem', '/zaznamy'] as $p) {
            $this->assertStringContainsString("['" . $p . "'", $s, $p . ' still reachable');
        }
    }
}
