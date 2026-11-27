<?php
declare(strict_types=1);

/*
 * Závady a požadavky: přiřazení řešiteli, termín, historie změn a zprávy mezi správcem a obyvatelem.
 *
 * - ticket_log drží vše, co se s hlášením děje: vznik, změny stavu, přiřazení, termín, dodavatele (správci vidí vše)
 *   a zprávy v obou směrech (msg_admin, msg_author – ty vidí i obyvatel, spolu se změnami stavu).
 * - Nové hlášení a odpověď obyvatele dostanou správci e-mailem (a do telefonu); je-li hlášení přiřazené, jen řešitel.
 *   Spravuje-li dům partner (správcovská firma), dostanou ho i jeho pracovníci, kteří mají upozornění zapnutá.
 * - Po termínu se hlášení zvýrazní a řešiteli jednou denně přijde připomínka.
 * Tabulky a sloupce se doplní samy při prvním použití (i v databázi jiného domu – partnerský portál).
 */

const TICKET_LOG_PUBLIC = ['created', 'status', 'msg_admin', 'msg_author'];

function ticket_ready(?PDO $pdo = null): void
{
    static $done = [];
    $pdo ??= db();
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $exists = $mysql ? (bool) $pdo->query("SHOW TABLES LIKE 'ticket_log'")->fetch() : (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ticket_log'")->fetch();
    if ($exists) {
        return;
    }
    $s = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $int = $mysql ? 'INT' : 'INTEGER';
    $pdo->exec("ALTER TABLE tickets ADD COLUMN assignee_id $int NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE tickets ADD COLUMN due_date {$s(10)}");
    $pdo->exec('CREATE TABLE ticket_log (id ' . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ", ticket_id $int NOT NULL, at {$s(19)}, user_id $int NOT NULL DEFAULT 0,"
        . " user_name {$s(191)}, kind {$s(20)}, text " . ($mysql ? 'TEXT NULL' : "TEXT NOT NULL DEFAULT ''") . ')' . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    $pdo->exec('CREATE INDEX ticket_log_ticket ON ticket_log (ticket_id, id)');
}
function ticket_log_add(int $ticketId, string $kind, string $text, ?array $by = null): void
{
    ticket_ready();
    $by ??= user();
    $name = $by ? $by['name'] : '';
    if ($by && !empty($_SESSION['partner'])) {
        $name = (string) $_SESSION['partner'];
    }
    q('INSERT INTO ticket_log (ticket_id, at, user_id, user_name, kind, text) VALUES (?,?,?,?,?,?)', [$ticketId, date('Y-m-d H:i:s'), (int) ($by['id'] ?? 0), $name, $kind, mb_substr($text, 0, 5000)]);
}
/** Historie hlášení; $public = jen to, co vidí obyvatel (zprávy a změny stavu). */
function ticket_log(array $ticketIds, bool $public = false): array
{
    ticket_ready();
    $ticketIds = array_values(array_filter(array_map('intval', $ticketIds)));
    if (!$ticketIds) {
        return [];
    }
    $sql = 'SELECT * FROM ticket_log WHERE ticket_id IN (' . implode(',', $ticketIds) . ')' . ($public ? " AND kind IN ('" . implode("','", TICKET_LOG_PUBLIC) . "')" : '') . ' ORDER BY id';
    $out = [];
    foreach (all($sql) as $r) {
        $out[(int) $r['ticket_id']][] = $r;
    }
    return $out;
}
/** Správci, kteří mohou hlášení řešit (do výběru řešitele). */
function ticket_admins(): array
{
    return all("SELECT id, name, email FROM users WHERE role = 'admin' AND active = 1 ORDER BY name");
}
function ticket_overdue(array $t): bool
{
    return ($t['due_date'] ?? '') !== '' && $t['status'] !== 'done' && $t['due_date'] < date('Y-m-d');
}
/** Komu poslat upozornění: řešiteli, jinak všem správcům; e-maily pracovníků partnera, kteří ve webu ještě nebyli, doplní ticket_partner_emails(). */
function ticket_recipients(array $t): array
{
    if ((int) ($t['assignee_id'] ?? 0) && ($a = one("SELECT id, email, name FROM users WHERE id = ? AND active = 1 AND role = 'admin'", [(int) $t['assignee_id']]))) {
        return [$a];
    }
    return all("SELECT id, email, name FROM users WHERE role = 'admin' AND active = 1");
}
/** Pracovníci partnera (správcovské firmy) s upozorněním na hlášení – i ti, kdo do webu domu ještě nevstoupili. */
function ticket_partner_emails(): array
{
    if (!defined('TENANT') || !function_exists('tenant_row') || !($t = tenant_row(TENANT)) || !(int) $t['partner_id'] || $t['partner_status'] !== 'active') {
        return [];
    }
    return array_column(pq('SELECT email FROM partner_users WHERE partner_id = ? AND active = 1 AND notify_tickets = 1', [(int) $t['partner_id']])->fetchAll(), 'email');
}
/**
 * Upozornění správcům: nové hlášení, nebo odpověď obyvatele. Je-li zapnutý příjem odpovědí e-mailem (app/ext/inbound_lib.php),
 * dostane každý aktivní správce domu vlastní značku vlákna – jeho odpověď na e-mail se uloží jako zpráva obyvateli.
 */
function ticket_notify_admins(array $t, string $what, string $text): void
{
    $users = ticket_recipients($t);
    $emails = array_map('strtolower', array_column($users, 'email'));
    if (!(int) ($t['assignee_id'] ?? 0)) {
        foreach (ticket_partner_emails() as $pe) {
            if (!in_array(strtolower($pe), $emails, true)) {
                $emails[] = strtolower($pe);
            }
        }
    }
    $subject = $what . ': ' . $t['subject'] . ' – ' . site('name');
    $body = "Dobrý den,\n\n$what na webu " . site('name') . ":\n\n" . $t['subject'] . ($t['location'] !== '' ? ' (' . $t['location'] . ')' : '') . "\n\n" . $text
        . "\n\nVyřídit: " . absolute_url('/admin/zavady#z' . $t['id']);
    $canAnswer = (int) ($t['user_id'] ?? 0) > 0;
    foreach (array_unique($emails) as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $in = $canAnswer && function_exists('inbound_reply') && ticket_admin_by_email($email) ? inbound_reply('V', TENANT, (int) $t['id'], $email) : null;
            $in ? send_mail($email, $subject . ' ' . $in['tag'], $body . "\nOdpovědět obyvateli můžete i přímo odpovědí na tento e-mail.\n\n" . $in['line'], null, null, null, $in['reply_to'])
                : send_mail($email, $subject, $body);
        }
    }
    if (function_exists('push_notify')) {
        push_notify(array_column($users, 'id'), site('name'), $what . ': ' . $t['subject'], '/admin/zavady#z' . $t['id']);
    }
}
/** Aktivní správce domu s danou e-mailovou adresou (jen ten smí odpovídat obyvateli e-mailem), nebo null. */
function ticket_admin_by_email(string $email): ?array
{
    return one("SELECT * FROM users WHERE LOWER(email) = ? AND role = 'admin' AND active = 1 ORDER BY id LIMIT 1", [strtolower(trim($email))]);
}
/** Zpráva správce obyvateli: e-mail a upozornění do telefonu. */
function ticket_notify_author(array $t, string $text): void
{
    $u = (int) $t['user_id'] ? one('SELECT * FROM users WHERE id = ? AND active = 1', [(int) $t['user_id']]) : null;
    if (!$u) {
        return;
    }
    if (filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
        $in = function_exists('inbound_reply') ? inbound_reply('Z', TENANT, (int) $t['id'], (string) $u['email']) : null;
        send_mail($u['email'], 'Odpověď na Vaše hlášení: ' . $t['subject'] . ' – ' . site('name') . ($in ? ' ' . $in['tag'] : ''), "Dobrý den,\n\nk Vašemu hlášení „" . $t['subject'] . "“ přišla odpověď:\n\n" . $text
            . "\n\nStav: " . mb_strtolower(TICKET_STATUS[$t['status']]) . ($in ? "\nOdpovědět můžete přímo na tento e-mail, nebo na webu: " . absolute_url('/zavady#z' . $t['id']) . "\n\n" . $in['line']
                : "\nOdpovědět můžete na webu: " . absolute_url('/zavady#z' . $t['id'])), null, null, null, $in['reply_to'] ?? null);
    }
    if (function_exists('push_notify')) {
        push_notify([(int) $u['id']], site('name'), 'Odpověď na hlášení „' . $t['subject'] . '“', '/zavady#z' . $t['id']);
    }
}
/**
 * Odpověď obyvatele ve vlákně (web /zavady/{id}/odpoved i odpověď e-mailem): vyřešené hlášení se znovu otevře,
 * zpráva se zapíše a správci (řešitel) dostanou upozornění.
 */
function ticket_author_reply(array $t, array $u, string $text): void
{
    ticket_ready();
    $id = (int) $t['id'];
    if ($t['status'] === 'done') {
        q("UPDATE tickets SET status = 'in_progress', updated_at = datetime('now','localtime') WHERE id = ?", [$id]);
        ticket_log_add($id, 'status', TICKET_STATUS['in_progress'] . ' (znovu otevřeno odpovědí obyvatele)', $u);
        $t['status'] = 'in_progress';
    }
    ticket_log_add($id, 'msg_author', $text, $u);
    ticket_notify_admins($t, 'Odpověď obyvatele', $u['name'] . ":\n" . $text);
}
/** Zpráva správce obyvateli (z administrace i odpovědí správce e-mailem): zápis do historie a upozornění obyvateli. */
function ticket_admin_reply(array $t, ?array $by, string $message): void
{
    ticket_log_add((int) $t['id'], 'msg_admin', mb_substr($message, 0, 5000), $by);
    ticket_notify_author($t, $message);
}
/** Denně: připomínka hlášení po termínu (řešiteli, jinak správcům) – jednou denně. */
function ticket_daily(): void
{
    ticket_ready();
    $today = date('Y-m-d');
    foreach (all("SELECT * FROM tickets WHERE status <> 'done' AND due_date <> '' AND due_date < ?", [$today]) as $t) {
        if (val("SELECT 1 FROM ticket_log WHERE ticket_id = ? AND kind = 'reminder' AND at >= ?", [$t['id'], $today . ' 00:00:00'])) {
            continue;
        }
        ticket_log_add((int) $t['id'], 'reminder', 'Připomínka: hlášení je po termínu ' . cz_date($t['due_date']) . '.', ['id' => 0, 'name' => 'Web']);
        foreach (ticket_recipients($t) as $r) {
            if (filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
                send_mail($r['email'], 'Po termínu: ' . $t['subject'] . ' – ' . site('name'), "Dobrý den,\n\nhlášení „" . $t['subject'] . '“ mělo být vyřešené do ' . cz_date($t['due_date'])
                    . ".\nStav: " . mb_strtolower(TICKET_STATUS[$t['status']]) . "\n\nVyřídit: " . absolute_url('/admin/zavady#z' . $t['id']));
            }
        }
    }
}
/** Popis změny pro historii. */
function ticket_log_label(array $r): string
{
    return match ($r['kind']) {
        'created' => 'nahlásil(a)',
        'status' => 'změna stavu',
        'assign' => 'řešitel',
        'due' => 'termín',
        'supplier' => 'dodavatel',
        'edit' => 'úprava textu',
        'msg_admin' => 'zpráva obyvateli',
        'msg_author' => 'odpověď obyvatele',
        'reminder' => 'připomínka',
        default => $r['kind'],
    };
}
