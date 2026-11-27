<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Dálkové odečty měřidel (app/meters_lib.php): rozpoznání druhu a jednotky, kontrola a import řádků, měsíční spotřeba. */
final class MetersTest extends TestCase
{
    private array $readingIds = [];
    private array $savedSettings = [];
    private array $savedAreas = [];

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
        foreach ($this->savedAreas as $id => $area) {
            q('UPDATE units SET area = ? WHERE id = ?', [$area, $id]);
        }
        setting('__reset');
        $this->readingIds = $this->savedSettings = $this->savedAreas = [];
    }

    private function setArea(int $unit, ?float $area): void
    {
        if (!array_key_exists($unit, $this->savedAreas)) {
            $this->savedAreas[$unit] = val('SELECT area FROM units WHERE id = ?', [$unit]);
        }
        q('UPDATE units SET area = ? WHERE id = ?', [$area, $unit]);
    }

    /** Konec měsíce před $n měsíci (Y-m-d). */
    private static function endOf(int $n): string
    {
        return date('Y-m-t', strtotime(date('Y-m-01') . " -$n months"));
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

    // ---------- Přehled pro obyvatele: graf, loňsko, průměr domu ----------

    public function testHouseAverageNeedsFiveUnitsSkipsEmptyAndHeatIndicators(): void
    {
        $ym = '2026-10';
        $areas = [1 => 50.0, 2 => 70.0, 3 => 60.0, 4 => 40.0, 5 => 80.0, 6 => 55.0, 7 => 0.0];
        $rows = [[1, 'tv', 'a', 5.0, 0], [2, 'tv', 'b', 7.0, 0], [3, 'tv', 'c', 3.0, 0], [4, 'tv', 'd', 4.0, 0], [6, 'tv', 'f', 0.0, 0], [7, 'tv', 'g', 9.0, 0]];
        $this->assertNull(meter_house_average([$ym => $rows], $ym, 'tv', $areas), '4 jednotky s daty (nula a bez plochy se nepočítají) → nic');
        $rows[] = [5, 'tv', 'e', 6.0, 0];
        $rows[] = [5, 'tv', 'e2', 2.0, 0];   // dva vodoměry v jedné jednotce se sečtou
        [$avg, $n] = meter_house_average([$ym => $rows], $ym, 'tv', $areas);
        $this->assertSame(5, $n);
        $this->assertEqualsWithDelta(27 / 300, $avg, 1e-9, 'celková spotřeba / celková plocha');
        $itn = array_map(fn($r) => [$r[0], 'itn', $r[2], $r[3], 0], $rows);
        $this->assertNull(meter_house_average([$ym => $itn], $ym, 'itn', $areas), 'indikátory topení se mezi byty nesrovnávají');
    }

    public function testOverviewSeriesYearAgoAndHouseComparison(): void
    {
        $this->remember('meter_compare');
        q("DELETE FROM settings WHERE `key` = 'meter_compare'");
        setting('__reset');
        $units = array_map('intval', array_column(all('SELECT id FROM units ORDER BY id LIMIT 6'), 'id'));
        $this->assertCount(6, $units, 'ukázkový dům má aspoň 6 jednotek');
        $mine = $units[0];
        foreach ($units as $i => $u) {
            $this->setArea($u, 50.0 + $i * 10);
            $meter = 'OV-' . $i . '-' . bin2hex(random_bytes(3));
            // stav: 13 a 14 měsíců zpět (loňský měsíc), pak poslední dva měsíce
            $this->addReading($u, 'tv', $meter, 10, self::endOf(14));
            $this->addReading($u, 'tv', $meter, 12, self::endOf(13));
            $this->addReading($u, 'tv', $meter, 20, self::endOf(2));
            $this->addReading($u, 'tv', $meter, 20 + 3 + $i, self::endOf(1));
        }
        $all = meter_overview([$mine]);
        $ov = array_values(array_filter($all, fn($m) => $m['kind'] === 'tv'))[0];
        $last = substr(self::endOf(1), 0, 7);
        $this->assertSame($last, $ov['last']);
        $this->assertEquals(3.0, $ov['use']);
        $this->assertEquals(2.0, $ov['yearAgo'], 'tentýž měsíc loni');
        $this->assertSame(50, meter_delta($ov['use'], $ov['yearAgo']));
        $this->assertEquals(50.0, $ov['area']);
        $this->assertEqualsWithDelta(3 / 50, $ov['perM2'], 1e-9);
        $this->assertGreaterThanOrEqual(6, $ov['houseN']);
        $this->assertArrayHasKey($last, $ov['house']);
        $this->assertLessThan(12, count($ov['series']) + 1, 'nejvýš 12 měsíců');
        $this->assertArrayNotHasKey(substr(self::endOf(13), 0, 7), $ov['series'], 'graf jen za posledních 12 měsíců');

        meter_setting('meter_compare', '0');
        $off = array_values(array_filter(meter_overview([$mine]), fn($m) => $m['kind'] === 'tv'))[0];
        $this->assertSame([], $off['house'], 'výbor srovnání vypnul');
        $this->assertSame([], meter_overview([]));
    }

    public function testResidentPageShowsChartAndComparisonWithoutOtherFlats(): void
    {
        $m = ['unit' => 1, 'kind' => 'tv', 'series' => ['2026-09' => 2.5, '2026-10' => 3.0], 'last' => '2026-10', 'use' => 3.0, 'yearAgo' => 4.0,
            'total12' => 5.5, 'area' => 50.0, 'perM2' => 0.06, 'house' => ['2026-09' => 0.05, '2026-10' => 0.08], 'houseN' => 7];
        $view = function (array $data): string {
            extract($data);
            ob_start();
            require dirname(__DIR__, 2) . '/app/views/readings.php';
            return (string) ob_get_clean();
        };
        $html = $view(['units' => [1 => 'A/1'], 'remote' => ['tv'], 'manualKinds' => [], 'rows' => [], 'errors' => [],
            'monthly' => ['2026-10' => [[1, 'tv', 'X', 3.0, 30.0]]], 'overview' => [$m]]);
        $this->assertStringContainsString('<svg class="meter-chart"', $html);
        $this->assertSame(2, substr_count($html, 'class="mc-bar'));
        $this->assertSame(2, substr_count($html, 'class="mc-house"'), 'tečky průměru domu');
        $this->assertStringContainsString('o 25 % méně než loni', $html);
        $this->assertStringContainsString('o 25 % méně než průměr', $html);
        $this->assertStringContainsString('(7 jednotek, bez údajů jednotlivých bytů)', $html);
        $this->assertStringContainsString('Tabulka po měsících', $html);
    }

    public function testNoticePlanStates(): void
    {
        $this->remember('meter_notice_auto');
        $this->remember('meter_notice_2030-10');
        meter_setting('meter_notice_auto', '1');
        q("DELETE FROM settings WHERE `key` = 'meter_notice_2030-10'");
        setting('__reset');
        $this->assertSame(['on' => true, 'month' => '2030-10', 'date' => '2030-11-27', 'waiting' => true], meter_notice_plan('2030-11-27'), 'termín je za námi, data chybí');
        $this->assertSame(['on' => true, 'month' => '2030-10', 'date' => '2030-11-05', 'waiting' => false], meter_notice_plan('2030-11-03'));
        meter_setting('meter_notice_2030-10', '2030-11-05 06:00|12');
        $this->assertSame(['on' => true, 'month' => '2030-11', 'date' => '2030-12-05', 'waiting' => false], meter_notice_plan('2030-11-27'), 'za říjen odešlo, další za listopad 5. 12.');
        meter_setting('meter_notice_auto', '0');
        $this->assertFalse(meter_notice_plan('2030-11-27')['on']);
    }

    public function testLateDataGetFollowUpNoticeOnlyForNewUnits(): void
    {
        if ((int) val('SELECT COUNT(*) FROM push_subs') > 0) {
            $this->markTestSkipped('push subscriptions present – push_notify() would contact a push service');
        }
        $ym = '2019-05';
        $this->remember('meter_notice_' . $ym);
        $this->remember('meter_notice_units_' . $ym);
        $linked = array_map('intval', array_column(all('SELECT DISTINCT uu.unit_id FROM user_units uu JOIN users u ON u.id = uu.user_id WHERE u.active = 1 ORDER BY uu.unit_id LIMIT 2'), 'unit_id'));
        $this->assertCount(2, $linked);
        [$a, $b] = $linked;
        $this->addReading($a, 'tv', 'L1', 5, $ym . '-31');
        $first = meter_notice_send($ym);
        $this->assertGreaterThan(0, $first);
        $this->assertSame((string) $a, setting('meter_notice_units_' . $ym));
        $this->assertSame(0, meter_notice_send($ym, true), 'nikdo nový → nic');
        $before = count($this->mails());
        $this->addReading($b, 'tv', 'L2', 7, $ym . '-31');
        $late = meter_notice_send($ym, true);
        $expected = (int) val('SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_units uu ON uu.user_id = u.id WHERE u.active = 1 AND uu.unit_id = ?', [$b]);
        $this->assertSame($expected, $late, 'jen obyvatelé jednotky, které data přišla později');
        $this->assertCount($before + $late, $this->mails());
        $this->assertStringEndsWith('|' . ($first + $late), setting('meter_notice_' . $ym), 'součet odeslaných');
        $this->assertSame($a . ',' . $b, setting('meter_notice_units_' . $ym));
    }

    public function testAdminScreenHasAutoSwitchInNoticeCard(): void
    {
        $s = (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/admin/meters.php');
        $card = substr($s, (int) strpos($s, 'id="upozorneni"'));
        $this->assertStringContainsString('name="auto"', $card, 'automatika je přímo v rámečku upozornění');
        $this->assertStringContainsString('value="auto"', $card);
        $this->assertStringContainsString('Poslat ručně', $card);
        $this->assertStringNotContainsString('name="auto"', substr($s, 0, (int) strpos($s, 'id="upozorneni"')));
        $this->assertStringContainsString('name="compare"', $s);
    }
}
