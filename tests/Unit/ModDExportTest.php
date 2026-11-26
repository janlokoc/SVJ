<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Modul D (app/ext/acct_lib.php) – export do POHODY, Money S3, ABRA Flexi a CSV. */
final class ModDExportTest extends TestCase
{
    /** Samostatný dům s ukázkovými daty (12 jednotek, předpisy a VS), aby testy neměnily dům „dum“. */
    private function house(array $settings = []): array
    {
        $slug = 'md-' . bin2hex(random_bytes(4));
        tenant_create(['slug' => $slug, 'name' => 'SVJ Exportní 5', 'title' => 'Exportní 5', 'unit_prefix' => '5', 'entrances' => 'A,B', 'seed' => 'demo']);
        tenant_set($slug, 'ico', '12345678');
        foreach ($settings as $k => $v) {
            tenant_set($slug, $k, $v);
        }
        $pdo = tenant_pdo($slug);
        $pdo->exec('CREATE TABLE IF NOT EXISTS bank_tx (id INTEGER PRIMARY KEY AUTOINCREMENT, tx_id TEXT NOT NULL DEFAULT \'\', tx_date TEXT NOT NULL DEFAULT \'\', amount REAL NOT NULL DEFAULT 0, vs TEXT NOT NULL DEFAULT \'\','
            . ' counter_account TEXT NOT NULL DEFAULT \'\', counter_name TEXT NOT NULL DEFAULT \'\', message TEXT NOT NULL DEFAULT \'\', unit_id INTEGER NOT NULL DEFAULT 0, source TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL DEFAULT \'\')');
        return [tenant_row($slug), $pdo];
    }

    private function params(array $in = []): array
    {
        return acct_export_params($in + ['od' => '2026-12', 'mesicu' => '2', 'banka_od' => '2026-11-01', 'banka_do' => '2026-11-30']);
    }

    private function xml(string $body): \DOMXPath
    {
        $d = new \DOMDocument();
        $this->assertTrue($d->loadXML($body), 'XML musí být dobře utvořené');
        return new \DOMXPath($d);
    }

    public function testNumbersFromAccountingExports(): void
    {
        $this->assertSame(-1200.5, acct_num('-1 200,50 Kč'));
        $this->assertSame(1200.5, acct_num('1.200,50'));
        $this->assertSame(1200.5, acct_num('1,200.50'));
        $this->assertSame(4.5, acct_num('4.5'));
        $this->assertSame(1200000.0, acct_num('1.200.000'));
        $this->assertSame(-300.0, acct_num('(300)'));
        $this->assertSame(-300.0, acct_num('300-'));
        $this->assertSame(-75.0, acct_num("\u{2212}75"));
        $this->assertSame(2500.0, acct_num("2\u{a0}500 CZK"));
        $this->assertNull(acct_num(''));
        $this->assertNull(acct_num('abc'));
        $this->assertNull(acct_num('12/2026'));
    }

    public function testSharesAreNormalised(): void
    {
        $this->assertSame('812/10000', acct_share('812 / 10000'));
        $this->assertSame('8.12%', acct_share('8,12 %'));
        $this->assertSame('8.12%', acct_share('0,0812'));
        $this->assertNull(acct_share('0/100'));
        $this->assertNull(acct_share('velký'));
        $this->assertNull(acct_share('12'));
        $this->assertGreaterThan(0, share_value((string) acct_share('0,0812')));
    }

    public function testNormalisedNames(): void
    {
        $this->assertSame('cislo bytu', acct_norm(' Číslo  bytu: '));
        $this->assertSame('stav uctu k 31 12', acct_norm('Stav účtu k 31.12.'));
    }

    public function testCsvIsUtf8BomSemicolonAndSafeFromFormulas(): void
    {
        $csv = acct_csv(['A', 'B'], [['=SUM(A1)', '-1200,00'], ['-x', "a;b\"c"], ['@cmd', '+1']]);
        $this->assertStringStartsWith("\xEF\xBB\xBFA;B\r\n", $csv);
        $this->assertStringContainsString("'=SUM(A1);-1200,00\r\n", $csv);
        $this->assertStringContainsString("'-x;\"a;b\"\"c\"\r\n", $csv);
        $this->assertStringContainsString("'@cmd;'+1\r\n", $csv);
    }

    public function testExportParamsDefaultsAndValidation(): void
    {
        $p = acct_export_params(['format' => 'nic', 'od' => '2026-13', 'mesicu' => '40', 'banka_od' => '2026-11-30', 'banka_do' => '2026-11-01', 'casti' => ['bank', 'zlo']]);
        $this->assertSame('pohoda', $p['format']);
        $this->assertSame(date('Y-m'), $p['from']);
        $this->assertSame(12, $p['months']);
        $this->assertSame(['2026-11-01', '2026-11-30'], [$p['bank_from'], $p['bank_to']]);
        $this->assertSame(['bank'], $p['parts']);
        $this->assertSame(array_keys(ACCT_PARTS), acct_export_params(['casti' => ['zlo']])['parts']);
        $this->assertSame(1, acct_export_params(['mesicu' => '0'])['months']);
    }

    public function testPrescriptionsPerUnitAndMonth(): void
    {
        $u = ['id' => 1, 'label' => '5A/1', 'prescribed' => 2500.0, 'vs' => '101', 'owner' => 'X', 'contact' => '', 'emails' => [], 'phones' => [], 'share' => ''];
        $zero = ['prescribed' => 0.0, 'label' => '5A/2'] + $u;
        $rows = acct_prescriptions([$u, $zero], '2026-12', 2, 15);
        $this->assertCount(2, $rows);
        $this->assertSame(['2026-12', '2027-01'], array_column($rows, 'period'));
        $this->assertSame('2027-01-01', $rows[1]['date']);
        $this->assertSame('2027-01-15', $rows[1]['due']);
        $this->assertStringContainsString('1/2027', $rows[1]['text']);
    }

    public function testPostalAddressSplit(): void
    {
        $this->assertSame(['Zahradní 12', '60200', 'Brno'], acct_split_address('Zahradní 12, 602 00 Brno'));
        $this->assertSame(['Lipová 3 Praha', '', ''], acct_split_address('Lipová 3 Praha'));
        $this->assertSame(['', '', ''], acct_split_address('jana@example.cz'));
    }

    public function testPohodaDataPackWithReceivablesAddressbookAndBank(): void
    {
        [$t, $pdo] = $this->house(['acct_bank_code' => 'KB', 'acct_due_day' => '15']);
        $pdo->exec("UPDATE units SET owner_note = 'Novák & syn <s.r.o.> – Řehoř' WHERE entrance = 'A' AND number = '1'");
        $pdo->exec("INSERT INTO bank_tx (tx_id, tx_date, amount, vs, counter_account, counter_name, message, unit_id) VALUES ('a', '2026-11-10', 2600, '10112', '19-123/0800', 'Jana', 'Záloha', 1), ('b', '2026-11-12', -50, '', '', '', '', 0), ('c', '2026-10-01', 99, '', '', '', '', 0)");
        $house = acct_house_tenant($t);
        $files = acct_export_files($pdo, $house, $this->params(['format' => 'pohoda']));
        $this->assertSame([acct_filename_base($house) . '-pohoda.xml'], array_keys($files));
        $body = reset($files);
        $this->assertStringStartsWith('<?xml version="1.0" encoding="Windows-1250"?>', $body);
        $x = $this->xml($body);
        $x->registerNamespace('dat', 'http://www.stormware.cz/schema/version_2/data.xsd');
        $x->registerNamespace('inv', 'http://www.stormware.cz/schema/version_2/invoice.xsd');
        $x->registerNamespace('typ', 'http://www.stormware.cz/schema/version_2/type.xsd');
        $x->registerNamespace('adb', 'http://www.stormware.cz/schema/version_2/addressbook.xsd');
        $x->registerNamespace('bnk', 'http://www.stormware.cz/schema/version_2/bank.xsd');
        $this->assertSame('12345678', $x->evaluate('string(/dat:dataPack/@ico)'));
        $this->assertSame('2.0', $x->evaluate('string(/dat:dataPack/@version)'));
        $units = (int) $pdo->query('SELECT COUNT(*) FROM units')->fetchColumn();
        $withPrescription = (int) $pdo->query('SELECT COUNT(*) FROM unit_accounts WHERE prescribed > 0')->fetchColumn();
        $this->assertSame($units, (int) $x->evaluate('count(//adb:addressbook)'));
        $this->assertSame($withPrescription * 2, (int) $x->evaluate("count(//inv:invoice[inv:invoiceHeader/inv:invoiceType = 'receivable'])"));
        $this->assertSame(0, (int) $x->evaluate("count(//inv:invoiceType[. != 'receivable'])"));
        // První předpis: VS a částka jednotky, splatnost z nastavení, nepodléhá DPH.
        $acc = $pdo->query("SELECT a.* FROM unit_accounts a JOIN units u ON u.id = a.unit_id WHERE u.entrance = 'A' AND u.number = '1'")->fetch();
        $inv = $x->query("//inv:invoice[inv:invoiceHeader/inv:symVar = '{$acc['vs']}']");
        $this->assertSame(2, $inv->length);
        $this->assertSame(number_format((float) $acc['prescribed'], 2, '.', ''), $x->evaluate('string(inv:invoiceSummary/inv:homeCurrency/typ:priceNone)', $inv->item(0)));
        $this->assertSame('2026-12-15', $x->evaluate('string(inv:invoiceHeader/inv:dateDue)', $inv->item(0)));
        $this->assertSame('nonSubsume', $x->evaluate('string(inv:invoiceHeader/inv:classificationVAT/typ:classificationVATType)', $inv->item(0)));
        $this->assertSame('Novák & syn <s.r.o.> – Řehoř', $x->evaluate('string(inv:invoiceHeader/inv:partnerIdentity/typ:address/typ:company)', $inv->item(0)));
        // Banka: jen pohyby v období, příjem a výdej, účet podle zkratky.
        $this->assertSame(2, (int) $x->evaluate('count(//bnk:bank)'));
        $this->assertSame('receipt', $x->evaluate("string(//bnk:bank[bnk:bankHeader/bnk:symVar = '10112']/bnk:bankHeader/bnk:bankType)"));
        $this->assertSame('expense', $x->evaluate("string(//bnk:bank[bnk:bankSummary/bnk:homeCurrency/typ:priceNone = '50.00']/bnk:bankHeader/bnk:bankType)"));
        $this->assertSame('KB', $x->evaluate('string(//bnk:bank/bnk:bankHeader/bnk:account/typ:ids)'));
        $this->assertSame('0800', $x->evaluate('string(//bnk:paymentAccount/typ:bankCode)'));
        // Jedinečné identifikátory položek balíku.
        $ids = array_map(fn($n) => $n->value, iterator_to_array($x->query('//dat:dataPackItem/@id')));
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function testPohodaOnlySelectedParts(): void
    {
        [$t, $pdo] = $this->house();
        $body = acct_export_files($pdo, acct_house_tenant($t), $this->params(['format' => 'pohoda', 'casti' => ['addressbook']]));
        $x = $this->xml(reset($body));
        $x->registerNamespace('inv', 'http://www.stormware.cz/schema/version_2/invoice.xsd');
        $x->registerNamespace('adb', 'http://www.stormware.cz/schema/version_2/addressbook.xsd');
        $this->assertSame(0, (int) $x->evaluate('count(//inv:invoice)'));
        $this->assertGreaterThan(0, (int) $x->evaluate('count(//adb:addressbook)'));
    }

    public function testMoneyS3Document(): void
    {
        [$t, $pdo] = $this->house(['acct_bank_code' => 'FIO']);
        $pdo->exec("INSERT INTO bank_tx (tx_id, tx_date, amount, vs, counter_account, counter_name, message, unit_id) VALUES ('a', '2026-11-10', 1500.25, '10112', '2000145399/2010', 'Petr', '', 1)");
        $files = acct_export_files($pdo, acct_house_tenant($t), $this->params(['format' => 'money', 'mesicu' => '1']));
        $body = reset($files);
        $this->assertStringContainsString('encoding="UTF-8"', $body);
        $x = $this->xml($body);
        $this->assertSame('12345678', $x->evaluate('string(/MoneyData/@ICAgendy)'));
        $units = (int) $pdo->query('SELECT COUNT(*) FROM units')->fetchColumn();
        $this->assertSame($units, (int) $x->evaluate('count(/MoneyData/SeznamFirem/Firma)'));
        $withPrescription = (int) $pdo->query('SELECT COUNT(*) FROM unit_accounts WHERE prescribed > 0')->fetchColumn();
        $this->assertSame($withPrescription, (int) $x->evaluate('count(/MoneyData/SeznamPohledavek/Pohledavka)'));
        foreach ($x->query('/MoneyData/SeznamPohledavek/Pohledavka') as $p) {
            $this->assertSame($x->evaluate('string(SouhrnDPH/Zaklad0)', $p), $x->evaluate('string(Celkem)', $p));
            $this->assertMatchesRegularExpression('/^\d{1,10}$/', $x->evaluate('string(VarSym)', $p));
            $this->assertNotSame('', $x->evaluate('string(Adresa/ObchNazev)', $p));
        }
        $b = $x->query('/MoneyData/SeznamBankDokl/BankDokl')->item(0);
        $this->assertSame('0', $x->evaluate('string(Vydej)', $b));
        $this->assertSame('1500.25', $x->evaluate('string(Celkem)', $b));
        $this->assertSame('2010', $x->evaluate('string(AdKod)', $b));
        $this->assertSame('FIO', $x->evaluate('string(Ucet/Zkrat)', $b));
    }

    public function testAbraFlexiDocument(): void
    {
        [$t, $pdo] = $this->house(['acct_flexi_receivable' => 'ZALOHY']);
        $files = acct_export_files($pdo, acct_house_tenant($t), $this->params(['format' => 'flexi', 'mesicu' => '1']));
        $x = $this->xml(reset($files));
        $this->assertSame('1.0', $x->evaluate('string(/winstrom/@version)'));
        $firms = array_map(fn($n) => $n->textContent, iterator_to_array($x->query('/winstrom/adresar/id')));
        $this->assertNotEmpty($firms);
        $this->assertSame(count($firms), count(array_unique($firms)));
        foreach ($x->query('/winstrom/pohledavka') as $p) {
            $this->assertSame('code:ZALOHY', $x->evaluate('string(typDokl)', $p));
            $this->assertContains($x->evaluate('string(firma)', $p), $firms, 'Pohledávka odkazuje na vlastníka z adresáře');
            $this->assertStringStartsWith('ext:SVJ-', $x->evaluate('string(id)', $p));
        }
    }

    public function testCsvExportFiles(): void
    {
        [$t, $pdo] = $this->house();
        $files = acct_export_files($pdo, acct_house_tenant($t), $this->params(['format' => 'csv']));
        $names = array_keys($files);
        foreach (['-vlastnici.csv', '-predpisy.csv', '-salda.csv', '-banka.csv'] as $suffix) {
            $this->assertNotEmpty(array_filter($names, fn($n) => str_ends_with($n, $suffix)), $suffix);
        }
        $owners = $files[array_values(array_filter($names, fn($n) => str_ends_with($n, '-vlastnici.csv')))[0]];
        $lines = preg_split('/\r\n/', trim(substr($owners, 3)));
        $this->assertSame(1 + (int) $pdo->query('SELECT COUNT(*) FROM units')->fetchColumn(), count($lines));
        $this->assertStringContainsString('5A/1;A;1;', $lines[1]);
        $only = acct_export_files($pdo, acct_house_tenant($t), $this->params(['format' => 'csv', 'casti' => ['addressbook']]));
        $this->assertCount(2, $only, 'vlastníci + salda');
    }

    public function testZipRoundTrip(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zt');
        file_put_contents($tmp, acct_zip(['a/b.xml' => '<x/>', 'c.csv' => 'y']));
        $z = new \ZipArchive();
        $this->assertTrue($z->open($tmp));
        $this->assertSame('<x/>', $z->getFromName('a/b.xml'));
        $z->close();
        file_put_contents($tmp, acct_zip([]));
        $this->assertTrue($z->open($tmp));
        $this->assertNotFalse($z->getFromName('README.txt'));
        $z->close();
        unlink($tmp);
    }

    public function testCurrentHouseAndOptions(): void
    {
        $h = acct_house_current();
        $this->assertSame('dum', $h['slug']);
        $this->assertSame(unit_prefix(), $h['prefix']);
        $this->assertSame('20', $h['opts']['acct_due_day']);
        $this->assertSame('28', acct_options(['acct_due_day' => '31'])['acct_due_day']);
        $this->assertSame('KB', acct_options(['acct_bank_code' => ' KB '])['acct_bank_code']);
        $this->assertSame('OST. POHLEDÁVKY', acct_options([])['acct_flexi_receivable']);
    }

    public function testAdminMenuItem(): void
    {
        $this->assertArrayHasKey('/admin/ucetnictvi', $GLOBALS['EXT_ADMIN_NAV']);
        $this->assertSame('Dům a lidé', $GLOBALS['EXT_ADMIN_NAV']['/admin/ucetnictvi'][1]);
        $this->assertArrayHasKey('/ucetnictvi', $GLOBALS['EXT_PARTNER_NAV']);
    }
}
