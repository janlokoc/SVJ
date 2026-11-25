<?php
declare(strict_types=1);

/*
 * Partneři – správcovské firmy, které spravují více domů.
 *
 * - Partner (firma) má vlastní přihlášení v portálu /partner na hlavní doméně; firma může mít víc pracovníků.
 * - K domu se partner dostane dvojím způsobem: web pro klienta založí sám (přístup je hned aktivní), nebo požádá
 *   o přístup k existujícímu webu a výbor ho v administraci schválí. Výbor může přístup kdykoli odebrat.
 * - Do administrace domu vstupuje pracovník jednorázovým odkazem; ve webu domu má účet správce bez hesla
 *   (přihlásit se jde jen přes portál) a v záznamech je vidět jménem i s firmou.
 * - Platí partner (varianta „jedna faktura“): domy, za které platí, objedná najednou s množstevní slevou;
 *   po připsání platby se předplatné všech domů na faktuře prodlouží o rok.
 */

const PARTNER_DISCOUNTS_DEFAULT = "5:10\n20:15\n50:20";

/** Tabulky partnerů v databázi služby (volá platform_db při migraci). */
function partner_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS partners (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, ico TEXT NOT NULL DEFAULT '', dic TEXT NOT NULL DEFAULT '',
        address TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '', active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS partner_users (id INTEGER PRIMARY KEY AUTOINCREMENT, partner_id INTEGER NOT NULL, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT NOT NULL DEFAULT '!', role TEXT NOT NULL DEFAULT 'member', totp_secret TEXT, totp_enabled INTEGER NOT NULL DEFAULT 0, totp_last INTEGER NOT NULL DEFAULT 0,
        invite_hash TEXT NOT NULL DEFAULT '', invite_expires TEXT NOT NULL DEFAULT '', active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, last_login_at TEXT);
        ALTER TABLE tenants ADD COLUMN partner_id INTEGER NOT NULL DEFAULT 0;
        ALTER TABLE tenants ADD COLUMN partner_status TEXT NOT NULL DEFAULT '';
        ALTER TABLE tenants ADD COLUMN partner_billing INTEGER NOT NULL DEFAULT 0;
        ALTER TABLE tenants ADD COLUMN partner_since TEXT NOT NULL DEFAULT '';
        ALTER TABLE payments ADD COLUMN partner_id INTEGER NOT NULL DEFAULT 0;");
}
function partner_row(int $id): ?array
{
    $r = $id ? pq('SELECT * FROM partners WHERE id = ?', [$id])->fetch() : null;
    return $r ?: null;
}
/** Domy partnera; $status '' = všechny (i čekající na schválení výborem), 'active' = jen schválené. */
function partner_tenants(int $partnerId, string $status = 'active'): array
{
    return pq('SELECT * FROM tenants WHERE partner_id = ?' . ($status !== '' ? ' AND partner_status = ' . platform_db()->quote($status) : '') . ' ORDER BY name', [$partnerId])->fetchAll();
}
/** Sleva v procentech podle počtu domů, které partner spravuje (nastavení služby „počet:procent“ na řádek). */
function partner_discount_tiers(): array
{
    $out = [];
    foreach (preg_split('/\R/', pset('partner_discounts') !== '' ? pset('partner_discounts') : PARTNER_DISCOUNTS_DEFAULT) as $line) {
        if (preg_match('/^\s*(\d+)\s*:\s*(\d{1,2})\s*$/', $line, $m)) {
            $out[(int) $m[1]] = (int) $m[2];
        }
    }
    ksort($out);
    return $out;
}
function partner_discount(int $houses): int
{
    $pct = 0;
    foreach (partner_discount_tiers() as $min => $p) {
        if ($houses >= $min) {
            $pct = $p;
        }
    }
    return $pct;
}

/** Pozve pracovníka partnera: e-mail s odkazem na nastavení hesla (platí 7 dní). Vrací odkaz (pro konzoli, kdyby e-mail nedošel). */
function partner_invite(array $partner, string $name, string $email, string $role = 'member'): string
{
    $token = bin2hex(random_bytes(32));
    pq('INSERT INTO partner_users (partner_id, name, email, role, invite_hash, invite_expires, created_at) VALUES (?,?,?,?,?,?,?)',
        [$partner['id'], $name, $email, $role === 'owner' ? 'owner' : 'member', hash('sha256', $token), date('Y-m-d H:i:s', time() + 7 * 86400), date('Y-m-d H:i:s')]);
    $link = platform_url('/partner/pozvanka/' . $token);
    platform_mail($email, 'Přístup do partnerského portálu – ' . $partner['name'], "Dobrý den,\n\nmáte přístup do partnerského portálu služby " . pset('service_name') . " za firmu " . $partner['name']
        . ".\nV portálu spravujete weby domů, které Vaše firma spravuje, a objednáváte jejich předplatné na jedné faktuře.\n\nHeslo si nastavíte tímto odkazem (platí 7 dní):\n$link\n\nPokud jste o přístup nežádali, zprávu ignorujte.");
    return $link;
}

// ---------- Domy a souhlas výboru ----------

/** Partner založil web pro klienta: dům je jeho hned (výbor přístup později může odebrat). */
function partner_attach_created(array $partner, string $slug): void
{
    pq("UPDATE tenants SET partner_id = ?, partner_status = 'active', partner_billing = 1, partner_since = ? WHERE slug = ?", [$partner['id'], date('Y-m-d H:i:s'), $slug]);
}
/**
 * Partner žádá o přístup k existujícímu webu. Ochrana proti cizím žádostem: musí znát IČO společenství
 * (pokud ho web má vyplněné). Vrací chybu nebo null.
 */
function partner_request(array $partner, string $slug, string $ico): ?string
{
    $t = tenant_row($slug);
    if (!$t || !empty($t['demo'])) {
        return 'Web s touto adresou neexistuje.';
    }
    if ((int) $t['partner_id'] === (int) $partner['id']) {
        return $t['partner_status'] === 'active' ? 'Tento dům už spravujete.' : 'Žádost už čeká na schválení výborem.';
    }
    if ((int) $t['partner_id'] && $t['partner_status'] === 'active') {
        return 'Tento dům už spravuje jiný správce. Výbor ho musí nejdřív odebrat.';
    }
    $known = preg_replace('/\D/', '', (string) (tenant_settings($slug)['ico'] ?? ''));
    if ($known !== '' && $known !== preg_replace('/\D/', '', $ico)) {
        return 'IČO nesouhlasí s údaji webu. Zkontrolujte ho, nebo požádejte výbor, ať ho v nastavení webu doplní.';
    }
    pq("UPDATE tenants SET partner_id = ?, partner_status = 'pending', partner_billing = 0, partner_since = ? WHERE slug = ?", [$partner['id'], date('Y-m-d H:i:s'), $slug]);
    platform_log('Partner: žádost o přístup', $partner['name'] . ' → ' . $slug);
    $to = array_filter([$t['owner_email']], fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
    foreach ($to as $email) {
        platform_mail($email, 'Žádost o přístup správce domu – ' . $t['name'], "Dobrý den,\n\nsprávcovská firma " . $partner['name'] . ($partner['ico'] !== '' ? ' (IČO ' . $partner['ico'] . ')' : '')
            . " žádá o přístup k administraci webu " . $t['name'] . ".\n\nSchválit nebo odmítnout ji můžete po přihlášení do administrace webu:\n" . tenant_url($t, '/admin/spravce-domu')
            . "\n\nDokud žádost neschválíte, firma k webu přístup nemá.");
    }
    return null;
}
/** Výbor schválí přístup; $billing = předplatné od teď platí správce. */
function partner_approve(array $t, bool $billing, string $by): void
{
    pq("UPDATE tenants SET partner_status = 'active', partner_billing = ?, partner_since = ? WHERE slug = ?", [$billing ? 1 : 0, date('Y-m-d H:i:s'), $t['slug']]);
    $p = partner_row((int) $t['partner_id']);
    platform_log('Partner: přístup schválen', $t['slug'] . ' · ' . ($p['name'] ?? '') . ' · ' . $by . ($billing ? ' · platí správce' : ''));
    if ($p && filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
        platform_mail($p['email'], 'Výbor schválil přístup – ' . $t['name'], "Dobrý den,\n\nvýbor společenství " . $t['name'] . " schválil Vaší firmě přístup k administraci webu."
            . ($billing ? "\nPředplatné tohoto domu bude od teď na Vaší společné faktuře." : '') . "\n\nDům najdete v partnerském portálu: " . platform_url('/partner'));
    }
}
/** Výbor žádost odmítne nebo odebere přístup: účty pracovníků firmy ve webu se vypnou, zaplacené předplatné zůstává. */
function partner_revoke(array $t, string $by): void
{
    $p = partner_row((int) $t['partner_id']);
    $ids = array_map('intval', array_values((array) json_decode((string) (tenant_settings($t['slug'])['partner_users'] ?? ''), true)));
    if ($ids) {
        tenant_pdo($t['slug'])->exec('UPDATE users SET active = 0 WHERE id IN (' . implode(',', $ids) . ')');
    }
    tenant_set($t['slug'], 'partner_users', '');
    pq("UPDATE tenants SET partner_id = 0, partner_status = '', partner_billing = 0, partner_since = '' WHERE slug = ?", [$t['slug']]);
    platform_log('Partner: přístup odebrán', $t['slug'] . ' · ' . ($p['name'] ?? '') . ' · ' . $by);
    if ($p && filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
        platform_mail($p['email'], 'Přístup k webu ukončen – ' . $t['name'], "Dobrý den,\n\nvýbor společenství " . $t['name'] . " ukončil přístup Vaší firmy k administraci webu."
            . " Již zaplacené předplatné domu zůstává v platnosti; další období si společenství hradí samo.");
    }
}
/** Id účtů pracovníků partnera ve webu domu (nezapočítávají se do limitu správců). */
function partner_tenant_user_ids(): array
{
    return array_map('intval', array_values((array) json_decode(setting('partner_users'), true)));
}

// ---------- Vstup pracovníka do administrace domu ----------

/**
 * Připraví jednorázový odkaz (platí 2 minuty), kterým pracovník partnera vstoupí do administrace domu jako správce.
 * Ve webu domu mu případně založí účet správce bez hesla. Vrací adresu odkazu, nebo chybu jako ['error' => …].
 */
function partner_enter_link(array $pu, array $partner, array $t): array
{
    if ((int) $t['partner_id'] !== (int) $partner['id'] || $t['partner_status'] !== 'active') {
        return ['error' => 'K tomuto domu nemáte přístup.'];
    }
    $pdo = tenant_pdo($t['slug']);
    $map = (array) json_decode((string) (tenant_settings($t['slug'])['partner_users'] ?? ''), true);
    $display = $pu['name'] . ' (' . $partner['name'] . ')';
    $find = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $find->execute([$pu['email']]);
    $u = $find->fetch();
    if ($u && !isset($map[strtolower($pu['email'])])) {
        // E-mail už ve webu patří jinému účtu (třeba pracovník bydlí v domě) – ten nepřebíráme.
        return ['error' => 'Ve webu tohoto domu už existuje jiný účet s e-mailem ' . $pu['email'] . '. Požádejte výbor, ať ho upraví, nebo použijte jiný e-mail.'];
    }
    if (!$u) {
        $pdo->prepare("INSERT INTO users (name, email, role, kind, password_hash) VALUES (?,?,'admin','owner',?)")->execute([$display, $pu['email'], NO_PASSWORD]);
        $uid = (int) $pdo->lastInsertId();
        $map[strtolower($pu['email'])] = $uid;
        tenant_set($t['slug'], 'partner_users', json_encode($map));
    } else {
        $uid = (int) $u['id'];
        $pdo->prepare("UPDATE users SET name = ?, role = 'admin', active = 1 WHERE id = ?")->execute([$display, $uid]);
    }
    $token = bin2hex(random_bytes(32));
    tenant_set($t['slug'], 'partner_login', json_encode(['hash' => hash('sha256', $token), 'expires' => time() + 120, 'uid' => $uid, 'by' => $display], JSON_UNESCAPED_UNICODE));
    return ['link' => tenant_url($t, '/spravce-vstup/' . $token)];
}

// ---------- Společná faktura ----------

/** Otevřená výzva k platbě partnera (nejvýše jedna). */
function partner_open_payment(int $partnerId): ?array
{
    $p = pq("SELECT * FROM payments WHERE partner_id = ? AND kind = 'partner' AND status IN ('pending','claimed') ORDER BY id DESC LIMIT 1", [$partnerId])->fetch();
    return $p ?: null;
}
/** Řádky faktury partnera (dům, edice, cena) z uložené platby. */
function partner_lines(array $p): array
{
    return array_values(array_filter((array) json_decode((string) $p['item'], true), 'is_array'));
}
/**
 * Výzva k platbě za vybrané domy: $choice = [slug => edice]. Cena edice (bez DPH) mínus nevyčerpaná část dražší
 * nižší edice, z celku množstevní sleva podle počtu domů partnera. Vrací platbu, nebo chybu jako string.
 */
function partner_order(array $partner, array $choice): array|string
{
    $open = partner_open_payment((int) $partner['id']);
    if ($open && $open['status'] === 'claimed') {
        return 'Platbu za předchozí fakturu už jste nahlásili jako odeslanou. Novou vystavíte, až ji potvrdíme.';
    }
    $lines = [];
    foreach ($choice as $slug => $edition) {
        $t = tenant_row((string) $slug);
        if (!$t || (int) $t['partner_id'] !== (int) $partner['id'] || $t['partner_status'] !== 'active' || !(int) $t['partner_billing'] || !in_array($edition, ['standard', 'premium'], true)) {
            continue;
        }
        if ($problem = billing_order_problem($t, $edition)) {
            return $t['name'] . ': ' . $problem;
        }
        $price = max(0, edition_price($edition) - billing_credit($t, $edition));
        $lines[] = ['slug' => $t['slug'], 'name' => $t['name'], 'edition' => $edition, 'price' => $price];
    }
    if (!$lines) {
        return 'Vyberte aspoň jeden dům a edici.';
    }
    $subtotal = array_sum(array_column($lines, 'price'));
    $pct = partner_discount(count(partner_tenants((int) $partner['id'])));
    $discount = (int) round($subtotal * $pct / 100);
    $json = json_encode($lines, JSON_UNESCAPED_UNICODE);
    if ($open) {
        pq('UPDATE payments SET amount = ?, item = ?, discount = ?, vat_rate = ?, created_at = ? WHERE id = ?', [$subtotal - $discount, $json, $discount, vat_rate_now(), date('Y-m-d H:i:s'), $open['id']]);
        return pq('SELECT * FROM payments WHERE id = ?', [$open['id']])->fetch();
    }
    pq("INSERT INTO payments (vat_rate, tenant, partner_id, amount, months, created_at, kind, item, discount) VALUES (" . vat_rate_now() . ",?,?,?,12,?,'partner',?,?)",
        ['partner:' . $partner['id'], $partner['id'], $subtotal - $discount, date('Y-m-d H:i:s'), $json, $discount]);
    $id = (int) platform_db()->lastInsertId();
    pq('UPDATE payments SET vs = ? WHERE id = ?', [date('y') . sprintf('%06d', $id), $id]);
    platform_log('Partner: společná faktura', $partner['name'] . ' · ' . count($lines) . ' domů · ' . ($subtotal - $discount) . ' Kč');
    return pq('SELECT * FROM payments WHERE id = ?', [$id])->fetch();
}
/** Odběratel na dokladu partnera. */
function partner_invoice_parties(array $partner): array
{
    return ['supplier' => operator(), 'note' => pset('invoice_note'), 'vat' => ['payer' => pset('vat_payer') === '1', 'rate' => pset_int('vat_rate', 0, 100)],
        'customer' => ['name' => $partner['name'], 'address' => $partner['address'], 'ico' => $partner['ico'], 'dic' => $partner['dic']]];
}
/** Potvrzení platby partnera: každý dům na faktuře se prodlouží o rok (od konce zaplaceného období, jinak ode dneška). */
function partner_billing_confirm(array $p, string $by): ?array
{
    $partner = partner_row((int) $p['partner_id']);
    if (!$partner || !in_array($p['status'], ['pending', 'claimed'], true)) {
        return null;
    }
    $today = date('Y-m-d');
    $lines = partner_lines($p);
    $min = $max = null;
    foreach ($lines as &$l) {
        $t = tenant_row($l['slug']);
        if (!$t) {
            $l['skipped'] = true;
            continue;
        }
        $same = tenant_edition($t) === $l['edition'];
        $from = $same && $t['paid_until'] >= $today ? date('Y-m-d', strtotime($t['paid_until'] . ' +1 day')) : $today;
        $to = date('Y-m-d', strtotime($from . ' +12 month -1 day'));
        $l += ['from' => $from, 'to' => $to, 'prev_until' => (string) $t['paid_until'], 'prev_plan' => (string) ($t['plan'] ?? '')];
        pq("UPDATE tenants SET billing = 1, paid_until = ?, plan = ?, reminded = '' WHERE slug = ?", [$to, $l['edition'], $t['slug']]);
        $min = $min === null ? $from : min($min, $from);
        $max = $max === null ? $to : max($max, $to);
    }
    unset($l);
    $year = date('Y');
    $no = $year . sprintf('%04d', 1 + (int) pq('SELECT COUNT(*) FROM payments WHERE invoice_no LIKE ?', [$year . '%'])->fetchColumn());
    pq("UPDATE payments SET status = 'paid', paid_at = ?, period_from = ?, period_to = ?, confirmed_by = ?, invoice_no = ?, invoice_data = ?, item = ? WHERE id = ?",
        [date('Y-m-d H:i:s'), $min ?? $today, $max ?? $today, $by, $no, json_encode(partner_invoice_parties($partner), JSON_UNESCAPED_UNICODE), json_encode($lines, JSON_UNESCAPED_UNICODE), $p['id']]);
    if (filter_var($partner['email'], FILTER_VALIDATE_EMAIL)) {
        platform_mail($partner['email'], 'Platba přijata – faktura ' . $no, "Dobrý den,\n\npřijali jsme platbu " . pay_money($p) . " (variabilní symbol " . $p['vs'] . ").\n"
            . 'Prodloužili jsme předplatné ' . count($lines) . " domů na faktuře.\n\nFakturu najdete v partnerském portálu: " . platform_url('/partner/fakturace') . "\n\nDěkujeme.");
    }
    return ['from' => $min ?? $today, 'to' => $max ?? $today];
}
/** Podklady pro tisk dokladu partnera (zálohová faktura, faktura). */
function partner_invoice_view(array $p): ?array
{
    $partner = partner_row((int) $p['partner_id']);
    $paid = in_array($p['status'], ['paid', 'storno'], true);
    $parties = $paid && $p['invoice_data'] !== '' ? (array) json_decode($p['invoice_data'], true) : ($partner ? partner_invoice_parties($partner) : null);
    if (!$parties || $p['status'] === 'cancelled') {
        return null;
    }
    $issued = substr((string) ($paid ? $p['paid_at'] : $p['created_at']), 0, 10);
    $iban = (string) ($parties['supplier']['iban'] ?? '');
    $lines = [];
    foreach (partner_lines($p) as $l) {
        $lines[] = ['Web společenství ' . $l['name'] . ' – edice ' . (EDITIONS[$l['edition']] ?? $l['edition']) . (isset($l['from']) ? ' v období ' . cz_date($l['from']) . ' – ' . cz_date($l['to']) : ' na 1 rok'), (int) $l['price']];
    }
    if ((int) $p['discount'] > 0) {
        $lines[] = ['Množstevní sleva správce', -(int) $p['discount']];
    }
    return ['item' => 'Předplatné domů ve správě', 'lines' => $lines, 'p' => $p, 'paid' => $paid, 'parties' => $parties, 'issued' => $issued,
        'due' => date('Y-m-d', strtotime($issued . ' +' . pset_int('invoice_due_days', 0, 90) . ' day')), 'site' => '', 'storno' => $p['status'] === 'storno', 'credit' => false,
        'qr' => !$paid && $iban !== '' ? qr_svg(spayd($iban, pay_total($p), $p['vs'], 'Sprava domu ' . ($partner['name'] ?? ''), pset('recipient'), true), 'QR kód pro okamžitou platbu') : ''];
}
/** Storno zaplacené faktury partnera: domům, kterým faktura prodloužila předplatné (a od té doby se nezměnilo), se vrátí předchozí stav. */
function partner_storno_revert(array $p): array
{
    $notes = [];
    foreach (partner_lines($p) as $l) {
        $t = isset($l['to']) ? tenant_row($l['slug']) : null;
        if ($t && $t['paid_until'] === $l['to']) {
            pq('UPDATE tenants SET paid_until = ?, plan = ? WHERE slug = ?', [(string) $l['prev_until'], (string) $l['prev_plan'], $t['slug']]);
            $notes[] = $l['name'] . ': předplatné vráceno ' . ($l['prev_until'] !== '' ? 'do ' . cz_date($l['prev_until']) : 'na edici Zdarma');
        }
    }
    return $notes;
}

// ---------- Přehled domů pro partnera ----------

/** Co se v domě děje: nevyřízené závady, nejbližší shromáždění, revize do 30 dnů, poslední přihlášení. */
function partner_house_stats(string $slug): array
{
    try {
        $pdo = tenant_pdo($slug);
        $one = fn(string $sql, array $a = []) => ($st = $pdo->prepare($sql)) && $st->execute($a) ? $st->fetchColumn() : null;
        $today = date('Y-m-d');
        return ['tickets' => (int) $one("SELECT COUNT(*) FROM tickets WHERE status <> 'done'"),
            'event' => (string) ($one('SELECT event_date || \'|\' || title FROM events WHERE event_date >= ? ORDER BY event_date LIMIT 1', [$today]) ?: ''),
            'revisions' => (int) $one('SELECT COUNT(*) FROM maintenance WHERE next_date IS NOT NULL AND next_date <= ?', [date('Y-m-d', time() + 30 * 86400)]),
            'units' => (int) $one('SELECT COUNT(*) FROM units'),
            'users' => (int) $one('SELECT COUNT(*) FROM users WHERE active = 1'),
            'last_login' => (string) $one('SELECT MAX(last_login_at) FROM users')];
    } catch (Throwable $e) {
        return ['tickets' => 0, 'event' => '', 'revisions' => 0, 'units' => 0, 'users' => 0, 'last_login' => ''];
    }
}
