<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

require_once __DIR__ . '/ModEHelper.php';

/** Modul E – společné části (app/ext/rejstriky_lib.php): ceny, beta a edice, tabulky, spotřeba, denní úloha, menu. */
final class ModERejstrikyTest extends TestCase
{
    use ModEHelper;

    public function testFeaturesAreRegisteredOffByDefault(): void
    {
        $this->assertArrayHasKey('katastr', BETA_FEATURES);
        $this->assertArrayHasKey('exekuce', BETA_FEATURES);
        $this->pset('beta_katastr', null);
        $this->pset('beta_exekuce', null);
        $this->assertSame('off', beta_stage('katastr'));
        $this->assertSame('off', beta_stage('exekuce'));
        $this->assertFalse(rej_visible('katastr'));
        $this->assertFalse(rej_allowed('exekuce'));
    }

    public function testVisibilityFollowsBetaStage(): void
    {
        $this->pset('beta_katastr', 'beta');
        $this->pset('beta_exekuce', 'off');
        $this->pset('beta_insolvence', 'off');
        $this->assertTrue(rej_visible('katastr'), 'test house is Premium');
        $this->assertTrue(rej_allowed('katastr'));
        $this->assertTrue(rej_nav_visible());
        $this->pset('beta_katastr', 'off');
        $this->assertFalse(rej_nav_visible());
        $this->pset('beta_insolvence', 'on');
        $this->assertTrue(rej_nav_visible(), 'insolvency alone shows the summary page');
    }

    public function testPremiumOnly(): void
    {
        $this->assertTrue(rej_premium_for('premium'));
        $this->assertFalse(rej_premium_for('standard'));
        $this->assertFalse(rej_premium_for('free'));
        $this->assertFalse(rej_premium_for('nesmysl'));
        $this->assertTrue(rej_premium(), 'demo house without billing has everything');
    }

    public function testAdminMenuAndDailyHookRegistered(): void
    {
        $item = $GLOBALS['EXT_ADMIN_NAV']['/admin/rejstriky'] ?? null;
        $this->assertNotNull($item);
        $this->assertSame(['Katastr a rejstříky', 'Dům a lidé'], [$item[0], $item[1]]);
        $this->pset('beta_katastr', 'off');
        $this->pset('beta_exekuce', 'off');
        $this->pset('beta_insolvence', 'off');
        $this->assertFalse(($item[2])());
        $this->assertContains('rej_daily', $GLOBALS['EXT_DAILY']);
    }

    public function testDailyTasksRunExtensionHooks(): void
    {
        $ran = 0;
        $GLOBALS['EXT_DAILY'][] = $hook = function () use (&$ran) {
            $ran++;
            throw new \RuntimeException('chyba v modulu nesmí zastavit ostatní úlohy');
        };
        $last = setting('last_daily');
        $log = ini_set('error_log', '/dev/null');
        try {
            rej_set('last_daily', '2000-01-01');
            daily_tasks();
            $this->assertSame(1, $ran);
            $this->assertSame(date('Y-m-d'), val("SELECT value FROM settings WHERE `key` = 'last_daily'"));
            setting('__reset');
            daily_tasks();
            $this->assertSame(1, $ran, 'once a day');
        } finally {
            array_pop($GLOBALS['EXT_DAILY']);
            rej_set('last_daily', $last);
            ini_set('error_log', (string) $log);
        }
    }

    public function testPricesDefaultsAndOverrides(): void
    {
        foreach (array_keys(REJ_PRICES) as $k) {
            $this->pset($k, null);
        }
        // Ceny od 12/2026: katastr 1 490 Kč za dům a rok vč. 1 LV + 990 Kč za další LV, kontrola po 30 dnech, lustrace 99 Kč.
        $this->assertSame(1490, rej_price('rej_price_katastr_house'));
        $this->assertSame(990, rej_price('rej_price_katastr_lv_extra'));
        $this->assertSame(99, rej_price('rej_price_exekuce'));
        $this->assertSame(100, rej_price('rej_cost_katastr_lv'));
        $this->assertSame(30, rej_price('rej_katastr_days'));
        $this->assertSame(0, rej_price('rej_price_katastr_unit'), 'cena za jednotku se už nepoužívá');
        $this->assertSame([0, 1490, 2480, 3470], array_map('rej_katastr_year_price', [0, 1, 2, 3]));
        $this->pset('rej_price_exekuce', '150');
        $this->assertSame(150, rej_price('rej_price_exekuce'));
        $this->pset('rej_price_exekuce', 'abc');
        $this->assertSame(99, rej_price('rej_price_exekuce'), 'invalid value → default');
        $this->pset('rej_price_exekuce', '0');
        $this->assertSame(0, rej_price('rej_price_exekuce'), 'zero is allowed (free)');
        $this->assertSame(0, rej_price('neznamy'));
    }

    public function testUsageReportGroupsByTenantAndKind(): void
    {
        rej_usage_add('exekuce', 1, 90, 60, 'a');
        rej_usage_add('exekuce', 1, 90, 15, 'b');
        rej_usage_add('katastr_year', 12, 360, 0);
        pq("INSERT INTO rej_usage (tenant, at, kind, qty, price, cost) VALUES ('jiny', ?, 'exekuce', 1, 50, 60)", [date('Y-m-d H:i:s')]);
        pq("INSERT INTO rej_usage (tenant, at, kind, qty, price, cost) VALUES ('dum', '2020-01-05 10:00:00', 'exekuce', 1, 999, 999)");
        $r = rej_usage_report(date('Y-m-01'), date('Y-m-d', strtotime(date('Y-m-01') . ' +1 month')));
        $this->assertSame(['n' => 2, 'qty' => 2, 'price' => 180, 'cost' => 75], $r['dum']['exekuce']);
        $this->assertSame(['n' => 1, 'qty' => 12, 'price' => 360, 'cost' => 0], $r['dum']['katastr_year']);
        $this->assertSame(50, $r['jiny']['exekuce']['price']);
        $this->assertSame(999, rej_usage_report('2020-01-01', '2020-02-01')['dum']['exekuce']['price']);
        $this->assertCount(4, rej_usage_tenant(), 'only this house');
        $this->assertSame(['dum'], array_values(array_unique(array_column(rej_usage_tenant(), 'tenant'))));
        $this->assertSame(999, (int) rej_usage_tenant(1)[0]['price'], 'newest first');
    }

    public function testReadyCreatesTablesInFreshDatabaseOnce(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        rej_ready($pdo);
        rej_ready($pdo);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'rej_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['rej_exekuce', 'rej_katastr_changes', 'rej_persons'], $tables);
        $cols = array_column($pdo->query('PRAGMA table_info(rej_persons)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        $this->assertNotContains('rc', $cols, 'birth number is never stored');
        $this->assertContains('birth_date', $cols);
    }

    public function testHttpUsesInjectedTransport(): void
    {
        $this->fakeHttp(fn($m, $u, $h, $b) => ['status' => 201, 'body' => strtoupper($b)]);
        $this->assertSame(['status' => 201, 'body' => 'ABC', 'error' => ''], rej_http('POST', 'https://x.invalid', ['A: b'], 'abc'));
        $this->assertSame(['method' => 'POST', 'url' => 'https://x.invalid', 'headers' => ['A: b'], 'body' => 'abc'], $this->calls[0]);
    }

    public function testHttpErrorHidesAddressAndKeys(): void
    {
        $e = rej_http_error('file_get_contents(https://www.ceecr.cz/api/v4/auth?api_key=TAJNE&api_secret=TAJNE2): Failed to open stream: Connection refused');
        $this->assertSame('Connection refused', $e);
        $e = rej_http_error('php_network_getaddresses: getaddrinfo for https://x.invalid/?api_key=TAJNE failed');
        $this->assertStringNotContainsString('TAJNE', $e);
        $this->assertSame('spojení se nepodařilo', rej_http_error(''));
    }

    public function testSummaryShowsOnlyVisibleParts(): void
    {
        $this->pset('beta_katastr', 'on');
        $this->pset('beta_exekuce', 'off');
        $this->pset('beta_insolvence', 'on');
        rej_person_add(3, 'Dvořák', 'Tomáš', '1970-05-01', 1);
        q("UPDATE rej_persons SET isir_found = '[{\"sz\":\"1 INS 1/2026\"}]'");
        $s = rej_summary();
        $this->assertSame(['katastr', 'insolvence'], array_keys($s));
        $this->assertSame([1, 1490], [$s['katastr']['lv'], $s['katastr']['price']], 'bez zadaného LV se počítá s jedním');
        rej_set('rej_katastr_lv', '11, 12');
        $this->assertSame([2, 2480], [rej_summary()['katastr']['lv'], rej_summary()['katastr']['price']]);
        $this->assertFalse($s['katastr']['on']);
        $this->assertSame([1, 1], [$s['insolvence']['persons'], $s['insolvence']['person_hits']]);
        $this->pset('beta_exekuce', 'on');
        $this->assertArrayHasKey('exekuce', rej_summary());
    }

    public function testHelpArticleExists(): void
    {
        $f = dirname(__DIR__, 2) . '/app/help/katastr-a-rejstriky.php';
        $this->assertFileExists($f);
        $html = (string) file_get_contents($f);
        $this->assertStringContainsString('oprávněn', $html, 'GDPR legal basis is explained');
        $this->assertStringContainsString('neověřen', mb_strtolower($html), 'unverified steps are marked');
    }

    /** Hlídání insolvencí je v edici Standard i Premium (rejstřík je zdarma); katastr a exekuce jen Premium. Vlastní proces: edice se v požadavku pamatuje. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testInsolvencyFromStandardOthersPremium(): void
    {
        $this->pset('beta_insolvence', 'on');
        $this->pset('beta_katastr', 'on');
        $this->pset('beta_exekuce', 'on');
        $orig = pq("SELECT billing, plan, paid_until, trial_ends FROM tenants WHERE slug = 'dum'")->fetch();
        try {
            pq("UPDATE tenants SET billing = 1, plan = 'standard', paid_until = ?, trial_ends = '' WHERE slug = 'dum'", [date('Y-m-d', strtotime('+100 day'))]);
            $this->assertSame('standard', plan_edition());
            $this->assertTrue(rej_allowed('insolvence'));
            $this->assertFalse(rej_allowed('katastr'));
            $this->assertFalse(rej_allowed('exekuce'));
            $this->assertSame(['insolvence' => 'standard', 'katastr' => 'premium', 'exekuce' => 'premium'], REJ_EDITION, 'free edition has none');
        } finally {
            pq('UPDATE tenants SET billing = ?, plan = ?, paid_until = ?, trial_ends = ? WHERE slug = \'dum\'', [$orig['billing'], $orig['plan'], $orig['paid_until'], $orig['trial_ends']]);
        }
    }
}
