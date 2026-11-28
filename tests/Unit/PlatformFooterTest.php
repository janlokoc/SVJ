<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Patička webu služby: odkazy seskupené do tří sloupců (dřív jeden dlouhý řádek, špatně se v něm hledalo). */
final class PlatformFooterTest extends TestCase
{
    public function testFooterLinksAreGrouped(): void
    {
        $s = (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/platform/site_layout.php');
        $this->assertStringNotContainsString('footer-links', $s);
        preg_match_all('~<h2 class="footer-head">([^<]+)</h2>~', $s, $m);
        $this->assertSame(['Služba', 'Smlouvy a bezpečnost', 'Kontakt a podpora'], $m[1]);
        foreach (['/cenik', '/registrace', '/podminky', '/ochrana-udaju', '/zpracovani-udaju', '/bezpecnost', '/hlaseni', '/sprava', '/funkce', '/pro-spravce'] as $path) {
            $this->assertStringContainsString("url('$path')", $s, $path);
            $this->assertContains(trim($path, '/'), PLATFORM_PATHS, $path . ' je stránka služby');
        }
        $this->assertStringContainsString('<div class="wrap footer-bottom"><?= theme_switch() ?></div>', $s);
        $this->assertStringContainsString('.footer-cols {', (string) file_get_contents(dirname(__DIR__, 2) . '/assets/style.css'));
    }
}
