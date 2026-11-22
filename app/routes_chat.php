<?php
declare(strict_types=1);

// Zprávy mezi účty jednoho společenství: soukromé rozhovory dvou přihlášených lidí.
// Zprávy vidí jen odesílatel a příjemce; správce webu je v administraci nečte.

const CHAT_MAX_LENGTH = 2000;
const CHAT_PER_HOUR = 60;

/** Smí přihlášený psát tomuto člověku? Kdo si zprávy od sousedů vypnul, tomu píše jen výbor, kontrolní komise a správci – a ten, komu sám napsal. */
function chat_can_write(array $me, array $other): bool
{
    if ((int) $other['id'] === (int) $me['id'] || !$other['active'] || $other['password_hash'] === NO_PASSWORD) {
        return false;
    }
    if (empty($other['chat_off']) || $me['role'] === 'admin' || ($me['position'] ?? '') !== '') {
        return true;
    }
    return (bool) val('SELECT 1 FROM messages WHERE from_id = ? AND to_id = ? LIMIT 1', [$other['id'], $me['id']]);
}
function chat_unread(int $userId): int
{
    return (int) val('SELECT COUNT(*) FROM messages WHERE to_id = ? AND read_at IS NULL', [$userId]);
}
/** Zpráva pro odeslání do prohlížeče. */
function chat_message_view(array $m, int $me): array
{
    return ['id' => (int) $m['id'], 'mine' => (int) $m['from_id'] === $me, 'html' => nl2br(e($m['body'])), 'time' => cz_datetime($m['created_at']), 'read' => $m['read_at'] !== null];
}

$route('GET', '/zpravy', function () {
    $u = require_login();
    $me = (int) $u['id'];
    // Poslední zpráva každého rozhovoru a počet nepřečtených.
    $last = [];
    foreach (all('SELECT * FROM messages WHERE from_id = ? OR to_id = ? ORDER BY id DESC LIMIT 2000', [$me, $me]) as $m) {
        $other = (int) $m['from_id'] === $me ? (int) $m['to_id'] : (int) $m['from_id'];
        $last[$other] ??= ['m' => $m, 'unread' => 0];
        if ((int) $m['to_id'] === $me && $m['read_at'] === null) {
            $last[$other]['unread']++;
        }
    }
    $people = [];
    foreach (all('SELECT u.*, ' . units_sql('u') . " units FROM users u WHERE u.active = 1 AND u.password_hash <> ? AND u.id <> ? ORDER BY u.name", [NO_PASSWORD, $me]) as $p) {
        $people[(int) $p['id']] = $p;
    }
    $conversations = [];
    foreach ($last as $other => $c) {
        if (isset($people[$other])) {
            $conversations[] = $c + ['user' => $people[$other]];
        }
    }
    $committee = array_filter($people, fn($p) => ($p['position'] ?? '') !== '');
    uasort($committee, fn($a, $b) => array_search($a['position'], array_keys(POSITIONS), true) <=> array_search($b['position'], array_keys(POSITIONS), true));
    $neighbours = array_filter($people, fn($p) => ($p['position'] ?? '') === '' && chat_can_write($u, $p));
    render('chat', ['title' => 'Zprávy', 'conversations' => $conversations, 'committee' => $committee, 'neighbours' => $neighbours]);
});

$route('GET', '/zpravy/(\d+)', function (int $id) {
    $u = require_login();
    $me = (int) $u['id'];
    $other = one('SELECT u.*, ' . units_sql('u') . ' units FROM users u WHERE u.id = ?', [$id]);
    $has = (bool) val('SELECT 1 FROM messages WHERE (from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?) LIMIT 1', [$me, $id, $id, $me]);
    if (!$other || $id === $me || (!$has && !chat_can_write($u, $other))) {
        not_found();
    }
    q('UPDATE messages SET read_at = ? WHERE to_id = ? AND from_id = ? AND read_at IS NULL', [date('Y-m-d H:i:s'), $me, $id]);
    $rows = array_reverse(all('SELECT * FROM messages WHERE (from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?) ORDER BY id DESC LIMIT 300', [$me, $id, $id, $me]));
    render('chat_thread', ['title' => 'Zprávy · ' . $other['name'], 'other' => $other, 'messages' => array_map(fn($m) => chat_message_view($m, $me), $rows), 'canWrite' => chat_can_write($u, $other)]);
});

// Nové zprávy od posledního zobrazeného čísla (stránka se na ně sama ptá každých pár vteřin).
$route('GET', '/zpravy/(\d+)/nove', function (int $id) {
    $u = require_login();
    $me = (int) $u['id'];
    $after = (int) ($_GET['po'] ?? 0);
    $rows = all('SELECT * FROM messages WHERE id > ? AND ((from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?)) ORDER BY id LIMIT 100', [$after, $me, $id, $id, $me]);
    if ($rows) {
        q('UPDATE messages SET read_at = ? WHERE to_id = ? AND from_id = ? AND read_at IS NULL', [date('Y-m-d H:i:s'), $me, $id]);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['messages' => array_map(fn($m) => chat_message_view($m, $me), $rows)], JSON_UNESCAPED_UNICODE);
    exit;
});

$route('POST', '/zpravy/(\d+)', function (int $id) {
    $u = require_login();
    $me = (int) $u['id'];
    $other = one('SELECT * FROM users WHERE id = ?', [$id]);
    $body = trim(str_replace("\r", '', (string) ($_POST['body'] ?? '')));
    $error = null;
    if (!$other || !chat_can_write($u, $other)) {
        $error = 'Tomuto člověku teď zprávu poslat nelze.';
    } elseif ($body === '') {
        $error = 'Napište text zprávy.';
    } elseif (mb_strlen($body) > CHAT_MAX_LENGTH) {
        $error = 'Zpráva je příliš dlouhá (nejvýše ' . CHAT_MAX_LENGTH . ' znaků).';
    } elseif ((int) val('SELECT COUNT(*) FROM messages WHERE from_id = ? AND created_at >= ?', [$me, date('Y-m-d H:i:s', time() - 3600)]) >= CHAT_PER_HOUR) {
        $error = 'Za poslední hodinu jste poslali hodně zpráv. Zkuste to prosím za chvíli.';
    }
    if ($error) {
        flash($error, 'error');
        redirect($other ? '/zpravy/' . $id : '/zpravy');
    }
    // E-mailem dáme vědět jen o první nepřečtené zprávě v rozhovoru, ne o každé další.
    $pending = (bool) val('SELECT 1 FROM messages WHERE from_id = ? AND to_id = ? AND read_at IS NULL LIMIT 1', [$me, $id]);
    q('INSERT INTO messages (from_id, to_id, body, created_at) VALUES (?,?,?,?)', [$me, $id, $body, date('Y-m-d H:i:s')]);
    if (!$pending && !empty($other['email_notify'])) {
        send_mail($other['email'], 'Nová zpráva na webu – ' . site('name'), "Dobrý den,\n\n" . $u['name'] . " Vám na webu " . site('name') . " poslal(a) zprávu.\nPřečtete si ji po přihlášení: "
            . absolute_url('/zpravy/' . $me) . "\n\nText zprávy v e-mailu z důvodu soukromí neposíláme. Upozornění na zprávy vypnete ve svém účtu.");
    }
    redirect('/zpravy/' . $id . '#konec');
});
