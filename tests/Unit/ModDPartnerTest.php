<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Modul D (app/ext/acct_lib.php) – partnerský portál: export všech domů do ZIP a import jednoho souboru za více domů. */
final class ModDPartnerTest extends TestCase
{
    private function house(string $name, string $ico = ''): array
    {
        $slug = 'mp-' . bin2hex(random_bytes(4));
        tenant_create(['slug' => $slug, 'name' => $name, 'title' => $name, 'unit_prefix' => '', 'entrances' => 'A,B', 'seed' => 'demo']);
        if ($ico !== '') {
            tenant_set($slug, 'ico', $ico);
        }
        return tenant_row($slug);
    }

    public function testHouseMatchingBySlugNameAndIco(): void
    {
        $a = $this->house('SVJ Lipová 3', '22222222');
        $b = $this->house('SVJ Dubová 8');
        $twin1 = $this->house('SVJ Stejná 1');
        $twin2 = $this->house('SVJ Stejná 1');
        $houses = acct_partner_houses([$a, $b, $twin1, $twin2]);
        $this->assertSame('22222222', $houses[0]['_ico']);
        $this->assertSame($a['slug'], acct_match_house('22222222', $houses));
        $this->assertSame($a['slug'], acct_match_house('IČO 222 222 22', $houses));
        $this->assertSame($b['slug'], acct_match_house('svj dubová 8', $houses));
        $this->assertSame($b['slug'], acct_match_house(strtoupper($b['slug']), $houses));
        $this->assertSame('', acct_match_house('SVJ Stejná 1', $houses), 'dva domy stejného jména – nepoznáme');
        $this->assertSame($twin2['slug'], acct_match_house($twin2['slug'], $houses));
        $this->assertSame('', acct_match_house('', $houses));
        $this->assertSame('', acct_match_house('Neznámý dům', $houses));
    }

    public function testSplitMultiHouseFileAndPreviewPerHouse(): void
    {
        $a = $this->house('SVJ Javorová 1', '33333333');
        $b = $this->house('SVJ Habrová 2');
        $houses = acct_partner_houses([$a, $b]);
        $table = acct_read_table("Dům;Jednotka;Předpis;Saldo\n33333333;A/1;3000;-500\nSVJ Habrová 2;B/2;2800;0\nSVJ Habrová 2;Z/1;1;1\nCizí dům;A/1;1;1\n;A/2;1;1\n", 'domy.csv');
        $map = acct_guess_mapping($table['headers'], array_keys(acct_fields('accounts', true)));
        $this->assertSame(0, $map['house']);
        $this->assertSame(1, $map['unit']);
        $split = acct_split_houses($table, $map['house'], $houses);
        $this->assertSame([$a['slug'], $b['slug']], array_keys($split['groups']));
        $this->assertSame(['Cizí dům' => 1, '(prázdné)' => 1], $split['unknown']);
        $this->assertSame([1, 2], array_keys($split['groups'][$b['slug']]['rows']), 'původní pořadí řádků zůstane');
        $pvA = acct_preview(tenant_pdo($a['slug']), acct_house_tenant($a), 'accounts', $split['groups'][$a['slug']], $map);
        $pvB = acct_preview(tenant_pdo($b['slug']), acct_house_tenant($b), 'accounts', $split['groups'][$b['slug']], $map);
        $this->assertSame([1, 0], [$pvA['ok'], $pvA['bad']]);
        $this->assertSame([1, 1], [$pvB['ok'], $pvB['bad']]);
        $this->assertSame(4, $pvB['rows'][1]['line']);
        acct_apply(tenant_pdo($a['slug']), 'accounts', $pvA, '2026-11-25');
        $bal = tenant_pdo($a['slug'])->query("SELECT a.balance FROM unit_accounts a JOIN units u ON u.id = a.unit_id WHERE u.entrance = 'A' AND u.number = '1'")->fetchColumn();
        $this->assertSame(-500.0, (float) $bal);
        $this->assertSame([], acct_split_houses($table, null, $houses)['groups'], 'bez sloupce Dům nic');
    }

    public function testPartnerZipHasFolderPerHouse(): void
    {
        $a = $this->house('SVJ Akátová 4', '44444444');
        $b = $this->house('SVJ Bříza 5');
        [$zip, $failed] = acct_partner_zip([$a, $b], acct_export_params(['format' => 'pohoda']));
        $this->assertSame([], $failed);
        $tmp = tempnam(sys_get_temp_dir(), 'pz');
        file_put_contents($tmp, $zip);
        $z = new \ZipArchive();
        $this->assertTrue($z->open($tmp));
        $names = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $names[] = $z->getNameIndex($i);
        }
        sort($names);
        $expected = [$a['slug'] . '/' . $a['slug'] . '-pohoda.xml', $b['slug'] . '/' . $b['slug'] . '-pohoda.xml'];
        sort($expected);
        $this->assertSame($expected, $names);
        $d = new \DOMDocument();
        $this->assertTrue($d->loadXML((string) $z->getFromName($a['slug'] . '/' . $a['slug'] . '-pohoda.xml')));
        $this->assertSame('44444444', $d->documentElement->getAttribute('ico'));
        $z->close();
        unlink($tmp);
    }

    public function testTenantHouseInfo(): void
    {
        $t = $this->house('SVJ Cedrová 6', '55555555');
        tenant_set($t['slug'], 'site_unit_prefix', '6');
        tenant_set($t['slug'], 'acct_bank_code', 'CS');
        $h = acct_house_tenant($t);
        $this->assertSame(['6', '55555555', 'CS', $t['slug']], [$h['prefix'], $h['ico'], $h['opts']['acct_bank_code'], $h['slug']]);
        $this->assertSame(['A', 'B'], $h['entrances']);
        $units = acct_units(tenant_pdo($t['slug']), $h['prefix']);
        $this->assertSame('6A/1', $units[0]['label']);
        $this->assertTrue($units[0]['has_account']);
    }

    public function testPartnerImportAuditKeepsChain(): void
    {
        $t = $this->house('SVJ Modřín 7');
        $pdo = tenant_pdo($t['slug']);
        acct_tenant_audit($pdo, 'Partner Test (p@example.cz)', 'Účetnictví: import', 'upraveno 3');
        acct_tenant_audit($pdo, 'Partner Test (p@example.cz)', 'Účetnictví: import', 'upraveno 4');
        $rows = $pdo->query('SELECT at, user_name, ip, action, detail, chain FROM audit_log ORDER BY id DESC LIMIT 2')->fetchAll();
        $this->assertSame('upraveno 4', $rows[0]['detail']);
        $this->assertSame(hash('sha256', implode('|', [$rows[1]['chain'], $rows[0]['at'], $rows[0]['user_name'], $rows[0]['ip'], $rows[0]['action'], $rows[0]['detail']])), $rows[0]['chain']);
    }
}
