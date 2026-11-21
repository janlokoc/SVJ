<?php
declare(strict_types=1);

// ---------- Můj účet ----------

$accountData = function (array $u, array $errors = []): array {
    $units = all('SELECT un.* FROM units un JOIN user_units uu ON uu.unit_id = un.id WHERE uu.user_id = ? ORDER BY un.entrance, un.number', [$u['id']]);
    foreach ($units as &$un) {
        $un['people'] = all('SELECT u.id, u.name, u.kind, u.invited_by, u.password_hash = ? pending FROM users u JOIN user_units uu ON uu.user_id = u.id WHERE uu.unit_id = ? AND u.active = 1 ORDER BY u.name', [NO_PASSWORD, $un['id']]);
    }
    $link = $_SESSION['invite_link'] ?? null;
    unset($_SESSION['invite_link']);
    return ['title' => 'Můj účet', 'u' => $u, 'errors' => $errors, 'units' => $units, 'inviteLink' => $link,
        'totpSetup' => $u['totp_enabled'] ? null : ($_SESSION['totp_setup'] ?? null),
        'canInvite' => setting_on('invite_enabled') && ($u['kind'] === 'owner' || setting_on('invite_by_tenants', false)),
        'inviteDays' => setting_int('invite_days', 14, 1, 90)];
};

$route('GET', '/muj-ucet', function () use ($accountData) {
    render('account', $accountData(require_login()));
});

$route('POST', '/muj-ucet', function () use ($accountData) {
    $u = require_login();
    $errors = [];
    if (post('action') === 'password') {
        $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify((string) ($_POST['current_password'] ?? ''), $u['password_hash'])) {
            $errors[] = 'Současné heslo není správně.';
        }
        if ($problem = password_problem($new, $u['email'], $u['name'])) {
            $errors[] = $problem;
        }
        if (!$errors && password_verify($new, $u['password_hash'])) {
            $errors[] = 'Nové heslo musí být jiné než dosavadní.';
        }
        if ($new !== (string) ($_POST['new_password2'] ?? '')) {
            $errors[] = 'Nová hesla se neshodují.';
        }
        if (!$errors) {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [$hash, $u['id']]);
            session_regenerate_id(true);
            $_SESSION['pv'] = pw_fingerprint($hash); // toto přihlášení zůstává, ostatní zařízení se odhlásí
            audit('password_changed');
            send_mail($u['email'], 'Heslo na webu SVJ Tábor 48 bylo změněno', "Dobrý den,\n\nheslo k Vašemu účtu na webu SVJ Tábor 48 a–d bylo právě změněno.\n\nPokud jste to nebyli Vy, obraťte se hned na výbor SVJ.\n");
            flash('Heslo bylo změněno. Na ostatních zařízeních jsme Vás odhlásili.');
            redirect('/muj-ucet');
        }
    } else {
        $name = post('name');
        if ($name === '') {
            $errors[] = 'Vyplňte jméno.';
        }
        if (!$errors) {
            q('UPDATE users SET name = ?, phone = ?, email_notify = ? WHERE id = ?', [$name, post('phone'), isset($_POST['email_notify']) ? 1 : 0, $u['id']]);
            flash('Údaje byly uloženy.');
            redirect('/muj-ucet');
        }
    }
    http_response_code(422);
    render('account', $accountData($u, $errors));
});

// ---------- Pozvání člena domácnosti ----------

$route('POST', '/muj-ucet/pozvat', function () use ($accountData) {
    $u = require_login();
    $mine = user_unit_ids((int) $u['id']);
    $name = post('name');
    $email = post('email');
    $chosen = array_values(array_intersect($mine, array_map('intval', (array) ($_POST['units'] ?? []))));
    $errors = [];
    $days = setting_int('invite_days', 14, 1, 90);
    $max = setting_int('invite_max', 6, 1, 20);
    if (!setting_on('invite_enabled') || !$mine || ($u['kind'] !== 'owner' && !setting_on('invite_by_tenants', false))) {
        $errors[] = 'Pozvat dalšího člena domácnosti teď nemůžete. Obraťte se prosím na výbor.';
    } else {
        if ($name === '') {
            $errors[] = 'Vyplňte jméno pozvané osoby.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Zadejte platný e-mail pozvané osoby.';
        } elseif (val('SELECT 1 FROM users WHERE email = ?', [$email])) {
            $errors[] = 'Účet s tímto e-mailem už existuje. O přiřazení k jednotce požádejte výbor.';
        }
        if (!$chosen) {
            $errors[] = 'Vyberte alespoň jednu jednotku.';
        }
        if ((int) val('SELECT COUNT(*) FROM users WHERE invited_by = ? AND password_hash = ?', [$u['id'], NO_PASSWORD]) >= 3) {
            $errors[] = 'Máte už tři nepřijaté pozvánky. Počkejte na jejich přijetí, nebo některou zrušte.';
        }
        foreach ($chosen as $unitId) {
            if ((int) val('SELECT COUNT(*) FROM user_units WHERE unit_id = ?', [$unitId]) >= $max) {
                $errors[] = 'K jedné jednotce lze pozvánkou přiřadit nejvýše ' . $max . ' účtů. Další účet může přidat výbor.';
                break;
            }
        }
    }
    if ($errors) {
        http_response_code(422);
        render('account', $accountData($u, $errors));
        return;
    }
    $token = bin2hex(random_bytes(32));
    q("INSERT INTO users (name, email, role, kind, password_hash, invited_by, invite_hash, invite_expires) VALUES (?,?,'resident',?,?,?,?,?)",
        [$name, $email, $u['kind'] === 'tenant' ? 'tenant' : (setting('invite_kind', 'owner') === 'tenant' ? 'tenant' : 'owner'), NO_PASSWORD, $u['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + $days * 86400)]);
    $newId = (int) db()->lastInsertId();
    foreach ($chosen as $unitId) {
        q('INSERT INTO user_units (user_id, unit_id) VALUES (?,?)', [$newId, $unitId]);
    }
    audit('invite_sent', $email);
    $link = absolute_url('/pozvanka/' . $token);
    $text = "Dobrý den,\n\n" . $u['name'] . " Vás zve na web SVJ Tábor 48 a–d jako dalšího člena domácnosti.\n\nÚčet si aktivujete nastavením hesla na této adrese (platí $days dní):\n$link\n\nPokud pozvánku nečekáte, zprávu prosím ignorujte.\n";
    if (send_mail($email, 'Pozvánka na web SVJ Tábor 48', $text)) {
        flash('Pozvánka byla odeslána na ' . $email . '. Platí ' . $days . ' dní.');
    } else {
        $_SESSION['invite_link'] = ['name' => $name, 'link' => $link];
    }
    redirect('/muj-ucet');
});

$route('POST', '/muj-ucet/pozvanka/(\d+)/zrusit', function (int $id) {
    $u = require_login();
    q('DELETE FROM users WHERE id = ? AND invited_by = ? AND password_hash = ?', [$id, $u['id'], NO_PASSWORD]);
    flash('Pozvánka byla zrušena.');
    redirect('/muj-ucet');
});

// ---------- Dvoufázové ověření ----------

$route('POST', '/muj-ucet/overeni', function () use ($accountData) {
    $u = require_login();
    $action = post('action');
    $errors = [];
    if ($action === 'start' && !$u['totp_enabled']) {
        $_SESSION['totp_setup'] = totp_new_secret();
    } elseif ($action === 'cancel') {
        unset($_SESSION['totp_setup']);
    } elseif ($action === 'confirm' && !$u['totp_enabled'] && !empty($_SESSION['totp_setup'])) {
        $step = totp_verify($_SESSION['totp_setup'], post('code'));
        if ($step === null) {
            $errors[] = 'Kód nesouhlasí. Zkontrolujte, že jste klíč opsali správně a že má telefon správný čas, a zadejte aktuální kód.';
        } else {
            q('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last = ? WHERE id = ?', [$_SESSION['totp_setup'], $step, $u['id']]);
            unset($_SESSION['totp_setup']);
            audit('2fa_enabled');
            flash('Dvoufázové ověření je zapnuté. Při příštím přihlášení zadáte i kód z aplikace.');
        }
    } elseif ($action === 'disable' && $u['totp_enabled']) {
        if (!password_verify((string) ($_POST['current_password'] ?? ''), $u['password_hash'])) {
            $errors[] = 'Pro vypnutí dvoufázového ověření zadejte správné současné heslo.';
        } else {
            q('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last = 0 WHERE id = ?', [$u['id']]);
            audit('2fa_disabled');
            flash('Dvoufázové ověření bylo vypnuto.', 'info');
        }
    }
    if ($errors) {
        http_response_code(422);
        render('account', $accountData($u, $errors));
        return;
    }
    redirect('/muj-ucet#overeni');
});

// ---------- Diskuze ----------

$route('GET', '/diskuze', function () {
    require_login();
    $threads = all('SELECT f.*, u.name author_name, ' . units_sql('u') . ' author_units,
            (SELECT COUNT(*) FROM forum_posts r WHERE r.parent_id = f.id) replies
        FROM forum_posts f LEFT JOIN users u ON u.id = f.author_id
        WHERE f.parent_id IS NULL ORDER BY f.last_activity_at DESC');
    render('forum', ['title' => 'Diskuze', 'threads' => $threads]);
});

$route('POST', '/diskuze', function () {
    $u = require_login();
    $title = post('title');
    $body = post('body');
    if ($title === '' || $body === '' || mb_strlen($title) > 150 || mb_strlen($body) > 5000) {
        flash('Vyplňte prosím název i text příspěvku (název max. 150 znaků, text max. 5000).', 'error');
        redirect('/diskuze');
    }
    q('INSERT INTO forum_posts (title, body, author_id) VALUES (?,?,?)', [$title, $body, $u['id']]);
    redirect('/diskuze/' . db()->lastInsertId());
});

$route('GET', '/diskuze/(\d+)', function (int $id) {
    require_login();
    $sql = 'SELECT f.*, u.name author_name, ' . units_sql('u') . ' author_units, u.role author_role FROM forum_posts f LEFT JOIN users u ON u.id = f.author_id ';
    $thread = one($sql . 'WHERE f.id = ? AND f.parent_id IS NULL', [$id]) ?? not_found();
    render('thread', ['title' => $thread['title'], 'thread' => $thread, 'replies' => all($sql . 'WHERE f.parent_id = ? ORDER BY f.id', [$id])]);
});

$route('POST', '/diskuze/(\d+)', function (int $id) {
    $u = require_login();
    one('SELECT id FROM forum_posts WHERE id = ? AND parent_id IS NULL', [$id]) ?? not_found();
    $body = post('body');
    if ($body === '' || mb_strlen($body) > 5000) {
        flash('Napište prosím text odpovědi (max. 5000 znaků).', 'error');
    } else {
        q('INSERT INTO forum_posts (parent_id, body, author_id) VALUES (?,?,?)', [$id, $body, $u['id']]);
        q("UPDATE forum_posts SET last_activity_at = datetime('now','localtime') WHERE id = ?", [$id]);
    }
    redirect('/diskuze/' . $id);
});

$route('POST', '/diskuze/(\d+)/smazat', function (int $id) {
    $u = require_login();
    $p = one('SELECT * FROM forum_posts WHERE id = ?', [$id]) ?? not_found();
    if ($u['role'] !== 'admin' && (int) $p['author_id'] !== (int) $u['id']) {
        not_found();
    }
    if ((int) $p['author_id'] !== (int) $u['id']) {
        audit('Smazání příspěvku v diskuzi', ($p['title'] !== '' ? $p['title'] : 'odpověď č. ' . $id) . ' · autor: ' . ((string) val('SELECT name FROM users WHERE id = ?', [$p['author_id']]) ?: 'neznámý'));
    }
    q('DELETE FROM forum_posts WHERE id = ?', [$id]);
    flash('Příspěvek byl smazán.');
    redirect($p['parent_id'] ? '/diskuze/' . $p['parent_id'] : '/diskuze');
});

// ---------- Hlášení závad ----------

$route('GET', '/zavady', function () {
    $u = require_login();
    render('tickets', ['title' => 'Závady a požadavky', 'tickets' => all('SELECT * FROM tickets WHERE user_id = ? ORDER BY id DESC', [$u['id']])]);
});

$route('POST', '/zavady', function () {
    $u = require_login();
    $subject = post('subject');
    $body = post('body');
    if ($subject === '' || $body === '' || mb_strlen($subject) > 150 || mb_strlen($body) > 5000) {
        flash('Vyplňte prosím stručný název i popis.', 'error');
        redirect('/zavady');
    }
    q('INSERT INTO tickets (user_id, subject, location, body) VALUES (?,?,?,?)', [$u['id'], $subject, mb_substr(post('location'), 0, 150), $body]);
    flash('Děkujeme, výbor Vaši závadu nebo požadavek dostal. Stav řešení uvidíte na této stránce.');
    redirect('/zavady');
});

// ---------- Účast na shromáždění ----------

$route('GET', '/terminy/(\d+)', function (int $id) {
    $u = require_login();
    $ev = one('SELECT * FROM events WHERE id = ?', [$id]) ?? not_found();
    $mine = $ev['rsvp'] && $u['kind'] === 'owner'
        ? all('SELECT un.*, r.status, r.proxy_name, ru.name responder FROM units un JOIN user_units uu ON uu.unit_id = un.id
            LEFT JOIN event_rsvp r ON r.unit_id = un.id AND r.event_id = ? LEFT JOIN users ru ON ru.id = r.user_id
            WHERE uu.user_id = ? ORDER BY un.entrance, un.number', [$id, $u['id']])
        : [];
    render('event', ['title' => $ev['title'], 'ev' => $ev, 'mine' => $mine, 'att' => $ev['rsvp'] ? attendance($id) : null,
        'evDocs' => all('SELECT d.* FROM documents d JOIN event_documents ed ON ed.document_id = d.id WHERE ed.event_id = ? AND ' . doc_scope_sql('d') . ' ORDER BY d.title', [$id]),
        'evPolls' => all('SELECT * FROM polls WHERE event_id = ? ORDER BY id', [$id]),
        'open' => $ev['event_date'] >= date('Y-m-d')]);
});

$route('POST', '/terminy/(\d+)/ucast', function (int $id) {
    $u = require_login();
    $ev = one('SELECT * FROM events WHERE id = ? AND rsvp = 1', [$id]) ?? not_found();
    $unitId = (int) post('unit_id');
    $status = post('status');
    if ($ev['event_date'] < date('Y-m-d')) {
        flash('Termín už proběhl, účast nelze měnit.', 'error');
    } elseif ($u['kind'] !== 'owner' || !in_array($unitId, user_unit_ids((int) $u['id']), true) || !isset(RSVP[$status])) {
        flash('Účast může potvrdit jen vlastník za svou jednotku.', 'error');
    } else {
        $proxy = $status === 'proxy' ? mb_substr(post('proxy_name'), 0, 190) : '';
        q("INSERT INTO event_rsvp (event_id, unit_id, user_id, status, proxy_name) VALUES (?,?,?,?,?)
            ON CONFLICT(event_id, unit_id) DO UPDATE SET user_id = excluded.user_id, status = excluded.status, proxy_name = excluded.proxy_name, updated_at = datetime('now','localtime')", [$id, $unitId, $u['id'], $status, $proxy]);
        flash('Děkujeme, účast je zaznamenaná. Do dne konání ji můžete změnit.');
    }
    redirect('/terminy/' . $id);
});
