<?php
declare(strict_types=1);

$route('GET', '/', function () {
    $v = vis_sql();
    render('home', [
        'title' => null,
        'posts' => all("SELECT * FROM posts WHERE $v AND published_at <= date('now','localtime') ORDER BY pinned DESC, published_at DESC, id DESC LIMIT 4"),
        'event' => one("SELECT * FROM events WHERE event_date >= date('now','localtime') ORDER BY event_date LIMIT 1"),
        'emergency' => all("SELECT * FROM contacts WHERE grp = 'havarie' ORDER BY sort, id"),
        'counts' => array_column(all("SELECT category, COUNT(*) c FROM documents WHERE $v GROUP BY category"), 'c', 'category'),
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
    $where = [vis_sql()];
    $params = [];
    if ($cat) {
        $where[] = 'category = ?';
        $params[] = $cat;
    }
    if ($search !== '') {
        $where[] = "(title LIKE ? ESCAPE '\\' OR original_name LIKE ? ESCAPE '\\')";
        $like = '%' . addcslashes($search, '%_\\') . '%';
        array_push($params, $like, $like);
    }
    $sql = 'SELECT * FROM documents WHERE ' . implode(' AND ', $where) . ' ORDER BY doc_date IS NULL, doc_date DESC, title';
    $groups = [];
    foreach (all($sql, $params) as $d) {
        $groups[$d['category']][] = $d;
    }
    $hidden = user() ? 0 : (int) val("SELECT COUNT(*) FROM documents WHERE visibility = 'residents'");
    render('documents', ['title' => 'Dokumenty', 'groups' => $groups, 'cat' => $cat, 'search' => $search, 'hidden' => $hidden]);
});

$route('GET', '/soubor/(\d+)', function (int $id) {
    $d = one('SELECT * FROM documents WHERE id = ?', [$id]) ?? not_found();
    if ($d['visibility'] === 'residents') {
        require_login();
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
    $ip = client_ip();
    q('DELETE FROM login_attempts WHERE at < ?', [time() - 900]);
    if ((int) val('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [$ip]) >= 8) {
        flash('Příliš mnoho neúspěšných pokusů. Zkuste to prosím znovu za 15 minut.', 'error');
        redirect('/prihlaseni');
    }
    $email = post('email');
    $u = one('SELECT * FROM users WHERE email = ? AND active = 1', [$email]);
    // Hash se ověřuje i pro neexistující účet, aby odezva neprozradila, které e-maily jsou registrované.
    $hash = $u['password_hash'] ?? '$2y$10$5iGzRBULwQkdHVH7LcQeruy9PLnpzCeVdDqlNNyrbTAr6ZReT.NXy';
    if (!password_verify((string) ($_POST['password'] ?? ''), $hash) || !$u) {
        q('INSERT INTO login_attempts (ip, at) VALUES (?,?)', [$ip, time()]);
        http_response_code(422);
        render('login', ['title' => 'Přihlášení', 'email' => $email, 'error' => 'Nesprávný e-mail nebo heslo.']);
        return;
    }
    $after = $_SESSION['after_login'] ?? null;
    session_regenerate_id(true);
    $_SESSION = ['uid' => $u['id']];
    q("UPDATE users SET last_login_at = datetime('now','localtime') WHERE id = ?", [$u['id']]);
    q('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
    redirect(is_string($after) && str_starts_with($after, '/') && !str_starts_with($after, '//') ? $after : ($u['role'] === 'admin' ? '/admin' : '/'));
});

$route('POST', '/odhlaseni', function () {
    $_SESSION = [];
    session_regenerate_id(true);
    flash('Byli jste odhlášeni.');
    redirect('/');
});

$setup = function () {
    if ((int) val("SELECT COUNT(*) FROM users WHERE role = 'admin'") > 0) {
        not_found();
    }
    $data = ['title' => 'První spuštění', 'name' => post('name'), 'email' => post('email'), 'errors' => []];
    if ($GLOBALS['METHOD'] === 'POST') {
        $pass = (string) ($_POST['password'] ?? '');
        if ($data['name'] === '') {
            $data['errors'][] = 'Vyplňte jméno.';
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $data['errors'][] = 'Zadejte platný e-mail.';
        }
        if (mb_strlen($pass) < 10) {
            $data['errors'][] = 'Heslo musí mít alespoň 10 znaků.';
        }
        if (!$data['errors']) {
            q("INSERT INTO users (email, name, password_hash, role) VALUES (?,?,?,'admin')", [$data['email'], $data['name'], password_hash($pass, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION = ['uid' => (int) db()->lastInsertId()];
            flash('Účet správce je vytvořen. Vítejte v administraci.');
            redirect('/admin');
        }
        http_response_code(422);
    }
    render('setup', $data);
};
$route('GET', '/instalace', $setup);
$route('POST', '/instalace', $setup);
