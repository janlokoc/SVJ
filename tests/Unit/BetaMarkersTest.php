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

    /** Migrace v29: „připravujeme“ (vypnuto i nenastaveno) → beta; spuštěné a starší zapnuté insolvence beze změny. */
    public function testMigrationMovesPreparedFeaturesToBeta(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE platform_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec("INSERT INTO platform_settings VALUES ('beta_sms', 'off'), ('beta_sso', 'on'), ('beta_katastr', 'beta'), ('isir_enabled', '1')");
        platform_beta_2026_11($pdo);
        $all = $pdo->query('SELECT key, value FROM platform_settings')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame('beta', $all['beta_sms']);
        $this->assertSame('on', $all['beta_sso'], 'launched stays launched');
        $this->assertSame('beta', $all['beta_katastr']);
        $this->assertArrayNotHasKey('beta_insolvence', $all, 'legacy switch already on');
        foreach (['bank-psd2', 'shromazdeni-online', 'exekuce', 'bankid'] as $k) {
            $this->assertSame('beta', $all['beta_' . $k], $k);
        }
        $this->assertSame(array_keys(BETA_FEATURES), array_values(array_intersect(array_keys(BETA_FEATURES), ['insolvence', 'sms', 'bank-psd2', 'shromazdeni-online', 'katastr', 'exekuce', 'sso', 'bankid'])), 'migration covers every beta feature');
    }

    /** „Připravujeme“ nenabízí funkce, které dům už má v betě; veřejný web služby jen ty spuštěné (beta je navenek „připravujeme“). */
    public function testPlannedListDropsFeaturesInBeta(): void
    {
        foreach (['sms', 'katastr', 'insolvence', 'exekuce', 'bankid'] as $k) {
            $this->pset('beta_' . $k, 'off');
        }
        $this->assertSame(array_keys(PLANNED_FEATURES), array_keys(planned_features()));
        $this->assertSame(array_keys(PLANNED_FEATURES), array_keys(planned_features(true)));
        $this->pset('beta_sms', 'beta');
        $this->pset('beta_exekuce', 'beta');
        $this->assertArrayNotHasKey('sms', planned_features(), 'Premium house sees SMS in beta');
        $this->assertArrayNotHasKey('insolvence', planned_features(), 'exekuce also covers this item');
        $this->assertArrayHasKey('katastr', planned_features());
        $this->assertArrayHasKey('sms', planned_features(true), 'veřejně je beta pořád „připravujeme“');
        $this->pset('beta_sms', 'on');
        $this->assertArrayNotHasKey('sms', planned_features(true), 'spuštěná funkce z Připravujeme zmizí');
        $this->assertArrayHasKey('ai-zapis-nahravka', planned_features(true), 'not a beta feature');
        $this->assertStringContainsString('1 490 Kč', PLANNED_FEATURES['katastr'][2]);
        $this->assertStringContainsString('19 Kč', PLANNED_FEATURES['bankid'][2]);
    }
}
