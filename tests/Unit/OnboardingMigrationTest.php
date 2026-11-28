<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Průvodce nastavením: převod starého webu sami přes AI asistenta a MCP; placený převod jen jako záloha s cenou z ceníku. */
final class OnboardingMigrationTest extends TestCase
{
    public function testStepExplainsAiMigrationAndOffersPaidFallback(): void
    {
        if (!function_exists('onboarding_steps')) {
            $route = function (): void {};
            require_once dirname(__DIR__, 2) . '/app/routes_onboarding.php';
        }
        if (!function_exists('help_link')) {
            $route = function (): void {};
            require_once dirname(__DIR__, 2) . '/app/routes_help.php';
        }
        $this->loginAs('admin');
        q("DELETE FROM settings WHERE `key` = 'onb_prevod'");
        api_ready();
        q('DELETE FROM api_refs');   // převod přes API by krok splnil sám
        $s = onboarding_steps()['prevod'];
        $this->assertSame('/admin/prevod', $s['href'], 'převod má vlastní stránku, odděleně od API a napojení');
        $how = implode(' ', $s['how']['steps']);
        $this->assertStringContainsString('Převod ze starého webu', $how);
        $this->assertStringContainsString('nic neposílá', $how);
        $this->assertSame('api-a-mcp', $s['how']['help']);
        if (pset_int('price_migration', 0, 1000000)) {
            $this->assertStringContainsString(money_czk(pset_int('price_migration', 0, 1000000)), $s['how']['alt'][1]);
            $this->assertSame('/admin/predplatne#prevod', $s['how']['alt'][0]);
        }
        $o = onboarding_summary();
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/admin/onboarding.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('class="onb-how"', $html);
        $this->assertStringContainsString('Převést starý web', $html);
        $this->assertLessThan(strpos($html, 'id="krok-kontakty"'), strpos($html, 'id="krok-prevod"'), 'převod je před ručním zadáváním obsahu');
        foreach (ONBOARDING_FROM_MIGRATION as $k) {
            if (($o['steps'][$k]['state'] ?? '') === 'wait') {
                $this->assertTrue(!empty($o['steps'][$k]['via_prevod']), $k . ' odkazuje na převod');
            }
        }
        $this->assertStringContainsString('přinese <a href="#krok-prevod">převod ze starého webu</a>', (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/admin/onboarding.php'));
        onboarding_save('prevod', 'skip');
        $this->assertArrayNotHasKey('via_prevod', onboarding_steps()['kontakty'], 'bez převodu žádný odkaz');
        onboarding_save('prevod', '');
    }
}
