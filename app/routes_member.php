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
    return ['title' => 'Můj účet', 'u' => $u, 'errors' => $errors, 'units' => $units, 'inviteLink' => $link];
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
        if (mb_strlen($new) < 10) {
            $errors[] = 'Nové heslo musí mít alespoň 10 znaků.';
        }
        if ($new !== (string) ($_POST['new_password2'] ?? '')) {
            $errors[] = 'Nová hesla se neshodují.';
        }
        if (!$errors) {
            q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            flash('Heslo bylo změněno.');
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
    if ($u['kind'] !== 'owner' || !$mine) {
        $errors[] = 'Pozvat dalšího člena domácnosti může jen vlastník s přiřazenou jednotkou. Obraťte se prosím na výbor.';
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
        foreach ($chosen as $unitId) {
            if ((int) val('SELECT COUNT(*) FROM user_units WHERE unit_id = ?', [$unitId]) >= 6) {
                $errors[] = 'K jedné jednotce lze přiřadit nejvýše 6 účtů. Další účet může přidat výbor.';
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
    q("INSERT INTO users (name, email, role, kind, password_hash, invited_by, invite_hash, invite_expires) VALUES (?,?,'resident','owner',?,?,?,datetime('now','localtime','+14 days'))",
        [$name, $email, NO_PASSWORD, $u['id'], hash('sha256', $token)]);
    $newId = (int) db()->lastInsertId();
    foreach ($chosen as $unitId) {
        q('INSERT INTO user_units (user_id, unit_id) VALUES (?,?)', [$newId, $unitId]);
    }
    $link = absolute_url('/pozvanka/' . $token);
    $text = "Dobrý den,\n\n" . $u['name'] . " Vás zve na web SVJ Tábor 48 a–d jako dalšího člena domácnosti.\n\nÚčet si aktivujete nastavením hesla na této adrese (platí 14 dní):\n$link\n\nPokud pozvánku nečekáte, zprávu prosím ignorujte.\n";
    if (send_mail($email, 'Pozvánka na web SVJ Tábor 48', $text)) {
        flash('Pozvánka byla odeslána na ' . $email . '. Platí 14 dní.');
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
    q('DELETE FROM forum_posts WHERE id = ?', [$id]);
    flash('Příspěvek byl smazán.');
    redirect($p['parent_id'] ? '/diskuze/' . $p['parent_id'] : '/diskuze');
});

// ---------- Hlášení závad ----------

$route('GET', '/zavady', function () {
    $u = require_login();
    render('tickets', ['title' => 'Hlášení závad', 'tickets' => all('SELECT * FROM tickets WHERE user_id = ? ORDER BY id DESC', [$u['id']])]);
});

$route('POST', '/zavady', function () {
    $u = require_login();
    $subject = post('subject');
    $body = post('body');
    if ($subject === '' || $body === '' || mb_strlen($subject) > 150 || mb_strlen($body) > 5000) {
        flash('Vyplňte prosím stručný název i popis závady.', 'error');
        redirect('/zavady');
    }
    q('INSERT INTO tickets (user_id, subject, location, body) VALUES (?,?,?,?)', [$u['id'], $subject, mb_substr(post('location'), 0, 150), $body]);
    flash('Děkujeme, závada byla nahlášena výboru. Stav řešení uvidíte na této stránce.');
    redirect('/zavady');
});
