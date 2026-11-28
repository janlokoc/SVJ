<?php
declare(strict_types=1);

// Edice služby (Zdarma, Standard, Premium): limity, funkce podle edice a zkoušení placených funkcí na 30 dní.
// Web mimo platformu a web „bez placení“ (domluvený s provozovatelem) nemá žádné omezení.

const EDITIONS = ['free' => 'Zdarma', 'standard' => 'Standard', 'premium' => 'Premium'];
const EDITION_RANK = ['free' => 0, 'standard' => 1, 'premium' => 2];
const PLAN_TRIAL_DAYS = 30;

/** Limity edic: klíč => [název, jednotka]. Hodnoty jsou v Nastavení služby (lim_<edice>_<klíč>), 0 = bez omezení. */
const PLAN_LIMITS = [
    'admins' => ['Správci webu', ''],
    'polls' => ['Hlasování ročně', ''],
    'users' => ['Účty obyvatel', ''],
    'mails' => ['Hromadné e-maily měsíčně', ''],
    'ai' => ['Použití AI měsíčně', ''],
    'storage' => ['Úložiště dokumentů', 'MB'],   // poslední: v ceníku i přehledech se ukazuje na konci
];
const PLAN_LIMIT_DEFAULTS = [
    'free' => ['admins' => 1, 'users' => 20, 'mails' => 30, 'storage' => 5, 'ai' => 5, 'polls' => 1],
    'standard' => ['admins' => 3, 'users' => 150, 'mails' => 500, 'storage' => 100, 'ai' => 30, 'polls' => 5],
    'premium' => ['admins' => 0, 'users' => 0, 'mails' => 0, 'storage' => 500, 'ai' => 300, 'polls' => 0],
];

/**
 * Funkce, které nejsou v edici Zdarma: klíč => [nejnižší edice, název, popis, vzor adres, které k funkci patří].
 * Vzor bez adres (null) znamená, že se funkce hlídá jinde (šablony, jazyky).
 */
const PLAN_FEATURES = [
    'ankety' => ['standard', 'Ankety', 'Rychlé zjištění názoru sousedů – termín brigády, barva fasády, zájem o kolárnu.', '~^/(admin/)?ankety(/|$)~'],
    'platby' => ['premium', 'Platby na jednotku', 'Každý vlastník vidí své zálohy a nedoplatky, výbor posílá upomínky.', '~^/(admin/)?platby(/|$)~'],
    'push' => ['standard', 'Upozornění do telefonu', 'Nové aktuality, zprávy od výboru a sousedů, změny u závad a měsíční spotřeba přímo na displej telefonu – web přidaný na plochu funguje jako aplikace.', null],
    'odecty' => ['standard', 'Odečty měřidel', 'Dálkové odečty od odečtové firmy (import i automatický příjem), měsíční spotřeba pro obyvatele a ruční samoodečty ostatních měřidel.', '~^/(admin/)?odecty(/|$)~'],
    'rezervace' => ['premium', 'Rezervace', 'Rezervace společenské místnosti, sušárny nebo parkovacího místa.', '~^/(admin/)?(rezervace|prostory)(/|$)~'],
    'api' => ['standard', 'API a napojení', 'Napojení dodavatelů (odečtová firma, účetní program, správce) a AI asistentů přes API a MCP.', '~^/admin/api(/|$)~'],
    'revize' => ['standard', 'Revize', 'Hlídání termínů revizí a kontrol s upozorněním předem.', '~^/admin/revize(/|$)~'],
    'klice' => ['standard', 'Klíče a čipy', 'Evidence, kdo má který klíč a čip.', '~^/admin/klice(/|$)~'],
    'ukoly' => ['standard', 'Úkoly a usnesení', 'Co kdo slíbil zařídit a do kdy – úkoly z jednání výboru a shromáždění.', '~^/admin/ukoly(/|$)~'],
    'dodavatele' => ['standard', 'Dodavatelé', 'Kontakty a smlouvy s dodavateli, přiřazení k závadám.', '~^/admin/dodavatele(/|$)~'],
    'zapis' => ['standard', 'Zápis ze shromáždění', 'Zápis sestavený z účasti, programu a výsledků hlasování, připravený k tisku.', '~^/admin/terminy/\d+/zapis$~'],
    'zaloha' => ['standard', 'Záloha dat ke stažení', 'Úplná záloha webu včetně dokumentů ke stažení a obnovení.', '~^/admin/zaloha(/|$)~'],
    'sablony' => ['standard', 'Šablony, barvy a logo', 'Tři šablony a tři barvy webu a vlastní logo. Edice Zdarma má jeden pevný vzhled bez přizpůsobení.', null],
    'vzhledy-plus' => ['premium', 'Deset šablon a deset barev', 'Všech deset šablon (navíc Novinová, Vlídná, Cedule, Panelák, Činžák, Čitelná a Vývěska) a deset barevných variant.', null],
    'jazyky' => ['premium', 'Jazykové verze', 'Web i ve slovenštině, angličtině, ukrajinštině a vietnamštině.', null],
    'ai-pomocnik' => ['standard', 'AI pomocník pro výbor', 'Z poznámek napíše oznámení, e-mail, pozvánku, program nebo návrhy usnesení, text uhladí či zkrátí a navrhne odpověď na závadu.', null],
    'ai-asistent' => ['premium', 'AI asistent', 'Obyvatelé i výbor se ptají běžnou řečí a dostanou odpověď podle stanov, domovního řádu, dokumentů a oznámení domu – s odkazy na zdroje.', '~^/asistent(/|$)~'],
    'ai-zapis' => ['premium', 'AI zápis z poznámek a přepisu', 'Pomocník napíše průběh jednání z poznámek nebo nahraného přepisu schůze.', '~^/admin/prepis$~'],
    'vyuctovani' => ['standard', 'Vyúčtování služeb', 'Vyúčtování jednotek na webu: vlastníci potvrdí převzetí, podávají námitky, výbor hlídá zákonné lhůty.', '~^/(admin/)?vyuctovani(/|$)~'],
    'smlouvy' => ['standard', 'Smlouvy a pojištění', 'Evidence smluv a pojištění s připomínkou výpovědní lhůty a konce smlouvy.', '~^/admin/smlouvy(/|$)~'],
    'plan-oprav' => ['premium', 'Plán oprav a fond oprav', 'Plánované opravy a výhled fondu oprav na 10–20 let s návrhem, o kolik zvýšit příspěvky.', '~^/(admin/)?plan-oprav(/|$)~'],
    'ucetnictvi-export' => ['standard', 'Export do účetnictví', 'Vlastníci, předpisy a platby jedním kliknutím do POHODY, Money S3, ABRA Flexi nebo CSV. Import jednotek z tabulky je ve všech edicích.', '~^/admin/ucetnictvi/export$~'],
];

function plan_managed(): bool
{
    return function_exists('platform_enabled') && platform_enabled() && defined('TENANT') && TENANT !== '';
}
/** Řádek společenství z databáze platformy (jednou za požadavek). */
function plan_tenant(): ?array
{
    static $t = false;
    if ($t === false) {
        $t = plan_managed() ? tenant_row(TENANT) : null;
    }
    return $t;
}
/** Edice, kterou má web právě teď. Web bez placení a ukázkový web nemají omezení. */
function tenant_edition(?array $t): string
{
    if (!empty($t['demo'])) {
        // Ukázka: návštěvník si může přepnout, jak web vypadá v jednotlivých edicích (jen ve své relaci).
        $chosen = (string) ($_SESSION['demo_edition'] ?? '');
        return isset(EDITIONS[$chosen]) ? $chosen : 'premium';
    }
    if (!$t || empty($t['billing'])) {
        return 'premium';
    }
    $today = date('Y-m-d');
    $grace = date('Y-m-d', strtotime('-' . pset_int('grace_days', 0, 365) . ' day'));
    $paid = $t['paid_until'] !== '' && $t['paid_until'] >= $grace ? (isset(EDITIONS[$t['plan'] ?? '']) && $t['plan'] !== 'free' ? $t['plan'] : 'standard') : 'free';
    // Zkouška edice (od 11/2026 Standard nebo Premium na 30 dní, jednou za web); starší weby se zkušební dobou mají Standard.
    $trial = $t['trial_ends'] !== '' && $t['trial_ends'] >= $today ? (in_array($t['trial_plan'] ?? '', ['standard', 'premium'], true) ? $t['trial_plan'] : 'standard') : 'free';
    return EDITION_RANK[$trial] > EDITION_RANK[$paid] ? $trial : $paid;
}
function plan_edition(): string
{
    return plan_managed() ? tenant_edition(plan_tenant()) : 'premium';
}
function plan_limit_of(string $edition, string $key): int
{
    return function_exists('pset_int') && plan_managed() ? pset_int('lim_' . $edition . '_' . $key, 0, 10000000) : 0;
}
/** Limit platný pro tento web; 0 = bez omezení. */
function plan_limit(string $key): int
{
    if (!plan_managed()) {
        return 0;
    }
    $limit = plan_limit_of(plan_edition(), $key);
    // K limitu úložiště se přičítají zaplacené balíčky navíc.
    // Zaplacené jednorázové hlasování přidá jedno hlasování nad limit (app/ext/pricing_lib.php).
    $limit += $key === 'polls' && $limit > 0 && function_exists('vote_extra_polls') ? vote_extra_polls(plan_tenant()) : 0;
    return $key === 'storage' && $limit > 0 ? $limit + storage_extra_mb(plan_tenant()) : $limit;
}
function plan_storage_mb(): float
{
    $bytes = 0;
    $dir = STORAGE_DIR . '/uploads';
    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            $bytes += $f->isFile() ? $f->getSize() : 0;
        }
    }
    return $bytes / 1048576;
}
/** Kolik z limitu je vyčerpáno. */
function plan_used(string $key): int
{
    return match ($key) {
        // Pracovníci správcovské firmy (partnera) se do limitu správců nepočítají.
        'admins' => max(0, (int) one("SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND active = 1")['n'] - (($pids = partner_tenant_user_ids()) ? (int) one("SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND active = 1 AND id IN (" . implode(',', $pids) . ')')['n'] : 0)),
        'users' => (int) one('SELECT COUNT(*) AS n FROM users WHERE active = 1')['n'],
        'mails' => (int) one('SELECT COALESCE(SUM(sent_count), 0) AS n FROM mailings WHERE sent_at >= ?', [date('Y-m-01 00:00:00')])['n'],
        'storage' => (int) ceil(plan_storage_mb()),
        'ai' => function_exists('ai_used') ? ai_used() : 0,
        'polls' => (int) one('SELECT COUNT(*) AS n FROM polls WHERE opens_at >= ?', [date('Y-01-01')])['n'],
        default => 0,
    };
}
/** Hláška, pokud by přidání $add kusů překročilo limit; jinak null. */
function plan_limit_error(string $key, int $add = 1): ?string
{
    $limit = plan_limit($key);
    if ($limit === 0 || plan_used($key) + $add <= $limit) {
        return null;
    }
    $what = match ($key) {
        'admins' => 'správců webu',
        'users' => 'účtů',
        'mails' => 'hromadných e-mailů za měsíc',
        'storage' => 'MB dokumentů',
        'polls' => 'hlasování za rok',
        default => 'použití',
    };
    $next = plan_edition() === 'free' ? 'Standard' : 'Premium';
    if ($key === 'storage') {
        return 'Úložiště je plné: využito ' . number_format(plan_used($key), 0, ',', "\u{a0}") . ' z ' . number_format($limit, 0, ',', "\u{a0}") . ' MB. Nic se nemaže a web běží dál – místo uvolníte smazáním starých souborů, nebo si v části Předplatné přikoupíte úložiště navíc či přejdete na vyšší edici.';
    }
    return 'Edice ' . EDITIONS[plan_edition()] . ' má limit ' . number_format($limit, 0, ',', "\u{a0}") . ' ' . $what . ' (využito ' . number_format(plan_used($key), 0, ',', "\u{a0}")
        . '). Nic se nemaže a web běží dál – pro vyšší limit přejděte v části Předplatné na edici ' . $next . '.';
}

/**
 * Funkce přesunuté ze Standardu do Premium: klíč => den přesunu. Web, který si Standard zaplatil před tímto dnem,
 * je má dál až do konce tehdy zaplaceného období.
 */
const PLAN_MOVED = ['platby' => '2026-11-22', 'rezervace' => '2026-11-22', 'jazyky' => '2026-11-22'];
function plan_kept(string $feature): bool
{
    static $paid = null;
    if (!isset(PLAN_MOVED[$feature]) || plan_edition() !== 'standard') {
        return false;
    }
    if ($paid === null) {
        $paid = (string) pq("SELECT MIN(paid_at) FROM payments WHERE tenant = ? AND kind = 'service' AND status = 'paid' AND (item = 'standard' OR item = '') AND period_to >= ?", [TENANT, date('Y-m-d')])->fetchColumn();
    }
    return $paid !== '' && substr($paid, 0, 10) < PLAN_MOVED[$feature];
}
/** Zkoušení funkcí tohoto webu: klíč => ['started' => …, 'ends' => …]. */
function plan_trials(bool $reload = false): array
{
    static $cache = null;
    if ($cache === null || $reload) {
        $cache = [];
        if (plan_managed()) {
            foreach (pq('SELECT feature, started, ends FROM feature_trials WHERE tenant = ?', [TENANT])->fetchAll() as $r) {
                $cache[$r['feature']] = $r;
            }
        }
    }
    return $cache;
}
/**
 * Stav placené funkce: 'on' (je v edici), 'trial' (běží zkouška), 'available' (jde vyzkoušet), 'expired' (zkouška skončila – jen pro čtení).
 */
function plan_state(string $feature): string
{
    if (!isset(PLAN_FEATURES[$feature]) || !plan_managed() || EDITION_RANK[plan_edition()] >= EDITION_RANK[PLAN_FEATURES[$feature][0]] || plan_kept($feature)) {
        return 'on';
    }
    $trial = plan_trials()[$feature] ?? null;
    if (!$trial) {
        return 'available';
    }
    return $trial['ends'] >= date('Y-m-d') ? 'trial' : 'expired';
}
function plan_has(string $feature): bool
{
    return in_array(plan_state($feature), ['on', 'trial'], true);
}
/** Spustí zkoušku funkce; každou jde vyzkoušet jednou. */
function plan_trial_start(string $feature): bool
{
    if (plan_state($feature) !== 'available') {
        return false;
    }
    pq('INSERT INTO feature_trials (tenant, feature, started, ends) VALUES (?,?,?,?)', [TENANT, $feature, date('Y-m-d'), date('Y-m-d', strtotime('+' . PLAN_TRIAL_DAYS . ' day'))]);
    plan_trials(true);
    return true;
}
/** Funkce, ke které adresa patří (nebo null). */
function plan_feature_for(string $path): ?string
{
    foreach (PLAN_FEATURES as $key => $f) {
        if ($f[3] !== null && preg_match($f[3], $path)) {
            return $key;
        }
    }
    return null;
}
/** Cena edice za rok (bez DPH). Platí se jen ročně. $size = společenství nebo počet jednotek: nad paušál se přičte přirážka za jednotku (app/ext/pricing_lib.php). */
function edition_price(string $edition, array|int|null $size = null): int
{
    $base = $edition === 'free' ? 0 : pset_int('price_' . $edition, 0, 1000000);
    return $base && $size !== null && function_exists('edition_surcharge') ? $base + edition_surcharge($edition, is_array($size) ? tenant_unit_count($size) : $size) : $base;
}

// ---------- Beta program: nové funkce nejdřív pro edici Premium ----------

/**
 * Funkce, které provozovatel spouští postupně: klíč => [název, adresa v administraci, starý přepínač (zapnuto = spuštěno)].
 * Stav nastavuje konzole (Nastavení služby → Nové funkce): off = vypnuto, beta = jen edice Premium se štítkem Beta, on = spuštěno.
 */
const BETA_FEATURES = [
    'insolvence' => ['Hlídání insolvencí vlastníků', '/admin/insolvence', 'isir_enabled'],
    'sms' => ['SMS zprávy vlastníkům', '/admin/sms', ''],   // Modul C: placené SMS přes bránu (GoSMS/BulkGate), kredit v konzoli /sms
    'bank-psd2' => ['Propojení s účty u dalších bank (PSD2)', '/admin/platby/banky', ''],   // Modul C: GoCardless Bank Account Data
    'shromazdeni-online' => ['Shromáždění online a hybridně', '/admin/shromazdeni-online', ''],
    'katastr' => ['Sledování změn v katastru (WSDP ČÚZK, placené)', '/admin/rejstriky/katastr', ''],
    'exekuce' => ['Lustrace v Centrální evidenci exekucí (placené)', '/admin/rejstriky/exekuce', ''],
    'sso' => ['Přihlášení přes Google, Microsoft, Apple a Facebook', '/muj-ucet#sso', ''],
    'bankid' => ['Hlasování s ověřením přes Bank iD (per rollam)', '/admin/hlasovani', ''],
];
const BETA_STAGES = ['off' => 'vypnuto', 'beta' => 'beta – jen Premium', 'on' => 'spuštěno'];

function beta_stage(string $key): string
{
    if (!isset(BETA_FEATURES[$key]) || !function_exists('pset') || !function_exists('platform_enabled') || !platform_enabled()) {
        return 'on';   // samostatná instalace bez platformy: vše zapnuté
    }
    $s = pset('beta_' . $key);
    if (isset(BETA_STAGES[$s])) {
        return $s;
    }
    $legacy = BETA_FEATURES[$key][2];
    return $legacy !== '' && pset($legacy) === '1' ? 'on' : 'off';
}
/** Edice Premium (nebo web mimo předplatné). */
function beta_premium(): bool
{
    return !function_exists('plan_managed') || !plan_managed() || EDITION_RANK[plan_edition()] >= EDITION_RANK['premium'];
}
/** Vidí toto společenství funkci? Spuštěnou ano (o edici rozhoduje funkce sama), v betě jen Premium. */
function beta_visible(string $key): bool
{
    $s = beta_stage($key);
    return $s === 'on' || ($s === 'beta' && beta_premium());
}
function beta_is(string $key): bool
{
    return beta_stage($key) === 'beta';
}
/** Štítek „ · beta“ k položce menu modulu: když pod její adresou běží funkce v betě, kterou tento web vidí. */
function beta_menu_tag(string $href): string
{
    foreach (BETA_FEATURES as $key => $f) {
        $path = explode('#', (string) $f[1])[0];
        if (($path === $href || str_starts_with($path, $href . '/')) && beta_is($key) && beta_visible($key)) {
            return ' · beta';
        }
    }
    return '';
}
