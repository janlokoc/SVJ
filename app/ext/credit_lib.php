<?php
declare(strict_types=1);

/*
 * Kredit služeb – jeden předplacený kredit v Kč na dům pro služby placené podle spotřeby
 * (SMS, lustrace exekucí, ověření hlasu přes Bank iD, online shromáždění v edici Standard).
 *
 * - Pohyby v databázi platformy (credit_ledger), částky v haléřích bez DPH. Kladný pohyb (dobití, převod, oprava)
 *   založí „šarži“ s platností credit_valid_months měsíců (výchozí 24); čerpá se od šarže, která propadá nejdřív (FIFO).
 *   Každý řádek nese lot_id šarže, ze které čerpá (u kladného řádku vlastní id). Zbytek šarže po platnosti propadne
 *   denní úlohou (kind 'expire').
 * - credit_charge() strhne částku atomicky (transakce) jen tehdy, když na ni zůstatek stačí – zůstatek nikdy není záporný.
 *   Vrácení za službu, která se nepovedla (neodeslaná SMS, zrušené online shromáždění), vrací peníze do téže šarže
 *   (credit_reverse podle 'ref').
 * - Dobití: zálohová faktura s QR (platba kind 'credit'); po potvrzení platby se kredit připíše a vystaví se faktura.
 *   Dům, za který platí partner (správcovská firma), dostane dobití na zálohovou fakturu partnera.
 * - Nízký zůstatek: jednou e-mail správcům domu, když zůstatek klesne pod credit_low_h (výchozí 100 Kč); po dobití se
 *   upozornění obnoví.
 * Nastavení (konzole → Kredit služeb): credit_valid_months, credit_low_h, bankid_price, price_online_meeting,
 * sms_free_premium; ceny SMS (sms_price_h) a lustrace (rej_price_exekuce) jsou u svých modulů.
 * Daňový režim kreditu (jednoúčelový poukaz, DPH při platbě) musí potvrdit účetní – viz UKOLY-PROVOZOVATELE.md.
 */

const CREDIT_KINDS = ['topup' => 'Dobití', 'sms' => 'SMS zprávy', 'exekuce' => 'Lustrace exekucí', 'bankid' => 'Ověření hlasu přes Bank iD', 'online' => 'Shromáždění online',
    'refund' => 'Vrácení zůstatku', 'fix' => 'Oprava', 'expire' => 'Propadnutí po době platnosti', 'migrate' => 'Převod dosavadního SMS kreditu'];
/** Služby, které čerpají kredit (souhrn spotřeby). */
const CREDIT_SERVICES = ['sms', 'exekuce', 'bankid', 'online'];
/** Výchozí nastavení provozovatele (pset): měsíce platnosti, hranice upozornění (haléře), ceny v Kč bez DPH, SMS v Premium. */
const CREDIT_DEFAULTS = ['credit_valid_months' => 24, 'credit_low_h' => 10000, 'bankid_price' => 19, 'price_online_meeting' => 490, 'sms_free_premium' => 100];
/** Nabízené částky dobití (Kč bez DPH) a nejmenší vlastní částka. */
const CREDIT_TOPUPS = [300, 500, 1000, 2000];
const CREDIT_TOPUP_MIN = 300;
const CREDIT_TOPUP_MAX = 100000;

$GLOBALS['EXT_ADMIN_NAV']['/admin/kredit'] = ['Kredit služeb', 'Správa webu', fn() => credit_nav_visible()];

function credit_setting(string $key): int
{
    $default = CREDIT_DEFAULTS[$key] ?? 0;
    if (!function_exists('pset') || !function_exists('platform_enabled') || !platform_enabled()) {
        return $default;
    }
    $v = preg_replace('/\s+/', '', pset($key));
    return ctype_digit($v) ? min(100000000, (int) $v) : $default;
}
/** Haléře jako „1 234,50 Kč“ (celé koruny bez desetin). */
function credit_money(int $h): string
{
    return ($h < 0 ? '−' : '') . number_format(abs($h) / 100, abs($h) % 100 === 0 ? 0 : 2, ',', "\u{a0}") . "\u{a0}Kč";
}

// ---------- Tabulky (migrace platformy, app/platform_lib.php) ----------

function credit_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant TEXT NOT NULL, at TEXT NOT NULL, delta_h INTEGER NOT NULL,
        kind TEXT NOT NULL, note TEXT NOT NULL DEFAULT '', by_whom TEXT NOT NULL DEFAULT '', lot_id INTEGER NOT NULL DEFAULT 0, expires_at TEXT NOT NULL DEFAULT '',
        ref TEXT NOT NULL DEFAULT '', qty INTEGER NOT NULL DEFAULT 0);
        CREATE INDEX IF NOT EXISTS credit_ledger_tenant ON credit_ledger (tenant, id);
        CREATE INDEX IF NOT EXISTS credit_ledger_lot ON credit_ledger (lot_id);
        CREATE INDEX IF NOT EXISTS credit_ledger_ref ON credit_ledger (tenant, ref);
        CREATE TABLE IF NOT EXISTS credit_state (tenant TEXT PRIMARY KEY, low_warned TEXT NOT NULL DEFAULT '', bankid_mailed TEXT NOT NULL DEFAULT '');");
}
/**
 * Jednorázový převod: zbývající SMS kredit každého domu (počet SMS v sms_ledger) × cena SMS → kredit v Kč (kind 'migrate').
 * sms_ledger zůstává jako historie; převedený zůstatek se v ní vynuluje řádkem 'fix'. Vrací [dům => haléře].
 */
function credit_migrate_sms(PDO $pdo): array
{
    if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sms_ledger'")->fetch()) {
        return [];
    }
    $st = $pdo->prepare('SELECT value FROM platform_settings WHERE key = ?');
    $st->execute(['sms_price_h']);
    $price = (string) $st->fetchColumn();
    $price = ctype_digit($price) ? (int) $price : (int) SMS_DEFAULTS['sms_price_h'];
    $st->execute(['credit_valid_months']);
    $months = (string) $st->fetchColumn();
    $months = ctype_digit($months) && (int) $months > 0 ? (int) $months : CREDIT_DEFAULTS['credit_valid_months'];
    $out = [];
    $now = date('Y-m-d H:i:s');
    foreach ($pdo->query('SELECT tenant, SUM(delta) AS n FROM sms_ledger GROUP BY tenant HAVING SUM(delta) > 0')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $n = (int) $r['n'];
        $h = $n * $price;
        $pdo->prepare("INSERT INTO credit_ledger (tenant, at, delta_h, kind, note, by_whom, expires_at, qty) VALUES (?,?,?,'migrate',?,'převod',?,?)")
            ->execute([$r['tenant'], $now, $h, "zbývajících $n SMS × " . credit_money($price), date('Y-m-d', strtotime('+' . $months . ' month')), $n]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE credit_ledger SET lot_id = ? WHERE id = ?')->execute([$id, $id]);
        $pdo->prepare("INSERT INTO sms_ledger (tenant, at, delta, kind, note, by_whom) VALUES (?,?,?,'fix',?,'převod')")
            ->execute([$r['tenant'], $now, -$n, 'převedeno na kredit služeb (' . credit_money($h) . ')']);
        $out[$r['tenant']] = $h;
    }
    return $out;
}

// ---------- Zůstatek a pohyby ----------

/** Vede se kredit? Jen u webu na platformě (web mimo platformu platí služby přímo provozovatel). */
function credit_managed(): bool
{
    return function_exists('plan_managed') && plan_managed();
}
function credit_balance_h(string $tenant): int
{
    return (int) pq('SELECT COALESCE(SUM(delta_h), 0) FROM credit_ledger WHERE tenant = ?', [$tenant])->fetchColumn();
}
/** Stačí kredit na částku? Web mimo platformu kredit nepotřebuje. */
function credit_enough(string $tenant, int $amountH): bool
{
    return $amountH <= 0 || credit_balance_h($tenant) >= $amountH;
}
/** Šarže se zůstatkem, v pořadí čerpání (nejdřív ta, která dřív propadá): [['lot_id', 'left', 'expires_at']]. */
function credit_lots(string $tenant, bool $validOnly = true): array
{
    return pq('SELECT lot_id, SUM(delta_h) AS left_h, MAX(expires_at) AS expires_at FROM credit_ledger WHERE tenant = ? AND lot_id > 0 GROUP BY lot_id HAVING SUM(delta_h) > 0'
        . ($validOnly ? ' AND MAX(expires_at) >= ?' : '') . ' ORDER BY MAX(expires_at), lot_id', $validOnly ? [$tenant, date('Y-m-d')] : [$tenant])->fetchAll();
}
function credit_row_add(string $tenant, int $deltaH, string $kind, string $note, string $by, int $lot, string $expires, string $ref = '', int $qty = 0): int
{
    pq('INSERT INTO credit_ledger (tenant, at, delta_h, kind, note, by_whom, lot_id, expires_at, ref, qty) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$tenant, date('Y-m-d H:i:s'), $deltaH, $kind, mb_substr($note, 0, 250), mb_substr($by, 0, 120), $lot, $expires, $ref, $qty]);
    return (int) platform_db()->lastInsertId();
}
/** Transakce nad databází platformy (vnořené volání běží v té vnější). */
function credit_tx(callable $fn): mixed
{
    static $depth = 0;   // BEGIN IMMEDIATE (zámek hned na začátku) – PDO::inTransaction() ho nevidí, proto vlastní počítadlo
    $pdo = platform_db();
    if ($depth > 0 || $pdo->inTransaction()) {
        return $fn();
    }
    $pdo->exec('BEGIN IMMEDIATE');
    $depth++;
    try {
        $r = $fn();
        $depth--;
        $pdo->exec($r === false || $r === null ? 'ROLLBACK' : 'COMMIT');
        return $r;
    } catch (Throwable $e) {
        $depth = 0;
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}
/**
 * Připíše kredit jako novou šarži (platí credit_valid_months měsíců). $kind: topup, migrate, fix.
 * Vrací id pohybu, nebo null (neplatná částka / dům neexistuje).
 */
function credit_add(string $tenant, int $amountH, string $kind, string $note = '', string $by = ''): ?int
{
    if ($amountH <= 0 || !in_array($kind, ['topup', 'migrate', 'fix'], true) || !tenant_row($tenant)) {
        return null;
    }
    $months = max(1, credit_setting('credit_valid_months'));
    $id = credit_row_add($tenant, $amountH, $kind, $note, $by, 0, date('Y-m-d', strtotime('+' . $months . ' month')));
    pq('UPDATE credit_ledger SET lot_id = ? WHERE id = ?', [$id, $id]);
    if (credit_balance_h($tenant) >= credit_setting('credit_low_h')) {
        credit_state_set($tenant, 'low_warned', '');   // po dobití zase upozorníme, až dojde
    }
    return $id;
}
/**
 * Strhne částku z kreditu (atomicky, od nejdřív propadající šarže). Odmítne (false), když zůstatek nestačí –
 * nikdy nejde do minusu. $ref spojí pohyby jedné služby (vrácení přes credit_reverse), $qty = počet kusů (SMS, hlasů).
 */
function credit_charge(string $tenant, int $amountH, string $kind, string $note, string $ref = '', int $qty = 0, string $by = ''): bool
{
    if ($amountH < 0 || !isset(CREDIT_KINDS[$kind])) {
        return false;
    }
    if ($amountH === 0) {
        return true;
    }
    credit_expire($tenant);   // co už propadlo, nejde čerpat
    $before = credit_balance_h($tenant);
    $ok = credit_tx(function () use ($tenant, $amountH, $kind, $note, $ref, $qty, $by): bool {
        $left = $amountH;
        $lots = credit_lots($tenant);
        if (array_sum(array_map(fn($l) => (int) $l['left_h'], $lots)) < $amountH) {
            return false;
        }
        $first = true;
        foreach ($lots as $lot) {
            $take = min($left, (int) $lot['left_h']);
            credit_row_add($tenant, -$take, $kind, $note, $by, (int) $lot['lot_id'], (string) $lot['expires_at'], $ref, $first ? $qty : 0);
            $first = false;
            $left -= $take;
            if ($left === 0) {
                break;
            }
        }
        return true;
    });
    if ($ok) {
        credit_low_check($tenant, $before, $before - $amountH);
    }
    return (bool) $ok;
}
/** Vrátí do kreditu, co bylo strženo pod $ref (do týchž šarží). Vrací vrácenou částku v haléřích. */
function credit_reverse(string $tenant, string $ref, string $note): int
{
    if ($ref === '') {
        return 0;
    }
    return (int) credit_tx(function () use ($tenant, $ref, $note): int {
        $back = 0;
        foreach (pq('SELECT lot_id, kind, SUM(delta_h) AS d, SUM(qty) AS q, MAX(expires_at) AS ex FROM credit_ledger WHERE tenant = ? AND ref = ? GROUP BY lot_id, kind HAVING SUM(delta_h) < 0 OR SUM(qty) > 0',
            [$tenant, $ref])->fetchAll() as $r) {
            $d = -(int) $r['d'];
            $ex = (int) $r['lot_id'] ? (string) pq('SELECT expires_at FROM credit_ledger WHERE id = ?', [(int) $r['lot_id']])->fetchColumn() : '';
            credit_row_add($tenant, $d, (string) $r['kind'], $note, '', (int) $r['lot_id'], $ex, $ref, -(int) $r['q']);
            $back += $d;
        }
        return $back;
    }) ?: 0;
}
/** Kolik je pod $ref čistě strženo (kladné číslo = zaplaceno). */
function credit_ref_charged(string $tenant, string $ref): int
{
    return -(int) pq('SELECT COALESCE(SUM(delta_h), 0) FROM credit_ledger WHERE tenant = ? AND ref = ?', [$tenant, $ref])->fetchColumn();
}
/** Výpis pohybů (nejnovější první). */
function credit_statement(string $tenant, int $limit = 50): array
{
    return pq('SELECT * FROM credit_ledger WHERE tenant = ? AND (delta_h <> 0 OR qty <> 0) ORDER BY id DESC LIMIT ' . max(1, $limit), [$tenant])->fetchAll();
}
/** Kredit, který do 60 dnů propadne: [['left_h', 'expires_at']]. */
function credit_expiring(string $tenant, int $days = 60): array
{
    return array_values(array_filter(credit_lots($tenant), fn($l) => $l['expires_at'] <= date('Y-m-d', strtotime('+' . $days . ' day'))));
}
/** Zbytek šarží po době platnosti propadne. Vrací propadlou částku (haléře). */
function credit_expire(?string $tenant = null): int
{
    $sql = 'SELECT tenant, lot_id, SUM(delta_h) AS left_h, MAX(expires_at) AS ex FROM credit_ledger WHERE lot_id > 0' . ($tenant !== null ? ' AND tenant = ?' : '')
        . ' GROUP BY tenant, lot_id HAVING SUM(delta_h) > 0 AND MAX(expires_at) < ?';
    $sum = 0;
    foreach (pq($sql, $tenant !== null ? [$tenant, date('Y-m-d')] : [date('Y-m-d')])->fetchAll() as $l) {
        credit_row_add((string) $l['tenant'], -(int) $l['left_h'], 'expire', 'platnost skončila ' . cz_date((string) $l['ex']), '', (int) $l['lot_id'], (string) $l['ex']);
        $sum += (int) $l['left_h'];
    }
    return $sum;
}
/** Denně (platform_daily): propadnutí starých šarží. */
function credit_daily(): void
{
    try {
        credit_expire();
    } catch (Throwable $e) {
        error_log('SVJ web, kredit: ' . $e->getMessage());
    }
}

// ---------- Upozornění ----------

function credit_state(string $tenant): array
{
    $r = pq('SELECT * FROM credit_state WHERE tenant = ?', [$tenant])->fetch();
    return $r ?: ['tenant' => $tenant, 'low_warned' => '', 'bankid_mailed' => ''];
}
function credit_state_set(string $tenant, string $key, string $value): void
{
    if (!in_array($key, ['low_warned', 'bankid_mailed'], true)) {
        return;
    }
    pq("INSERT INTO credit_state (tenant, $key) VALUES (?,?) ON CONFLICT(tenant) DO UPDATE SET $key = excluded.$key", [$tenant, $value]);
}
/** E-maily správců domu (správci webu + kontakt z registrace). */
function credit_admin_emails(string $tenant): array
{
    $t = tenant_row($tenant);
    $out = [];
    try {
        $out = array_column(tenant_pdo($tenant)->query("SELECT email FROM users WHERE role = 'admin' AND active = 1 AND email <> ''")->fetchAll(), 'email');
    } catch (Throwable $e) {
    }
    if ($t && filter_var($t['owner_email'], FILTER_VALIDATE_EMAIL)) {
        $out[] = $t['owner_email'];
    }
    return array_values(array_unique(array_filter(array_map('strtolower', $out), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
}
/** Po stržení: zůstatek klesl pod hranici → jednou e-mail správcům (znovu až po dobití). */
function credit_low_check(string $tenant, int $before, int $after): void
{
    $low = credit_setting('credit_low_h');
    if ($low <= 0 || $after >= $low || $before < $low || credit_state($tenant)['low_warned'] !== '') {
        return;
    }
    credit_state_set($tenant, 'low_warned', date('Y-m-d H:i:s'));
    $t = tenant_row($tenant);
    if (!$t) {
        return;
    }
    foreach (credit_admin_emails($tenant) as $to) {
        platform_mail($to, 'Dochází kredit služeb – ' . $t['name'], "Dobrý den,\n\nkredit služeb webu " . $t['name'] . ' klesl na ' . credit_money($after) . ".\n"
            . "Z kreditu se platí SMS zprávy, lustrace exekucí, ověření hlasu přes Bank iD a online shromáždění. Když kredit dojde, tyto služby nepůjdou použít.\n\n"
            . 'Dobít ho můžete v administraci webu: ' . tenant_url($t, '/admin/kredit') . "\n");
    }
}

// ---------- Odkaz „Dobít kredit“ ----------

/** Odkaz na dobití (HTML) – místo tlačítka placené služby, když kredit nestačí. */
function credit_topup_link(string $label = 'Dobít kredit'): string
{
    return '<a class="btn btn-small" href="' . url('/admin/kredit') . '#dobit">' . e($label) . '</a>';
}
/** Věta o nedostatku kreditu s odkazem (HTML). */
function credit_short_html(int $needH): string
{
    return 'Kredit služeb nestačí: potřeba ' . e(credit_money($needH)) . ', zůstatek ' . e(credit_money(credit_balance_h(TENANT))) . '. ' . credit_topup_link();
}
/** Položka menu: dům na platformě, který používá nebo může používat placenou službu, nebo kredit má. */
function credit_nav_visible(): bool
{
    if (!credit_managed()) {
        return false;
    }
    foreach (['sms', 'exekuce', 'bankid', 'shromazdeni-online'] as $k) {
        if (beta_visible($k)) {
            return true;
        }
    }
    return (bool) pq('SELECT 1 FROM credit_ledger WHERE tenant = ? LIMIT 1', [TENANT])->fetchColumn();
}

// ---------- Dobití ----------

/** Otevřená výzva k platbě za dobití (přímá platba domu), nebo null. */
function credit_topup_payment(array $t): ?array
{
    $p = pq("SELECT * FROM payments WHERE tenant = ? AND kind = 'credit' AND status IN ('pending','claimed') ORDER BY id DESC LIMIT 1", [$t['slug']])->fetch();
    return $p ?: null;
}
/** Platí za dům partner (dobití jde na jeho fakturu)? */
function credit_partner_billed(array $t): bool
{
    return (int) ($t['partner_id'] ?? 0) && ($t['partner_status'] ?? '') === 'active' && (int) ($t['partner_billing'] ?? 0);
}
/** Řádek dobití tohoto domu na otevřené faktuře partnera: [platba, řádek] nebo null. */
function credit_partner_pending(array $t): ?array
{
    if (!credit_partner_billed($t) || !($p = partner_open_payment((int) $t['partner_id']))) {
        return null;
    }
    foreach (partner_lines($p) as $l) {
        if (($l['kind'] ?? '') === 'credit' && $l['slug'] === $t['slug']) {
            return [$p, $l];
        }
    }
    return null;
}
/** Kontrola částky dobití (Kč bez DPH): chyba, nebo null. */
function credit_topup_error(int $czk): ?string
{
    if ($czk < CREDIT_TOPUP_MIN || $czk > CREDIT_TOPUP_MAX) {
        return 'Dobít lze ' . number_format(CREDIT_TOPUP_MIN, 0, ',', "\u{a0}") . ' až ' . number_format(CREDIT_TOPUP_MAX, 0, ',', "\u{a0}") . ' Kč.';
    }
    return null;
}
/**
 * Objednávka dobití: zálohová faktura s QR (nezaplacená se přepíše), u domu placeného partnerem řádek na faktuře partnera.
 * Vrací platbu, nebo chybu jako text.
 */
function credit_topup_order(array $t, int $czk): array|string
{
    if ($err = credit_topup_error($czk)) {
        return $err;
    }
    if (!empty($t['demo'])) {
        return 'V ukázkovém webu kredit dobít nejde.';
    }
    if (credit_partner_billed($t)) {
        return partner_extra_line($t, ['kind' => 'credit', 'price' => $czk]);
    }
    $p = credit_topup_payment($t);
    if ($p && $p['status'] === 'claimed') {
        return 'Platbu za předchozí dobití už jste nahlásili. Další objednáte, až ji potvrdíme.';
    }
    if ($p) {
        pq('UPDATE payments SET amount = ?, item = ?, vat_rate = ? WHERE id = ?', [$czk, (string) $czk, vat_rate_now(), $p['id']]);
        return pq('SELECT * FROM payments WHERE id = ?', [$p['id']])->fetch();
    }
    pq("INSERT INTO payments (vat_rate, tenant, amount, months, created_at, kind, item) VALUES (" . vat_rate_now() . ",?,?,0,?,'credit',?)", [$t['slug'], $czk, date('Y-m-d H:i:s'), (string) $czk]);
    $id = (int) platform_db()->lastInsertId();
    pq('UPDATE payments SET vs = ? WHERE id = ?', [date('y') . sprintf('%06d', $id), $id]);
    return pq('SELECT * FROM payments WHERE id = ?', [$id])->fetch();
}
/** Potvrzení platby za dobití (volá billing_confirm): faktura a připsání kreditu. */
function credit_topup_confirm(array $p, string $by): ?array
{
    $t = tenant_row((string) $p['tenant']);
    if (!$t || !in_array($p['status'], ['pending', 'claimed'], true)) {
        return null;
    }
    $today = date('Y-m-d');
    $year = date('Y');
    $no = $year . sprintf('%04d', 1 + (int) pq('SELECT COUNT(*) FROM payments WHERE invoice_no LIKE ?', [$year . '%'])->fetchColumn());
    pq("UPDATE payments SET status = 'paid', paid_at = ?, period_from = ?, period_to = ?, confirmed_by = ?, invoice_no = ?, invoice_data = ? WHERE id = ?",
        [date('Y-m-d H:i:s'), $today, $today, $by, $no, json_encode(invoice_parties($t), JSON_UNESCAPED_UNICODE), $p['id']]);
    credit_add($t['slug'], (int) $p['amount'] * 100, 'topup', 'faktura č. ' . $no . ', VS ' . $p['vs'], $by);
    foreach (array_unique(array_filter([$t['owner_email']])) as $to) {
        platform_mail($to, 'Kredit služeb dobit – ' . $t['name'], "Dobrý den,\n\npřijali jsme platbu " . pay_money($p) . ' a webu ' . $t['name'] . ' jsme připsali kredit ' . money_czk((int) $p['amount'])
            . ".\nZůstatek je " . credit_money(credit_balance_h($t['slug'])) . '. Kredit platí ' . credit_setting('credit_valid_months') . " měsíců.\n"
            . "Fakturu č. $no si můžete stáhnout po přihlášení do administrace: " . tenant_url($t, '/admin/predplatne/doklad/' . $p['id']) . "\n\nDěkujeme.");
    }
    return ['from' => $today, 'to' => $today];
}
/** Text položky na faktuře za dobití. */
function credit_invoice_item(array $p): string
{
    return 'Kredit služeb ' . money_czk((int) $p['amount']) . ' – předplatné služeb (SMS, lustrace exekucí, ověření hlasu, online shromáždění)';
}
/** Storno zaplaceného dobití: odebere z kreditu nejvýš tolik, kolik zbývá. Vrací větu pro záznam. */
function credit_storno_take(string $tenant, int $amountH, string $note, string $by): string
{
    $take = min($amountH, max(0, credit_balance_h($tenant)));
    if ($take > 0 && credit_charge($tenant, $take, 'fix', $note, '', 0, $by)) {
        return 'Z kreditu služeb odebráno ' . credit_money($take) . ($take < $amountH ? ' (víc nezbývalo – zbytek už byl spotřebován)' : '') . '.';
    }
    return 'Kredit služeb už byl spotřebován – nic se neodebralo.';
}

// ---------- Konzole provozovatele ----------

/** Ruční oprava (kladná = připsání nové šarže, záporná = odečtení). Vrací chybu, nebo null. */
function credit_fix(string $tenant, int $deltaH, string $note, string $by): ?string
{
    if (!tenant_row($tenant)) {
        return 'Společenství neexistuje.';
    }
    if ($deltaH === 0 || abs($deltaH) > 100000000) {
        return 'Zadejte částku (kladnou pro připsání, zápornou pro odečtení).';
    }
    if (trim($note) === '') {
        return 'Doplňte poznámku – proč se kredit upravuje.';
    }
    if ($deltaH > 0) {
        return credit_add($tenant, $deltaH, 'fix', $note, $by) ? null : 'Kredit se nepodařilo připsat.';
    }
    return credit_charge($tenant, -$deltaH, 'fix', $note, '', 0, $by) ? null : 'Opravou by kredit klesl pod nulu (zůstatek ' . credit_money(credit_balance_h($tenant)) . ').';
}
/** Vrácení zůstatku (zrušení služby): vynuluje kredit; peníze pošle provozovatel převodem. Vrací vrácenou částku. */
function credit_refund_all(string $tenant, string $note, string $by): int
{
    credit_expire($tenant);
    $h = credit_balance_h($tenant);
    if ($h <= 0 || !credit_charge($tenant, $h, 'refund', $note !== '' ? $note : 'vrácení zůstatku', '', 0, $by)) {
        return 0;
    }
    return $h;
}
/** Zůstatky domů s pohyby: [slug => ['h', 'last']]. */
function credit_balances(): array
{
    return array_column(pq('SELECT tenant, SUM(delta_h) AS h, MAX(at) AS last FROM credit_ledger GROUP BY tenant ORDER BY tenant')->fetchAll(), null, 'tenant');
}
/** Spotřeba za období: [slug => [kind => ['h' (čerpáno, kladně), 'qty', 'n']]] – jen služby (vrácení se odečte). */
function credit_usage_report(string $from, string $to): array
{
    $out = [];
    foreach (pq("SELECT tenant, kind, SUM(delta_h) AS h, SUM(qty) AS qty, SUM(CASE WHEN delta_h < 0 OR qty > 0 THEN 1 ELSE 0 END) AS n FROM credit_ledger
        WHERE at >= ? AND at < ? AND kind IN ('" . implode("','", CREDIT_SERVICES) . "') GROUP BY tenant, kind ORDER BY tenant, kind", [$from, $to])->fetchAll() as $r) {
        $out[$r['tenant']][$r['kind']] = ['h' => -(int) $r['h'], 'qty' => (int) $r['qty'], 'n' => (int) $r['n']];
    }
    return $out;
}
/** Dobití a další pohyby mimo služby za období: [kind => haléře]. */
function credit_totals(string $from, string $to): array
{
    return array_map('intval', array_column(pq('SELECT kind, SUM(delta_h) AS h FROM credit_ledger WHERE at >= ? AND at < ? GROUP BY kind', [$from, $to])->fetchAll(), 'h', 'kind'));
}

// ---------- Ceny služeb pro ceník a obrazovky ----------

/** Ceny a parametry v konzoli (Kredit služeb): klíč => [popis, jednotka ('Kč'; 'h' = zadává se v Kč, ukládá v haléřích; '' = číslo), aktuální hodnota]. */
function credit_console_fields(): array
{
    return [
        'credit_valid_months' => ['Platnost kreditu (měsíců od dobití)', '', fn() => credit_setting('credit_valid_months')],
        'credit_low_h' => ['Upozornit správce, když kredit klesne pod (Kč)', 'h', fn() => credit_setting('credit_low_h')],
        'sms_price_h' => ['SMS – cena za 1 SMS (Kč)', 'h', fn() => sms_price_h()],
        'sms_free_premium' => ['SMS v ceně edice Premium (kusů za kalendářní rok)', '', fn() => credit_setting('sms_free_premium')],
        'rej_price_exekuce' => ['Lustrace exekucí – cena za dotaz (Kč)', 'Kč', fn() => rej_price('rej_price_exekuce')],
        'bankid_price' => ['Bank iD – cena za ověřený hlas (Kč)', 'Kč', fn() => credit_setting('bankid_price')],
        'price_online_meeting' => ['Shromáždění online v edici Standard – cena za shromáždění (Kč, Premium v ceně)', 'Kč', fn() => credit_setting('price_online_meeting')],
        'rej_price_katastr_house' => ['Sledování katastru – za dům a rok vč. 1 LV (Kč, doplněk k předplatnému)', 'Kč', fn() => rej_price('rej_price_katastr_house')],
        'rej_price_katastr_lv_extra' => ['Sledování katastru – každý další LV a rok (Kč)', 'Kč', fn() => rej_price('rej_price_katastr_lv_extra')],
        'rej_katastr_days' => ['Sledování katastru – kontrola každých (dní)', '', fn() => rej_price('rej_katastr_days')],
    ];
}


/**
 * Ceník služeb a doplňků (Kč/haléře bez DPH) pro veřejný ceník a administraci:
 * [['key' => beta klíč, 'name', 'desc', 'h' => cena v haléřích, 'unit', 'credit' => z kreditu?]].
 */
function credit_price_list(): array
{
    $sms = function_exists('sms_price_h') ? sms_price_h() : 150;
    $free = credit_setting('sms_free_premium');
    $rows = [
        ['key' => 'sms', 'name' => 'SMS zpráva', 'desc' => 'Naléhavá oznámení vlastníkům a kódy pro potvrzení hlasu.' . ($free ? ' V edici Premium ' . $free . ' SMS ročně v ceně.' : ''), 'h' => $sms, 'unit' => 'za SMS', 'credit' => true],
        ['key' => 'exekuce', 'name' => 'Lustrace v Centrální evidenci exekucí', 'desc' => 'Na kliknutí výboru, platí se jen úspěšný dotaz.', 'h' => (function_exists('rej_price') ? rej_price('rej_price_exekuce') : 99) * 100, 'unit' => 'za dotaz', 'credit' => true],
        ['key' => 'bankid', 'name' => 'Ověření hlasu přes Bank iD', 'desc' => 'Hlasování mimo zasedání s ověřením totožnosti přes banku.', 'h' => credit_setting('bankid_price') * 100, 'unit' => 'za ověřený hlas', 'credit' => true],
        ['key' => 'shromazdeni-online', 'name' => 'Shromáždění online nebo hybridně', 'desc' => 'V edici Premium v ceně.', 'h' => credit_setting('price_online_meeting') * 100, 'unit' => 'za shromáždění (Standard)', 'credit' => true],
    ];
    if (function_exists('rej_katastr_year_price')) {
        $rows[] = ['key' => 'katastr', 'name' => 'Sledování změn v katastru', 'desc' => 'Edice Premium. Kontrola listu vlastnictví každých ' . max(1, rej_price('rej_katastr_days')) . ' dní; každý další LV '
            . money_czk(rej_price('rej_price_katastr_lv_extra')) . ' ročně. Platí se s ročním předplatným (při zapnutí v průběhu roku poměrná část).', 'h' => rej_katastr_year_price(1) * 100, 'unit' => 'za dům a rok vč. 1 LV', 'credit' => false];
    }
    return $rows;
}
/** Cena s DPH (haléře) – stejný výpočet jako u plateb (vat_split). Neplátce: stejná částka. */
function credit_gross_h(int $h): int
{
    return vat_payer() ? (int) round(vat_split($h / 100, vat_rate_now())['total'] * 100) : $h;
}
