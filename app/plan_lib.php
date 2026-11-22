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
    'users' => ['Účty obyvatel', ''],
    'mails' => ['Hromadné e-maily měsíčně', ''],
    'storage' => ['Úložiště dokumentů', 'MB'],
    'ai' => ['AI pomocník měsíčně', ''],
    'polls' => ['Hlasování ročně', ''],
];
const PLAN_LIMIT_DEFAULTS = [
    'free' => ['admins' => 1, 'users' => 30, 'mails' => 50, 'storage' => 50, 'ai' => 5, 'polls' => 1],
    'standard' => ['admins' => 3, 'users' => 150, 'mails' => 1000, 'storage' => 500, 'ai' => 30, 'polls' => 0],
    'premium' => ['admins' => 0, 'users' => 0, 'mails' => 0, 'storage' => 1000, 'ai' => 300, 'polls' => 0],
];

/**
 * Funkce, které nejsou v edici Zdarma: klíč => [nejnižší edice, název, popis, vzor adres, které k funkci patří].
 * Vzor bez adres (null) znamená, že se funkce hlídá jinde (šablony, jazyky).
 */
const PLAN_FEATURES = [
    'ankety' => ['standard', 'Ankety', 'Rychlé zjištění názoru sousedů – termín brigády, barva fasády, zájem o kolárnu.', '~^/(admin/)?ankety(/|$)~'],
    'platby' => ['premium', 'Platby na jednotku', 'Každý vlastník vidí své zálohy a nedoplatky, výbor posílá upomínky.', '~^/(admin/)?platby(/|$)~'],
    'odecty' => ['standard', 'Odečty měřidel', 'Obyvatelé zapisují stavy vodoměrů a měřičů sami, výbor má přehled.', '~^/(admin/)?odecty(/|$)~'],
    'rezervace' => ['premium', 'Rezervace', 'Rezervace společenské místnosti, sušárny nebo parkovacího místa.', '~^/(admin/)?(rezervace|prostory)(/|$)~'],
    'revize' => ['standard', 'Revize', 'Hlídání termínů revizí a kontrol s upozorněním předem.', '~^/admin/revize(/|$)~'],
    'klice' => ['standard', 'Klíče a čipy', 'Evidence, kdo má který klíč a čip.', '~^/admin/klice(/|$)~'],
    'ukoly' => ['standard', 'Úkoly a usnesení', 'Co kdo slíbil zařídit a do kdy – úkoly z jednání výboru a shromáždění.', '~^/admin/ukoly(/|$)~'],
    'dodavatele' => ['standard', 'Dodavatelé', 'Kontakty a smlouvy s dodavateli, přiřazení k závadám.', '~^/admin/dodavatele(/|$)~'],
    'zapis' => ['standard', 'Zápis ze shromáždění', 'Zápis sestavený z účasti, programu a výsledků hlasování, připravený k tisku.', '~^/admin/terminy/\d+/zapis$~'],
    'zaloha' => ['standard', 'Záloha dat ke stažení', 'Úplná záloha webu včetně dokumentů ke stažení a obnovení.', '~^/admin/zaloha(/|$)~'],
    'sablony' => ['standard', 'Všechny šablony vzhledu', 'Pět šablon webu místo jedné základní.', null],
    'jazyky' => ['premium', 'Jazykové verze', 'Web i ve slovenštině, angličtině, ukrajinštině a vietnamštině.', null],
    'ai-zapis' => ['premium', 'AI zápis z poznámek a přepisu', 'Pomocník napíše průběh jednání z poznámek nebo nahraného přepisu schůze.', '~^/admin/prepis$~'],
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
    if (!$t || empty($t['billing']) || !empty($t['demo'])) {
        return 'premium';
    }
    $today = date('Y-m-d');
    $grace = date('Y-m-d', strtotime('-' . pset_int('grace_days', 0, 365) . ' day'));
    if ($t['paid_until'] !== '' && $t['paid_until'] >= $grace) {
        return isset(EDITIONS[$t['plan'] ?? '']) && $t['plan'] !== 'free' ? $t['plan'] : 'standard';
    }
    // Weby založené dřív se zkušební dobou mají do jejího konce Standard.
    return $t['trial_ends'] !== '' && $t['trial_ends'] >= $today ? 'standard' : 'free';
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
    return plan_managed() ? plan_limit_of(plan_edition(), $key) : 0;
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
        'admins' => (int) one("SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND active = 1")['n'],
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
/** Cena edice za rok, nebo za měsíc: měsíční cena je dvanáctina roční zvýšená o přirážku z nastavení (výchozí 30 %), zaokrouhlená na koruny. */
function edition_price(string $edition, string $period = 'year'): int
{
    $year = $edition === 'free' ? 0 : pset_int('price_' . $edition, 0, 1000000);
    return $period === 'month' ? (int) round($year / 12 * (1 + pset_int('monthly_surcharge', 0, 500) / 100)) : $year;
}
