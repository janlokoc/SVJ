<?php
declare(strict_types=1);

// Konzole hlavního správce platformy: zakládá a spravuje weby jednotlivých SVJ. Běží na hlavní doméně platformy.

$GLOBALS['LAYOUT'] = 'platform/layout';
$super = empty($_SESSION['sid']) ? null : (pq('SELECT * FROM super_admins WHERE id = ?', [$_SESSION['sid']])->fetch() ?: null);
if ($super && (!hash_equals(pw_fingerprint($super['password_hash']), (string) ($_SESSION['pv'] ?? '')) || time() - ($_SESSION['seen'] ?? 0) > IDLE_LIMIT)) {
    $super = null;
    $_SESSION = ['t' => '#platform'];
}
if ($super) {
    $_SESSION['seen'] = time();
    header('Cache-Control: no-store, private');
}
$GLOBALS['SUPER'] = $super;
$blocked = function (): bool {
    pq('DELETE FROM attempts WHERE at < ?', [time() - 900]);
    return (int) pq('SELECT COUNT(*) FROM attempts WHERE ip = ?', [client_ip()])->fetchColumn() >= 8;
};
$failed = fn() => pq('INSERT INTO attempts (ip, at) VALUES (?,?)', [client_ip(), time()]);
$signIn = function (array $a): never {
    session_regenerate_id(true);
    $_SESSION = ['t' => '#platform', 'sid' => (int) $a['id'], 'pv' => pw_fingerprint($a['password_hash']), 'seen' => time()];
    pq('UPDATE super_admins SET last_login_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $a['id']]);
    $GLOBALS['SUPER'] = $a;
    platform_log('Přihlášení');
    redirect('/');
};
$tenantInput = function (array $t = []): array {
    $look = isset(LOOKS[post('look')]) ? post('look') : 'les';
    return array_merge($t, ['name' => post('name'), 'title' => post('title'), 'accent' => post('accent'), 'tagline' => post('tagline'), 'full' => post('full'), 'address' => post('address'),
        'unit_prefix' => mb_substr(post('unit_prefix'), 0, 10), 'entrances' => post('entrances') !== '' ? post('entrances') : 'A', 'look' => $look,
        'lang' => isset(LANGS[post('lang')]) ? post('lang') : 'cs',
        'custom_domain' => strtolower(preg_replace('~^https?://|/.*$~', '', post('custom_domain'))), 'note' => post('note'), 'active' => isset($_POST['active']) ? 1 : 0]);
};
$tenantErrors = function (array $t): array {
    $e = [];
    if ($t['name'] === '' || $t['title'] === '') {
        $e[] = 'Vyplňte název společenství i krátký název na web.';
    }
    if ($t['custom_domain'] !== '' && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $t['custom_domain'])) {
        $e[] = 'Vlastní doména nemá správný tvar (např. www.svj-priklad.cz).';
    }
    return $e;
};

// ---------- První spuštění: účet hlavního správce ----------
if ((int) pq('SELECT COUNT(*) FROM super_admins')->fetchColumn() === 0) {
    $code = platform_setup_code();
    $errors = [];
    if ($METHOD === 'POST') {
        $pass = (string) ($_POST['password'] ?? '');
        if ($blocked()) {
            $errors[] = 'Příliš mnoho pokusů. Zkuste to znovu za 15 minut.';
        } elseif (!hash_equals($code, strtoupper(preg_replace('/[^A-Za-z0-9]/', '', post('code'))))) {
            $failed();
            $errors[] = 'Instalační kód není správný.';
        }
        if (post('name') === '' || !filter_var(post('email'), FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Vyplňte jméno a platný e-mail.';
        }
        if ($problem = password_problem($pass, post('email'), post('name'))) {
            $errors[] = $problem;
        }
        if (!$errors) {
            pq('INSERT INTO super_admins (email, name, password_hash, created_at) VALUES (?,?,?,?)', [post('email'), post('name'), password_hash($pass, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
            array_map('unlink', glob(ROOT_STORAGE . '/instalacni-kod-platformy-*.txt'));
            $signIn(pq('SELECT * FROM super_admins WHERE email = ?', [post('email')])->fetch());
        }
        http_response_code(422);
    }
    render('platform/setup', ['title' => 'První spuštění platformy', 'errors' => $errors]);
    exit;
}

// ---------- Přihlášení ----------
if (!$super) {
    $error = null;
    $pending = $_SESSION['spending'] ?? null;
    if ($PATH === '/overeni' && $pending && time() - $pending['at'] < 300) {
        $a = pq('SELECT * FROM super_admins WHERE id = ?', [$pending['id']])->fetch();
        if ($METHOD === 'POST' && $a) {
            $step = $blocked() ? null : totp_verify((string) $a['totp_secret'], post('code'), (int) $a['totp_last']);
            if ($step !== null) {
                pq('UPDATE super_admins SET totp_last = ? WHERE id = ?', [$step, $a['id']]);
                $signIn($a);
            }
            $failed();
            $error = 'Kód není správný nebo už vypršel.';
            http_response_code(422);
        }
        render('platform/login', ['title' => 'Ověření přihlášení', 'error' => $error, 'verify' => true]);
        exit;
    }
    if ($METHOD === 'POST' && $PATH === '/prihlaseni') {
        if ($blocked()) {
            $error = 'Příliš mnoho neúspěšných pokusů. Zkuste to znovu za 15 minut.';
        } else {
            $a = pq('SELECT * FROM super_admins WHERE email = ?', [post('email')])->fetch();
            if (!password_verify((string) ($_POST['password'] ?? ''), $a['password_hash'] ?? '$2y$10$5iGzRBULwQkdHVH7LcQeruy9PLnpzCeVdDqlNNyrbTAr6ZReT.NXy') || !$a) {
                $failed();
                pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), '', client_ip(), 'Neúspěšné přihlášení', post('email')]);
                $error = 'Nesprávný e-mail nebo heslo.';
            } elseif ($a['totp_enabled']) {
                session_regenerate_id(true);
                $_SESSION = ['t' => '#platform', 'spending' => ['id' => (int) $a['id'], 'at' => time()]];
                redirect('/overeni');
            } else {
                $signIn($a);
            }
        }
        http_response_code(422);
    }
    if ($PATH !== '/' && $PATH !== '/prihlaseni') {
        redirect('/');
    }
    render('platform/login', ['title' => 'Správa platformy', 'error' => $error, 'verify' => false]);
    exit;
}

// ---------- Přihlášený hlavní správce ----------
if ($METHOD === 'POST' && $PATH === '/odhlaseni') {
    platform_log('Odhlášení');
    $_SESSION = ['t' => '#platform'];
    session_regenerate_id(true);
    redirect('/');
}
if ($PATH === '/') {
    $tenants = pq('SELECT * FROM tenants ORDER BY active DESC, name')->fetchAll();
    foreach ($tenants as &$t) {
        $t['stats'] = tenant_stats($t['slug']);
    }
    unset($t);
    render('platform/dashboard', ['title' => 'Společenství', 'tenants' => $tenants]);
    exit;
}
if ($PATH === '/svj/nove') {
    $t = ['slug' => '', 'name' => '', 'title' => '', 'accent' => '', 'tagline' => 'Společenství vlastníků jednotek', 'full' => '', 'address' => '', 'unit_prefix' => '', 'entrances' => 'A',
        'look' => 'les', 'lang' => 'cs', 'custom_domain' => '', 'note' => '', 'active' => 1, 'seed' => ''];
    $errors = [];
    if ($METHOD === 'POST') {
        $t = $tenantInput($t);
        $t['slug'] = strtolower(post('slug'));
        $t['seed'] = isset($_POST['seed_tabor']) ? 'tabor' : '';
        $errors = $tenantErrors($t);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,38}$/', $t['slug']) || in_array($t['slug'], RESERVED_SLUGS, true)) {
            $errors[] = 'Adresa smí obsahovat jen malá písmena bez diakritiky, číslice a pomlčku (2–39 znaků) a nesmí být vyhrazená.';
        } elseif (tenant_row($t['slug'])) {
            $errors[] = 'Tato adresa už je obsazená.';
        }
        if (!$errors) {
            if ($t['full'] === '') {
                $t['full'] = $t['name'];
            }
            pq('INSERT INTO tenants (slug, name, title, accent, tagline, "full", address, unit_prefix, entrances, look, lang, custom_domain, seed, note, active, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$t['slug'], $t['name'], $t['title'], $t['accent'], $t['tagline'], $t['full'], $t['address'], $t['unit_prefix'], $t['entrances'], $t['look'], $t['lang'], $t['custom_domain'], $t['seed'], $t['note'], $t['active'], date('Y-m-d H:i:s')]);
            tenant_pdo($t['slug']);   // založí databázi a naplní ji výchozím obsahem
            platform_log('Založení společenství', $t['slug'] . ' · ' . $t['name']);
            flash('Web společenství je založen. Předejte jeho výboru adresu a instalační kód, nebo mu rovnou vytvořte účet správce.');
            redirect('/svj/' . $t['slug']);
        }
        http_response_code(422);
    }
    render('platform/tenant_form', ['title' => 'Nové společenství', 't' => $t, 'errors' => $errors, 'new' => true]);
    exit;
}
if (preg_match('~^/svj/([a-z0-9][a-z0-9-]{1,38})(/spravce|/smazat)?$~', $PATH, $m) && ($t = tenant_row($m[1]))) {
    $slug = $t['slug'];
    $action = $m[2] ?? '';
    if ($METHOD === 'POST' && $action === '/smazat') {
        if (post('confirm') !== $slug) {
            flash('Pro smazání opište přesně adresu společenství.', 'error');
            redirect('/svj/' . $slug);
        }
        tenant_delete_storage($slug);
        pq('DELETE FROM tenants WHERE slug = ?', [$slug]);
        platform_log('Smazání společenství', $slug . ' · ' . $t['name']);
        flash('Společenství „' . $t['name'] . '“ bylo smazáno včetně všech dat.');
        redirect('/');
    }
    if ($METHOD === 'POST' && $action === '/spravce') {
        $pdo = tenant_pdo($slug);
        $email = post('email');
        $exists = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $exists->execute([$email]);
        if (post('name') === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $exists->fetchColumn()) {
            flash('Vyplňte jméno a platný e-mail, který na webu společenství ještě není.', 'error');
            redirect('/svj/' . $slug);
        }
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO users (name, email, role, kind, password_hash, invite_hash, invite_expires) VALUES (?,?,'admin','owner',?,?,?)")
            ->execute([post('name'), $email, NO_PASSWORD, hash('sha256', $token), date('Y-m-d H:i:s', time() + 14 * 86400)]);
        platform_log('Vytvoření správce společenství', $slug . ' · ' . $email);
        $_SESSION['tenant_invite'] = ['name' => post('name'), 'link' => tenant_url($t, '/pozvanka/' . $token)];
        redirect('/svj/' . $slug);
    }
    $errors = [];
    $settings = tenant_settings($slug);
    if ($METHOD === 'POST' && $action === '') {
        $new = $tenantInput($t);
        $errors = $tenantErrors($new);
        if (!$errors) {
            pq('UPDATE tenants SET name = ?, title = ?, accent = ?, tagline = ?, "full" = ?, address = ?, unit_prefix = ?, entrances = ?, look = ?, lang = ?, custom_domain = ?, note = ?, active = ? WHERE slug = ?',
                [$new['name'], $new['title'], $new['accent'], $new['tagline'], $new['full'], $new['address'], $new['unit_prefix'], $new['entrances'], $new['look'], $new['lang'], $new['custom_domain'], $new['note'], $new['active'], $slug]);
            // Název a vzhled se zapisují přímo do nastavení webu společenství (jeho správce je může dál měnit).
            foreach (['name', 'title', 'accent', 'tagline', 'full', 'unit_prefix', 'entrances', 'look', 'lang'] as $k) {
                tenant_set($slug, 'site_' . $k, (string) $new[$k]);
            }
            tenant_set($slug, 'address', $new['address']);
            platform_log('Úprava společenství', $slug . ($new['active'] ? '' : ' · pozastaveno'));
            flash('Uloženo.');
            redirect('/svj/' . $slug);
        }
        $t = $new;
        http_response_code(422);
    } else {
        // Zobrazují se aktuální hodnoty z webu společenství (správce je mohl změnit).
        foreach (['name', 'title', 'accent', 'tagline', 'full', 'unit_prefix', 'entrances', 'look', 'lang'] as $k) {
            $t[$k] = $settings['site_' . $k] ?? $t[$k];
        }
        $t['address'] = $settings['address'] ?? $t['address'];
    }
    $stats = tenant_stats($slug);
    $invite = $_SESSION['tenant_invite'] ?? null;
    unset($_SESSION['tenant_invite']);
    render('platform/tenant_form', ['title' => $t['name'], 't' => $t, 'errors' => $errors, 'new' => false, 'stats' => $stats,
        'setupCode' => $stats['ready'] && !$stats['admins'] ? setup_code(tenant_storage($slug)) : null, 'invite' => $invite]);
    exit;
}
if ($PATH === '/zaznamy') {
    render('platform/log', ['title' => 'Záznamy platformy', 'rows' => pq('SELECT * FROM platform_log ORDER BY id DESC LIMIT 500')->fetchAll()]);
    exit;
}
if ($PATH === '/ucet') {
    $errors = [];
    if ($METHOD === 'POST') {
        $action = post('action');
        if ($action === 'password') {
            $new = (string) ($_POST['new_password'] ?? '');
            if (!password_verify((string) ($_POST['current_password'] ?? ''), $super['password_hash'])) {
                $errors[] = 'Současné heslo není správně.';
            } elseif ($problem = password_problem($new, $super['email'], $super['name'])) {
                $errors[] = $problem;
            } elseif ($new !== (string) ($_POST['new_password2'] ?? '')) {
                $errors[] = 'Nová hesla se neshodují.';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                pq('UPDATE super_admins SET password_hash = ? WHERE id = ?', [$hash, $super['id']]);
                $_SESSION['pv'] = pw_fingerprint($hash);
                platform_log('Změna hesla');
                flash('Heslo bylo změněno.');
                redirect('/ucet');
            }
        } elseif ($action === 'totp_start' && !$super['totp_enabled']) {
            $_SESSION['totp_setup'] = totp_new_secret();
            redirect('/ucet');
        } elseif ($action === 'totp_confirm' && !$super['totp_enabled'] && !empty($_SESSION['totp_setup'])) {
            $step = totp_verify($_SESSION['totp_setup'], post('code'));
            if ($step === null) {
                $errors[] = 'Kód nesouhlasí. Zadejte aktuální kód z aplikace.';
            } else {
                pq('UPDATE super_admins SET totp_secret = ?, totp_enabled = 1, totp_last = ? WHERE id = ?', [$_SESSION['totp_setup'], $step, $super['id']]);
                unset($_SESSION['totp_setup']);
                platform_log('Zapnutí dvoufázového ověření');
                flash('Dvoufázové ověření je zapnuté.');
                redirect('/ucet');
            }
        } elseif ($action === 'totp_disable' && $super['totp_enabled'] && password_verify((string) ($_POST['current_password'] ?? ''), $super['password_hash'])) {
            pq('UPDATE super_admins SET totp_secret = NULL, totp_enabled = 0, totp_last = 0 WHERE id = ?', [$super['id']]);
            platform_log('Vypnutí dvoufázového ověření');
            flash('Dvoufázové ověření bylo vypnuto.', 'info');
            redirect('/ucet');
        } elseif ($action === 'add_admin') {
            $pass = random_password();
            if (post('name') === '' || !filter_var(post('email'), FILTER_VALIDATE_EMAIL) || pq('SELECT 1 FROM super_admins WHERE email = ?', [post('email')])->fetchColumn()) {
                $errors[] = 'Vyplňte jméno a platný e-mail, který ještě mezi hlavními správci není.';
            } else {
                pq('INSERT INTO super_admins (email, name, password_hash, created_at) VALUES (?,?,?,?)', [post('email'), post('name'), password_hash($pass, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
                platform_log('Přidání hlavního správce', post('email'));
                $_SESSION['new_super'] = ['email' => post('email'), 'password' => $pass];
                redirect('/ucet');
            }
        }
        if ($errors) {
            http_response_code(422);
        }
    }
    $newSuper = $_SESSION['new_super'] ?? null;
    unset($_SESSION['new_super']);
    render('platform/account', ['title' => 'Můj účet', 'errors' => $errors, 'super' => $super, 'totpSetup' => $super['totp_enabled'] ? null : ($_SESSION['totp_setup'] ?? null),
        'admins' => pq('SELECT * FROM super_admins ORDER BY name')->fetchAll(), 'newSuper' => $newSuper]);
    exit;
}
http_response_code(404);
render('platform/message', ['title' => 'Stránka nenalezena', 'message' => 'Tato stránka v konzoli platformy neexistuje.']);
