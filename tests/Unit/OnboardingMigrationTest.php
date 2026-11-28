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
        $this->loginAs('admin');
        q("DELETE FROM settings WHERE `key` = 'onb_prevod'");
        $s = onboarding_steps()['prevod'];
        $this->assertSame('/admin/api', $s['href']);
        $how = implode(' ', $s['how']['steps']);
        $this->assertStringContainsString(absolute_url('/mcp'), $how, 'adresa MCP serveru domu');
        $this->assertStringContainsString('documents:write', $how);
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
        $this->assertStringContainsString('Vytvořit klíč pro AI asistenta', $html);
    }
}
