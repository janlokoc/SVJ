<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Import z katastru: druh jednotky (z textu, jinak odhad podle podílu) a podíl v procentech. */
final class UnitKindShareTest extends TestCase
{
    private static function cadastre(string $extra = ''): string
    {
        $rows = '';
        foreach (range(1, 8) as $n) {
            $rows .= "Vlastník Byt$n, Zahradní 1200/12, Brno\nJednotka: 1200/$n\t141/2500\n";
        }
        foreach (range(101, 103) as $n) {
            $rows .= "Vlastník Garáž$n, Zahradní 1200/12, Brno\nJednotka: 1200/$n\t6/625\n";
        }
        return "Informace o stavbě\n$extra\nVlastníci, jiní oprávnění\nVlastnické právo\tPodíl\n$rows";
    }

    public function testKindEstimatedFromShareAndTakenFromText(): void
    {
        $r = parse_cadastre(self::cadastre());
        $this->assertSame('byt', $r['units']['1200/1']['kind']);
        $this->assertTrue($r['units']['1200/1']['kind_est']);
        $this->assertSame('garaz', $r['units']['1200/101']['kind'], '0,96 % proti 5,64 % = garáž');
        $r = parse_cadastre(self::cadastre("Vymezené jednotky\n1200/1 byt\n1200/101 (garáž)\n1200/102 jiný nebytový prostor"));
        $this->assertSame('nebyt', $r['units']['1200/102']['kind']);
        $this->assertFalse($r['units']['1200/102']['kind_est'], 'druh z textu katastru není odhad');
        $this->assertSame('garaz', $r['units']['1200/101']['kind']);
    }

    /** Společný podíl více jednotek (byt + garáž) se rozdělí podle typických podílů v domě, ne rovným dílem. */
    public function testJointShareSplitByTypicalShares(): void
    {
        $text = self::cadastre("Stavba:\tč. p. 1200\nKatastrální území:\tZahradní [123456]\nČíslo LV:\t577\nPlatnost dat k 28.11.2026 14:40")
            . "SJ Novák Jan a Nováková Eva, Zahradní 1200/12, Brno\nJednotka: 1200/9, 1200/104\t331/5000\n"
            . "SJ Dvořák Petr a Dvořáková Marie\nJednotka: 1200/10, 1200/105, 1200/106\t19/250\nDvořák Petr, Ulice 1, Brno\nDvořáková Marie, Ulice 2, Brno\n";
        $r = parse_cadastre($text);
        $this->assertSame('141/2500', $r['units']['1200/9']['share'], 'byt dostane typický podíl bytu');
        $this->assertSame('49/5000', $r['units']['1200/104']['share'], 'garáž typický podíl garáže');
        $this->assertSame(['byt', 'garaz', 'garaz'], [$r['units']['1200/10']['kind'], $r['units']['1200/105']['kind'], $r['units']['1200/106']['kind']]);
        $this->assertStringContainsString('podle typických podílů', implode(' ', $r['warnings']));
        $this->assertSame(['stavba' => 'č. p. 1200', 'ku' => 'Zahradní [123456]', 'lv' => '577', 'platnost' => '2026-11-28'], $r['meta']);
        $this->assertNull(cadastre_split_share(0.5, 2, ['byt' => 0.0564, 'garaz' => 0.0098]), 'nesedí-li kombinace, rovným dílem');
    }

    public function testSnapshotDiff(): void
    {
        $r = parse_cadastre(self::cadastre());
        $snap = ['at' => '2026-01-01 10:00:00', 'meta' => [], 'units' => []];
        foreach ($r['units'] as $k => $u) {
            $snap['units'][$k] = ['owner' => $u['owner_note'], 'share' => $u['share']];
        }
        $this->assertSame([], cadastre_diff($r, $snap));
        $snap['units']['1200/1']['owner'] = 'Původní Vlastník';
        $snap['units']['1200/999'] = ['owner' => 'Zrušená', 'share' => '1/100'];
        unset($snap['units']['1200/2']);
        $d = cadastre_diff($r, $snap);
        $this->assertSame(['owner', 'new', 'gone'], array_column($d, 'what'));
        $this->assertSame('Původní Vlastník', $d[0]['old']);
        $this->assertSame([], cadastre_diff($r, null), 'první import bez porovnání');
    }

    public function testSharePercent(): void
    {
        $this->assertSame(5.64, share_percent('141/2500'));
        $this->assertSame(0.96, share_percent('6/625'));
        $this->assertSame('5,64 %', share_percent_cz('141/2500'));
        $this->assertSame('', share_percent_cz('nevím'));
        $this->assertNull(share_percent('1/0'));
    }
}
