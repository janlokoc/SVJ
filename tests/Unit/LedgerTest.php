<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Konto bytu z účetnictví POHODA (app/ext/ledger_lib.php). Vzorek podle dokumentované struktury XML exportu (Windows-1250). */
final class LedgerTest extends TestCase
{
    private int $unit;
    private ?array $savedAccount = null;

    protected function setUp(): void
    {
        parent::setUp();
        ledger_ready();
        $this->unit = (int) val("SELECT uu.unit_id FROM user_units uu JOIN users u ON u.id = uu.user_id WHERE u.kind = 'owner' AND u.role <> 'admin' ORDER BY uu.unit_id LIMIT 1");
        $this->savedAccount = one('SELECT * FROM unit_accounts WHERE unit_id = ?', [$this->unit]);
        q('DELETE FROM unit_accounts WHERE unit_id = ?', [$this->unit]);
        q('INSERT INTO unit_accounts (unit_id, prescribed, balance, vs, as_of, note) VALUES (?,?,?,?,?,?)', [$this->unit, 3000, 0, '004812', '2026-01-01', '']);
        q('DELETE FROM ledger_docs');
        q('DELETE FROM ledger_imports');
    }

    protected function tearDown(): void
    {
        q('DELETE FROM unit_accounts WHERE unit_id = ?', [$this->unit]);
        if ($this->savedAccount) {
            q('INSERT INTO unit_accounts (unit_id, prescribed, balance, vs, as_of, note) VALUES (?,?,?,?,?,?)', [$this->unit, $this->savedAccount['prescribed'], $this->savedAccount['balance'], $this->savedAccount['vs'], $this->savedAccount['as_of'], $this->savedAccount['note']]);
        }
        q('DELETE FROM ledger_docs');
        q('DELETE FROM ledger_imports');
        parent::tearDown();
    }

    private static function sample(): string
    {
        $m0 = date('Y-m');
        $m1 = date('Y-m', strtotime('first day of last month'));
        $xml = str_replace(['{VS}', '{M0}', '{M1}'], ['4812', $m0, $m1], (string) file_get_contents(dirname(__DIR__) . '/fixtures/pohoda-pohledavky.xml.tpl'));
        return (string) iconv('UTF-8', 'Windows-1250', $xml);
    }

    public function testParsePohodaExport(): void
    {
        $p = ledger_pohoda_parse(self::sample());
        $this->assertIsArray($p);
        $this->assertCount(3, $p['receivables']);
        $this->assertCount(1, $p['payments']);
        $this->assertSame(2, $p['skipped'], 'vydaná faktura a výdaj z banky se přeskočí');
        $r = $p['receivables'][0];
        $this->assertSame(['501', 'PH26100', '4812', 3450.0, 3450.0], [$r['ext_id'], $r['number'], $r['vs'], $r['amount'], $r['paid']]);
        $this->assertSame('Předpis záloh ' . date('Y-m', strtotime('first day of last month')), $r['text'], 'Windows-1250 → UTF-8');
        $this->assertSame([['Dlouhodobá záloha na opravy (fond oprav)', 1500.0], ['Teplo', 1450.0], ['Studená voda', 500.0]], $r['items']);
        $this->assertSame(0.0, $p['receivables'][2]['paid'], 'bez likvidace = neuhrazeno');
        $this->assertSame(['27', '4812', 1000.0, date('Y-m') . '-09'], [$p['payments'][0]['ext_id'], $p['payments'][0]['vs'], $p['payments'][0]['amount'], $p['payments'][0]['doc_date']]);
    }

    public function testParseRejectsBadInputAndIgnoresEntities(): void
    {
        $this->assertIsString(ledger_pohoda_parse(''));
        $this->assertIsString(ledger_pohoda_parse('<nejde'));
        $this->assertStringContainsString('jiné doklady', (string) ledger_pohoda_parse('<?xml version="1.0"?><x xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"><inv:invoiceHeader><inv:invoiceType>issuedInvoice</inv:invoiceType></inv:invoiceHeader></x>'));
        $xxe = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd" xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"><y><inv:invoiceHeader><inv:invoiceType>receivable</inv:invoiceType><inv:text>&e;</inv:text><inv:symVar>1</inv:symVar></inv:invoiceHeader></y></x>';
        $p = ledger_pohoda_parse($xxe);
        $this->assertIsArray($p);
        $this->assertStringNotContainsString('root:', $p['receivables'][0]['text'], 'externí entity se nenačítají');
    }

    public function testPreviewImportAndAccount(): void
    {
        $p = ledger_pohoda_parse(self::sample());
        $pv = ledger_preview($p);
        $this->assertSame([3, 1, 1], [$pv['receivables'], $pv['payments'], $pv['units']]);
        $this->assertSame(['99999'], $pv['unmatched'], 'VS se porovná bez úvodních nul (004812 = 4812)');

        $r = ledger_import($p, 'pohoda', 'export.xml', 1);
        $this->assertSame(['saved' => 4, 'units' => 1, 'unmatched' => 1], $r);
        $acc = ledger_account($this->unit, 12, date('Y-m') . '-20');
        $this->assertSame('PH26101', $acc['prescription']['number'], 'předpis = poslední pohledávka');
        $this->assertCount(3, $acc['prescription']['items']);
        $this->assertSame(2450.0, $acc['open'], 'zbývá 3450 − 1000');
        $this->assertSame(2450.0, $acc['overdue'], 'po splatnosti 15. dne');
        $this->assertCount(1, $acc['payments']);
        $this->assertEquals([3450, -2450], [(float) val('SELECT prescribed FROM unit_accounts WHERE unit_id = ?', [$this->unit]), (float) val('SELECT balance FROM unit_accounts WHERE unit_id = ?', [$this->unit])], 'Platby na jednotku se přepočítají (upomínky, QR)');

        // Opakovaný import přepíše stejné doklady (mezitím uhrazeno) – nezdvojí je.
        $p['receivables'][1]['paid'] = 3450.0;
        ledger_import($p, 'pohoda', 'export2.xml', 1);
        $this->assertSame(4, (int) val('SELECT COUNT(*) FROM ledger_docs'));
        $this->assertSame(0.0, ledger_account($this->unit)['open']);
        $this->assertSame(2, (int) val('SELECT COUNT(*) FROM ledger_imports'));
        $this->assertStringNotContainsString('Předpis', (string) val("SELECT detail FROM audit_log WHERE action = 'ledger_import' ORDER BY id DESC LIMIT 1"));
    }

    public function testResidentSeesOnlyOwnUnitAndViewRenders(): void
    {
        ledger_import(ledger_pohoda_parse(self::sample()), 'pohoda', 'export.xml', 1);
        $acc = ledger_account($this->unit, 12, date('Y-m') . '-20');
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/ext/ledger/_unit.php';
        $html = str_replace("\u{a0}", ' ', (string) ob_get_clean());
        $this->assertStringContainsString('Dlouhodobá záloha na opravy (fond oprav)', $html);
        $this->assertStringContainsString('po splatnosti', $html);
        $this->assertStringContainsString('Přijaté platby', $html);
        $other = (int) val('SELECT id FROM units WHERE id <> ? ORDER BY id LIMIT 1', [$this->unit]);
        $this->assertSame([], ledger_account($other)['receivables'], 'cizí jednotka nemá nic');
        $this->assertStringContainsString("ledger_account((int) \$r['id'])", (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/payments.php'), 'konto jen u jednotek přihlášeného (řádky stránky Platby)');
    }
}
