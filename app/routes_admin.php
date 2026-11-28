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
        'tickets' => all("SELECT t.*, u.name author_name, " . units_sql('u') . " author_units FROM tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.status <> 'done' ORDER BY t.id DESC LIMIT 5"),
        'external' => external_doc_count(db()),
        'maintenanceDue' => all('SELECT * FROM maintenance WHERE next_date IS NOT NULL AND next_date <= ? ORDER BY next_date', [date('Y-m-d', time() + 30 * 86400)]),
        'adminsWithout2fa' => (int) val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND totp_enabled = 0"),
        'failed24' => (int) val("SELECT COUNT(*) FROM audit_log WHERE action IN ('login_failed','login_2fa_failed') AND at >= datetime('now','localtime','-1 day')"),
    ]);
});

// Přehled správy domu: čísla za období, graf hlášení a časová osa práce správců (výboru i správcovské firmy).
$route('GET', '/admin/prehled-spravy', function () {
    require_admin();
    $days = dash_period();
    $t = function_exists('tenant_row') && TENANT !== '' ? tenant_row(TENANT) : null;
    $partner = $t && (int) ($t['partner_id'] ?? 0) && $t['partner_status'] === 'active' ? partner_row((int) $t['partner_id']) : null;
    render('admin/house_dashboard', ['title' => 'Přehled správy domu', 'd' => house_dashboard(db(), $days, true, $partner ? partner_tenant_user_ids() : []), 'partner' => $partner]);
});

$route('POST', '/admin/import', function () {
    require_admin();
    @set_time_limit(120);
    [$done, $failed, $left] = import_external_files(15);
    if (!$done && !$left) {
        [$done, $failed, $left] = import_external_files_into(db(), UPLOAD_DIR, 15, 0, true);   // už jen neúspěšné: zkusit je znovu
    }
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
                if ($post['published_at'] <= date('Y-m-d')) {
                    push_notify(array_diff(push_all_user_ids(), [(int) $admin['id']]), site('name'), 'Nová aktualita: ' . $post['title'], '/aktuality/' . $id);
                }
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
        : ['id' => 0, 'title' => '', 'category' => 'oznameni', 'visibility' => 'public', 'doc_date' => date('Y-m-d'), 'filename' => null, 'original_name' => '', 'external_url' => null, 'unit_id' => null];
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $doc = array_merge($doc, [
            'title' => post('title'),
            'category' => isset(CATEGORIES[post('category')]) ? post('category') : 'oznameni',
            'visibility' => isset(VISIBILITY[post('visibility')]) ? post('visibility') : 'public',
            'doc_date' => post('doc_date') ?: null,
            'unit_id' => val('SELECT id FROM units WHERE id = ?', [(int) post('unit_id')]) ?: null,
        ]);
        if ($doc['unit_id']) {
            $doc['visibility'] = 'residents';   // dokument jednotky není nikdy veřejný
        }
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
                q('UPDATE documents SET title = ?, category = ?, visibility = ?, doc_date = ?, unit_id = ? WHERE id = ?', [$doc['title'], $doc['category'], $doc['visibility'], $doc['doc_date'], $doc['unit_id'], $id]);
                if ($up) {
                    if ($doc['filename']) {
                        @unlink(UPLOAD_DIR . '/' . basename($doc['filename']));
                    }
                    q('UPDATE documents SET filename = ?, original_name = ?, size = ?, external_url = NULL, content = NULL WHERE id = ?', [$up[0], $up[1], $up[2], $id]);
                }
            } else {
                q('INSERT INTO documents (title, category, visibility, doc_date, filename, original_name, size, unit_id) VALUES (?,?,?,?,?,?,?,?)',
                    [$doc['title'], $doc['category'], $doc['visibility'], $doc['doc_date'], $up[0], $up[1], $up[2], $doc['unit_id']]);
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
// Nová adresa odběru kalendáře domu: dosavadní přestane fungovat (bezpečnost 11/2026 – bývalí obyvatelé ji jinak mají napořád).
$route('POST', '/admin/terminy/novy-odkaz-kalendare', function () {
    require_admin();
    calendar_token(true);
    audit('calendar_token', 'nová adresa odběru kalendáře domu');
    flash('Adresa pro odběr kalendáře je nová. Dosavadní odběry přestaly fungovat – obyvatelé si kalendář přidají znovu na stránce Termíny.');
    redirect('/admin/terminy');
});
$eventForm = function (int $id = 0) {
    require_admin();
    $ev = $id ? (one('SELECT * FROM events WHERE id = ?', [$id]) ?? not_found())
        : ['id' => 0, 'title' => 'Shromáždění vlastníků SVJ', 'event_date' => '', 'event_time' => '', 'place' => '', 'note' => '', 'rsvp' => 1, 'program' => ''];
    $evDocs = $id ? array_map('intval', array_column(all('SELECT document_id FROM event_documents WHERE event_id = ?', [$id]), 'document_id')) : [];
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $ev = array_merge($ev, ['title' => post('title'), 'event_date' => post('event_date'), 'event_time' => post('event_time'), 'place' => post('place'), 'note' => post('note'), 'rsvp' => isset($_POST['rsvp']) ? 1 : 0, 'program' => mb_substr(post('program'), 0, 4000)]);
        $evDocs = array_map('intval', (array) ($_POST['documents'] ?? []));
        if ($ev['title'] === '') {
            $errors[] = 'Vyplňte název.';
        }
        if (!valid_date($ev['event_date'])) {
            $errors[] = 'Zadejte platné datum.';
        }
        if ($ev['event_time'] !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $ev['event_time'])) {
            $errors[] = 'Čas zadejte ve tvaru HH:MM, nebo pole nechte prázdné.';
        }
        $errors = [...$errors, ...(function_exists('hybrid_event_check') ? hybrid_event_check($_POST, (int) $id) : [])];   // modul H: forma shromáždění (a kredit na ni)
        if (!$errors) {
            $args = [$ev['title'], $ev['event_date'], $ev['event_time'], $ev['place'], $ev['note'], $ev['rsvp'], $ev['program']];
            if ($id) {
                q('UPDATE events SET title = ?, event_date = ?, event_time = ?, place = ?, note = ?, rsvp = ?, program = ? WHERE id = ?', [...$args, $id]);
            } else {
                q('INSERT INTO events (title, event_date, event_time, place, note, rsvp, program) VALUES (?,?,?,?,?,?,?)', $args);
                $id = (int) db()->lastInsertId();
            }
            q('DELETE FROM event_documents WHERE event_id = ?', [$id]);
            foreach (array_unique($evDocs) as $docId) {
                if (val('SELECT 1 FROM documents WHERE id = ? AND unit_id IS NULL', [$docId])) {
                    q('INSERT INTO event_documents (event_id, document_id) VALUES (?,?)', [$id, $docId]);
                }
            }
            function_exists('hybrid_event_store') && hybrid_event_store($id, $_POST, (int) user()['id']);
            flash('Termín byl uložen.');
            redirect('/admin/terminy');
        }
        http_response_code(422);
    }
    render('admin/event_form', ['title' => $ev['id'] ? 'Upravit termín' : 'Nový termín', 'ev' => $ev, 'errors' => $errors, 'evDocs' => $evDocs,
        'documents' => all('SELECT id, title, category, doc_date FROM documents WHERE unit_id IS NULL ORDER BY created_at DESC, id DESC')]);
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
    render('admin/users', ['title' => 'Uživatelé', 'users' => all('SELECT u.*, ' . units_sql('u') . " unit_names, i.name inviter_name FROM users u LEFT JOIN users i ON i.id = u.invited_by ORDER BY u.active DESC, u.role = 'admin' DESC, unit_names IS NULL, unit_names, u.name")]);
});
$userForm = function (int $id = 0) {
    $me = require_admin();
    $u = $id ? (one('SELECT * FROM users WHERE id = ?', [$id]) ?? not_found())
        : ['id' => 0, 'name' => '', 'email' => '', 'role' => 'resident', 'kind' => 'owner', 'email_notify' => 1, 'phone' => '', 'active' => 1, 'position' => ''];
    $unitIds = $id ? user_unit_ids($id) : [];
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $u = array_merge($u, [
            'name' => post('name'), 'email' => post('email'),
            'role' => isset(ROLES[post('role')]) ? post('role') : 'resident',
            'phone' => post('phone'), 'active' => isset($_POST['active']) ? 1 : 0,
            'kind' => isset(KINDS[post('kind')]) ? post('kind') : 'owner', 'email_notify' => isset($_POST['email_notify']) ? 1 : 0,
            'position' => isset(POSITIONS[post('position')]) ? post('position') : '',
        ]);
        $unitIds = array_map('intval', (array) ($_POST['units'] ?? []));
        if ($u['name'] === '') {
            $errors[] = 'Vyplňte jméno.';
        }
        if (!filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Zadejte platný e-mail – slouží jako přihlašovací jméno.';
        } elseif (val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$u['email'], $id])) {
            $errors[] = 'Účet s tímto e-mailem už existuje.';
        }
        if (!$id && ($limitError = plan_limit_error('users'))) {
            $errors[] = $limitError;
        }
        if ($u['role'] === 'admin' && $u['active'] && !($id && val("SELECT 1 FROM users WHERE id = ? AND role = 'admin' AND active = 1", [$id])) && ($limitError = plan_limit_error('admins'))) {
            $errors[] = $limitError;
        }
        if ($id === (int) $me['id'] && ($u['role'] !== 'admin' || !$u['active'])) {
            $errors[] = 'Sami sobě nemůžete odebrat práva správce ani účet vypnout.';
        }
        if ($u['kind'] === 'tenant' && $u['active'] && ($tenantError = tenant_units_error(array_values(array_unique($unitIds)), $id))) {
            $errors[] = $tenantError;
        }
        if ($ownerError = owner_change_error($id, $u['kind'] === 'owner' && $u['active'] ? $unitIds : [])) {
            $errors[] = $ownerError;
        }
        if (!$errors) {
            $args = [$u['name'], $u['email'], $u['role'], $u['phone'], $u['active'], $u['kind'], $u['email_notify'], $u['position']];
            if ($id) {
                q('UPDATE users SET name = ?, email = ?, role = ?, phone = ?, active = ?, kind = ?, email_notify = ?, position = ? WHERE id = ?', [...$args, $id]);
                $userId = $id;
                flash('Uživatel byl uložen.');
            } else {
                $pass = random_password();
                q('INSERT INTO users (name, email, role, phone, active, kind, email_notify, position, password_hash, must_change_password) VALUES (?,?,?,?,?,?,?,?,?,1)', [...$args, password_hash($pass, PASSWORD_DEFAULT)]);
                $userId = (int) db()->lastInsertId();
                audit('user_created', $u['email'] . ' · ' . $u['role']);
                $_SESSION['new_password'] = ['name' => $u['name'], 'email' => $u['email'], 'password' => $pass];
            }
            q('DELETE FROM user_units WHERE user_id = ?', [$userId]);
            foreach (array_unique($unitIds) as $unitId) {
                if (val('SELECT 1 FROM units WHERE id = ?', [$unitId])) {
                    q('INSERT INTO user_units (user_id, unit_id) VALUES (?,?)', [$userId, $unitId]);
                }
            }
            redirect('/admin/uzivatele');
        }
        http_response_code(422);
    }
    render('admin/user_form', ['title' => $u['id'] ? 'Upravit uživatele' : 'Nový uživatel', 'u' => $u, 'errors' => $errors, 'units' => all_units(), 'unitIds' => $unitIds]);
};
$route('GET', '/admin/uzivatele/novy', $userForm);
$route('POST', '/admin/uzivatele/novy', $userForm);
$route('GET', '/admin/uzivatele/(\d+)', $userForm);
$route('POST', '/admin/uzivatele/(\d+)', $userForm);
$route('POST', '/admin/uzivatele/(\d+)/heslo', function (int $id) {
    require_admin();
    $u = one('SELECT * FROM users WHERE id = ?', [$id]) ?? not_found();
    $pass = random_password();
    q('UPDATE users SET password_hash = ?, must_change_password = 1, invite_hash = NULL, invite_expires = NULL WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
    audit('password_reset', $u['email']);
    $_SESSION['new_password'] = ['name' => $u['name'], 'email' => $u['email'], 'password' => $pass];
    redirect('/admin/uzivatele');
});
$route('POST', '/admin/uzivatele/(\d+)/smazat', function (int $id) {
    $me = require_admin();
    if ($id === (int) $me['id']) {
        flash('Vlastní účet smazat nelze.', 'error');
    } elseif ($ownerError = owner_change_error($id, [])) {
        flash($ownerError, 'error');
    } else {
        q('DELETE FROM users WHERE id = ?', [$id]);
        flash('Uživatel byl smazán.');
    }
    redirect('/admin/uzivatele');
});

// ---------- Závady ----------

$route('GET', '/admin/zavady', function () {
    $me = require_admin();
    ticket_ready();
    $filter = in_array($_GET['f'] ?? '', ['moje', 'po-terminu', 'vse'], true) ? $_GET['f'] : 'otevrene';
    $where = match ($filter) {
        'moje' => "t.status <> 'done' AND t.assignee_id = " . (int) $me['id'],
        'po-terminu' => "t.status <> 'done' AND t.due_date <> '' AND t.due_date < " . db()->quote(date('Y-m-d')),
        'vse' => '1 = 1',
        default => "t.status <> 'done'",
    };
    $tickets = all("SELECT t.*, u.name author_name, " . units_sql('u') . " author_units, u.phone author_phone, u.email author_email, a.name assignee_name
        FROM tickets t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN users a ON a.id = t.assignee_id WHERE $where
        ORDER BY t.status = 'done', CASE WHEN t.due_date = '' THEN 1 ELSE 0 END, t.due_date, t.id DESC");
    $counts = [
        'otevrene' => (int) val("SELECT COUNT(*) FROM tickets WHERE status <> 'done'"),
        'moje' => (int) val("SELECT COUNT(*) FROM tickets WHERE status <> 'done' AND assignee_id = ?", [$me['id']]),
        'po-terminu' => (int) val("SELECT COUNT(*) FROM tickets WHERE status <> 'done' AND due_date <> '' AND due_date < ?", [date('Y-m-d')]),
        'vse' => (int) val('SELECT COUNT(*) FROM tickets'),
    ];
    render('admin/tickets', ['title' => 'Závady a požadavky', 'suppliers' => all('SELECT * FROM suppliers ORDER BY field, name'), 'files' => ticket_files(array_column($tickets, 'id')),
        'tickets' => $tickets, 'log' => ticket_log(array_column($tickets, 'id')), 'admins' => ticket_admins(), 'filter' => $filter, 'counts' => $counts]);
});
$route('POST', '/admin/zavady/(\d+)', function (int $id) {
    require_admin();
    ticket_ready();
    $t = one('SELECT * FROM tickets WHERE id = ?', [$id]) ?? not_found();
    $status = isset(TICKET_STATUS[post('status')]) ? post('status') : 'new';
    // Správce může opravit i text hlášení; prázdná pole nechávají původní znění.
    $subject = post('subject') !== '' ? mb_substr(post('subject'), 0, 150) : $t['subject'];
    $body = post('body') !== '' ? mb_substr(post('body'), 0, 5000) : $t['body'];
    $location = isset($_POST['location']) ? mb_substr(post('location'), 0, 150) : $t['location'];
    $supplier = ctype_digit(post('supplier_id')) && val('SELECT 1 FROM suppliers WHERE id = ?', [post('supplier_id')]) ? (int) post('supplier_id') : null;
    $assignee = ctype_digit(post('assignee_id')) && val("SELECT 1 FROM users WHERE id = ? AND role = 'admin'", [post('assignee_id')]) ? (int) post('assignee_id') : 0;
    $due = valid_date(post('due_date')) ? post('due_date') : '';
    $message = trim(post('message'));
    q("UPDATE tickets SET status = ?, subject = ?, location = ?, body = ?, supplier_id = ?, assignee_id = ?, due_date = ?, updated_at = datetime('now','localtime') WHERE id = ?",
        [$status, $subject, $location, $body, $supplier, $assignee, $due, $id]);
    if ($subject !== $t['subject'] || $body !== $t['body'] || $location !== $t['location']) {
        ticket_log_add($id, 'edit', 'Upraven text hlášení.');
    }
    if ((int) $t['assignee_id'] !== $assignee) {
        $name = $assignee ? (string) val('SELECT name FROM users WHERE id = ?', [$assignee]) : '';
        ticket_log_add($id, 'assign', $assignee ? $name : 'bez řešitele');
        $me = user();
        if ($assignee && $assignee !== (int) $me['id'] && ($a = one('SELECT * FROM users WHERE id = ?', [$assignee])) && filter_var($a['email'], FILTER_VALIDATE_EMAIL)) {
            send_mail($a['email'], 'Přiděleno Vám: ' . $subject . ' – ' . site('name'), "Dobrý den,\n\n" . $me['name'] . ' Vám přidělil(a) hlášení „' . $subject . '“' . ($due !== '' ? ' s termínem ' . cz_date($due) : '') . ".\n\nVyřídit: " . absolute_url('/admin/zavady#z' . $id));
        }
    }
    if ((string) $t['due_date'] !== $due) {
        ticket_log_add($id, 'due', $due !== '' ? cz_date($due) : 'bez termínu');
    }
    if ((int) $t['supplier_id'] !== (int) $supplier) {
        ticket_log_add($id, 'supplier', $supplier ? (string) val('SELECT name FROM suppliers WHERE id = ?', [$supplier]) : 'bez dodavatele');
    }
    if ($status !== $t['status']) {
        ticket_log_add($id, 'status', TICKET_STATUS[$status]);
    }
    $old = $t['status'];
    $t = one('SELECT * FROM tickets WHERE id = ?', [$id]);
    if ($message !== '') {
        ticket_admin_reply($t, null, $message);
    } elseif ($status !== $old && !empty($t['user_id'])) {
        push_notify([(int) $t['user_id']], site('name'), 'Vaše hlášení „' . $subject . '“: ' . mb_strtolower(TICKET_STATUS[$status]), '/zavady#z' . $id);
    }
    flash('Uloženo.');
    redirect('/admin/zavady' . (post('f') !== '' ? '?f=' . rawurlencode(post('f')) : '') . '#z' . $id);
});
$route('POST', '/admin/zavady/(\d+)/smazat', function (int $id) {
    require_admin();
    foreach (all('SELECT filename FROM ticket_files WHERE ticket_id = ?', [$id]) as $tf) {
        @unlink(UPLOAD_DIR . '/' . basename($tf['filename']));
    }
    q('DELETE FROM tickets WHERE id = ?', [$id]);
    flash('Hlášení bylo smazáno.');
    redirect('/admin/zavady');
});

// ---------- Nastavení ----------

const SETTING_KEYS = ['site_name', 'site_title', 'site_accent', 'site_tagline', 'site_full', 'site_unit_prefix', 'site_entrances', 'site_look', 'site_style', 'site_lang', 'site_url', 'intro', 'address', 'ico', 'account', 'emergency_note', 'forum_note', 'webmaster_email', 'mail_from', 'mail_from_name', 'mail_reply_to',
    'mail_tracking', 'invite_enabled', 'invite_by_tenants', 'invite_kind', 'invite_max', 'invite_days', 'quorum_percent', 'privacy_text'];
const BOOL_SETTINGS = ['mail_tracking', 'invite_enabled', 'invite_by_tenants'];
// ---------- Uvolnění místa: promazání starých příloh závad, zpráv a rozeslaných e-mailů ----------

/** Co jde promazat a kolik toho je: druh => [název, popis, počet, bajtů]. Starší než $months měsíců. */
function cleanup_candidates(int $months): array
{
    $before = date('Y-m-d H:i:s', strtotime('-' . $months . ' months'));
    $size = function (array $files): int {
        $sum = 0;
        foreach ($files as $f) {
            $sum += is_file(UPLOAD_DIR . '/' . $f) ? (int) filesize(UPLOAD_DIR . '/' . $f) : 0;
        }
        return $sum;
    };
    $ticketFiles = array_column(all("SELECT f.filename FROM ticket_files f JOIN tickets t ON t.id = f.ticket_id WHERE t.status = 'done' AND t.created_at < ?", [$before]), 'filename');
    return [
        'prilohy' => ['Přílohy vyřešených závad', 'Fotografie a soubory u závad a požadavků, které jsou vyřešené. Samotné hlášení i odpověď výboru zůstanou.', count($ticketFiles), $size($ticketFiles)],
        'zpravy' => ['Zprávy mezi sousedy', 'Soukromé zprávy mezi účty na webu. Smažou se oběma stranám.', (int) val('SELECT COUNT(*) FROM messages WHERE created_at < ?', [$before]), 0],
        'emaily' => ['Záznamy rozeslaných e-mailů', 'Texty hromadných e-mailů a přehled, kdo je otevřel. Do schránek vlastníků se nezasahuje.', (int) val('SELECT COUNT(*) FROM mailings WHERE sent_at < ?', [$before]), 0],
    ];
}
$route('GET', '/admin/uvolnit-misto', function () {
    require_admin();
    $months = (int) ($_GET['mesice'] ?? 24);
    $months = in_array($months, [6, 12, 24, 36], true) ? $months : 24;
    $big = all('SELECT id, title, original_name, size, doc_date FROM documents WHERE filename IS NOT NULL ORDER BY size DESC LIMIT 10');
    render('admin/cleanup', ['title' => 'Uvolnit místo', 'months' => $months, 'items' => cleanup_candidates($months), 'big' => $big,
        'used' => plan_managed() ? plan_used('storage') : null, 'limit' => plan_managed() ? plan_limit('storage') : 0]);
});
$route('POST', '/admin/uvolnit-misto', function () {
    require_admin();
    $months = in_array((int) post('mesice'), [6, 12, 24, 36], true) ? (int) post('mesice') : 24;
    $kind = post('co');
    $before = date('Y-m-d H:i:s', strtotime('-' . $months . ' months'));
    if (!isset($_POST['potvrdit'])) {
        flash('Potvrďte prosím, že chcete vybrané záznamy nenávratně smazat.', 'error');
        redirect('/admin/uvolnit-misto?mesice=' . $months);
    }
    $n = 0;
    $freed = 0;
    if ($kind === 'prilohy') {
        foreach (all("SELECT f.id, f.filename FROM ticket_files f JOIN tickets t ON t.id = f.ticket_id WHERE t.status = 'done' AND t.created_at < ?", [$before]) as $f) {
            $path = UPLOAD_DIR . '/' . basename((string) $f['filename']);
            if (is_file($path)) {
                $freed += (int) filesize($path);
                @unlink($path);
            }
            q('DELETE FROM ticket_files WHERE id = ?', [$f['id']]);
            $n++;
        }
    } elseif ($kind === 'zpravy') {
        $n = (int) val('SELECT COUNT(*) FROM messages WHERE created_at < ?', [$before]);
        q('DELETE FROM messages WHERE created_at < ?', [$before]);
    } elseif ($kind === 'emaily') {
        $ids = array_map('intval', array_column(all('SELECT id FROM mailings WHERE sent_at < ?', [$before]), 'id'));
        if ($ids) {
            q('DELETE FROM mailing_recipients WHERE mailing_id IN (' . implode(',', $ids) . ')');
            q('DELETE FROM mailings WHERE id IN (' . implode(',', $ids) . ')');
        }
        $n = count($ids);
    } else {
        redirect('/admin/uvolnit-misto');
    }
    audit('cleanup', ['prilohy' => 'přílohy vyřešených závad', 'zpravy' => 'zprávy mezi sousedy', 'emaily' => 'záznamy rozeslaných e-mailů'][$kind] . ' starší než ' . $months . ' měsíců · smazáno ' . $n . ($freed ? ' · uvolněno ' . round($freed / 1048576, 1) . ' MB' : ''));
    flash('Smazáno: ' . $n . ($freed ? '. Uvolněno ' . str_replace('.', ',', (string) round($freed / 1048576, 1)) . ' MB.' : '.'));
    redirect('/admin/uvolnit-misto?mesice=' . $months);
});

$route('GET', '/admin/nastaveni', function () {
    require_admin();
    render('admin/settings', ['title' => 'Nastavení webu']);
});
$route('POST', '/admin/nastaveni', function () {
    require_admin();
    if (post('site_url') !== '' && !preg_match('~^https?://[A-Za-z0-9.\-]+(:\d+)?(/[^\s]*)?$~', post('site_url'))) {
        flash('Adresa webu musí začínat https:// (např. https://www.vase-svj.cz). Nastavení nebylo uloženo.', 'error');
        redirect('/admin/nastaveni');
    }
    if (post('mail_from') !== '' && !filter_var(post('mail_from'), FILTER_VALIDATE_EMAIL)) {
        flash('Adresa odesílatele e-mailů nemá správný tvar. Nastavení nebylo uloženo.', 'error');
        redirect('/admin/nastaveni');
    }
    if (post('mail_reply_to') !== '' && !filter_var(post('mail_reply_to'), FILTER_VALIDATE_EMAIL)) {
        flash('Adresa pro odpovědi na e-maily nemá správný tvar. Nastavení nebylo uloženo.', 'error');
        redirect('/admin/nastaveni');
    }
    if (post('site_name') === '' || post('site_title') === '') {
        flash('Název společenství a krátký název na web nesmí být prázdné. Nastavení nebylo uloženo.', 'error');
        redirect('/admin/nastaveni');
    }
    $save = fn(string $k, string $v) => q('INSERT INTO settings (`key`, value) VALUES (?,?) ON CONFLICT(`key`) DO UPDATE SET value = excluded.value', [$k, $v]);
    // Logo: obrázek PNG nebo JPG; staré se při výměně smaže.
    if (plan_managed() && !plan_has('sablony')) {
        // Edice Zdarma: bez vlastního loga (případné dřívější logo zůstává uložené a vrátí se s edicí Standard).
    } elseif (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $ext = strtolower(pathinfo((string) $_FILES['logo']['name'], PATHINFO_EXTENSION));
        $up = in_array($ext, ['png', 'jpg', 'jpeg'], true) && (int) $_FILES['logo']['size'] <= 1048576 ? store_upload($_FILES['logo']) : 'Logo musí být obrázek PNG nebo JPG do 1 MB.';
        if (is_string($up)) {
            flash($up . ' Nastavení nebylo uloženo.', 'error');
            redirect('/admin/nastaveni');
        }
        if (preg_match('/^[a-f0-9]{32}\.[a-z]+$/', setting('logo'))) {
            @unlink(UPLOAD_DIR . '/' . setting('logo'));
        }
        $save('logo', $up[0]);
    } elseif (isset($_POST['logo_remove']) && preg_match('/^[a-f0-9]{32}\.[a-z]+$/', setting('logo'))) {
        @unlink(UPLOAD_DIR . '/' . setting('logo'));
        $save('logo', '');
    }
    foreach (SETTING_KEYS as $k) {
        $v = in_array($k, BOOL_SETTINGS, true) ? (isset($_POST[$k]) ? '1' : '0') : post($k);
        if ($k === 'site_style' && !isset(STYLES[$v])) {
            $v = 'klasik';
        }
        if ($k === 'site_look' && !isset(LOOKS[$v])) {
            $v = 'les';
        }
        // Prémiovou šablonu a barvu jde uložit jen v Premium nebo při běžící zkoušce – dřív šla „vybrat do zásoby“
        // a správce nevěděl, že web vypadá jinak, než zvolil (11/2026).
        if (plan_managed() && !plan_has('vzhledy-plus') && (($k === 'site_style' && in_array($v, PREMIUM_STYLES, true)) || ($k === 'site_look' && in_array($v, PREMIUM_LOOKS, true)))) {
            $v = $k === 'site_style' ? site_style_used() : site_look_used();
        }
        if ($k === 'site_lang' && !isset(LANGS[$v])) {
            $v = 'cs';
        }
        $save($k, $v);
    }
    if (ai_configured()) {   // volba se ukládá jen tam, kde je pomocník na serveru k dispozici – jinak by se omylem vypnul
        $save('ai_enabled', isset($_POST['ai_enabled']) ? '1' : '0');
        $save('ai_residents', isset($_POST['ai_residents']) ? '1' : '0');
    }
    // Menu: zaškrtnuté části se zobrazují, ostatní se schovají.
    $shown = (array) ($_POST['features'] ?? []);
    $save('hidden_features', implode(',', array_values(array_diff(array_keys(FEATURES), $shown))));
    ext_menu_save(array_map('strval', (array) ($_POST['ext_menu'] ?? [])));   // části z modulů (např. Inzeráty)
    flash('Nastavení bylo uloženo.');
    // Tlačítko Uložit u sekce: stránka se po uložení vrátí k ní (viz settings.php).
    if (preg_match('/^[a-z]{2,20}$/', post('sekce'))) {
        $_SESSION['settings_saved'] = post('sekce');
    }
    redirect('/admin/nastaveni');
});

// ---------- Zprávy e-mailem ----------

$route('GET', '/admin/zpravy', function () {
    require_admin();
    render('admin/mailings', ['title' => 'Zprávy e-mailem', 'mailings' => all('SELECT m.*, u.name author_name FROM mailings m LEFT JOIN users u ON u.id = m.sent_by ORDER BY m.id DESC')]);
});

$mailForm = function () {
    $admin = require_admin();
    $m = ['subject' => '', 'body' => '', 'audience' => 'owners'];
    $errors = [];
    $notice = null;
    if ($GLOBALS['METHOD'] === 'POST') {
        $m = ['subject' => post('subject'), 'body' => post('body'), 'audience' => isset(AUDIENCES[post('audience')]) ? post('audience') : 'owners'];
        if ($m['subject'] === '' || mb_strlen($m['subject']) > 150) {
            $errors[] = 'Vyplňte předmět (nejvýše 150 znaků).';
        }
        if ($m['body'] === '') {
            $errors[] = 'Vyplňte text zprávy.';
        }
        if (!filter_var(setting('mail_from'), FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Nejdřív v Nastavení vyplňte adresu odesílatele e-mailů.';
        }
        [$text, $html] = build_mail($m['body'], null);
        if (!$errors && post('action') !== 'test' && ($limitError = plan_limit_error('mails', count(mail_recipients($m['audience']))))) {
            $errors[] = $limitError;
        }
        $testTo = filter_var(post('test_to'), FILTER_VALIDATE_EMAIL) ? post('test_to') : $admin['email'];
        if (!$errors && post('action') === 'test') {
            $route = mail_route();
            if (in_array($route, ['staging-off', 'local'], true)) {
                send_mail($testTo, '[Zkouška] ' . $m['subject'], $text, $html);
                $errors[] = 'Zkušební zpráva se skutečně neodeslala. ' . mail_route_note();
            } elseif (send_mail($testTo, '[Zkouška] ' . $m['subject'], $text, $html)) {
                $notice = ($route === 'smtp' ? 'SMTP server převzal zkušební zprávu pro ' : 'Zkušební zpráva byla předána k odeslání na ') . $testTo . '. Zkontrolujte, že dorazila a nespadla do spamu.' . (mail_route_note() !== '' ? ' ' . mail_route_note() : '');
            } else {
                $errors[] = 'Zkušební zprávu se nepodařilo odeslat. ' . ($GLOBALS['MAIL_ERROR'] ?? 'Server nejspíš nemá povolené odesílání e-mailů – ověřte to u poskytovatele hostingu.');
            }
        } elseif (!$errors) {
            // Jednorázový klíč brání dvojímu odeslání při opakovaném kliknutí nebo obnovení stránky.
            if (!hash_equals((string) ($_SESSION['mail_nonce'] ?? ''), post('nonce')) || post('nonce') === '') {
                $errors[] = 'Formulář už byl jednou odeslán. Zkontrolujte historii zpráv, než ho odešlete znovu.';
            } else {
                unset($_SESSION['mail_nonce']);
                @set_time_limit(600);
                $tracked = setting_on('mail_tracking');
                q('INSERT INTO mailings (subject, body, audience, tracked, sent_by) VALUES (?,?,?,?,?)', [$m['subject'], $m['body'], $m['audience'], $tracked ? 1 : 0, $admin['id']]);
                $mailingId = (int) db()->lastInsertId();
                $sent = 0;
                $failed = [];
                // Hromadná zpráva: nad hodinový limit hostingu čeká ve frontě a odchází postupně.
                mail_bulk(function () use ($m, $tracked, $mailingId, &$sent, &$failed) {
                    foreach (mail_recipients($m['audience']) as $r) {
                        $token = bin2hex(random_bytes(16));
                        [$text, $html] = build_mail($m['body'], $tracked ? $token : null);
                        $ok = send_mail($r['email'], $m['subject'], $text, $html);
                        $ok ? $sent++ : $failed[] = $r['name'] . ' <' . $r['email'] . '>';
                        q('INSERT INTO mailing_recipients (mailing_id, user_id, name, email, token, sent_ok) VALUES (?,?,?,?,?,?)', [$mailingId, $r['id'], $r['name'], $r['email'], $token, $ok ? 1 : 0]);
                    }
                });
                q('UPDATE mailings SET sent_count = ?, failed_count = ?, failed_list = ? WHERE id = ?', [$sent, count($failed), implode("\n", $failed), $mailingId]);
                push_notify(array_column(mail_recipients($m['audience']), 'id'), site('name'), 'Zpráva od výboru: ' . $m['subject'], '/');
                audit('mailing_sent', $m['subject'] . ' · ' . $sent . ' příjemců');
                $fake = in_array(mail_route(), ['staging-off', 'local'], true);
                flash(($fake ? "Zpráva se skutečně nikomu nedoručila ($sent příjemců). " . mail_route_note() : "Zpráva byla předána k odeslání $sent příjemcům." . mailq_note()) . ($failed ? ' Nepodařilo se: ' . count($failed) . ' – viz historie.' : ''), $failed || $fake ? 'info' : 'ok');
                redirect('/admin/zpravy/' . $mailingId);
            }
        }
        if ($errors) {
            http_response_code(422);
        }
    } elseif (($eid = (int) ($_GET['termin'] ?? 0)) && ($evp = one('SELECT * FROM events WHERE id = ?', [$eid]))) {
        $m['subject'] = $evp['title'] . ' ' . cz_date($evp['event_date']);
        $m['body'] = 'Výbor SVJ Vás zve: ' . $evp['title'] . ' se koná **' . cz_date($evp['event_date'], true) . ($evp['event_time'] ? ' v ' . $evp['event_time'] : '') . '**'
            . ($evp['place'] ? ', ' . $evp['place'] : '') . '.' . ($evp['note'] ? ' ' . $evp['note'] : '')
            . ($evp['rsvp'] ? "\n\nDejte nám prosím vědět, zda přijdete – stačí jedno kliknutí po přihlášení:\n" . absolute_url('/terminy/' . $eid) . "\n\nPotřebujeme vědět předem, zda bude shromáždění usnášeníschopné." : "\n\nPodrobnosti: " . absolute_url('/terminy/' . $eid));
        // Podklady pro AI pomocníka („Napsat pozvánku“): údaje termínu, program a podklady.
        $evDocTitles = array_column(all('SELECT d.title FROM documents d JOIN event_documents ed ON ed.document_id = d.id WHERE ed.event_id = ?', [$eid]), 'title');
        $aiInvite = implode("\n", array_filter(['Název: ' . $evp['title'], 'Datum: ' . cz_date($evp['event_date'], true), $evp['event_time'] ? 'Čas: ' . $evp['event_time'] : '',
            $evp['place'] ? 'Místo: ' . $evp['place'] : '', $evp['note'] ? 'Poznámka: ' . $evp['note'] : '', $evp['program'] !== '' ? "Program:\n" . str_replace('**', '', $evp['program']) : '',
            $evDocTitles ? 'Podklady (na stránce termínu): ' . implode('; ', $evDocTitles) : '',
            'Stránka termínu' . ($evp['rsvp'] ? ' (podrobnosti, podklady a potvrzení účasti po přihlášení)' : ' (podrobnosti)') . ': ' . absolute_url('/terminy/' . $eid)]));
    } elseif (($pid = (int) ($_GET['aktualita'] ?? 0)) && ($p = one('SELECT * FROM posts WHERE id = ?', [$pid]))) {
        $docs = post_documents($pid);
        $m['subject'] = $p['title'];
        $m['body'] = trim($p['body']) . "\n\nCelé oznámení" . ($docs ? ' včetně příloh' : '') . ' najdete na webu: ' . absolute_url('/aktuality/' . $pid)
            . ($p['visibility'] === 'residents' || array_filter($docs, fn($d) => $d['visibility'] === 'residents') ? "\n(Pro zobrazení se přihlaste.)" : '');
    }
    render('admin/mailing_form', [
        'title' => 'Nová zpráva e-mailem', 'm' => $m, 'errors' => $errors, 'notice' => $notice, 'aiInvite' => $aiInvite ?? '',
        'nonce' => $_SESSION['mail_nonce'] = bin2hex(random_bytes(16)),
        'counts' => ['owners' => count(mail_recipients('owners')), 'all' => count(mail_recipients('all'))],
        'optedOut' => (int) val('SELECT COUNT(*) FROM users WHERE active = 1 AND email_notify = 0'),
    ]);
};
$route('GET', '/admin/zpravy/nova', $mailForm);
$route('POST', '/admin/zpravy/nova', $mailForm);

// ---------- Jednotky ----------

$route('GET', '/admin/jednotky', function () {
    require_admin();
    $people = [];
    foreach (all('SELECT uu.unit_id, u.id, u.name, u.kind, u.active, u.password_hash = ? pending FROM user_units uu JOIN users u ON u.id = uu.user_id ORDER BY u.kind, u.name', [NO_PASSWORD]) as $p) {
        $people[$p['unit_id']][] = $p;
    }
    render('admin/units', ['title' => 'Jednotky', 'units' => all_units(), 'people' => $people]);
});

$route('POST', '/admin/jednotky/hromadne', function () {
    require_admin();
    $entrance = in_array(post('entrance'), entrances(), true) ? post('entrance') : '';
    $from = (int) post('from');
    $to = (int) post('to');
    if ($from < 1 || $to < $from || $to - $from >= 200) {
        flash('Zadejte rozsah čísel jednotek od–do (nejvýše 200 najednou).', 'error');
        redirect('/admin/jednotky');
    }
    $added = 0;
    for ($n = $from; $n <= $to; $n++) {
        $added += q('INSERT OR IGNORE INTO units (entrance, number) VALUES (?,?)', [$entrance, (string) $n])->rowCount();
    }
    flash("Přidáno jednotek: $added." . ($added < $to - $from + 1 ? ' Už existující byly přeskočeny.' : ''));
    redirect('/admin/jednotky');
});

$unitForm = function (int $id = 0) {
    require_admin();
    $unit = $id ? (one('SELECT * FROM units WHERE id = ?', [$id]) ?? not_found())
        : ['id' => 0, 'entrance' => 'A', 'number' => '', 'floor' => '', 'share' => '', 'owner_note' => '', 'note' => '', 'owner_contact' => '', 'area' => null];
    $userIds = $id ? array_map('intval', array_column(all('SELECT user_id FROM user_units WHERE unit_id = ?', [$id]), 'user_id')) : [];
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $unit = array_merge($unit, [
            'entrance' => in_array(post('entrance'), entrances(), true) ? post('entrance') : '',
            'number' => post('number'), 'floor' => post('floor'), 'share' => post('share'), 'owner_note' => post('owner_note'), 'note' => post('note'), 'owner_contact' => mb_substr(post('owner_contact'), 0, 2000),
        ]);
        $area = unit_area_parse(post('area'));
        $unit['area'] = $area === false ? post('area') : $area;
        $userIds = array_map('intval', (array) ($_POST['users'] ?? []));
        if ($unit['number'] === '' || mb_strlen($unit['number']) > 10) {
            $errors[] = 'Vyplňte číslo jednotky (nejvýše 10 znaků).';
        } elseif (val('SELECT 1 FROM units WHERE entrance = ? AND number = ? AND id <> ?', [$unit['entrance'], $unit['number'], $id])) {
            $errors[] = 'Jednotka s tímto vchodem a číslem už existuje.';
        }
        if ($area === false) {
            $errors[] = 'Plochu zadejte v m² jako číslo, např. 54,3 (nebo pole nechte prázdné).';
        }
        // Nájemník bez vlastníka nevznikne: když jsou mezi účty jednotky aktivní nájemníci, musí tam být i aktivní vlastník.
        $picked = $userIds ? all('SELECT kind FROM users WHERE active = 1 AND id IN (' . implode(',', array_map('intval', array_unique($userIds))) . ')') : [];
        $kinds = array_column($picked, 'kind');
        if (in_array('tenant', $kinds, true) && !in_array('owner', $kinds, true)) {
            $errors[] = 'K jednotce je přiřazený nájemník, ale žádný vlastník s účtem. Nájemník bez vlastníka být nemůže – přiřaďte i účet vlastníka.';
        }
        if (!$errors) {
            $args = [$unit['entrance'], $unit['number'], $unit['floor'], $unit['share'], $unit['owner_note'], $unit['note'], $unit['owner_contact'], $area];
            if ($id) {
                q('UPDATE units SET entrance = ?, number = ?, floor = ?, share = ?, owner_note = ?, note = ?, owner_contact = ?, area = ? WHERE id = ?', [...$args, $id]);
            } else {
                q('INSERT INTO units (entrance, number, floor, share, owner_note, note, owner_contact, area) VALUES (?,?,?,?,?,?,?,?)', $args);
                $id = (int) db()->lastInsertId();
            }
            q('DELETE FROM user_units WHERE unit_id = ?', [$id]);
            foreach (array_unique($userIds) as $userId) {
                if (val('SELECT 1 FROM users WHERE id = ?', [$userId])) {
                    q('INSERT INTO user_units (user_id, unit_id) VALUES (?,?)', [$userId, $id]);
                }
            }
            flash('Jednotka byla uložena.');
            redirect('/admin/jednotky');
        }
        http_response_code(422);
    }
    render('admin/unit_form', ['title' => $unit['id'] ? 'Upravit jednotku ' . unit_name($unit) : 'Nová jednotka', 'unit' => $unit, 'errors' => $errors,
        'userIds' => $userIds, 'users' => all('SELECT id, name, email, kind, active FROM users ORDER BY name')]);
};
$route('GET', '/admin/jednotky/nova', $unitForm);
$route('POST', '/admin/jednotky/nova', $unitForm);
$route('GET', '/admin/jednotky/(\d+)', $unitForm);
$route('POST', '/admin/jednotky/(\d+)', $unitForm);
$route('POST', '/admin/jednotky/(\d+)/smazat', function (int $id) {
    require_admin();
    if (val('SELECT 1 FROM documents WHERE unit_id = ?', [$id])) {
        flash('Jednotka má přiřazené dokumenty. Nejdřív je smažte nebo přiřaďte jinam – jinak by je mohli vidět i ostatní.', 'error');
        redirect('/admin/jednotky');
    }
    q('DELETE FROM units WHERE id = ?', [$id]);
    flash('Jednotka byla smazána. Účty jejích obyvatel zůstaly zachované.');
    redirect('/admin/jednotky');
});

// ---------- Účast na shromáždění (správce) ----------

$route('GET', '/admin/terminy/(\d+)/ucast', function (int $id) {
    require_admin();
    $ev = one('SELECT * FROM events WHERE id = ?', [$id]) ?? not_found();
    render('admin/attendance', ['title' => 'Účast: ' . $ev['title'], 'ev' => $ev, 'att' => attendance($id)]);
});
$route('POST', '/admin/terminy/(\d+)/ucast', function (int $id) {
    $admin = require_admin();
    one('SELECT id FROM events WHERE id = ?', [$id]) ?? not_found();
    $unitId = (int) post('unit_id');
    $status = post('status');
    if (!val('SELECT 1 FROM units WHERE id = ?', [$unitId])) {
        not_found();
    }
    if (isset(RSVP[$status])) {
        q("INSERT INTO event_rsvp (event_id, unit_id, user_id, status, proxy_name) VALUES (?,?,?,?,?)
            ON CONFLICT(event_id, unit_id) DO UPDATE SET user_id = excluded.user_id, status = excluded.status, proxy_name = excluded.proxy_name, updated_at = datetime('now','localtime')",
            [$id, $unitId, $admin['id'], $status, $status === 'proxy' ? mb_substr(post('proxy_name'), 0, 190) : '']);
    } else {
        q('DELETE FROM event_rsvp WHERE event_id = ? AND unit_id = ?', [$id, $unitId]);
    }
    redirect('/admin/terminy/' . $id . '/ucast#j' . $unitId);
});

// ---------- Detail odeslané zprávy ----------

$route('GET', '/admin/zpravy/(\d+)', function (int $id) {
    require_admin();
    $m = one('SELECT m.*, u.name author_name FROM mailings m LEFT JOIN users u ON u.id = m.sent_by WHERE m.id = ?', [$id]) ?? not_found();
    render('admin/mailing', ['title' => $m['subject'], 'm' => $m,
        'recipients' => all('SELECT r.*, ' . units_sql('u') . ' unit_names FROM mailing_recipients r LEFT JOIN users u ON u.id = r.user_id WHERE r.mailing_id = ? ORDER BY r.name', [$id])]);
});

// ---------- Zabezpečení: vypnutí dvoufázového ověření a záznamy ----------

$route('POST', '/admin/uzivatele/(\d+)/overeni', function (int $id) {
    require_admin();
    $u = one('SELECT * FROM users WHERE id = ?', [$id]) ?? not_found();
    q('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last = 0 WHERE id = ?', [$id]);
    audit('2fa_reset', $u['email']);
    flash('Dvoufázové ověření uživatele „' . $u['name'] . '“ bylo vypnuto. Zapnout si ho může znovu ve svém účtu.');
    redirect('/admin/uzivatele');
});

const AUDIT_LABELS = [
    'login' => 'Přihlášení', 'login_failed' => 'Neúspěšné přihlášení', 'login_2fa_failed' => 'Chybný ověřovací kód', 'logout' => 'Odhlášení',
    'setup' => 'Vytvoření prvního správce', 'setup_code_failed' => 'Chybný instalační kód', 'password_changed' => 'Změna hesla', 'gdpr_export' => 'Export osobních údajů', 'gdpr_erasure' => 'Zrušení účtu na vlastní žádost',
    '2fa_enabled' => 'Zapnutí dvoufázového ověření', '2fa_disabled' => 'Vypnutí dvoufázového ověření', '2fa_reset' => 'Správce vypnul dvoufázové ověření',
    'cleanup' => 'Uvolnění místa (promazání starých záznamů)', 'meter_import' => 'Odečty: příjem dat', 'presence' => 'Prezence na shromáždění', 'meter_notice' => 'Odečty: měsíční informace obyvatelům', 'meter_settings' => 'Odečty: nastavení dálkových odečtů', 'join_requested' => 'Žádost o přiřazení k jednotce', 'join_approved' => 'Schválení přiřazení k jednotce', 'join_rejected' => 'Zamítnutí přiřazení k jednotce', 'join_link' => 'Odkaz pro přiřazení k jednotce', 'support_login' => 'Vstup podpory provozovatele (jen pro čtení)', 'partner_login' => 'Vstup správce domu (správcovská firma)', 'partner_approved' => 'Schválení přístupu správce domu', 'partner_revoked' => 'Ukončení přístupu správce domu', 'support_logout' => 'Odchod podpory provozovatele', 'user_created' => 'Založení účtu', 'user_updated' => 'Úprava účtu', 'user_deleted' => 'Smazání účtu', 'password_reset' => 'Nové dočasné heslo', 'password_reset_requested' => 'Žádost o nové heslo e-mailem', 'password_reset_done' => 'Nastavení nového hesla po zapomenutí',
    'settings_saved' => 'Změna nastavení', 'mailing_sent' => 'Rozeslání e-mailu', 'post_deleted' => 'Smazání aktuality', 'document_deleted' => 'Smazání dokumentu',
    'unit_deleted' => 'Smazání jednotky', 'users_imported' => 'Import uživatelů', 'units_imported' => 'Import jednotek z katastru', 'invite_sent' => 'Pozvánka člena domácnosti', 'invite_accepted' => 'Přijetí pozvánky', 'session_expired' => 'Odhlášení po nečinnosti',
    'sso_login' => 'Přihlášení přes Google, Microsoft, Apple nebo Facebook', 'sso_failed' => 'Neúspěšné přihlášení přes další účet', 'sso_linked' => 'Propojení s dalším účtem', 'sso_unlinked' => 'Zrušení propojení s dalším účtem',
];
$route('GET', '/admin/zaznamy', function () {
    require_admin();
    $only = isset($_GET['jen-problemy']);
    $who = trim((string) ($_GET['kdo'] ?? ''));
    $where = [];
    $params = [];
    if ($only) {
        $where[] = "a.action IN ('login_failed','login_2fa_failed','setup_code_failed','2fa_disabled','2fa_reset','password_reset') OR a.action LIKE 'Smazání%'";
    }
    if ($who !== '') {
        $where[] = 'a.user_name = ?';
        $params[] = $who;
    }
    render('admin/audit', ['title' => 'Záznamy', 'only' => $only, 'who' => $who,
        'rows' => all('SELECT a.* FROM audit_log a' . ($where ? ' WHERE (' . implode(') AND (', $where) . ')' : '') . ' ORDER BY a.id DESC LIMIT 500', $params),
        'people' => array_column(all("SELECT DISTINCT user_name FROM audit_log WHERE user_name <> '' ORDER BY user_name"), 'user_name'),
        'chain' => audit_chain_check(),
        'failed24' => (int) val("SELECT COUNT(*) FROM audit_log WHERE action IN ('login_failed','login_2fa_failed') AND at >= datetime('now','localtime','-1 day')")]);
});

// ---------- Import jednotek a vlastníků z katastru ----------

$cadastreImport = function () {
    require_admin();
    $text = (string) ($_POST['text'] ?? '');
    $parsed = null;
    if ($GLOBALS['METHOD'] === 'POST') {
        if (strlen($text) > 500000) {
            flash('Vložený text je příliš dlouhý.', 'error');
            redirect('/admin/jednotky/import');
        }
        $parsed = parse_cadastre($text);
        if ($parsed['units'] && post('action') === 'save') {
            $new = $updated = $removed = 0;
            if ($parsed['by_cp']) {
                // Vchody se označí čísly popisnými a jednotky se jmenují jako v katastru (bez společné předpony).
                foreach (['site_entrances' => implode(',', $parsed['by_cp']), 'site_unit_prefix' => ''] as $k => $v) {
                    q('DELETE FROM settings WHERE `key` = ?', [$k]);
                    q('INSERT INTO settings (`key`, value) VALUES (?,?)', [$k, $v]);
                }
            }
            if (isset($_POST['remove_others'])) {
                // Úklid po dřívějším chybném importu: jednotky, které ve výpisu nejsou a nikdo k nim není přiřazen.
                $keep = array_map(fn($u) => $u['entrance'] . '/' . $u['number'], $parsed['units']);
                foreach (all('SELECT u.id, u.entrance, u.number FROM units u WHERE NOT EXISTS (SELECT 1 FROM user_units uu WHERE uu.unit_id = u.id)') as $old) {
                    if (!in_array($old['entrance'] . '/' . $old['number'], $keep, true)) {
                        q('DELETE FROM units WHERE id = ?', [$old['id']]);
                        $removed++;
                    }
                }
            }
            foreach ($parsed['units'] as $u) {
                $id = val('SELECT id FROM units WHERE entrance = ? AND number = ?', [$u['entrance'], $u['number']]);
                if ($id) {
                    q('UPDATE units SET share = ?, owner_note = ?, owner_contact = ? WHERE id = ?', [$u['share'], $u['owner_note'], $u['owner_contact'], $id]);
                    $updated++;
                } else {
                    q('INSERT INTO units (entrance, number, share, owner_note, owner_contact) VALUES (?,?,?,?,?)', [$u['entrance'], $u['number'], $u['share'], $u['owner_note'], $u['owner_contact']]);
                    $new++;
                }
            }
            audit('units_imported', "nových $new, aktualizovaných $updated" . ($removed ? ", smazaných $removed" : ''));
            setting('__reset');
            flash("Import hotov: nových jednotek $new, aktualizovaných $updated" . ($removed ? ", smazaných $removed" : '') . '. Účty přiřazené k jednotkám zůstaly beze změny.');
            redirect('/admin/jednotky');
        }
    }
    $existing = [];
    foreach (all_units() as $un) {
        $existing[$un['entrance'] . '/' . $un['number']] = $un;
    }
    render('admin/units_import', ['title' => 'Import z katastru', 'text' => $text, 'parsed' => $parsed, 'existing' => $existing]);
};
$route('GET', '/admin/jednotky/import', $cadastreImport);
$route('POST', '/admin/jednotky/import', $cadastreImport);

// ---------- Import uživatelů ----------

$userImport = function () {
    require_admin();
    $text = (string) ($_POST['text'] ?? '');
    $rows = null;
    if ($GLOBALS['METHOD'] === 'POST') {
        if ($limitError = plan_limit_error('users')) {
            flash($limitError, 'error');
            redirect('/admin/uzivatele');
        }
        if (strlen($text) > 200000) {
            flash('Vložený text je příliš dlouhý.', 'error');
            redirect('/admin/uzivatele/import');
        }
        $rows = parse_user_import($text);
        if (count($rows) > 500) {
            flash('Najednou lze importovat nejvýše 500 uživatelů.', 'error');
            redirect('/admin/uzivatele/import');
        }
        $ok = array_filter($rows, fn($r) => $r['status'] === 'ok');
        if ($ok && post('action') === 'save') {
            @set_time_limit(600);
            $invite = isset($_POST['invite']);
            $sent = $notSent = 0;
            foreach ($ok as $r) {
                q("INSERT INTO users (name, email, phone, kind, role, password_hash) VALUES (?,?,?,?,'resident',?)", [$r['name'], $r['email'], $r['phone'], $r['kind'], NO_PASSWORD]);
                $id = (int) db()->lastInsertId();
                foreach ($r['units'] as $un) {
                    q('INSERT OR IGNORE INTO user_units (user_id, unit_id) VALUES (?,?)', [$id, $un['id']]);
                }
                if ($invite) {
                    send_account_invite(['id' => $id, 'email' => $r['email']])[0] ? $sent++ : $notSent++;
                }
            }
            audit('users_imported', count($ok) . ' účtů' . ($invite ? ", pozvánek odesláno $sent" : ''));
            flash('Založeno účtů: ' . count($ok) . '.' . ($invite ? " Pozvánek odesláno: $sent" . ($notSent ? ", nepodařilo se: $notSent (pošlete je znovu u jednotlivých účtů)." : '.') : ' Pozvánky jste neposílali – pošlete je u jednotlivých účtů odkazem „Poslat pozvánku“.'), $notSent ? 'info' : 'ok');
            redirect('/admin/uzivatele');
        }
    }
    render('admin/users_import', ['title' => 'Import uživatelů', 'text' => $text, 'rows' => $rows,
        'mailReady' => (bool) filter_var(setting('mail_from'), FILTER_VALIDATE_EMAIL)]);
};
// Výbor podle veřejného rejstříku (ARES): správce u každého člena doplní e-mail a pošle pozvánku, nebo jen nastaví funkci existujícímu účtu.
$committee = function () {
    require_admin();
    $key = 'ares_name_key';
    $users = all('SELECT id, name, email, position, active, password_hash FROM users ORDER BY name');
    $byName = [];
    foreach ($users as $u) {
        $byName[preg_replace('/\s+/', ' ', trim($key($u['name'])))] = $u;
    }
    $res = ares_officers(setting('ico'));
    $errors = [];
    if ($res['ok'] && $GLOBALS['METHOD'] === 'POST') {
        $done = [];
        foreach ($res['members'] as $i => $m) {
            if (!isset($_POST['use'][$i])) {
                continue;
            }
            $pos = isset(POSITIONS[$_POST['position'][$i] ?? '']) ? (string) $_POST['position'][$i] : '';
            $existing = $byName[preg_replace('/\s+/', ' ', trim($key($m['name'])))] ?? null;
            if ($existing) {
                q('UPDATE users SET position = ? WHERE id = ?', [$pos, $existing['id']]);
                $done[] = $existing['name'] . ' – funkce nastavena';
                continue;
            }
            $email = trim((string) ($_POST['email'][$i] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'U člena ' . $m['name'] . ' chybí platný e-mail – bez něj účet založit nejde.';
                continue;
            }
            if (val('SELECT 1 FROM users WHERE email = ?', [$email])) {
                $errors[] = 'E-mail ' . $email . ' už má jiný účet. U člena ' . $m['name'] . ' ho opravte, nebo funkci nastavte v jeho účtu.';
                continue;
            }
            if ($limitError = plan_limit_error('users')) {
                $errors[] = $limitError;
                break;
            }
            q("INSERT INTO users (name, email, role, kind, position, password_hash, invited_by) VALUES (?,?,'resident','owner',?,?,?)", [$m['name'], $email, $pos, NO_PASSWORD, user()['id']]);
            $new = one('SELECT * FROM users WHERE id = ?', [(int) db()->lastInsertId()]);
            audit('user_created', $email . ' · výbor z rejstříku');
            [$sent] = isset($_POST['invite'][$i]) ? send_account_invite($new) : [false];
            $done[] = $m['name'] . ($sent ? ' – pozvánka odeslána na ' . $email : ' – účet založen' . (isset($_POST['invite'][$i]) ? ', pozvánku se ale nepodařilo odeslat (pošlete ji ze seznamu uživatelů)' : ', pozvánku pošlete ze seznamu uživatelů'));
        }
        if ($done) {
            flash('Výbor podle rejstříku: ' . implode('; ', $done) . '.');
        }
        if (!$errors) {
            redirect('/admin/uzivatele');
        }
        http_response_code(422);
    }
    $rows = [];
    foreach ($res['members'] ?? [] as $i => $m) {
        $rows[$i] = $m + ['user' => $byName[preg_replace('/\s+/', ' ', trim($key($m['name'])))] ?? null];
    }
    render('admin/committee', ['title' => 'Výbor podle rejstříku', 'res' => $res, 'rows' => $rows, 'errors' => $errors]);
};
$route('GET', '/admin/uzivatele/vybor', $committee);
$route('POST', '/admin/uzivatele/vybor', $committee);
$route('GET', '/admin/uzivatele/import', $userImport);
$route('POST', '/admin/uzivatele/import', $userImport);

$route('POST', '/admin/uzivatele/(\d+)/pozvanka', function (int $id) {
    require_admin();
    $u = one('SELECT * FROM users WHERE id = ? AND password_hash = ?', [$id, NO_PASSWORD]) ?? not_found();
    [$sent, $link] = send_account_invite($u);
    audit('invite_sent', $u['email']);
    if ($sent) {
        flash('Pozvánka byla odeslána na ' . $u['email'] . '.');
    } else {
        $_SESSION['admin_invite_link'] = ['name' => $u['name'], 'link' => $link];
    }
    redirect('/admin/uzivatele');
});

// ---------- Úprava příspěvku v diskuzi správcem ----------

$forumEdit = function (int $id) {
    $admin = require_admin();
    $p = one('SELECT f.*, u.name author_name FROM forum_posts f LEFT JOIN users u ON u.id = f.author_id WHERE f.id = ?', [$id]) ?? not_found();
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $title = $p['parent_id'] ? '' : post('title');
        $body = post('body');
        if ($body === '' || mb_strlen($body) > 5000 || (!$p['parent_id'] && ($title === '' || mb_strlen($title) > 150))) {
            $errors[] = 'Vyplňte text (nejvýše 5000 znaků)' . ($p['parent_id'] ? '.' : ' a název tématu (nejvýše 150 znaků).');
        } else {
            q('UPDATE forum_posts SET title = ?, body = ?, edited_note = ? WHERE id = ?', [$title, $body, 'Upravil správce ' . $admin['name'] . ' ' . date('j. n. Y'), $id]);
            flash('Příspěvek byl upraven. U příspěvku je vidět, že ho upravil správce.');
            redirect('/diskuze/' . ($p['parent_id'] ?: $id));
        }
        $p = array_merge($p, ['title' => $title, 'body' => $body]);
        http_response_code(422);
    }
    render('admin/forum_form', ['title' => 'Upravit příspěvek', 'p' => $p, 'errors' => $errors]);
};
$route('GET', '/admin/diskuze/(\d+)', $forumEdit);
$route('POST', '/admin/diskuze/(\d+)', $forumEdit);
