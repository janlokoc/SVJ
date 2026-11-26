<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Vlastní doménu už nenabízíme: konzole ji neukazuje jako nastavitelnou nabídku, pole u společenství jen u webů, které ji mají. */
final class DomainOfferTest extends TestCase
{
    public function testConsoleDoesNotOfferCustomDomain(): void
    {
        $v = dirname(__DIR__, 2) . '/app/views/platform/';
        $settings = (string) file_get_contents($v . 'settings.php');
        $this->assertStringNotContainsString('Vlastní doména společenství (Kč za rok)', $settings);
        $this->assertStringContainsString('type="hidden" name="price_domain"', $settings, 'value kept for existing domains');
        $form = (string) file_get_contents($v . 'tenant_form.php');
        $this->assertStringContainsString("<?php if ((\$t['custom_domain'] ?? '') !== ''):", $form);
        $this->assertStringNotContainsString('Vlastní doména (nepovinné)', $form);
        $this->assertStringNotContainsString('placený doplněk', $form);
    }
}
