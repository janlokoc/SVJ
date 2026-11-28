<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Poznámka o DPH u ceníku je nenápadná poznámka pod čarou (ceny jsou u částek označené „bez DPH“), ne zvýrazněný pruh. */
final class VatNoteFootnoteTest extends TestCase
{
    public function testFootnoteStyleAndPlacement(): void
    {
        $root = dirname(__DIR__, 2);
        $css = (string) file_get_contents($root . '/assets/style.css');
        $this->assertSame(1, preg_match('/^\.vat-note \{([^}]*)\}/m', $css, $m));
        $this->assertStringContainsString('border-top: 1px solid var(--line)', $m[1]);
        $this->assertStringContainsString('color: var(--muted)', $m[1]);
        $this->assertStringNotContainsString('background', $m[1]);
        $this->assertStringNotContainsString('font-weight: 600', $m[1]);
        $l = (string) file_get_contents($root . '/app/views/platform/landing.php');
        $this->assertGreaterThan(strpos($l, 'lpx-partner-note'), strpos($l, 'class="vat-note"'), 'na úvodní stránce až na konci ceníku');
    }
}
