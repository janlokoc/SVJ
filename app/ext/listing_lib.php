<?php
declare(strict_types=1);

/*
 * Inzeráty vlastníků: vlastník jednotky (účet druhu „vlastník“ přiřazený k jednotce) může na webu domu zveřejnit inzerát
 * na prodej nebo pronájem svého bytu a sdílet ho (Facebook, WhatsApp, text pro Bazoš). Ve všech edicích, bez beta programu.
 *
 * Průběh: koncept → zveřejněný → (prodáno/pronajato | vypršel | skrytý výborem). Zveřejněný platí 60 dní (připomínka
 * 7 dní předem, prodloužení jedním kliknutím). „Nejdřív sousedé“: první 3 dny ho vidí jen přihlášení obyvatelé domu.
 * Prodaný se ukazuje se štítkem ještě 14 dní, potom zmizí.
 *
 * Soukromí: veřejná stránka ukazuje jen ulici a město domu (nikdy číslo jednotky, vchod ani jiné údaje z evidence),
 * e-mail vlastníka se nikde nezobrazuje – zprávy zájemců předává web. Fotky se na serveru znovu uloží přes GD
 * (bez EXIF, tedy i bez GPS polohy) a zmenší.
 *
 * Průkaz energetické náročnosti budovy (PENB) zadává výbor v Nastavení; připomínka 90 dní před koncem platnosti.
 */

const LISTING_TYPES = ['prodej' => 'Prodej', 'pronajem' => 'Pronájem'];
const LISTING_LAYOUTS = ['1+kk', '1+1', '2+kk', '2+1', '3+kk', '3+1', '4+kk', '4+1', '5+kk', '5+1', 'jine'];
const LISTING_CLASSES = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
const LISTING_STATUS = ['draft' => 'Koncept', 'published' => 'Zveřejněný', 'sold' => 'Prodáno / pronajato', 'expired' => 'Vypršel'];
const LISTING_DAYS = 60;            // jak dlouho je inzerát zveřejněný
const LISTING_REMIND_DAYS = 7;      // připomínka před koncem
const LISTING_NEIGHBOURS_DAYS = 3;  // „Nejdřív sousedé“
const LISTING_SOLD_DAYS = 14;       // jak dlouho se ukazuje prodaný
const LISTING_PHOTOS_MIN = 3;
const LISTING_PHOTOS_MAX = 10;
const LISTING_PHOTO_BYTES = 15 * 1048576;
const LISTING_PHOTO_SIDE = 1600;
const LISTING_THUMB_SIDE = 480;
const LISTING_PENB_REMIND_DAYS = 90;
// Ochrana formulářů před roboty a zahlcením: nejméně tolik sekund od načtení stránky, nejvýše tolik zpráv / hlášení za hodinu z jedné adresy.
const LISTING_FORM_MIN_SECONDS = 3;
const LISTING_CONTACT_PER_HOUR = 5;
const LISTING_REPORT_PER_HOUR = 3;

$GLOBALS['EXT_ADMIN_NAV']['/admin/inzeraty'] = ['Inzeráty vlastníků', 'Dům a lidé'];
$GLOBALS['EXT_RESIDENT_NAV']['/inzeraty'] = ['Inzeráty', fn() => listing_enabled()];
$GLOBALS['EXT_DAILY'][] = 'listing_daily';
$GLOBALS['EXT_SETTINGS'][] = __DIR__ . '/../views/ext/listing/_settings.php';

/** Založí tabulky modulu (jednou za požadavek a databázi). */
function listing_ready(?PDO $pdo = null): void
{
    static $done = [];
    $pdo ??= db();
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $have = $mysql ? array_map('strval', $pdo->query("SHOW TABLES LIKE 'listing%'")->fetchAll(PDO::FETCH_COLUMN))
        : array_map('strval', $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'listing%'")->fetchAll(PDO::FETCH_COLUMN));
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $int = $mysql ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
    $big = $mysql ? 'BIGINT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
    $text = $mysql ? 'TEXT NULL' : "TEXT NOT NULL DEFAULT ''";
    $id = 'id ' . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT');
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $tables = [
        // energy_source: 'budova' (průkaz budovy z nastavení domu), 'vlastni' (třídu zvolil vlastník), 'nema' (průkaz nemá).
        // public_from: od kdy inzerát vidí i nepřihlášení (Nejdřív sousedé); expires_on: poslední den zveřejnění.
        'listings' => "CREATE TABLE listings ($id, unit_id $int, user_id $int, type {$s(10)}, price $big, fees $big, layout {$s(10)}, area " . ($mysql ? 'DECIMAL(8,2) NULL' : 'REAL')
            . ", floor {$s(10)}, description $text, share_text $text, contact_name {$s(120)}, energy_class {$s(1)}, energy_source {$s(10)}, neighbours_first $int, status {$s(10)}, hidden $int,"
            . " hidden_reason {$s(500)}, created_at {$s(19)}, updated_at {$s(19)}, published_at {$s(19)}, public_from {$s(19)}, expires_on {$s(10)}, reminded_on {$s(10)}, sold_at {$s(19)})$end",
        'listing_photos' => "CREATE TABLE listing_photos ($id, listing_id $int, filename {$s(80)}, thumb {$s(80)}, width $int, height $int, sort $int, caption {$s(120)})$end",
        // Záznamy pro omezení počtu zpráv a hlášení z jedné adresy (mažou se po dni).
        'listing_hits' => "CREATE TABLE listing_hits ($id, kind {$s(10)}, ip {$s(45)}, listing_id $int, at $big)$end",
        'listing_reports' => "CREATE TABLE listing_reports ($id, listing_id $int, reason $text, created_at {$s(19)})$end",
    ];
    foreach ($tables as $name => $ddl) {
        if (!in_array($name, $have, true)) {
            $pdo->exec($ddl);
        }
    }
    // Popis fotky (přidán 11/2026) – starší tabulka sloupec nemá.
    if (in_array('listing_photos', $have, true)) {
        $cols = $mysql ? array_map('strval', $pdo->query('SHOW COLUMNS FROM listing_photos')->fetchAll(PDO::FETCH_COLUMN))
            : array_column($pdo->query('PRAGMA table_info(listing_photos)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('caption', $cols, true)) {
            $pdo->exec("ALTER TABLE listing_photos ADD COLUMN caption {$s(120)}");
        }
    }
    if (!in_array('listings', $have, true)) {
        $pdo->exec('CREATE INDEX listings_unit ON listings (unit_id, status)');
        $pdo->exec('CREATE INDEX listing_photos_listing ON listing_photos (listing_id, sort)');
        $pdo->exec('CREATE INDEX listing_hits_ip ON listing_hits (kind, ip, at)');
    }
}

function listing_enabled(): bool
{
    return setting_on('listing_enabled', true);
}
function listing_setting_save(string $key, string $value): void
{
    q('INSERT INTO settings (`key`, value) VALUES (?,?) ON CONFLICT(`key`) DO UPDATE SET value = excluded.value', [$key, $value]);
    setting('__reset');
}

// ---------- Průkaz energetické náročnosti budovy ----------

/** PENB domu z nastavení: ['class' => 'C' | '', 'until' => 'Y-m-d' | '', 'valid' => platí dnes]. */
function listing_penb(?string $today = null): array
{
    $today ??= date('Y-m-d');
    $class = setting('penb_class');
    $class = in_array($class, LISTING_CLASSES, true) ? $class : '';
    $until = valid_date(setting('penb_valid_until')) ? setting('penb_valid_until') : '';
    return ['class' => $class, 'until' => $until, 'valid' => $class !== '' && $until !== '' && $until >= $today];
}
/** Uloží PENB z formuláře nastavení; vrací text chyby, nebo null. */
function listing_penb_save(string $class, string $until): ?string
{
    if ($class !== '' && !in_array($class, LISTING_CLASSES, true)) {
        return 'Vyberte energetickou třídu A až G, nebo „nezadáno“.';
    }
    if ($until !== '' && !valid_date($until)) {
        return 'Datum platnosti průkazu nemá správný tvar.';
    }
    if ($class !== '' && $until === '') {
        return 'U průkazu vyplňte i datum, do kdy platí (najdete ho na první straně průkazu).';
    }
    listing_setting_save('penb_class', $class);
    listing_setting_save('penb_valid_until', $class === '' ? '' : $until);
    return null;
}

/**
 * Soubor průkazu (PDF). $stored = [filename, original_name, size] z store_upload() (nový soubor), nebo null.
 * $offer = nabídnout ho vlastníkům v Dokumentech (pak je to běžný dokument „jen pro přihlášené“, jinak ho vidí jen správci);
 * $remove = průkaz smazat. Dosavadní soubor se při nahrání nového smaže.
 */
function listing_penb_file(?array $stored, bool $offer, bool $remove = false): void
{
    $docId = (int) setting('penb_doc_id');
    $doc = $docId ? one('SELECT * FROM documents WHERE id = ?', [$docId]) : null;
    $file = preg_match('/^[a-f0-9]{32}\.pdf$/', setting('penb_file')) ? setting('penb_file') : '';
    if ($remove || $stored) {
        if ($doc) {
            delete_document($docId);
        }
        if ($file !== '') {
            @unlink(UPLOAD_DIR . '/' . $file);
        }
        [$doc, $file] = [null, ''];
        listing_setting_save('penb_doc_id', '');
        listing_setting_save('penb_file', '');
        listing_setting_save('penb_file_name', '');
    }
    if ($remove) {
        return;
    }
    if ($stored) {
        [$file, $origName] = [$stored[0], $stored[1]];
        listing_setting_save('penb_file_name', mb_substr((string) $origName, 0, 200));
        listing_setting_save('penb_file_size', (string) (int) $stored[2]);
    }
    if ($offer && $file !== '') {
        // Soubor se stane dokumentem pro přihlášené obyvatele (spravuje se pak i v části Dokumenty).
        q("INSERT INTO documents (title, category, visibility, filename, original_name, size, doc_date) VALUES (?, 'zakladni', 'residents', ?, ?, ?, ?)",
            ['Průkaz energetické náročnosti budovy', $file, setting('penb_file_name') !== '' ? setting('penb_file_name') : 'prukaz-energeticke-narocnosti.pdf', (int) setting('penb_file_size'), date('Y-m-d')]);
        listing_setting_save('penb_doc_id', (string) db()->lastInsertId());
        listing_setting_save('penb_file', '');
    } elseif (!$offer && $doc) {
        // Dokument přestane být vidět obyvatelům, soubor zůstane jen pro správce.
        q('DELETE FROM documents WHERE id = ?', [$docId]);
        listing_setting_save('penb_doc_id', '');
        listing_setting_save('penb_file', (string) $doc['filename']);
        listing_setting_save('penb_file_name', (string) $doc['original_name']);
    } elseif ($file !== '') {
        listing_setting_save('penb_file', $file);
    }
}

// ---------- Kdo smí co ----------

/** Jednotky, pro které smí uživatel podat inzerát: jen účet druhu vlastník přiřazený k jednotce. */
function listing_owner_units(?array $user): array
{
    if (!$user || ($user['kind'] ?? '') !== 'owner' || !(int) ($user['active'] ?? 1)) {
        return [];
    }
    return all('SELECT un.* FROM units un JOIN user_units uu ON uu.unit_id = un.id WHERE uu.user_id = ? ORDER BY un.entrance, un.number + 0, un.number', [(int) $user['id']]);
}
function listing_owns_unit(?array $user, int $unitId): bool
{
    return in_array($unitId, array_map(fn($u) => (int) $u['id'], listing_owner_units($user)), true);
}
/** Inzerát smí upravovat vlastník jednotky, ke které patří (i spoluvlastník nebo manžel/ka s účtem vlastníka). */
function listing_can_manage(array $listing, ?array $user): bool
{
    return listing_owns_unit($user, (int) $listing['unit_id']);
}
/** Rozpracovaný nebo zveřejněný inzerát jednotky (na jednotku smí být jen jeden). */
function listing_active_for_unit(int $unitId, int $exceptId = 0): ?array
{
    listing_ready();
    return one("SELECT * FROM listings WHERE unit_id = ? AND status IN ('draft','published') AND id <> ? ORDER BY id DESC LIMIT 1", [$unitId, $exceptId]);
}
function listing_get(int $id): ?array
{
    listing_ready();
    return one('SELECT * FROM listings WHERE id = ?', [$id]);
}

/**
 * Komu je inzerát vidět (bez ohledu na návštěvníka): 'public' (všem), 'residents' (jen přihlášeným obyvatelům – první dny
 * u „Nejdřív sousedé“), nebo 'none' (koncept, skrytý výborem, vypršel, déle prodaný).
 */
function listing_visibility(array $l, ?int $now = null): string
{
    $now ??= time();
    if ((int) $l['hidden'] || !listing_enabled()) {
        return 'none';
    }
    if ($l['status'] === 'sold') {
        return $l['sold_at'] !== '' && strtotime($l['sold_at']) + LISTING_SOLD_DAYS * 86400 > $now ? 'public' : 'none';
    }
    if ($l['status'] !== 'published' || $l['expires_on'] === '' || $l['expires_on'] < date('Y-m-d', $now)) {
        return 'none';
    }
    return $l['public_from'] !== '' && strtotime($l['public_from']) > $now ? 'residents' : 'public';
}
/** Smí návštěvník inzerát vidět? Vlastník jednotky a správci vidí i nezveřejněný (jako náhled). */
function listing_can_view(array $l, ?array $user, ?int $now = null): bool
{
    $v = listing_visibility($l, $now);
    return $v === 'public' || ($v === 'residents' && $user) || listing_can_manage($l, $user) || ($user['role'] ?? '') === 'admin';
}

// ---------- Texty ----------

/** Lokalita do inzerátu: jen ulice a město domu (bez čísla domu, PSČ, vchodů a čísla jednotky). Výbor ji může v nastavení přepsat. */
function listing_locality(): string
{
    $own = trim(setting('listing_locality'));
    if ($own !== '') {
        return mb_substr($own, 0, 100);
    }
    return listing_locality_from(setting('address'));
}
function listing_locality_from(string $address): string
{
    $parts = array_values(array_filter(array_map('trim', explode(',', $address)), fn($p) => $p !== ''));
    if (!$parts) {
        return '';
    }
    // Ulice: bez čísla popisného / orientačního a označení vchodů („Tábor 48 a–d“ → „Tábor“).
    $street = trim((string) preg_replace('~\s+\d.*$~u', '', $parts[0]));
    $city = count($parts) > 1 ? trim((string) preg_replace(['~\b\d{3}\s?\d{2}\b~u', '~\s+\d+\s*$~u'], '', end($parts))) : '';
    $city = trim((string) preg_replace('~\s+~u', ' ', $city));
    return implode(', ', array_filter([$street, $city !== $street ? $city : ''], fn($x) => $x !== ''));
}
/** Město z lokality (pro AI pomocníka a adresu stránky). */
function listing_city(): string
{
    $p = explode(', ', listing_locality());
    return (string) end($p);
}
function listing_layout_label(string $layout): string
{
    return $layout === 'jine' || $layout === '' ? '' : $layout;
}
/** „Prodej bytu 2+kk“ / „Pronájem bytu“. */
function listing_title(array $l): string
{
    return trim(($l['type'] === 'pronajem' ? 'Pronájem bytu ' : 'Prodej bytu ') . listing_layout_label((string) $l['layout']));
}
function listing_money(int|float $v): string
{
    return number_format((float) $v, 0, ',', "\u{a0}") . "\u{a0}Kč";
}
/** Cena k zobrazení: „4 250 000 Kč“ / „18 500 Kč / měsíc“. */
function listing_price_label(array $l): string
{
    return listing_money((int) $l['price']) . ($l['type'] === 'pronajem' ? ' / měsíc' : '');
}
function listing_slug(array $l): string
{
    $s = strtolower(strip_accents(listing_title($l) . ' ' . listing_city()));
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}
function listing_path(array $l): string
{
    return '/inzerat/' . (int) $l['id'] . '-' . listing_slug($l);
}
const LISTING_CAPTION_MAX = 120;
/** Uloží popisky fotek inzerátu ([id fotky => text]); fotky jiného inzerátu se nezmění. */
function listing_photo_captions(int $listingId, array $captions): void
{
    foreach ($captions as $pid => $text) {
        $text = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_string($text) ? $text : '')), 0, LISTING_CAPTION_MAX);
        q('UPDATE listing_photos SET caption = ? WHERE id = ? AND listing_id = ?', [$text, (int) $pid, $listingId]);
    }
}
/** Text pro alt a popisek fotky: popis od vlastníka, jinak „Fotka n z m“. */
function listing_photo_alt(array $p, int $i, int $count): string
{
    $c = trim((string) ($p['caption'] ?? ''));
    return $c !== '' ? $c : 'Fotka ' . ($i + 1) . ' z ' . $count;
}
function listing_photos(int $listingId): array
{
    listing_ready();
    return all('SELECT * FROM listing_photos WHERE listing_id = ? ORDER BY sort, id', [$listingId]);
}
function listing_photo_url(array $photo, bool $thumb = false): string
{
    return '/inzerat/foto/' . (int) $photo['id'] . ($thumb ? '-t' : '') . '.jpg';
}
/** Krátký popis (pro náhled při sdílení): plocha, patro, lokalita a začátek textu. */
function listing_summary(array $l, int $len = 160): string
{
    $facts = array_filter([unit_area_label($l['area']), $l['floor'] !== '' ? $l['floor'] . '. patro' : '', listing_locality()], fn($x) => $x !== '');
    $head = implode(' · ', $facts);
    return trim($head . ($head !== '' ? '. ' : '') . excerpt((string) $l['description'], max(40, $len - mb_strlen($head))));
}
/** Značky pro náhled odkazu (Facebook, WhatsApp, Messenger – Open Graph; X – Twitter card). */
function listing_meta(array $l, array $photos): array
{
    $title = listing_title($l) . ', ' . str_replace("\u{a0}", ' ', listing_price_label($l));
    $url = absolute_url(listing_path($l));
    $meta = ['description' => listing_summary($l), 'canonical' => $url, 'og:type' => 'website', 'og:locale' => 'cs_CZ', 'og:site_name' => site('name'),
        'og:title' => $title, 'og:description' => listing_summary($l), 'og:url' => $url];
    if ($photos) {
        $meta['og:image'] = absolute_url(listing_photo_url($photos[0]));
        $meta['og:image:width'] = (string) (int) $photos[0]['width'];
        $meta['og:image:height'] = (string) (int) $photos[0]['height'];
        $meta['og:image:alt'] = listing_title($l) . ' – ' . listing_locality();
    }
    $meta['twitter:card'] = $photos ? 'summary_large_image' : 'summary';
    $meta['twitter:title'] = $title;
    $meta['twitter:description'] = $meta['og:description'];
    if ($photos) {
        $meta['twitter:image'] = $meta['og:image'];
    }
    return $meta;
}
/** Text pro Bazoš a skupiny na Facebooku: hlavní údaje a odkaz (prostý text, bez e-mailu vlastníka). */
function listing_share_text(array $l): string
{
    $lines = [listing_title($l) . ', ' . listing_locality()];
    $facts = array_filter([unit_area_label($l['area']), $l['floor'] !== '' ? $l['floor'] . '. patro' : '',
        $l['energy_class'] !== '' ? 'energetická třída ' . $l['energy_class'] : ''], fn($x) => $x !== '');
    if ($facts) {
        $lines[] = implode(', ', $facts);
    }
    $lines[] = 'Cena: ' . listing_price_label($l) . ((int) $l['fees'] > 0 && $l['type'] === 'pronajem' ? ' + poplatky ' . listing_money((int) $l['fees']) . ' / měsíc' : '');
    $lines[] = '';
    $lines[] = trim((string) ($l['share_text'] ?? '')) !== '' ? trim((string) $l['share_text']) : excerpt((string) $l['description'], 400);
    $lines[] = '';
    $lines[] = 'Fotky a kontakt: ' . absolute_url(listing_path($l));
    return str_replace("\u{a0}", ' ', implode("\n", $lines));
}
/** Kontext pro AI pomocníka (údaje z formuláře, které nejsou v textu). */
function listing_ai_context(): string
{
    return 'Lokalita: ' . listing_locality() . "\nDům: bytový dům ve společenství vlastníků";
}

// ---------- Formulář ----------

/** Údaje z formuláře: [data, chyby]. $unit = jednotka (předvyplněné patro a plocha). */
function listing_input(array $post, array $unit, ?string $today = null): array
{
    $g = fn(string $k) => is_string($post[$k] ?? null) ? trim($post[$k]) : '';
    $num = fn(string $v) => preg_match('/^\d{1,10}$/', $d = str_replace([' ', "\u{a0}", '.', 'Kč', 'kč', ',-'], '', $v)) ? (int) $d : null;
    $errors = [];
    $d = ['type' => isset(LISTING_TYPES[$g('type')]) ? $g('type') : 'prodej', 'layout' => in_array($g('layout'), LISTING_LAYOUTS, true) ? $g('layout') : '',
        'floor' => mb_substr($g('floor'), 0, 10), 'description' => mb_substr(str_replace("\r", '', $g('description')), 0, 8000), 'contact_name' => mb_substr($g('contact_name'), 0, 120),
        'share_text' => mb_substr(str_replace("\r", '', $g('share_text')), 0, 1000),
        'neighbours_first' => !empty($post['neighbours_first']) ? 1 : 0];
    if (!isset(LISTING_TYPES[$g('type')])) {
        $errors[] = 'Vyberte, zda byt prodáváte, nebo pronajímáte.';
    }
    if ($d['layout'] === '') {
        $errors[] = 'Vyberte dispozici bytu.';
    }
    $price = $num($g('price'));
    if ($price === null || $price < 1) {
        $errors[] = 'Zadejte cenu v Kč (jen číslo, např. 4 250 000).';
    }
    $d['price'] = (int) $price;
    $fees = $g('fees') === '' ? 0 : $num($g('fees'));
    if ($d['type'] === 'pronajem' && $fees === null) {
        $errors[] = 'Poplatky zadejte v Kč za měsíc (jen číslo), nebo pole nechte prázdné.';
    }
    $d['fees'] = $d['type'] === 'pronajem' ? (int) $fees : 0;
    $area = unit_area_parse($g('area'));
    if ($area === false) {
        $errors[] = 'Plochu zadejte v m² jako číslo, např. 54,3.';
    }
    $d['area'] = $area === false ? null : $area;
    if (mb_strlen($d['description']) < 30) {
        $errors[] = 'Napište popis bytu (aspoň pár vět).';
    }
    if (mb_strlen($d['contact_name']) < 2) {
        $errors[] = 'Vyplňte jméno, které se u inzerátu zobrazí.';
    }
    // Energetická třída: s platným průkazem budovy se použije ten; jinak ji zvolí vlastník, nebo uvede, že průkaz nemá.
    // NEOVĚŘENO, ověřte s právníkem: zákon č. 406/2000 Sb. (§ 7a) zřejmě ukládá uvádět v inzerci prodeje i pronájmu
    // ukazatel energetické náročnosti z průkazu (budovy nebo jednotky); proto výrazné upozornění, když třída chybí.
    $penb = listing_penb($today);
    if ($penb['valid']) {
        [$d['energy_class'], $d['energy_source']] = [$penb['class'], 'budova'];
    } elseif (in_array($g('energy'), LISTING_CLASSES, true)) {
        [$d['energy_class'], $d['energy_source']] = [$g('energy'), 'vlastni'];
    } elseif ($g('energy') === 'nema') {
        [$d['energy_class'], $d['energy_source']] = ['', 'nema'];
    } else {
        [$d['energy_class'], $d['energy_source']] = ['', ''];
        $errors[] = 'Vyberte energetickou třídu bytu, nebo „Průkaz nemám“.';
    }
    return [$d, $errors];
}
/** Výchozí hodnoty nového inzerátu (patro a plocha z evidence jednotky, jméno z účtu). */
function listing_defaults(array $unit, array $user): array
{
    $penb = listing_penb();
    return ['id' => 0, 'unit_id' => (int) $unit['id'], 'type' => 'prodej', 'price' => '', 'fees' => '', 'layout' => '', 'area' => $unit['area'] ?? null, 'floor' => (string) $unit['floor'],
        'description' => '', 'share_text' => '', 'contact_name' => (string) $user['name'], 'energy_class' => $penb['valid'] ? $penb['class'] : '', 'energy_source' => $penb['valid'] ? 'budova' : '',
        'neighbours_first' => 0, 'status' => 'draft', 'hidden' => 0, 'hidden_reason' => '', 'expires_on' => '', 'public_from' => '', 'published_at' => '', 'sold_at' => ''];
}
/** Uloží inzerát (nový jako koncept); vrací id. Volající ověří oprávnění (listing_owns_unit) a jeden inzerát na jednotku. */
function listing_save(array $user, int $unitId, array $d, int $id = 0): int
{
    listing_ready();
    $now = date('Y-m-d H:i:s');
    $cols = ['type' => $d['type'], 'price' => $d['price'], 'fees' => $d['fees'], 'layout' => $d['layout'], 'area' => $d['area'], 'floor' => $d['floor'], 'description' => $d['description'], 'share_text' => $d['share_text'],
        'contact_name' => $d['contact_name'], 'energy_class' => $d['energy_class'], 'energy_source' => $d['energy_source'], 'neighbours_first' => $d['neighbours_first'], 'updated_at' => $now];
    if ($id) {
        q('UPDATE listings SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($cols))) . ' WHERE id = ?', [...array_values($cols), $id]);
        return $id;
    }
    $cols += ['unit_id' => $unitId, 'user_id' => (int) $user['id'], 'status' => 'draft', 'created_at' => $now];
    q('INSERT INTO listings (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($cols));
    return (int) db()->lastInsertId();
}
/** Zveřejní koncept (nebo znovu vypršelý inzerát). Vrací text chyby, nebo null. Správcům odejde upozornění. */
function listing_publish(int $id, ?int $now = null): ?string
{
    $now ??= time();
    $l = listing_get($id);
    if (!$l || !in_array($l['status'], ['draft', 'expired'], true)) {
        return 'Tento inzerát už zveřejněný je.';
    }
    if (!listing_enabled()) {
        return 'Výbor inzeráty na webu domu teď nepovolil.';
    }
    if (count(listing_photos($id)) < LISTING_PHOTOS_MIN) {
        return 'Ke zveřejnění jsou potřeba aspoň ' . LISTING_PHOTOS_MIN . ' fotky.';
    }
    if (listing_active_for_unit((int) $l['unit_id'], $id) && $l['status'] === 'expired') {
        return 'Jednotka už má jiný inzerát.';
    }
    $neighbours = (int) $l['neighbours_first'] && $l['status'] === 'draft';
    q("UPDATE listings SET status = 'published', published_at = ?, public_from = ?, expires_on = ?, reminded_on = '', updated_at = ? WHERE id = ?", [
        date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + ($neighbours ? LISTING_NEIGHBOURS_DAYS * 86400 : 0)),
        date('Y-m-d', $now + LISTING_DAYS * 86400), date('Y-m-d H:i:s', $now), $id]);
    $l = listing_get($id);
    $who = one('SELECT name FROM users WHERE id = ?', [(int) $l['user_id']]);
    $unit = one('SELECT * FROM units WHERE id = ?', [(int) $l['unit_id']]);
    listing_mail_admins('Nový inzerát vlastníka: ' . listing_title($l), 'Vlastník ' . ($who['name'] ?? '') . ($unit ? ' (jednotka ' . unit_name($unit) . ')' : '')
        . ' zveřejnil na webu domu inzerát: ' . listing_title($l) . ', ' . str_replace("\u{a0}", ' ', listing_price_label($l)) . ".\n\n"
        . 'Inzerát: ' . absolute_url(listing_path($l)) . "\nVšechny inzeráty (můžete je i skrýt): " . absolute_url('/admin/inzeraty'));
    return null;
}
function listing_mark_sold(int $id): void
{
    q("UPDATE listings SET status = 'sold', sold_at = ?, updated_at = ? WHERE id = ? AND status = 'published'", [date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
}
/** Prodlouží zveřejnění o LISTING_DAYS ode dneška (i vypršelý inzerát). */
function listing_extend(int $id, ?int $now = null): bool
{
    $now ??= time();
    $l = listing_get($id);
    if (!$l || !in_array($l['status'], ['published', 'expired'], true) || ($l['status'] === 'expired' && listing_active_for_unit((int) $l['unit_id'], $id))) {
        return false;
    }
    q("UPDATE listings SET status = 'published', expires_on = ?, reminded_on = '', updated_at = ? WHERE id = ?", [date('Y-m-d', $now + LISTING_DAYS * 86400), date('Y-m-d H:i:s', $now), $id]);
    return true;
}
/** Smaže inzerát i s fotkami. */
function listing_delete(int $id): void
{
    foreach (listing_photos($id) as $p) {
        listing_photo_unlink($p);
    }
    q('DELETE FROM listing_photos WHERE listing_id = ?', [$id]);
    q('DELETE FROM listing_reports WHERE listing_id = ?', [$id]);
    q('DELETE FROM listings WHERE id = ?', [$id]);
}
/** Výbor inzerát skryje (s důvodem) nebo znovu ukáže; vlastník dostane e-mail. */
function listing_set_hidden(int $id, bool $hide, string $reason = ''): void
{
    $l = listing_get($id);
    if (!$l) {
        return;
    }
    $reason = mb_substr(trim($reason), 0, 500);
    q('UPDATE listings SET hidden = ?, hidden_reason = ?, updated_at = ? WHERE id = ?', [$hide ? 1 : 0, $hide ? $reason : '', date('Y-m-d H:i:s'), $id]);
    if ($owner = listing_owner_user($l)) {
        $hide ? send_mail($owner['email'], 'Výbor skryl Váš inzerát – ' . site('name'), "Dobrý den,\n\nvýbor společenství skryl Váš inzerát „" . listing_title($l) . "“ na webu domu. Inzerát teď nikdo nevidí.\n\n"
                . 'Důvod: ' . ($reason !== '' ? $reason : 'neuveden') . "\n\nPokud jde o omyl nebo jste inzerát upravili, ozvěte se prosím výboru.\nVáš inzerát: " . absolute_url('/inzeraty/moje'))
            : send_mail($owner['email'], 'Váš inzerát je opět vidět – ' . site('name'), "Dobrý den,\n\nvýbor společenství Váš inzerát „" . listing_title($l) . "“ znovu zobrazil.\n\n" . absolute_url(listing_path($l)));
    }
}
/** Účet, kterému chodí zprávy k inzerátu: autor, a když už nemá aktivní účet, jiný vlastník jednotky. */
function listing_owner_user(array $l): ?array
{
    return one("SELECT u.* FROM users u WHERE u.id = ? AND u.active = 1 AND u.kind = 'owner'", [(int) $l['user_id']])
        ?? one("SELECT u.* FROM users u JOIN user_units uu ON uu.user_id = u.id WHERE uu.unit_id = ? AND u.active = 1 AND u.kind = 'owner' ORDER BY u.id LIMIT 1", [(int) $l['unit_id']]);
}
function listing_mail_admins(string $subject, string $text): void
{
    foreach (all("SELECT email FROM users WHERE role = 'admin' AND active = 1") as $a) {
        send_mail($a['email'], $subject . ' – ' . site('name'), "Dobrý den,\n\n" . $text);
    }
}

// ---------- Fotky ----------

function listing_photo_dir(): string
{
    $dir = UPLOAD_DIR . '/inzeraty';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}
/** Natočení fotky podle údaje Orientation v EXIF (1 = beze změny). Čte jen hlavičku JPEG, bez rozšíření exif. */
function listing_jpeg_orientation(string $data): int
{
    $pos = 2;
    $len = strlen($data);
    while ($pos + 4 <= $len && $data[$pos] === "\xFF") {
        $marker = ord($data[$pos + 1]);
        $size = unpack('n', substr($data, $pos + 2, 2))[1];
        if ($marker === 0xE1 && substr($data, $pos + 4, 6) === "Exif\0\0") {
            $tiff = substr($data, $pos + 10, $size - 8);
            $le = substr($tiff, 0, 2) === 'II';
            $u16 = fn(int $o) => strlen($tiff) >= $o + 2 ? unpack($le ? 'v' : 'n', substr($tiff, $o, 2))[1] : 0;
            $u32 = fn(int $o) => strlen($tiff) >= $o + 4 ? unpack($le ? 'V' : 'N', substr($tiff, $o, 4))[1] : 0;
            $ifd = $u32(4);
            $count = $u16($ifd);
            for ($i = 0; $i < min($count, 100); $i++) {
                $e = $ifd + 2 + $i * 12;
                if ($u16($e) === 0x0112) {
                    $o = $u16($e + 8);
                    return $o >= 1 && $o <= 8 ? $o : 1;
                }
            }
            return 1;
        }
        if ($marker === 0xDA || $size < 2) {
            break;
        }
        $pos += 2 + $size;
    }
    return 1;
}
/** Zmenší obrázek GD tak, aby delší strana měla nejvýš $side bodů (na bílém pozadí kvůli průhlednosti PNG). */
function listing_image_fit(\GdImage $img, int $side): \GdImage
{
    [$w, $h] = [imagesx($img), imagesy($img)];
    $scale = min(1, $side / max($w, $h));
    [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $out;
}
function listing_image_jpeg(\GdImage $img, int $quality): string
{
    ob_start();
    imageinterlace($img, true);
    imagejpeg($img, null, $quality);
    return (string) ob_get_clean();
}
/**
 * Zpracuje nahranou fotku: ověří typ podle obsahu (finfo), natočí ji podle EXIF a znovu uloží přes GD jako JPEG –
 * tím zmizí všechna metadata (EXIF, GPS poloha, model telefonu). Vrací ['large', 'thumb', 'width', 'height'] nebo text chyby.
 */
function listing_photo_process(string $path): array|string
{
    clearstatcache(true, $path);
    $size = (int) @filesize($path);
    if ($size <= 0) {
        return 'Fotku se nepodařilo nahrát.';
    }
    if ($size > LISTING_PHOTO_BYTES) {
        return 'Fotka je příliš velká (nejvýše 15 MB).';
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return 'Fotka musí být JPG, PNG nebo WebP.';
    }
    $info = @getimagesize($path);
    if (!$info || $info[0] < 200 || $info[1] < 200) {
        return 'Fotka je příliš malá (aspoň 200 × 200 bodů).';
    }
    if ($info[0] * $info[1] > 40000000) {
        return 'Fotka má příliš vysoké rozlišení. Zmenšete ji prosím.';
    }
    $limit = ini_get('memory_limit');
    if ($limit !== '-1' && (int) $limit < 512) {
        @ini_set('memory_limit', '512M');
    }
    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        default => @imagecreatefromwebp($path),
    };
    if (!$img) {
        return 'Fotku se nepodařilo přečíst.';
    }
    if ($mime === 'image/jpeg') {
        $o = listing_jpeg_orientation((string) file_get_contents($path, false, null, 0, 131072));
        if (in_array($o, [2, 4, 5, 7], true)) {
            imageflip($img, IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($o) { 3, 4 => 180, 5, 6 => 270, 7, 8 => 90, default => 0 };
        if ($angle) {
            $img = imagerotate($img, $angle, 0) ?: $img;
        }
    }
    $large = listing_image_fit($img, LISTING_PHOTO_SIDE);
    $thumb = listing_image_fit($img, LISTING_THUMB_SIDE);
    return ['large' => listing_image_jpeg($large, 82), 'thumb' => listing_image_jpeg($thumb, 78), 'width' => imagesx($large), 'height' => imagesy($large)];
}
/** Uloží zpracovanou fotku k inzerátu; vrací text chyby, nebo null. */
function listing_photo_add(int $listingId, string $path): ?string
{
    if ((int) val('SELECT COUNT(*) FROM listing_photos WHERE listing_id = ?', [$listingId]) >= LISTING_PHOTOS_MAX) {
        return 'K inzerátu lze nahrát nejvýše ' . LISTING_PHOTOS_MAX . ' fotek.';
    }
    $r = listing_photo_process($path);
    if (is_string($r)) {
        return $r;
    }
    $dir = listing_photo_dir();
    $name = bin2hex(random_bytes(16));
    if (@file_put_contents($dir . '/' . $name . '.jpg', $r['large']) === false || @file_put_contents($dir . '/' . $name . '-t.jpg', $r['thumb']) === false) {
        return 'Fotku se nepodařilo uložit.';
    }
    $sort = (int) val('SELECT COALESCE(MAX(sort), 0) FROM listing_photos WHERE listing_id = ?', [$listingId]) + 1;
    q('INSERT INTO listing_photos (listing_id, filename, thumb, width, height, sort) VALUES (?,?,?,?,?,?)', [$listingId, $name . '.jpg', $name . '-t.jpg', $r['width'], $r['height'], $sort]);
    return null;
}
function listing_photo_unlink(array $p): void
{
    foreach ([$p['filename'], $p['thumb']] as $f) {
        if (preg_match('/^[a-f0-9]{32}(-t)?\.jpg$/', (string) $f)) {
            @unlink(listing_photo_dir() . '/' . $f);
        }
    }
}
/** Cesta k souboru fotky (jen platné názvy – žádné ../), nebo null. */
function listing_photo_file(array $p, bool $thumb): ?string
{
    $f = (string) ($thumb ? $p['thumb'] : $p['filename']);
    if (!preg_match('/^[a-f0-9]{32}(-t)?\.jpg$/', $f)) {
        return null;
    }
    $path = listing_photo_dir() . '/' . $f;
    return is_file($path) ? $path : null;
}
/** Úvodní fotka: vybraná se posune na první místo. */
function listing_photo_cover(int $listingId, int $photoId): void
{
    if (val('SELECT 1 FROM listing_photos WHERE id = ? AND listing_id = ?', [$photoId, $listingId])) {
        q('UPDATE listing_photos SET sort = sort + 1 WHERE listing_id = ?', [$listingId]);
        q('UPDATE listing_photos SET sort = 0 WHERE id = ?', [$photoId]);
    }
}

// ---------- Ochrana formulářů (zprávy zájemců, nahlášení) ----------

function listing_secret(): string
{
    $s = setting('listing_secret');
    if (!preg_match('/^[a-f0-9]{64}$/', $s)) {
        $s = bin2hex(random_bytes(32));
        listing_setting_save('listing_secret', $s);
    }
    return $s;
}
/** Značka času načtení formuláře (podepsaná – robot ji nepodvrhne). */
function listing_form_token(?int $now = null): string
{
    $now ??= time();
    return $now . '.' . substr(hash_hmac('sha256', 'form|' . $now, listing_secret()), 0, 20);
}
/** Formulář odeslaný příliš rychle (robot), se starou nebo podvrženou značkou neprojde. */
function listing_form_token_ok(string $token, ?int $now = null): bool
{
    $now ??= time();
    if (!preg_match('/^(\d{9,11})\.([a-f0-9]{20})$/', $token, $m) || !hash_equals(substr(hash_hmac('sha256', 'form|' . $m[1], listing_secret()), 0, 20), $m[2])) {
        return false;
    }
    $age = $now - (int) $m[1];
    return $age >= LISTING_FORM_MIN_SECONDS && $age <= 86400;
}
/** Kolik záznamů druhu $kind z adresy $ip za poslední hodinu; $record = zapsat nový. */
function listing_hits(string $kind, string $ip, ?int $now = null): int
{
    listing_ready();
    $now ??= time();
    return (int) val('SELECT COUNT(*) FROM listing_hits WHERE kind = ? AND ip = ? AND at > ?', [$kind, $ip, $now - 3600]);
}
function listing_hit(string $kind, string $ip, int $listingId, ?int $now = null): void
{
    q('INSERT INTO listing_hits (kind, ip, listing_id, at) VALUES (?,?,?,?)', [$kind, $ip, $listingId, $now ?? time()]);
}
/**
 * Zpráva zájemce vlastníkovi. Vlastník dostane e-mail (odpověď jde rovnou zájemci, je-li zadán e-mail); zájemce adresu
 * vlastníka nevidí ani nedostane. Vrací ['ok' => true] nebo ['errors' => […], 'in' => vyplněné údaje].
 */
function listing_contact(array $l, array $post, string $ip, bool $loggedIn = false, ?int $now = null): array
{
    $now ??= time();
    $g = fn(string $k) => is_string($post[$k] ?? null) ? trim($post[$k]) : '';
    $in = ['name' => mb_substr($g('name'), 0, 120), 'contact' => mb_substr($g('contact'), 0, 190), 'message' => mb_substr(str_replace("\r", '', $g('message')), 0, 3000)];
    if ($g('website') !== '') {
        return ['ok' => true, 'robot' => true];   // robot vyplnil skryté pole – tváříme se, že je hotovo, nic se neodešle
    }
    if (!listing_form_token_ok($g('t'), $now)) {
        return ['errors' => ['Formulář vypršel nebo byl odeslán příliš rychle. Zkontrolujte prosím zprávu a odešlete ji znovu.'], 'in' => $in];
    }
    $vis = listing_visibility($l, $now);
    if ($vis === 'none' || ($vis === 'residents' && !$loggedIn) || $l['status'] !== 'published' || !($owner = listing_owner_user($l))) {
        return ['errors' => ['Inzerát už není aktivní, zprávu nejde odeslat.'], 'in' => $in];
    }
    if (listing_hits('contact', $ip, $now) >= LISTING_CONTACT_PER_HOUR) {
        return ['errors' => ['Z tohoto připojení přišlo v poslední hodině příliš mnoho zpráv. Zkuste to prosím později.'], 'in' => $in];
    }
    $errors = [];
    if (mb_strlen($in['name']) < 2) {
        $errors[] = 'Vyplňte prosím své jméno.';
    }
    $email = filter_var($in['contact'], FILTER_VALIDATE_EMAIL) ? $in['contact'] : '';
    if ($email === '' && !preg_match('/^\+?[0-9 ]{9,16}$/', $in['contact'])) {
        $errors[] = 'Zadejte e-mail nebo telefon, na kterém Vás vlastník bytu zastihne.';
    }
    if (mb_strlen($in['message']) < 5) {
        $errors[] = 'Napište prosím zprávu.';
    }
    if ($errors) {
        return ['errors' => $errors, 'in' => $in];
    }
    listing_hit('contact', $ip, (int) $l['id'], $now);
    $text = "Dobrý den,\n\nna webu domu " . site('name') . ' se ozval zájemce o Váš inzerát „' . listing_title($l) . "“:\n\n"
        . 'Jméno: ' . $in['name'] . "\n" . ($email !== '' ? 'E-mail: ' . $email : 'Telefon: ' . $in['contact']) . "\n\n" . $in['message'] . "\n\n"
        . ($email !== '' ? 'Odpovědět můžete přímo na tento e-mail – odpověď dostane zájemce.' : 'Zájemce nechal jen telefon – zavolejte mu prosím.') . "\n"
        . 'Váš e-mail jsme zájemci neprozradili. Inzerát: ' . absolute_url(listing_path($l));
    send_mail($owner['email'], 'Zájemce o Váš inzerát: ' . listing_title($l), $text, null, null, null, $email !== '' ? $email : null);
    return ['ok' => true];
}
/** Nahlášení nevhodného inzerátu výboru (omezené počtem z jedné adresy). Vrací text chyby, nebo null. */
function listing_report(array $l, string $reason, string $ip, ?int $now = null): ?string
{
    $now ??= time();
    $reason = mb_substr(trim(str_replace("\r", '', $reason)), 0, 2000);
    if (mb_strlen($reason) < 5) {
        return 'Napište prosím, co je na inzerátu špatně.';
    }
    if (listing_hits('report', $ip, $now) >= LISTING_REPORT_PER_HOUR) {
        return 'Z tohoto připojení přišlo v poslední hodině příliš mnoho hlášení. Zkuste to prosím později.';
    }
    listing_hit('report', $ip, (int) $l['id'], $now);
    q('INSERT INTO listing_reports (listing_id, reason, created_at) VALUES (?,?,?)', [(int) $l['id'], $reason, date('Y-m-d H:i:s', $now)]);
    listing_mail_admins('Nahlášený inzerát: ' . listing_title($l), 'návštěvník webu nahlásil inzerát „' . listing_title($l) . "“:\n\n" . $reason . "\n\n"
        . 'Inzerát: ' . absolute_url(listing_path($l)) . "\nSkrýt ho můžete v administraci: " . absolute_url('/admin/inzeraty'));
    return null;
}

// ---------- Prodloužení odkazem z e-mailu ----------

function listing_extend_token(array $l): string
{
    return hash_hmac('sha256', 'extend|' . (int) $l['id'] . '|' . $l['expires_on'], listing_secret());
}
function listing_extend_url(array $l): string
{
    return absolute_url('/inzerat/prodlouzit/' . (int) $l['id'] . '/' . listing_extend_token($l));
}

// ---------- Denní úlohy ----------

/** Připomínka konce platnosti PENB (jednou na každé datum platnosti), připomínky a konec zveřejnění inzerátů. */
function listing_daily(?int $now = null): void
{
    $now ??= time();
    $today = date('Y-m-d', $now);
    setting('__reset');
    $penb = listing_penb($today);
    if ($penb['class'] !== '' && $penb['until'] !== '' && setting('penb_reminded') !== $penb['until']
        && $today >= date('Y-m-d', strtotime($penb['until'] . ' -' . LISTING_PENB_REMIND_DAYS . ' days'))) {
        listing_mail_admins('Průkaz energetické náročnosti budovy brzy končí', 'průkaz energetické náročnosti budovy (třída ' . $penb['class'] . ') ' . ($penb['until'] < $today ? 'skončil' : 'platí jen') . ' do ' . cz_date($penb['until']) . ".\n\n"
            . "Průkaz potřebujete mimo jiné k inzerci prodeje a pronájmu bytů v domě. Nový zpracuje energetický specialista; po jeho obdržení zadejte novou třídu a datum v nastavení webu:\n"
            . absolute_url('/admin/nastaveni') . '#penb');
        listing_setting_save('penb_reminded', $penb['until']);
    }
    listing_ready();
    q('DELETE FROM listing_hits WHERE at < ?', [$now - 86400]);
    foreach (all("SELECT * FROM listings WHERE status = 'published' AND expires_on <> '' AND expires_on < ?", [$today]) as $l) {
        q("UPDATE listings SET status = 'expired', updated_at = ? WHERE id = ?", [date('Y-m-d H:i:s', $now), (int) $l['id']]);
        if (($owner = listing_owner_user($l)) && !(int) $l['hidden']) {
            send_mail($owner['email'], 'Váš inzerát skončil – ' . site('name'), "Dobrý den,\n\nVáš inzerát „" . listing_title($l) . '“ byl zveřejněný ' . LISTING_DAYS . " dní a teď skončil.\n\n"
                . "Pokud byt stále nabízíte, zveřejněte ho znovu jedním kliknutím:\n" . listing_extend_url(listing_get((int) $l['id'])) . "\n\nSvé inzeráty najdete zde: " . absolute_url('/inzeraty/moje'));
        }
    }
    $soon = date('Y-m-d', $now + LISTING_REMIND_DAYS * 86400);
    foreach (all("SELECT * FROM listings WHERE status = 'published' AND hidden = 0 AND reminded_on = '' AND expires_on <> '' AND expires_on <= ?", [$soon]) as $l) {
        q('UPDATE listings SET reminded_on = ? WHERE id = ?', [$today, (int) $l['id']]);
        if ($owner = listing_owner_user($l)) {
            send_mail($owner['email'], 'Váš inzerát brzy skončí – ' . site('name'), "Dobrý den,\n\nVáš inzerát „" . listing_title($l) . '“ bude na webu domu do ' . cz_date($l['expires_on']) . ".\n\n"
                . 'Prodloužit ho o ' . LISTING_DAYS . " dní můžete jedním kliknutím:\n" . listing_extend_url($l) . "\n\n"
                . 'Pokud je byt už prodaný nebo pronajatý, označte to prosím u inzerátu: ' . absolute_url('/inzeraty/moje'));
        }
    }
}
