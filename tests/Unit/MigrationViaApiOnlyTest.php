<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Převod obsahu jen přes API/MCP: JSON import (svj-import-1) z konzole i nástroj tools/import-content.php jsou pryč. */
final class MigrationViaApiOnlyTest extends TestCase
{
    public function testJsonImportRemoved(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFalse(function_exists('import_content'));
        $this->assertFileDoesNotExist($root . '/tools/import-content.php');
        $c = (string) file_get_contents($root . '/app/platform_console.php');
        $this->assertStringNotContainsString("\$action === '/import'", $c);
        $v = (string) file_get_contents($root . '/app/views/platform/tenant_form.php');
        $this->assertStringNotContainsString('svj-import-1', $v);
        $this->assertStringContainsString('přes API nebo MCP', $v, 'provozovatel převádí stejně jako správci');
        $this->assertFileExists($root . '/tools/import-webnode.php', 'stahování souborů z odkazů zůstává');
    }
}
