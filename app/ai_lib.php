<?php
declare(strict_types=1);

// AI pomocník pro výbor: z poznámek napíše návrh oznámení, uhladí nebo zkrátí text, navrhne odpověď na hlášení.
// Je volitelný – funguje jen s nainstalovanou knihovnou Anthropic (composer install) a klíčem v storage/config.php.
// Text, který správce pomocníkovi zadá, se odesílá ke zpracování službě Claude (Anthropic); nic se neposílá samo.

const AI_TASKS = [
    'draft' => ['Napsat z poznámek', 'Z poznámek níže napiš oznámení výboru pro obyvatele domu.'],
    'polish' => ['Uhladit text', 'Uprav text níže: oprav pravopis a překlepy, zjednoduš kostrbaté věty a sjednoť tón. Význam, fakta, čísla ani data neměň a nic nepřidávej.'],
    'shorten' => ['Zkrátit', 'Zkrať text níže zhruba na polovinu. Zachovej všechna data, časy, místa, částky a to, co mají lidé udělat.'],
    'minutes' => ['Napsat zápis z poznámek', 'Z poznámek (nebo přepisu) ze shromáždění vlastníků níže napiš část zápisu „Průběh jednání“. Je to úřední záznam, ne oznámení: piš ve třetí osobě, minulým časem, věcně a úplně. '
        . 'Postupuj podle bodů programu z kontextu; u každého bodu shrň, co zaznělo, kdo co přednesl nebo navrhl (jména jen tak, jak jsou v poznámkách) a k čemu se dospělo. '
        . 'Výsledky hlasování a účast neopisuj – jsou v zápisu uvedeny zvlášť; jen na příslušném místě napiš, že se hlasovalo. Diskuzi shrň, nepřepisuj doslova. '
        . 'Co v poznámkách není, nedoplňuj; nejasné místo označ [ověřit: …]. Každý bod programu začni jeho názvem na samostatném řádku.'],
    'reply' => ['Navrhnout odpověď', 'Napiš krátkou odpověď výboru obyvateli na jeho hlášení uvedené v kontextu. Poznámky níže říkají, co mu má výbor sdělit; když jsou prázdné, potvrď přijetí a napiš, že se výbor ozve.'],
];

const AI_SYSTEM = <<<'TXT'
Pomáháš výboru společenství vlastníků jednotek (SVJ) psát texty na web domu a do e-mailů vlastníkům. Čtou je sousedé různého věku, často na telefonu, a potřebují rychle pochopit, co se děje, kdy, kde a co mají udělat.

Piš česky, věcně a zdvořile, s vykáním (Vy, Vám), bez úřednických frází a bez reklamního nadšení. Nejdůležitější informaci dej na začátek.

Drž se jen toho, co je v zadání. Nevymýšlej data, časy, částky, jména ani důvody – výbor za text ručí. Když zjevně chybí údaj, bez kterého text nedává smysl (například datum nebo čas), napiš na jeho místo [doplnit: co chybí].

Výstup se vloží rovnou do formuláře, proto vrať jen samotný text: bez úvodu, bez vysvětlení, bez nadpisu a bez podpisu. Formátování je jednoduché: odstavce oddělené prázdným řádkem, výčet řádky začínající „- “, zvýraznění **tučně** jen u toho nejdůležitějšího (typicky datum a čas).
TXT;

function ai_settings(): array
{
    $c = (array) (app_config()['ai'] ?? []);
    $model = (string) ($c['model'] ?? 'claude-opus-5-5');
    $limit = (int) ($c['monthly_limit'] ?? 30);
    $plus = false;
    // Na platformě určuje limit hlavní správce: základní počet použití je v ceně, vyšší má web s doplňkem AI Plus.
    if (platform_enabled() && defined('TENANT') && TENANT !== '') {
        $plus = ai_plus_active(tenant_row(TENANT));
        $limit = pset_int($plus ? 'ai_plus_limit' : 'ai_free_limit', 0, 100000);
        $model = pset('ai_model') !== '' ? pset('ai_model') : $model;
    }
    return ['key' => (string) ($c['api_key'] ?? ''), 'model' => $model, 'base_url' => (string) ($c['base_url'] ?? ''), 'limit' => $limit, 'plus' => $plus];
}
/** Knihovna je nainstalovaná (složka vendor vedle index.php). */
function ai_sdk_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
        $ready = class_exists(\Anthropic\Client::class);
    }
    return $ready;
}
/** Pomocník je k dispozici: klíč v nastavení serveru, knihovna na místě a správce webu ho nemá vypnutý. */
/** Na serveru je pomocník nastaven (klíč a knihovna), bez ohledu na to, zda ho konkrétní web má zapnutý. */
function ai_configured(): bool
{
    return ai_settings()['key'] !== '' && ai_sdk_ready();
}
function ai_available(): bool
{
    return ai_settings()['key'] !== '' && setting_on('ai_enabled', true) && ai_sdk_ready();
}
function ai_used(): int
{
    return (int) setting('ai_used_' . date('Y-m'), '0');
}
/** Zavolá Claude a vrátí ['text' => …] nebo ['error' => …]. Chybu vždy vrací jako srozumitelnou větu pro správce. */
function ai_run(string $task, string $input, string $context = ''): array
{
    $cfg = ai_settings();
    if (!isset(AI_TASKS[$task])) {
        return ['error' => 'Neznámý úkol.'];
    }
    if (!ai_available()) {
        return ['error' => 'AI pomocník není na tomto webu zapnutý.'];
    }
    $input = trim($input);
    if ($input === '' && $task !== 'reply') {
        return ['error' => 'Nejdřív napište text nebo aspoň pár poznámek.'];
    }
    if (mb_strlen($input) + mb_strlen($context) > ($task === 'minutes' ? 120000 : 20000)) {
        return ['error' => 'Text je na pomocníka příliš dlouhý (nejvýše ' . ($task === 'minutes' ? '120 000' : '20 000') . ' znaků). Zkraťte ho, nebo ho zpracujte po částech.'];
    }
    if ($cfg['limit'] > 0 && ai_used() >= $cfg['limit']) {
        return ['error' => 'Měsíční limit AI pomocníka (' . $cfg['limit'] . ' použití) je vyčerpán. Obnoví se prvního dne příštího měsíce.'
            . (platform_enabled() && !$cfg['plus'] ? ' Vyšší limit nabízí doplněk AI Plus v části Předplatné.' : '')];
    }
    $prompt = AI_TASKS[$task][1] . "\n\nSpolečenství: " . site('full') . ($context !== '' ? "\n\n<kontext>\n$context\n</kontext>" : '') . "\n\n<text>\n$input\n</text>";
    try {
        $client = new \Anthropic\Client(apiKey: $cfg['key'], baseUrl: $cfg['base_url'] !== '' ? $cfg['base_url'] : null);
        // Když model žádost z bezpečnostních důvodů odmítne, zkusí ji služba sama na náhradním modelu.
        $message = $client->beta->messages->create(maxTokens: 16000, messages: [['role' => 'user', 'content' => $prompt]], model: $cfg['model'], system: AI_SYSTEM,
            fallbacks: 'default', betas: ['server-side-fallback-2026-07-01']);
    } catch (\Anthropic\Core\Exceptions\AuthenticationException | \Anthropic\Core\Exceptions\PermissionDeniedException $e) {
        error_log('SVJ web, AI: ' . $e->getMessage());
        return ['error' => 'Služba odmítla přihlašovací klíč. Zkontrolujte api_key v souboru storage/config.php.'];
    } catch (\Anthropic\Core\Exceptions\RateLimitException | \Anthropic\Core\Exceptions\OverloadedException $e) {
        return ['error' => 'Služba je teď přetížená. Zkuste to prosím za chvíli znovu.'];
    } catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
        error_log('SVJ web, AI: ' . $e->getMessage());
        return ['error' => 'Služba vrátila chybu a text nevznikl. Podrobnosti jsou v protokolu serveru.'];
    } catch (\Anthropic\Core\Exceptions\APIConnectionException $e) {
        error_log('SVJ web, AI: ' . $e->getMessage());
        return ['error' => 'Ke službě se nepodařilo připojit. Zkuste to prosím za chvíli znovu.'];
    } catch (Throwable $e) {
        error_log('SVJ web, AI: ' . $e->getMessage());
        return ['error' => 'AI pomocník teď není dostupný.'];
    }
    if ($message->stopReason === 'refusal') {
        return ['error' => 'Tento text pomocník zpracovat odmítl. Zkuste ho přeformulovat, nebo ho napište ručně.'];
    }
    $text = '';
    foreach ($message->content as $block) {
        if ($block->type === 'text') {
            $text .= $block->text;
        }
    }
    $text = trim($text);
    if ($text === '') {
        return ['error' => 'Pomocník nevrátil žádný text. Zkuste to znovu.'];
    }
    q("INSERT INTO settings (`key`, value) VALUES (?, '1') ON CONFLICT(`key`) DO UPDATE SET value = value + 1", ['ai_used_' . date('Y-m')]);
    return ['text' => $text, 'truncated' => $message->stopReason === 'max_tokens'];
}

/**
 * Text z nahraného přepisu schůze (.txt, .md, .vtt, .srt, .docx) – třeba z Teams, Google Meet
 * nebo z diktafonu v telefonu. U titulkových formátů zahodí časové značky a čísla.
 * Vrací ['text' => …] nebo ['error' => …].
 */
function transcript_text(string $path, string $name): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['txt', 'md', 'vtt', 'srt', 'docx'], true)) {
        return ['error' => 'Nahrajte přepis jako .txt, .vtt, .srt nebo .docx. Zvukovou nahrávku zatím zpracovat neumíme – nechte ji nejdřív přepsat (Teams, Google Meet, diktafon v telefonu).'];
    }
    if (filesize($path) > 5 * 1024 * 1024) {
        return ['error' => 'Soubor je příliš velký (nejvýše 5 MB).'];
    }
    if ($ext === 'docx') {
        if (!class_exists('ZipArchive')) {
            return ['error' => 'Tento server neumí číst soubory Wordu. Uložte přepis jako prostý text (.txt).'];
        }
        $zip = new ZipArchive();
        $xml = $zip->open($path) === true ? $zip->getFromName('word/document.xml') : false;
        if ($xml === false || strlen($xml) > 20 * 1024 * 1024) {
            return ['error' => 'Soubor se nepodařilo přečíst. Uložte přepis jako prostý text (.txt).'];
        }
        $xml = preg_replace(['~<w:tab\b[^>]*/>~', '~<w:br\b[^>]*/>~', '~</w:p>~'], ["\t", "\n", "\n"], $xml);
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    } else {
        $text = (string) file_get_contents($path);
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        } elseif (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-16');
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1250');
        }
        if ($ext === 'vtt' || $ext === 'srt') {
            $lines = [];
            foreach (preg_split('~\R~', $text) as $line) {
                $line = trim($line);
                if ($line === '' || str_contains($line, '-->') || preg_match('~^(WEBVTT|NOTE\b|STYLE$|REGION$|Kind:|Language:|\d+$|[0-9a-f-]{20,}(/\d+-\d+)?$)~i', $line)) {
                    continue;
                }
                // <v Jana Nováková>text</v> → Jana Nováková: text
                $line = preg_replace('~<v\s+([^>]+)>~', '$1: ', $line);
                $line = trim(html_entity_decode(strip_tags($line), ENT_QUOTES, 'UTF-8'));
                if ($line !== '' && $line !== end($lines)) {
                    $lines[] = $line;
                }
            }
            $text = implode("\n", $lines);
        }
    }
    $text = trim(preg_replace(['~[^\S\n]+~u', '~\n{3,}~'], [' ', "\n\n"], str_replace("\r", '', $text)));
    if ($text === '') {
        return ['error' => 'V souboru jsme nenašli žádný text.'];
    }
    if (mb_strlen($text) > 110000) {
        return ['text' => mb_substr($text, 0, 110000), 'note' => 'Přepis je velmi dlouhý, načetli jsme prvních 110 000 znaků. Zbytek zpracujte zvlášť.'];
    }
    return ['text' => $text];
}

