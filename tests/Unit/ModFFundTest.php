<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Modul F – plán oprav a fond oprav (app/ext/modf_fund_lib.php): výhled, potřebné zvýšení příspěvků, rozpočítání, graf. */
final class ModFFundTest extends TestCase
{
    private function settings(array $over = []): array
    {
        return $over + ['balance' => 100000.0, 'balance_date' => '2026-12-31', 'mode' => 'shares', 'monthly' => 10000.0, 'years' => 10, 'inflation' => 0.0, 'published' => false];
    }
    private function repair(int $year, int $cost, string $status = 'planned'): array
    {
        return ['title' => 'Oprava ' . $year, 'year' => $year, 'cost' => $cost, 'priority' => 2, 'status' => $status, 'note' => ''];
    }

    public function testProjectionWithoutRepairsGrows(): void
    {
        $p = modf_fund_projection($this->settings(), [], 12);
        $this->assertCount(10, $p['years']);
        $this->assertSame(2026, $p['years'][0]['year']);
        $this->assertSame(0.0, $p['years'][0]['income'], 'Stav k 31. 12. – v prvním roce už žádné příspěvky.');
        $this->assertSame(220000.0, $p['years'][1]['end']);
        $this->assertNull($p['first_negative']);
        $this->assertSame(0.0, $p['increase']);
    }

    public function testNegativeYearAndNeededIncrease(): void
    {
        // Stav 100 000 k 31. 12. 2026, 10 000 Kč měsíčně; v roce 2028 střecha za 500 000.
        $p = modf_fund_projection($this->settings(), [$this->repair(2028, 500000), $this->repair(2027, 1000, 'done')], 10);
        $byYear = array_column($p['years'], null, 'year');
        $this->assertSame(220000.0, $byYear[2027]['end'], 'Hotová oprava se nepočítá.');
        $this->assertSame(-160000.0, $byYear[2028]['end']);
        $this->assertTrue($byYear[2028]['negative']);
        $this->assertSame(2028, $p['first_negative']);
        // Potřeba: (500 000 − 100 000) / 24 měsíců = 16 666,67 → 16 667 Kč měsíčně.
        $this->assertSame(16667.0, $p['needed']);
        $this->assertSame(6667.0, $p['increase']);
        $this->assertSame(667.0, $p['increase_unit']);
        // Se zvýšeným příspěvkem už fond do mínusu nejde.
        $p2 = modf_fund_projection($this->settings(['monthly' => $p['needed']]), [$this->repair(2028, 500000)], 10);
        $this->assertNull($p2['first_negative']);
    }

    public function testFirstYearMonthsAndOverdueRepairsAndHorizon(): void
    {
        // Stav k 15. 3.: příspěvky za duben–prosinec (9 měsíců); nehotová oprava z minulosti patří do prvního roku.
        $p = modf_fund_projection($this->settings(['balance_date' => '2026-03-15', 'balance' => 0.0, 'monthly' => 1000.0]), [$this->repair(2020, 5000), $this->repair(2050, 1)], 1);
        $this->assertSame(9000.0, $p['years'][0]['income']);
        $this->assertSame(5000.0, $p['years'][0]['cost']);
        $this->assertSame(4000.0, $p['years'][0]['end']);
        $this->assertCount(1, $p['beyond'], 'Oprava za horizontem se jen vypíše.');
    }

    public function testImmediateGapCannotBeFixedByContributions(): void
    {
        $p = modf_fund_projection($this->settings(['balance' => 1000.0]), [$this->repair(2026, 50000)], 4);
        $this->assertSame(2026, $p['first_negative']);
        $this->assertNull($p['needed'], 'V prvním roce už žádný příspěvek nepřijde – zvýšení nepomůže.');
        $this->assertSame(0.0, $p['increase']);
    }

    public function testInflationAndFlatMode(): void
    {
        $s = $this->settings(['mode' => 'flat', 'monthly' => 500.0, 'inflation' => 10.0]);
        $this->assertSame(6000.0, modf_fund_monthly_total($s, 12));
        $p = modf_fund_projection($s, [$this->repair(2028, 100000)], 12);
        $this->assertEqualsWithDelta(121000.0, array_column($p['years'], 'cost', 'year')[2028], 0.01, 'Dva roky růstu cen o 10 %.');
    }

    public function testUnitContributionsByShares(): void
    {
        $units = [['id' => 1, 'share' => '1/4'], ['id' => 2, 'share' => '3/4']];
        $c = modf_unit_contributions($this->settings(['monthly' => 1000.0]), $units);
        $this->assertSame([250.0, 750.0], array_column($c, 'amount'));
        // Chybí-li podíl, dělí se rovným dílem.
        $c = modf_unit_contributions($this->settings(['monthly' => 1000.0]), [['id' => 1, 'share' => '1/4'], ['id' => 2, 'share' => '']]);
        $this->assertSame([500.0, 500.0], array_column($c, 'amount'));
        $c = modf_unit_contributions($this->settings(['mode' => 'flat', 'monthly' => 300.0]), $units);
        $this->assertSame([300.0, 300.0], array_column($c, 'amount'));
        $this->assertSame([], modf_unit_contributions($this->settings(), []));
    }

    public function testChartGeometry(): void
    {
        $proj = ['years' => [['year' => 2026, 'end' => 300.0, 'cost' => 0.0, 'negative' => false], ['year' => 2027, 'end' => -100.0, 'cost' => 200.0, 'negative' => true]]];
        $ch = modf_fund_chart($proj);
        $this->assertSame(25.0, $ch['zero']);
        $this->assertSame(25.0, $ch['cols'][0]['bal_b']);
        $this->assertSame(75.0, $ch['cols'][0]['bal_h']);
        $this->assertSame(0.0, $ch['cols'][1]['bal_b'], 'Záporný zůstatek jde od nuly dolů.');
        $this->assertSame(25.0, $ch['cols'][1]['bal_h']);
        $this->assertSame(50.0, $ch['cols'][1]['cost_h']);
        // Nulový výhled nedělí nulou.
        $this->assertSame(0.0, modf_fund_chart(['years' => [['year' => 2026, 'end' => 0.0, 'cost' => 0.0, 'negative' => false]]])['zero']);
    }

    public function testFundValidationAndSave(): void
    {
        [, $err] = modf_fund_validate(['balance' => 'hodně', 'balance_date' => '', 'mode' => 'x', 'monthly' => '-5', 'years' => '30', 'inflation' => '20']);
        $this->assertCount(6, $err);
        [$d, $err] = modf_fund_validate(['balance' => '1 250 000,50', 'balance_date' => '2026-10-31', 'mode' => 'flat', 'monthly' => '850', 'years' => '15', 'inflation' => '2,5', 'published' => '1']);
        $this->assertSame([], $err);
        $this->assertSame(1250000.5, $d['balance']);
        modf_fund_save($d);
        $s = modf_fund_settings();
        $this->assertSame(1250000.5, $s['balance']);
        $this->assertSame('flat', $s['mode']);
        $this->assertSame(15, $s['years']);
        $this->assertSame(2.5, $s['inflation']);
        $this->assertTrue($s['published']);
        $d['published'] = false;
        modf_fund_save($d);
        $this->assertFalse(modf_fund_settings()['published']);
    }

    public function testRepairValidationAndSave(): void
    {
        [, $err] = modf_repair_validate(['title' => '', 'year' => '1900', 'cost' => 'x', 'priority' => '9', 'status' => 'nic'], 2026);
        $this->assertCount(5, $err);
        [$d, $err] = modf_repair_validate(['title' => 'Výtah', 'year' => '2030', 'cost' => '1 200 000', 'priority' => '1', 'status' => 'approved'], 2026);
        $this->assertSame([], $err);
        $this->assertSame(1200000, $d['cost']);
        $id = modf_repair_save($d);
        $d['status'] = 'done';
        modf_repair_save($d, $id);
        $rows = array_column(modf_repairs(), null, 'id');
        $this->assertSame('done', $rows[$id]['status']);
        $this->assertSame(2030, (int) $rows[$id]['year']);
    }
}
