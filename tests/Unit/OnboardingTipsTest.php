<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * Průvodce nastavením a tipy na funkce (onboarding_steps/summary/save, feature_tips v app/routes_onboarding.php).
 * Soubor tras se načte s prázdnou $route – obsahuje jen definice funkcí a registrace tras.
 */
final class OnboardingTipsTest extends TestCase
{
    private array $savedSettings = [];
    private ?string $savedBeta = null;

    private const ADDED = ['zavady' => '2026-11-26', 'banka' => '2026-11-25'];

    private static function load(): void
    {
        if (!function_exists('onboarding_steps')) {
            $route = function (): void {};
            require_once dirname(__DIR__, 2) . '/app/routes_onboarding.php';
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::load();
        $this->savedSettings = all("SELECT `key`, value FROM settings WHERE `key` LIKE 'onb\\_%' ESCAPE '\\' OR `key` IN ('tips_hidden', 'hidden_features', 'ico', 'address', 'isir_last', 'isir_ico')");
        $v = pq("SELECT value FROM platform_settings WHERE key = 'beta_insolvence'")->fetchColumn();
        $this->savedBeta = $v === false ? null : (string) $v;
    }

    protected function tearDown(): void
    {
        q("DELETE FROM settings WHERE `key` LIKE 'onb\\_%' ESCAPE '\\' OR `key` IN ('tips_hidden', 'hidden_features', 'ico', 'address', 'isir_last', 'isir_ico')");
        foreach ($this->savedSettings as $r) {
            q('INSERT INTO settings (`key`, value) VALUES (?,?)', [$r['key'], $r['value']]);
        }
        setting('__reset');
        if ($this->savedBeta === null) {
            pq("DELETE FROM platform_settings WHERE key = 'beta_insolvence'");
            pset('__reset');
        } else {
            pset_save('beta_insolvence', $this->savedBeta);
        }
        parent::tearDown();
    }

    private static function set(string $key, ?string $value): void
    {
        q('DELETE FROM settings WHERE `key` = ?', [$key]);
        if ($value !== null) {
            q('INSERT INTO settings (`key`, value) VALUES (?,?)', [$key, $value]);
        }
        setting('__reset');
    }

    // ---------- Průvodce ----------

    public function testStepsStructureAndAutoDetection(): void
    {
        $steps = onboarding_steps();
        $this->assertSame(['udaje', 'vzhled', 'overeni', 'jednotky', 'ucty', 'vybor', 'kontakty', 'dokumenty', 'aktualita', 'termin', 'prevod', 'udaje-ochrana', 'zastup', 'oznameni'],
            array_keys($steps), 'Premium offers all steps');
        foreach ($steps as $key => $s) {
            $this->assertSame($key, $s['key']);
            $this->assertArrayHasKey($s['group'], ONBOARDING_GROUPS);
            $this->assertContains($s['state'], ['done', 'skip', 'wait']);
            $this->assertStringStartsWith('/', $s['href']);
            $this->assertIsBool($s['important']);
        }
        // Ukázkový dům má jednotky, kontakty, dokumenty, termíny, rozeslaný e-mail a vyplněné údaje.
        foreach (['jednotky', 'kontakty', 'dokumenty', 'termin', 'oznameni', 'udaje', 'ucty'] as $k) {
            $this->assertTrue($steps[$k]['auto'], $k);
            $this->assertSame('done', $steps[$k]['state'], $k);
        }
        $this->assertFalse($steps['udaje-ochrana']['auto'], 'privacy text is never detected automatically');

        self::set('ico', '');
        $this->assertSame('wait', onboarding_steps()['udaje']['state'], 'IČO missing');
        self::set('ico', '12345678');
        self::set('address', '');
        $this->assertSame('wait', onboarding_steps()['udaje']['state'], 'address missing');
        self::set('address', 'Ulice 1');
        $this->assertSame('done', onboarding_steps()['udaje']['state']);

        $admins = (int) val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1");
        $this->assertSame($admins > 1, onboarding_steps()['zastup']['auto']);
    }

    public function testManualStatesAndSave(): void
    {
        $k = 'udaje-ochrana';
        onboarding_save($k, '');
        $this->assertSame('wait', onboarding_steps()[$k]['state']);
        onboarding_save($k, 'done');
        $this->assertSame('done', setting('onb_' . $k));
        $this->assertSame('done', onboarding_steps()[$k]['state']);
        onboarding_save($k, 'skip');
        $this->assertSame('skip', onboarding_steps()[$k]['state']);
        onboarding_save($k, '');
        $this->assertSame('', setting('onb_' . $k), 'empty value removes the row');
        $this->assertSame(0, (int) val("SELECT COUNT(*) FROM settings WHERE `key` = 'onb_udaje-ochrana'"));
        $this->assertSame('wait', onboarding_steps()[$k]['state']);

        onboarding_save('jednotky', 'skip');
        $this->assertSame('done', onboarding_steps()['jednotky']['state'], 'detected content wins over a manual skip');
    }

    public function testSummary(): void
    {
        onboarding_save('udaje-ochrana', '');
        onboarding_save('hidden', '');
        $o = onboarding_summary();
        $steps = onboarding_steps();
        $this->assertSame($steps, $o['steps']);
        $this->assertSame(count($steps), $o['total']);
        $this->assertSame(count(array_filter($steps, fn($s) => $s['state'] !== 'wait')), $o['done']);
        $this->assertSame($o['total'] - $o['done'], count($o['waiting']));
        $this->assertContains('udaje-ochrana', array_column($o['waiting'], 'key'));
        $imp = array_map(fn($s) => (int) $s['important'], $o['waiting']);
        $sorted = $imp;
        rsort($sorted);
        $this->assertSame($sorted, $imp, 'important steps first');
        $this->assertSame(array_sum($imp), $o['important']);
        $this->assertFalse($o['hidden']);

        onboarding_save('udaje-ochrana', 'skip');
        $o2 = onboarding_summary();
        $this->assertSame($o['done'] + 1, $o2['done'], 'skipped step counts as handled');
        $this->assertSame($o['important'] - 1, $o2['important']);

        onboarding_save('hidden', '1');
        $this->assertTrue(onboarding_summary()['hidden']);
        onboarding_save('hidden', '');
        $this->assertFalse(onboarding_summary()['hidden']);
    }

    // ---------- Tipy ----------

    private static function assertTipOrder(array $tips): void
    {
        $rank = array_map(fn($t) => [(int) !empty($t['beta']), (int) $t['new']], array_values($tips));
        $sorted = $rank;
        usort($sorted, fn($a, $b) => $b <=> $a);
        self::assertSame($sorted, $rank, 'beta first, then new, then the rest');
    }

    public function testTipsUsedHiddenAndFeatureOff(): void
    {
        self::set('tips_hidden', null);
        self::set('hidden_features', null);
        $tips = feature_tips();
        self::assertTipOrder($tips);
        $this->assertSame(array_keys($tips), array_column($tips, 'key'));
        // Co dům už používá, se nenabízí.
        $this->assertGreaterThan(0, (int) val('SELECT COUNT(*) FROM polls'));
        $this->assertArrayNotHasKey('hlasovani', $tips);
        $this->assertArrayNotHasKey('ankety', $tips, 'demo house has surveys');
        $this->assertArrayNotHasKey('revize', $tips, 'demo house has maintenance');
        foreach ($tips as $k => $t) {
            $added = self::ADDED[$k] ?? '';
            $this->assertSame($added !== '' && $added >= date('Y-m-d', time() - 60 * 86400), $t['new'], "$k: new for 60 days after it was added");
        }

        $hadPush = (int) val('SELECT COUNT(*) FROM push_subs') > 0;
        $this->assertSame(!$hadPush, isset($tips['push']));
        $uid = (int) val("SELECT id FROM users WHERE active = 1 ORDER BY id LIMIT 1");
        q("INSERT INTO push_subs (user_id, endpoint, p256dh, auth, device, created_at, last_ok, fails) VALUES (?, 'https://push.example.invalid/tip', '', '', '', '', '', 0)", [$uid]);
        $sub = (int) db()->lastInsertId();
        try {
            $this->assertArrayNotHasKey('push', feature_tips(), 'used once a device is registered');
        } finally {
            q('DELETE FROM push_subs WHERE id = ?', [$sub]);
        }

        if (!$hadPush) {
            self::set('tips_hidden', 'push,nic');
            $this->assertArrayNotHasKey('push', feature_tips(), 'hidden by the admin');
            self::set('tips_hidden', null);
        }

        $presence = (int) val('SELECT COUNT(*) FROM event_presence') > 0;
        $this->assertSame(!$presence, isset(feature_tips()['shromazdeni']));
        self::set('hidden_features', 'hlasovani');
        $this->assertArrayNotHasKey('shromazdeni', feature_tips(), 'meeting presence belongs to the voting feature');
    }

    public function testBetaTipFirstForPremium(): void
    {
        self::set('tips_hidden', null);
        self::set('isir_last', null);
        self::set('isir_ico', null);
        $this->assertSame('premium', plan_edition());
        $this->assertArrayNotHasKey('beta-insolvence', feature_tips(), 'feature off by default');

        pset_save('beta_insolvence', 'beta');
        $tips = feature_tips();
        $this->assertSame('beta-insolvence', array_key_first($tips));
        $b = $tips['beta-insolvence'];
        $this->assertTrue($b['beta']);
        $this->assertTrue($b['new']);
        $this->assertSame(BETA_FEATURES['insolvence'][0], $b['title']);
        $this->assertSame(BETA_FEATURES['insolvence'][1], $b['href']);
        self::assertTipOrder($tips);

        self::set('tips_hidden', 'beta-insolvence');
        $this->assertArrayNotHasKey('beta-insolvence', feature_tips(), 'hidden by the admin');
        self::set('tips_hidden', null);
        self::set('isir_ico', '12345678');
        $this->assertArrayNotHasKey('beta-insolvence', feature_tips(), 'already used');
        self::set('isir_ico', null);

        pset_save('beta_insolvence', 'on');
        $this->assertArrayNotHasKey('beta-insolvence', feature_tips(), 'launched features are no longer beta tips');
    }

    /** Edice Zdarma: bez vzhledu a druhého správce v průvodci, bez tipů na placené funkce a bez betY. Edice se čte jednou za proces. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFreeEdition(): void
    {
        pq("UPDATE tenants SET billing = 1, plan = 'free', paid_until = '', trial_ends = '' WHERE slug = ?", [TENANT]);
        $this->assertSame('free', plan_edition());
        $steps = onboarding_steps();
        $this->assertArrayNotHasKey('vzhled', $steps);
        $this->assertArrayNotHasKey('zastup', $steps);
        $this->assertArrayHasKey('udaje', $steps);

        q('DELETE FROM push_subs');
        q('DELETE FROM surveys');
        q('DELETE FROM maintenance');
        pset_save('beta_insolvence', 'beta');
        $tips = feature_tips();
        foreach (['push', 'ankety', 'revize', 'platby', 'banka', 'beta-insolvence'] as $k) {
            $this->assertArrayNotHasKey($k, $tips, "$k is not in the free edition");
        }
    }
}
