<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Přechod na cizí web po odeslání formuláře (banka, Bank iD, přihlášení u poskytovatele). Hlavička Location by v Chromu
 * a Firefoxu narazila na CSP form-action 'self' (platí i pro přesměrování odeslaného formuláře) – našel to ověřovací běh nápovědy.
 */
final class ExternalRedirectTest extends TestCase
{
    public function testPageLinksAndRefreshesToHttpsTarget(): void
    {
        $html = external_redirect_page('https://ob.gocardless.com/psd2/start/req-1/CS?a=1&b=2', 'Pokračovat do banky');
        $this->assertNotNull($html);
        $this->assertStringContainsString('<meta http-equiv="refresh" content="0;url=https://ob.gocardless.com/psd2/start/req-1/CS?a=1&amp;b=2">', $html);
        $this->assertStringContainsString('<a href="https://ob.gocardless.com/psd2/start/req-1/CS?a=1&amp;b=2">Pokračovat do banky</a>', $html);
        $this->assertStringNotContainsString('<script', $html, 'CSP nepovoluje skripty ve stránce');
    }

    public function testRejectsNonHttpsAndInjection(): void
    {
        $this->assertNull(external_redirect_page('http://example.com/'));
        $this->assertNull(external_redirect_page('javascript:alert(1)'));
        $this->assertNull(external_redirect_page('https://example.com/"><script>'));
        $this->assertNull(external_redirect_page('/admin'));
    }

    /** Po odeslání formuláře se na cizí web nesmí přesměrovat hlavičkou Location. */
    public function testFormFlowsToForeignSitesDoNotUseLocationHeader(): void
    {
        $root = __DIR__ . '/../../app/ext/';
        $psd2 = (string) file_get_contents($root . 'routes_psd2.php');
        $this->assertStringContainsString("redirect_external(\$r['link']", $psd2);
        $this->assertStringNotContainsString("header('Location: ' . \$r['link']", $psd2);
        $sso = (string) file_get_contents($root . 'routes_sso.php');
        $this->assertMatchesRegularExpression("~redirect_external\\(sso_start\\(\\\$p, 'site', \\['tenant' => TENANT, 'mode' => 'link'~", $sso);
        $this->assertStringContainsString("redirect_external(sso_start(\$p, \$ctx, ['mode' => 'link'", (string) file_get_contents($root . 'sso_lib.php'));
        $this->assertStringContainsString('redirect_external($r, ', (string) file_get_contents($root . 'routes_rollam.php'));
    }
}
