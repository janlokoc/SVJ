<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Output/formatting helpers from app/lib.php (dates, sizes, text formatting, escaping, URLs). */
final class LibFormattingTest extends TestCase
{
    private array $savedGlobals = [];
    private array $savedServer = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['BASE', 'ASSET_BASE', 'PATH'] as $k) {
            $this->savedGlobals[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
        }
        $this->savedServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $k => $v) {
            if ($v === null) {
                unset($GLOBALS[$k]);
            } else {
                $GLOBALS[$k] = $v[0];
            }
        }
        $_SERVER = $this->savedServer;
        q('DELETE FROM settings WHERE `key` = ?', ['site_url']);
        setting('__reset');
        parent::tearDown();
    }

    public function testCzDateShortAndInvalid(): void
    {
        $this->assertSame('5. 3. 2026', cz_date('2026-03-05'));
        $this->assertSame('31. 12. 2025', cz_date('2025-12-31 23:59:00'));
        $this->assertSame('', cz_date(null));
        $this->assertSame('', cz_date(''));
        $this->assertSame('', cz_date('not a date'));
    }

    public function testCzDateLongUsesGenitiveMonth(): void
    {
        $this->assertSame('5. března 2026', cz_date('2026-03-05', true));
        $this->assertSame('1. října 2026', cz_date('2026-10-01', true));
    }

    public function testCzDatetime(): void
    {
        $this->assertSame('5. 3. 2026 07:08', cz_datetime('2026-03-05 07:08:09'));
        $this->assertSame('', cz_datetime(null));
        $this->assertSame('', cz_datetime('garbage'));
    }

    public function testMonthLabelUsesNominative(): void
    {
        $this->assertSame('říjen 2026', month_label('2026-10'));
        $this->assertSame('leden 2025', month_label('2025-01-15'));
        $this->assertSame('prosinec 2024', month_label('2024-12'));
    }

    public function testFileSize(): void
    {
        $this->assertSame('', file_size(0));
        $this->assertSame('', file_size(-5));
        $this->assertSame('1 kB', file_size(1), 'tiny files round up to 1 kB, never 0 kB');
        $this->assertSame('2 kB', file_size(1536));
        $this->assertSame('1024 kB', file_size(1048575));
        $this->assertSame('1,0 MB', file_size(1048576));
        $this->assertSame('1,5 MB', file_size(1572864));
        $this->assertSame('2 000,0 MB', file_size(2000 * 1048576));
    }

    public function testValidDate(): void
    {
        $this->assertTrue(valid_date('2024-02-29'));
        $this->assertFalse(valid_date('2026-02-29'), 'non-leap year');
        $this->assertFalse(valid_date('2026-13-01'));
        $this->assertFalse(valid_date('2026-1-5'), 'must be zero padded');
        $this->assertFalse(valid_date('5. 1. 2026'));
        $this->assertFalse(valid_date(''));
        $this->assertFalse(valid_date('2026-01-05 10:00'));
    }

    public function testTelHref(): void
    {
        $this->assertSame('tel:+420777123456', tel_href('777 123 456'), 'Czech 9-digit number gets +420');
        $this->assertSame('tel:+420777123456', tel_href('+420 777 123 456'));
        $this->assertSame('tel:+421905123456', tel_href('(+421) 905-123-456'));
        $this->assertSame('tel:112', tel_href('112'));
        $this->assertSame('tel:', tel_href('nemá telefon'));
    }

    public function testFormatTextParagraphsAndLineBreaks(): void
    {
        $this->assertSame('<p>Ahoj<br>světe</p>', format_text("Ahoj\nsvěte"));
        $this->assertSame('<p>a</p><p>b</p>', format_text("a\n\n\nb"));
        $this->assertSame('<p>a<br>b</p>', format_text("  a  \r\n  b  "), 'CR and surrounding whitespace are dropped');
        $this->assertSame('', format_text("  \n\n "));
    }

    public function testFormatTextHeadingsAndLists(): void
    {
        $this->assertSame('<h3>Nadpis</h3><p>text</p>', format_text("## Nadpis\ntext"));
        $this->assertSame('<ul><li>a</li><li>b</li></ul>', format_text("- a\n- b"));
        $this->assertSame('<p>Úvod</p><ul><li>a</li></ul><p>Závěr</p>', format_text("Úvod\n- a\nZávěr"));
        $this->assertSame('<p>-bez mezery</p>', format_text('-bez mezery'), 'only "- " starts a list item');
    }

    public function testFormatTextNotesModeAttachesLineToListItem(): void
    {
        $text = "- Volba výboru\nSchvaluje se nový výbor.\n- Rozpočet";
        $this->assertSame(
            '<ul><li>Volba výboru<br><span class="li-note">Schvaluje se nový výbor.</span></li><li>Rozpočet</li></ul>',
            format_text($text, true)
        );
        $this->assertSame(
            '<ul><li>Volba výboru</li></ul><p>Schvaluje se nový výbor.</p><ul><li>Rozpočet</li></ul>',
            format_text($text)
        );
        // Two note lines: each opens a span, all are closed.
        $html = format_text("- Bod\nPoznámka 1\nPoznámka 2", true);
        $this->assertSame('<ul><li>Bod<br><span class="li-note">Poznámka 1<br><span class="li-note">Poznámka 2</span></span></li></ul>', $html);
        // A blank line ends the list even in notes mode.
        $this->assertSame('<ul><li>Bod</li></ul><p>Odstavec</p>', format_text("- Bod\n\nOdstavec", true));
    }

    public function testFormatTextBoldAndLinks(): void
    {
        $this->assertSame('<p><strong>a</strong> a <strong>b</strong></p>', format_text('**a** a **b**'));
        $this->assertSame(
            '<p>Viz <a href="https://example.cz/a?b=1" rel="noopener noreferrer">https://example.cz/a?b=1</a>.</p>',
            format_text('Viz https://example.cz/a?b=1.'),
            'trailing punctuation is not part of the link'
        );
        $this->assertStringNotContainsString('<a', format_text('ftp://example.cz/x'));
    }

    public function testFormatTextEscapesHtml(): void
    {
        $html = format_text("<script>alert('x')</script>\n## <b>Nadpis</b>\n- <img src=x onerror=1>");
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', $html);
        // A quote inside a URL cannot break out of the href attribute.
        $link = format_text('https://example.cz/"onmouseover="alert(1)');
        $this->assertStringNotContainsString('"onmouseover', $link);
        $this->assertMatchesRegularExpression('~^<p><a href="[^"]*" rel="noopener noreferrer">~', $link);
    }

    public function testExcerpt(): void
    {
        $this->assertSame('Nadpis tučně text', excerpt("## Nadpis\n**tučně**\n\n   text"));
        $this->assertSame('ab…', excerpt('ab cd', 3), 'trailing space is trimmed before the ellipsis');
        $this->assertSame('abcde', excerpt('abcde', 5), 'exact length is not shortened');
        $this->assertSame('příliš…', excerpt('příliš žluťoučký', 6), 'multibyte-safe');
    }

    public function testEscape(): void
    {
        $this->assertSame('&lt;a href=&quot;x&quot;&gt;&#039;&amp;', e('<a href="x">\'&'));
        $this->assertSame('', e(null));
        $this->assertSame('5', e(5));
        $this->assertSame("\u{FFFD}", e("\xff"), 'invalid UTF-8 is substituted, not dropped');
    }

    public function testStripAccents(): void
    {
        $this->assertSame('Prilis zlutoucky kun upel dabelske ody', strip_accents('Příliš žluťoučký kůň úpěl ďábelské ódy'));
        $this->assertSame('ACDEEINORSTUUYZ', strip_accents('ÁČĎÉĚÍŇÓŘŠŤÚŮÝŽ'));
        $this->assertSame('Lubos Stur, Ovocny dvor', strip_accents('Ľuboš Štúr, Ovocný dvôr'));
        $this->assertSame('plain', strip_accents('plain'));
    }

    public function testPercentCz(): void
    {
        $this->assertSame('50,0 %', percent_cz(50));
        $this->assertSame('12,3 %', percent_cz(12.345));
        $this->assertSame('1 234,5 %', percent_cz(1234.5));
        $this->assertSame('0,0 %', percent_cz(0));
    }

    /** @return array<string, array{string, string}> */
    public static function vocatives(): array
    {
        return [
            'Jan' => ['Jan', 'Jane'],
            'Jana' => ['Jana', 'Jano'],
            'Tomáš' => ['Tomáš', 'Tomáši'],
            'Matěj' => ['Matěj', 'Matěji'],
            'Marek' => ['Marek', 'Marku'],
            'Vojtěch' => ['Vojtěch', 'Vojtěchu'],
            'Dominik' => ['Dominik', 'Dominiku'],
            'Petr (fixed)' => ['Petr', 'Petře'],
            'Pavel (fixed)' => ['Pavel', 'Pavle'],
            'Zdeněk (fixed)' => ['Zdeněk', 'Zdeňku'],
            'Dimitr (consonant+r)' => ['Dimitr', 'Dimitře'],
            'Libor (vowel+r)' => ['Libor', 'Libore'],
            'Michal' => ['Michal', 'Michale'],
            'Marie' => ['Marie', 'Marie'],
            'Jiří' => ['Jiří', 'Jiří'],
            'Dagmar (feminine)' => ['Dagmar', 'Dagmar'],
            'lowercase untouched' => ['jan', 'jan'],
            'full name untouched' => ['Jan Novák', 'Jan Novák'],
            'trimmed' => ['  Jana ', 'Jano'],
            'empty' => ['', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('vocatives')]
    public function testCzVocative(string $in, string $out): void
    {
        $this->assertSame($out, cz_vocative($in));
    }

    public function testIconReturnsSvg(): void
    {
        $svg = icon('doc');
        $this->assertStringStartsWith('<svg class="icon"', $svg);
        $this->assertStringEndsWith('</svg>', $svg);
        $this->assertStringContainsString('<path', $svg);
        $this->assertStringContainsString('aria-hidden="true"', $svg);
        $this->assertNotSame(icon('doc'), icon('mail'));
        $unknown = icon('no-such-icon');
        $this->assertStringStartsWith('<svg', $unknown);
        $this->assertStringNotContainsString('<path', $unknown);
    }

    public function testUrlAndAsset(): void
    {
        unset($GLOBALS['BASE'], $GLOBALS['ASSET_BASE']);
        $this->assertSame('/', url());
        $this->assertSame('/dokumenty', url('/dokumenty'));
        $GLOBALS['BASE'] = '/svj';
        $this->assertSame('/svj/dokumenty', url('/dokumenty'));
        // Existing file gets a cache-busting version from its mtime.
        $mtime = filemtime(dirname(__DIR__, 2) . '/assets/style.css');
        $this->assertSame('/svj/assets/style.css?v=' . $mtime, asset('style.css'));
        $GLOBALS['ASSET_BASE'] = 'https://cdn.example';
        $this->assertSame('https://cdn.example/assets/missing.css', asset('missing.css'), 'no version for missing file');
    }

    public function testAbsoluteUrlFromHostAndHttps(): void
    {
        unset($GLOBALS['BASE']);
        $_SERVER['HTTP_HOST'] = 'dum.test.invalid';
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $this->assertSame('http://dum.test.invalid/x', absolute_url('/x'));
        $_SERVER['HTTPS'] = 'on';
        $this->assertSame('https://dum.test.invalid/', absolute_url());
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertStringStartsWith('https://', absolute_url('/'));
        // Hostile Host header: characters outside host syntax are removed.
        $_SERVER['HTTP_HOST'] = 'evil.example/"><script>';
        $this->assertSame('https://evil.examplescript/a', absolute_url('/a'));
    }

    public function testAbsoluteUrlPrefersConfiguredSiteUrl(): void
    {
        q('DELETE FROM settings WHERE `key` = ?', ['site_url']);
        q('INSERT INTO settings (`key`, value) VALUES (?, ?)', ['site_url', 'https://www.svj-test.cz/']);
        setting('__reset');
        $_SERVER['HTTP_HOST'] = 'attacker.example';
        $this->assertSame('https://www.svj-test.cz/muj-ucet', absolute_url('/muj-ucet'));

        // An invalid configured value is ignored.
        q('UPDATE settings SET value = ? WHERE `key` = ?', ['javascript:alert(1)', 'site_url']);
        setting('__reset');
        $this->assertSame('http://attacker.example/muj-ucet', absolute_url('/muj-ucet'));
    }
}
