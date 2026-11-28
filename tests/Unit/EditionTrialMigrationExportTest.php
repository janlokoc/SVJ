<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Zkouška celé edice na 30 dní (jednou za web, bez platby, sama skončí) a příloha exportu k objednávce převodu
 * (uložená mimo veřejné soubory a zálohy, po dokončení převodu smazaná).
 */
final class EditionTrialMigrationExportTest extends TestCase
{
    private array $slugs = [];

    protected function tearDown(): void
    {
        foreach ($this->slugs as $s) {
            pq('DELETE FROM tenants WHERE slug = ?', [$s]);
            pq('DELETE FROM migrations WHERE tenant = ?', [$s]);
            pq('DELETE FROM payments WHERE tenant = ?', [$s]);
        }
        parent::tearDown();
    }

    private function tenant(): array
    {
        $slug = 'zk' . bin2hex(random_bytes(4));
        $this->slugs[] = $slug;
        tenant_create(['slug' => $slug, 'name' => 'SVJ ' . $slug, 'title' => 'Test', 'billing' => 1, 'owner_email' => $slug . '@example.cz']);
        return tenant_row($slug);
    }

    public function testEditionTrialOncePerSiteAndEndsByItself(): void
    {
        $t = $this->tenant();
        $this->assertSame('free', tenant_edition($t));
        $this->assertNull(edition_trial_problem($t, 'premium'));
        $this->assertNull(edition_trial_start($t, 'premium'));
        $t = tenant_row($t['slug']);
        $this->assertSame('premium', tenant_edition($t));
        $this->assertSame(date('Y-m-d', strtotime('+' . PLAN_TRIAL_DAYS . ' day')), $t['trial_ends']);
        $this->assertStringContainsString('už využili', (string) edition_trial_problem($t, 'standard'), 'jen jednou');
        $this->assertSame(0, (int) pq('SELECT COUNT(*) FROM payments WHERE tenant = ?', [$t['slug']])->fetchColumn(), 'nic se neobjedná');
        pq('UPDATE tenants SET trial_ends = ? WHERE slug = ?', [date('Y-m-d', strtotime('-1 day')), $t['slug']]);
        $this->assertSame('free', tenant_edition(tenant_row($t['slug'])), 'po skončení zpět do edice Zdarma');
        $this->assertNotNull(edition_trial_problem($this->tenant(), 'free'));
    }

    public function testPaidStandardCanTryPremium(): void
    {
        $t = $this->tenant();
        pq("UPDATE tenants SET paid_until = ?, plan = 'standard' WHERE slug = ?", [date('Y-m-d', strtotime('+200 day')), $t['slug']]);
        $t = tenant_row($t['slug']);
        $this->assertNotNull(edition_trial_problem($t, 'standard'), 'Standard už má');
        $this->assertNull(edition_trial_start($t, 'premium'));
        $t = tenant_row($t['slug']);
        $this->assertSame('premium', tenant_edition($t));
        pq('UPDATE tenants SET trial_ends = ? WHERE slug = ?', [date('Y-m-d', strtotime('-1 day')), $t['slug']]);
        $this->assertSame('standard', tenant_edition(tenant_row($t['slug'])), 'zaplacená edice zůstává');
    }

    public function testMigrationExportStoredPrivatelyAndDeleted(): void
    {
        $t = $this->tenant();
        $tmp = tempnam(sys_get_temp_dir(), 'exp');
        file_put_contents($tmp, 'PK fake zip');
        $r = migration_export_store(['error' => UPLOAD_ERR_OK, 'name' => 'integri-export.zip', 'tmp_name' => $tmp, 'size' => 11], $t['slug']);
        $this->assertIsArray($r);
        $this->assertFileExists(migration_export_dir() . '/' . $r[0]);
        $this->assertStringStartsWith(ROOT_STORAGE . '/prevody', migration_export_dir(), 'mimo veřejné soubory domu');
        $this->assertFileExists(migration_export_dir() . '/.htaccess');
        $this->assertMatchesRegularExpression(BACKUP_SKIP, 'prevody/' . $r[0], 'export nejde do zálohy');
        $this->assertIsString(migration_export_store(['error' => UPLOAD_ERR_OK, 'name' => 'skript.php', 'tmp_name' => $tmp, 'size' => 1], $t['slug']));
        $m = migration_order($t, ['url' => 'export', 'scope' => 'dokumenty', 'access' => 'export', 'note' => '', 'contact_name' => 'Výbor', 'contact_email' => 'vybor@example.cz', 'contact_phone' => '',
            'export_file' => $r[0], 'export_name' => $r[1], 'export_url' => '']);
        $this->assertSame('přiloženého exportu (integri-export.zip)', migration_source($m));
        migration_export_delete($m);
        $this->assertFileDoesNotExist(migration_export_dir() . '/' . $r[0]);
        $this->assertSame('', (string) migration_row($t['slug'])['export_file']);
    }

    public function testWiring(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertStringContainsString("'/admin/predplatne/zkouska-edice'", (string) file_get_contents($root . '/app/routes_billing.php'));
        $ed = (string) file_get_contents($root . '/app/views/platform/_editions.php');
        $this->assertStringContainsString('Vyzkoušet <?= PLAN_TRIAL_DAYS ?> dní zdarma', $ed);
        $this->assertStringContainsString("\$edNow === \$ek && !\$edOnTrial ? 'Prodloužit o rok'", $ed, 'během zkoušky Objednat, ne Prodloužit');
        $this->assertStringContainsString('name="export"', (string) file_get_contents($root . '/app/views/admin/billing.php'));
        $c = (string) file_get_contents($root . '/app/platform_console.php');
        $this->assertStringContainsString("\$action === '/prevod-export'", $c);
        $this->assertStringContainsString('migration_export_delete($m);', $c, 'smazání po dokončení');
    }
}
