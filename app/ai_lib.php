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
    'program' => ['Uspořádat program', 'Z textu níže udělej přehledný program akce v domě (shromáždění vlastníků, schůze výboru, brigáda…), jejíž údaje jsou v kontextu. '
        . 'Každý bod na samostatný řádek začínající „- “; upřesnění k bodu (co se schvaluje, kdo předkládá, co si přinést) dej na další řádek bez pomlčky. '
        . 'U shromáždění drž obvyklé pořadí: zahájení a kontrola usnášeníschopnosti na začátku, body k hlasování, diskuze a závěr na konci; jinak pořadí neměň. '
        . 'U bodu, o kterém se hlasuje, stručně a jednoznačně napiš, co se schvaluje. Oprav pravopis a zjednoduš kostrbaté formulace. '
        . 'Tučně (**…**) zvýrazni jen několik opravdu důležitých míst – např. co si mají vlastníci přinést, termín, „Účast nutná“. '
        . 'Nepřidávej body, čísla, částky ani jména, které v textu nejsou; když je vstup jen pár poznámek, udělej z nich body programu.'],
    'resolution' => ['Formulovat návrhy usnesení', 'Z poznámek níže formuluj návrhy usnesení, o kterých budou vlastníci hlasovat (pro / proti / zdržuji se). '
        . 'Každý návrh napiš na jeden samostatný řádek – bez odrážek, číslování a bez jakéhokoli formátování (žádné hvězdičky). '
        . 'Každý začni podle povahy věci „Shromáždění vlastníků schvaluje …“, „… volí …“ nebo „… bere na vědomí …“ a formuluj ho jednoznačně a úplně, aby se dal přijmout nebo odmítnout jako celek: co přesně, s kým, za kolik a do kdy – ale jen to, co je v poznámkách. '
        . 'Různé věci dej do samostatných návrhů. Chybějící podstatný údaj (částka, dodavatel, termín) označ [doplnit: …]. Nic nevymýšlej.'],
    'rationale' => ['Napsat odůvodnění', 'Napiš pro vlastníky úvodní text a odůvodnění k hlasování, jehož název a návrhy usnesení jsou v kontextu: proč se o věci rozhoduje, co návrh obnáší (náklady, termíny, dopady) '
        . 'a co se stane při přijetí a při nepřijetí. Vycházej jen z poznámek níže a z kontextu, nic nedomýšlej. Když jsou v kontextu data hlasování, na konci jednou větou napiš, do kdy hlasovat.'],
    'invite' => ['Napsat pozvánku', 'Napiš e-mailovou pozvánku vlastníkům na akci, jejíž údaje jsou v kontextu (název, datum, čas, místo, program, podklady, odkaz). '
        . 'Datum, čas a místo dej hned na začátek tučně. Je-li v kontextu program, uveď všechny jeho body jako výčet ve stejném pořadí a znění (můžeš jen opravit pravopis). '
        . 'U shromáždění vlastníků připomeň, že kdo nemůže přijít, může se nechat zastoupit na základě plné moci, a že pro usnášeníschopnost je potřeba přítomnost vlastníků s potřebným podílem hlasů. '
        . 'Na podklady a potvrzení účasti odkaž přesně adresou z kontextu – jiné adresy nevymýšlej. Text níže jsou poznámky nebo koncept výboru; co v nich je navíc, zapracuj.'],
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
        $limit = plan_limit('ai');
        if ($plus) {
            $limit = max($limit, pset_int('lim_premium_ai', 0, 100000));   // dříve zaplacený doplněk AI Plus platí do konce období
        }
    }
    // Model zvolený v konzoli platí pro všechny weby i pro zkoušku spojení v konzoli.
    if (platform_enabled() && pset('ai_model') !== '') {
        $model = pset('ai_model');
    }
    // Klíč bez mezer a zalomení řádků kolem (častá chyba při vkládání do config.php).
    // workspace_id je potřeba jen u osobního klíče (sk-ant-usr…), který není vázaný na jeden workspace.
    return ['key' => trim((string) ($c['api_key'] ?? '')), 'model' => $model, 'base_url' => (string) ($c['base_url'] ?? ''), 'limit' => $limit, 'plus' => $plus,
        'workspace' => trim((string) ($c['workspace_id'] ?? ''))];
}
/** Volby požadavku pro knihovnu Anthropic: hlavička workspace u klíčů, které ho samy neurčují. */
function ai_request_options(array $cfg): ?array
{
    return $cfg['workspace'] !== '' ? ['extraHeaders' => ['anthropic-workspace-id' => $cfg['workspace']]] : null;
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
/** Zapíše skutečně spotřebované tokeny (účtuje je Anthropic) k webu a měsíci – kvůli hlídání nákladů. */
function ai_track_tokens($message): void
{
    try {
        $u = $message->usage;
        $in = (int) $u->inputTokens + (int) ($u->cacheCreationInputTokens ?? 0) + (int) ($u->cacheReadInputTokens ?? 0);
        $out = (int) $u->outputTokens;
        foreach (['ai_tin_' => $in, 'ai_tout_' => $out] as $k => $n) {
            q("INSERT INTO settings (`key`, value) VALUES (?, ?) ON CONFLICT(`key`) DO UPDATE SET value = value + ?", [$k . date('Y-m'), (string) $n, $n]);
        }
    } catch (Throwable $e) {
        error_log('SVJ web, AI tokeny: ' . $e->getMessage());
    }
}
/** Odhad nákladu v Kč za dané tokeny podle cen v konzoli (Kč za milion vstupních / výstupních tokenů). */
function ai_cost_czk(int $in, int $out): float
{
    return function_exists('pset') ? ($in * ai_token_price('ai_price_in') + $out * ai_token_price('ai_price_out')) / 1000000 : 0.0;
}
/** Cena tokenů z konzole (Kč za milion, může mít desetinná místa). */
function ai_token_price(string $key): float
{
    return max(0.0, (float) str_replace(',', '.', pset($key)));
}
/** Částka v Kč pro přehled nákladů: malé částky s haléři. */
function ai_czk(float $x): string
{
    return number_format($x, $x < 100 ? 2 : 0, ',', "\u{a0}") . "\u{a0}Kč";
}
/** Zbývající dokoupené AI kredity webu (jen na platformě). */
function ai_credits(): int
{
    return platform_enabled() && defined('TENANT') && TENANT !== '' ? (int) (tenant_row(TENANT)['ai_credits'] ?? 0) : 0;
}
/** Smí web teď použít AI? Nejdřív měsíční limit edice, pak dokoupené kredity. Vrací null, nebo text chyby. */
function ai_quota_error(): ?string
{
    $limit = ai_settings()['limit'];
    if ($limit === 0 || ai_used() < $limit || ai_credits() > 0) {
        return null;
    }
    return 'Měsíční limit AI (' . $limit . ' použití) je vyčerpán. Obnoví se prvního dne příštího měsíce'
        . (platform_enabled() ? ', nebo si v části Předplatné dokupte AI kredity.' : '.');
}
/** Započte jedno použití AI: v rámci měsíčního limitu, po jeho vyčerpání z kreditů. */
function ai_count_use(): void
{
    $limit = ai_settings()['limit'];
    if ($limit > 0 && ai_used() >= $limit && ai_credits() > 0) {
        pq('UPDATE tenants SET ai_credits = ai_credits - 1 WHERE slug = ? AND ai_credits > 0', [TENANT]);
        return;
    }
    q("INSERT INTO settings (`key`, value) VALUES (?, '1') ON CONFLICT(`key`) DO UPDATE SET value = value + 1", ['ai_used_' . date('Y-m')]);
    setting('__reset');
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
    if ($input === '' && !in_array($task, ['reply', 'invite', 'rationale'], true)) {
        return ['error' => 'Nejdřív napište text nebo aspoň pár poznámek.'];
    }
    if (mb_strlen($input) + mb_strlen($context) > ($task === 'minutes' ? 120000 : 20000)) {
        return ['error' => 'Text je na pomocníka příliš dlouhý (nejvýše ' . ($task === 'minutes' ? '120 000' : '20 000') . ' znaků). Zkraťte ho, nebo ho zpracujte po částech.'];
    }
    if ($quota = ai_quota_error()) {
        return ['error' => $quota];
    }
    $prompt = AI_TASKS[$task][1] . "\n\nSpolečenství: " . site('full') . ($context !== '' ? "\n\n<kontext>\n$context\n</kontext>" : '') . "\n\n<text>\n$input\n</text>";
    try {
        $client = new \Anthropic\Client(apiKey: $cfg['key'], baseUrl: $cfg['base_url'] !== '' ? $cfg['base_url'] : null);
        // Když model žádost z bezpečnostních důvodů odmítne, zkusí ji služba sama na náhradním modelu.
        $message = $client->beta->messages->create(maxTokens: 16000, messages: [['role' => 'user', 'content' => $prompt]], model: $cfg['model'], system: AI_SYSTEM,
            fallbacks: 'default', betas: ['server-side-fallback-2026-07-01'], requestOptions: ai_request_options($cfg));
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
    ai_count_use();
    ai_track_tokens($message);
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

