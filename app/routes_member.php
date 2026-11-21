<?php
declare(strict_types=1);

// ---------- Můj účet ----------

$route('GET', '/muj-ucet', function () {
    render('account', ['title' => 'Můj účet', 'u' => require_login(), 'errors' => []]);
});

$route('POST', '/muj-ucet', function () {
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
            q('UPDATE users SET name = ?, phone = ? WHERE id = ?', [$name, post('phone'), $u['id']]);
            flash('Údaje byly uloženy.');
            redirect('/muj-ucet');
        }
    }
    http_response_code(422);
    render('account', ['title' => 'Můj účet', 'u' => $u, 'errors' => $errors]);
});

// ---------- Diskuze ----------

$route('GET', '/diskuze', function () {
    require_login();
    $threads = all('SELECT f.*, u.name author_name, u.entrance author_entrance,
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
    $sql = 'SELECT f.*, u.name author_name, u.entrance author_entrance, u.role author_role FROM forum_posts f LEFT JOIN users u ON u.id = f.author_id ';
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
