<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * Edice, limity, zkoušení funkcí a beta program (app/plan_lib.php).
 *
 * Edice aktuálního webu („dum“) se čte z řádku společenství jednou za proces (statická cache v plan_tenant()),
 * proto testy jiné edice než Premium běží v samostatném procesu: nejdřív upraví řádek „dum“, pak se poprvé ptají na edici.
 */
final class PlanTest extends TestCase
{
    /** @var array<string, ?string> uložené hodnoty nastavení služby (null = v tabulce nebyly) */
    private array $savedPsets = [];

    protected function tearDown(): void
    {
        foreach ($this->savedPsets as $key => $old) {
            if ($old === null) {
                pq('DELETE FROM platform_settings WHERE key = ?', [$key]);
                pset('__reset');
            } else {
                pset_save($key, $old);
            }
        }
        $this->savedPsets = [];
        parent::tearDown();
    }

    private function setPset(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->savedPsets)) {
            $v = pq('SELECT value FROM platform_settings WHERE key = ?', [$key])->fetchColumn();
            $this->savedPsets[$key] = $v === false ? null : (string) $v;
        }
        pset_save($key, $value);
    }

    private function clearPset(string $key): void
    {
        if (!array_key_exists($key, $this->savedPsets)) {
            $v = pq('SELECT value FROM platform_settings WHERE key = ?', [$key])->fetchColumn();
            $this->savedPsets[$key] = $v === false ? null : (string) $v;
        }
        pq('DELETE FROM platform_settings WHERE key = ?', [$key]);
        pset('__reset');
    }

    /** Nastaví edici webu „dum“ (jen v izolovaném procesu – před prvním dotazem na edici). */
    private function setDumEdition(string $edition): void
    {
        if ($edition === 'free') {
            pq("UPDATE tenants SET billing = 1, plan = '', paid_until = '', trial_ends = '' WHERE slug = 'dum'");
        } else {
            pq("UPDATE tenants SET billing = 1, plan = ?, paid_until = ?, trial_ends = '' WHERE slug = 'dum'", [$edition, date('Y-m-d', strtotime('+100 day'))]);
        }
    }

    // ---------- Konstanty ----------

    public function testEditionConstantsAreConsistent(): void
    {
        $this->assertSame(['free', 'standard', 'premium'], array_keys(EDITIONS));
        $this->assertSame(array_keys(EDITIONS), array_keys(EDITION_RANK));
        $this->assertLessThan(EDITION_RANK['standard'], EDITION_RANK['free']);
        $this->assertLessThan(EDITION_RANK['premium'], EDITION_RANK['standard']);
        $this->assertSame(30, PLAN_TRIAL_DAYS);
        foreach (array_keys(PLAN_LIMIT_DEFAULTS) as $edition) {
            $this->assertArrayHasKey($edition, EDITIONS);
            $this->assertSame(array_keys(PLAN_LIMITS), array_values(array_intersect(array_keys(PLAN_LIMITS), array_keys(PLAN_LIMIT_DEFAULTS[$edition]))));
            foreach (PLAN_LIMIT_DEFAULTS[$edition] as $key => $value) {
                $this->assertSame((string) $value, PLATFORM_DEFAULTS['lim_' . $edition . '_' . $key], "lim_{$edition}_{$key}");
            }
        }
    }

    public function testPlanFeaturesShape(): void
    {
        $this->assertNotEmpty(PLAN_FEATURES);
        foreach (PLAN_FEATURES as $key => $f) {
            $this->assertCount(4, $f, $key);
            $this->assertContains($f[0], ['standard', 'premium'], "$key: nejnižší edice nesmí být Zdarma");
            $this->assertNotSame('', $f[1]);
            $this->assertNotSame('', $f[2]);
            if ($f[3] !== null) {
                $this->assertNotFalse(@preg_match($f[3], ''), "$key: neplatný regulární výraz");
            }
        }
        foreach (array_keys(PLAN_MOVED) as $moved) {
            $this->assertSame('premium', PLAN_FEATURES[$moved][0], "$moved byl přesunut do Premium");
        }
    }

    public function testPlanFeatureFor(): void
    {
        $this->assertSame('ankety', plan_feature_for('/ankety'));
        $this->assertSame('ankety', plan_feature_for('/admin/ankety/5'));
        $this->assertSame('platby', plan_feature_for('/admin/platby'));
        $this->assertSame('rezervace', plan_feature_for('/prostory'));
        $this->assertSame('zapis', plan_feature_for('/admin/terminy/12/zapis'));
        $this->assertSame('ai-asistent', plan_feature_for('/asistent'));
        $this->assertNull(plan_feature_for('/admin/terminy/12'));
        $this->assertNull(plan_feature_for('/anketyx'));
        $this->assertNull(plan_feature_for('/aktuality'));
        $this->assertNull(plan_feature_for('/'));
    }

    public function testEditionPrice(): void
    {
        $this->assertSame(0, edition_price('free'));
        $this->assertSame(pset_int('price_standard', 0, 1000000), edition_price('standard'));
        $this->setPset('price_premium', '7000');
        $this->assertSame(7000, edition_price('premium'));
        $this->setPset('price_premium', '99999999');
        $this->assertSame(1000000, edition_price('premium'), 'cena je shora omezená');
        $this->setPset('price_premium', 'nesmysl');
        $this->assertSame((int) PLATFORM_DEFAULTS['price_premium'], edition_price('premium'));
    }

    // ---------- Web „dum“: Premium bez placení ----------

    public function testUnmanagedBillingTenantIsPremiumWithEverything(): void
    {
        $this->assertTrue(plan_managed());
        $this->assertSame('premium', plan_edition());
        foreach (array_keys(PLAN_FEATURES) as $feature) {
            $this->assertSame('on', plan_state($feature), $feature);
            $this->assertTrue(plan_has($feature), $feature);
        }
        $this->assertSame('on', plan_state('neexistujici-funkce'));
        $this->assertFalse(plan_trial_start('platby'), 'funkci, která je v edici, nejde zkoušet');
        $this->assertArrayNotHasKey('platby', plan_trials(true));
    }

    public function testPremiumLimits(): void
    {
        $this->assertSame(0, plan_limit('admins'));
        $this->assertSame(0, plan_limit('users'));
        $this->assertSame(pset_int('lim_premium_storage', 0, 10000000), plan_limit_of('premium', 'storage'));
        $this->assertSame(pset_int('lim_standard_admins', 0, 10000000), plan_limit_of('standard', 'admins'));
        $this->assertSame(plan_limit_of('premium', 'storage') + storage_extra_mb(plan_tenant()), plan_limit('storage'));
        $this->assertNull(plan_limit_error('admins', 1000), 'bez limitu žádná chyba');
        $this->assertNull(plan_limit_error('storage', 1));
        $err = plan_limit_error('storage', 10000000);
        $this->assertIsString($err);
        $this->assertStringStartsWith('Úložiště je plné', $err);
        $this->assertStringContainsString('MB', $err);
    }

    public function testPlanLimitChangesWithSetting(): void
    {
        $this->setPset('lim_premium_admins', '1');
        $this->assertSame(1, plan_limit('admins'));
        $used = plan_used('admins');
        $this->assertGreaterThanOrEqual(1, $used, 'ukázkový dům má aspoň jednoho správce');
        $err = plan_limit_error('admins');
        $this->assertIsString($err);
        $this->assertStringContainsString('Edice Premium má limit 1 správců webu', $err);
        $this->assertStringContainsString('na edici Premium', $err, 'z Premium vede doporučení zase na Premium');
    }

    public function testPlanUsed(): void
    {
        $this->assertSame((int) val('SELECT COUNT(*) FROM users WHERE active = 1'), plan_used('users'));
        $this->assertSame((int) val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1"), plan_used('admins'));
        $this->assertSame((int) val('SELECT COUNT(*) FROM polls WHERE opens_at >= ?', [date('Y-01-01')]), plan_used('polls'));
        $this->assertSame((int) ceil(plan_storage_mb()), plan_used('storage'));
        $this->assertGreaterThanOrEqual(0, plan_used('mails'));
        $this->assertSame(0, plan_used('neznamy-limit'));
    }

    // ---------- Beta program ----------

    public function testBetaStageValues(): void
    {
        $this->clearPset('isir_enabled');
        foreach (['off', 'beta', 'on'] as $stage) {
            $this->setPset('beta_insolvence', $stage);
            $this->assertSame($stage, beta_stage('insolvence'));
            $this->assertSame($stage === 'beta', beta_is('insolvence'));
        }
        $this->assertSame('on', beta_stage('neznama-funkce'), 'neznámá funkce není v beta programu');
        $this->assertFalse(beta_is('neznama-funkce'));
    }

    public function testBetaLegacyFallback(): void
    {
        $this->clearPset('beta_insolvence');
        $this->clearPset('isir_enabled');
        $this->assertSame('off', beta_stage('insolvence'));
        $this->setPset('isir_enabled', '1');
        $this->assertSame('on', beta_stage('insolvence'), 'starý přepínač isir_enabled = 1 znamená spuštěno');
        $this->setPset('isir_enabled', '0');
        $this->assertSame('off', beta_stage('insolvence'));
        // Nová hodnota má přednost před starým přepínačem.
        $this->setPset('isir_enabled', '1');
        $this->setPset('beta_insolvence', 'off');
        $this->assertSame('off', beta_stage('insolvence'));
        // Neplatná hodnota se ignoruje a rozhoduje starý přepínač.
        $this->setPset('beta_insolvence', 'nesmysl');
        $this->assertSame('on', beta_stage('insolvence'));
    }

    public function testBetaVisibleForPremium(): void
    {
        $this->assertTrue(beta_premium());
        $this->clearPset('isir_enabled');
        $this->setPset('beta_insolvence', 'off');
        $this->assertFalse(beta_visible('insolvence'));
        $this->setPset('beta_insolvence', 'beta');
        $this->assertTrue(beta_visible('insolvence'));
        $this->setPset('beta_insolvence', 'on');
        $this->assertTrue(beta_visible('insolvence'));
    }

    // ---------- Jiné edice (samostatný proces) ----------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStandardEditionFeaturesAndTrials(): void
    {
        $this->setDumEdition('standard');
        $this->assertSame('standard', plan_edition());
        $this->assertSame('on', plan_state('ankety'));
        $this->assertSame('on', plan_state('odecty'));
        $this->assertSame('available', plan_state('platby'), 'Premium funkce jde ve Standardu vyzkoušet');
        $this->assertFalse(plan_has('platby'));

        $this->assertTrue(plan_trial_start('platby'));
        $trial = plan_trials()['platby'];
        $this->assertSame(date('Y-m-d'), $trial['started']);
        $this->assertSame(date('Y-m-d', strtotime('+' . PLAN_TRIAL_DAYS . ' day')), $trial['ends']);
        $this->assertSame('trial', plan_state('platby'));
        $this->assertTrue(plan_has('platby'));
        $this->assertFalse(plan_trial_start('platby'), 'každou funkci jde vyzkoušet jen jednou');

        // Skončená zkouška: jen pro čtení.
        pq('INSERT INTO feature_trials (tenant, feature, started, ends) VALUES (?,?,?,?)', ['dum', 'rezervace', date('Y-m-d', strtotime('-40 day')), date('Y-m-d', strtotime('-1 day'))]);
        plan_trials(true);
        $this->assertSame('expired', plan_state('rezervace'));
        $this->assertFalse(plan_has('rezervace'));
        $this->assertFalse(plan_trial_start('rezervace'));

        // Zkouška končící dnes ještě běží.
        trial_set('dum', 'jazyky', date('Y-m-d'));
        plan_trials(true);
        $this->assertSame('trial', plan_state('jazyky'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStandardEditionLimitsAndBeta(): void
    {
        $this->setDumEdition('standard');
        $this->assertSame(pset_int('lim_standard_admins', 0, 10000000), plan_limit('admins'));
        $err = plan_limit_error('users', 100000);
        $this->assertIsString($err);
        $this->assertStringContainsString('Edice Standard má limit', $err);
        $this->assertStringContainsString('na edici Premium', $err);
        $this->assertSame(plan_used('users') <= plan_limit('users'), plan_limit_error('users', 0) === null);

        // Beta funkce vidí jen Premium; spuštěnou vidí všichni.
        $this->assertFalse(beta_premium());
        pq('DELETE FROM platform_settings WHERE key = ?', ['isir_enabled']);
        pset_save('beta_insolvence', 'beta');
        $this->assertFalse(beta_visible('insolvence'));
        $this->assertTrue(beta_is('insolvence'));
        pset_save('beta_insolvence', 'on');
        $this->assertTrue(beta_visible('insolvence'));
        pset_save('beta_insolvence', 'off');
        $this->assertFalse(beta_visible('insolvence'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFreeEdition(): void
    {
        $this->setDumEdition('free');
        $this->assertSame('free', plan_edition());
        $this->assertSame('available', plan_state('ankety'));
        $this->assertSame('available', plan_state('platby'));
        $this->assertFalse(plan_has('ankety'));
        $this->assertSame(pset_int('lim_free_users', 0, 10000000), plan_limit('users'));
        $err = plan_limit_error('users', 100000);
        $this->assertIsString($err);
        $this->assertStringContainsString('Edice Zdarma má limit', $err);
        $this->assertStringContainsString('na edici Standard', $err, 'ze Zdarma vede doporučení na Standard');
        $this->assertFalse(beta_premium());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStandardPaidBeforeMoveKeepsMovedFeatures(): void
    {
        $this->setDumEdition('standard');
        $until = date('Y-m-d', strtotime('+100 day'));
        pq("INSERT INTO payments (tenant, vs, amount, months, status, created_at, paid_at, period_from, period_to, kind, item) VALUES ('dum', '1', 2990, 12, 'paid', ?, ?, ?, ?, 'service', 'standard')",
            ['2026-01-10 10:00:00', '2026-01-10 10:00:00', '2026-01-10', $until]);
        $this->assertTrue(plan_kept('platby'));
        $this->assertSame('on', plan_state('platby'), 'Standard zaplacený před přesunem má funkci do konce období');
        $this->assertSame('on', plan_state('rezervace'));
        $this->assertFalse(plan_kept('ai-asistent'), 'nepřesunutá funkce se neponechává');
        $this->assertSame('available', plan_state('ai-asistent'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStandardPaidAfterMoveDoesNotKeep(): void
    {
        $this->setDumEdition('standard');
        $until = date('Y-m-d', strtotime('+100 day'));
        pq("INSERT INTO payments (tenant, vs, amount, months, status, created_at, paid_at, period_from, period_to, kind, item) VALUES ('dum', '1', 2990, 12, 'paid', ?, ?, ?, ?, 'service', 'standard')",
            ['2026-11-23 10:00:00', '2026-11-23 10:00:00', '2026-11-23', $until]);
        $this->assertFalse(plan_kept('platby'));
        $this->assertSame('available', plan_state('platby'));
    }
}
