<?php
declare(strict_types=1);

$route('GET', '/', function () {
    $v = vis_sql();
    render('home', [
        'title' => null,
        'posts' => all("SELECT * FROM posts WHERE $v AND published_at <= date('now','localtime') ORDER BY pinned DESC, published_at DESC, id DESC LIMIT 4"),
        'event' => one("SELECT * FROM events WHERE event_date >= date('now','localtime') ORDER BY event_date LIMIT 1"),
        'emergency' => all("SELECT * FROM contacts WHERE grp = 'havarie' ORDER BY sort, id"),
        'counts' => array_column(all('SELECT category, COUNT(*) c FROM documents WHERE ' . doc_scope_sql() . ' GROUP BY category'), 'c', 'category'),
    ]);
});

$route('GET', '/aktuality', function () {
    $v = vis_sql();
    $posts = all("SELECT * FROM posts WHERE $v AND published_at <= date('now','localtime') ORDER BY pinned DESC, published_at DESC, id DESC");
    render('posts', ['title' => 'Aktuality', 'posts' => $posts]);
});

$route('GET', '/aktuality/(\d+)', function (int $id) {
    $post = one('SELECT * FROM posts WHERE id = ?', [$id]) ?? not_found();
    if (!is_admin() && $post['published_at'] > date('Y-m-d')) {
        not_found();
    }
    if ($post['visibility'] === 'residents') {
        require_login();
    }
    render('post', ['title' => $post['title'], 'post' => $post, 'docs' => post_documents($id)]);
});

$route('GET', '/dokumenty', function () {
    $cat = (string) ($_GET['kategorie'] ?? '');
    if (!isset(CATEGORIES[$cat])) {
        $cat = '';
    }
    $search = trim((string) ($_GET['q'] ?? ''));
    $where = [doc_scope_sql()];
    $params = [];
    if ($cat) {
        $where[] = 'category = ?';
        $params[] = $cat;
    }
    if ($search !== '') {
        $where[] = "(title LIKE ? ESCAPE '!' OR original_name LIKE ? ESCAPE '!')";
        $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        array_push($params, $like, $like);
    }
    $sql = 'SELECT * FROM documents WHERE ' . implode(' AND ', $where) . ' ORDER BY doc_date IS NULL, doc_date DESC, title';
    $groups = [];
    foreach (all($sql, $params) as $d) {
        $groups[$d['category']][] = $d;
    }
    $hidden = user() ? 0 : (int) val("SELECT COUNT(*) FROM documents WHERE visibility = 'residents' AND unit_id IS NULL");
    render('documents', ['title' => 'Dokumenty', 'groups' => $groups, 'cat' => $cat, 'search' => $search, 'hidden' => $hidden]);
});

$route('GET', '/soubor/(\d+)', function (int $id) {
    $d = one('SELECT * FROM documents WHERE id = ?', [$id]) ?? not_found();
    if ($d['visibility'] === 'residents' || $d['unit_id']) {
        require_login();
    }
    if (!can_open_document($d)) {
        not_found();   // dokument jiné jednotky
    }
    if (!$d['filename']) {
        if ($d['external_url'] && str_starts_with($d['external_url'], 'https://')) {
            header('Location: ' . $d['external_url'], true, 302);
            exit;
        }
        not_found();
    }
    $file = UPLOAD_DIR . '/' . basename($d['filename']);
    if (!is_file($file)) {
        not_found();
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $inline = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    $name = $d['original_name'] ?: $d['title'] . '.' . $ext;
    header_remove('Content-Security-Policy');
    header('Content-Type: ' . ($inline[$ext] ?? 'application/octet-stream'));
    header('Content-Disposition: ' . (isset($inline[$ext]) ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: private, max-age=3600');
    readfile($file);
});

$route('GET', '/kontakty', function () {
    $groups = [];
    foreach (all('SELECT * FROM contacts ORDER BY sort, id') as $c) {
        $groups[$c['grp']][] = $c;
    }
    render('contacts', ['title' => 'Kontakty', 'groups' => $groups]);
});

$route('GET', '/prihlaseni', function () {
    if (user()) {
        redirect('/');
    }
    render('login', ['title' => 'Přihlášení', 'email' => '']);
});

$route('POST', '/prihlaseni', function () {
    $email = post('email');
    if (login_blocked($email)) {
        flash('Příliš mnoho neúspěšných pokusů. Zkuste to prosím znovu za 15 minut.', 'error');
        redirect('/prihlaseni');
    }
    $u = one('SELECT * FROM users WHERE email = ? AND active = 1', [$email]);
    // Hash se ověřuje i pro neexistující účet, aby odezva neprozradila, které e-maily jsou registrované.
    $hash = $u['password_hash'] ?? '$2y$10$5iGzRBULwQkdHVH7LcQeruy9PLnpzCeVdDqlNNyrbTAr6ZReT.NXy';
    if (!password_verify((string) ($_POST['password'] ?? ''), $hash) || !$u) {
        login_failed($email);
        http_response_code(422);
        render('login', ['title' => 'Přihlášení', 'email' => $email, 'error' => 'Nesprávný e-mail nebo heslo.']);
        return;
    }
    $after = $_SESSION['after_login'] ?? null;
    if ($u['totp_enabled']) {
        session_regenerate_id(true);
        $_SESSION = ['t' => TENANT, 'pending' => ['uid' => (int) $u['id'], 'at' => time(), 'after' => $after]];
        redirect('/prihlaseni/overeni');
    }
    finish_login($u, $after);
});

$verify = function () {
    $pending = $_SESSION['pending'] ?? null;
    $u = $pending && time() - $pending['at'] < 300 ? one('SELECT * FROM users WHERE id = ? AND active = 1 AND totp_enabled = 1', [$pending['uid']]) : null;
    if (!$u) {
        unset($_SESSION['pending']);
        redirect('/prihlaseni');
    }
    $error = null;
    if ($GLOBALS['METHOD'] === 'POST') {
        if (login_blocked($u['email'])) {
            unset($_SESSION['pending']);
            flash('Příliš mnoho neúspěšných pokusů. Zkuste to prosím znovu za 15 minut.', 'error');
            redirect('/prihlaseni');
        }
        $step = totp_verify((string) $u['totp_secret'], post('code'), (int) $u['totp_last']);
        if ($step !== null) {
            q('UPDATE users SET totp_last = ? WHERE id = ?', [$step, $u['id']]);
            finish_login($u, $pending['after']);
        }
        login_failed($u['email'], 'login_2fa_failed');
        $error = 'Kód není správný nebo už vypršel. Zadejte aktuální kód z aplikace.';
        http_response_code(422);
    }
    render('verify', ['title' => 'Ověření přihlášení', 'error' => $error]);
};
$route('GET', '/prihlaseni/overeni', $verify);
$route('POST', '/prihlaseni/overeni', $verify);

$route('POST', '/odhlaseni', function () {
    audit('logout');
    $_SESSION = [];
    session_regenerate_id(true);
    flash('Byli jste odhlášeni.');
    redirect('/');
});

$setup = function () {
    if ((int) val("SELECT COUNT(*) FROM users WHERE role = 'admin'") > 0) {
        not_found();
    }
    $code = setup_code();
    $data = ['title' => 'První spuštění', 'name' => post('name'), 'email' => post('email'), 'errors' => []];
    if ($GLOBALS['METHOD'] === 'POST') {
        $pass = (string) ($_POST['password'] ?? '');
        if (login_blocked('')) {
            $data['errors'][] = 'Příliš mnoho pokusů. Zkuste to znovu za 15 minut.';
        } elseif (!hash_equals($code, strtoupper(preg_replace('/[^A-Za-z0-9]/', '', post('code'))))) {
            login_failed('', 'setup_code_failed');
            $data['errors'][] = 'Instalační kód není správný.';
        }
        if ($data['name'] === '') {
            $data['errors'][] = 'Vyplňte jméno.';
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $data['errors'][] = 'Zadejte platný e-mail.';
        }
        if ($problem = password_problem($pass, $data['email'], $data['name'])) {
            $data['errors'][] = $problem;
        }
        if (!$data['errors']) {
            q("INSERT INTO users (email, name, password_hash, role) VALUES (?,?,?,'admin')", [$data['email'], $data['name'], password_hash($pass, PASSWORD_DEFAULT)]);
            $new = one('SELECT * FROM users WHERE id = ?', [(int) db()->lastInsertId()]);
            q("INSERT INTO settings (`key`, value) VALUES ('site_url', ?) ON CONFLICT(`key`) DO UPDATE SET value = excluded.value", [rtrim(absolute_url('/'), '/')]);
            array_map('unlink', glob(STORAGE_DIR . '/instalacni-kod-*.txt'));
            login_user($new);
            audit('setup', $new['email']);
            flash('Účet správce je vytvořen. Vítejte v administraci.');
            redirect('/admin');
        }
        http_response_code(422);
    }
    render('setup', $data);
};
$route('GET', '/instalace', $setup);
$route('POST', '/instalace', $setup);

// ---------- Přijetí pozvánky ----------

$acceptInvite = function (string $token) {
    $inv = one("SELECT * FROM users WHERE invite_hash = ? AND password_hash = ? AND active = 1 AND invite_expires >= datetime('now','localtime')", [hash('sha256', $token), NO_PASSWORD]);
    if (!$inv) {
        http_response_code(404);
        render('error', ['title' => 'Pozvánka neplatí', 'message' => 'Odkaz už byl použit, vypršel, nebo byla pozvánka zrušena. Požádejte o novou toho, kdo Vás pozval, nebo výbor SVJ.']);
        return;
    }
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $pass = (string) ($_POST['password'] ?? '');
        if ($problem = password_problem($pass, $inv['email'], $inv['name'])) {
            $errors[] = $problem;
        }
        if ($pass !== (string) ($_POST['password2'] ?? '')) {
            $errors[] = 'Hesla se neshodují.';
        }
        if (!$errors) {
            q("UPDATE users SET password_hash = ?, invite_hash = NULL, invite_expires = NULL, last_login_at = datetime('now','localtime') WHERE id = ?", [password_hash($pass, PASSWORD_DEFAULT), $inv['id']]);
            login_user(one('SELECT * FROM users WHERE id = ?', [$inv['id']]));
            audit('invite_accepted', $inv['email']);
            flash('Vítejte! Účet je aktivní.');
            redirect('/');
        }
        http_response_code(422);
    }
    render('invite', ['title' => 'Pozvánka', 'inv' => $inv, 'errors' => $errors, 'token' => $token,
        'unitNames' => array_map('unit_name', all('SELECT un.* FROM units un JOIN user_units uu ON uu.unit_id = un.id WHERE uu.user_id = ?', [$inv['id']]))]);
};
$route('GET', '/pozvanka/([a-f0-9]{64})', $acceptInvite);
$route('POST', '/pozvanka/([a-f0-9]{64})', $acceptInvite);

// ---------- Sledování e-mailů ----------

$route('GET', '/e/([a-f0-9]{32})\.gif', function ($token) {
    q("UPDATE mailing_recipients SET opened_at = COALESCE(opened_at, datetime('now','localtime')) WHERE token = ?", [(string) $token]);
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, private');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
});

$route('GET', '/e/([a-f0-9]{32})', function ($token) {
    q("UPDATE mailing_recipients SET clicked_at = COALESCE(clicked_at, datetime('now','localtime')), opened_at = COALESCE(opened_at, datetime('now','localtime')) WHERE token = ?", [(string) $token]);
    // Přesměrování jen na stránky tohoto webu, nikdy na cizí adresu.
    $to = $_GET['u'] ?? '/';
    redirect(is_string($to) && preg_match('~^/(?![/\\\\])[^\s\\\\]*$~', $to) ? $to : '/');
});

// ---------- Zapomenuté heslo ----------

$forgot = function () {
    if (user()) {
        redirect('/muj-ucet');
    }
    $mailReady = (bool) filter_var(setting('mail_from'), FILTER_VALIDATE_EMAIL);
    $done = false;
    if ($GLOBALS['METHOD'] === 'POST' && $mailReady) {
        // Nejvýše 5 žádostí za 15 minut z jedné adresy; odpověď je vždy stejná, aby nešlo zjišťovat, kdo má na webu účet.
        q('DELETE FROM login_attempts WHERE at < ?', [time() - 900]);
        if ((int) val("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND email = '#reset'", [client_ip()]) >= 5) {
            flash('Příliš mnoho žádostí. Zkuste to prosím znovu za 15 minut.', 'error');
            redirect('/zapomenute-heslo');
        }
        q("INSERT INTO login_attempts (ip, at, email) VALUES (?,?,'#reset')", [client_ip(), time()]);
        $u = one('SELECT * FROM users WHERE email = ? AND active = 1', [post('email')]);
        if ($u) {
            send_password_reset($u);
            audit('password_reset_requested', $u['email'], (int) $u['id']);
        }
        $done = true;
    }
    render('forgot', ['title' => 'Zapomenuté heslo', 'mailReady' => $mailReady, 'done' => $done]);
};
$route('GET', '/zapomenute-heslo', $forgot);
$route('POST', '/zapomenute-heslo', $forgot);

$resetPassword = function (string $token) {
    $u = one("SELECT * FROM users WHERE invite_hash = ? AND password_hash <> ? AND active = 1 AND invite_expires >= datetime('now','localtime')", [hash('sha256', $token), NO_PASSWORD]);
    if (!$u) {
        http_response_code(404);
        render('error', ['title' => 'Odkaz neplatí', 'message' => 'Odkaz na nové heslo už byl použit nebo vypršel (platí jednu hodinu). Požádejte o nový na stránce Zapomenuté heslo.']);
        return;
    }
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $pass = (string) ($_POST['password'] ?? '');
        if ($problem = password_problem($pass, $u['email'], $u['name'])) {
            $errors[] = $problem;
        }
        if ($pass !== (string) ($_POST['password2'] ?? '')) {
            $errors[] = 'Hesla se neshodují.';
        }
        if (!$errors) {
            // Nové heslo zruší všechna dosavadní přihlášení. Dvoufázové ověření zůstává zapnuté.
            q('UPDATE users SET password_hash = ?, must_change_password = 0, invite_hash = NULL, invite_expires = NULL WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            q('DELETE FROM login_attempts WHERE email = ?', [mb_strtolower($u['email'])]);
            audit('password_reset_done', $u['email'], (int) $u['id']);
            send_mail($u['email'], 'Heslo na webu ' . site('name') . ' bylo změněno', "Dobrý den,\n\nheslo k Vašemu účtu na webu " . site('name') . " bylo právě změněno přes odkaz ze zapomenutého hesla.\n\nPokud jste to nebyli Vy, obraťte se hned na výbor SVJ.\n");
            flash('Heslo je změněno. Přihlaste se novým heslem.');
            redirect('/prihlaseni');
        }
        http_response_code(422);
    }
    render('reset', ['title' => 'Nové heslo', 'u' => $u, 'errors' => $errors, 'token' => $token]);
};
$route('GET', '/obnova-hesla/([a-f0-9]{64})', $resetPassword);
$route('POST', '/obnova-hesla/([a-f0-9]{64})', $resetPassword);

// ---------- Logo společenství ----------

$route('GET', '/logo', function () {
    $file = setting('logo');
    if (!preg_match('/^[a-f0-9]{32}\.(png|jpe?g)$/', $file) || !is_file(UPLOAD_DIR . '/' . $file)) {
        not_found();
    }
    header_remove('Content-Security-Policy');
    header('Content-Type: ' . (str_ends_with($file, 'png') ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: public, max-age=86400');
    readfile(UPLOAD_DIR . '/' . $file);
});
