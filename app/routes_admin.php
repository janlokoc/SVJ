<?php
declare(strict_types=1);

$route('GET', '/admin', function () {
    require_admin();
    render('admin/dashboard', [
        'title' => 'Administrace',
        'stats' => [
            'posts' => (int) val('SELECT COUNT(*) FROM posts'),
            'documents' => (int) val('SELECT COUNT(*) FROM documents'),
            'users' => (int) val('SELECT COUNT(*) FROM users WHERE active = 1'),
            'tickets' => (int) val("SELECT COUNT(*) FROM tickets WHERE status <> 'done'"),
        ],
        'tickets' => all("SELECT t.*, u.name author_name, u.entrance author_entrance FROM tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.status <> 'done' ORDER BY t.id DESC LIMIT 5"),
        'external' => (int) val('SELECT COUNT(*) FROM documents WHERE filename IS NULL AND external_url IS NOT NULL'),
    ]);
});

$route('POST', '/admin/import', function () {
    require_admin();
    @set_time_limit(120);
    [$done, $failed, $left] = import_external_files(15);
    flash("Staženo souborů: $done" . ($failed ? ", nepodařilo se: $failed" : '') . ($left ? ". Zbývá $left – pokračujte dalším kliknutím." : '. Všechny soubory jsou nyní uložené na novém webu.'), $failed && !$done ? 'error' : 'ok');
    redirect('/admin');
});

// ---------- Aktuality ----------

$route('GET', '/admin/aktuality', function () {
    require_admin();
    render('admin/posts', ['title' => 'Aktuality', 'posts' => all('SELECT p.*, (SELECT COUNT(*) FROM post_documents pd WHERE pd.post_id = p.id) docs FROM posts p ORDER BY published_at DESC, id DESC')]);
});

$postForm = function (int $id = 0) {
    $admin = require_admin();
    $post = $id ? (one('SELECT * FROM posts WHERE id = ?', [$id]) ?? not_found())
        : ['id' => 0, 'title' => '', 'body' => '', 'visibility' => 'public', 'pinned' => 0, 'published_at' => date('Y-m-d')];
    $selected = $id ? array_map('intval', array_column(all('SELECT document_id FROM post_documents WHERE post_id = ?', [$id]), 'document_id')) : [];
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $post = array_merge($post, [
            'title' => post('title'),
            'body' => post('body'),
            'visibility' => isset(VISIBILITY[post('visibility')]) ? post('visibility') : 'public',
            'pinned' => isset($_POST['pinned']) ? 1 : 0,
            'published_at' => post('published_at'),
        ]);
        $selected = array_map('intval', (array) ($_POST['documents'] ?? []));
        if ($post['title'] === '') {
            $errors[] = 'Vyplňte nadpis.';
        }
        if (!valid_date($post['published_at'])) {
            $errors[] = 'Zadejte platné datum zveřejnění.';
        }
        $new = [];
        if (!$errors) {
            foreach (uploaded_list('files') as $f) {
                $r = store_upload($f);
                if (is_string($r)) {
                    $errors[] = $f['name'] . ': ' . $r;
                } else {
                    $new[] = $r;
                }
            }
        }
        if (!$errors) {
            $args = [$post['title'], $post['body'], $post['visibility'], $post['pinned'], $post['published_at']];
            if ($id) {
                q('UPDATE posts SET title = ?, body = ?, visibility = ?, pinned = ?, published_at = ? WHERE id = ?', [...$args, $id]);
            } else {
                q('INSERT INTO posts (title, body, visibility, pinned, published_at, author_id) VALUES (?,?,?,?,?,?)', [...$args, $admin['id']]);
                $id = (int) db()->lastInsertId();
            }
            foreach ($new as [$filename, $orig, $size]) {
                q('INSERT INTO documents (title, category, visibility, filename, original_name, size, doc_date) VALUES (?,?,?,?,?,?,?)',
                    [pathinfo($orig, PATHINFO_FILENAME), 'oznameni', $post['visibility'], $filename, $orig, $size, $post['published_at']]);
                $selected[] = (int) db()->lastInsertId();
            }
            q('DELETE FROM post_documents WHERE post_id = ?', [$id]);
            foreach (array_unique($selected) as $docId) {
                if (val('SELECT 1 FROM documents WHERE id = ?', [$docId])) {
                    q('INSERT INTO post_documents (post_id, document_id) VALUES (?,?)', [$id, $docId]);
                }
            }
            flash('Aktualita byla uložena.');
            redirect('/admin/aktuality');
        }
        foreach ($new as [$filename]) {
            @unlink(UPLOAD_DIR . '/' . $filename);
        }
        http_response_code(422);
    }
    render('admin/post_form', [
        'title' => $post['id'] ? 'Upravit aktualitu' : 'Nová aktualita',
        'post' => $post, 'selected' => $selected, 'errors' => $errors,
        'documents' => all('SELECT id, title, category, doc_date FROM documents ORDER BY created_at DESC, id DESC'),
    ]);
};
$route('GET', '/admin/aktuality/nova', $postForm);
$route('POST', '/admin/aktuality/nova', $postForm);
$route('GET', '/admin/aktuality/(\d+)', $postForm);
$route('POST', '/admin/aktuality/(\d+)', $postForm);
$route('POST', '/admin/aktuality/(\d+)/smazat', function (int $id) {
    require_admin();
    q('DELETE FROM posts WHERE id = ?', [$id]);
    flash('Aktualita byla smazána. Přiložené dokumenty zůstaly v sekci Dokumenty.');
    redirect('/admin/aktuality');
});

// ---------- Dokumenty ----------

$route('GET', '/admin/dokumenty', function () {
    require_admin();
    $cat = (string) ($_GET['kategorie'] ?? '');
    $cat = isset(CATEGORIES[$cat]) ? $cat : '';
    $docs = $cat ? all('SELECT * FROM documents WHERE category = ? ORDER BY doc_date IS NULL, doc_date DESC, title', [$cat])
        : all('SELECT * FROM documents ORDER BY created_at DESC, id DESC');
    render('admin/documents', ['title' => 'Dokumenty', 'docs' => $docs, 'cat' => $cat]);
});

$docForm = function (int $id = 0) {
    require_admin();
    $doc = $id ? (one('SELECT * FROM documents WHERE id = ?', [$id]) ?? not_found())
        : ['id' => 0, 'title' => '', 'category' => 'oznameni', 'visibility' => 'public', 'doc_date' => date('Y-m-d'), 'filename' => null, 'original_name' => '', 'external_url' => null];
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $doc = array_merge($doc, [
            'title' => post('title'),
            'category' => isset(CATEGORIES[post('category')]) ? post('category') : 'oznameni',
            'visibility' => isset(VISIBILITY[post('visibility')]) ? post('visibility') : 'public',
            'doc_date' => post('doc_date') ?: null,
        ]);
        if ($doc['doc_date'] !== null && !valid_date($doc['doc_date'])) {
            $errors[] = 'Zadejte platné datum, nebo pole nechte prázdné.';
        }
        $hasFile = ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$id && !$hasFile) {
            $errors[] = 'Vyberte soubor k nahrání.';
        }
        $up = null;
        if (!$errors && $hasFile) {
            $up = store_upload($_FILES['file']);
            if (is_string($up)) {
                $errors[] = $up;
            }
        }
        if (!$errors) {
            if ($doc['title'] === '') {
                $doc['title'] = $up ? pathinfo($up[1], PATHINFO_FILENAME) : ($doc['original_name'] ?: 'Dokument');
            }
            if ($id) {
                q('UPDATE documents SET title = ?, category = ?, visibility = ?, doc_date = ? WHERE id = ?', [$doc['title'], $doc['category'], $doc['visibility'], $doc['doc_date'], $id]);
                if ($up) {
                    if ($doc['filename']) {
                        @unlink(UPLOAD_DIR . '/' . basename($doc['filename']));
                    }
                    q('UPDATE documents SET filename = ?, original_name = ?, size = ?, external_url = NULL WHERE id = ?', [$up[0], $up[1], $up[2], $id]);
                }
            } else {
                q('INSERT INTO documents (title, category, visibility, doc_date, filename, original_name, size) VALUES (?,?,?,?,?,?,?)',
                    [$doc['title'], $doc['category'], $doc['visibility'], $doc['doc_date'], $up[0], $up[1], $up[2]]);
            }
            flash('Dokument byl uložen.');
            redirect('/admin/dokumenty');
        }
        http_response_code(422);
    }
    render('admin/document_form', ['title' => $doc['id'] ? 'Upravit dokument' : 'Nahrát dokument', 'doc' => $doc, 'errors' => $errors]);
};
$route('GET', '/admin/dokumenty/novy', $docForm);
$route('POST', '/admin/dokumenty/novy', $docForm);
$route('GET', '/admin/dokumenty/(\d+)', $docForm);
$route('POST', '/admin/dokumenty/(\d+)', $docForm);
$route('POST', '/admin/dokumenty/(\d+)/smazat', function (int $id) {
    require_admin();
    delete_document($id);
    flash('Dokument byl smazán.');
    redirect('/admin/dokumenty');
});

// ---------- Kontakty ----------

$route('GET', '/admin/kontakty', function () {
    require_admin();
    render('admin/contacts', ['title' => 'Kontakty', 'contacts' => all('SELECT * FROM contacts ORDER BY CASE grp WHEN \'vybor\' THEN 1 WHEN \'sprava\' THEN 2 ELSE 3 END, sort, id')]);
});
$contactForm = function (int $id = 0) {
    require_admin();
    $c = $id ? (one('SELECT * FROM contacts WHERE id = ?', [$id]) ?? not_found())
        : ['id' => 0, 'grp' => 'vybor', 'role' => '', 'name' => '', 'phone' => '', 'email' => '', 'note' => '', 'sort' => 100];
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $c = array_merge($c, [
            'grp' => isset(CONTACT_GROUPS[post('grp')]) ? post('grp') : 'vybor',
            'role' => post('role'), 'name' => post('name'), 'phone' => post('phone'),
            'email' => post('email'), 'note' => post('note'), 'sort' => (int) post('sort', '100'),
        ]);
        if ($c['name'] === '') {
            $errors[] = 'Vyplňte jméno nebo název.';
        }
        if ($c['email'] !== '' && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'E-mail nemá správný tvar.';
        }
        if (!$errors) {
            $args = [$c['grp'], $c['role'], $c['name'], $c['phone'], $c['email'], $c['note'], $c['sort']];
            $id ? q('UPDATE contacts SET grp = ?, role = ?, name = ?, phone = ?, email = ?, note = ?, sort = ? WHERE id = ?', [...$args, $id])
                : q('INSERT INTO contacts (grp, role, name, phone, email, note, sort) VALUES (?,?,?,?,?,?,?)', $args);
            flash('Kontakt byl uložen.');
            redirect('/admin/kontakty');
        }
        http_response_code(422);
    }
    render('admin/contact_form', ['title' => $c['id'] ? 'Upravit kontakt' : 'Nový kontakt', 'c' => $c, 'errors' => $errors]);
};
$route('GET', '/admin/kontakty/novy', $contactForm);
$route('POST', '/admin/kontakty/novy', $contactForm);
$route('GET', '/admin/kontakty/(\d+)', $contactForm);
$route('POST', '/admin/kontakty/(\d+)', $contactForm);
$route('POST', '/admin/kontakty/(\d+)/smazat', function (int $id) {
    require_admin();
    q('DELETE FROM contacts WHERE id = ?', [$id]);
    flash('Kontakt byl smazán.');
    redirect('/admin/kontakty');
});

// ---------- Termíny ----------

$route('GET', '/admin/terminy', function () {
    require_admin();
    render('admin/events', ['title' => 'Termíny', 'events' => all('SELECT * FROM events ORDER BY event_date DESC')]);
});
$eventForm = function (int $id = 0) {
    require_admin();
    $ev = $id ? (one('SELECT * FROM events WHERE id = ?', [$id]) ?? not_found())
        : ['id' => 0, 'title' => 'Shromáždění vlastníků SVJ', 'event_date' => '', 'event_time' => '', 'place' => '', 'note' => ''];
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $ev = array_merge($ev, ['title' => post('title'), 'event_date' => post('event_date'), 'event_time' => post('event_time'), 'place' => post('place'), 'note' => post('note')]);
        if ($ev['title'] === '') {
            $errors[] = 'Vyplňte název.';
        }
        if (!valid_date($ev['event_date'])) {
            $errors[] = 'Zadejte platné datum.';
        }
        if ($ev['event_time'] !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $ev['event_time'])) {
            $errors[] = 'Čas zadejte ve tvaru HH:MM, nebo pole nechte prázdné.';
        }
        if (!$errors) {
            $args = [$ev['title'], $ev['event_date'], $ev['event_time'], $ev['place'], $ev['note']];
            $id ? q('UPDATE events SET title = ?, event_date = ?, event_time = ?, place = ?, note = ? WHERE id = ?', [...$args, $id])
                : q('INSERT INTO events (title, event_date, event_time, place, note) VALUES (?,?,?,?,?)', $args);
            flash('Termín byl uložen.');
            redirect('/admin/terminy');
        }
        http_response_code(422);
    }
    render('admin/event_form', ['title' => $ev['id'] ? 'Upravit termín' : 'Nový termín', 'ev' => $ev, 'errors' => $errors]);
};
$route('GET', '/admin/terminy/novy', $eventForm);
$route('POST', '/admin/terminy/novy', $eventForm);
$route('GET', '/admin/terminy/(\d+)', $eventForm);
$route('POST', '/admin/terminy/(\d+)', $eventForm);
$route('POST', '/admin/terminy/(\d+)/smazat', function (int $id) {
    require_admin();
    q('DELETE FROM events WHERE id = ?', [$id]);
    flash('Termín byl smazán.');
    redirect('/admin/terminy');
});

// ---------- Uživatelé ----------

$route('GET', '/admin/uzivatele', function () {
    require_admin();
    render('admin/users', ['title' => 'Uživatelé', 'users' => all("SELECT * FROM users ORDER BY active DESC, role = 'admin' DESC, entrance, name")]);
});
$userForm = function (int $id = 0) {
    $me = require_admin();
    $u = $id ? (one('SELECT * FROM users WHERE id = ?', [$id]) ?? not_found())
        : ['id' => 0, 'name' => '', 'email' => '', 'role' => 'resident', 'entrance' => '', 'unit' => '', 'phone' => '', 'active' => 1];
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $u = array_merge($u, [
            'name' => post('name'), 'email' => post('email'),
            'role' => isset(ROLES[post('role')]) ? post('role') : 'resident',
            'entrance' => in_array(post('entrance'), ENTRANCES, true) ? post('entrance') : '',
            'unit' => post('unit'), 'phone' => post('phone'), 'active' => isset($_POST['active']) ? 1 : 0,
        ]);
        if ($u['name'] === '') {
            $errors[] = 'Vyplňte jméno.';
        }
        if (!filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Zadejte platný e-mail – slouží jako přihlašovací jméno.';
        } elseif (val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$u['email'], $id])) {
            $errors[] = 'Účet s tímto e-mailem už existuje.';
        }
        if ($id === (int) $me['id'] && ($u['role'] !== 'admin' || !$u['active'])) {
            $errors[] = 'Sami sobě nemůžete odebrat práva správce ani účet vypnout.';
        }
        if (!$errors) {
            $args = [$u['name'], $u['email'], $u['role'], $u['entrance'], $u['unit'], $u['phone'], $u['active']];
            if ($id) {
                q('UPDATE users SET name = ?, email = ?, role = ?, entrance = ?, unit = ?, phone = ?, active = ? WHERE id = ?', [...$args, $id]);
                flash('Uživatel byl uložen.');
            } else {
                $pass = random_password();
                q('INSERT INTO users (name, email, role, entrance, unit, phone, active, password_hash, must_change_password) VALUES (?,?,?,?,?,?,?,?,1)', [...$args, password_hash($pass, PASSWORD_DEFAULT)]);
                $_SESSION['new_password'] = ['name' => $u['name'], 'email' => $u['email'], 'password' => $pass];
            }
            redirect('/admin/uzivatele');
        }
        http_response_code(422);
    }
    render('admin/user_form', ['title' => $u['id'] ? 'Upravit uživatele' : 'Nový uživatel', 'u' => $u, 'errors' => $errors]);
};
$route('GET', '/admin/uzivatele/novy', $userForm);
$route('POST', '/admin/uzivatele/novy', $userForm);
$route('GET', '/admin/uzivatele/(\d+)', $userForm);
$route('POST', '/admin/uzivatele/(\d+)', $userForm);
$route('POST', '/admin/uzivatele/(\d+)/heslo', function (int $id) {
    require_admin();
    $u = one('SELECT * FROM users WHERE id = ?', [$id]) ?? not_found();
    $pass = random_password();
    q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
    $_SESSION['new_password'] = ['name' => $u['name'], 'email' => $u['email'], 'password' => $pass];
    redirect('/admin/uzivatele');
});
$route('POST', '/admin/uzivatele/(\d+)/smazat', function (int $id) {
    $me = require_admin();
    if ($id === (int) $me['id']) {
        flash('Vlastní účet smazat nelze.', 'error');
    } else {
        q('DELETE FROM users WHERE id = ?', [$id]);
        flash('Uživatel byl smazán.');
    }
    redirect('/admin/uzivatele');
});

// ---------- Závady ----------

$route('GET', '/admin/zavady', function () {
    require_admin();
    render('admin/tickets', ['title' => 'Hlášené závady', 'tickets' => all("SELECT t.*, u.name author_name, u.entrance author_entrance, u.unit author_unit, u.phone author_phone, u.email author_email
        FROM tickets t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.status = 'done', t.id DESC")]);
});
$route('POST', '/admin/zavady/(\d+)', function (int $id) {
    require_admin();
    one('SELECT id FROM tickets WHERE id = ?', [$id]) ?? not_found();
    $status = isset(TICKET_STATUS[post('status')]) ? post('status') : 'new';
    q("UPDATE tickets SET status = ?, admin_note = ?, updated_at = datetime('now','localtime') WHERE id = ?", [$status, post('admin_note'), $id]);
    flash('Závada byla aktualizována.');
    redirect('/admin/zavady');
});
$route('POST', '/admin/zavady/(\d+)/smazat', function (int $id) {
    require_admin();
    q('DELETE FROM tickets WHERE id = ?', [$id]);
    flash('Hlášení bylo smazáno.');
    redirect('/admin/zavady');
});

// ---------- Nastavení ----------

const SETTING_KEYS = ['intro', 'address', 'ico', 'account', 'emergency_note', 'forum_note', 'webmaster_email'];
$route('GET', '/admin/nastaveni', function () {
    require_admin();
    render('admin/settings', ['title' => 'Nastavení webu']);
});
$route('POST', '/admin/nastaveni', function () {
    require_admin();
    foreach (SETTING_KEYS as $k) {
        q('INSERT INTO settings (key, value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$k, post($k)]);
    }
    flash('Nastavení bylo uloženo.');
    redirect('/admin/nastaveni');
});
