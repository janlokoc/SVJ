<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * app/ai_lib.php. Bez sítě: v hlavním procesu není klíč (ai_run skončí hned), v samostatných procesech se místo
 * knihovny Anthropic načte náhradní klient (FakeAiClient níže), který jen zapíše požadavek a vrátí připravenou odpověď.
 */
final class AiTest extends TestCase
{
    private static function ym(): string
    {
        return date('Y-m');
    }

    private static function setSetting(string $key, ?string $value): void
    {
        q('DELETE FROM settings WHERE `key` = ?', [$key]);
        if ($value !== null) {
            q('INSERT INTO settings (`key`, value) VALUES (?,?)', [$key, $value]);
        }
        setting('__reset');
    }

    private static function restorePset(string $key, ?string $orig): void
    {
        if ($orig === null) {
            pq('DELETE FROM platform_settings WHERE key = ?', [$key]);
            pset('__reset');
        } else {
            pset_save($key, $orig);
        }
    }

    private static function psetRaw(string $key): ?string
    {
        $v = pq('SELECT value FROM platform_settings WHERE key = ?', [$key])->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /** Samostatný proces: klíč v config.php a náhradní klient místo knihovny Anthropic. Musí běžet před prvním ai_*(). */
    private static function fakeSdk(): void
    {
        ini_set('error_log', ROOT_STORAGE . '/php-error.log');   // error_log() aplikace nesmí jít na výstup procesu
        file_put_contents(ROOT_STORAGE . '/config.php', "<?php\nreturn ['platform' => ['domain' => 'test.invalid'], 'ai' => ['api_key' => ' sk-ant-test-123 ']];\n");
        spl_autoload_register(function (string $class): void {
            if ($class === 'Anthropic\\Client') {
                class_alias(FakeAiClient::class, 'Anthropic\\Client');
            }
        }, true, true);
        FakeAiClient::$calls = [];
        FakeAiClient::$reply = ['stop' => 'end_turn', 'text' => '  Hotový text  ', 'in' => 120, 'out' => 40];
    }

    // ---------- Úkoly a texty ----------

    public function testTaskDefinitions(): void
    {
        $this->assertSame(['draft', 'polish', 'shorten', 'sms', 'objection', 'minutes', 'program', 'resolution', 'rationale', 'invite', 'listing', 'fb', 'reply'], array_keys(AI_TASKS));
        $this->assertStringContainsString('nejvýše 160 znaků', AI_TASKS['sms'][1]);
        $this->assertStringContainsString('[doplnit: …]', AI_TASKS['objection'][1]);
        foreach (AI_TASKS as $key => $t) {
            $this->assertCount(2, $t, $key);
            $this->assertNotSame('', trim($t[0]), "$key has a label");
            $this->assertGreaterThan(40, mb_strlen($t[1]), "$key has an instruction");
        }
        $this->assertStringContainsString('Datum, čas ani místo do programu nepiš', AI_TASKS['program'][1]);
        $this->assertStringContainsString('nic nepřidávej – ani úvodní řádek, datum, čas či místo', AI_TASKS['polish'][1]);
        $this->assertStringContainsString('Zachovej všechna data, časy, místa, částky', AI_TASKS['shorten'][1]);
        $this->assertStringContainsString('[doplnit: …]', AI_TASKS['resolution'][1]);
        $this->assertStringContainsString('[ověřit: …]', AI_TASKS['minutes'][1]);
        $this->assertStringContainsString('[doplnit: co chybí]', AI_SYSTEM);
        $this->assertStringContainsString('Nevymýšlej data, časy, částky, jména', AI_SYSTEM);
    }

    // ---------- Nastavení a dostupnost ----------

    public function testWithoutApiKeyNothingIsCalled(): void
    {
        $cfg = ai_settings();
        $this->assertSame('', $cfg['key']);
        $this->assertSame(plan_limit('ai'), $cfg['limit']);
        $this->assertFalse($cfg['plus']);
        $this->assertFalse(ai_configured());
        $this->assertFalse(ai_available());
        $used = ai_used();
        $this->assertSame(['error' => 'Neznámý úkol.'], ai_run('neexistuje', 'text'));
        $this->assertSame(['error' => 'AI pomocník není na tomto webu zapnutý.'], ai_run('draft', 'Poznámky ke schůzi'));
        $this->assertSame(['error' => 'AI pomocník není na tomto webu zapnutý.'], ai_run('polish', str_repeat('x', 50000)));
        $this->assertSame($used, ai_used(), 'failed run does not count');
        $this->assertTrue(ai_sdk_ready(), 'library is installed in vendor/');
    }

    public function testModelFromConsoleAndWorkspaceOptions(): void
    {
        $orig = self::psetRaw('ai_model');
        try {
            pset_save('ai_model', 'claude-test-model');
            $this->assertSame('claude-test-model', ai_settings()['model']);
        } finally {
            self::restorePset('ai_model', $orig);
        }
        $this->assertSame(pset('ai_model') !== '' ? pset('ai_model') : 'claude-opus-5-5', ai_settings()['model'], 'config default when the console sets none');
        $this->assertNull(ai_request_options(['workspace' => '']));
        $this->assertSame(['extraHeaders' => ['anthropic-workspace-id' => 'wrkspc_1']], ai_request_options(['workspace' => 'wrkspc_1']));
    }

    // ---------- Limity a počítání ----------

    public function testQuotaUsageAndCredits(): void
    {
        $key = 'ai_used_' . self::ym();
        $origUsed = setting($key) === '' ? null : setting($key);
        $origCredits = (int) tenant_row(TENANT)['ai_credits'];
        $limit = ai_settings()['limit'];
        $this->assertGreaterThan(0, $limit, 'Premium has a monthly AI limit');
        try {
            self::setSetting($key, null);
            $this->assertSame(0, ai_used());
            $this->assertNull(ai_quota_error());
            ai_count_use();
            ai_count_use();
            $this->assertSame(2, ai_used());

            self::setSetting($key, (string) $limit);
            pq('UPDATE tenants SET ai_credits = 0 WHERE slug = ?', [TENANT]);
            $this->assertSame(0, ai_credits());
            $err = ai_quota_error();
            $this->assertSame('Měsíční limit AI (' . $limit . ' použití) je vyčerpán. Obnoví se prvního dne příštího měsíce, nebo si v části Předplatné dokupte AI kredity.', $err);

            // Dokoupené kredity: limit vyčerpán, ale lze dál – použití se strhne z kreditů, ne z měsíčního počtu.
            pq('UPDATE tenants SET ai_credits = 2 WHERE slug = ?', [TENANT]);
            $this->assertSame(2, ai_credits());
            $this->assertNull(ai_quota_error());
            ai_count_use();
            $this->assertSame(1, ai_credits());
            $this->assertSame($limit, ai_used());
            ai_count_use();
            $this->assertSame(0, ai_credits());
            $this->assertNotNull(ai_quota_error());
            ai_count_use();   // bez kreditů (např. souběh): započte se do měsíce
            $this->assertSame($limit + 1, ai_used());
        } finally {
            self::setSetting($key, $origUsed);
            pq('UPDATE tenants SET ai_credits = ? WHERE slug = ?', [$origCredits, TENANT]);
        }
    }

    public function testZeroLimitMeansUnlimited(): void
    {
        $orig = self::psetRaw('lim_premium_ai');
        $key = 'ai_used_' . self::ym();
        $origUsed = setting($key) === '' ? null : setting($key);
        try {
            pset_save('lim_premium_ai', '0');
            $this->assertSame(0, ai_settings()['limit']);
            self::setSetting($key, '99999');
            $this->assertNull(ai_quota_error());
        } finally {
            self::restorePset('lim_premium_ai', $orig);
            self::setSetting($key, $origUsed);
        }
    }

    public function testTokenTrackingAndCost(): void
    {
        $in = 'ai_tin_' . self::ym();
        $out = 'ai_tout_' . self::ym();
        $orig = [$in => setting($in) === '' ? null : setting($in), $out => setting($out) === '' ? null : setting($out)];
        $origPrice = ['ai_price_in' => self::psetRaw('ai_price_in'), 'ai_price_out' => self::psetRaw('ai_price_out')];
        try {
            self::setSetting($in, null);
            self::setSetting($out, null);
            ai_track_tokens((object) ['usage' => (object) ['inputTokens' => 100, 'outputTokens' => 30, 'cacheCreationInputTokens' => 5, 'cacheReadInputTokens' => 7]]);
            ai_track_tokens((object) ['usage' => (object) ['inputTokens' => 10, 'outputTokens' => 3]]);
            setting('__reset');
            $this->assertSame(122, (int) setting($in), 'input incl. cache creation and cache reads');
            $this->assertSame(33, (int) setting($out));

            pset_save('ai_price_in', '75,5');
            pset_save('ai_price_out', '375');
            $this->assertSame(75.5, ai_token_price('ai_price_in'));
            $this->assertEqualsWithDelta(75.5 + 187.5, ai_cost_czk(1000000, 500000), 1e-9);
            $this->assertSame(0.0, ai_cost_czk(0, 0));
            pset_save('ai_price_in', '-3');
            $this->assertSame(0.0, ai_token_price('ai_price_in'), 'negative price is ignored');
            pset_save('ai_price_in', 'nesmysl');
            $this->assertSame(0.0, ai_token_price('ai_price_in'));
        } finally {
            foreach ($orig as $k => $v) {
                self::setSetting($k, $v);
            }
            foreach ($origPrice as $k => $v) {
                self::restorePset($k, $v);
            }
        }
    }

    public function testCzkFormatting(): void
    {
        $nb = "\u{a0}";
        $this->assertSame("0,00{$nb}Kč", ai_czk(0.0));
        $this->assertSame("12,35{$nb}Kč", ai_czk(12.345));
        $this->assertSame("99,99{$nb}Kč", ai_czk(99.99));
        $this->assertSame("100{$nb}Kč", ai_czk(100.0));
        $this->assertSame("1{$nb}235{$nb}Kč", ai_czk(1234.5));
    }

    // ---------- Přepis schůze ----------

    public function testTranscriptText(): void
    {
        $dir = sys_get_temp_dir() . '/svj-ai-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $vtt = "WEBVTT\n\nNOTE poznámka\n\n1\n00:00:01.000 --> 00:00:03.000\n<v Jana Nováková>Dobrý den, zahajuji.</v>\n\n2\n00:00:03.000 --> 00:00:04.000\n<v Jana Nováková>Dobrý den, zahajuji.</v>\n\n"
                . "3f2a1b9c-1234-5678-9abc-def012345678/12-0\n00:00:05.000 --> 00:00:06.000\nPetr &amp; Eva: souhlas\n";
            file_put_contents("$dir/a.vtt", $vtt);
            $this->assertSame(['text' => "Jana Nováková: Dobrý den, zahajuji.\nPetr & Eva: souhlas"], transcript_text("$dir/a.vtt", 'zaznam.VTT'), 'timestamps, numbers, ids and repeated lines removed');

            file_put_contents("$dir/b.srt", "1\r\n00:00:01,000 --> 00:00:02,000\r\nBod 1\r\n\r\n2\r\n00:00:02,000 --> 00:00:03,000\r\nBod 2\r\n");
            $this->assertSame(['text' => "Bod 1\nBod 2"], transcript_text("$dir/b.srt", 'b.srt'));

            file_put_contents("$dir/c.txt", "\xEF\xBB\xBFPrvní\t\t řádek\r\n\r\n\r\n\r\nDruhý");
            $this->assertSame(['text' => "První řádek\n\nDruhý"], transcript_text("$dir/c.txt", 'c.txt'), 'BOM removed, spaces and blank lines collapsed');

            file_put_contents("$dir/e.txt", "\xFF\xFE" . mb_convert_encoding('Ahoj', 'UTF-16LE', 'UTF-8'));
            $this->assertSame(['text' => 'Ahoj'], transcript_text("$dir/e.txt", 'e.txt'));

            $zip = new \ZipArchive();
            $zip->open("$dir/f.docx", \ZipArchive::CREATE);
            $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Jana:</w:t><w:tab/><w:t>Vítám &quot;vás&quot;</w:t></w:r></w:p><w:p><w:r><w:t>Konec</w:t></w:r></w:p></w:body></w:document>');
            $zip->close();
            $this->assertSame(['text' => "Jana: Vítám \"vás\"\nKonec"], transcript_text("$dir/f.docx", 'f.docx'));

            file_put_contents("$dir/g.mp3", 'ID3');
            $this->assertStringStartsWith('Nahrajte přepis jako .txt', transcript_text("$dir/g.mp3", 'g.mp3')['error']);
            file_put_contents("$dir/h.txt", "  \n\n ");
            $this->assertSame(['error' => 'V souboru jsme nenašli žádný text.'], transcript_text("$dir/h.txt", 'h.txt'));
            file_put_contents("$dir/i.docx", 'not a zip');
            $this->assertSame(['error' => 'Soubor se nepodařilo přečíst. Uložte přepis jako prostý text (.txt).'], transcript_text("$dir/i.docx", 'i.docx'));

            file_put_contents("$dir/j.txt", str_repeat('slovo ', 20000));   // 120 000 znaků
            $r = transcript_text("$dir/j.txt", 'j.txt');
            $this->assertSame(110000, mb_strlen($r['text']));
            $this->assertStringContainsString('110 000', $r['note']);
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function testTranscriptInWindows1250(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'tr');
        file_put_contents($f, iconv('UTF-8', 'Windows-1250', 'Žluťoučký kůň'));
        try {
            $this->assertSame(['text' => 'Žluťoučký kůň'], transcript_text($f, 'zapis.txt'));
        } finally {
            unlink($f);
        }
    }

    // ---------- ai_run s náhradním klientem (samostatný proces, bez sítě) ----------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRunValidatesInputBeforeCallingService(): void
    {
        self::fakeSdk();
        $this->assertSame('sk-ant-test-123', ai_settings()['key'], 'key is trimmed');
        $this->assertTrue(ai_available());
        $this->assertSame(['error' => 'Neznámý úkol.'], ai_run('hack', 'x'));
        $this->assertSame(['error' => 'Nejdřív napište text nebo aspoň pár poznámek.'], ai_run('draft', " \n "));
        $this->assertSame(['error' => 'Text je na pomocníka příliš dlouhý (nejvýše 20 000 znaků). Zkraťte ho, nebo ho zpracujte po částech.'],
            ai_run('draft', str_repeat('á', 19990), str_repeat('b', 11)), 'input + context over 20 000 characters');
        $this->assertSame(['error' => 'Text je na pomocníka příliš dlouhý (nejvýše 120 000 znaků). Zkraťte ho, nebo ho zpracujte po částech.'],
            ai_run('minutes', str_repeat('a', 120001)));
        $this->assertSame([], FakeAiClient::$calls, 'nothing sent to the service');

        $this->assertArrayHasKey('text', ai_run('draft', str_repeat('á', 19990), str_repeat('b', 10)), 'exactly 20 000 is fine');
        $this->assertArrayHasKey('text', ai_run('minutes', str_repeat('a', 100000)));
        $this->assertArrayHasKey('text', ai_run('reply', '', 'Hlášení: kape voda'), 'reply may have empty notes');
        $this->assertArrayHasKey('text', ai_run('invite', '', 'Shromáždění 1. 3.'));

        // Limit: po vyčerpání se služba nevolá.
        q("INSERT INTO settings (`key`, value) VALUES (?, ?) ON CONFLICT(`key`) DO UPDATE SET value = excluded.value", ['ai_used_' . self::ym(), (string) ai_settings()['limit']]);
        setting('__reset');
        $n = count(FakeAiClient::$calls);
        $this->assertStringStartsWith('Měsíční limit AI', ai_run('draft', 'poznámky')['error']);
        $this->assertCount($n, FakeAiClient::$calls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRunBuildsPromptCountsUseAndTracksTokens(): void
    {
        self::fakeSdk();
        $this->assertSame(0, ai_used());

        $r = ai_run('draft', "  Odstávka vody\n", 'Termín: 3. 12. 2026');
        $this->assertSame(['text' => 'Hotový text', 'truncated' => false], $r);
        $call = end(FakeAiClient::$calls);
        $this->assertSame('sk-ant-test-123', FakeAiClient::$ctor['apiKey']);
        $this->assertNull(FakeAiClient::$ctor['baseUrl']);
        $prompt = $call['messages'][0]['content'];
        $this->assertSame('user', $call['messages'][0]['role']);
        $this->assertSame(AI_TASKS['draft'][1] . "\n\nSpolečenství: " . site('full') . "\n\n<kontext>\nTermín: 3. 12. 2026\n</kontext>\n\n<text>\nOdstávka vody\n</text>", $prompt);
        $this->assertSame(AI_SYSTEM, $call['system']);
        $this->assertSame(16000, $call['maxTokens']);
        $this->assertSame(['effort' => 'low'], $call['outputConfig']);
        $this->assertSame(ai_settings()['model'], $call['model']);
        $this->assertSame('default', $call['fallbacks']);
        $this->assertNull($call['requestOptions']);
        $this->assertSame(1, ai_used());
        setting('__reset');
        $this->assertSame(120, (int) setting('ai_tin_' . self::ym()));
        $this->assertSame(40, (int) setting('ai_tout_' . self::ym()));

        // Uhlazení a zkrácení: údaje z formuláře (kontext) se nepošlou, aby je model nedoplnil do textu.
        foreach (['polish', 'shorten'] as $task) {
            ai_run($task, 'Text k úpravě', 'Datum: 1. 1. 2027, místo: sklep');
            $prompt = end(FakeAiClient::$calls)['messages'][0]['content'];
            $this->assertStringNotContainsString('<kontext>', $prompt, $task);
            $this->assertStringNotContainsString('sklep', $prompt, $task);
            $this->assertStringStartsWith(AI_TASKS[$task][1], $prompt);
        }
        ai_run('program', 'zahájení, rozpočet', 'Shromáždění 24. 2. 2027 v 18:00, sušárna');
        $prompt = end(FakeAiClient::$calls)['messages'][0]['content'];
        $this->assertStringContainsString("<kontext>\nShromáždění 24. 2. 2027 v 18:00, sušárna\n</kontext>", $prompt, 'program keeps context');
        $this->assertStringContainsString('Datum, čas ani místo do programu nepiš', $prompt);

        ai_run('minutes', 'poznámky ze schůze');
        $this->assertSame(['effort' => 'medium'], end(FakeAiClient::$calls)['outputConfig'], 'minutes think longer');
        $this->assertSame(5, ai_used());

        FakeAiClient::$reply = ['stop' => 'max_tokens', 'text' => 'Useknutý', 'in' => 1, 'out' => 1];
        $this->assertSame(['text' => 'Useknutý', 'truncated' => true], ai_run('draft', 'x'));
        $this->assertSame(6, ai_used());
    }

    /** Obyvatel smí jen úpravu vlastního textu a inzerát; údaje z formuláře (kontext) jdou jen k inzerátu. */
    public function testResidentTaskList(): void
    {
        $this->assertSame(['polish', 'shorten', 'listing', 'fb'], AI_RESIDENT_TASKS);
        $this->assertStringContainsString('[doplnit: …]', AI_TASKS['listing'][1]);
        $this->assertStringContainsString('nic nevymýšlej', AI_TASKS['listing'][1]);
        $this->assertStringContainsString('nejvýše 600 znaků', AI_TASKS['fb'][1]);
        $this->assertStringContainsString('bez emoji', AI_TASKS['fb'][1]);
        $this->assertSame('Napsat inzerát', AI_TASKS['listing'][0]);
        $this->assertSame('Zkrátit pro Facebook', AI_TASKS['fb'][0]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResidentRunPassesFormFactsOnlyForListing(): void
    {
        self::fakeSdk();
        self::setSetting('ai_enabled', '1');
        self::setSetting('ai_residents', '1');
        $u = one("SELECT * FROM users WHERE role = 'resident' AND active = 1 ORDER BY id LIMIT 1");
        $this->assertSame(['error' => 'Tento úkol pomocníka je jen pro správce.'], ai_run_resident($u, 'draft', 'text', 'Typ: prodej'));
        $this->assertSame(['error' => 'Tento úkol pomocníka je jen pro správce.'], ai_run_resident($u, 'minutes', 'text'));

        $this->assertSame(['text' => 'Hotový text', 'truncated' => false], ai_run_resident($u, 'listing', 'slunný byt, sklep', "Typ inzerátu: prodej\nDispozice: 2+kk"));
        $prompt = end(FakeAiClient::$calls)['messages'][0]['content'];
        $this->assertStringStartsWith(AI_TASKS['listing'][1], $prompt);
        $this->assertStringContainsString("<kontext>\nTyp inzerátu: prodej\nDispozice: 2+kk\n</kontext>", $prompt);

        // Příspěvek pro Facebook jde napsat i z prázdného pole – text inzerátu je v kontextu.
        $this->assertArrayHasKey('text', ai_run_resident($u, 'fb', '', 'Text inzerátu: Prodám byt.'));
        $this->assertStringContainsString('Text inzerátu: Prodám byt.', end(FakeAiClient::$calls)['messages'][0]['content']);

        // Uhlazení dál pracuje jen s textem v poli.
        ai_run_resident($u, 'polish', 'Muj text', 'Cena: 5 000 000');
        $prompt = end(FakeAiClient::$calls)['messages'][0]['content'];
        $this->assertStringNotContainsString('<kontext>', $prompt);
        $this->assertStringNotContainsString('5 000 000', $prompt);
        $this->assertSame(3, (int) setting('ai_res_' . (int) $u['id'] . '_' . date('Y-m-d')), 'počítá se do denního limitu obyvatele');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRunRefusalAndEmptyAnswerDoNotCount(): void
    {
        self::fakeSdk();
        FakeAiClient::$reply = ['stop' => 'refusal', 'text' => '', 'in' => 5, 'out' => 0];
        $this->assertSame(['error' => 'Tento text pomocník zpracovat odmítl. Zkuste ho přeformulovat, nebo ho napište ručně.'], ai_run('draft', 'x'));
        FakeAiClient::$reply = ['stop' => 'end_turn', 'text' => "  \n ", 'in' => 5, 'out' => 1];
        $this->assertSame(['error' => 'Pomocník nevrátil žádný text. Zkuste to znovu.'], ai_run('draft', 'x'));
        $this->assertSame(0, ai_used());
        FakeAiClient::$reply = ['throw' => new \RuntimeException('boom')];
        $this->assertSame(['error' => 'AI pomocník teď není dostupný.'], ai_run('draft', 'x'));
        $this->assertSame(0, ai_used());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRunNotInFreeEdition(): void
    {
        self::fakeSdk();
        pq("UPDATE tenants SET billing = 1, plan = 'free', paid_until = '', trial_ends = '' WHERE slug = ?", [TENANT]);
        $this->assertSame('free', plan_edition());
        $this->assertSame(['error' => 'AI pomocník je součástí edice Standard. Vyzkoušet ho můžete v části Předplatné.'], ai_run('draft', 'poznámky'));
        $this->assertSame([], FakeAiClient::$calls);
        $this->assertSame((int) pset_int('lim_free_ai', 0, 100000), ai_settings()['limit'], 'free edition limit');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWebsiteCanSwitchAssistantOff(): void
    {
        self::fakeSdk();
        q("INSERT INTO settings (`key`, value) VALUES ('ai_enabled', '0') ON CONFLICT(`key`) DO UPDATE SET value = excluded.value");
        setting('__reset');
        $this->assertTrue(ai_configured(), 'server is configured');
        $this->assertFalse(ai_available(), 'but this website turned it off');
        $this->assertSame(['error' => 'AI pomocník není na tomto webu zapnutý.'], ai_run('draft', 'x'));
    }
}

/** Náhradní klient knihovny Anthropic: zapíše parametry požadavku a vrátí připravenou odpověď (žádná síť). */
final class FakeAiClient
{
    public static array $calls = [];
    public static array $ctor = [];
    public static array $reply = [];
    public object $beta;

    public function __construct(string $apiKey = '', ?string $baseUrl = null)
    {
        self::$ctor = ['apiKey' => $apiKey, 'baseUrl' => $baseUrl];
        $this->beta = new \stdClass();
        $this->beta->messages = new FakeAiMessages();
    }
}

final class FakeAiMessages
{
    public function create(mixed ...$args): object
    {
        FakeAiClient::$calls[] = $args;
        $r = FakeAiClient::$reply;
        if (isset($r['throw'])) {
            throw $r['throw'];
        }
        return (object) ['stopReason' => $r['stop'], 'content' => [(object) ['type' => 'thinking', 'thinking' => 'ignored'], (object) ['type' => 'text', 'text' => $r['text']]],
            'usage' => (object) ['inputTokens' => $r['in'], 'outputTokens' => $r['out']]];
    }
}
