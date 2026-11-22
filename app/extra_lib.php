<?php
declare(strict_types=1);

// Funkce pro hlasování, dokumenty jednotek, revize, odečty, rezervace, klíče, zálohu a podklady shromáždění.

const SCHEMA_VERSION = 8;
const CHOICES = ['yes' => 'Pro', 'no' => 'Proti', 'abstain' => 'Zdržuji se'];
const READING_KINDS = ['sv' => 'Studená voda', 'tv' => 'Teplá voda', 'teplo' => 'Teplo', 'plyn' => 'Plyn', 'el' => 'Elektřina'];

// ---------- Jednotky přihlášeného a viditelnost dokumentů ----------

function my_unit_ids(): array
{
    static $ids = null;
    return $ids ??= user() ? user_unit_ids((int) user()['id']) : [];
}
function unit_options(): array
{
    $out = [];
    foreach (all_units() as $un) {
        $out[$un['id']] = unit_name($un);
    }
    return $out;
}
/** SQL podmínka: které dokumenty smí aktuální návštěvník vidět v seznamech (dokument jednotky jen její lidé). */
function doc_scope_sql(string $alias = ''): string
{
    $a = $alias ? "$alias." : '';
    if (!user()) {
        return "{$a}visibility = 'public' AND {$a}unit_id IS NULL";
    }
    $mine = my_unit_ids();
    return "({$a}unit_id IS NULL" . ($mine ? " OR {$a}unit_id IN (" . implode(',', array_map('intval', $mine)) . ')' : '') . ')';
}
function can_open_document(array $d): bool
{
    if ($d['unit_id']) {
        return user() && (is_admin() || in_array((int) $d['unit_id'], my_unit_ids(), true));
    }
    return $d['visibility'] === 'public' || user() !== null;
}

// ---------- Hlasování ----------

function poll_state(array $poll): string
{
    $today = date('Y-m-d');
    return $today < $poll['opens_at'] ? 'planned' : ($today > $poll['closes_at'] ? 'closed' : 'open');
}
const POLL_STATES = ['planned' => 'Připravuje se', 'open' => 'Probíhá', 'closed' => 'Ukončeno'];

/**
 * Výsledky hlasování. Váhou hlasu je spoluvlastnický podíl jednotky (pokud ho mají vyplněný všechny jednotky, jinak 1 hlas na jednotku).
 * Návrh je přijat, když hlasy PRO přesáhnou nastavené procento VŠECH hlasů v domě – nejen těch, kdo hlasovali.
 */
function poll_results(int $pollId): array
{
    $poll = one('SELECT * FROM polls WHERE id = ?', [$pollId]);
    $units = all_units();
    $byShare = (bool) $units;
    foreach ($units as $un) {
        $byShare = $byShare && share_value($un['share']) > 0;
    }
    $weights = [];
    $total = 0.0;
    foreach ($units as $un) {
        $weights[$un['id']] = $byShare ? share_value($un['share']) : 1.0;
        $total += $weights[$un['id']];
    }
    $votes = [];
    foreach (all('SELECT v.*, u.name voter FROM poll_votes v JOIN poll_items i ON i.id = v.item_id LEFT JOIN users u ON u.id = v.user_id WHERE i.poll_id = ?', [$pollId]) as $v) {
        $votes[$v['item_id']][$v['unit_id']] = $v;
    }
    $items = all('SELECT * FROM poll_items WHERE poll_id = ? ORDER BY sort, id', [$pollId]);
    $votedUnits = [];
    foreach ($items as &$it) {
        $it['sum'] = ['yes' => 0.0, 'no' => 0.0, 'abstain' => 0.0];
        $it['count'] = ['yes' => 0, 'no' => 0, 'abstain' => 0];
        foreach ($votes[$it['id']] ?? [] as $unitId => $v) {
            if (isset($weights[$unitId])) {
                $it['sum'][$v['choice']] += $weights[$unitId];
                $it['count'][$v['choice']]++;
                $votedUnits[$unitId] = true;
            }
        }
        $it['percent'] = array_map(fn($x) => $total > 0 ? $x / $total * 100 : 0.0, $it['sum']);
        $it['passed'] = $it['percent']['yes'] > $poll['threshold'];
    }
    unset($it);
    $turnout = 0.0;
    foreach (array_keys($votedUnits) as $unitId) {
        $turnout += $weights[$unitId];
    }
    return ['items' => $items, 'votes' => $votes, 'units' => $units, 'byShare' => $byShare, 'voted' => count($votedUnits),
        'turnout' => $total > 0 ? $turnout / $total * 100 : 0.0, 'threshold' => (int) $poll['threshold']];
}

// ---------- Jednoduché evidence v administraci (revize, prostory, klíče, odečty, rezervace) ----------

/** Popis evidencí: adresa v administraci → tabulka, názvy a pole formuláře [popis, typ, povinné?, volby]. */
function crud_defs(): array
{
    static $defs = null;
    if ($defs !== null) {
        return $defs;
    }
    $units = fn() => ['' => '—'] + unit_options();
    return $defs = [
        'revize' => ['table' => 'maintenance', 'title' => 'Revize a údržba', 'one' => 'revizi', 'name' => 'title', 'order' => 'next_date IS NULL, next_date, title',
            'lead' => 'Pravidelné revize a kontroly v domě. Na blížící se termín (30 dní předem) upozorní přehled administrace a web pošle správcům e-mail.',
            'fields' => ['title' => ['Co se kontroluje', 'text', true], 'contractor' => ['Kdo provádí', 'text'], 'last_date' => ['Naposledy provedeno', 'date'],
                'interval_months' => ['Opakovat po (měsících)', 'number'], 'next_date' => ['Příští termín', 'date'], 'note' => ['Poznámka', 'text']]],
        'prostory' => ['table' => 'resources', 'title' => 'Společné prostory k rezervaci', 'one' => 'prostor', 'name' => 'name', 'order' => 'name',
            'lead' => 'Prostory a věci, které si obyvatelé mohou rezervovat (sušárna, společenská místnost, vozík…).',
            'fields' => ['name' => ['Název', 'text', true], 'note' => ['Pravidla a poznámka', 'text'], 'active' => ['Lze rezervovat', 'check']]],
        'rezervace' => ['table' => 'reservations', 'title' => 'Rezervace', 'one' => 'rezervaci', 'name' => 'res_date', 'order' => 'res_date DESC, time_from',
            'lead' => 'Všechny rezervace společných prostor. Obyvatelé si je zadávají sami; zde je můžete upravit nebo zrušit.',
            'fields' => ['resource_id' => ['Prostor', 'select', true, fn() => array_column(all('SELECT id, name FROM resources ORDER BY name'), 'name', 'id')],
                'user_id' => ['Kdo', 'select', true, fn() => array_column(all('SELECT id, name FROM users ORDER BY name'), 'name', 'id')],
                'res_date' => ['Den', 'date', true], 'time_from' => ['Od', 'time', true], 'time_to' => ['Do', 'time', true], 'note' => ['Poznámka', 'text']]],
        'klice' => ['table' => 'keys_log', 'title' => 'Klíče a čipy', 'one' => 'klíč nebo čip', 'name' => 'label', 'order' => 'returned_at IS NOT NULL, label',
            'lead' => 'Evidence vydaných klíčů a čipů: komu, kdy a zda byly vráceny.',
            'fields' => ['label' => ['Označení (např. čip č. 12, klíč od kolárny)', 'text', true], 'unit_id' => ['Jednotka', 'select', false, $units], 'holder' => ['Komu vydáno', 'text'],
                'issued_at' => ['Vydáno dne', 'date'], 'returned_at' => ['Vráceno dne', 'date'], 'note' => ['Poznámka', 'text']]],
        'ukoly' => ['table' => 'tasks', 'title' => 'Úkoly a usnesení', 'one' => 'úkol', 'name' => 'title', 'order' => 'done, due_date IS NULL, due_date, id DESC',
            'lead' => 'Co se na shromáždění nebo na výboru rozhodlo, kdo to má zařídit a do kdy. Vidí jen správci.',
            'fields' => ['title' => ['Co je potřeba udělat', 'text', true], 'assignee' => ['Kdo to má na starosti', 'text'], 'due_date' => ['Termín', 'date'],
                'source' => ['Z čeho úkol vzešel (usnesení, schůze)', 'text'], 'note' => ['Poznámka', 'text'], 'done' => ['Hotovo', 'check']]],
        'dodavatele' => ['table' => 'suppliers', 'title' => 'Dodavatelé', 'one' => 'dodavatele', 'name' => 'name', 'order' => 'field, name',
            'lead' => 'Firmy a řemeslníci, se kterými dům spolupracuje. Dodavatele pak můžete přiřadit k nahlášené závadě.',
            'fields' => ['name' => ['Název nebo jméno', 'text', true], 'field' => ['Obor (instalatér, výtahy, úklid…)', 'text'], 'phone' => ['Telefon', 'text'], 'email' => ['E-mail', 'text'], 'note' => ['Poznámka', 'text']]],
        'odecty' => ['table' => 'readings', 'title' => 'Odečty měřidel', 'one' => 'odečet', 'name' => 'read_date', 'order' => 'read_date DESC, id DESC',
            'lead' => 'Stavy měřidel, které obyvatelé nahlásili sami nebo které jste zapsali za ně.',
            'fields' => ['unit_id' => ['Jednotka', 'select', true, fn() => unit_options()], 'kind' => ['Měřidlo', 'select', true, fn() => READING_KINDS], 'meter_no' => ['Číslo měřidla', 'text'],
                'amount' => ['Stav', 'decimal', true], 'read_date' => ['Datum odečtu', 'date', true], 'note' => ['Poznámka', 'text']]],
    ];
}
function crud_display(array $field, mixed $value): string
{
    return match ($field[1]) {
        'select' => (string) (($field[3]())[$value] ?? ($value === null || $value === '' ? '' : '?')),
        'check' => $value ? 'ano' : 'ne',
        'date' => cz_date($value ? (string) $value : null),
        'decimal' => $value === null ? '' : rtrim(rtrim(number_format((float) $value, 3, ',', ' '), '0'), ','),
        'number' => (int) $value ? (string) (int) $value : '',
        default => (string) $value,
    };
}
/** Stav termínu revize: 'overdue', 'soon' (do 30 dní) nebo ''. */
function maintenance_state(?string $next): string
{
    if (!$next) {
        return '';
    }
    return $next < date('Y-m-d') ? 'overdue' : ($next <= date('Y-m-d', time() + 30 * 86400) ? 'soon' : '');
}

// ---------- Úlohy jednou denně (bez plánovače na hostingu: spustí je první návštěva dne) ----------

function daily_tasks(): void
{
    if (setting('last_daily') === date('Y-m-d')) {
        return;
    }
    q("INSERT INTO settings (`key`, value) VALUES ('last_daily', ?) ON CONFLICT(`key`) DO UPDATE SET value = excluded.value", [date('Y-m-d')]);
    if (!filter_var(setting('mail_from'), FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $due = all('SELECT * FROM maintenance WHERE next_date IS NOT NULL AND next_date <= ? AND (reminded_for IS NULL OR reminded_for <> next_date)', [date('Y-m-d', time() + 30 * 86400)]);
    if (!$due) {
        return;
    }
    $lines = array_map(fn($m) => '- ' . $m['title'] . ': ' . cz_date($m['next_date']) . ($m['next_date'] < date('Y-m-d') ? ' (PO TERMÍNU)' : '') . ($m['contractor'] ? ', ' . $m['contractor'] : ''), $due);
    $text = "Dobrý den,\n\nblíží se termín těchto revizí a kontrol v domě:\n\n" . implode("\n", $lines) . "\n\nPřehled a zápis provedení: " . absolute_url('/admin/revize') . "\n";
    foreach (all("SELECT email FROM users WHERE role = 'admin' AND active = 1") as $admin) {
        send_mail($admin['email'], 'Blíží se termín revize – ' . site('name'), $text);
    }
    foreach ($due as $m) {
        q('UPDATE maintenance SET reminded_for = next_date WHERE id = ?', [$m['id']]);
    }
}

// ---------- Záloha ----------

/** Tabulky v pořadí, v jakém je lze obnovit (nejdřív ty, na které se ostatní odkazují). */
const BACKUP_TABLES = ['users', 'units', 'user_units', 'posts', 'documents', 'post_documents', 'contacts', 'events', 'event_documents', 'forum_posts', 'tickets',
    'mailings', 'event_rsvp', 'mailing_recipients', 'settings', 'audit_log', 'polls', 'poll_items', 'poll_votes', 'maintenance', 'readings', 'resources', 'reservations', 'keys_log', 'surveys', 'survey_options', 'survey_answers', 'tasks', 'suppliers', 'unit_accounts', 'messages'];

function backup_data(): array
{
    $data = ['format' => 'svj-backup-1', 'created' => date('c'), 'tables' => []];
    foreach (BACKUP_TABLES as $t) {
        $data['tables'][$t] = all("SELECT * FROM $t");
    }
    return $data;
}
/** Obnoví data ze zálohy do (prázdné nebo stávající) databáze: stávající obsah tabulek nahradí. */
function restore_data(array $data): array
{
    if (($data['format'] ?? '') !== 'svj-backup-1') {
        throw new RuntimeException('Soubor není záloha tohoto webu.');
    }
    $pdo = db();
    $mysql = db_driver() === 'mysql';
    $pdo->exec($mysql ? 'SET FOREIGN_KEY_CHECKS = 0' : 'PRAGMA foreign_keys = OFF');
    $counts = [];
    $pdo->beginTransaction();
    foreach (array_reverse(BACKUP_TABLES) as $t) {
        $pdo->exec("DELETE FROM $t");
    }
    foreach (BACKUP_TABLES as $t) {
        $counts[$t] = 0;
        foreach ($data['tables'][$t] ?? [] as $row) {
            $cols = array_keys($row);
            $pdo->prepare("INSERT INTO $t (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
            $counts[$t]++;
        }
    }
    // Číslo verze struktury patří k databázi, do které se obnovuje, ne k záloze (záloha může pocházet z jiného typu databáze).
    $pdo->exec("DELETE FROM settings WHERE `key` = 'schema_version'");
    if ($mysql) {
        $pdo->exec("INSERT INTO settings (`key`, value) VALUES ('schema_version', '" . SCHEMA_VERSION . "')");
    }
    $pdo->commit();
    $pdo->exec($mysql ? 'SET FOREIGN_KEY_CHECKS = 1' : 'PRAGMA foreign_keys = ON');
    return $counts;
}

// ---------- Ochrana osobních údajů ----------

const PRIVACY_DEFAULT = <<<'TXT'
## Kdo údaje zpracovává
Správcem osobních údajů je společenství vlastníků jednotek, kterému tento web patří. Kontakt na výbor najdete na stránce Kontakty.

## Jaké údaje web ukládá
- U účtů obyvatel: jméno, e-mail, telefon (pokud ho uvedete), zda jste vlastník nebo nájemník a ke které jednotce patříte.
- U jednotek: vlastníka podle katastru nemovitostí a spoluvlastnický podíl.
- Co na webu sami napíšete: příspěvky v diskuzi, nahlášené závady a požadavky, odečty měřidel, rezervace, potvrzení účasti na shromáždění a hlasy v hlasování.
- Provozní záznamy: čas přihlášení, neúspěšné pokusy o přihlášení a IP adresu, ze které přišly.

## K čemu údaje slouží
Ke správě domu a plnění povinností společenství: vedení seznamu členů, svolávání shromáždění, hlasování, řešení závad a rozesílání oznámení výboru. Provozní záznamy slouží k ochraně účtů před zneužitím.

## Kdo údaje vidí
- Výbor (správci webu) vidí všechny údaje kromě soukromých zpráv mezi obyvateli.
- Ostatní přihlášení obyvatelé vidí Vaše jméno a jednotku u příspěvků v diskuzi, u rezervací a v seznamu lidí, kterým lze napsat zprávu.
- Soukromé zprávy vidí jen odesílatel a příjemce.
- Vaše hlasy v hlasování a nahlášené závady vidí jen výbor a lidé z Vaší jednotky.
- Veřejnost vidí jen jména a funkce členů výboru a kontrolní komise.

## Kdo údaje zpracovává pro společenství
- Provozovatel této webové služby, který web technicky provozuje a zálohuje (zpracovatel; je uveden v patičce nebo na stránce služby).
- Pokud výbor používá AI pomocníka, odesílá se text, který mu výbor zadá, ke zpracování společnosti Anthropic. Výbor do něj nemá vkládat osobní údaje, které tam být nemusí.
Údaje se nepředávají nikomu dalšímu a nepoužívají se k reklamě.

## E-maily
Výbor Vám může posílat důležité zprávy e-mailem. Zasílání si můžete vypnout ve svém účtu. U rozeslaných zpráv web eviduje, zda byla zpráva otevřena a zda jste klikli na odkaz v ní, aby výbor věděl, ke komu se informace dostala.

## Cookies
Web používá jen nezbytné cookies: pro přihlášení, zvolený jazyk a světlý nebo tmavý vzhled. Nepoužívá reklamní ani sledovací cookies třetích stran, proto nezobrazuje cookie lištu.

## Jak dlouho údaje uchováváme
Po dobu, kdy jste vlastníkem nebo obyvatelem domu, a dále po dobu, kterou společenství ukládají právní předpisy (například zápisy ze shromáždění a výsledky hlasování). Provozní záznamy o přihlášení slouží bezpečnosti a uchovávají se nejdéle po dobu existence webu.

## Vaše práva a jak je uplatnit
- **Přístup a přenositelnost:** po přihlášení si v části Můj účet → Moje údaje a soukromí stáhnete vše, co o Vás web ukládá.
- **Oprava:** jméno, telefon a heslo změníte ve svém účtu; ostatní opraví výbor.
- **Výmaz („právo být zapomenut“):** účet si můžete sami zrušit tamtéž. Zůstanou jen údaje, které společenství musí uchovat ze zákona (seznam vlastníků, zápisy a výsledky hlasování), a příspěvky bez Vašeho jména.
- **Námitka a omezení zpracování:** e-maily od výboru a zprávy od sousedů vypnete ve svém účtu; s dalšími požadavky se obraťte na výbor.
- **Stížnost:** můžete ji podat u Úřadu pro ochranu osobních údajů (www.uoou.cz).
Na žádosti odpovídá výbor bez zbytečného odkladu, nejpozději do jednoho měsíce. Kontakt najdete na stránce Kontakty.
TXT;

// ---------- Import obsahu z jiného webu (soubor JSON připravený převodem) ----------

/**
 * Nahraje do webu obsah ze souboru ve formátu svj-import-1: název a nastavení, kontakty, dokumenty (jako odkazy na původní soubory)
 * a aktuality s přílohami. S $replace nejdřív smaže dosavadní aktuality, dokumenty a kontakty. Vrací počty, nebo text chyby.
 */
function import_content(PDO $pdo, array $data, bool $replace): array|string
{
    if (($data['format'] ?? '') !== 'svj-import-1') {
        return 'Soubor není ve formátu pro import obsahu (svj-import-1).';
    }
    $str = fn(mixed $v, int $max) => mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $max);
    $date = fn(mixed $v) => is_string($v) && valid_date($v) ? $v : null;
    $set = function (string $key, string $value) use ($pdo): void {
        $pdo->prepare('DELETE FROM settings WHERE `key` = ?')->execute([$key]);
        $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (?,?)')->execute([$key, $value]);
    };
    $n = ['contacts' => 0, 'documents' => 0, 'posts' => 0];
    $pdo->beginTransaction();
    try {
        if ($replace) {
            foreach (['post_documents', 'event_documents', 'posts', 'documents', 'contacts'] as $table) {
                $pdo->exec("DELETE FROM $table");
            }
        }
        foreach (['name', 'title', 'accent', 'full', 'tagline', 'unit_prefix', 'entrances'] as $k) {
            if (isset($data['site'][$k])) {
                $set('site_' . $k, $str($data['site'][$k], 200));
            }
        }
        foreach (['address', 'ico', 'account', 'intro', 'emergency_note', 'forum_note'] as $k) {
            if (isset($data['settings'][$k])) {
                $set($k, $str($data['settings'][$k], 2000));
            }
        }
        $st = $pdo->prepare('INSERT INTO contacts (grp, role, name, phone, email, note, sort) VALUES (?,?,?,?,?,?,?)');
        foreach ((array) ($data['contacts'] ?? []) as $i => $c) {
            if (isset(CONTACT_GROUPS[$c['grp'] ?? '']) && $str($c['name'] ?? '', 200) !== '') {
                $st->execute([$c['grp'], $str($c['role'] ?? '', 200), $str($c['name'], 200), $str($c['phone'] ?? '', 60), $str($c['email'] ?? '', 190), $str($c['note'] ?? '', 500), ($i + 1) * 10]);
                $n['contacts']++;
            }
        }
        $ids = [];
        $st = $pdo->prepare('INSERT INTO documents (title, category, visibility, original_name, external_url, doc_date) VALUES (?,?,?,?,?,?)');
        foreach ((array) ($data['documents'] ?? []) as $d) {
            $url = $str($d['external_url'] ?? '', 1000);
            if ($str($d['title'] ?? '', 255) === '' || !preg_match('~^https://[^\s]+$~', $url)) {
                continue;
            }
            $st->execute([$str($d['title'], 255), isset(CATEGORIES[$d['category'] ?? '']) ? $d['category'] : 'oznameni', ($d['visibility'] ?? '') === 'residents' ? 'residents' : 'public',
                $str($d['original_name'] ?? '', 255), $url, $date($d['doc_date'] ?? null)]);
            $ids[(string) ($d['key'] ?? '')] = (int) $pdo->lastInsertId();
            $n['documents']++;
        }
        $st = $pdo->prepare('INSERT INTO posts (title, body, visibility, pinned, published_at) VALUES (?,?,?,?,?)');
        $link = $pdo->prepare('INSERT INTO post_documents (post_id, document_id) VALUES (?,?)');
        foreach ((array) ($data['posts'] ?? []) as $p) {
            if ($str($p['title'] ?? '', 255) === '') {
                continue;
            }
            $st->execute([$str($p['title'], 255), $str($p['body'] ?? '', 20000), ($p['visibility'] ?? '') === 'residents' ? 'residents' : 'public', empty($p['pinned']) ? 0 : 1, $date($p['published_at'] ?? null) ?? date('Y-m-d')]);
            $pid = (int) $pdo->lastInsertId();
            foreach (array_unique(array_map('strval', (array) ($p['docs'] ?? []))) as $key) {
                if (isset($ids[$key])) {
                    $link->execute([$pid, $ids[$key]]);
                }
            }
            $n['posts']++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('SVJ web, import obsahu: ' . $e->getMessage());
        return 'Import se nepodařil a nic se nezměnilo. Zkontrolujte, že soubor je úplný.';
    }
    return $n;
}

// ---------- Termíny do kalendáře (formát iCalendar, který umí Google, Apple i Outlook) ----------

/** Začátek a konec termínu: [začátek, konec, celodenní]. Čas „18:00“ nebo „18.00“ se rozpozná, jinak je termín celodenní. */
function event_times(array $ev): array
{
    if (preg_match('/(\d{1,2})[:.](\d{2})/', (string) $ev['event_time'], $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
        $start = strtotime($ev['event_date'] . sprintf(' %02d:%02d:00', $m[1], $m[2]));
        return [$start, $start + 2 * 3600, false];
    }
    $start = strtotime($ev['event_date'] . ' 00:00:00');
    return [$start, strtotime($ev['event_date'] . ' +1 day'), true];
}
function ics_text(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\;', '\,', '\n', '\n', ''], trim($s));
}
/** Kalendář se zadanými termíny. Řádky se podle normy lámou po 75 bajtech. */
function ics_calendar(array $events): string
{
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) parse_url(absolute_url('/'), PHP_URL_HOST)) ?: 'svj';
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//SVJ web//CS', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . ics_text(site('name')), 'X-WR-TIMEZONE:Europe/Prague',
        'BEGIN:VTIMEZONE', 'TZID:Europe/Prague', 'BEGIN:DAYLIGHT', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'TZNAME:CEST', 'DTSTART:19700329T020000', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'END:DAYLIGHT',
        'BEGIN:STANDARD', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'TZNAME:CET', 'DTSTART:19701025T030000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:STANDARD', 'END:VTIMEZONE'];
    foreach ($events as $ev) {
        [$start, $end, $allDay] = event_times($ev);
        $about = trim($ev['note'] . ($ev['program'] !== '' ? "\n\nProgram:\n" . $ev['program'] : '') . "\n\n" . absolute_url('/terminy/' . $ev['id']));
        array_push($lines, 'BEGIN:VEVENT', 'UID:termin-' . (int) $ev['id'] . '@' . $host, 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            $allDay ? 'DTSTART;VALUE=DATE:' . date('Ymd', $start) : 'DTSTART;TZID=Europe/Prague:' . date('Ymd\THis', $start),
            $allDay ? 'DTEND;VALUE=DATE:' . date('Ymd', $end) : 'DTEND;TZID=Europe/Prague:' . date('Ymd\THis', $end),
            'SUMMARY:' . ics_text($ev['title'] . ' – ' . site('name')), 'DESCRIPTION:' . ics_text($about));
        if ($ev['place'] !== '') {
            $lines[] = 'LOCATION:' . ics_text($ev['place']);
        }
        array_push($lines, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . ics_text($ev['title']), 'TRIGGER:-P1D', 'END:VALARM', 'END:VEVENT');
    }
    $lines[] = 'END:VCALENDAR';
    $out = '';
    foreach ($lines as $line) {
        while (strlen($line) > 73) {
            $cut = 73;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;   // nelámat uprostřed znaku
            }
            $out .= substr($line, 0, $cut) . "\r\n ";
            $line = substr($line, $cut);
        }
        $out .= $line . "\r\n";
    }
    return $out;
}
function send_ics(string $body, string $filename): never
{
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=300');
    echo $body;
    exit;
}
function google_calendar_url(array $ev): string
{
    [$start, $end, $allDay] = event_times($ev);
    return 'https://calendar.google.com/calendar/render?' . http_build_query(['action' => 'TEMPLATE', 'text' => $ev['title'] . ' – ' . site('name'),
        'dates' => $allDay ? date('Ymd', $start) . '/' . date('Ymd', $end) : date('Ymd\THis', $start) . '/' . date('Ymd\THis', $end), 'ctz' => 'Europe/Prague',
        'details' => trim($ev['note'] . "\n" . absolute_url('/terminy/' . $ev['id'])), 'location' => $ev['place']]);
}
/** Tajná část adresy, na které si kalendářové aplikace samy stahují všechny termíny (přihlásit se neumějí). */
function calendar_token(): string
{
    $t = setting('calendar_token');
    if (!preg_match('/^[a-f0-9]{32}$/', $t)) {
        $t = bin2hex(random_bytes(16));
        q('DELETE FROM settings WHERE `key` = ?', ['calendar_token']);
        q('INSERT INTO settings (`key`, value) VALUES (?,?)', ['calendar_token', $t]);
        setting('__reset');
    }
    return $t;
}

// ---------- Základní dokumenty společenství: přehled toho, co má na webu být, a nahrání jedním krokem ----------

/** klíč → [název dokumentu, kategorie, viditelnost, k čemu slouží] */
const CORE_DOCS = [
    'stanovy' => ['Stanovy společenství', 'zakladni', 'public', 'Základní pravidla společenství; vlastníci je hledají nejčastěji.'],
    'domovni-rad' => ['Domovní řád', 'zakladni', 'public', 'Pravidla užívání domu a společných prostor.'],
    'prohlaseni' => ['Prohlášení vlastníka', 'zakladni', 'residents', 'Vymezení jednotek a spoluvlastnických podílů.'],
    'rejstrik' => ['Výpis z rejstříku společenství', 'zakladni', 'public', 'Aktuální zápis výboru a sídla.'],
    'sprava' => ['Smlouva o správě domu', 'zakladni', 'residents', 'Smlouva se správcem nebo účetní firmou.'],
    'pojisteni' => ['Pojistná smlouva domu', 'zakladni', 'residents', 'Hodí se při hlášení pojistné události.'],
    'penb' => ['Průkaz energetické náročnosti budovy', 'zakladni', 'public', 'Vlastníci ho potřebují při prodeji nebo pronájmu bytu.'],
    'pozarni-rad' => ['Požární řád a evakuační plán', 'zakladni', 'public', 'Co dělat při požáru, kde jsou hasicí přístroje a uzávěry.'],
    'gdpr' => ['Zásady zpracování osobních údajů', 'zakladni', 'public', 'Jak společenství nakládá s údaji vlastníků.'],
    'rozpocet' => ['Rozpočet a plán oprav', 'oznameni', 'residents', 'Schválený rozpočet a plán čerpání fondu oprav na letošní rok.'],
    'plna-moc' => ['Plná moc na shromáždění (formulář)', 'formulare', 'public', 'Vzor k vytištění pro vlastníky, kteří nemohou přijít.'],
    'zmena-osob' => ['Oznámení změny počtu osob (formulář)', 'formulare', 'public', 'Pro rozúčtování služeb podle počtu osob.'],
    'zmena-vlastnika' => ['Oznámení změny vlastníka (formulář)', 'formulare', 'public', 'Při prodeji nebo převodu jednotky.'],
];
/** Který dokument je k danému základnímu dokumentu nahraný (nebo null). */
function core_doc(string $key): ?array
{
    $id = (int) setting('core_doc_' . $key);
    return $id ? one('SELECT * FROM documents WHERE id = ?', [$id]) : null;
}
