<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Patička webu domu: odkazy ve sloupcích jako na webu služby; prázdné IČ/účet se nevypisují („účet“ bez čísla). */
final class SiteFooterTest extends TestCase
{
    public function testFooterIsGroupedAndSkipsEmptyFields(): void
    {
        $s = (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/layout.php');
        $foot = substr($s, strpos($s, '<footer class="site-footer">'));
        $this->assertStringNotContainsString('footer-links', $foot);
        preg_match_all('~<h2 class="footer-head">([^<]+)</h2>~', $foot, $m);
        $this->assertSame(['Na webu', 'Informace'], $m[1]);
        $this->assertStringContainsString("\$fAcc !== '' ? 'účet ' . e(\$fAcc) : ''", $foot, 'účet jen s číslem');
        $this->assertStringContainsString('<div class="wrap footer-bottom"><?= theme_switch() ?>', $foot);
        foreach (['en', 'sk', 'uk', 'vi'] as $l) {
            $t = require dirname(__DIR__, 2) . "/app/lang/$l.php";
            $this->assertArrayHasKey('Na webu', $t, $l);
            $this->assertArrayHasKey('Informace', $t, $l);
        }
    }
}
