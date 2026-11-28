<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Převod ze starého webu: vlastní stránka oddělená od API a napojení; klíč jedním tlačítkem s oprávněními jen pro převod. */
final class MigrationPageTest extends TestCase
{
    public function testMigrationKeyAndPromptsAreSeparated(): void
    {
        api_ready();
        q('UPDATE api_keys SET revoked_at = ? WHERE name = ? AND revoked_at = ?', [date('Y-m-d H:i:s'), API_MIGRATION_KEY, '']);
        $this->assertNull(api_migration_key());
        [$id] = api_key_create(API_MIGRATION_KEY, API_MIGRATION_SCOPES, '', 1);
        $k = api_migration_key();
        $this->assertSame($id, (int) $k['id']);
        $sc = explode(' ', $k['scopes']);
        $exp = API_MIGRATION_SCOPES;
        sort($sc);
        sort($exp);
        $this->assertSame($exp, $sc, 'jen oprávnění pro převod');
        $this->assertNotContains('ledger:write', API_MIGRATION_SCOPES);
        $this->assertNotContains('meters:write', API_MIGRATION_SCOPES);
        api_key_revoke($id);
        $mig = array_column(api_prompts_migration(), 0);
        $com = array_column(api_prompts_committee(), 0);
        $this->assertSame([], array_intersect($mig, $com));
        $this->assertCount(count(api_prompts()), array_merge($mig, $com));
        $this->assertContains('Převod starého webu', $mig);
        $this->assertContains('Podklady pro schůzi výboru', $com);
    }

    public function testPagesAndWiring(): void
    {
        $root = dirname(__DIR__, 2);
        $r = (string) file_get_contents($root . '/app/ext/routes_api.php');
        $this->assertStringContainsString("\$route('POST', '/admin/prevod'", $r);
        $this->assertArrayHasKey('/admin/prevod', $GLOBALS['EXT_ADMIN_NAV']);
        $this->assertMatchesRegularExpression(PLAN_FEATURES['api'][3], '/admin/prevod', 'převod přes AI je v placených edicích (lze vyzkoušet)');
        $mv = (string) file_get_contents($root . '/app/views/ext/api/migration.php');
        $this->assertStringContainsString('Zkopírovat zadání – API', $mv);
        $this->assertStringContainsString('Objednat převod', $mv);
        $this->assertStringContainsString('export dat od Vaší správcovské firmy', $mv, 'u převodu z exportu je řečeno, že export je nutný');
        $av = (string) file_get_contents($root . '/app/views/ext/api/admin.php');
        $this->assertStringContainsString('api_prompts_committee()', $av, 'API a napojení bez zadání pro převod');
        $this->assertStringNotContainsString('api_prompts_migration()', $av);
    }
}
