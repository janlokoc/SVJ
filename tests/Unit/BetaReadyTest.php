<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Funkce v betě, ke které provozovatel nemá přístupy ke službě (SMS brána, PSD2, katastr, exekuce, SSO, Bank iD),
 * se domu nenabízí k vyzkoušení – zůstává v „Připravujeme“, aby si nikdo nemyslel, že ji zkusí a bude fungovat.
 */
final class BetaReadyTest extends TestCase
{
    private array $old = [];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['BETA_READY_ALL'] = false;
        foreach (array_keys(BETA_FEATURES) as $k) {
            $this->old[$k] = pset('beta_' . $k);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->old as $k => $v) {
            pset_save('beta_' . $k, $v);
        }
        parent::tearDown();
    }

    public function testFeaturesWithoutServiceAccessStayPlanned(): void
    {
        foreach (['sms', 'bank-psd2', 'katastr', 'exekuce', 'sso', 'bankid'] as $k) {
            pset_save('beta_' . $k, 'beta');
            $this->assertFalse(beta_ready($k), $k . ': testy nemají přístupy ke službě');
            $this->assertFalse(beta_visible($k), $k . ': v betě, ale bez přístupů se domu nenabízí');
            pset_save('beta_' . $k, 'on');
            $this->assertFalse(beta_visible($k), $k . ': ani spuštěná bez přístupů');
        }
        $planned = array_keys(planned_features());
        foreach (['sms', 'katastr', 'insolvence', 'bankid'] as $k) {
            if (isset(PLANNED_FEATURES[$k]) && $k !== 'insolvence') {
                $this->assertContains($k, $planned, $k . ' zůstává v Připravujeme');
            }
        }
        $this->assertContains('sms', array_keys(planned_features(true)), 'veřejně také');
    }

    public function testFeaturesWithoutExternalServiceAreReady(): void
    {
        $this->assertTrue(beta_ready('insolvence'), 'veřejný ISIR');
        $this->assertTrue(beta_ready('shromazdeni-online'), 'videohovor dodá dům sám');
        pset_save('beta_insolvence', 'on');
        $this->assertTrue(beta_visible('insolvence'));
    }

    public function testPricingTagsUnreadyServicesAsPlanned(): void
    {
        pset_save('beta_sms', 'on');
        $root = dirname(__DIR__, 2);
        $this->assertStringContainsString("beta_stage(\$key) === 'on' && beta_ready(\$key)", (string) file_get_contents($root . '/app/views/ext/credit/pricing_rows.php'));
        $this->assertStringContainsString('Chybí přístupy ke službě', (string) file_get_contents($root . '/app/views/platform/settings.php'));
    }
}
