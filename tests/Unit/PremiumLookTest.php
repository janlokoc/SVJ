<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Prémiové šablony a barvy: bez edice Premium nebo běžící zkoušky je nejde zvolit ani uložit (dřív šly „vybrat do zásoby“
 * a správce si myslel, že je má, nebo že se po měsíci něco přepne a zaplatí).
 */
final class PremiumLookTest extends TestCase
{
    public function testViewDisablesPremiumChoicesAndSaveRefusesThem(): void
    {
        $root = dirname(__DIR__, 2);
        $v = (string) file_get_contents($root . '/app/views/admin/settings.php');
        $this->assertStringContainsString("!\$plusOk && in_array(\$style, PREMIUM_STYLES, true) ? ' disabled' : ''", $v);
        $this->assertStringContainsString("!\$plusOk && in_array(\$k, PREMIUM_LOOKS, true) ? ' disabled' : ''", $v);
        $this->assertStringContainsString("site_style_used() === \$style ? ' checked'", $v, 'zaškrtnuté je to, co web opravdu používá');
        $this->assertStringNotContainsString('Vybrat je můžete i teď', $v);
        $r = (string) file_get_contents($root . '/app/routes_admin.php');
        $this->assertStringContainsString("plan_managed() && !plan_has('vzhledy-plus') && ((\$k === 'site_style' && in_array(\$v, PREMIUM_STYLES, true))", $r);
        $this->assertStringContainsString('.look-card:has(input:disabled)', (string) file_get_contents($root . '/assets/style.css'));
    }

}
