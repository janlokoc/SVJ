<?php
declare(strict_types=1);

/*
 * Obchod (konzole → Obchod, 11/2026): cesta od prvního kontaktu k platbě, cíl „N cizích platících domů do data“,
 * zakládající domy (převod zdarma, zamčená cena Standardu) a doporučení výborů na úvodní stránce („Kdo za tím stojí“).
 *
 * „Známý“ dům = provozovatel ho zná osobně; do cíle se počítají jen cizí domy, protože ty ověřují, že se produkt
 * prodá i bez osobního vztahu. Kontakty (leads) jsou údaje provozovatele, leží jen v databázi platformy na serveru.
 */

const SALES_STAGES = ['kontakt' => 'Ozval se', 'ukazka' => 'Ukázka proběhla', 'registrace' => 'Založil web', 'zaplaceno' => 'Zaplatil', 'nezajem' => 'Nemá zájem'];
const SALES_SOURCES = ['skupina' => 'Facebooková skupina', 'forum' => 'Diskusní fórum', 'doporuceni' => 'Doporučení', 'web' => 'Web nebo vyhledávač', 'jine' => 'Jinak'];

function sales_goal(): int
{
    return pset_int('sales_goal', 1, 10000);
}
function sales_deadline(): string
{
    $d = pset('sales_deadline');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '2027-05-31';
}
function founding_slots(): int
{
    return pset_int('founding_slots', 0, 1000);
}
/** Kolik zakládajících míst je obsazeno (označené domy, i ty, které ještě nezaplatily). */
function founding_used(): int
{
    return (int) pq("SELECT COUNT(*) FROM tenants WHERE founding <> '' AND demo = 0")->fetchColumn();
}
/** Nabídka zakládajících domů je zapnutá a má volná místa. */
function founding_open(): bool
{
    return pset('founding_on') === '1' && founding_slots() > founding_used();
}
function founding_left(): int
{
    return max(0, founding_slots() - founding_used());
}
/**
 * Označí dům jako zakládající: zamkne dnešní cenu Standardu (bez přirážky za jednotky, ta se počítá dál podle velikosti).
 * Zrušení vrátí běžný ceník; už vystavené výzvy k platbě se nemění.
 */
function founding_set(string $slug, bool $on): bool
{
    $t = tenant_row($slug);
    if (!$t || !empty($t['demo'])) {
        return false;
    }
    if ($on && (string) $t['founding'] === '') {
        if (founding_used() >= founding_slots()) {
            return false;
        }
        pq('UPDATE tenants SET founding = ?, founding_std = ? WHERE slug = ?', [date('Y-m-d'), pset_int('price_standard', 0, 1000000), $slug]);
        platform_log('Zakládající dům', $slug . ' · Standard ' . pset_int('price_standard', 0, 1000000) . ' Kč natrvalo');
    } elseif (!$on && (string) $t['founding'] !== '') {
        pq("UPDATE tenants SET founding = '', founding_std = 0 WHERE slug = ?", [$slug]);
        platform_log('Zakládající dům zrušen', $slug);
    }
    return true;
}
/** Zakládající dům má převod obsahu zdarma. */
function migration_price(?array $t): int
{
    return $t && (string) ($t['founding'] ?? '') !== '' ? 0 : pset_int('price_migration', 0, 1000000);
}

/** Web je oznámený sousedům (první hromadný e-mail) – stejné pravidlo jako u týdenního přehledu. */
function sales_tenant_launched(string $slug): bool
{
    if (!is_dir(tenant_storage($slug))) {
        return false;
    }
    try {
        $pdo = tenant_pdo($slug);
        if ((string) $pdo->query("SELECT value FROM settings WHERE `key` = 'digest_week'")->fetchColumn() !== '') {
            return true;
        }
        return (int) $pdo->query('SELECT COUNT(*) FROM mailings')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
/** Dům aspoň jednou zaplatil edici (skutečně připsaná platba; převod, doplňky a dárky se nepočítají). */
function sales_tenant_paid(string $slug): bool
{
    return (bool) pq("SELECT 1 FROM payments WHERE tenant = ? AND status = 'paid' AND kind = 'service' AND amount > 0 LIMIT 1", [$slug])->fetchColumn();
}

/** Domy (bez ukázek) s údaji pro obchod: název, založení, známý/cizí, zakládající, edice, oznámeno, zaplaceno. */
function sales_houses(): array
{
    $rows = [];
    foreach (pq('SELECT * FROM tenants WHERE demo = 0 ORDER BY created_at DESC')->fetchAll() as $t) {
        $rows[] = ['slug' => $t['slug'], 'name' => $t['name'], 'created_at' => (string) $t['created_at'], 'active' => (int) $t['active'], 'known' => (int) $t['known'],
            'founding' => (string) $t['founding'], 'edition' => billing_state($t)['edition'], 'launched' => sales_tenant_launched($t['slug']), 'paid' => sales_tenant_paid($t['slug'])];
    }
    return $rows;
}

/**
 * Cesta od kontaktu k platbě. Kontakty a ukázky z ručně zapsaných kontaktů, registrace, oznámení a platby z platformy.
 * Vrací ['cizi' => [krok => počet], 'znami' => [...], 'goal' => cíl, 'deadline' => datum, 'days' => zbývá dní].
 */
function sales_funnel(?array $houses = null, ?array $leads = null): array
{
    $houses ??= sales_houses();
    $leads ??= pq('SELECT * FROM leads')->fetchAll();
    $f = fn() => ['kontakt' => 0, 'ukazka' => 0, 'registrace' => 0, 'oznameno' => 0, 'zaplaceno' => 0];
    $out = ['cizi' => $f(), 'znami' => $f()];
    foreach ($leads as $l) {
        $out['cizi']['kontakt']++;
        if (!empty($l['demo_done']) || in_array($l['stage'], ['ukazka', 'registrace', 'zaplaceno'], true)) {
            $out['cizi']['ukazka']++;
        }
    }
    foreach ($houses as $h) {
        $k = $h['known'] ? 'znami' : 'cizi';
        $out[$k]['registrace']++;
        $out[$k]['oznameno'] += $h['launched'] ? 1 : 0;
        $out[$k]['zaplaceno'] += $h['paid'] ? 1 : 0;
    }
    $deadline = sales_deadline();
    $out['goal'] = sales_goal();
    $out['deadline'] = $deadline;
    $out['days'] = (int) floor((strtotime($deadline) - strtotime(date('Y-m-d'))) / 86400);
    return $out;
}

/** Uloží nový kontakt nebo změnu. Vrací chyby (prázdné = uloženo). */
function sales_lead_save(array $in, ?int $id = null): array
{
    $d = ['house' => trim((string) ($in['house'] ?? '')), 'contact' => trim((string) ($in['contact'] ?? '')), 'source' => (string) ($in['source'] ?? 'jine'),
        'stage' => (string) ($in['stage'] ?? 'kontakt'), 'tenant' => trim((string) ($in['tenant'] ?? '')), 'note' => trim((string) ($in['note'] ?? '')),
        'demo_done' => !empty($in['demo_done']) ? 1 : 0];
    $errors = [];
    if ($d['house'] === '') {
        $errors['house'] = 'Napište, o jaký dům jde (např. ulici a město).';
    }
    if (!isset(SALES_SOURCES[$d['source']])) {
        $d['source'] = 'jine';
    }
    if (!isset(SALES_STAGES[$d['stage']])) {
        $d['stage'] = 'kontakt';
    }
    if ($d['tenant'] !== '' && !tenant_row($d['tenant'])) {
        $errors['tenant'] = 'Web s touto adresou neexistuje.';
    }
    if (in_array($d['stage'], ['ukazka', 'registrace', 'zaplaceno'], true)) {
        $d['demo_done'] = 1;
    }
    foreach (['house' => 150, 'contact' => 200, 'note' => 2000] as $k => $max) {
        $d[$k] = mb_substr($d[$k], 0, $max);
    }
    if ($errors) {
        return $errors;
    }
    $now = date('Y-m-d H:i:s');
    if ($id) {
        pq('UPDATE leads SET house = ?, contact = ?, source = ?, stage = ?, tenant = ?, note = ?, demo_done = ?, updated_at = ? WHERE id = ?',
            [$d['house'], $d['contact'], $d['source'], $d['stage'], $d['tenant'], $d['note'], $d['demo_done'], $now, $id]);
    } else {
        pq('INSERT INTO leads (house, contact, source, stage, tenant, note, demo_done, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$d['house'], $d['contact'], $d['source'], $d['stage'], $d['tenant'], $d['note'], $d['demo_done'], $now, $now]);
    }
    return [];
}

/** Doporučení zobrazená na úvodní stránce (jen se zaznamenaným souhlasem). */
function testimonials_public(): array
{
    return pq("SELECT * FROM testimonials WHERE visible = 1 AND consent <> '' ORDER BY sort, id")->fetchAll();
}
/** Sekce „Kdo za tím stojí“ má co ukázat. */
function about_has_content(): bool
{
    return trim(pset('about_story')) !== '' || testimonials_public() !== [];
}
function testimonial_save(array $in, ?int $id = null): array
{
    $d = ['text' => trim((string) ($in['text'] ?? '')), 'who' => trim((string) ($in['who'] ?? '')), 'house' => trim((string) ($in['house'] ?? '')),
        'consent' => trim((string) ($in['consent'] ?? '')), 'visible' => !empty($in['visible']) ? 1 : 0, 'sort' => (int) ($in['sort'] ?? 0)];
    $errors = [];
    if ($d['text'] === '') {
        $errors['text'] = 'Doplňte text doporučení.';
    }
    if ($d['who'] === '') {
        $errors['who'] = 'Doplňte, kdo doporučení napsal (např. předseda výboru).';
    }
    if ($d['consent'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['consent'])) {
        $errors['consent'] = 'Datum souhlasu zadejte jako datum.';
    }
    if ($d['visible'] && $d['consent'] === '') {
        $errors['consent'] = 'Zveřejnit lze jen doporučení, ke kterému máte písemný souhlas – doplňte datum souhlasu.';
    }
    if ($errors) {
        return $errors;
    }
    $d['text'] = mb_substr($d['text'], 0, 600);
    $d['who'] = mb_substr($d['who'], 0, 120);
    $d['house'] = mb_substr($d['house'], 0, 120);
    if ($id) {
        pq('UPDATE testimonials SET text = ?, who = ?, house = ?, consent = ?, visible = ?, sort = ? WHERE id = ?', [$d['text'], $d['who'], $d['house'], $d['consent'], $d['visible'], $d['sort'], $id]);
    } else {
        pq('INSERT INTO testimonials (text, who, house, consent, visible, sort, created_at) VALUES (?,?,?,?,?,?,?)', [$d['text'], $d['who'], $d['house'], $d['consent'], $d['visible'], $d['sort'], date('Y-m-d H:i:s')]);
    }
    return [];
}

// ---------- Fotka provozovatele pro „Kdo za tím stojí“ (mimo webový kořen, vydává ji /kdo-za-tim-stoji/foto) ----------

const ABOUT_PHOTO_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

function about_photo_dir(): string
{
    return ROOT_STORAGE . '/about';
}
/** Cesta k uložené fotce, nebo ''. */
function about_photo_file(): string
{
    foreach (ABOUT_PHOTO_TYPES as $ext) {
        $f = about_photo_dir() . '/photo.' . $ext;
        if (is_file($f)) {
            return $f;
        }
    }
    return '';
}
/** Uloží nahranou fotku (JPEG, PNG nebo WebP do 3 MB). Vrací chybu, nebo ''. $local = soubor z disku (testy), ne z formuláře. */
function about_photo_store(array $f, bool $local = false): string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (!$local && !is_uploaded_file((string) $f['tmp_name']))) {
        return 'Fotku se nepodařilo nahrát.';
    }
    if ((int) $f['size'] > 3 * 1024 * 1024) {
        return 'Fotka je větší než 3 MB – zmenšete ji prosím.';
    }
    $info = @getimagesize((string) $f['tmp_name']);
    $ext = $info ? (ABOUT_PHOTO_TYPES[$info[2]] ?? '') : '';
    if ($ext === '') {
        return 'Nahrajte fotku ve formátu JPEG, PNG nebo WebP.';
    }
    $dir = about_photo_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    about_photo_delete();
    $target = $dir . '/photo.' . $ext;
    if (!($local ? copy((string) $f['tmp_name'], $target) : move_uploaded_file((string) $f['tmp_name'], $target))) {
        return 'Fotku se nepodařilo uložit.';
    }
    pset_save('about_photo_v', (string) time());
    return '';
}
function about_photo_delete(): void
{
    foreach (ABOUT_PHOTO_TYPES as $ext) {
        @unlink(about_photo_dir() . '/photo.' . $ext);
    }
}
