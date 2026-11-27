<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Úvodní část veřejných stránek služby (lpx-hero) nesmí mít vpravo prázdné místo – vždy obrázek z assets/landing (11/2026, podruhé). */
final class PublicHeroVisualTest extends TestCase
{
    public function testEveryHeroHasScreenshotThatExists(): void
    {
        $root = dirname(__DIR__, 2);
        $checked = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app/views')) as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $s = (string) file_get_contents($f->getPathname());
            if (!str_contains($s, 'class="lpx-hero')) {
                continue;
            }
            $name = substr($f->getPathname(), strlen($root) + 1);
            $this->assertStringNotContainsString('class="lpx-hero lpx-hero-plain"', $s, "$name: hero bez obrázku");
            $this->assertMatchesRegularExpression('/lpx-hero-grid|lpx-devices/', $s, "$name: hero má mít sloupec s obrázkem");
            preg_match_all('~(?:landing/|\$img\(\x27)([a-z0-9-]+\.jpg)~', $s, $m);
            $this->assertNotEmpty($m[1], "$name: odkaz na snímek z assets/landing");
            foreach (array_unique($m[1]) as $img) {
                $this->assertFileExists($root . '/assets/landing/' . $img, "$name: snímek $img vytváří tools/napoveda/overit.js");
            }
            $checked++;
        }
        $this->assertGreaterThanOrEqual(4, $checked);
    }
}
