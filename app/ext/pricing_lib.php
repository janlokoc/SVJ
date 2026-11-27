<?php
declare(strict_types=1);

/*
 * Modul G – ceny podle velikosti domu, edice Zdarma podle velikosti, jednorázové hlasování, ceny s DPH na veřejném webu
 * a ověření aplikace pro Android (Trusted Web Activity, /.well-known/assetlinks.json).
 *
 * Nastavení (Nastavení služby → Edice a ceny; klíče v PLATFORM_DEFAULTS):
 *   price_flat_units    – do kolika jednotek platí paušál (výchozí 50)
 *   price_unit_standard – přirážka za každou další jednotku a rok v edici Standard (výchozí 20 Kč)
 *   price_unit_premium  – totéž v edici Premium (výchozí 40 Kč)
 *   free_max_units      – edice Zdarma jen pro domy do N jednotek (0 = bez omezení, dosavadní chování)
 *   price_vote          – cena jednorázového hlasování (0 = nenabízet)
 *   compare_on          – veřejná stránka /srovnani (0 = vypnuto, dokud ji provozovatel nezkontroluje)
 *   twa_package, twa_sha256 – aplikace pro Android: název balíčku a otisk(y) podpisového certifikátu
 * Ceny jsou bez DPH. Už zaplacená období se nemění: nová cena platí pro nové výzvy k platbě.
 */

const VOTE_PASS_DAYS = 60;

// ---------- Velikost domu ----------

/** Počet jednotek domu (z databáze jeho webu). $reload načte znovu (po změně jednotek). */
function tenant_unit_count(array $t, bool $reload = false): int
{
    static $cache = [];
    $slug = (string) ($t['slug'] ?? '');
    if ($slug === '') {
        return 0;
    }
    if (!$reload && isset($cache[$slug])) {
        return $cache[$slug];
    }
    try {
        $n = is_dir(tenant_storage($slug)) ? (int) tenant_pdo($slug)->query('SELECT COUNT(*) FROM units')->fetchColumn() : 0;
    } catch (Throwable $e) {
        $n = 0;
    }
    return $cache[$slug] = $n;
}
/** Do kolika jednotek platí paušál. */
function price_flat_units(): int
{
    return pset_int('price_flat_units', 0, 100000);
}
/** Přirážka za jednu jednotku nad paušál a rok (bez DPH). */
function price_unit_extra(string $edition): int
{
    return in_array($edition, ['standard', 'premium'], true) ? pset_int('price_unit_' . $edition, 0, 100000) : 0;
}
/** Přirážka za velikost domu: (jednotky nad paušál) × cena za jednotku. */
function edition_surcharge(string $edition, int $units): int
{
    return max(0, $units - price_flat_units()) * price_unit_extra($edition);
}
/** Věta do ceníku: „do 50 jednotek paušál, nad to +20 Kč za jednotku a rok“ (prázdná, když přirážka není). */
function price_size_note(string $edition): string
{
    $per = price_unit_extra($edition);
    return $per > 0 ? 'do ' . price_flat_units() . ' jednotek paušál, nad to +' . money_czk($per) . ' za každou další jednotku a rok' : '';
}

// ---------- Edice Zdarma podle velikosti ----------

function free_max_units(): int
{
    return pset_int('free_max_units', 0, 100000);
}
/** Smí dům mít edici Zdarma? (0 = bez omezení) */
function free_size_allowed(array $t): bool
{
    $max = free_max_units();
    return $max === 0 || tenant_unit_count($t) <= $max;
}
/**
 * Dům nad hranicí edice Zdarma, který ještě nic neplatil ani nezkoušel, dostane jednou zkušební dobu edice Standard
 * (PLAN_TRIAL_DAYS dní). Potom platí, nebo zůstane v omezeném režimu s limity edice Zdarma. Vrací true, když zkoušku zapnul.
 */
function free_size_check(?array $t): bool
{
    if (!$t || empty($t['billing']) || !empty($t['demo']) || free_max_units() === 0 || (string) $t['trial_ends'] !== '' || (string) $t['paid_until'] !== '') {
        return false;
    }
    if (free_size_allowed($t)) {
        return false;
    }
    $ends = date('Y-m-d', strtotime('+' . PLAN_TRIAL_DAYS . ' day'));
    pq("UPDATE tenants SET trial_ends = ? WHERE slug = ? AND trial_ends = '' AND paid_until = ''", [$ends, $t['slug']]);
    platform_log('Zkušební doba Standard pro velký dům', $t['slug'] . ' · ' . tenant_unit_count($t) . ' jednotek, edice Zdarma je do ' . free_max_units());
    return true;
}
/** Je dům v omezeném režimu (nad hranicí edice Zdarma, bez placené edice)? */
function free_size_limited(?array $t): bool
{
    return $t && !empty($t['billing']) && tenant_edition($t) === 'free' && !free_size_allowed($t);
}

// ---------- Ceny s DPH na veřejném ceníku ----------

/**
 * Dovětek k ceně na veřejném webu. Ceny se uvádějí jen bez DPH (rozhodnutí provozovatele 11/2026), proto u plátce nic;
 * neplátce DPH upozorní, že cena je konečná.
 */
function price_vat_html(int $base): string
{
    if ($base <= 0 || vat_payer()) {
        return '';
    }
    return '<small class="vat-incl">konečná cena, nejsme plátci DPH</small>';
}
/** Cena s DPH jako číslo (neplátce = stejná). */
function price_gross(int $base): float
{
    return vat_payer() ? vat_split($base, vat_rate_now())['total'] : (float) $base;
}

// ---------- Jednorázové hlasování ----------

function vote_price(): int
{
    return pset_int('price_vote', 0, 1000000);
}
/** Nabízí se jednorázové hlasování? Jen webům bez předplatného (edice Zdarma) a když má cenu. */
function vote_offerable(?array $t): bool
{
    return $t && !empty($t['billing']) && empty($t['demo']) && vote_price() > 0 && tenant_edition($t) === 'free';
}
/** Otevřená objednávka jednorázového hlasování, nebo null. */
function vote_payment(array $t): ?array
{
    $p = pq("SELECT * FROM payments WHERE tenant = ? AND kind = 'vote' AND status IN ('pending','claimed') ORDER BY id DESC LIMIT 1", [$t['slug']])->fetch();
    return $p ?: null;
}
/** Objedná jednorázové hlasování (výzva k platbě). Vrací platbu, nebo chybu jako text. */
function vote_order(array $t): array|string
{
    if (!vote_offerable($t)) {
        return 'Jednorázové hlasování se nabízí jen webům v edici Zdarma.';
    }
    if ($p = vote_payment($t)) {
        return $p;   // jedna objednávka najednou; čekající nebo nahlášená zůstává
    }
    pq("INSERT INTO payments (vat_rate, tenant, amount, months, created_at, kind, item) VALUES (" . vat_rate_now() . ",?,?,0,?,'vote','1')", [$t['slug'], vote_price(), date('Y-m-d H:i:s')]);
    $id = (int) platform_db()->lastInsertId();
    pq('UPDATE payments SET vs = ? WHERE id = ?', [date('y') . sprintf('%06d', $id), $id]);
    return pq('SELECT * FROM payments WHERE id = ?', [$id])->fetch();
}
/** Potvrzení platby za jednorázové hlasování (volá billing_confirm): jedno hlasování navíc na VOTE_PASS_DAYS dní. */
function vote_confirm(array $p, string $by): ?array
{
    $t = tenant_row((string) $p['tenant']);
    if (!$t || !in_array($p['status'], ['pending', 'claimed'], true)) {
        return null;
    }
    $from = date('Y-m-d');
    $to = date('Y-m-d', strtotime('+' . (VOTE_PASS_DAYS - 1) . ' day'));
    $year = date('Y');
    $no = $year . sprintf('%04d', 1 + (int) pq('SELECT COUNT(*) FROM payments WHERE invoice_no LIKE ?', [$year . '%'])->fetchColumn());
    pq("UPDATE payments SET status = 'paid', paid_at = ?, period_from = ?, period_to = ?, confirmed_by = ?, invoice_no = ?, invoice_data = ? WHERE id = ?",
        [date('Y-m-d H:i:s'), $from, $to, $by, $no, json_encode(invoice_parties($t), JSON_UNESCAPED_UNICODE), $p['id']]);
    if ($t['owner_email'] !== '') {
        platform_mail($t['owner_email'], 'Jednorázové hlasování je zaplacené – ' . $t['name'], "Dobrý den,\n\npřijali jsme platbu " . pay_money($p) . " za jednorázové hlasování.\n"
            . 'Do ' . cz_date($to) . ' můžete na webu ' . $t['name'] . " vypsat jedno hlasování nad limit edice Zdarma: " . tenant_url($t, '/admin/hlasovani/nove')
            . "\nFakturu č. $no si můžete stáhnout po přihlášení do administrace: " . tenant_url($t, '/admin/predplatne/doklad/' . $p['id']) . "\n\nDěkujeme.");
    }
    return ['from' => $from, 'to' => $to];
}
/** Kolik hlasování navíc má web díky zaplaceným jednorázovým hlasováním, která ještě platí. */
function vote_extra_polls(?array $t): int
{
    if (!$t) {
        return 0;
    }
    return (int) pq("SELECT COUNT(*) FROM payments WHERE tenant = ? AND kind = 'vote' AND status = 'paid' AND period_to >= ?", [$t['slug'], date('Y-m-d')])->fetchColumn();
}
/** Text položky na faktuře. */
function vote_invoice_item(array $p): string
{
    return 'Jednorázové hlasování – jedno hlasování nad limit edice Zdarma na ' . VOTE_PASS_DAYS . ' dní';
}

// ---------- Aplikace pro Android (Trusted Web Activity) ----------

/** Otisky SHA-256 podpisového certifikátu z nastavení (víc otisků oddělte řádkem nebo čárkou); neplatné se vynechají. */
function twa_fingerprints(?string $raw = null): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', strtoupper(trim($raw ?? pset('twa_sha256')))) as $f) {
        if (preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $f)) {
            $out[] = $f;
        } elseif (preg_match('/^[0-9A-F]{64}$/', $f)) {
            $out[] = implode(':', str_split($f, 2));   // otisk bez dvojteček
        }
    }
    return array_values(array_unique($out));
}
function twa_package_valid(string $package): bool
{
    return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $package) && strlen($package) <= 150;
}
/**
 * Obsah /.well-known/assetlinks.json (Digital Asset Links): aplikace z obchodu smí otevírat tento web na celou obrazovku.
 * Bez nastavené aplikace vrací null (adresa pak neexistuje). Dokumentace:
 * https://developer.android.com/training/app-links/verify-android-applinks a
 * https://developer.chrome.com/docs/android/trusted-web-activity/quick-start
 */
function twa_assetlinks(): ?array
{
    $package = trim(pset('twa_package'));
    $prints = twa_fingerprints();
    if (!twa_package_valid($package) || !$prints) {
        return null;
    }
    return [['relation' => ['delegate_permission/common.handle_all_urls'],
        'target' => ['namespace' => 'android_app', 'package_name' => $package, 'sha256_cert_fingerprints' => $prints]]];
}
function twa_send(): void
{
    $links = twa_assetlinks();
    if ($links === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found\n";
        exit;
    }
    header('Content-Type: application/json');
    header('Cache-Control: public, max-age=3600');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($links, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
/** Popis aplikace pro hlavní doménu služby (podklad pro Bubblewrap); weby domů mají vlastní /manifest.webmanifest. */
function platform_manifest(): array
{
    return ['id' => '/', 'name' => pset('service_name'), 'short_name' => mb_substr(pset('service_name'), 0, 12), 'start_url' => '/', 'scope' => '/', 'display' => 'standalone', 'lang' => 'cs',
        'background_color' => '#ffffff', 'theme_color' => '#2f6b3f',
        'icons' => [['src' => asset('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'], ['src' => asset('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable']]];
}

// ---------- Nastavení služby: kontrola hodnot modulu ----------

/** Chyby v hodnotách modulu z formuláře Nastavení služby (volá app/platform_console.php). */
function pricing_settings_errors(array $in): array
{
    $errors = [];
    foreach (['price_flat_units' => 'Paušál do počtu jednotek', 'price_unit_standard' => 'Přirážka za jednotku – Standard', 'price_unit_premium' => 'Přirážka za jednotku – Premium',
        'free_max_units' => 'Edice Zdarma do počtu jednotek', 'price_vote' => 'Cena jednorázového hlasování'] as $k => $label) {
        if (!ctype_digit((string) ($in[$k] ?? '')) || (int) $in[$k] > 100000) {
            $errors[] = $label . ': zadejte celé číslo (0–100 000).';
        }
    }
    $package = trim((string) ($in['twa_package'] ?? ''));
    if ($package !== '' && !twa_package_valid($package)) {
        $errors[] = 'Aplikace pro Android: název balíčku má tvar např. cz.webprosvj.app.';
    }
    $raw = trim((string) ($in['twa_sha256'] ?? ''));
    if ($raw !== '' && count(twa_fingerprints($raw)) !== count(array_filter(preg_split('/[\s,;]+/', $raw)))) {
        $errors[] = 'Aplikace pro Android: otisk certifikátu má tvar 32 dvojic šestnáctkových číslic oddělených dvojtečkou (SHA-256).';
    }
    return $errors;
}
