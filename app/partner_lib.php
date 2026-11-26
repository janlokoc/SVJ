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
function partner_enter_link(array $pu, array $partner, array $t, string $next = ''): array
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
    tenant_set($t['slug'], 'partner_login', json_encode(['hash' => hash('sha256', $token), 'expires' => time() + 120, 'uid' => $uid, 'by' => $display, 'next' => $next], JSON_UNESCAPED_UNICODE));
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
        $price = max(0, edition_price($edition, $t) - billing_credit($t, $edition));
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

/** Kam po vstupu do domu (z odkazu v portálu): k hlášení, nebo na přehled správy. */
function partner_next(): string
{
    if (ctype_digit((string) ($_GET['zavada'] ?? ''))) {
        return '/admin/zavady?f=vse#z' . (int) $_GET['zavada'];
    }
    return match ((string) ($_GET['k'] ?? '')) {
        'prehled' => '/admin/prehled-spravy',
        'zavady' => '/admin/zavady',
        'revize' => '/admin/revize',
        default => '',
    };
}
/** Je adresa po vstupu povolená (jen vybrané stránky administrace)? */
function partner_next_ok(string $next): bool
{
    return (bool) preg_match('~^/admin(/zavady(\?f=vse)?(#z\d+)?|/prehled-spravy|/revize)?$~D', $next);
}

// ---------- Ukázka partnerského portálu ----------

const PARTNER_DEMO_HOUSES = [
    'ukazka-lipova' => ['SVJ Lipová 8', 'Lipová 8', 'Lipová 8, Brno', 'les'],
    'ukazka-sady' => ['SVJ Sady 3', 'Sady 3', 'Sady 3, Brno', 'more'],
];

/**
 * Ukázková správcovská firma se třemi domy (ukázkový web Zahradní 12 a dva menší domy s hlášeními).
 * Založí se při prvním vstupu do ukázky; domy jsou jen pro čtení jako ukázkový web. Vrací pracovníka firmy, nebo null.
 */
function partner_demo_ensure(): ?array
{
    $demo = demo_tenant();
    if (!$demo) {
        return null;
    }
    $pid = (int) pset('demo_partner');
    if (!$pid || !partner_row($pid)) {
        pq('INSERT INTO partners (name, ico, address, email, phone, note, active, created_at) VALUES (?,?,?,?,?,?,1,?)',
            ['Ukázková správa domů s.r.o.', '', 'Masarykova 1, Brno', 'ukazka@example.invalid', '', 'ukázka partnerského portálu – jen pro čtení', date('Y-m-d H:i:s')]);
        $pid = (int) platform_db()->lastInsertId();
        pset_save('demo_partner', (string) $pid);
        pq("INSERT INTO partner_users (partner_id, name, email, password_hash, role, notify_tickets, created_at) VALUES (?,?,?,?,'owner',0,?)",
            [$pid, 'Petra Dvořáková', 'ukazka-partner@example.invalid', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
    }
    // Novější obsah ukázky (víc funkcí portálu): domy ukázky založit znovu.
    if (pset('demo_partner_v') !== '4') {
        foreach (array_keys(PARTNER_DEMO_HOUSES) as $slug) {
            if (($old = tenant_row($slug)) && !empty($old['demo'])) {
                tenant_delete_storage($slug);
                pq('DELETE FROM tenants WHERE slug = ?', [$slug]);
            }
        }
        pq("UPDATE partners SET web = 'https://www.example.com', phone = '+420 500 600 700', email = 'sprava@example.invalid', resale = '4500', brand = 1, ico = '00000000', address = 'Masarykova 1, 602 00 Brno', account = '000000-0000000000/0000' WHERE id = ?", [$pid]);
        if (!pq('SELECT 1 FROM partner_templates WHERE partner_id = ?', [$pid])->fetchColumn()) {
            foreach ([
                ['post', 'Odečty vodoměrů', 'Odečty vodoměrů a měřičů tepla', "Dobrý den,\n\nv úterý příští týden od 16 do 19 hodin proběhnou odečty vodoměrů a měřičů tepla. Prosíme o zpřístupnění bytů; kdo nebude doma, nechte prosím stav měřidel s číslem bytu ve schránce.\n\nDěkujeme, Ukázková správa domů"],
                ['post', 'Odstávka vody', 'Plánovaná odstávka vody', "Dobrý den,\n\nz důvodu opravy rozvodů bude ve čtvrtek od 8 do 12 hodin přerušena dodávka teplé i studené vody. Připravte si prosím zásobu.\n\nDěkujeme za pochopení."],
                ['mailing', 'Pozvánka na shromáždění', 'Pozvánka na shromáždění vlastníků', "Vážení vlastníci,\n\nvýbor Vás zve na shromáždění vlastníků jednotek. Program, místo a čas najdete v pozvánce na webu domu. Pokud se nemůžete zúčastnit, můžete se nechat zastoupit na základě plné moci (formulář je na webu).\n\nS pozdravem\nUkázková správa domů s.r.o."],
            ] as [$k, $n, $ti, $b]) {
                pq('INSERT INTO partner_templates (partner_id, kind, name, title, body, updated_at) VALUES (?,?,?,?,?,?)', [$pid, $k, $n, $ti, $b, date('Y-m-d H:i:s')]);
            }
        }
        pset_save('demo_partner_v', '4');
    }
    foreach (PARTNER_DEMO_HOUSES as $slug => [$name, $title, $address, $look]) {
        if (!tenant_row($slug)) {
            tenant_create(['slug' => $slug, 'name' => $name, 'title' => $title, 'address' => $address, 'look' => $look, 'style' => 'modern', 'demo' => 2, 'note' => 'ukázka partnerského portálu, jen pro čtení']);
            partner_demo_seed($slug);
        }
    }
    $since = date('Y-m-d H:i:s', strtotime('-200 days'));
    foreach (array_merge([$demo['slug']], array_keys(PARTNER_DEMO_HOUSES)) as $slug) {
        pq("UPDATE tenants SET partner_id = ?, partner_status = 'active', partner_billing = 1, partner_since = CASE WHEN partner_since = '' THEN ? ELSE partner_since END WHERE slug = ?", [$pid, $since, $slug]);
    }
    $u = pq('SELECT * FROM partner_users WHERE partner_id = ? AND active = 1 ORDER BY id LIMIT 1', [$pid])->fetch();
    return $u ?: null;
}
function partner_demo_id(): int
{
    return (int) pset('demo_partner');
}
/** Obsah malého ukázkového domu: správci, jednotky s vlastníky a několik hlášení v různém stavu. */
function partner_demo_seed(string $slug): void
{
    $pdo = tenant_pdo($slug);
    ticket_ready($pdo);
    $ins = fn(string $sql, array $p) => $pdo->prepare($sql)->execute($p) ? (int) $pdo->lastInsertId() : 0;
    $now = time();
    $at = fn(int $days) => date('Y-m-d H:i:s', $now - $days * 86400);
    $spravce = $ins("INSERT INTO users (name, email, role, kind, password_hash) VALUES (?,?,'admin','owner',?)", ['Petra Dvořáková (Ukázková správa domů s.r.o.)', 'ukazka-partner@example.invalid', NO_PASSWORD]);
    tenant_set($slug, 'partner_users', json_encode(['ukazka-partner@example.invalid' => $spravce]));
    tenant_set($slug, 'ico', '00000000');
    tenant_set($slug, 'account', '000000-0000000000/0000');
    $vybor = $ins("INSERT INTO users (name, email, role, kind, password_hash, position) VALUES (?,?,'admin','owner',?,?)", ['Tomáš Malý', 'predseda@' . $slug . '.example.invalid', NO_PASSWORD, 'predseda']);
    $people = [];
    foreach (['Eva Horáková', 'Martin Kučera', 'Lenka Pokorná', 'Jiří Navrátil', 'Hana Veselá'] as $i => $n) {
        $people[] = $ins("INSERT INTO users (name, email, role, kind, password_hash) VALUES (?,?,'resident','owner',?)", [$n, 'obyvatel' . ($i + 1) . '@' . $slug . '.example.invalid', NO_PASSWORD]);
        $unit = $ins('INSERT INTO units (entrance, number, share, owner_note) VALUES (?,?,?,?)', ['A', (string) ($i + 1), (1500 + $i * 120) . '/10000', $n]);
        $pdo->prepare('INSERT INTO user_units (user_id, unit_id) VALUES (?,?)')->execute([$people[$i], $unit]);
    }
    $ins('INSERT INTO posts (title, body, published_at) VALUES (?,?,?)', ['Odečty vodoměrů', 'Odečty proběhnou příští týden ve středu od 16 hodin. Prosíme o zpřístupnění bytů.', $at(3)]);
    $lip = $slug === 'ukazka-lipova';
    $day = fn(int $d) => date('Y-m-d', $now + $d * 86400);
    $ins('INSERT INTO events (title, event_date, event_time, place) VALUES (?,?,?,?)', [$lip ? 'Shromáždění vlastníků' : 'Odečet vodoměrů', $day($lip ? 24 : 9), $lip ? '18:00' : '16:00', $lip ? 'kočárkárna, přízemí' : '']);
    $ins('INSERT INTO maintenance (title, contractor, last_date, interval_months, next_date) VALUES (?,?,?,?,?)', [$lip ? 'Revize elektroinstalace' : 'Revize výtahu', 'Revize Brno s.r.o.', $day(-370), 12, $day($lip ? -5 : 18)]);
    $ins('INSERT INTO maintenance (title, contractor, last_date, interval_months, next_date) VALUES (?,?,?,?,?)', ['Kontrola hasicích přístrojů', 'Požární servis', $day(-340), 12, $day($lip ? 25 : 40)]);
    $tickets = $slug === 'ukazka-lipova' ? [
        [0, 'Nefunguje zvonek u vchodu', 'vchod A', 'Zvonek k bytu 3 nezvoní, displej nesvítí.', 'new', 1, 0, ''],
        [1, 'Zatéká do sklepa po dešti', 'sklep, kóje 4', 'Po včerejším dešti je na podlaze voda, teče od okénka.', 'in_progress', 9, $spravce, date('Y-m-d', $now - 2 * 86400)],
        [2, 'Výměna zámku u kočárkárny', 'přízemí', 'Zámek jde ztuha, klíč se zasekává.', 'done', 30, $vybor, ''],
    ] : [
        [3, 'Hluk z výtahu', 'výtah', 'Při jízdě do 3. patra výtah skřípe a cuká.', 'in_progress', 4, $spravce, date('Y-m-d', $now + 5 * 86400)],
        [4, 'Prasklá dlaždice na chodbě', '2. patro', 'U schodů je prasklá dlaždice, hrozí zakopnutí.', 'new', 0, 0, ''],
    ];
    foreach ($tickets as [$who, $subject, $loc, $body, $status, $days, $assignee, $due]) {
        $id = $ins('INSERT INTO tickets (user_id, subject, location, body, status, created_at, assignee_id, due_date) VALUES (?,?,?,?,?,?,?,?)', [$people[$who], $subject, $loc, $body, $status, $at($days), $assignee, $due]);
        $ins("INSERT INTO ticket_log (ticket_id, at, user_id, user_name, kind, text) VALUES (?,?,?,?,'created','')", [$id, $at($days), $people[$who], '']);
        if ($assignee) {
            $ins("INSERT INTO ticket_log (ticket_id, at, user_id, user_name, kind, text) VALUES (?,?,?,?,'assign',?)", [$id, $at(max(0, $days - 1)), $vybor, 'Tomáš Malý', $assignee === $spravce ? 'Petra Dvořáková (Ukázková správa domů s.r.o.)' : 'Tomáš Malý']);
        }
        if ($status !== 'new') {
            $ins("INSERT INTO ticket_log (ticket_id, at, user_id, user_name, kind, text) VALUES (?,?,?,?,'msg_admin',?)", [$id, $at(max(0, $days - 1)), $spravce, 'Petra Dvořáková (Ukázková správa domů s.r.o.)',
                $status === 'done' ? 'Zámek je vyměněný, nové klíče jsou u předsedy.' : 'Dobrý den, objednali jsme technika, ozve se Vám s termínem.']);
        }
    }
}

// ---------- Společný kalendář domů ----------

const PARTNER_CAL_KINDS = ['termin' => 'Termín', 'revize' => 'Revize', 'zavada' => 'Termín hlášení', 'hlasovani' => 'Konec hlasování'];

/** Termíny všech domů partnera od $from do $to (revize a hlášení po termínu i dřív): [['date','time','kind','title','house','slug','late','k'], …]. */
function partner_calendar(array $houses, string $from, string $to): array
{
    $today = date('Y-m-d');
    $items = [];
    foreach ($houses as $h) {
        try {
            $pdo = tenant_pdo($h['slug']);
            ticket_ready($pdo);
            $q = function (string $sql, array $p) use ($pdo) {
                $st = $pdo->prepare($sql);
                $st->execute($p);
                return $st->fetchAll();
            };
            $add = function (string $date, string $time, string $kind, string $title, string $k) use (&$items, $h, $today): void {
                $items[] = ['date' => $date, 'time' => $time, 'kind' => $kind, 'title' => $title, 'house' => $h['name'], 'slug' => $h['slug'], 'late' => $date < $today && $kind !== 'termin', 'k' => $k];
            };
            foreach ($q('SELECT title, event_date, event_time FROM events WHERE event_date >= ? AND event_date <= ?', [$from, $to]) as $r) {
                $add($r['event_date'], (string) $r['event_time'], 'termin', $r['title'], '');
            }
            foreach ($q("SELECT title, next_date FROM maintenance WHERE next_date IS NOT NULL AND next_date <> '' AND next_date <= ?", [$to]) as $r) {
                $add($r['next_date'], '', 'revize', $r['title'], 'revize');
            }
            foreach ($q("SELECT id, subject, due_date FROM tickets WHERE status <> 'done' AND due_date <> '' AND due_date <= ?", [$to]) as $r) {
                $add($r['due_date'], '', 'zavada', $r['subject'], 'zavada=' . (int) $r['id']);
            }
            foreach ($q('SELECT title, closes_at FROM polls WHERE published = 1 AND closes_at >= ? AND closes_at <= ?', [$from, $to]) as $r) {
                $add($r['closes_at'], '', 'hlasovani', $r['title'], '');
            }
        } catch (Throwable $e) {
            error_log('SVJ partner, kalendář domu ' . $h['slug'] . ': ' . $e->getMessage());
        }
    }
    usort($items, fn($a, $b) => [$a['date'], $a['time'], $a['house']] <=> [$b['date'], $b['time'], $b['house']]);
    return $items;
}
/** Kalendář pro odběr (Google, Outlook, telefon): celodenní události s připomenutím den předem. */
function partner_calendar_ics(array $partner, array $items): string
{
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//SVJ web//CS', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . ics_text($partner['name'] . ' – domy'), 'X-WR-TIMEZONE:Europe/Prague'];
    foreach ($items as $i => $it) {
        $d = strtotime($it['date']);
        array_push($lines, 'BEGIN:VEVENT', 'UID:' . md5($it['slug'] . $it['kind'] . $it['title'] . $it['date']) . '@' . platform_domain(), 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART;VALUE=DATE:' . date('Ymd', $d), 'DTEND;VALUE=DATE:' . date('Ymd', $d + 86400),
            'SUMMARY:' . ics_text(PARTNER_CAL_KINDS[$it['kind']] . ': ' . $it['title'] . ' – ' . $it['house']),
            'DESCRIPTION:' . ics_text(($it['time'] !== '' ? 'Začátek ' . $it['time'] . "\n" : '') . platform_url('/partner/kalendar')),
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . ics_text($it['title']), 'TRIGGER:-P1D', 'END:VALARM', 'END:VEVENT');
    }
    $lines[] = 'END:VCALENDAR';
    return ics_fold($lines);
}

// ---------- Značka firmy na webech domů a registrační odkaz ----------

function partner_logo_path(array $p): string
{
    return preg_match('/^logo-\d+-[a-f0-9]{16}\.(png|jpg)$/', (string) ($p['logo'] ?? '')) ? ROOT_STORAGE . '/partners/' . $p['logo'] : '';
}
/** Logo jako data: adresa (web domu běží na jiné adrese než platforma a cizí obrázky nenačítá). */
function partner_logo_data(array $p): string
{
    $f = partner_logo_path($p);
    if ($f === '' || !is_file($f) || filesize($f) > 200000) {
        return '';
    }
    return 'data:image/' . (str_ends_with($f, '.png') ? 'png' : 'jpeg') . ';base64,' . base64_encode((string) file_get_contents($f));
}
/** Uloží logo firmy (PNG nebo JPG do 200 kB). Vrací chybu nebo null. */
function partner_logo_save(array $p, array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Logo se nepodařilo nahrát.';
    }
    $info = @getimagesize((string) $file['tmp_name']);
    $ext = match ($info[2] ?? 0) { IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', default => '' };
    if ($ext === '' || (int) $file['size'] > 200000) {
        return 'Logo musí být obrázek PNG nebo JPG do 200 kB.';
    }
    $dir = ROOT_STORAGE . '/partners';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $name = 'logo-' . (int) $p['id'] . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name) && !copy((string) $file['tmp_name'], $dir . '/' . $name)) {
        return 'Logo se nepodařilo uložit.';
    }
    if (($old = partner_logo_path($p)) !== '') {
        @unlink($old);
    }
    pq('UPDATE partners SET logo = ? WHERE id = ?', [$name, $p['id']]);
    return null;
}
/** Kód pro registrační odkaz partnera (vytvoří se při prvním použití). */
function partner_ref_code(array $p): string
{
    if ((string) ($p['ref_code'] ?? '') !== '') {
        return $p['ref_code'];
    }
    $code = substr(preg_replace('/[^a-z0-9]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $p['name']) ?: 'partner')), 0, 12) . '-' . bin2hex(random_bytes(3));
    pq('UPDATE partners SET ref_code = ? WHERE id = ?', [$code, $p['id']]);
    return $code;
}
function partner_by_code(string $code): ?array
{
    if (!preg_match('/^[a-z0-9-]{4,40}$/', $code)) {
        return null;
    }
    $p = pq('SELECT * FROM partners WHERE ref_code = ? AND active = 1', [$code])->fetch();
    return $p ?: null;
}
/** Správcovská firma domu pro zobrazení na webu (patička, kontakty), nebo null. Jen v kontextu webu domu. */
function partner_brand(): ?array
{
    static $b = false;
    if ($b !== false) {
        return $b;
    }
    $b = null;
    if (!defined('TENANT') || TENANT === '' || !function_exists('tenant_row') || !($t = tenant_row(TENANT)) || !(int) ($t['partner_id'] ?? 0) || $t['partner_status'] !== 'active') {
        return $b;
    }
    $p = partner_row((int) $t['partner_id']);
    if ($p && $p['active'] && (int) ($p['brand'] ?? 1)) {
        $b = ['name' => $p['name'], 'phone' => $p['phone'], 'email' => $p['email'], 'web' => (string) ($p['web'] ?? ''), 'logo' => partner_logo_data($p)];
    }
    return $b;
}

// ---------- Hromadné akce a šablony firmy ----------

const PARTNER_TPL_KINDS = ['post' => 'Aktualita', 'mailing' => 'E-mail obyvatelům'];

function partner_templates(int $partnerId, string $kind = ''): array
{
    return pq('SELECT * FROM partner_templates WHERE partner_id = ?' . ($kind !== '' ? ' AND kind = ' . platform_db()->quote($kind) : '') . ' ORDER BY name', [$partnerId])->fetchAll();
}
/** Šablony firmy, která spravuje tento dům (pro formuláře v administraci domu). */
function partner_house_templates(string $kind): array
{
    if (!defined('TENANT') || TENANT === '' || !($t = tenant_row(TENANT)) || !(int) ($t['partner_id'] ?? 0) || $t['partner_status'] !== 'active') {
        return [];
    }
    return partner_templates((int) $t['partner_id'], $kind);
}
/**
 * Aktualita (a případně příloha) do více domů najednou. $file = ['tmp' => cesta, 'name' => původní název] nebo null.
 * Vrací [počet domů, chyby].
 */
function partner_bulk_post(array $pu, array $partner, array $slugs, string $title, string $body, string $visibility, ?array $file): array
{
    $done = 0;
    $errors = [];
    $display = $pu['name'] . ' (' . $partner['name'] . ')';
    foreach ($slugs as $slug) {
        $t = tenant_row((string) $slug);
        if (!$t || (int) $t['partner_id'] !== (int) $partner['id'] || $t['partner_status'] !== 'active') {
            continue;
        }
        try {
            $pdo = tenant_pdo($t['slug']);
            $map = (array) json_decode((string) (tenant_settings($t['slug'])['partner_users'] ?? ''), true);
            $author = (int) ($map[strtolower($pu['email'])] ?? 0) ?: null;
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO posts (title, body, visibility, pinned, published_at, author_id) VALUES (?,?,?,0,?,?)')->execute([$title, $body, $visibility, date('Y-m-d'), $author]);
            $postId = (int) $pdo->lastInsertId();
            if ($file) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $dir = tenant_storage($t['slug']) . '/uploads';
                if (!is_dir($dir)) {
                    mkdir($dir, 0700, true);
                }
                $name = bin2hex(random_bytes(16)) . '.' . $ext;
                if (!copy($file['tmp'], $dir . '/' . $name)) {
                    throw new RuntimeException('příloha se nepodařila uložit');
                }
                $pdo->prepare("INSERT INTO documents (title, category, visibility, filename, original_name, size, doc_date) VALUES (?,'oznameni',?,?,?,?,?)")
                    ->execute([pathinfo($file['name'], PATHINFO_FILENAME), $visibility, $name, basename($file['name']), (int) filesize($dir . '/' . $name), date('Y-m-d')]);
                $pdo->prepare('INSERT INTO post_documents (post_id, document_id) VALUES (?,?)')->execute([$postId, (int) $pdo->lastInsertId()]);
            }
            $pdo->commit();
            $done++;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $t['name'] . ': ' . $e->getMessage();
        }
    }
    return [$done, $errors];
}

// ---------- Faktury partnera společenstvím (SVJ platí partnerovi, partner platformě) ----------

const PARTNER_SVJ_STATUS = ['issued' => 'čeká na platbu', 'paid' => 'zaplaceno', 'cancelled' => 'zrušeno'];

/** Fakturační údaje odběratele (SVJ) z nastavení jeho webu. */
function partner_svj_customer(array $t): array
{
    $s = tenant_settings($t['slug']);
    return ['name' => (string) ($t['full'] !== '' ? $t['full'] : $t['name']), 'address' => (string) (($s['address'] ?? '') !== '' ? $s['address'] : $t['address']), 'ico' => (string) ($s['ico'] ?? ''), 'dic' => ''];
}
/** Partner může fakturovat: má IČO, adresu a účet. Vrací chybějící údaje (prázdné pole = vše v pořádku). */
function partner_svj_missing(array $p): array
{
    return array_keys(array_filter(['IČO' => $p['ico'] === '', 'sídlo' => $p['address'] === '', 'číslo účtu' => ($p['account'] ?? '') === '']));
}
/** Vystaví fakturu společenství za web na dané období. Vrací id faktury. */
function partner_svj_issue(array $p, array $t, string $from, string $to, int $amount, string $by): int
{
    $year = date('Y');
    $seq = 1 + (int) pq("SELECT COUNT(*) FROM partner_svj_invoices WHERE partner_id = ? AND number LIKE ?", [$p['id'], $year . '-%'])->fetchColumn();
    $number = $year . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    $vs = $year . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    $supplier = ['name' => $p['name'], 'address' => $p['address'], 'ico' => $p['ico'], 'dic' => $p['dic'], 'email' => $p['email'], 'phone' => $p['phone'], 'account' => (string) $p['account'], 'iban' => (string) $p['iban']];
    $issued = date('Y-m-d');
    pq('INSERT INTO partner_svj_invoices (partner_id, tenant, number, vs, issued, due, period_from, period_to, item, amount, vat_rate, data, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$p['id'], $t['slug'], $number, $vs, $issued, date('Y-m-d', strtotime($issued . ' +' . max(0, (int) ($p['inv_due'] ?? 14)) . ' day')), $from, $to,
            'Web společenství ' . $t['name'] . ' (' . tenant_address($t['slug']) . ')', $amount, (int) ($p['vat_rate'] ?? 0),
            json_encode(['supplier' => $supplier, 'customer' => partner_svj_customer($t)], JSON_UNESCAPED_UNICODE), $by, date('Y-m-d H:i:s')]);
    return (int) platform_db()->lastInsertId();
}
/** Data pro zobrazení faktury. */
function partner_svj_view(array $inv): array
{
    $data = (array) json_decode($inv['data'], true);
    $vat = (int) round($inv['amount'] * $inv['vat_rate'] / 100);
    $total = $inv['amount'] + $vat;
    $iban = preg_replace('/\s+/', '', (string) ($data['supplier']['iban'] ?? ''));
    return ['inv' => $inv, 's' => $data['supplier'] ?? [], 'c' => $data['customer'] ?? [], 'vat' => $vat, 'total' => $total,
        'qr' => $inv['status'] === 'issued' && $iban !== '' ? qr_svg(spayd($iban, $total, $inv['vs'], 'Web SVJ ' . $inv['number'], (string) ($data['supplier']['name'] ?? ''), true), 'QR kód pro okamžitou platbu') : ''];
}
/** Pošle výboru (správcům webu) e-mail o nové faktuře s odkazem do administrace. */
function partner_svj_notify(array $p, array $t, int $invId): void
{
    $inv = pq('SELECT * FROM partner_svj_invoices WHERE id = ?', [$invId])->fetch();
    $emails = array_column(tenant_pdo($t['slug'])->query("SELECT email FROM users WHERE role = 'admin' AND active = 1 AND email NOT LIKE '%.invalid'")->fetchAll(), 'email');
    $map = array_map('strtolower', array_keys((array) json_decode((string) (tenant_settings($t['slug'])['partner_users'] ?? ''), true)));
    foreach (array_unique($emails) as $email) {
        if (!in_array(strtolower($email), $map, true) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            platform_mail($email, 'Faktura za web domu od správce ' . $p['name'], "Dobrý den,\n\nsprávce domu " . $p['name'] . ' Vám vystavil fakturu č. ' . $inv['number'] . ' za web společenství ' . $t['name']
                . ' (' . money_czk((int) $inv['amount']) . ($inv['vat_rate'] ? ' bez DPH' : '') . ', splatnost ' . cz_date($inv['due']) . ").\n\nFakturu najdete v administraci webu: " . tenant_url($t, '/admin/predplatne#spravce-faktury'));
        }
    }
}
