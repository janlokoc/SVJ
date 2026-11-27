<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Modul D (app/ext/acct_lib.php) – import CSV/XLSX s odhadem sloupců, náhledem, kontrolou a uložením. */
final class ModDImportTest extends TestCase
{
    private function house(): array
    {
        $slug = 'mi-' . bin2hex(random_bytes(4));
        tenant_create(['slug' => $slug, 'name' => 'SVJ Importní 7', 'title' => 'Importní 7', 'unit_prefix' => '7', 'entrances' => 'A,B', 'seed' => 'demo']);
        return [acct_house_tenant(tenant_row($slug)), tenant_pdo($slug)];
    }

    /** Minimální XLSX: sdílené texty, vložený text a čísla. */
    private function xlsx(array $rows): string
    {
        $shared = [];
        $sheet = '';
        foreach ($rows as $ri => $row) {
            $sheet .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($row) as $ci => $v) {
                $ref = chr(65 + $ci) . ($ri + 1);
                if (is_int($v) || is_float($v)) {
                    $sheet .= "<c r=\"$ref\"><v>$v</v></c>";
                } elseif ($ci === 1 && $ri > 0) {
                    $sheet .= "<c r=\"$ref\" t=\"inlineStr\"><is><t>" . htmlspecialchars($v) . '</t></is></c>';
                } else {
                    $shared[] = $v;
                    $sheet .= "<c r=\"$ref\" t=\"s\"><v>" . (count($shared) - 1) . '</v></c>';
                }
            }
            $sheet .= '</row>';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xt');
        $z = new \ZipArchive();
        $z->open($tmp, \ZipArchive::OVERWRITE);
        $z->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="1" r:id="rId7"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="worksheet" Target="worksheets/data.xml"/></Relationships>');
        $z->addFromString('xl/worksheets/data.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheet . '</sheetData></worksheet>');
        $z->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . implode('', array_map(fn($s) => '<si><t>' . htmlspecialchars($s) . '</t></si>', $shared)) . '</sst>');
        $z->close();
        $data = (string) file_get_contents($tmp);
        unlink($tmp);
        return $data;
    }

    public function testDelimiterDetection(): void
    {
        $this->assertSame(';', acct_detect_delimiter("a;b;c\n1;2;3\n"));
        $this->assertSame(',', acct_detect_delimiter("a,b,c\n1,2,3\n"));
        $this->assertSame("\t", acct_detect_delimiter("a\tb\n1\t2,5\n"));
        $this->assertSame(';', acct_detect_delimiter("Jednotka;Saldo\nA/1;\"1,5\"\n"));
    }

    public function testCsvInWindows1250WithPreambleAndQuotedNewline(): void
    {
        $csv = iconv('UTF-8', 'CP1250', "Přehled salda k 31. 10.\r\n\r\nČíslo bytu;Vlastník;Záloha;Saldo\r\nA/1;\"Novák\r\nPetr\";2 700,00;-1 200,50\r\n");
        $t = acct_read_table($csv, 'saldo.csv');
        $this->assertSame('', $t['error']);
        $this->assertSame('Windows-1250', $t['encoding']);
        $this->assertSame(';', $t['delimiter']);
        $this->assertSame(['Číslo bytu', 'Vlastník', 'Záloha', 'Saldo'], $t['headers']);
        $this->assertCount(1, $t['rows']);
        $this->assertSame("Novák\r\nPetr", $t['rows'][0][1]);
    }

    public function testCsvUtf8BomAndShortRowsArePadded(): void
    {
        $t = acct_read_table("\xEF\xBB\xBFJednotka,Podíl,Vlastník\nA/1,812/10000\n", 'x.csv');
        $this->assertSame('UTF-8', $t['encoding']);
        $this->assertSame(['A/1', '812/10000', ''], $t['rows'][0]);
    }

    public function testUnsupportedAndEmptyFiles(): void
    {
        $this->assertNotSame('', acct_read_table("\xD0\xCF\x11\xE0xxxx", 'stary.xls')['error']);
        $this->assertNotSame('', acct_read_table("\n\n", 'prazdny.csv')['error']);
        $this->assertNotSame('', acct_read_table("PK\x03\x04nesmysl", 'x.xlsx')['error']);
    }

    public function testXlsxFirstSheetSharedAndInlineStrings(): void
    {
        $t = acct_read_table($this->xlsx([['Jednotka', 'Vlastník', 'Předpis', 'Saldo'], ['A/1', 'Nováková Jana', 2700, -1200.5], ['B/2', 'Král', 1800, 0]]), 'export.xlsx');
        $this->assertSame('', $t['error']);
        $this->assertSame('XLSX', $t['encoding']);
        $this->assertSame(['Jednotka', 'Vlastník', 'Předpis', 'Saldo'], $t['headers']);
        $this->assertSame(['A/1', 'Nováková Jana', '2700', '-1200.5'], $t['rows'][0]);
        $this->assertSame(-1200.5, acct_num($t['rows'][0][3]));
    }

    public function testGuessMappingCzechAndEnglish(): void
    {
        $m = acct_guess_mapping(['Č. bytu', 'Jméno vlastníka', 'Spoluvlastnický podíl', 'Měsíční záloha', 'Stav účtu', 'Var. symbol', 'Pozn.'], ['unit', 'owner', 'share', 'prescribed', 'balance', 'vs', 'note']);
        $this->assertSame(['unit' => 0, 'owner' => 1, 'share' => 2, 'prescribed' => 3, 'balance' => 4, 'vs' => 5, 'note' => 6], $m);
        $e = acct_guess_mapping(['Unit', 'Owner name', 'Balance', 'Advance', 'Variable symbol'], ['unit', 'owner', 'balance', 'prescribed', 'vs']);
        $this->assertSame(['unit' => 0, 'owner' => 1, 'balance' => 2, 'prescribed' => 3, 'vs' => 4], $e);
        // Každý sloupec nejvýš jednomu poli; neznámé zůstane null.
        $d = acct_guess_mapping(['Stav', 'Stav účtu'], ['balance', 'prescribed']);
        $this->assertSame(1, $d['balance'], 'přesnější název má přednost');
        $this->assertNull($d['prescribed']);
    }

    public function testPresetHasPriority(): void
    {
        $h = ['Byt', 'Jméno', 'Zůstatek', 'Saldo'];
        $this->assertSame(3, acct_guess_mapping($h, ['unit', 'owner', 'balance'])['balance']);
        $this->assertSame(2, acct_guess_mapping($h, ['unit', 'owner', 'balance'], 'mecom')['balance']);
        foreach (ACCT_PRESETS as [$label, $map]) {
            $this->assertNotSame('', $label);
            $this->assertArrayHasKey('unit', $map);
        }
    }

    public function testMappingInputRestoreAndExport(): void
    {
        $headers = ['Jednotka', 'Saldo', 'VS'];
        $fields = acct_fields('accounts');
        $m = acct_mapping_from_input(['unit' => '0', 'balance' => '1', 'vs' => '1', 'prescribed' => '9', 'note' => 'x'], $headers, $fields);
        $this->assertSame(0, $m['unit']);
        $this->assertSame(1, $m['vs']);
        $this->assertNull($m['balance'], 'sloupec už je použitý');
        $m = acct_mapping_from_input(['unit' => '0', 'balance' => '1'], $headers, $fields);
        $this->assertNull($m['prescribed']);
        $json = acct_mapping_export($m, $headers);
        $this->assertSame(['unit' => 'Jednotka', 'balance' => 'Saldo'], json_decode($json, true));
        $re = acct_mapping_restore($json, ['VS', 'saldo', 'jednotka'], $fields);
        $this->assertSame(2, $re['unit']);
        $this->assertSame(1, $re['balance']);
        $this->assertNull(acct_mapping_restore($json, ['Jednotka', 'Stav'], $fields), 'chybí sloupec Saldo');
        $this->assertNull(acct_mapping_restore('', $headers, $fields));
        $this->assertArrayHasKey('house', acct_fields('units', true));
    }

    public function testRememberedMappingPerHouse(): void
    {
        [, $pdo] = $this->house();
        acct_setting_save($pdo, 'acct_map_units', '{"unit":"Byt"}');
        acct_setting_save($pdo, 'acct_map_units', '{"unit":"Jednotka"}');
        $this->assertSame('{"unit":"Jednotka"}', acct_settings($pdo)['acct_map_units']);
    }

    public function testPreviewAccountsMatchingAndValidation(): void
    {
        [$house, $pdo] = $this->house();
        $vsB2 = (string) $pdo->query("SELECT a.vs FROM unit_accounts a JOIN units u ON u.id = a.unit_id WHERE u.entrance = 'B' AND u.number = '2'")->fetchColumn();
        $table = ['headers' => ['Jednotka', 'Vchod', 'VS', 'Předpis', 'Saldo', 'Pozn'], 'rows' => [
            ['7A/1', '', '', '2 700', '-100', 'ok'],      // označení s předponou
            ['3', 'A', '', '2500', '', ''],              // vchod + číslo
            ['', '', $vsB2, '', '50', ''],               // podle VS
            ['Z/9', '', '', '1', '1', ''],               // neznámá jednotka
            ['A/4', '', '', 'hodně', '', ''],            // částka
            ['A/5', '', '', '-10', '', ''],              // záporný předpis
            ['A/1', '', '', '1', '', ''],                // podruhé tatáž jednotka
            ['A/6', '', '12345678901', '', '', ''],      // dlouhý VS
            ['Celkem', '', '', '9999', '', ''],          // součet – přeskočí se
            ['', '', '', '', '', ''],
        ]];
        $map = acct_guess_mapping($table['headers'], array_keys(acct_fields('accounts')));
        $this->assertSame(['unit' => 0, 'entrance' => 1, 'vs' => 2, 'prescribed' => 3, 'balance' => 4, 'note' => 5], $map);
        $pv = acct_preview($pdo, $house, 'accounts', $table, $map);
        $this->assertSame([], $pv['missing']);
        $rows = $pv['rows'];
        $this->assertCount(8, $rows);
        $this->assertSame(['7A/1', 'update', 2700.0, -100.0], [$rows[0]['label'], $rows[0]['action'], $rows[0]['values']['prescribed'], $rows[0]['values']['balance']]);
        $this->assertSame('7A/3', $rows[1]['label']);
        $this->assertSame('7B/2', $rows[2]['label']);
        $this->assertStringContainsString('v domě nemáme', $rows[3]['errors'][0]);
        $this->assertStringContainsString('není částka', $rows[4]['errors'][0]);
        $this->assertStringContainsString('záporný', $rows[5]['errors'][0]);
        $this->assertStringContainsString('víckrát', $rows[6]['errors'][0]);
        $this->assertStringContainsString('číslic', $rows[7]['errors'][0]);
        $this->assertSame(3, $pv['ok']);
        $this->assertSame(5, $pv['bad']);
        $this->assertSame(2, $rows[0]['line']);
    }

    public function testPreviewNeedsUnitColumn(): void
    {
        [$house, $pdo] = $this->house();
        $t = ['headers' => ['Saldo'], 'rows' => [['1']]];
        $this->assertNotEmpty(acct_preview($pdo, $house, 'accounts', $t, ['unit' => null, 'vs' => null, 'balance' => 0])['missing']);
        $this->assertNotEmpty(acct_preview($pdo, $house, 'units', $t, ['unit' => null])['missing']);
        $t2 = ['headers' => ['Jednotka', 'Vlastník'], 'rows' => [['A/1', 'X']]];
        $this->assertNotEmpty(acct_preview($pdo, $house, 'accounts', $t2, ['unit' => 0, 'vs' => null, 'prescribed' => null, 'balance' => null])['missing']);
    }

    public function testApplyAccountsKeepsUnmappedValues(): void
    {
        [$house, $pdo] = $this->house();
        $before = $pdo->query("SELECT a.* FROM unit_accounts a JOIN units u ON u.id = a.unit_id WHERE u.entrance = 'A' AND u.number = '1'")->fetch();
        $pdo->exec("UPDATE unit_accounts SET reminded_at = '2026-10-01 10:00:00' WHERE unit_id = " . (int) $before['unit_id']);
        $table = ['headers' => ['Jednotka', 'Saldo'], 'rows' => [['A/1', '-321,5'], ['Z/1', '5']]];
        $pv = acct_preview($pdo, $house, 'accounts', $table, ['unit' => 0, 'balance' => 1]);
        $res = acct_apply($pdo, 'accounts', $pv, '2026-11-15');
        $this->assertSame(['updated' => 1, 'created' => 0], $res);
        $after = $pdo->query('SELECT * FROM unit_accounts WHERE unit_id = ' . (int) $before['unit_id'])->fetch();
        $this->assertSame(-321.5, (float) $after['balance']);
        $this->assertSame((float) $before['prescribed'], (float) $after['prescribed']);
        $this->assertSame($before['vs'], $after['vs']);
        $this->assertSame('2026-11-15', $after['as_of']);
        $this->assertSame('2026-10-01 10:00:00', $after['reminded_at']);
    }

    public function testApplyAccountsForUnitWithoutAccount(): void
    {
        [$house, $pdo] = $this->house();
        $id = (int) $pdo->query("SELECT id FROM units WHERE entrance = 'B' AND number = '6'")->fetchColumn();
        $pdo->exec("DELETE FROM unit_accounts WHERE unit_id = $id");
        $pv = acct_preview($pdo, $house, 'accounts', ['headers' => ['J', 'Předpis', 'VS'], 'rows' => [['B/6', '1999', '000777']]], ['unit' => 0, 'prescribed' => 1, 'vs' => 2]);
        acct_apply($pdo, 'accounts', $pv, '2026-11-01');
        $row = $pdo->query("SELECT * FROM unit_accounts WHERE unit_id = $id")->fetch();
        $this->assertSame(1999.0, (float) $row['prescribed']);
        $this->assertSame('000777', $row['vs']);
        $this->assertSame(0.0, (float) $row['balance']);
        // Nový import pozná jednotku i podle VS bez úvodních nul.
        $idx = acct_unit_index($pdo, $house['prefix']);
        $this->assertSame($id, acct_resolve_unit($idx, $house['prefix'], '', '', '777', $house['entrances'])[0]);
    }

    public function testUnitsImportCreatesUpdatesAndValidates(): void
    {
        [$house, $pdo] = $this->house();
        $table = acct_read_table("Jednotka;Vchod;Podíl;Vlastník;Adresa pro doručování;Patro\n"
            . "A/1;;8,5 %;Nový Vlastník;Lipová 1, 602 00 Brno;2\n"
            . "7B/07;;1010/10000;;;\n"
            . "8;B;500/10000;Nováček;;4\n"
            . "C/1;;100/10000;Nikdo;;\n"
            . "A/2;;velký;X;;\n", 'jednotky.csv');
        $map = acct_guess_mapping($table['headers'], array_keys(acct_fields('units')));
        $this->assertSame(['unit' => 0, 'entrance' => 1, 'share' => 2, 'owner' => 3, 'contact' => 4, 'floor' => 5, 'area' => null], $map);
        $pv = acct_preview($pdo, $house, 'units', $table, $map);
        [$a1, $b7, $b8, $c1, $a2] = $pv['rows'];
        $this->assertSame('update', $a1['action']);
        $this->assertSame('8.5%', $a1['values']['share']);
        $this->assertSame('create', $b7['action'], 'B/07 v domě není (jsou B/1–B/6) – založí se');
        $this->assertSame(['create', 'B', '8'], [$b8['action'], $b8['entrance'], $b8['number']]);
        $this->assertStringContainsString('Vchod „C“', $c1['errors'][0]);
        $this->assertStringContainsString('Podíl', $a2['errors'][0]);
        $this->assertSame(2, $pv['create']);
        $res = acct_apply($pdo, 'units', $pv, date('Y-m-d'));
        $this->assertSame(['updated' => 1, 'created' => 2], $res);
        $u = $pdo->query("SELECT * FROM units WHERE entrance = 'A' AND number = '1'")->fetch();
        $this->assertSame(['8.5%', 'Nový Vlastník', 'Lipová 1, 602 00 Brno', '2'], [$u['share'], $u['owner_note'], $u['owner_contact'], $u['floor']]);
        $n = $pdo->query("SELECT * FROM units WHERE entrance = 'B' AND number = '8'")->fetch();
        $this->assertSame(['500/10000', 'Nováček', '4'], [$n['share'], $n['owner_note'], $n['floor']]);
        $this->assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM units WHERE entrance = 'B' AND number = '7'")->fetchColumn(), 'úvodní nula se nepřebírá');
        $a2row = $pdo->query("SELECT owner_note FROM units WHERE entrance = 'A' AND number = '2'")->fetchColumn();
        $this->assertNotSame('X', $a2row, 'řádek s chybou se neuloží');
    }

    public function testUnitLookupWithLeadingZerosAndSingleEntrance(): void
    {
        [$house, $pdo] = $this->house();
        $idx = acct_unit_index($pdo, $house['prefix']);
        $a3 = (int) $pdo->query("SELECT id FROM units WHERE entrance = 'A' AND number = '3'")->fetchColumn();
        $this->assertSame($a3, acct_resolve_unit($idx, '7', 'A/03', '', '', ['A', 'B'])[0]);
        $this->assertSame($a3, acct_resolve_unit($idx, '7', '7 A / 3', '', '', ['A', 'B'])[0]);
        $this->assertSame(0, acct_resolve_unit($idx, '7', '3', '', '', ['A', 'B'])[0], 'číslo 3 je ve dvou vchodech');
        $this->assertSame([0, 'A', '9'], acct_resolve_unit($idx, '7', '9', '', '', ['A']));
    }
}
