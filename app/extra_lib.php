<?php
declare(strict_types=1);

// Funkce pro hlasování, dokumenty jednotek, revize, odečty, rezervace, klíče, zálohu a podklady shromáždění.

const SCHEMA_VERSION = 6;
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
        send_mail($admin['email'], 'Blíží se termín revize – SVJ Tábor 48', $text);
    }
    foreach ($due as $m) {
        q('UPDATE maintenance SET reminded_for = next_date WHERE id = ?', [$m['id']]);
    }
}

// ---------- Záloha ----------

/** Tabulky v pořadí, v jakém je lze obnovit (nejdřív ty, na které se ostatní odkazují). */
const BACKUP_TABLES = ['users', 'units', 'user_units', 'posts', 'documents', 'post_documents', 'contacts', 'events', 'event_documents', 'forum_posts', 'tickets',
    'mailings', 'event_rsvp', 'mailing_recipients', 'settings', 'audit_log', 'polls', 'poll_items', 'poll_votes', 'maintenance', 'readings', 'resources', 'reservations', 'keys_log'];

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
Správcem osobních údajů je Společenství vlastníků jednotek Tábor 48 a–d, Brno. Kontakt na výbor najdete na stránce Kontakty.

## Jaké údaje web ukládá
- U účtů obyvatel: jméno, e-mail, telefon (pokud ho uvedete), zda jste vlastník nebo nájemník a ke které jednotce patříte.
- U jednotek: vlastníka podle katastru nemovitostí a spoluvlastnický podíl.
- Co na webu sami napíšete: příspěvky v diskuzi, nahlášené závady a požadavky, odečty měřidel, rezervace, potvrzení účasti na shromáždění a hlasy v hlasování.
- Provozní záznamy: čas přihlášení, neúspěšné pokusy o přihlášení a IP adresu, ze které přišly.

## K čemu údaje slouží
Ke správě domu a plnění povinností společenství: vedení seznamu členů, svolávání shromáždění, hlasování, řešení závad a rozesílání oznámení výboru. Provozní záznamy slouží k ochraně účtů před zneužitím.

## Kdo údaje vidí
- Výbor (správci webu) vidí všechny údaje.
- Ostatní přihlášení obyvatelé vidí Vaše jméno a jednotku u příspěvků v diskuzi a u rezervací.
- Vaše hlasy v hlasování a nahlášené závady vidí jen výbor a lidé z Vaší jednotky.
- Veřejnost nevidí nic z výše uvedeného.

## E-maily
Výbor Vám může posílat důležité zprávy e-mailem. Zasílání si můžete vypnout ve svém účtu. U rozeslaných zpráv web eviduje, zda byla zpráva otevřena a zda jste klikli na odkaz v ní, aby výbor věděl, ke komu se informace dostala.

## Jak dlouho údaje uchováváme
Po dobu, kdy jste vlastníkem nebo obyvatelem domu, a dále po dobu, kterou společenství ukládají právní předpisy. Účet Vám výbor na požádání zruší.

## Vaše práva
Máte právo na přístup ke svým údajům, jejich opravu, výmaz, omezení zpracování a právo vznést námitku. Obraťte se na výbor. Stížnost můžete podat u Úřadu pro ochranu osobních údajů (www.uoou.cz).
TXT;
