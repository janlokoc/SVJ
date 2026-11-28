<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Dokumenty v administraci: viditelnost se přepne jedním kliknutím v seznamu; u akcí je výslovné „Upravit“. */
final class DocVisibilityToggleTest extends TestCase
{
    public function testToggleWiring(): void
    {
        $root = dirname(__DIR__, 2);
        $r = (string) file_get_contents($root . '/app/routes_admin.php');
        $this->assertStringContainsString('/admin/dokumenty/(\d+)/viditelnost', $r);
        $this->assertStringContainsString("if (!\$d['unit_id'])", $r, 'dokument jednotky zůstává jen pro ni');
        $v = (string) file_get_contents($root . '/app/views/admin/documents.php');
        $this->assertStringContainsString('class="vis-btn', $v);
        $this->assertStringContainsString('>Upravit</a>', $v);
    }
}
