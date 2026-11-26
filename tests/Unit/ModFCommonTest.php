<?php
declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use Tests\TestCase;

/** Modul F – společné části (app/ext/modf_lib.php): tabulky, data, edice, menu, „Co se blíží“, kalendář, denní úlohy. */
final class ModFCommonTest extends TestCase
{
    public function testReadyCreatesTablesOnceOnFreshDatabase(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        modf_ready($pdo);
        modf_ready($pdo);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'modf_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['modf_contracts', 'modf_objections', 'modf_reminders', 'modf_repairs', 'modf_settlement_items', 'modf_settlements'], $tables);
        // Druhé PDO na stejnou databázi (např. po novém připojení) tabulky nezakládá znovu.
        $this->assertSame([], modf_upcoming($pdo, '2026-11-26'));
    }

    public function testDateHelpers(): void
    {
        $this->assertSame('2027-02-28', modf_add_months('2026-11-30', 3));
        $this->assertSame('2028-02-29', modf_add_months('2027-11-30', 3));
        $this->assertSame('2026-09-30', modf_add_months('2026-12-31', -3));
        $this->assertSame('2025-12-31', modf_add_months('2026-01-31', -1));
        $this->assertSame('2026-03-02', modf_add_days('2026-02-28', 2));
        $this->assertSame(1234.5, modf_number('1 234,50 Kč'));
        $this->assertSame(-50.0, modf_number('-50'));
        $this->assertNull(modf_number('abc'));
        $this->assertNull(modf_number(''));
        $this->assertSame("12\u{a0}500\u{a0}Kč", modf_money(12500));
    }

    public function testPlanFeaturesAndGating(): void
    {
        $this->assertSame('standard', PLAN_FEATURES['vyuctovani'][0]);
        $this->assertSame('standard', PLAN_FEATURES['smlouvy'][0]);
        $this->assertSame('premium', PLAN_FEATURES['plan-oprav'][0]);
        $this->assertSame('vyuctovani', plan_feature_for('/admin/vyuctovani/5'));
        $this->assertSame('vyuctovani', plan_feature_for('/vyuctovani'));
        $this->assertSame('smlouvy', plan_feature_for('/admin/smlouvy/nova'));
        $this->assertSame('plan-oprav', plan_feature_for('/plan-oprav'));
        $this->assertSame('plan-oprav', plan_feature_for('/admin/plan-oprav/3/smazat'));
        $this->assertNull(plan_feature_for('/admin/vyuctovanix'));
        // Edice Zdarma: nic z modulu, Standard: vyúčtování a smlouvy, výhled fondu až Premium.
        $_SESSION['demo_edition'] = 'standard';
        $this->assertSame('standard', tenant_edition(['demo' => 1]));
        $this->assertSame(EDITION_RANK['standard'], EDITION_RANK[PLAN_FEATURES['smlouvy'][0]]);
        $this->assertLessThan(EDITION_RANK[PLAN_FEATURES['plan-oprav'][0]], EDITION_RANK['standard']);
    }

    public function testAdminMenuItems(): void
    {
        foreach (['/admin/vyuctovani', '/admin/plan-oprav', '/admin/smlouvy'] as $href) {
            $this->assertSame('Provoz domu', $GLOBALS['EXT_ADMIN_NAV'][$href][1] ?? null, $href);
        }
    }

    public function testAccountLinksForOwnerAndPublishedFund(): void
    {
        $tenant = one("SELECT * FROM users WHERE role = 'resident' AND kind = 'tenant' AND active = 1 LIMIT 1") ?? ['id' => 0, 'role' => 'resident', 'kind' => 'tenant'];
        q("DELETE FROM settings WHERE `key` = 'modf_fund_published'");
        setting('__reset');
        $this->assertSame('', modf_account_links($tenant), 'Nájemník bez vyúčtování a bez zveřejněného fondu nic nevidí.');
        q("INSERT INTO settings (`key`, value) VALUES ('modf_fund_published', '1')");
        setting('__reset');
        $this->assertStringContainsString('/plan-oprav', modf_account_links($tenant));
        $admin = one("SELECT * FROM users WHERE role = 'admin' AND active = 1 LIMIT 1");
        $this->assertStringContainsString('Moje vyúčtování', modf_account_links($admin));
        q("DELETE FROM settings WHERE `key` = 'modf_fund_published'");
        setting('__reset');
    }

    public function testUpcomingFromContractsAndObjections(): void
    {
        [$d] = modf_contract_validate(['kind' => 'insurance', 'party' => 'Pojišťovna', 'subject' => 'Pojištění domu Test', 'end_date' => '2027-01-20', 'notice_value' => '6', 'notice_unit' => 'w', 'auto_renew' => '1']);
        $id = modf_contract_save($d);
        $up = modf_upcoming(db(), '2026-11-26');
        $titles = array_column($up, 'title');
        $this->assertContains('Poslední den pro výpověď: Pojištění domu Test – Pojišťovna', $titles);   // 9. 12. 2026
        $this->assertContains('Automatické prodloužení: Pojištění domu Test – Pojišťovna', $titles);
        $this->assertNotContains('Automatické prodloužení: Pojištění domu Test – Pojišťovna', array_column(modf_upcoming(db(), '2026-10-01'), 'title'), 'Víc než 60 dní dopředu se neukazuje.');
        // Přehled správy (dashboard_lib) termíny modulu přidá do „Co se blíží“.
        $dash = house_dashboard(db(), 90);
        $this->assertIsArray($dash['upcoming']);
        modf_contract_delete($id);
    }

    public function testIcsFeedAndToken(): void
    {
        [$d] = modf_contract_validate(['kind' => 'contract', 'party' => 'Výtahy', 'subject' => 'Servis výtahu ICS', 'end_date' => date('Y-m-d', strtotime('+100 day')), 'notice_value' => '1', 'notice_unit' => 'm', 'auto_renew' => '1']);
        $id = modf_contract_save($d);
        $t = modf_ics_token();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $t);
        $this->assertSame($t, modf_ics_token());
        $this->assertNotSame($t, modf_ics_token(true));
        $ics = modf_ics();
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString('UID:smlouva-' . $id . '-notice@', $ics);
        $this->assertStringContainsString('SUMMARY:Poslední den pro výpověď: Servis výtahu ICS – Výtahy', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:' . str_replace('-', '', modf_notice_deadline(modf_contract($id))), $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        modf_contract_delete($id);
    }

    public function testDailyRunsAndNeverThrows(): void
    {
        modf_daily('2026-11-26');
        modf_daily('2026-11-26');
        $this->assertTrue(function_exists('daily_tasks'));
        $this->assertStringContainsString("modf_daily()", (string) file_get_contents(dirname(__DIR__, 2) . '/app/extra_lib.php'), 'Denní úlohy modul volají.');
    }
}
