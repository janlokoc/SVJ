<?php
declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use Tests\TestCase;

/** Plocha jednotky (units.area): migrace databáze, čtení z formuláře a importu, zobrazení správci i vlastníkovi. */
final class UnitAreaTest extends TestCase
{
    private function rmdir(string $dir): void
    {
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function testFreshDatabaseHasAreaColumnAndVersion17(): void
    {
        $dir = sys_get_temp_dir() . '/svj-area-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        try {
            $pdo = open_database($dir, '');
            $this->assertGreaterThanOrEqual(17, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
            $cols = array_column($pdo->query('PRAGMA table_info(units)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            $this->assertContains('area', $cols);
        } finally {
            $this->rmdir($dir);
        }
    }

    public function testOlderDatabaseGetsAreaColumnAndKeepsUnits(): void
    {
        $dir = sys_get_temp_dir() . '/svj-area-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        try {
            $pdo = open_database($dir, '');
            $pdo->exec("INSERT INTO units (entrance, number, floor, share) VALUES ('Z', '99', '4', '1/100')");
            // Databáze ve verzi 16 (před plochou jednotek).
            $pdo->exec('ALTER TABLE units DROP COLUMN area');
            $pdo->exec('PRAGMA user_version = 16');
            unset($pdo);
            $pdo = open_database($dir, '');
            $this->assertGreaterThanOrEqual(17, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
            $row = $pdo->query("SELECT * FROM units WHERE entrance = 'Z' AND number = '99'")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame(['4', '1/100'], [$row['floor'], $row['share']]);
            $this->assertArrayHasKey('area', $row);
            $this->assertNull($row['area'], 'u starých jednotek plocha není zadaná');
        } finally {
            $this->rmdir($dir);
        }
    }

    public function testParseAndLabel(): void
    {
        $this->assertSame(54.3, unit_area_parse('54,3'));
        $this->assertSame(54.3, unit_area_parse(' 54.3 m² '));
        $this->assertSame(120.0, unit_area_parse('120 m2'));
        $this->assertSame(1234.5, unit_area_parse('1 234,5'));
        $this->assertNull(unit_area_parse(''));
        $this->assertNull(unit_area_parse('  '));
        foreach (['velký', '0', '-5', '54,333', '20000', '5e3'] as $bad) {
            $this->assertFalse(unit_area_parse($bad), $bad);
        }
        $this->assertSame("54,3\u{a0}m²", unit_area_label(54.3));
        $this->assertSame("120\u{a0}m²", unit_area_label('120.00'));
        $this->assertSame('', unit_area_label(null));
        $this->assertSame('', unit_area_label(''));
    }

    public function testCurrentHouseHasColumn(): void
    {
        $this->assertContains('area', array_column(all('PRAGMA table_info(units)'), 'name'));
    }

    public function testAdminFormAndListShowArea(): void
    {
        $unit = ['id' => 5, 'entrance' => 'A', 'number' => '5', 'floor' => '2', 'share' => '1/10', 'owner_note' => '', 'note' => '', 'owner_contact' => '', 'area' => 54.3];
        $title = 'Upravit jednotku';
        $errors = [];
        $userIds = [];
        $users = [];
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/admin/unit_form.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('name="area" inputmode="decimal" value="54,3"', $html);
        $this->assertStringContainsString('Plocha (m²)', $html);
        // Po chybě se vrátí to, co správce napsal.
        $unit['area'] = 'hodně';
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/admin/unit_form.php';
        $this->assertStringContainsString('name="area" inputmode="decimal" value="hodně"', (string) ob_get_clean());

        $units = [['id' => 5, 'entrance' => 'A', 'number' => '5', 'floor' => '2', 'share' => '1/10', 'owner_note' => '', 'area' => 54.3]];
        $people = [];
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/admin/units.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('<th>Plocha</th>', $html);
        $this->assertStringContainsString("<td data-label=\"Plocha\">54,3\u{a0}m²</td>", $html);
    }

    public function testOwnerSeesAreaInAccount(): void
    {
        $u = one("SELECT * FROM users WHERE role = 'resident' AND active = 1 AND kind = 'owner' ORDER BY id LIMIT 1");
        $units = [['id' => 1, 'entrance' => 'A', 'number' => '1', 'floor' => '3', 'area' => '61.5', 'people' => []]];
        $errors = [];
        $inviteLink = null;
        $totpSetup = null;
        $canInvite = false;
        $inviteDays = 14;
        $title = 'Můj účet';
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/account.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString("Plocha jednotky: 61,5\u{a0}m²", $html);
        $this->assertStringNotContainsString('name="area"', $html, 'vlastník plochu nemění');
        $this->assertStringContainsString('/inzeraty/moje', $html, 'vlastník má odkaz na inzerát');
    }

    public function testImportReadsAreaColumn(): void
    {
        $slug = 'ar-' . bin2hex(random_bytes(4));
        tenant_create(['slug' => $slug, 'name' => 'SVJ Plošná 3', 'title' => 'Plošná 3', 'unit_prefix' => '3', 'entrances' => 'A,B', 'seed' => 'demo']);
        $house = acct_house_tenant(tenant_row($slug));
        $pdo = tenant_pdo($slug);
        $table = acct_read_table("Jednotka;Podíl;Plocha (m²)\nA/1;1/10;54,3\nA/2;1/10;obrovská\nA/3;1/10;\n", 'jednotky.csv');
        $map = acct_guess_mapping($table['headers'], array_keys(acct_fields('units')));
        $this->assertSame(2, $map['area']);
        $pv = acct_preview($pdo, $house, 'units', $table, $map);
        [$a1, $a2, $a3] = $pv['rows'];
        $this->assertSame(54.3, $a1['values']['area']);
        $this->assertStringContainsString('Plochu', $a2['errors'][0]);
        $this->assertArrayNotHasKey('area', $a3['values'], 'prázdná plocha nic nepřepíše');
        acct_apply($pdo, 'units', $pv, date('Y-m-d'));
        $this->assertSame(54.3, (float) $pdo->query("SELECT area FROM units WHERE entrance = 'A' AND number = '1'")->fetchColumn());
    }
}
