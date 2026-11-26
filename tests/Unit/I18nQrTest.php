<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Translation helpers (app/i18n.php) and the QR generator (app/qr.php). */
final class I18nQrTest extends TestCase
{
    private ?array $savedPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedPath = array_key_exists('PATH', $GLOBALS) ? [$GLOBALS['PATH']] : null;
    }

    protected function tearDown(): void
    {
        if ($this->savedPath === null) {
            unset($GLOBALS['PATH']);
        } else {
            $GLOBALS['PATH'] = $this->savedPath[0];
        }
        unset($GLOBALS['IS_PLATFORM']);
        parent::tearDown();
    }

    // ---------- i18n ----------

    public function testCurrentLangIsKnownLanguage(): void
    {
        // The demo house is Czech and no cookie is set in CLI; the value is cached per process.
        $this->assertSame('cs', current_lang());
        $this->assertArrayHasKey(current_lang(), LANGS);
    }

    public function testLangDictionary(): void
    {
        $en = lang_dictionary('en');
        $this->assertNotEmpty($en);
        $this->assertSame('Sign out', $en['Odhlásit']);
        $this->assertSame([], lang_dictionary('xx'), 'missing file');
        $this->assertSame([], lang_dictionary('../en'), 'path traversal refused');
        $this->assertSame([], lang_dictionary('EN'), 'only lowercase codes');
        foreach (['sk', 'uk', 'vi'] as $lang) {
            $this->assertArrayHasKey('Odhlásit', lang_dictionary($lang), $lang);
        }
    }

    public function testTranslateExactAndUnknown(): void
    {
        $this->assertSame('Sign out', t('Odhlásit', 'en'));
        $this->assertSame('Odhlásiť', t('Odhlásit', 'sk'));
        $this->assertSame('Odhlásit', t('Odhlásit', 'cs'), 'Czech is the source language');
        $this->assertSame('Odhlásit', t('Odhlásit'), 'defaults to current language (cs)');
        $this->assertSame('Tohle ve slovníku není', t('Tohle ve slovníku není', 'en'));
        $this->assertSame('Odhlásit', t('Odhlásit', 'xx'), 'unknown language leaves text');
        $this->assertSame('123 456', t('123 456', 'en'), 'text without letters is untouched');
    }

    public function testTranslateNormalisesWhitespaceButKeepsOuterSpace(): void
    {
        $this->assertSame(' Sign out ', t("\n   Odhlásit\t ", 'en'));
        $this->assertSame('Sign out ', t('Odhlásit  ', 'en'));
        $this->assertSame('Welcome, Jana', t("Vítejte,\n Jana", 'en'));
    }

    public function testTranslateNumbersAndPlaceholders(): void
    {
        $this->assertSame('Year 2026', t('Rok 2026', 'en'));
        $this->assertSame('Рік 2026', t('Rok 2026', 'uk'));
        $this->assertSame('Company ID 12345678 · account 123/0800', t('IČ 12345678 · účet 123/0800', 'en'));
        $this->assertSame('Welcome, Jana Výborová', t('Vítejte, Jana Výborová', 'en'));
        $this->assertSame('Vitajte, Jana', t('Vítejte, Jana', 'sk'));
        $this->assertSame('owner · 12A/1', t('vlastník · 12A/1', 'en'));
    }

    public function testTranslateHtml(): void
    {
        $html = '<html><head><title>Odhlásit · SVJ Zahradní 12</title></head><body>'
            . '<a href="/odhlasit" title="Odhlásit">Odhlásit</a> <input placeholder="Odhlásit">'
            . '<p>Neznámý obsah &amp; &lt;b&gt;</p><span> Rok 2026 </span></body></html>';
        $out = translate_html($html, 'en');
        $this->assertStringContainsString('<title>Sign out · SVJ Zahradní 12</title>', $out, 'only the part before · is translated');
        $this->assertStringContainsString('title="Sign out">Sign out</a>', $out);
        $this->assertStringContainsString('placeholder="Sign out"', $out);
        $this->assertStringContainsString('href="/odhlasit"', $out, 'href is not an attribute that gets translated');
        $this->assertStringContainsString('<p>Neznámý obsah &amp; &lt;b&gt;</p>', $out, 'unknown text is left byte-for-byte');
        $this->assertStringContainsString('<span> Year 2026 </span>', $out);
        $this->assertSame($html, translate_html($html, 'cs'));
        $this->assertSame($html, translate_html($html, 'xx'));
    }

    public function testTranslateHtmlEscapesTranslatedOutput(): void
    {
        // A placeholder value containing markup must come back escaped.
        $out = translate_html('<p>Vítejte, <b>x</b></p><p>Vítejte, &lt;script&gt;</p>', 'en');
        $this->assertStringContainsString('<p>Welcome, &lt;script&gt;</p>', $out);
        $this->assertStringNotContainsString('<script>', $out);
    }

    public function testPageTranslatable(): void
    {
        unset($GLOBALS['PATH'], $GLOBALS['IS_PLATFORM']);
        $this->assertTrue(page_translatable());
        $GLOBALS['PATH'] = '/dokumenty';
        $this->assertTrue(page_translatable());
        $GLOBALS['PATH'] = '/admin/uzivatele';
        $this->assertFalse(page_translatable());
        $GLOBALS['PATH'] = '/';
        $GLOBALS['IS_PLATFORM'] = true;
        $this->assertFalse(page_translatable());
    }

    public function testMonthAndWeekdayNames(): void
    {
        $this->assertSame('března', month_name(3));
        $this->assertSame('bře', month_name(3, true));
        $this->assertSame('prosince', month_name(12));
        $this->assertSame('neděle', weekday_name(0));
        $this->assertSame('sobota', weekday_name(6));
        $GLOBALS['PATH'] = '/admin';
        $this->assertSame('ledna', month_name(1), 'admin is always Czech');
        foreach (MONTHS_I18N as $lang => $months) {
            $this->assertCount(13, $months, $lang);
            $this->assertCount(13, MONTHS_SHORT_I18N[$lang], $lang);
            $this->assertCount(7, WEEKDAYS_I18N[$lang], $lang);
        }
    }

    // ---------- QR ----------

    public function testQrSvgForShortText(): void
    {
        $svg = qr_svg('HELLO', 'Platba <QR>');
        $this->assertStringStartsWith('<svg class="qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 29 29"', $svg, 'version 1 = 21 modules + 2×4 quiet zone');
        $this->assertStringContainsString('aria-label="Platba &lt;QR&gt;"', $svg);
        $this->assertStringEndsWith('</svg>', $svg);
        $this->assertMatchesRegularExpression('/<path fill="#000" d="(M\d+ \d+h\d+v1h-\d+z)+"\/>/', $svg);
        $this->assertSame($svg, qr_svg('HELLO', 'Platba <QR>'), 'deterministic');
        $this->assertNotSame($svg, qr_svg('HELLP', 'Platba <QR>'));
    }

    public function testQrVersionGrowsWithLengthAndHasLimit(): void
    {
        $this->assertSame(25, count(qr_matrix(str_repeat('a', 26))), '26 bytes still version 2');
        $this->assertSame(29, count(qr_matrix(str_repeat('a', 27))), '27 bytes need version 3');
        $this->assertSame(21, count(qr_matrix(str_repeat('a', 14))), '14 bytes fit version 1-M');
        $this->assertSame(25, count(qr_matrix(str_repeat('a', 15))), '15 bytes need version 2');
        $this->assertSame(57, count(qr_matrix(str_repeat('a', 213))), 'version 10');
        $this->assertNull(qr_matrix(str_repeat('a', 214)), 'too long');
        $this->assertSame('', qr_svg(str_repeat('a', 214)));
        $this->assertStringContainsString('viewBox="0 0 65 65"', qr_svg(str_repeat('a', 213)));
    }

    public function testQrMatrixStructure(): void
    {
        foreach (['SPD*1.0*ACC:CZ6508000000192000145399*AM:450.00*CC:CZK', str_repeat('x', 100)] as $data) {
            $m = qr_matrix($data);
            $n = count($m);
            $version = ($n - 17) / 4;
            foreach ($m as $row) {
                $this->assertCount($n, $row);
            }
            // Finder patterns in three corners (7×7 ring + 3×3 centre) with light separators.
            foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$r0, $c0]) {
                for ($r = 0; $r < 7; $r++) {
                    for ($c = 0; $c < 7; $c++) {
                        $ring = $r === 0 || $r === 6 || $c === 0 || $c === 6;
                        $core = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
                        $this->assertSame($ring || $core, (bool) $m[$r0 + $r][$c0 + $c], "finder at $r0,$c0 module $r,$c");
                    }
                }
            }
            $this->assertFalse((bool) $m[7][7]);
            // Timing patterns alternate dark/light.
            for ($i = 8; $i < $n - 8; $i++) {
                $this->assertSame($i % 2 === 0, (bool) $m[6][$i], "timing row at $i");
                $this->assertSame($i % 2 === 0, (bool) $m[$i][6], "timing column at $i");
            }
            // The always-dark module.
            $this->assertTrue((bool) $m[4 * $version + 9][8]);

            // Format information (both copies) must be a valid level-M word.
            $valid = ['101010000010010', '101000100100101', '101111001111100', '101101101001011',
                '100010111111001', '100000011001110', '100111110010111', '100101010100000'];
            $a = '';
            foreach ([[8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8], [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]] as [$r, $c]) {
                $a .= $m[$r][$c] ? '1' : '0';
            }
            $b = '';
            for ($r = $n - 1; $r >= $n - 7; $r--) {
                $b .= $m[$r][8] ? '1' : '0';
            }
            for ($c = $n - 8; $c < $n; $c++) {
                $b .= $m[8][$c] ? '1' : '0';
            }
            $this->assertSame($a, $b, 'both format copies agree');
            $this->assertTrue(in_array($a, $valid, true) || in_array(strrev($a), $valid, true), "format bits $a are a level-M word");
        }
    }

    public function testTotpQrFallsBackToShortIssuer(): void
    {
        $secret = totp_new_secret();
        $svg = totp_qr($secret, 'SVJ Zahradní 12', 'jana@example.cz', 'SVJ');
        $this->assertStringStartsWith('<svg class="qr"', $svg);
        $this->assertStringContainsString('aria-label="QR kód pro ověřovací aplikaci"', $svg);
        // An issuer too long for any QR version: the short one is used instead.
        $long = str_repeat('Společenství vlastníků ', 10);
        $this->assertSame(qr_svg(totp_uri($secret, 'SVJ', 'jana@example.cz'), 'QR kód pro ověřovací aplikaci'), totp_qr($secret, $long, 'jana@example.cz', 'SVJ'));
        // Everything too long → ''.
        $this->assertSame('', totp_qr($secret, $long, str_repeat('a', 200) . '@example.cz', $long));
    }
}
