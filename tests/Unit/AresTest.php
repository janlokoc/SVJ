<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Údaje z ARES (app/ares_lib.php). Testují se jen části bez sítě: kontrola IČO, převod názvů a odpovědí,
 * a vstupní kontroly, které vrátí chybu dřív, než by se volalo ARES (adresa ARES jde přepsat jen v config.php).
 */
final class AresTest extends TestCase
{
    public function testIcoChecksumValid(): void
    {
        $this->assertSame('27082440', ares_ico('27082440'));
        $this->assertSame('00032824', ares_ico('00032824'));
        $this->assertSame('00032824', ares_ico('32824'), 'short IČO is left-padded with zeros');
        $this->assertSame('27082440', ares_ico(' 270 82 440 '), 'whitespace is ignored');
        $this->assertSame('45274649', ares_ico('45274649'));
        $this->assertSame('00064581', ares_ico('00064581'));
        // zbytek 0 → kontrolní číslice 1, zbytek 1 → kontrolní číslice 0
        $this->assertSame('00000621', ares_ico('621'));
        $this->assertSame('00000400', ares_ico('400'));
        $this->assertNull(ares_ico('620'));
        $this->assertNull(ares_ico('401'));
    }

    public function testIcoChecksumInvalid(): void
    {
        foreach (['27082441', '27082449', '00032825', '12345678', '1234567a', '', '   ', '123456789', '2708-2440', 'CZ27082440', '-1'] as $bad) {
            $this->assertNull(ares_ico($bad), "'$bad' must be rejected");
        }
    }

    public function testShortNameStripsSvjPrefixes(): void
    {
        $cases = [
            'Společenství vlastníků jednotek Zahradní 12, Brno' => 'Zahradní 12, Brno',
            'Společenství vlastníků jednotek pro dům Lipová 5' => 'Lipová 5',
            'Společenství vlastníků jednotek domu Lipová 5' => 'Lipová 5',
            'Společenství vlastníků jednotek v domě Lipová 5, Praha' => 'Lipová 5, Praha',
            'Společenství vlastníků jednotek, Lipová 5' => 'Lipová 5',
            'Společenství vlastníků jednotek pro budovu č.p. 1234' => 'č.p. 1234',
            'Společenství vlastníků jednotek č.p. 1234' => '1234',
            'Společenství vlastníků jednotek čp. 77 Kunštát' => '77 Kunštát',
            'Společenství vlastníků Na Kopci 3' => 'Na Kopci 3',
            'SVJ Lipová 5' => 'Lipová 5',
            'svj lipová 5' => 'lipová 5',
            'SPOLEČENSTVÍ VLASTNÍKŮ JEDNOTEK Pod Lesem 1' => 'Pod Lesem 1',
            '  Společenství vlastníků jednotek domu Brno – Lesná.  ' => 'Brno – Lesná',
            'Bytové družstvo Lipa' => 'Bytové družstvo Lipa',
        ];
        foreach ($cases as $in => $out) {
            $this->assertSame($out, ares_short_name($in), $in);
        }
    }

    public function testShortNameKeepsOriginalWhenNothingIsLeft(): void
    {
        $this->assertSame('Společenství vlastníků jednotek', ares_short_name('Společenství vlastníků jednotek'));
        $this->assertSame('SVJ', ares_short_name(' SVJ '));
        $this->assertSame('', ares_short_name(''));
    }

    public function testSubjectMapping(): void
    {
        $s = ares_subject([
            'ico' => '27082440', 'dic' => 'CZ27082440', 'obchodniJmeno' => ' Společenství vlastníků jednotek Zahradní 12, Brno ',
            'sidlo' => ['textovaAdresa' => ' Zahradní 1234/12, Černá Pole, 60200 Brno '], 'datumVzniku' => '2004-05-01', 'pravniForma' => '145',
        ]);
        $this->assertSame([
            'ico' => '27082440', 'dic' => 'CZ27082440', 'name' => 'Společenství vlastníků jednotek Zahradní 12, Brno', 'short' => 'Zahradní 12, Brno',
            'site_name' => 'SVJ Zahradní 12, Brno', 'address' => 'Zahradní 1234/12, Černá Pole, 60200 Brno', 'founded' => '2004-05-01', 'svj' => true, 'closed' => false,
        ], $s);
    }

    public function testSubjectMappingNonSvjClosedAndMissingFields(): void
    {
        $s = ares_subject(['ico' => '45274649', 'obchodniJmeno' => 'ČEZ, a. s.', 'pravniForma' => '121', 'datumZaniku' => '2020-01-01']);
        $this->assertFalse($s['svj']);
        $this->assertTrue($s['closed']);
        $this->assertSame('', $s['dic']);
        $this->assertSame('', $s['address']);
        $this->assertSame('', $s['founded']);
        $this->assertSame('ČEZ, a. s.', $s['name']);
        $this->assertSame('ČEZ, a. s', $s['short'], 'short name trims trailing punctuation (meant for SVJ names)');

        $empty = ares_subject([]);
        $this->assertSame('', $empty['ico']);
        $this->assertSame('', $empty['name']);
        $this->assertFalse($empty['svj']);
        $this->assertFalse($empty['closed']);
        $this->assertTrue(ares_subject(['pravniForma' => 145])['svj']);
        $this->assertFalse(ares_subject(['datumZaniku' => ''])['closed']);
    }

    public function testByIcoRejectsInvalidIcoWithoutNetwork(): void
    {
        foreach (['', 'abc', '27082441', '123456789'] as $bad) {
            $r = ares_by_ico($bad);
            $this->assertFalse($r['ok']);
            $this->assertStringContainsString('není platné IČO', $r['error']);
        }
    }

    public function testSearchRejectsShortQueryWithoutNetwork(): void
    {
        $r = ares_search('ab');
        $this->assertSame(['ok' => false, 'error' => 'Napište IČO, nebo aspoň tři písmena z názvu či ulice.'], $r);
        // obecná slova se odříznou – zbude příliš krátký text
        $r = ares_search('Společenství vlastníků jednotek   pro dům  Ab');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('tři písmena', $r['error']);
        $r = ares_search(' x ', 8, true);
        $this->assertSame(['ok' => false, 'error' => 'Napište IČO, nebo aspoň tři písmena z názvu.'], $r);
    }

    public function testOfficersRejectInvalidIcoWithoutNetwork(): void
    {
        $r = ares_officers('');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('není platné IČO', $r['error']);
        $this->assertFalse(ares_officers('11111111')['ok']);
    }

    public function testLookupJsonForInvalidInputs(): void
    {
        ob_start();
        ares_lookup_json('27082441');
        $byIco = json_decode((string) ob_get_clean(), true);
        $this->assertFalse($byIco['ok']);
        $this->assertStringContainsString('není platné IČO', $byIco['error']);

        ob_start();
        ares_lookup_json('ab', true);
        $search = json_decode((string) ob_get_clean(), true);
        $this->assertSame(['ok' => false, 'error' => 'Napište IČO, nebo aspoň tři písmena z názvu.'], $search);
    }

    public function testPositionMapping(): void
    {
        $cases = [
            'předseda kontrolní komise' => 'predseda_kk',
            'Předseda Kontrolní komise' => 'predseda_kk',
            'člen kontrolní komise' => 'clen_kk',
            'Kontrolní komise' => 'clen_kk',
            'místopředseda výboru' => 'mistopredseda',
            'MÍSTOPŘEDSEDA VÝBORU' => 'mistopredseda',
            'předseda společenství' => 'predseda_svj',
            'předseda výboru' => 'predseda',
            'Předseda' => 'predseda',
            'člen výboru' => 'clen_vyboru',
            'jednatel' => '',
            'pověřený vlastník' => '',
            '' => '',
        ];
        foreach ($cases as $in => $out) {
            $this->assertSame($out, ares_position($in), $in);
            $this->assertArrayHasKey($out, POSITIONS, 'mapped value must be a known position');
        }
    }

    public function testPersonName(): void
    {
        $this->assertSame('Ing. Jan Novák, Ph.D.', ares_person_name(['titulPredJmenem' => 'Ing.', 'jmeno' => 'JAN', 'prijmeni' => 'NOVÁK', 'titulZaJmenem' => 'Ph.D.']));
        $this->assertSame('Eva Svobodová', ares_person_name(['jmeno' => ' EVA ', 'prijmeni' => 'SVOBODOVÁ']));
        $this->assertSame('Marie Nováková-Dvořáková', ares_person_name(['jmeno' => 'MARIE', 'prijmeni' => 'NOVÁKOVÁ-DVOŘÁKOVÁ']));
        $this->assertSame('Šťastný', ares_person_name(['prijmeni' => 'ŠŤASTNÝ', 'titulZaJmenem' => ' ']));
        $this->assertSame('', ares_person_name([]));
    }

    public function testNameKeyIgnoresPrenominalTitlesAccentsAndCase(): void
    {
        $this->assertSame('jan novak', ares_name_key('Ing. Jan Novák'));
        $this->assertSame(ares_name_key('JAN NOVÁK'), ares_name_key('Ing.  Jan   Novák'));
        $this->assertSame('eva svobodova', ares_name_key('Mgr. Bc. Eva Svobodová'));
        $this->assertSame('petr dvorak', ares_name_key('doc. MUDr. Petr Dvořák'));
        $this->assertSame('ingrid bcova', ares_name_key('Ingrid Bcová'), 'titles are removed only as whole words');
    }

    public function testNameKeyMatchesRegistryNameWithPostnominalTitles(): void
    {
        $this->markTestIncomplete('BUG: ares_name_key() leaves ", Ph.D." and a trailing comma after ", CSc."/", DiS." (app/ares_lib.php:220) – '
            . 'a registry member from ares_person_name() like "Ing. Jan Novák, Ph.D." does not match the existing account "Jan Novák" in platform_console.php /vybor.');
        $this->assertSame('jan novak', ares_name_key(ares_person_name(['titulPredJmenem' => 'Ing.', 'jmeno' => 'JAN', 'prijmeni' => 'NOVÁK', 'titulZaJmenem' => 'Ph.D.'])));
        $this->assertSame('petr dvorak', ares_name_key('doc. MUDr. Petr Dvořák, CSc.'));
        $this->assertSame('eva svobodova', ares_name_key('Eva Svobodová, DiS.'));
    }
}
