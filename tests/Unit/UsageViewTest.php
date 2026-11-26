<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Konzole → Využití: na telefonu se místo sloupců s fajfkami ukáže seznam používaných a nepoužívaných funkcí. */
final class UsageViewTest extends TestCase
{
    public function testRowHasReadableMobileSummary(): void
    {
        $keys = array_keys(USAGE_FEATURES);
        $use = array_fill_keys($keys, false);
        $use[$keys[0]] = true;
        $rows = [['t' => ['slug' => 'dum', 'name' => 'SVJ Test'], 'edition' => 'premium', 'use' => $use]];
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/platform/usage.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('<td class="usage-mobile"><p><span class="muted">Používá:</span> ' . e(USAGE_FEATURES[$keys[0]][0]) . '</p>', $html);
        $this->assertStringContainsString('<span class="muted">Nepoužívá:</span> ' . e(USAGE_FEATURES[$keys[1]][0]), $html);
        $this->assertStringContainsString('data-label="Využito"', $html);
        $this->assertSame(count($keys), substr_count($html, 'class="usage-cell"'), 'desktop columns stay');
    }
}
