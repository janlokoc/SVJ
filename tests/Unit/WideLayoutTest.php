<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Administrace a konzole na širokém monitoru: širší obsah, stránky s tabulkou bez úzkého sloupce. */
final class WideLayoutTest extends TestCase
{
    public function testWideRules(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/style.css');
        $this->assertStringContainsString('body.in-admin .wrap { width: min(1480px, 100% - 4rem); }', $css);
        $this->assertStringContainsString('body.in-admin .narrow:has(table.table) { max-width: 1240px; }', $css);
        $this->assertStringContainsString('<body class="in-admin">', (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/platform/layout.php'));
    }
}
