<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Konzole → Společenství: ukázkové weby jsou ve výchozím stavu schované, zobrazí je odkaz (?ukazky=1). */
final class ConsoleDemoHiddenTest extends TestCase
{
    public function testDemosHiddenByDefault(): void
    {
        $root = dirname(__DIR__, 2);
        $c = (string) file_get_contents($root . '/app/platform_console.php');
        $this->assertStringContainsString("\$showDemo = (\$_GET['ukazky'] ?? '') === '1';", $c);
        $this->assertStringContainsString("array_filter(\$tenants, fn(\$x) => empty(\$x['demo']))", $c);
        $v = (string) file_get_contents($root . '/app/views/platform/dashboard.php');
        $this->assertStringContainsString("'Zobrazit ukázkové weby (' . \$demoCount . ')'", $v);
        $this->assertStringContainsString('Skrýt ukázkové weby', $v);
    }
}
