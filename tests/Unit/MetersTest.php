<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Dálkové odečty měřidel (app/meters_lib.php): rozpoznání druhu a jednotky, kontrola a import řádků, měsíční spotřeba. */
final class MetersTest extends TestCase
{
    private array $readingIds = [];
    private array $savedSettings = [];

    protected function tearDown(): void
    {
        foreach ($this->readingIds as $id) {
            q('DELETE FROM readings WHERE id = ?', [$id]);
        }
        foreach ($this->savedSettings as $key => $value) {
            q('DELETE FROM settings WHERE `key` = ?', [$key]);
            if ($value !== null) {
                q('INSERT INTO settings (`key`, value) VALUES (?,?)', [$key, $value]);
            }
        }
        setting('__reset');
        $this->readingIds = $this->savedSettings = [];
    }

    private function remember(string $key): void
    {
        if (!array_key_exists($key, $this->savedSettings)) {
            $this->savedSettings[$key] = ($r = one('SELECT value FROM settings WHERE `key` = ?', [$key])) ? (string) $r['value'] : null;
        }
    }

    private function addReading(int $unit, string $kind, string $meter, float $amount, string $date): void
    {
        q('INSERT INTO readings (unit_id, kind, meter_no, amount, read_date, user_id, note, source) VALUES (?,?,?,?,?,NULL,?,?)', [$unit, $kind, $meter, $amount, $date, '', 'test']);
        $this->readingIds[] = (int) db()->lastInsertId();
    }

    // ---------- remote_kinds / meter_setting ----------

    public function testRemoteKindsDefaultAndCustom(): void
    {
        $this->remember('remote_kinds');
        q('DELETE FROM settings WHERE `key` = ?', ['remote_kinds']);
        setting('__reset');
        $this->assertSame(['tv', 'teplo', 'itn'], remote_kinds(), 'default by law: hot water, heat, heat cost allocators');

        meter_setting('remote_kinds', 'el,sv,bogus,,sv');
        $this->assertSame('el,sv,bogus,,sv', setting('remote_kinds'), 'meter_setting resets the settings cache');
        $this->assertSame(['sv', 'el'], remote_kinds(), 'unknown kinds dropped, order of READING_KINDS kept');

        meter_setting('remote_kinds', '');
        $this->assertSame([], remote_kinds());
        $this->assertSame(1, (int) val('SELECT COUNT(*) FROM settings WHERE `key` = ?', ['remote_kinds']), 'meter_setting replaces the row');
    }

    // ---------- meter_kind_from ----------

    public function testKindFromVariousLabels(): void
    {
        $cases = [
            'tv' => 'tv', 'sv' => 'sv', 'teplo' => 'teplo', 'itn' => 'itn', 'plyn' => 'plyn', 'el' => 'el',
            'Teplá voda' => 'tv', 'TEPLÁ VODA' => 'tv', 'TUV' => 'tv', 'TV' => 'tv', 'Hot water' => 'tv', 'warm water' => 'tv',
            'Studená voda' => 'sv', 'SV' => 'sv', 'Voda' => 'sv', 'Vodoměr' => 'sv', 'Cold water' => 'sv',
            'Indikátor topných nákladů' => 'itn', 'ITN' => 'itn', 'HCA' => 'itn', 'Rozdělovač' => 'itn',
            'Teplo' => 'teplo', 'Měřič tepla' => 'teplo', 'Kalorimetr' => 'teplo', 'Heat' => 'teplo', 'ÚT' => 'teplo',
            'Plyn' => 'plyn', 'Plynoměr' => 'plyn', 'Gas' => 'plyn',
            'Elektřina' => 'el', 'Elektroměr' => 'el', 'EL' => 'el', 'power' => 'el',
            '  Teplá voda  ' => 'tv',
        ];
        foreach ($cases as $in => $out) {
            $this->assertSame($out, meter_kind_from((string) $in), "'$in'");
        }
        $this->assertNull(meter_kind_from(''));
        $this->assertNull(meter_kind_from('xyz'));
        $this->assertNull(meter_kind_from('vodné'));
    }

    public function testKindFromCzechHotWaterAbbreviationSpelledOut(): void
    {
        $this->markTestIncomplete('BUG: meter_kind_from() classifies "Teplá užitková voda" (spelled-out TUV) and "Voda teplá" as heat (teplo), '
            . 'because only "tepl\w* vod" directly adjacent is recognised before the generic "tepl" rule (app/meters_lib.php:28,30).');
        $this->assertSame('tv', meter_kind_from('Teplá užitková voda'));
        $this->assertSame('tv', meter_kind_from('Voda teplá'));
    }

    // ---------- meter_unit_from ----------

    public function testUnitFromLabelVariants(): void
    {
        $units = all('SELECT * FROM units');
        $a1 = (int) val("SELECT id FROM units WHERE entrance = 'A' AND number = '1'");
        $b6 = (int) val("SELECT id FROM units WHERE entrance = 'B' AND number = '6'");
        $this->assertSame($a1, meter_unit_from(unit_name(one('SELECT * FROM units WHERE id = ?', [$a1])), '', $units));
        $this->assertSame($a1, meter_unit_from('12 A / 1', '', $units));
        $this->assertSame($a1, meter_unit_from('A/1', '', $units));
        $this->assertSame($a1, meter_unit_from('a/1', '', $units));
        $this->assertSame($a1, meter_unit_from('A1', '', $units));
        $this->assertSame($b6, meter_unit_from('b6', '', $units));
        $this->assertNull(meter_unit_from('1', '', $units), 'number alone is ambiguous with two entrances');
        $this->assertNull(meter_unit_from('C/1', '', $units));
        $this->assertNull(meter_unit_from('', '', $units));
    }

    public function testUnitFromUniqueNumberOrMeterNumber(): void
    {
        $units = [['id' => 101, 'entrance' => 'A', 'number' => '1'], ['id' => 102, 'entrance' => 'A', 'number' => '2b']];
        $this->assertSame(102, meter_unit_from('2B', '', $units), 'unique number is enough');

        $meter = 'MT-' . bin2hex(random_bytes(4));
        $unit = (int) val("SELECT id FROM units WHERE entrance = 'B' AND number = '3'");
        $this->addReading($unit, 'sv', $meter, 1, '2026-01-10');
        $this->assertSame($unit, meter_unit_from('Z/99', $meter, all('SELECT * FROM units')), 'falls back to earlier readings of the meter');
        $this->assertSame($unit, meter_unit_from('', $meter, all('SELECT * FROM units')));
        $this->assertNull(meter_unit_from('', 'NO-SUCH-' . $meter, all('SELECT * FROM units')));
    }

    // ---------- meter_rows_check ----------

    public function testRowsCheckValidRow(): void
    {
        $a2 = (int) val("SELECT id FROM units WHERE entrance = 'A' AND number = '2'");
        $rows = [['unit' => 'A/2', 'meter_no' => ' TV-1 ', 'kind' => 'Teplá voda', 'amount' => '1 234,5', 'date' => '5. 10. 2026']];
        $out = meter_rows_check($rows);
        $this->assertCount(1, $out);
        [$line, $data, $err, $orig] = $out[0];
        $this->assertSame(1, $line);
        $this->assertSame('', $err);
        $this->assertSame($rows[0], $orig);
        $this->assertSame(['unit_id' => $a2, 'kind' => 'tv', 'meter_no' => 'TV-1', 'amount' => 1234.5, 'read_date' => '2026-10-05'], $data);

        $out = meter_rows_check([['unit' => 'A/2', 'kind' => 'sv', 'amount' => '7', 'date' => '2026-10-05T08:00:00']]);
        $this->assertSame('2026-10-05', $out[0][1]['read_date'], 'ISO date with time is cut to the date');
        $this->assertSame('', $out[0][1]['meter_no']);
    }

    public function testRowsCheckErrors(): void
    {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $rows = [
            ['unit' => 'Z/9', 'meter_no' => '', 'kind' => 'tv', 'amount' => '1', 'date' => $today],
            ['unit' => 'Z/9', 'meter_no' => 'NOPE-' . bin2hex(random_bytes(3)), 'kind' => 'tv', 'amount' => '1', 'date' => $today],
            ['unit' => 'A/1', 'kind' => 'pára', 'amount' => '1', 'date' => $today],
            ['unit' => 'A/1', 'kind' => 'tv', 'amount' => 'abc', 'date' => $today],
            ['unit' => 'A/1', 'kind' => 'tv', 'amount' => '-5', 'date' => $today],
            ['unit' => 'A/1', 'kind' => 'tv', 'amount' => '5', 'date' => $tomorrow],
            ['unit' => 'A/1', 'kind' => 'tv', 'amount' => '5', 'date' => '31.02.2026'],
            ['unit' => 'A/1', 'kind' => 'tv', 'amount' => '5', 'date' => ''],
            ['unit' => 'A/1', 'kind' => 'tv', 'amount' => '0', 'date' => $today],
            [],
        ];
        $out = meter_rows_check($rows);
        $errors = array_column($out, 2);
        $this->assertSame([
            'Neznámá jednotka',
            'Neznámá jednotka ani číslo měřidla',
            'Neznámý druh měřidla „pára“',
            'Stav není číslo',
            'Stav není číslo',
            'Neplatné datum',
            'Neplatné datum',
            'Neplatné datum',
            '',
            'Neznámá jednotka',
        ], $errors);
        $this->assertSame(range(1, 10), array_column($out, 0));
        foreach ($out as [$i, $data, $err]) {
            $this->assertSame($err === '', $data !== null, "row $i: data only without error");
        }
    }

    // ---------- meter_rows_save ----------

    public function testRowsSaveInsertsThenOverwritesSameDay(): void
    {
        $meter = 'EL-' . bin2hex(random_bytes(4));
        $checked = meter_rows_check([
            ['unit' => 'B/4', 'meter_no' => $meter, 'kind' => 'el', 'amount' => '100', 'date' => '2026-10-01'],
            ['unit' => 'B/4', 'meter_no' => $meter, 'kind' => 'el', 'amount' => '150', 'date' => '2026-10-15'],
            ['unit' => 'nic', 'meter_no' => '', 'kind' => 'el', 'amount' => '1', 'date' => '2026-10-01'],
        ]);
        try {
            $this->assertSame([2, 0], meter_rows_save($checked, 'import'));
            $checked[0][1]['amount'] = 101.0;
            $this->assertSame([0, 2], meter_rows_save($checked, 'api'));
            $rows = all('SELECT amount, read_date, source, user_id FROM readings WHERE meter_no = ? ORDER BY read_date', [$meter]);
            $this->assertCount(2, $rows);
            $this->assertEquals(101, $rows[0]['amount']);
            $this->assertSame('api', $rows[0]['source']);
            $this->assertNull($rows[0]['user_id']);
            $this->assertEquals(150, $rows[1]['amount']);
        } finally {
            q('DELETE FROM readings WHERE meter_no = ?', [$meter]);
        }
    }

    // ---------- meter_csv_rows ----------

    public function testCsvRowsCzechSemicolon(): void
    {
        $csv = "\xEF\xBB\xBF" . "Jednotka;Číslo měřidla;Druh;Stav;Datum odečtu\r\n12A/1;TV-1;Teplá voda;12,5;1. 10. 2026\r\n\r\nA/2;SV-2;studená;3;2026-10-01\r\n";
        $rows = meter_csv_rows($csv);
        $this->assertSame([
            ['unit' => '12A/1', 'meter_no' => 'TV-1', 'kind' => 'Teplá voda', 'amount' => '12,5', 'date' => '1. 10. 2026'],
            ['unit' => 'A/2', 'meter_no' => 'SV-2', 'kind' => 'studená', 'amount' => '3', 'date' => '2026-10-01'],
        ], $rows);
    }

    public function testCsvRowsEnglishCommaAndTab(): void
    {
        $this->assertSame([['unit' => '', 'meter_no' => 'M1', 'kind' => 'heat', 'amount' => '5', 'date' => '2026-10-01']],
            meter_csv_rows("Meter,Kind,Value,Date\nM1,heat,5,2026-10-01"));
        $this->assertSame([['unit' => 'B/1', 'meter_no' => '', 'kind' => 'itn', 'amount' => '7,5', 'date' => '1.10.2026']],
            meter_csv_rows("Byt\tTyp\tHodnota\tDatum\nB/1\titn\t7,5\t1.10.2026"));
    }

    public function testCsvRowsCp1250(): void
    {
        $this->markTestIncomplete('BUG: meter_csv_rows() converts non-UTF-8 input with mb_convert_encoding(…, "Windows-1250"), which mbstring does not support '
            . '(PHP 8.3: ValueError) – a CSV export in Windows-1250 crashes the import (app/meters_lib.php:110; bank_lib uses iconv for this).');
        $csv = iconv('UTF-8', 'CP1250', "Jednotka;Druh;Stav;Datum\nA/1;Teplá voda;1;1.10.2026");
        $rows = meter_csv_rows($csv);
        $this->assertSame('Teplá voda', $rows[0]['kind']);
    }

    public function testCsvRowsErrors(): void
    {
        $this->assertSame('Soubor je prázdný nebo má jen záhlaví.', meter_csv_rows(''));
        $this->assertSame('Soubor je prázdný nebo má jen záhlaví.', meter_csv_rows("Jednotka;Druh;Stav;Datum\n\n"));
        $missing = 'V záhlaví chybí potřebné sloupce. Potřeba je: jednotka nebo číslo měřidla, druh měřidla, stav a datum odečtu.';
        $this->assertSame($missing, meter_csv_rows("Jednotka;Druh;Stav\nA/1;tv;1"), 'no date');
        $this->assertSame($missing, meter_csv_rows("Jednotka;Druh;Datum\nA/1;tv;2026-10-01"), 'no reading');
        $this->assertSame($missing, meter_csv_rows("Druh;Stav;Datum\ntv;1;2026-10-01"), 'neither unit nor meter');
    }

    public function testCsvRowsShortLinesGiveEmptyValues(): void
    {
        $rows = meter_csv_rows("Jednotka;Druh;Stav;Datum\nA/1");
        $this->assertSame([['unit' => 'A/1', 'meter_no' => '', 'kind' => '', 'amount' => '', 'date' => '']], $rows);
        $this->assertSame('Neplatné datum', meter_rows_check([['unit' => 'A/1', 'kind' => 'tv', 'amount' => '1', 'date' => '']])[0][2]);
    }

    // ---------- monthly_consumption ----------

    public function testMonthlyConsumption(): void
    {
        $this->assertSame([], monthly_consumption([]));
        $unit = (int) val("SELECT id FROM units WHERE entrance = 'B' AND number = '6'");
        $meter = 'PL-' . bin2hex(random_bytes(4));
        $ym = fn(int $n) => date('Y-m', strtotime("first day of -$n months"));
        $this->addReading($unit, 'plyn', $meter, 100, $ym(3) . '-20');
        $this->addReading($unit, 'plyn', $meter, 110, $ym(2) . '-01');
        $this->addReading($unit, 'plyn', $meter, 130.5, $ym(2) . '-28');
        $this->addReading($unit, 'plyn', $meter, 125, $ym(1) . '-15');   // výměna/chyba: pokles → spotřeba 0
        $this->addReading($unit, 'plyn', $meter, 140, date('Y-m') . '-01');   // rozpracovaný měsíc se neukazuje

        $out = monthly_consumption([$unit, 0]);
        $mine = [];
        foreach ($out as $month => $list) {
            foreach ($list as $r) {
                if ($r[2] === $meter) {
                    $mine[$month] = $r;
                }
            }
        }
        $this->assertSame([$ym(1), $ym(2)], array_keys($mine), 'closed months with a previous reading, newest first');
        $this->assertSame([$unit, 'plyn', $meter, 30.5, 130.5], $mine[$ym(2)]);
        $this->assertEquals([$unit, 'plyn', $meter, 0, 125.0], $mine[$ym(1)]);
        $keys = array_keys($out);
        $sorted = $keys;
        rsort($sorted);
        $this->assertSame($sorted, $keys);

        $this->assertArrayNotHasKey($ym(2), array_filter(monthly_consumption([$unit], 1), fn($l) => in_array($meter, array_column($l, 2), true)),
            'shorter window excludes older months');
    }

    // ---------- meter_daily / meter_notice_send ----------

    public function testDailyDoesNothingWhenAutoNoticeOff(): void
    {
        $this->remember('meter_notice_auto');
        q('DELETE FROM settings WHERE `key` = ?', ['meter_notice_auto']);
        setting('__reset');
        meter_daily();
        $this->assertSame([], $this->mails());
    }

    public function testDailyDoesNotRepeatNoticeForMonth(): void
    {
        $last = date('Y-m', strtotime('first day of last month'));
        $this->remember('meter_notice_auto');
        $this->remember('meter_notice_' . $last);
        meter_setting('meter_notice_auto', '1');
        meter_setting('meter_notice_' . $last, '2026-01-05 08:00|3');
        meter_daily();
        $this->assertSame([], $this->mails());
        $this->assertSame('2026-01-05 08:00|3', setting('meter_notice_' . $last));
    }

    public function testNoticeSendMailsResidentsOfUnitsWithReadings(): void
    {
        if ((int) val('SELECT COUNT(*) FROM push_subs') > 0) {
            $this->markTestSkipped('push subscriptions present – push_notify() would contact a push service');
        }
        $ym = '2019-03';
        $this->assertSame(0, meter_notice_send('2019-02'), 'no readings in month → nothing sent');

        $unit = (int) val('SELECT uu.unit_id FROM user_units uu JOIN users u ON u.id = uu.user_id WHERE u.active = 1 ORDER BY uu.unit_id LIMIT 1');
        $this->assertGreaterThan(0, $unit, 'demo has residents linked to units');
        $expected = array_column(all('SELECT DISTINCT u.email FROM users u JOIN user_units uu ON uu.user_id = u.id WHERE u.active = 1 AND uu.unit_id = ?', [$unit]), 'email');
        $this->remember('meter_notice_' . $ym);
        $this->addReading($unit, 'tv', 'N-' . bin2hex(random_bytes(3)), 5, $ym . '-31');

        $sent = meter_notice_send($ym);
        $this->assertSame(count($expected), $sent);
        $mails = $this->mails();
        $this->assertCount($sent, $mails);
        sort($expected);
        $to = array_column($mails, 'to');
        sort($to);
        $this->assertSame($expected, $to);
        $this->assertStringContainsString('Spotřeba za březen 2019', mb_decode_mimeheader($mails[0]['subject']));
        $this->assertFalse($GLOBALS['MAIL_BULK'], 'bulk flag is reset');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}\|' . $sent . '$/', setting('meter_notice_' . $ym));
    }
}
