<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * Služby placené z kreditu podle edice: online shromáždění (app/ext/hybrid_lib.php – Premium v ceně, Standard 490 Kč za termín,
 * vrácení při přepnutí zpět) a co je v edicích bez příplatku (insolvence a PSD2 v Premium, SSO všude, export do účetnictví od Standardu).
 *
 * Edice webu „dum“ se čte jednou za proces (plan_tenant()), proto testy edice Standard běží v samostatném procesu
 * (ten má vlastní dočasnou kopii platformy – změny se nepřenesou do ostatních testů).
 */
final class CreditServicesTest extends TestCase
{
    private function prepare(string $edition): void
    {
        if ($edition !== 'premium') {
            pq("UPDATE tenants SET billing = 1, plan = ?, paid_until = ?, trial_ends = '' WHERE slug = 'dum'", [$edition, date('Y-m-d', strtotime('+100 day'))]);
        }
        pset_save('beta_' . HYBRID_BETA, 'on');
        pset_save('credit_low_h', '0');
        q("INSERT INTO settings (`key`, value) VALUES ('hybrid_on', '1') ON CONFLICT(`key`) DO UPDATE SET value = '1'");
        setting('__reset');
    }

    private function event(int $dayOffset = 10): int
    {
        q('INSERT INTO events (title, event_date, event_time, place, note, rsvp, program) VALUES (?,?,?,?,?,1,?)',
            ['Shromáždění ' . uniqid(), date('Y-m-d', strtotime(($dayOffset >= 0 ? '+' : '') . $dayOffset . ' day')), '18:00', 'Sušárna', '', '']);
        return (int) db()->lastInsertId();
    }

    private static function online(string $form = 'online', string $url = 'https://meet.jit.si/Shromazdeni-test'): array
    {
        return ['hybrid_form' => $form, 'hybrid_video_url' => $url, 'hybrid_statutes' => '1'];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStandardPaysOnlineMeetingOnceAndGetsRefundWhenSwitchedBack(): void
    {
        $this->prepare('standard');
        $this->assertSame('standard', plan_edition());
        $this->assertTrue(hybrid_available());
        $this->assertTrue(hybrid_paid());
        $this->assertSame(49000, hybrid_price_h());
        $id = $this->event(10);

        // Bez kreditu formulář online formu odmítne (s odkazem na dobití ve formuláři).
        $err = hybrid_event_check(self::online(), $id);
        $this->assertCount(1, $err);
        $this->assertStringContainsString('490 Kč', str_replace("\u{a0}", ' ', $err[0]));
        $this->assertSame([], hybrid_event_check(self::online('prezencni', ''), $id), 'prezenční forma nic nestojí');
        ob_start();
        hybrid_form_fields($id);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Dobít kredit', $html);

        credit_add(TENANT, 100000, 'topup', 'test');
        $this->assertSame([], hybrid_event_check(self::online(), $id));
        hybrid_event_store($id, self::online(), 1);
        $this->assertSame('online', hybrid_event($id)['form']);
        $this->assertSame(51000, credit_balance_h(TENANT));
        $this->assertTrue(hybrid_charged($id));
        $this->assertSame([], hybrid_event_check(self::online('hybridni'), $id), 'zaplacený termín už kredit nepotřebuje');

        hybrid_event_store($id, self::online('hybridni', 'https://meet.jit.si/jina'), 1);
        $this->assertSame(51000, credit_balance_h(TENANT), 'jednou za termín');

        // Zpět na prezenční před začátkem, nikdo se nepřipojil → vrácení.
        hybrid_event_store($id, self::online('prezencni', ''), 1);
        $this->assertSame(100000, credit_balance_h(TENANT));
        $this->assertFalse(hybrid_charged($id));
        $last = credit_statement(TENANT, 1)[0];
        $this->assertSame(['online', 49000], [$last['kind'], (int) $last['delta_h']]);

        // Znovu online → znovu zaplaceno; když se už někdo připojil, při přepnutí zpět se nevrací.
        hybrid_event_store($id, self::online(), 1);
        $this->assertSame(51000, credit_balance_h(TENANT));
        hybrid_ready();
        q("INSERT INTO hybrid_attendance (event_id, unit_id, user_id, joined_at, verified) VALUES (?, 1, 1, ?, 'login')", [$id, date('Y-m-d H:i:s')]);
        hybrid_event_store($id, self::online('prezencni', ''), 1);
        $this->assertSame(51000, credit_balance_h(TENANT), 'někdo se připojil → bez vrácení');

        // Shromáždění, které už začalo, se nevrací.
        $past = $this->event(-1);
        hybrid_event_store($past, self::online(), 1);
        $this->assertSame(2000, credit_balance_h(TENANT));
        hybrid_event_store($past, self::online('prezencni', ''), 1);
        $this->assertSame(2000, credit_balance_h(TENANT), 'po začátku se nevrací');

        // Kredit nestačí (souběh): forma se nezmění, nic se nestrhne.
        $third = $this->event(20);
        hybrid_event_store($third, self::online(), 1);
        $this->assertSame('prezencni', hybrid_event($third)['form']);
        $this->assertSame(2000, credit_balance_h(TENANT));
    }

    public function testPremiumMeetingIsIncluded(): void
    {
        pq('DELETE FROM credit_ledger WHERE tenant = ?', [TENANT]);
        $saved = pq("SELECT value FROM platform_settings WHERE key = 'beta_" . HYBRID_BETA . "'")->fetchColumn();
        $hyb = val("SELECT value FROM settings WHERE `key` = 'hybrid_on'");
        try {
            $this->prepare('premium');
            $this->assertSame('premium', plan_edition());
            $this->assertFalse(hybrid_paid());
            $id = $this->event(5);
            $this->assertSame([], hybrid_event_check(self::online(), $id));
            hybrid_event_store($id, self::online(), 1);
            $this->assertSame('online', hybrid_event($id)['form']);
            $this->assertSame([], credit_statement(TENANT), 'v Premium se nic neplatí');
        } finally {
            $saved === false ? pq("DELETE FROM platform_settings WHERE key = 'beta_" . HYBRID_BETA . "'") : pset_save('beta_' . HYBRID_BETA, (string) $saved);
            pq("DELETE FROM platform_settings WHERE key = 'credit_low_h'");
            pset('__reset');
            $hyb === null || $hyb === false ? q("DELETE FROM settings WHERE `key` = 'hybrid_on'") : q("UPDATE settings SET value = ? WHERE `key` = 'hybrid_on'", [$hyb]);
            setting('__reset');
        }
    }

    // ---------- V ceně edic ----------

    public function testIncludedFeaturesByEdition(): void
    {
        // Export do účetnictví od edice Standard; import jednotek zůstává ve všech edicích.
        $this->assertSame('standard', PLAN_FEATURES['ucetnictvi-export'][0]);
        $this->assertSame('ucetnictvi-export', plan_feature_for('/admin/ucetnictvi/export'));
        $this->assertNull(plan_feature_for('/admin/ucetnictvi'));
        $this->assertNull(plan_feature_for('/admin/ucetnictvi/import'));
        // PSD2 patří k Platbám na jednotku (Premium), insolvence k Premium bez ceny, SSO jen podle betaprogramu.
        $this->assertSame('premium', PLAN_FEATURES['platby'][0]);
        $this->assertStringContainsString("plan_has('platby')", (string) file_get_contents(dirname(__DIR__, 2) . '/app/ext/psd2_lib.php'));
        $this->assertArrayNotHasKey('rej_price_insolvence', REJ_PRICES);
        $this->assertArrayNotHasKey('sso', PLAN_FEATURES);
        foreach (['sms', 'exekuce', 'bankid', 'katastr', 'shromazdeni-online', 'bank-psd2', 'insolvence', 'sso'] as $k) {
            $this->assertArrayHasKey($k, BETA_FEATURES, 'placené služby zůstávají v betaprogramu (výchozí stav vypnuto)');
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFreeEditionCannotDownloadAccountingExport(): void
    {
        $this->prepare('free');
        pq("UPDATE tenants SET billing = 1, plan = '', paid_until = '' WHERE slug = 'dum'");
        $this->assertSame('free', plan_edition());
        $this->assertFalse(plan_has('ucetnictvi-export'));
        $this->assertSame('available', plan_state('ucetnictvi-export'), 'jde vyzkoušet na 30 dní');
    }
}
