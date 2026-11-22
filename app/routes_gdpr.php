<?php
declare(strict_types=1);

// Ochrana osobních údajů (GDPR): přehled a stažení vlastních údajů, zrušení vlastního účtu, export údajů uživatele pro výbor.

/** Všechno, co web o člověku ukládá, v podobě vhodné ke stažení (bez hesla a tajných klíčů). */
function gdpr_export(int $userId): array
{
    $u = one('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$u) {
        return [];
    }
    $names = array_column(all('SELECT id, name FROM users'), 'name', 'id');
    $who = fn($id) => $names[(int) $id] ?? 'bývalý uživatel';
    $data = [
        'vytvořeno' => date('c'),
        'web' => site('name'),
        'účet' => ['jméno' => $u['name'], 'e-mail' => $u['email'], 'telefon' => $u['phone'], 'vztah k domu' => KINDS[$u['kind']] ?? $u['kind'], 'role na webu' => ROLES[$u['role']] ?? $u['role'],
            'funkce ve společenství' => position_label($u), 'účet aktivní' => (bool) $u['active'], 'e-mailová upozornění' => (bool) $u['email_notify'], 'zprávy od sousedů vypnuté' => !empty($u['chat_off']),
            'dvoufázové ověření' => (bool) $u['totp_enabled'], 'účet založen' => $u['created_at'] ?? null, 'poslední přihlášení' => $u['last_login_at'] ?? null],
        'jednotky' => array_map(fn($r) => ['jednotka' => unit_name($r), 'spoluvlastnický podíl' => $r['share'] ?? ''], all('SELECT un.* FROM units un JOIN user_units uu ON uu.unit_id = un.id WHERE uu.user_id = ?', [$userId])),
        'příspěvky v diskuzi' => all('SELECT title, body, created_at FROM forum_posts WHERE author_id = ? ORDER BY id', [$userId]),
        'závady a požadavky' => all('SELECT subject, location, body, status, created_at FROM tickets WHERE user_id = ? ORDER BY id', [$userId]),
        'odečty měřidel' => all('SELECT kind, meter_no, amount, read_date, note FROM readings WHERE user_id = ? ORDER BY id', [$userId]),
        'rezervace' => all('SELECT r.res_date, r.time_from, r.time_to, r.note, s.name AS prostor FROM reservations r JOIN resources s ON s.id = r.resource_id WHERE r.user_id = ? ORDER BY r.res_date', [$userId]),
        'potvrzení účasti' => all('SELECT e.title AS termin, e.event_date, r.status, r.proxy_name, r.updated_at FROM event_rsvp r JOIN events e ON e.id = r.event_id WHERE r.user_id = ?', [$userId]),
        'hlasy v hlasování' => all('SELECT p.title AS hlasovani, i.question AS otazka, v.choice, v.voted_at FROM poll_votes v JOIN poll_items i ON i.id = v.item_id JOIN polls p ON p.id = i.poll_id WHERE v.user_id = ?', [$userId]),
        'odpovědi v anketách' => all('SELECT s.title AS anketa, o.label AS odpoved, a.answered_at FROM survey_answers a JOIN surveys s ON s.id = a.survey_id JOIN survey_options o ON o.id = a.option_id WHERE a.user_id = ?', [$userId]),
        'zprávy' => array_map(fn($m) => ['od' => $who($m['from_id']), 'komu' => $who($m['to_id']), 'text' => $m['body'], 'odesláno' => $m['created_at'], 'přečteno' => $m['read_at']],
            all('SELECT * FROM messages WHERE from_id = ? OR to_id = ? ORDER BY id', [$userId, $userId])),
        'e-maily od výboru' => all('SELECT m.subject, m.sent_at, r.email, r.opened_at, r.clicked_at FROM mailing_recipients r JOIN mailings m ON m.id = r.mailing_id WHERE r.user_id = ? ORDER BY r.id', [$userId]),
        'provozní záznamy' => all('SELECT at, action, ip FROM audit_log WHERE user_id = ? ORDER BY id DESC LIMIT 500', [$userId]),
    ];
    return $data;
}
function gdpr_download(array $data, string $name): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$route('GET', '/muj-ucet/moje-udaje', function () {
    $u = require_login();
    $d = gdpr_export((int) $u['id']);
    $counts = [];
    foreach ($d as $k => $v) {
        if (is_array($v) && array_is_list($v)) {
            $counts[$k] = count($v);
        }
    }
    $lastAdmin = $u['role'] === 'admin' && (int) val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1") <= 1;
    render('gdpr', ['title' => 'Moje údaje a soukromí', 'u' => $u, 'account' => $d['účet'], 'counts' => $counts, 'lastAdmin' => $lastAdmin]);
});
$route('GET', '/muj-ucet/moje-udaje.json', function () {
    $u = require_login();
    audit('gdpr_export', 'stažení vlastních údajů');
    gdpr_download(gdpr_export((int) $u['id']), 'moje-udaje-' . date('Y-m-d') . '.json');
});
// Právo na výmaz: člověk si účet zruší sám. Poslední správce to udělat nemůže – web by zůstal bez správy.
$route('POST', '/muj-ucet/smazat-ucet', function () {
    $u = require_login();
    $errors = [];
    if (!password_verify((string) ($_POST['password'] ?? ''), $u['password_hash'])) {
        $errors[] = 'Heslo není správně.';
    }
    if (!isset($_POST['understand'])) {
        $errors[] = 'Potvrďte, že rozumíte tomu, že účet nepůjde obnovit.';
    }
    if ($u['role'] === 'admin' && (int) val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1") <= 1) {
        $errors[] = 'Jste jediný správce webu. Nejdřív předejte správu někomu dalšímu, nebo zrušte celý web v části Předplatné.';
    }
    if ($errors) {
        flash(implode(' ', $errors), 'error');
        redirect('/muj-ucet/moje-udaje#smazat');
    }
    $id = (int) $u['id'];
    audit('gdpr_erasure', 'účet zrušen na vlastní žádost: ' . $u['name']);
    q('DELETE FROM users WHERE id = ?', [$id]);   // zprávy, přiřazení k jednotkám a rezervace se smažou s účtem; příspěvky a závady zůstanou bez jména
    if (function_exists('rq') && reports_central()) {
        rq("UPDATE reports SET user_name = '', user_email = '', user_id = 0 WHERE tenant = ? AND user_id = ?", [defined('TENANT') ? TENANT : '', $id]);
    }
    send_mail($u['email'], 'Váš účet na webu ' . site('name') . ' byl zrušen', "Dobrý den,\n\nna Vaši žádost jsme zrušili účet na webu " . site('name') . " a smazali údaje, které k němu patřily.\n"
        . "Pokud jste o zrušení nežádali, obraťte se prosím hned na výbor.\n\nTento e-mail je poslední, který Vám z webu posíláme.");
    $webmaster = setting('webmaster_email');
    if (filter_var($webmaster, FILTER_VALIDATE_EMAIL) && $webmaster !== $u['email']) {
        send_mail($webmaster, 'Účet zrušen na vlastní žádost – ' . site('name'), "Uživatel " . $u['name'] . " si na webu sám zrušil účet. Údaje účtu byly smazány; záznam o tom je v Záznamech.");
    }
    $_SESSION = [];
    session_regenerate_id(true);
    flash('Váš účet byl zrušen a údaje smazány.', 'info');
    redirect('/');
});
// Výbor vyřizuje žádost o přístup k údajům za člověka, který se sám nepřihlásí.
$route('GET', '/admin/uzivatele/(\d+)/udaje.json', function (int $id) {
    require_admin();
    $d = gdpr_export($id);
    if (!$d) {
        not_found();
    }
    audit('gdpr_export', 'export údajů uživatele: ' . $d['účet']['jméno'], null);
    gdpr_download($d, 'udaje-uzivatele-' . $id . '-' . date('Y-m-d') . '.json');
});
