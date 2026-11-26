<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Beta program: funkce v betě má v menu administrace štítek „· beta“ (i položky z modulů) a sekce přihlášení přes další účty
 * štítek Beta. SMS na ostrém webu bez nastavené brány se neodešlou (výchozí „jen záznam“ by jinak strhl kredit bez odeslání).
 */
final class BetaMarkersTest extends TestCase
{
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            $v === null ? pq('DELETE FROM platform_settings WHERE key = ?', [$k]) : pset_save($k, $v);
        }
        pset('__reset');
        parent::tearDown();
    }

    private function pset(string $k, ?string $v): void
    {
        if (!array_key_exists($k, $this->saved)) {
            $o = pq('SELECT value FROM platform_settings WHERE key = ?', [$k])->fetchColumn();
            $this->saved[$k] = $o === false ? null : (string) $o;
        }
        if ($v === null) {
            pq('DELETE FROM platform_settings WHERE key = ?', [$k]);
            pset('__reset');
        } else {
            pset_save($k, $v);
        }
    }

    public function testMenuTagForModuleItems(): void
    {
        $this->assertTrue(beta_premium(), 'test house sees Premium betas');
        $this->pset('beta_sms', 'beta');
        $this->assertSame(' · beta', beta_menu_tag('/admin/sms'));
        $this->pset('beta_sms', 'on');
        $this->assertSame('', beta_menu_tag('/admin/sms'), 'launched feature has no tag');
        foreach (['katastr', 'exekuce', 'insolvence'] as $k) {
            $this->pset('beta_' . $k, 'off');
        }
        $this->assertSame('', beta_menu_tag('/admin/rejstriky'));
        $this->pset('beta_katastr', 'beta');
        $this->assertSame(' · beta', beta_menu_tag('/admin/rejstriky'), 'sub-page /admin/rejstriky/katastr');
        $this->assertSame('', beta_menu_tag('/admin/rejstrik'), 'prefix must end at a slash');
        $this->assertSame('', beta_menu_tag('/admin/aktuality'));
    }

    public function testSsoSectionShowsBetaTag(): void
    {
        $this->pset('beta_sso', 'beta');
        $GLOBALS['SSO_CONFIG'] = ['google' => ['client_id' => 'x', 'client_secret' => 'y']];
        try {
            $u = $this->loginAs('resident');
            $html = sso_account_box('site', (int) $u['id']);
            if ($html === '') {
                $this->markTestSkipped('SSO section not rendered in this setup');
            }
            $this->assertStringContainsString('Přihlášení přes další účty <span class="tag tag-beta">Beta</span>', $html);
            $this->pset('beta_sso', 'on');
            $this->assertStringNotContainsString('tag-beta', sso_account_box('site', (int) $u['id']));
        } finally {
            unset($GLOBALS['SSO_CONFIG']);
        }
    }

    public function testSmsRefusedOnLiveSiteWithoutGateway(): void
    {
        $this->pset('beta_sms', 'on');
        $this->pset('sms_provider', null);
        $this->assertFalse(is_staging());
        $this->assertFalse(sms_gateway_ready(), 'default "log" on a live site = not configured');
        $r = sms_send_ex('+420601123456', 'Odstávka vody zítra 8–12 h.');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('SMS brána zatím není nastavená', $r['error']);
        $this->pset('sms_provider', 'log');
        $this->assertTrue(sms_gateway_ready(), 'operator explicitly chose the log for testing');
        $this->pset('sms_provider', 'gosms');
        $this->assertTrue(sms_gateway_ready());
    }
}
