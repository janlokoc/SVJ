<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Úplnost překladů části webu pro obyvatele: každý text ze šablon pro obyvatele musí mít překlad
 * ve všech slovnících (app/lang/{en,sk,uk,vi}.php), stejně jako hlášky (flash) skládané v PHP.
 */
final class I18nCoverageTest extends TestCase
{
    private const LANGS = ['en', 'sk', 'uk', 'vi'];

    /** Šablony stránek pro obyvatele (administrace, konzole a partnerský portál se nepřekládají). */
    private const VIEWS = [
        // moduly
        'ext/hybrid/_event_box', 'ext/hybrid/owner', 'ext/moda/_digest_box', 'ext/moda/digest_unsub', 'ext/moda/magic_confirm',
        'ext/moda/magic_request', 'ext/modf/fund_public', 'ext/modf/my', 'ext/modf/_chart', 'ext/rollam/_poll_note', 'ext/rollam/confirm',
        'ext/unitcase/list', 'ext/unitcase/form', 'ext/unitcase/case', 'ext/listing/page', 'ext/listing/list', 'ext/listing/mine', 'ext/listing/form', 'ext/listing/report', 'ext/listing/extend', 'ext/listing/inquiries', 'ext/listing/inquiry',
        '_ai_mini',
        // jádro
        'account', 'gdpr', 'chat', 'chat_thread', 'contacts', 'event', 'events', 'file_view', 'home', 'invite', 'join', 'login',
        'payments', 'readings', 'reservations', 'search', 'surveys', 'tickets', 'welcome', '_push_box', '_ticket_thread',
        'reports/_form', 'reports/page', 'assistant', '_assistant_widget', 'board', 'privacy', 'poll', 'post', 'documents',
    ];

    /**
     * Texty, které se ze šablony staticky nedají ověřit, nebo se nepřekládají. Ke každému důvod.
     * Varianty skládané v PHP jsou ověřené níže na skutečném výsledném textu.
     */
    private const SKIP = [
        'E-mail' => 'stejné ve všech jazycích',
        'Outlook (Microsoft 365)' => 'název služby',
        'Outlook.com' => 'název služby',
        'Facebook' => 'název služby',
        'WhatsApp' => 'název služby',
        'Web' => 'skryté pole proti robotům (honeypot)',
        'Potřeba: {}' => 'MAJORITY – ověřeno v testMajorityLabels',
        'od {} za {} {}. Vaše podíly se počítají do usnášeníschopnosti.' => 'jednotku/jednotky – ověřeno v testComposedTexts',
        '{}odpovědí: {}{}' => 'varianty ověřeny v testComposedTexts',
        '– dostane ho {}, ne výbor ani správa domu. Závadu v domě (topení, výtah, světlo, úklid) sem prosím nepište:' => 'dvě varianty – ověřeno v testComposedTexts',
        'z {} ({} {}). Návrh je přijat, když je PRO více než {} % {}.' => 'procenta – ověřeno v testComposedTexts',
        '({}) · proti {} ({}) · zdrželo se {} ({}) —' => 'procenta – ověřeno v testComposedTexts',
        'Přihlášeno {}, potřeba více než {} %' => 'procenta – ověřeno v testComposedTexts',
        'od {}{}' => 'datum zařízení – ověřeno v testComposedTexts',
        'Jednotka {}. Kód jsme poslali jako {}.' => 'e-mail / SMS – ověřeno v testSpecificPatternWinsOverGenericOne',
    ];

    public static function setUpBeforeClass(): void
    {
        if (!defined('MAJORITY')) {
            $route = function (): void {};   // soubor tras: trasy se jen „zaregistrují“ naprázdno
            require_once dirname(__DIR__, 2) . '/app/routes_presence.php';
        }
    }

    /** Texty z šablon (stejný postup jako translate_html: textové uzly a překládané atributy; <?= ?> = {}). */
    private static function viewTexts(string $file): array
    {
        $s = (string) file_get_contents($file);
        $s = (string) preg_replace('/<\?=.*?\?>/s', '{}', $s);
        $s = (string) preg_replace('/<\?php.*?\?>/s', "\x01", $s);
        $texts = [];
        preg_match_all('/>([^<>\x01]+)</u', $s, $m);
        foreach ($m[1] as $t) {
            $t = trim((string) preg_replace('/\s+/u', ' ', $t), " \x01");
            if (preg_match('/\p{L}{2}/u', $t) && $t !== '{}') {
                $texts[] = $t;
            }
        }
        preg_match_all('/\s(?:placeholder|aria-label|title|alt|data-confirm|data-label)="([^"]*\p{L}[^"]*)"/u', $s, $m);
        foreach ($m[1] as $t) {
            $texts[] = trim($t);
        }
        return array_values(array_unique($texts));
    }

    /** Je text přeložený? Zkusí i podobu bez ikon na okraji ({} na začátku/konci), bez vložených částí a s číslem místo {}. */
    private static function translated(string $text, string $lang): bool
    {
        foreach (self::candidates($text) as $c) {
            if (!preg_match('/\p{L}/u', $c) || t($c, $lang) !== $c) {
                return true;
            }
        }
        return false;
    }

    private static function candidates(string $text): array
    {
        $edge = (string) preg_replace(['/^(\{\}\s*)+/u', '/(\s*\{\})+[.,]?$/u'], '', $text);
        $all = array_map('trim', [$text, $edge, str_replace('{}', '', $text), str_replace('{}', '7', $text), str_replace('{}', '7', $edge)]);
        return array_values(array_filter(array_unique($all), fn($c) => $c !== ''));
    }

    public function testResidentViewsAreTranslated(): void
    {
        $root = dirname(__DIR__, 2) . '/app/views/';
        $missing = [];
        foreach (self::VIEWS as $view) {
            $file = $root . $view . '.php';
            $this->assertFileExists($file);
            foreach (self::viewTexts($file) as $text) {
                if (isset(self::SKIP[$text])) {
                    continue;
                }
                foreach (self::LANGS as $lang) {
                    // Slovenština má řadu slov stejných jako čeština (Kontakty, Program…) – stačí, že je klíč ve slovníku.
                    if (!self::translated($text, $lang) && !($lang === 'sk' && self::inDictionary($text, 'sk'))) {
                        $missing[] = "$lang · $view · $text";
                    }
                }
            }
        }
        $this->assertSame([], $missing, "Chybí překlad:\n" . implode("\n", $missing));
    }

    private static function inDictionary(string $text, string $lang): bool
    {
        $d = lang_dictionary($lang);
        foreach (self::candidates($text) as $c) {
            if (isset($d[$c]) || isset($d[(string) preg_replace('/\d+(?:[.,]\d+)*/', '#', $c)])) {
                return true;
            }
        }
        return false;
    }

    public function testDictionariesHaveSameKeysAndPlaceholders(): void
    {
        $en = lang_dictionary('en');
        foreach (['sk', 'uk', 'vi'] as $lang) {
            $d = lang_dictionary($lang);
            $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($d))), "$lang: chybějící klíče");
            $this->assertSame([], array_values(array_diff(array_keys($d), array_keys($en))), "$lang: přebytečné klíče");
        }
        foreach (self::LANGS as $lang) {
            foreach (lang_dictionary($lang) as $k => $v) {
                $this->assertSame(substr_count((string) $k, '{}'), substr_count($v, '{}'), "$lang: počet {} u „{$k}“");
                $this->assertSame(substr_count((string) $k, '#'), substr_count($v, '#'), "$lang: počet # u „{$k}“");
            }
        }
    }

    public function testSpecificPatternWinsOverGenericOne(): void
    {
        // „Jednotka {}“ nesmí spolknout delší větu, která jím začíná.
        $this->assertSame('Unit 12A. We have sent the code by e-mail to j•••@example.cz.', t('Jednotka 12A. Kód jsme poslali jako e-mail j•••@example.cz.', 'en'));
        $this->assertSame('Unit 12A', t('Jednotka 12A', 'en'));
        $this->assertSame('Квартира 3. Ми надіслали код через SMS на •••123.', t('Jednotka 3. Kód jsme poslali jako SMS na •••123.', 'uk'));
    }

    public function testComposedTexts(): void
    {
        $cases = [
            // flash() skládané v PHP (app/ext/routes_*.php, rollam_lib.php, sso_lib.php)
            'Děkujeme, hlas za jednotku 1/12 je ověřen a zaznamenán.' => 'Thank you, the vote for unit 1/12 has been verified and recorded.',
            'Děkujeme, hlas za jednotku 1/12 je ověřen přes Bank iD a zaznamenán.' => 'Thank you, the vote for unit 1/12 has been verified through Bank iD and recorded.',
            'Děkujeme, hlas za jednotku 1/12 je zaznamenán.' => 'Thank you, the vote for unit 1/12 has been recorded.',
            'Poslali jsme Vám kód na e-mail jan@example.cz.' => 'We have sent a code to your e-mail jan@example.cz.',
            'Hlas je zaznamenaný: zdržel se.' => 'Your vote has been recorded: abstain.',
            'Kód už byl odeslán 3×. Začněte prosím hlasovat znovu.' => 'The code has already been sent 3 times. Please start voting again.',
            'Kód nesouhlasí. Zbývá pokusů: 2.' => 'The code does not match. Attempts left: 2.',
            'Účet Google je propojený. Příště se můžete přihlásit tlačítkem „Přihlásit přes Google“.' => 'The Google account is linked. Next time you can sign in with the “Sign in with Google” button.',
            'Propojení s účtem Apple je zrušené.' => 'The Apple account has been unlinked.',
            'Přihlášení přes Microsoft jste zrušili.' => 'You cancelled signing in with Microsoft.',
            'Pozvánka byla odeslána na jana@example.cz. Platí 30 dní.' => 'The invitation has been sent to jana@example.cz. It is valid for 30 days.',
            'Děkujeme, výbor Vaši závadu nebo požadavek dostal i s přílohami (2). Stav řešení uvidíte na této stránce.' => 'Thank you, the committee has received your fault report or request including the attachments (2). You will see its status on this page.',
            'Zpráva je příliš dlouhá (nejvýše 2000 znaků).' => 'The message is too long (at most 2000 characters).',
            'Připojit se půjde v den konání shromáždění (5 December 2026).' => 'You will be able to join on the day of the meeting (5 December 2026).',
            // texty skládané v šablonách
            'od 18:05 za jednotku A1. Vaše podíly se počítají do usnášeníschopnosti.' => 'since 18:05 for unit A1. Your shares count towards the quorum.',
            'od 18:05 za jednotky A1, A2. Vaše podíly se počítají do usnášeníschopnosti.' => 'since 18:05 for units A1, A2. Your shares count towards the quorum.',
            '5 December 2026 v 18:00 · Hybridní (na místě i online)' => '5 December 2026 at 18:00 · Hybrid (on site and online)',
            'do 3. 12. 2026 · odpovědí: 4 · anonymní' => 'until 3. 12. 2026 · answers: 4 · anonymous',
            'odpovědí: 4 · výbor vidí, kdo jak odpověděl' => 'answers: 4 · the committee sees who answered what',
            '– dostane ho správce tohoto webu, ne výbor ani správa domu. Závadu v domě (topení, výtah, světlo, úklid) sem prosím nepište:' => '– it goes to the administrator of this website, not to the committee or building management. Please do not report faults in the building (heating, lift, lights, cleaning) here:',
            'z 24 (45,5 % jednotek). Návrh je přijat, když je PRO více než 50 % všech jednotek.' => 'of 24 (45,5 % of units). A proposal is adopted when more than 50 % of all units vote FOR.',
            'Přihlášeno 45,5 %, potřeba více než 50 %' => 'Registered 45,5 %, more than 50 % needed',
            'jednotky (1)' => 'units (1)',
            'Výhled zůstatku fondu oprav a plánovaných nákladů po letech 2026–2035; podrobně v tabulce pod grafem.' => 'Outlook of the repair fund balance and planned costs by year, 2026–2035; details in the table below the chart.',
            '2027: zůstatek na konci roku 1 234 Kč · opravy 0 Kč' => '2027: year-end balance 1 234 Kč · repairs 0 Kč',
            'Správu domu zajišťuje výbor SVJ. IČ 00000000, číslo účtu 000000-0000000000/0000.' => 'The building is managed by the owners’ association committee. Company ID 00000000, account number 000000-0000000000/0000.',
            "12\u{a0}345\u{a0}Kč – uhradíte načtením QR kódu" => 'CZK 12 345 – pay by scanning the QR code',
            '3 nepřečtené zprávy' => '3 unread messages',
            'od 3. 11. 2026' => 'since 3. 11. 2026',
            'od 3. 11. 2026 · naposledy doručeno 4. 11. 2026 10:00' => 'since 3. 11. 2026 · last delivered 4. 11. 2026 10:00',
        ];
        foreach ($cases as $cs => $en) {
            $this->assertSame($en, t($cs, 'en'), $cs);
            foreach (['sk', 'uk', 'vi'] as $lang) {
                // slovensky může text vyjít stejně jako česky („jednotky (1)“) – pak musí být aspoň ve slovníku
                $this->assertTrue(t($cs, $lang) !== $cs || ($lang === 'sk' && self::inDictionary($cs, 'sk')), "$lang: $cs");
            }
        }
    }

    public function testMajorityLabelsAndChoices(): void
    {
        foreach (MAJORITY as [$label]) {
            foreach (self::LANGS as $lang) {
                $this->assertNotSame('Potřeba: ' . $label, t('Potřeba: ' . $label, $lang), "$lang: $label");
            }
        }
        foreach (MOTION_CHOICES as $label) {
            foreach (['en', 'uk', 'vi'] as $lang) {
                $this->assertNotSame($label, t($label, $lang), "$lang: $label");
                $this->assertNotSame('Hlas je zaznamenaný: ' . mb_strtolower($label) . '.', t('Hlas je zaznamenaný: ' . mb_strtolower($label) . '.', $lang));
            }
        }
        foreach (AI_RESIDENT_TASKS as $task) {
            foreach (self::LANGS as $lang) {
                $this->assertNotSame(AI_TASKS[$task][0], t(AI_TASKS[$task][0], $lang), "$lang: AI_TASKS[$task]");
            }
        }
    }

    /** Inzeráty: názvy, ceny a hlášky skládané v PHP (app/ext/listing_lib.php, routes_listing.php). */
    public function testListingRuntimeTexts(): void
    {
        $l = ['type' => 'prodej', 'layout' => '2+kk', 'price' => 4250000];
        $cases = [
            listing_title($l) => '2+kk flat for sale',
            listing_title(['type' => 'pronajem', 'layout' => '3+1']) => '3+1 flat for rent',
            listing_title(['type' => 'pronajem', 'layout' => 'jine']) => 'Flat for rent',
            listing_price_label(['type' => 'pronajem', 'price' => 18500]) => '18 500 Kč / month',   // t() sjednotí mezery
            'Ke zveřejnění jsou potřeba aspoň ' . LISTING_PHOTOS_MIN . ' fotky.' => 'At least 3 photos are needed to publish.',
            'Inzerát je označený jako prodaný. Ještě ' . LISTING_SOLD_DAYS . ' dní bude vidět se štítkem, potom zmizí.' => 'The listing is marked as sold. It will be shown with a label for another 14 days and then disappear.',
            'Inzerát je prodloužený do 3. 2. 2027.' => 'The listing has been extended until 3. 2. 2027.',
            'Fotka je příliš malá (aspoň 200 × 200 bodů).' => 'The photo is too small (at least 200 × 200 pixels).',
            'K inzerátu lze nahrát nejvýše ' . LISTING_PHOTOS_MAX . ' fotek.' => 'At most 10 photos can be uploaded to a listing.',
            'Jednotka 12A/5. V inzerátu se ukáže jen ulice a město domu – číslo bytu, vchod ani Váš e-mail ne.' => 'Unit 12A/5. The listing shows only the street and town of the building – not the flat number, the entrance or your e-mail.',
        ];
        foreach ($cases as $cs => $en) {
            $this->assertSame($en, t($cs, 'en'), $cs);
            foreach (['sk', 'uk', 'vi'] as $lang) {
                $this->assertTrue(t($cs, $lang) !== $cs || ($lang === 'sk' && self::inDictionary($cs, 'sk')), "$lang: $cs");
            }
        }
        // Hlášky a chyby, které vidí vlastník nebo zájemce.
        [, $errors] = listing_input([], ['id' => 0, 'floor' => '', 'area' => null]);
        // Trasy pro vlastníky a návštěvníky (část pro výbor je až za značkou „Výbor“ a nepřekládá se).
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/app/ext/routes_listing.php');
        $src = substr($routes, 0, (int) strpos($routes, '// ---------- Výbor')) . file_get_contents(dirname(__DIR__, 2) . '/app/ext/listing_lib.php');
        preg_match_all("~(?:flash|render\\('error'[^;]*'message' =>)\\('([^'.]+[.!?])'~u", $src, $m);
        $texts = array_merge($errors, $m[1]);
        foreach (['Fotka musí být JPG, PNG nebo WebP.', 'Napište prosím zprávu.', 'Vyplňte prosím své jméno.', 'Zadejte e-mail nebo telefon, na kterém Vás vlastník bytu zastihne.',
            'Napište prosím, co je na inzerátu špatně.', 'Inzerát už není aktivní, zprávu nejde odeslat.'] as $x) {
            $texts[] = $x;
        }
        $this->assertGreaterThan(12, count($texts));
        foreach (array_unique($texts) as $x) {
            foreach (self::LANGS as $lang) {
                $this->assertTrue(t($x, $lang) !== $x || ($lang === 'sk' && self::inDictionary($x, 'sk')), "$lang: $x");
            }
        }
        // Zájemci o inzerát a balkon / lodžie: popisky, chyby a hlášky skládané v PHP.
        $more = array_merge(array_values(LISTING_OUTDOOR), [listing_inquiry_label(1, 1), listing_inquiry_label(3, 3), listing_inquiry_label(12, 12), listing_inquiry_label(0, 4), listing_inquiry_label(0, 0),
            'Zájemce nechal jen telefon – zavolejte mu nebo pošlete SMS.', 'Napište prosím odpověď.', 'Odpověď je příliš dlouhá (nejvýše ' . LISTING_INQUIRY_MAX . ' znaků).',
            'Odpověď jsme zájemci poslali e-mailem. Vaši adresu neuvidí.', 'Zpráva je v archivu.', 'Zpráva je zpět mezi zájemci.', 'Zájemci o inzerát', 'Zpráva zájemce',
            'Zadejte plochu balkonu, lodžie, terasy nebo předzahrádky v m², např. 9,7.']);
        foreach ($more as $x) {
            foreach (self::LANGS as $lang) {
                $this->assertTrue(t($x, $lang) !== $x || ($lang === 'sk' && self::inDictionary($x, 'sk')), "$lang: $x");
            }
        }
        $this->assertSame('Interested people (3 new)', t(listing_inquiry_label(3, 5), 'en'));
        $this->assertSame('Glazed loggia', t('Zasklená lodžie', 'en'));
        foreach (['Inzeráty', 'Moje inzeráty', 'Nový inzerát', 'Upravit inzerát', 'Nahlásit inzerát', 'Prodloužit inzerát'] as $title) {
            $this->assertNotSame($title, t($title, 'en'));
        }
    }

    /** Obsah textových polí (text ke zkopírování, co napsal uživatel) se nepřekládá, ani když začíná známou frází. */
    public function testTextareaContentIsNeverTranslated(): void
    {
        $html = '<h1>Prodej bytu 2+kk</h1><textarea rows="3" placeholder="Popis bytu">Prodej bytu 2+kk, Brno' . "\n" . 'Cena: 1 Kč</textarea><p>Kontakt</p>';
        $out = translate_html($html, 'en');
        $this->assertStringContainsString('<h1>2+kk flat for sale</h1>', $out);
        $this->assertStringContainsString('<textarea rows="3" placeholder="Description of the flat">Prodej bytu 2+kk, Brno' . "\n" . 'Cena: 1 Kč</textarea>', $out);
        $this->assertStringContainsString('<p>Contact</p>', $out);
    }

    public function testSsoMessagesAreTranslated(): void
    {
        foreach (['denied', 'no_account', 'unverified', 'invite', 'blocked', 'expired', 'linked_other', 'link_wrong_user', 'other'] as $code) {
            foreach (['', 'jana@example.cz'] as $email) {
                $msg = sso_message($code, 'google', $email);
                foreach (self::LANGS as $lang) {
                    $this->assertNotSame($msg, t($msg, $lang), "$lang: $code");
                }
            }
        }
    }

    public function testTranslateHtmlCoversTableLabelsAndTooltips(): void
    {
        $html = '<td data-label="Odhad nákladů">1</td><div data-tip="2027: zůstatek na konci roku 1 234 Kč · opravy 0 Kč"></div>';
        $out = translate_html($html, 'en');
        $this->assertStringContainsString('data-label="Estimated cost"', $out);
        $this->assertStringContainsString('data-tip="2027: year-end balance 1 234 Kč · repairs 0 Kč"', $out);
    }

    public function testGdprCountsAreSeparateTextNodes(): void
    {
        $html = '<p class="muted">A dále, co jste na webu sami zapsali: <span>jednotky (1)</span>, <span>zprávy (5)</span>.</p>';
        $this->assertSame('<p class="muted">And what you have entered on the website yourself: <span>units (1)</span>, <span>messages (5)</span>.</p>', translate_html($html, 'en'));
    }

    /** Plán oprav: „Stav“ a „Probíhá“ mají ve slovníku jiný význam (odečet, otevřené hlášení), proto vlastní texty. */
    public function testRepairPlanStatusHasOwnWording(): void
    {
        $this->assertSame('Repair status', t('Stav opravy', 'en'));
        $this->assertSame('Repair in progress', t('Oprava probíhá', 'en'));
        foreach (['sk', 'uk', 'vi'] as $lang) {
            $this->assertNotSame('Oprava probíhá', t('Oprava probíhá', $lang), $lang);
        }
        $this->assertStringContainsString("'Oprava probíhá'", (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/ext/modf/fund_public.php'));
    }
}
