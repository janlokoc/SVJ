<?php
declare(strict_types=1);

/*
 * Modul E – hlídání katastru a rejstříků (placený doplněk edice Premium).
 *
 * - Změny v katastru: dálkový přístup ČÚZK (WSDP 3.1), každých 30 dní výpis LV domu, porovnání s evidencí jednotek (app/ext/rej_katastr_lib.php).
 *   Platí se jako doplněk k ročnímu předplatnému (1 490 Kč za dům vč. 1 LV, další LV 990 Kč; addon_set() v app/platform_lib.php).
 * - Insolvence fyzických osob: ISIR podle jména a data narození (app/ext/rej_isir_lib.php); firmy dál hlídá app/isir_lib.php. V edici Premium bez příplatku.
 * - Exekuce: lustrace v Centrální evidenci exekucí na kliknutí správce (app/ext/rej_exekuce_lib.php), platí se z kreditu služeb.
 *
 * Společné: tabulky v databázi domu (rej_ready), spotřeba placených dotazů v databázi platformy (rej_usage_*),
 * ceny pro zákazníky a náklady provozovatele v Nastavení služby (pset, klíče rej_*), síťové volání přes rej_http()
 * – v unit testech se podstrčí $GLOBALS['REJ_HTTP'], takže nic nevolá síť.
 */

/** Ceny a parametry (konzole → Katastr a rejstříky): klíč => [výchozí hodnota, popis]. Ceny bez DPH. */
const REJ_PRICES = [
    'rej_price_katastr_house' => [1490, 'Sledování katastru – cena pro zákazníka za dům a rok včetně 1 LV (Kč, doplněk k ročnímu předplatnému)'],
    'rej_price_katastr_lv_extra' => [990, 'Sledování katastru – každý další LV a rok (Kč)'],
    'rej_price_exekuce' => [99, 'Lustrace exekucí – cena pro zákazníka za jeden dotaz (Kč, z kreditu služeb)'],
    'rej_cost_katastr_lv' => [100, 'Náklad provozovatele: výpis LV z WSDP (Kč, položka 5001 sazebníku ČÚZK)'],
    'rej_cost_exekuce' => [60, 'Náklad provozovatele: jeden dotaz do CEE (Kč, podle ceníku Exekutorské komory)'],
    'rej_katastr_days' => [30, 'Jak často se katastr kontroluje (dní)'],
];

/** Druhy záznamů spotřeby. */
const REJ_USAGE_KINDS = ['katastr_lv' => 'Výpis LV (katastr)', 'katastr_year' => 'Sledování katastru – roční poplatek', 'exekuce' => 'Lustrace exekucí'];

$GLOBALS['EXT_ADMIN_NAV']['/admin/rejstriky'] = ['Katastr a rejstříky', 'Dům a lidé', fn() => rej_nav_visible()];
$GLOBALS['EXT_DAILY'][] = 'rej_daily';

function rej_price(string $key): int
{
    $default = REJ_PRICES[$key][0] ?? 0;
    if (!function_exists('pset') || !function_exists('platform_enabled') || !platform_enabled()) {
        return $default;
    }
    $v = pset($key);
    return ctype_digit($v) ? min(1000000, (int) $v) : $default;
}
/** Edice Premium, nebo web mimo předplatné. */
function rej_premium(): bool
{
    return !function_exists('plan_managed') || !plan_managed() || rej_premium_for(plan_edition());
}
function rej_premium_for(string $edition): bool
{
    return (EDITION_RANK[$edition] ?? 0) >= EDITION_RANK['premium'];
}
/** Funkce modulu: 'katastr', 'insolvence' (fyzické osoby), 'exekuce'. Vidí ji správce tohoto domu? */
function rej_visible(string $feature): bool
{
    return function_exists('beta_visible') && beta_visible($feature);
}
/** Smí ji dům používat (viditelná + edice Premium)? */
/** Edice, od které funkci dům smí používat: insolvence od Standardu (rejstřík je zdarma), katastr a exekuce Premium. */
const REJ_EDITION = ['insolvence' => 'standard', 'katastr' => 'premium', 'exekuce' => 'premium'];
function rej_edition_ok(string $feature): bool
{
    if (!function_exists('plan_managed') || !plan_managed()) {
        return true;
    }
    return (EDITION_RANK[plan_edition()] ?? 0) >= EDITION_RANK[REJ_EDITION[$feature] ?? 'premium'];
}
function rej_allowed(string $feature): bool
{
    return rej_visible($feature) && rej_edition_ok($feature);
}
function rej_nav_visible(): bool
{
    return rej_visible('katastr') || rej_visible('exekuce') || rej_visible('insolvence');
}
function rej_set(string $key, string $value): void
{
    q('INSERT INTO settings (`key`, value) VALUES (?,?) ON CONFLICT(`key`) DO UPDATE SET value = excluded.value', [$key, $value]);
    setting('__reset');
}
/** Adresy e-mailů správců domu. */
function rej_admin_emails(): array
{
    return array_column(all("SELECT email FROM users WHERE role = 'admin' AND active = 1"), 'email');
}

// ---------- Tabulky v databázi domu ----------

function rej_ready(?PDO $pdo = null): void
{
    static $done = [];
    $pdo ??= db();
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $exists = $mysql ? (bool) $pdo->query("SHOW TABLES LIKE 'rej_persons'")->fetch() : (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'rej_persons'")->fetch();
    if ($exists) {
        return;
    }
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $text = $mysql ? 'TEXT NULL' : "TEXT NOT NULL DEFAULT ''";
    $int = $mysql ? 'INT' : 'INTEGER';
    $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    // Fyzické osoby – vlastníci, u kterých výbor hlídá insolvenci / lustruje exekuce. Jen správci; rodné číslo se neukládá.
    $pdo->exec("CREATE TABLE rej_persons (id $pk, unit_id $int NOT NULL DEFAULT 0, surname {$s(100)}, first_name {$s(100)}, birth_date {$s(10)},"
        . " created_at {$s(19)}, created_by $int NOT NULL DEFAULT 0, isir_checked {$s(16)}, isir_found $text)$tail");
    // Zjištěné změny v katastru a jejich vyřízení.
    $pdo->exec("CREATE TABLE rej_katastr_changes (id $pk, found_at {$s(19)}, unit_key {$s(40)}, unit_id $int NOT NULL DEFAULT 0, kind {$s(20)},"
        . " old_value $text, new_value $text, data $text, status {$s(12)}, handled_at {$s(19)}, handled_by {$s(191)})$tail");
    // Výsledky lustrací v evidenci exekucí.
    $pdo->exec("CREATE TABLE rej_exekuce (id $pk, at {$s(19)}, unit_id $int NOT NULL DEFAULT 0, person_id $int NOT NULL DEFAULT 0, subject {$s(191)},"
        . " query_kind {$s(1)}, found $int NOT NULL DEFAULT 0, result $text, price $int NOT NULL DEFAULT 0, user_name {$s(191)}, error {$s(255)})$tail");
}

// ---------- Spotřeba placených služeb (databáze platformy) ----------

function rej_usage_ready(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    if (!function_exists('platform_enabled') || !platform_enabled()) {
        return $ok = false;
    }
    pq('CREATE TABLE IF NOT EXISTS rej_usage (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant TEXT NOT NULL, at TEXT NOT NULL, kind TEXT NOT NULL,'
        . " qty INTEGER NOT NULL DEFAULT 1, price INTEGER NOT NULL DEFAULT 0, cost INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT '')");
    pq('CREATE INDEX IF NOT EXISTS rej_usage_tenant ON rej_usage (tenant, at)');
    return $ok = true;
}
/** Zapíše placený dotaz: $price = cena pro zákazníka, $cost = náklad provozovatele (Kč bez DPH). */
function rej_usage_add(string $kind, int $qty, int $price, int $cost, string $note = ''): void
{
    if (!rej_usage_ready()) {
        return;
    }
    $tenant = defined('TENANT') ? (string) TENANT : '';
    pq('INSERT INTO rej_usage (tenant, at, kind, qty, price, cost, note) VALUES (?,?,?,?,?,?,?)', [$tenant, date('Y-m-d H:i:s'), $kind, $qty, $price, $cost, mb_substr($note, 0, 255)]);
}
/** Souhrn za období: [tenant => [kind => ['qty', 'price', 'cost', 'n']]]. */
function rej_usage_report(string $from, string $to): array
{
    if (!rej_usage_ready()) {
        return [];
    }
    $out = [];
    foreach (pq('SELECT tenant, kind, COUNT(*) AS n, SUM(qty) AS qty, SUM(price) AS price, SUM(cost) AS cost FROM rej_usage WHERE at >= ? AND at < ? GROUP BY tenant, kind ORDER BY tenant, kind',
        [$from, $to])->fetchAll() as $r) {
        $out[$r['tenant']][$r['kind']] = ['n' => (int) $r['n'], 'qty' => (int) $r['qty'], 'price' => (int) $r['price'], 'cost' => (int) $r['cost']];
    }
    return $out;
}
/** Poslední záznamy spotřeby tohoto domu. */
function rej_usage_tenant(int $limit = 20): array
{
    if (!rej_usage_ready() || !defined('TENANT')) {
        return [];
    }
    return pq('SELECT * FROM rej_usage WHERE tenant = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [TENANT])->fetchAll();
}

// ---------- Síť (podstrčitelná pro testy) ----------

/**
 * HTTP požadavek: vrací ['status' => int (0 = spojení selhalo), 'body' => string, 'error' => string].
 * V testech se místo sítě zavolá $GLOBALS['REJ_HTTP'](method, url, headers, body).
 */
function rej_http(string $method, string $url, array $headers = [], string $body = '', int $timeout = 30): array
{
    if (isset($GLOBALS['REJ_HTTP']) && is_callable($GLOBALS['REJ_HTTP'])) {
        $r = ($GLOBALS['REJ_HTTP'])($method, $url, $headers, $body);
        return ['status' => (int) ($r['status'] ?? 0), 'body' => (string) ($r['body'] ?? ''), 'error' => (string) ($r['error'] ?? '')];
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'timeout' => $timeout, 'ignore_errors' => true,
        'header' => implode("\r\n", $headers), 'content' => $body]]);
    $resp = @file_get_contents($url, false, $ctx);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s?/', $http_response_header[0], $m) ? (int) $m[1] : 0;
    if ($resp === false || $status === 0) {
        return ['status' => 0, 'body' => '', 'error' => rej_http_error((string) (error_get_last()['message'] ?? ''))];
    }
    return ['status' => $status, 'body' => $resp, 'error' => ''];
}
/** Chyba spojení bez adresy (v adrese může být API klíč): „file_get_contents(https://…?api_key=…): Failed to open stream: Connection refused“ → „Connection refused“. */
function rej_http_error(string $message): string
{
    if (preg_match('/failed to open stream:\s*(.+)$/i', $message, $m)) {
        $message = $m[1];
    }
    $message = trim(preg_replace('~[a-z]+://\S+~i', '[adresa]', $message));
    return mb_substr($message !== '' ? $message : 'spojení se nepodařilo', 0, 200);
}
function rej_sleep(int $seconds): void
{
    if (isset($GLOBALS['REJ_SLEEP']) && is_callable($GLOBALS['REJ_SLEEP'])) {
        ($GLOBALS['REJ_SLEEP'])($seconds);
        return;
    }
    sleep($seconds);
}

// ---------- Denní úloha a přehled ----------

/** Denně (z daily_tasks): katastr podle intervalu, insolvence osob jednou týdně, roční poplatek za sledování katastru. */
function rej_daily(): void
{
    foreach (['rej_katastr_daily', 'rej_isir_daily'] as $fn) {
        if (function_exists($fn)) {
            try {
                $fn();
            } catch (Throwable $e) {
                error_log('SVJ web, ' . $fn . ': ' . $e->getMessage());
            }
        }
    }
}
/** Souhrn pro stránku /admin/rejstriky. */
function rej_summary(): array
{
    rej_ready();
    $out = [];
    if (rej_visible('katastr')) {
        $out['katastr'] = ['allowed' => rej_allowed('katastr'), 'configured' => rej_katastr_configured(), 'on' => setting('rej_katastr_on') !== '',
            'last' => explode('|', setting('rej_katastr_last'), 3), 'open' => (int) val("SELECT COUNT(*) FROM rej_katastr_changes WHERE status = 'new'"),
            'lv' => max(1, count(rej_katastr_target()['lv'])), 'price' => rej_katastr_year_price(max(1, count(rej_katastr_target()['lv'])))];
    }
    if (rej_visible('insolvence')) {
        $persons = all('SELECT isir_found FROM rej_persons');
        $out['insolvence'] = ['allowed' => rej_edition_ok('insolvence'), 'companies' => function_exists('isir_company_units') ? count(isir_company_units()) : 0,
            'company_hits' => count(array_filter((array) json_decode(setting('isir_results'), true), fn($r) => !empty($r['found']))),
            'persons' => count($persons), 'person_hits' => count(array_filter($persons, fn($p) => (array) json_decode((string) $p['isir_found'], true) !== [])),
            'last' => explode('|', setting('rej_isir_last'), 3)];
    }
    if (rej_visible('exekuce')) {
        $out['exekuce'] = ['allowed' => rej_allowed('exekuce'), 'configured' => rej_cee_configured(), 'checks' => (int) val('SELECT COUNT(*) FROM rej_exekuce WHERE error = \'\''),
            'hits' => (int) val("SELECT COUNT(*) FROM rej_exekuce WHERE error = '' AND found > 0"), 'last' => (string) val('SELECT MAX(at) FROM rej_exekuce'), 'price' => rej_price('rej_price_exekuce'),
            'credit' => rej_exekuce_credit_ok()];
    }
    return $out;
}
