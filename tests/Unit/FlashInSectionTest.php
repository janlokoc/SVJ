<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Chyba po uložení s návratem na oddíl (#kotva) je vidět i v tom oddílu (dřív jen nahoře a stránka sjela pryč);
 * PENB po chybě zachová vyplněné hodnoty a dokument průkazu patří do Revize a technická dokumentace.
 */
final class FlashInSectionTest extends TestCase
{
    public function testErrorCopiedIntoTargetSectionAndPenbKeepsInput(): void
    {
        $root = dirname(__DIR__, 2);
        $js = (string) file_get_contents($root . '/assets/app.js');
        $this->assertStringContainsString("document.querySelector('main [data-flash]')", $js, 'úspěch i chyba');
        $this->assertStringContainsString("msg.classList.add('flash-here')", $js);
        $this->assertStringContainsString('if (wrap) wrap.remove();', $js, 'nahoře nezůstane – stránka neskáče');
        foreach (['app/views/layout.php', 'app/views/platform/layout.php', 'app/views/platform/site_layout.php'] as $l) {
            $this->assertStringContainsString('role="status" data-flash>', (string) file_get_contents($root . '/' . $l), $l);
        }
        $this->assertStringContainsString("\$_SESSION['penb_form']", (string) file_get_contents($root . '/app/ext/routes_listing.php'));
        $v = (string) file_get_contents($root . '/app/views/ext/listing/_settings.php');
        $this->assertStringContainsString("unset(\$_SESSION['penb_form'])", $v);
        $this->assertStringContainsString("\$penb['until'] !== '' && !\$penb['valid']", $v, 'bez data žádné „Průkaz skončil .“');
        $this->assertStringContainsString("'technicke', 'residents'", (string) file_get_contents($root . '/app/ext/listing_lib.php'));
    }
}
