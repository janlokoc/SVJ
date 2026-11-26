<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Párování plateb s bankou (app/bank_lib.php): čtení výpisů GPC/CSV, ukládání a párování podle VS. */
final class BankTest extends TestCase
{
    /** Řádek GPC/ABO s poli na pevných pozicích (1-based) podle specifikace, jak je čte bank_parse_gpc. */
    private static function gpcLine(array $f): string
    {
        $chars = array_fill(0, 128, ' ');
        $put = function (int $from, string $value) use (&$chars): void {
            foreach (mb_str_split($value) as $i => $ch) {
                $chars[$from - 1 + $i] = $ch;
            }
        };
        $put(1, '075');
        $put(4, str_pad($f['own'] ?? '2900123456', 16, '0', STR_PAD_LEFT));
        $put(20, str_pad($f['counter'] ?? '0', 16, '0', STR_PAD_LEFT));
        $put(36, str_pad($f['txno'] ?? '1', 13, '0', STR_PAD_LEFT));
        $put(49, str_pad((string) $f['hellers'], 12, '0', STR_PAD_LEFT));
        $put(61, $f['code'] ?? '2');
        $put(62, str_pad($f['vs'] ?? '', 10, '0', STR_PAD_LEFT));
        $put(72, '00' . ($f['bank'] ?? '0800') . '0000');   // konstantní symbol: 2 číslice + kód banky + KS
        $put(82, '0000000000');                            // specifický symbol
        $put(92, $f['date'] ?? '101126');                  // DDMMRR
        $put(98, mb_str_pad($f['name'] ?? '', 20));
        return rtrim(implode('', $chars));
    }

    private static function gpcHeader(): string
    {
        return '0740000002900123456SVJ ZAHRADNI 12     01112600000001500000+00000002500000+00000000150000000000000000261126';
    }

    private static function setSetting(string $key, ?string $value): void
    {
        if ($value === null) {
            q('DELETE FROM settings WHERE `key` = ?', [$key]);
        } else {
            q('INSERT INTO settings (`key`, value) VALUES (?,?) ON CONFLICT(`key`) DO UPDATE SET value = excluded.value', [$key, $value]);
        }
        setting('__reset');
    }

    private static function uid(): string
    {
        return bin2hex(random_bytes(5));
    }

    // ---------- bank_utf8 / bank_vs ----------

    public function testUtf8InputIsReturnedUnchanged(): void
    {
        $s = 'Příliš žluťoučký kůň úpěl ďábelské ódy';
        $this->assertSame($s, bank_utf8($s));
        $this->assertSame('', bank_utf8(''));
        $this->assertSame('ASCII only;123', bank_utf8('ASCII only;123'));
    }

    public function testCp1250InputIsConvertedToUtf8(): void
    {
        $s = 'Příliš žluťoučký kůň úpěl ďábelské ódy – Šťastný';
        $cp = iconv('UTF-8', 'CP1250', $s);
        $this->assertFalse(mb_check_encoding($cp, 'UTF-8'), 'fixture must really be non-UTF-8');
        $this->assertSame($s, bank_utf8($cp));
    }

    public function testBankVsKeepsDigitsWithoutLeadingZeros(): void
    {
        $this->assertSame('10112', bank_vs('0000010112'));
        $this->assertSame('10112', bank_vs(' VS: 101-12 '));
        $this->assertSame('', bank_vs('0000000000'));
        $this->assertSame('', bank_vs('abc'));
        $this->assertSame('1000', bank_vs('1000'));
    }

    // ---------- GPC ----------

    public function testParseGpcRealisticStatement(): void
    {
        $data = implode("\r\n", [
            self::gpcHeader(),
            self::gpcLine(['counter' => '0000001234567890', 'txno' => '4711', 'hellers' => '260000', 'code' => '2', 'vs' => '10112', 'bank' => '0100', 'date' => '101126', 'name' => 'Výborová Jana']),
            self::gpcLine(['counter' => '19-2000145399', 'txno' => '4712', 'hellers' => '305050', 'code' => '2', 'vs' => '0000010212', 'bank' => '0800', 'date' => '111126', 'name' => 'NOVAK PETR']),
            // odchozí platba (kód 1) – přeskočit
            self::gpcLine(['counter' => '555', 'txno' => '4713', 'hellers' => '99900', 'code' => '1', 'vs' => '777', 'date' => '121126', 'name' => 'Dodavatel s.r.o.']),
            // storno příchozí platby (kód 5) – záporně
            self::gpcLine(['counter' => '', 'txno' => '4714', 'hellers' => '10000', 'code' => '5', 'vs' => '10312', 'date' => '131126', 'name' => '']),
            // storno odchozí (4) – přeskočit
            self::gpcLine(['counter' => '1', 'txno' => '4715', 'hellers' => '100', 'code' => '4', 'vs' => '1', 'date' => '131126']),
            '075 příliš krátký řádek',
            '',
        ]);
        $tx = bank_parse_gpc($data);
        $this->assertCount(3, $tx);

        $this->assertSame('2026-11-10', $tx[0]['date']);
        $this->assertEquals(2600, $tx[0]['amount']);
        $this->assertSame('10112', $tx[0]['vs']);
        $this->assertSame('1234567890/0100', $tx[0]['account']);
        $this->assertSame('Výborová Jana', $tx[0]['name']);
        $this->assertSame('', $tx[0]['message']);
        $this->assertSame('gpc:0000002900123456:0000000004711:2026-11-10:000000260000', $tx[0]['id']);

        $this->assertEquals(3050.5, $tx[1]['amount']);
        $this->assertSame('10212', $tx[1]['vs']);
        $this->assertSame('2026-11-11', $tx[1]['date']);
        $this->assertSame('NOVAK PETR', $tx[1]['name']);

        $this->assertEquals(-100, $tx[2]['amount']);
        $this->assertSame('10312', $tx[2]['vs']);
        $this->assertSame('', $tx[2]['account'], 'all-zero counter account means no account');
    }

    public function testParseGpcInCp1250(): void
    {
        $line = self::gpcLine(['txno' => '9', 'hellers' => '100', 'vs' => '5', 'date' => '010126', 'name' => 'Šťastná Žofie']);
        $tx = bank_parse_gpc(iconv('UTF-8', 'CP1250', self::gpcHeader() . "\n" . $line));
        $this->assertCount(1, $tx);
        $this->assertSame('Šťastná Žofie', $tx[0]['name']);
        $this->assertSame('2026-01-01', $tx[0]['date']);
        $this->assertEquals(1, $tx[0]['amount']);
    }

    public function testParseGpcBadDateGivesEmptyDate(): void
    {
        $tx = bank_parse_gpc(self::gpcLine(['hellers' => '100', 'date' => 'XXXXXX']));
        $this->assertCount(1, $tx);
        $this->assertSame('', $tx[0]['date']);
    }

    public function testParseGpcIgnoresNonItemLines(): void
    {
        $this->assertSame([], bank_parse_gpc(self::gpcHeader()));
        $this->assertSame([], bank_parse_gpc(''));
        $this->assertSame([], bank_parse_gpc("076 doplňkový řádek\n078 zpráva"));
    }

    // ---------- CSV ----------

    public function testParseCsvFioExportWithPreamble(): void
    {
        $csv = "\xEF\xBB\xBF" . implode("\n", [
            '"accountId";"2900123456"',
            '"bankId";"2010"',
            '"currency";"CZK"',
            '',
            '"ID pohybu";"Datum";"Objem";"Měna";"Protiúčet";"Název protiúčtu";"Kód banky";"Název banky";"KS";"VS";"SS";"Uživatelská identifikace";"Zpráva pro příjemce";"Typ"',
            '"26123456789";"10.11.2026";"2600,00";"CZK";"1234567890";"Výborová Jana";"0100";"KB";"";"10112";"";"";"Zálohy 11/2026";"Bezhotovostní příjem"',
            '"26123456790";"12.11.2026";"-1 500,50";"CZK";"999";"Dodavatel";"0800";"ČS";"";"777";"";"";"Faktura";"Bezhotovostní platba"',
            '"26123456791";"13.11.2026";"1 250,75";"CZK";"";"";"";"";"";"0010212";"";"";"";"Vklad"',
        ]);
        $tx = bank_parse_csv($csv);
        $this->assertCount(2, $tx, 'outgoing (negative) payments are ignored');
        $this->assertSame(['id' => 'csv:26123456789', 'date' => '2026-11-10', 'amount' => 2600.0, 'vs' => '10112', 'account' => '1234567890/0100',
            'name' => 'Výborová Jana', 'message' => 'Zálohy 11/2026'], $tx[0]);
        $this->assertSame(1250.75, $tx[1]['amount']);
        $this->assertSame('10212', $tx[1]['vs']);
        $this->assertSame('', $tx[1]['account']);
        $this->assertSame('2026-11-13', $tx[1]['date']);
    }

    public function testParseCsvCeskaSporitelnaLikeSemicolon(): void
    {
        $csv = implode("\r\n", [
            'Datum zaúčtování;Částka;Měna;Variabilní symbol;Číslo protiúčtu;Název protiúčtu;Poznámka',
            '3. 11. 2026;3 050,00;CZK;10212;19-2000145399/0800;Novák Petr;listopad',
            '4. 11. 2026;0,00;CZK;10312;;;nulová',
        ]);
        $tx = bank_parse_csv($csv);
        $this->assertCount(1, $tx, 'zero amount is not an incoming payment');
        $this->assertSame('2026-11-03', $tx[0]['date']);
        $this->assertSame(3050.0, $tx[0]['amount']);
        $this->assertSame('10212', $tx[0]['vs']);
        $this->assertSame('19-2000145399/0800', $tx[0]['account']);
        $this->assertSame('Novák Petr', $tx[0]['name']);
        $this->assertSame('listopad', $tx[0]['message']);
        $this->assertStringStartsWith('csv:', $tx[0]['id']);
    }

    public function testParseCsvKomercniBankaLikeCommaWithIsoDates(): void
    {
        $csv = implode("\n", [
            '"Datum provedení","Částka v CZK","VS","Protiúčet","Kód banky","Protistrana","Zpráva pro příjemce"',
            '"2026-11-05","2050,00","10312","123","0300","Dvořák Tomáš","zaloha"',
            '"2026-11-06","1000.5","10412","","","",""',
        ]);
        $tx = bank_parse_csv($csv);
        $this->assertCount(2, $tx);
        $this->assertSame('2026-11-05', $tx[0]['date']);
        $this->assertSame(2050.0, $tx[0]['amount']);
        $this->assertSame('123/0300', $tx[0]['account']);
        $this->assertSame('Dvořák Tomáš', $tx[0]['name']);
        $this->assertSame('zaloha', $tx[0]['message']);
        $this->assertSame(1000.5, $tx[1]['amount']);
        $this->assertSame('10412', $tx[1]['vs']);
    }

    public function testParseCsvTabSeparatedEnglishHeader(): void
    {
        $tx = bank_parse_csv("Date\tAmount\tVS\n2026-11-07\t100\t42\n");
        $this->assertCount(1, $tx);
        $this->assertSame(['2026-11-07', 100.0, '42'], [$tx[0]['date'], $tx[0]['amount'], $tx[0]['vs']]);
    }

    public function testParseCsvWithoutIdUsesLineFingerprintAndDistinguishesDuplicates(): void
    {
        $line = '01.11.2026;500;10112';
        $tx = bank_parse_csv("Datum;Částka;VS\n$line\n$line\n02.11.2026;500;10112");
        $this->assertCount(3, $tx);
        $this->assertSame('csv:' . md5($line) . ':1', $tx[0]['id']);
        $this->assertSame('csv:' . md5($line) . ':2', $tx[1]['id']);
        $this->assertNotSame($tx[0]['id'], $tx[2]['id']);
    }

    public function testParseCsvSkipsRowsWithBadDateAndFilesWithoutHeader(): void
    {
        $this->assertSame([], bank_parse_csv("Datum;Částka;VS\nzítra;100;1\n;100;1"));
        $this->assertSame([], bank_parse_csv("Datum;Částka\n01.11.2026;100"), 'VS column is required');
        $this->assertSame([], bank_parse_csv(''));
    }

    public function testParseCsvCp1250(): void
    {
        $csv = iconv('UTF-8', 'CP1250', "Datum;Částka;Variabilní symbol;Název protiúčtu\n01.11.2026;1 000,00;10112;Šťastný Řehoř");
        $tx = bank_parse_csv($csv);
        $this->assertCount(1, $tx);
        $this->assertSame('Šťastný Řehoř', $tx[0]['name']);
        $this->assertSame(1000.0, $tx[0]['amount']);
    }

    // ---------- dispatch ----------

    public function testBankParseDispatchesByFormat(): void
    {
        $gpc = self::gpcHeader() . "\n" . self::gpcLine(['hellers' => '12300', 'vs' => '9', 'date' => '020226']);
        $tx = bank_parse($gpc);
        $this->assertCount(1, $tx);
        $this->assertStringStartsWith('gpc:', $tx[0]['id']);
        $this->assertEquals(123, $tx[0]['amount']);

        $tx = bank_parse("Datum;Objem;VS\n02.02.2026;123;9");
        $this->assertCount(1, $tx);
        $this->assertStringStartsWith('csv:', $tx[0]['id']);
    }

    // ---------- bank_store / bank_paid_since ----------

    public function testStoreDeduplicatesAndMatchesByVs(): void
    {
        $u = self::uid();
        $vsUnit1 = bank_vs((string) val('SELECT vs FROM unit_accounts WHERE unit_id = 1'));
        $this->assertNotSame('', $vsUnit1);
        $txs = [
            ['id' => "t:$u:1", 'date' => '2026-11-10', 'amount' => 100, 'vs' => $vsUnit1, 'account' => '1/0100', 'name' => 'A', 'message' => 'm'],
            ['id' => "t:$u:2", 'date' => '2026-11-11', 'amount' => 200, 'vs' => '99999999', 'account' => '', 'name' => '', 'message' => ''],
            ['id' => "t:$u:3", 'date' => '2026-11-12', 'amount' => 300, 'vs' => '', 'account' => '', 'name' => '', 'message' => ''],
            ['id' => "t:$u:4", 'date' => '', 'amount' => 400, 'vs' => $vsUnit1, 'account' => '', 'name' => '', 'message' => ''],
        ];
        $this->assertSame([3, 1, 2], bank_store($txs, 'test'));
        $this->assertSame([0, 0, 0], bank_store($txs, 'test'), 'already known transactions are skipped');

        $row = one('SELECT * FROM bank_tx WHERE tx_id = ?', ["t:$u:1"]);
        $this->assertSame(1, (int) $row['unit_id']);
        $this->assertSame('test', $row['source']);
        $this->assertSame('1/0100', $row['counter_account']);
        $this->assertEquals(100, $row['amount']);
        $this->assertSame(0, (int) val('SELECT unit_id FROM bank_tx WHERE tx_id = ?', ["t:$u:2"]));
        $this->assertSame(0, (int) val('SELECT COUNT(*) FROM bank_tx WHERE tx_id = ?', ["t:$u:4"]));
    }

    public function testStoreTruncatesLongFields(): void
    {
        $u = self::uid();
        $id = "t:$u:" . str_repeat('x', 200);
        $this->assertSame([1, 0, 1], bank_store([['id' => $id, 'date' => '2026-11-10', 'amount' => 1.005, 'vs' => '', 'account' => '', 'name' => str_repeat('N', 300), 'message' => str_repeat('M', 400)]], 'test'));
        $row = one('SELECT * FROM bank_tx WHERE tx_id = ?', [mb_substr($id, 0, 80)]);
        $this->assertNotNull($row);
        $this->assertSame(140, mb_strlen($row['counter_name']));
        $this->assertSame(255, mb_strlen($row['message']));
        $this->assertSame([0, 0, 0], bank_store([['id' => $id . 'different-tail', 'date' => '2026-11-10', 'amount' => 1, 'vs' => '', 'account' => '', 'name' => '', 'message' => '']], 'test'),
            'id is compared on its first 80 characters');
    }

    public function testPaidSinceSumsOnlyPaymentsAfterAccountDate(): void
    {
        $acc = one('SELECT * FROM unit_accounts WHERE unit_id = 3');
        $vs = bank_vs((string) $acc['vs']);
        $asOf = (string) $acc['as_of'];
        $before = bank_paid_since()[3] ?? 0.0;
        $u = self::uid();
        $after1 = date('Y-m-d', strtotime($asOf . ' +1 day'));
        $after2 = date('Y-m-d', strtotime($asOf . ' +2 day'));
        $earlier = date('Y-m-d', strtotime($asOf . ' -5 day'));
        bank_store([
            ['id' => "p:$u:1", 'date' => $after1, 'amount' => 1000.25, 'vs' => $vs, 'account' => '', 'name' => '', 'message' => ''],
            ['id' => "p:$u:2", 'date' => $after2, 'amount' => 50, 'vs' => $vs, 'account' => '', 'name' => '', 'message' => ''],
            ['id' => "p:$u:3", 'date' => $earlier, 'amount' => 777, 'vs' => $vs, 'account' => '', 'name' => '', 'message' => ''],
            ['id' => "p:$u:4", 'date' => $asOf, 'amount' => 888, 'vs' => $vs, 'account' => '', 'name' => '', 'message' => ''],
        ], 'test');
        $paid = bank_paid_since();
        $this->assertEqualsWithDelta($before + 1050.25, $paid[3], 0.001);
        foreach ($paid as $unit => $sum) {
            $this->assertIsInt($unit);
            $this->assertIsFloat($sum);
        }
    }

    public function testParsedStatementStoredEndToEnd(): void
    {
        $u = random_int(1000000, 9999999);
        $vs = bank_vs((string) val('SELECT vs FROM unit_accounts WHERE unit_id = 4'));
        $csv = "ID pohybu;Datum;Objem;VS\n$u;10.11.2026;2600;$vs\n" . ($u + 1) . ";11.11.2026;10;123456789";
        $this->assertSame([2, 1, 1], bank_store(bank_parse($csv), 'soubor'));
        $this->assertSame([0, 0, 0], bank_store(bank_parse($csv), 'soubor'));
    }

    // ---------- bank_daily ----------

    public function testDailyWithoutTokenDoesNothing(): void
    {
        $origToken = one('SELECT value FROM settings WHERE `key` = ?', ['fio_token']);
        $origLast = one('SELECT value FROM settings WHERE `key` = ?', ['bank_last']);
        try {
            self::setSetting('fio_token', null);
            self::setSetting('bank_last', 'marker');
            bank_daily();
            $this->assertSame('marker', val('SELECT value FROM settings WHERE `key` = ?', ['bank_last']));
        } finally {
            self::setSetting('fio_token', $origToken['value'] ?? null);
            self::setSetting('bank_last', $origLast['value'] ?? null);
        }
    }

    public function testDailyWithMalformedTokenRecordsErrorWithoutNetwork(): void
    {
        $origToken = one('SELECT value FROM settings WHERE `key` = ?', ['fio_token']);
        $origLast = one('SELECT value FROM settings WHERE `key` = ?', ['bank_last']);
        try {
            self::setSetting('fio_token', 'bad token!');   // tvar neprojde → bank_fio_fetch vrátí chybu bez spojení
            bank_daily();
            $last = (string) val('SELECT value FROM settings WHERE `key` = ?', ['bank_last']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}\|chyba\|Token Fio API nemá správný tvar\.$/u', $last);
        } finally {
            self::setSetting('fio_token', $origToken['value'] ?? null);
            self::setSetting('bank_last', $origLast['value'] ?? null);
        }
    }

    public function testFioFetchRejectsMalformedTokenBeforeNetwork(): void
    {
        $this->assertSame('Token Fio API nemá správný tvar.', bank_fio_fetch(''));
        $this->assertSame('Token Fio API nemá správný tvar.', bank_fio_fetch('short'));
        $this->assertSame('Token Fio API nemá správný tvar.', bank_fio_fetch(str_repeat('a', 30) . '/../x'));
    }
}
