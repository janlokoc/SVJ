<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Doporučovací program: vlastní stránka /doporuceni a zvýrazněný blok na ceníku (dřív jen řádek v tabulce doplňků). */
final class ReferralPageTest extends TestCase
{
    private function view(string $name): string
    {
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/' . $name . '.php';
        return str_replace("\u{a0}", ' ', (string) ob_get_clean());
    }

    public function testPageExplainsProgramWithConfiguredAmounts(): void
    {
        $old = [pset('referral_reward'), pset('referral_welcome')];
        pset_save('referral_reward', '300');
        pset_save('referral_welcome', '150');
        try {
            $html = $this->view('ext/pricing/referral');
            $this->assertStringContainsString('<strong>300 Kč</strong>', $html);
            $this->assertStringContainsString('slevu <strong>150 Kč</strong>', $html);
            $this->assertStringContainsString('Jak to funguje', $html);
            $this->assertStringContainsString('poprvé zaplatí roční edici', $html);
            $this->assertStringContainsString('nevyplácí v penězích', $html);
            $this->assertStringContainsString('stejným kontaktním e-mailem', $html, 'podmínka odpovídá referral_register');
        } finally {
            pset_save('referral_reward', $old[0]);
            pset_save('referral_welcome', $old[1]);
        }
    }

    public function testWiring(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertContains('doporuceni', PLATFORM_PATHS, 'adresa nejde zabrat jako slug webu');
        $this->assertStringContainsString("\$PATH === '/doporuceni' && pset_int('referral_reward'", (string) file_get_contents($root . '/app/ext/site_pricing.php'));
        $pricing = (string) file_get_contents($root . '/app/views/platform/pricing.php');
        $this->assertStringContainsString('class="ref-promo"', $pricing);
        $this->assertStringNotContainsString('Sleva za doporučení', $pricing, 'už ne jako zapadlý řádek tabulky');
        $this->assertStringContainsString("url('/doporuceni')", (string) file_get_contents($root . '/app/views/platform/site_layout.php'));
        $this->assertStringContainsString("platform_url('/doporuceni')", (string) file_get_contents($root . '/app/views/admin/billing.php'));
    }
}
