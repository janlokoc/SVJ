<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * app/search_lib.php. Testy, které závisí na přihlášeném uživateli, běží v samostatném procesu:
 * user() si uživatele pamatuje po celý požadavek (statická proměnná), takže v jednom procesu nejde roli střídat.
 */
final class SearchTest extends TestCase
{
    /** Přihlášení v novém procesu: uid + otisk hesla, jak to dělá login_user(). */
    private static function signIn(int $id): void
    {
        $u = one('SELECT * FROM users WHERE id = ?', [$id]);
        $_SESSION['uid'] = (int) $u['id'];
        $_SESSION['pv'] = pw_fingerprint($u['password_hash']);
    }

    public function testNormRemovesDiacriticsAndKeepsLength(): void
    {
        $s = 'Žluťoučký KŮŇ úpěl ďábelské ódy – Řeřicha, ŁÓDŹ, garçon';
        $n = search_norm($s);
        $this->assertSame('zlutoucky kun upel dabelske ody – rericha, lodz, garcon', $n);
        $this->assertSame(mb_strlen($s), mb_strlen($n), 'length preserved for highlighting');
        $this->assertSame('lsctzyaieuuaon', search_norm('ĽŠČŤŽÝÁÍÉÚŮÄÔŇ'));
    }

    public function testTerms(): void
    {
        $this->assertSame(['zapis', 'ze', 'schuze', '2026'], search_terms('Zápis ze schůze, a 2026 – ZÁPIS!'));
        $this->assertSame([], search_terms(' a , i ; '));
        $this->assertSame([], search_terms(''));
        $many = search_terms('jedna dva tri ctyri pet sest sedm osm devet deset');
        $this->assertCount(8, $many);
        $this->assertSame('osm', end($many));
        $this->assertSame(['mail'], search_terms('e-mail'), 'one-letter parts are dropped');
    }

    public function testSnippetHighlightsOriginalTextAndEscapes(): void
    {
        $out = search_snippet('Zápis ze  schůze výboru', ['schuze']);
        $this->assertSame('Zápis ze <mark>schůze</mark> výboru', $out, 'whitespace collapsed, diacritics kept in the mark');

        $out = search_snippet('<b>Schůze</b> & <script>x</script>', ['schuze', 'script']);
        $this->assertSame('&lt;b&gt;<mark>Schůze</mark>&lt;/b&gt; &amp; &lt;<mark>script</mark>&gt;x&lt;/<mark>script</mark>&gt;', $out);
        $this->assertStringNotContainsString('<script>', $out);

        // Překrývající se termy: delší vyhrává, nic se nezdvojí.
        $this->assertSame('<mark>Vodoměry</mark> a <mark>vodo</mark>vod', search_snippet('Vodoměry a vodovod', ['vodo', 'vodomery']));

        $this->assertSame('', search_snippet("  \n ", ['x']));
        $this->assertSame('bez nálezu', search_snippet('bez nálezu', ['zzz']));
    }

    public function testSnippetWindowAndEllipsis(): void
    {
        $text = str_repeat('alfa beta ', 40) . 'HLEDANÉ slovo ' . str_repeat('gama delta ', 40);
        $out = search_snippet($text, ['hledane'], 100);
        $this->assertStringStartsWith('…', $out);
        $this->assertStringEndsWith('…', $out);
        $this->assertStringContainsString('<mark>HLEDANÉ</mark>', $out);
        $plain = html_entity_decode(strip_tags($out));
        $this->assertSame(100 + 2, mb_strlen($plain), 'window of $len characters plus two ellipses');
        $this->assertSame(60, mb_strpos($plain, 'HLEDANÉ') - 1, 'match starts 60 characters into the window');

        $short = search_snippet('Krátký text o vodě', ['vode']);
        $this->assertStringStartsNotWith('…', $short);
        $this->assertStringEndsNotWith('…', $short);
    }

    public function testDocTextExtract(): void
    {
        $dir = sys_get_temp_dir() . '/svj-search-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents("$dir/a.txt", "První   řádek\t s mezerami\n\n\n\n\nDalší &amp; text");
            $this->assertSame("První řádek s mezerami\n\nDalší & text", doc_text_extract("$dir/a.txt", 'TXT'));

            $zip = new \ZipArchive();
            $zip->open("$dir/b.docx", \ZipArchive::CREATE);
            $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Stanovy</w:t><w:tab/><w:t>společenství</w:t></w:r></w:p><w:p><w:r><w:t>Čl. 1 &quot;Název&quot;</w:t></w:r></w:p></w:body></w:document>');
            $zip->close();
            $this->assertSame("Stanovy společenství\nČl. 1 \"Název\"", doc_text_extract("$dir/b.docx", 'docx'));

            $zip = new \ZipArchive();
            $zip->open("$dir/c.xlsx", \ZipArchive::CREATE);
            $zip->addFromString('xl/sharedStrings.xml', '<sst><si><t>Jednotka</t></si><si><t>Podíl</t></si></sst>');
            $zip->close();
            $this->assertSame("Jednotka\nPodíl", doc_text_extract("$dir/c.xlsx", 'xlsx'));

            $this->assertSame('', doc_text_extract("$dir/neni.txt", 'txt'), 'missing file');
            $this->assertSame('', doc_text_extract("$dir/a.txt", 'jpg'), 'unsupported type');
            file_put_contents("$dir/broken.docx", 'not a zip');
            $this->assertSame('', doc_text_extract("$dir/broken.docx", 'docx'), 'broken file never throws');
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function testPdfTextBuiltin(): void
    {
        $content = "BT /F1 12 Tf (Hello \\(PDF\\)) Tj ET\nBT [(Wor) -250 (ld)] TJ ET\nBT <416263> Tj ET";
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream\nendobj\n";
        $out = pdf_text_builtin($pdf);
        $this->assertStringContainsString('Hello (PDF)', $out);
        $this->assertStringContainsString('Wor ld', $out, 'large negative kerning = word space');
        $this->assertStringContainsString('Abc', $out, 'hex string');

        $flate = gzcompress('BT (Komprimovano) Tj ET');
        $pdf = "%PDF-1.5\n2 0 obj\n<< /Filter /FlateDecode /Length " . strlen($flate) . " >>\nstream\n$flate\nendstream\nendobj\n";
        $this->assertStringContainsString('Komprimovano', pdf_text_builtin($pdf));

        $this->assertSame('', pdf_text_builtin('not a pdf'));
    }

    public function testIndexPendingFillsContent(): void
    {
        $name = bin2hex(random_bytes(16));
        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0700, true);
        }
        file_put_contents(UPLOAD_DIR . "/$name.txt", 'Obsah dokumentu kvetinac');
        q("INSERT INTO documents (title, category, visibility, filename, original_name) VALUES ('Index test', 'oznameni', 'public', ?, 'puvodni.txt')", ["$name.txt"]);
        $ok = (int) db()->lastInsertId();
        q("INSERT INTO documents (title, category, visibility, filename, original_name) VALUES ('Index zly', 'oznameni', 'public', '../../config.php', 'x.txt')");
        $bad = (int) db()->lastInsertId();
        try {
            $this->assertNull(val('SELECT content FROM documents WHERE id = ?', [$ok]));
            $n = search_index_pending(500, 60.0);
            $this->assertGreaterThanOrEqual(2, $n);
            $this->assertSame('Obsah dokumentu kvetinac', val('SELECT content FROM documents WHERE id = ?', [$ok]));
            $this->assertSame('', val('SELECT content FROM documents WHERE id = ?', [$bad]), 'suspicious file name is never read');
            $this->assertSame(0, search_index_pending(), 'nothing left to index');
        } finally {
            q('DELETE FROM documents WHERE id IN (?,?)', [$ok, $bad]);
            @unlink(UPLOAD_DIR . "/$name.txt");
        }
    }

    // ---------- site_search: viditelnost podle role (samostatné procesy) ----------

    /** Data se slovem „zrzavka“: veřejná a vnitřní aktualita, dokument domu, dokument jednotky 2, termín, závada uživatele 2. */
    private static function seedSearchData(): void
    {
        q("INSERT INTO posts (title, body, visibility, published_at) VALUES ('Veřejná zrzavka', 'text pro všechny', 'public', ?)", [date('Y-m-d')]);
        q("INSERT INTO posts (title, body, visibility, published_at) VALUES ('Vnitřní zrzavka', 'jen pro obyvatele', 'residents', ?)", [date('Y-m-d')]);
        q("INSERT INTO posts (title, body, visibility, published_at) VALUES ('Budoucí zrzavka', 'ještě nevyšlo', 'public', ?)", [date('Y-m-d', strtotime('+3 day'))]);
        q("INSERT INTO documents (title, category, visibility, original_name, content) VALUES ('Zrzavka řád', 'zakladni', 'residents', 'rad.pdf', 'pravidla')");
        q("INSERT INTO documents (title, category, visibility, original_name, content) VALUES ('Veřejný dokument', 'zakladni', 'public', 'verejny.pdf', 'uvnitř je slovo ZRZAVKA a další text')");
        q("INSERT INTO documents (title, category, visibility, unit_id, original_name, content) VALUES ('Vyúčtování zrzavka', 'oznameni', 'residents', 2, 'v.pdf', '')");
        q("INSERT INTO events (title, event_date, event_time, place, note, program) VALUES ('Zrzavka brigáda', ?, '', '', '', '')", [date('Y-m-d', strtotime('+5 day'))]);
        ticket_ready();
        q("INSERT INTO tickets (user_id, subject, location, body, status, created_at) VALUES (2, 'Zrzavka v chodbě', '', 'popis', 'new', ?)", [date('Y-m-d H:i:s')]);
    }

    private static function titles(array $groups, string $key): array
    {
        return isset($groups[$key]) ? array_column($groups[$key][1], 0) : [];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnonymousSeesOnlyPublicContent(): void
    {
        self::seedSearchData();
        $this->assertNull(user());
        $g = site_search('ZRZAVKA');
        $this->assertSame(['Veřejná zrzavka'], self::titles($g, 'aktuality'), 'no residents-only or future posts');
        $this->assertSame(['Veřejný dokument'], self::titles($g, 'dokumenty'));
        $doc = $g['dokumenty'][1][0];
        $this->assertStringContainsString('<mark>ZRZAVKA</mark>', $doc[2], 'snippet from document text');
        $this->assertStringStartsWith('/soubor/', $doc[1]);
        foreach (['terminy', 'diskuze', 'zavady', 'jednotky', 'ucty', 'dodavatele'] as $k) {
            $this->assertArrayNotHasKey($k, $g, "anonymous must not search $k");
        }
        $this->assertSame([], site_search('a'), 'no usable term');
        $this->assertSame([], site_search('zrzavka neexistujicislovo'), 'all words must match');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResidentSeesResidentsContentAndOwnUnitOnly(): void
    {
        self::seedSearchData();
        self::signIn(2);   // jednotka 2, autor závady
        $this->assertSame('resident', user()['role']);
        $g = site_search('zrzavka');
        $this->assertEqualsCanonicalizing(['Veřejná zrzavka', 'Vnitřní zrzavka'], self::titles($g, 'aktuality'));
        $this->assertEqualsCanonicalizing(['Zrzavka řád', 'Veřejný dokument', 'Vyúčtování zrzavka'], self::titles($g, 'dokumenty'));
        $this->assertSame(['Zrzavka brigáda'], self::titles($g, 'terminy'));
        $this->assertSame(['Zrzavka v chodbě'], self::titles($g, 'zavady'));
        $this->assertSame('Moje závady a požadavky', $g['zavady'][0]);
        $this->assertStringStartsWith('/zavady#t', $g['zavady'][1][0][1]);
        foreach (['jednotky', 'ucty', 'dodavatele'] as $k) {
            $this->assertArrayNotHasKey($k, $g);
        }
        // Nadpis váží víc než text: dokument s hledaným slovem v názvu je před dokumentem, který ho má jen v obsahu.
        $docs = self::titles($g, 'dokumenty');
        $this->assertGreaterThan(array_search('Zrzavka řád', $docs, true), array_search('Veřejný dokument', $docs, true));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOtherResidentDoesNotSeeForeignUnitDocumentOrTicket(): void
    {
        self::seedSearchData();
        self::signIn(4);   // jednotka 3
        $g = site_search('zrzavka');
        $this->assertNotContains('Vyúčtování zrzavka', self::titles($g, 'dokumenty'));
        $this->assertContains('Zrzavka řád', self::titles($g, 'dokumenty'));
        $this->assertArrayNotHasKey('zavady', $g, 'ticket of another user is not found');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAdminSeesEverythingIncludingAccounts(): void
    {
        self::seedSearchData();
        self::signIn(1);
        $this->assertTrue(is_admin());
        $g = site_search('zrzavka');
        // Správce najde i dokument jednotky – může ho otevřít (can_open_document).
        $this->assertContains('Vyúčtování zrzavka', self::titles($g, 'dokumenty'));
        $this->assertContains('Zrzavka řád', self::titles($g, 'dokumenty'));
        $this->assertSame('Závady a požadavky', $g['zavady'][0]);
        $this->assertStringStartsWith('/admin/zavady#t', $g['zavady'][1][0][1]);
        $name = (string) val('SELECT name FROM users WHERE id = 3');
        $g = site_search(search_norm($name));
        $this->assertContains($name, self::titles($g, 'ucty'));
        $this->assertSame('Účty (jen pro správce)', $g['ucty'][0]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHiddenFeatureIsNotSearched(): void
    {
        self::seedSearchData();
        q("INSERT INTO settings (`key`, value) VALUES ('hidden_features', 'aktuality,dokumenty') ON CONFLICT(`key`) DO UPDATE SET value = excluded.value");
        setting('__reset');
        $g = site_search('zrzavka');
        $this->assertArrayNotHasKey('aktuality', $g);
        $this->assertArrayNotHasKey('dokumenty', $g);
    }
}
