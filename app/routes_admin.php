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
        'external' => (int) val('SELECT COUNT(*) FROM documents WHERE filename IS NULL AND external_url IS NOT NULL'),
        'adminsWithout2fa' => (int) val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND totp_enabled = 0"),
        'failed24' => (int) val("SELECT COUNT(*) FROM audit_log WHERE action IN ('login_failed','login_2fa_failed') AND at >= datetime('now','localtime','-1 day')"),
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
    audit('post_deleted', (string) val('SELECT title FROM posts WHERE id = ?', [$id]));
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
    audit('document_deleted', (string) val('SELECT title FROM documents WHERE id = ?', [$id]));
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
        : ['id' => 0, 'title' => 'Shromáždění vlastníků SVJ', 'event_date' => '', 'event_time' => '', 'place' => '', 'note' => '', 'rsvp' => 1];
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $ev = array_merge($ev, ['title' => post('title'), 'event_date' => post('event_date'), 'event_time' => post('event_time'), 'place' => post('place'), 'note' => post('note'), 'rsvp' => isset($_POST['rsvp']) ? 1 : 0]);
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
            $args = [$ev['title'], $ev['event_date'], $ev['event_time'], $ev['place'], $ev['note'], $ev['rsvp']];
            $id ? q('UPDATE events SET title = ?, event_date = ?, event_time = ?, place = ?, note = ?, rsvp = ? WHERE id = ?', [...$args, $id])
                : q('INSERT INTO events (title, event_date, event_time, place, note, rsvp) VALUES (?,?,?,?,?,?)', $args);
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
    render('admin/users', ['title' => 'Uživatelé', 'users' => all('SELECT u.*, ' . units_sql('u') . " unit_names, i.name inviter_name FROM users u LEFT JOIN users i ON i.id = u.invited_by ORDER BY u.active DESC, u.role = 'admin' DESC, unit_names IS NULL, unit_names, u.name")]);
});
$userForm = function (int $id = 0) {
    $me = require_admin();
    $u = $id ? (one('SELECT * FROM users WHERE id = ?', [$id]) ?? not_found())
        : ['id' => 0, 'name' => '', 'email' => '', 'role' => 'resident', 'kind' => 'owner', 'email_notify' => 1, 'phone' => '', 'active' => 1];
    $unitIds = $id ? user_unit_ids($id) : [];
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $u = array_merge($u, [
            'name' => post('name'), 'email' => post('email'),
            'role' => isset(ROLES[post('role')]) ? post('role') : 'resident',
            'phone' => post('phone'), 'active' => isset($_POST['active']) ? 1 : 0,
            'kind' => isset(KINDS[post('kind')]) ? post('kind') : 'owner', 'email_notify' => isset($_POST['email_notify']) ? 1 : 0,
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
        if ($id === (int) $me['id'] && ($u['role'] !== 'admin' || !$u['active'])) {
            $errors[] = 'Sami sobě nemůžete odebrat práva správce ani účet vypnout.';
        }
        if (!$errors) {
            $args = [$u['name'], $u['email'], $u['role'], $u['phone'], $u['active'], $u['kind'], $u['email_notify']];
            if ($id) {
                q('UPDATE users SET name = ?, email = ?, role = ?, phone = ?, active = ?, kind = ?, email_notify = ? WHERE id = ?', [...$args, $id]);
                $userId = $id;
                audit('user_updated', $u['email'] . ' · ' . $u['role'] . ($u['active'] ? '' : ' · vypnutý'));
                flash('Uživatel byl uložen.');
            } else {
                $pass = random_password();
                q('INSERT INTO users (name, email, role, phone, active, kind, email_notify, password_hash, must_change_password) VALUES (?,?,?,?,?,?,?,?,1)', [...$args, password_hash($pass, PASSWORD_DEFAULT)]);
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
    } else {
        audit('user_deleted', (string) val('SELECT email FROM users WHERE id = ?', [$id]));
        q('DELETE FROM users WHERE id = ?', [$id]);
        flash('Uživatel byl smazán.');
    }
    redirect('/admin/uzivatele');
});

// ---------- Závady ----------

$route('GET', '/admin/zavady', function () {
    require_admin();
    render('admin/tickets', ['title' => 'Hlášené závady', 'tickets' => all("SELECT t.*, u.name author_name, " . units_sql('u') . " author_units, u.phone author_phone, u.email author_email
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

const SETTING_KEYS = ['site_url', 'intro', 'address', 'ico', 'account', 'emergency_note', 'forum_note', 'webmaster_email', 'mail_from', 'mail_from_name',
    'mail_tracking', 'invite_enabled', 'invite_by_tenants', 'invite_kind', 'invite_max', 'invite_days', 'quorum_percent'];
const BOOL_SETTINGS = ['mail_tracking', 'invite_enabled', 'invite_by_tenants'];
$route('GET', '/admin/nastaveni', function () {
    require_admin();
    render('admin/settings', ['title' => 'Nastavení webu']);
});
$route('POST', '/admin/nastaveni', function () {
    require_admin();
    if (post('site_url') !== '' && !preg_match('~^https?://[A-Za-z0-9.\-]+(:\d+)?(/[^\s]*)?$~', post('site_url'))) {
        flash('Adresa webu musí začínat https:// (např. https://www.svjtabor48.cz). Nastavení nebylo uloženo.', 'error');
        redirect('/admin/nastaveni');
    }
    if (post('mail_from') !== '' && !filter_var(post('mail_from'), FILTER_VALIDATE_EMAIL)) {
        flash('Adresa odesílatele e-mailů nemá správný tvar. Nastavení nebylo uloženo.', 'error');
        redirect('/admin/nastaveni');
    }
    foreach (SETTING_KEYS as $k) {
        q('INSERT INTO settings (`key`, value) VALUES (?,?) ON CONFLICT(`key`) DO UPDATE SET value = excluded.value', [$k, in_array($k, BOOL_SETTINGS, true) ? (isset($_POST[$k]) ? '1' : '0') : post($k)]);
    }
    audit('settings_saved');
    flash('Nastavení bylo uloženo.');
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
        if (!$errors && post('action') === 'test') {
            send_mail($admin['email'], '[Zkouška] ' . $m['subject'], $text, $html)
                ? $notice = 'Zkušební zpráva byla předána k odeslání na ' . $admin['email'] . '. Zkontrolujte, že dorazila a nespadla do spamu.'
                : $errors[] = 'Zkušební zprávu se nepodařilo odeslat. Server nejspíš nemá povolené odesílání e-mailů – ověřte to u poskytovatele hostingu.';
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
                foreach (mail_recipients($m['audience']) as $r) {
                    $token = bin2hex(random_bytes(16));
                    [$text, $html] = build_mail($m['body'], $tracked ? $token : null);
                    $ok = send_mail($r['email'], $m['subject'], $text, $html);
                    $ok ? $sent++ : $failed[] = $r['name'] . ' <' . $r['email'] . '>';
                    q('INSERT INTO mailing_recipients (mailing_id, user_id, name, email, token, sent_ok) VALUES (?,?,?,?,?,?)', [$mailingId, $r['id'], $r['name'], $r['email'], $token, $ok ? 1 : 0]);
                }
                q('UPDATE mailings SET sent_count = ?, failed_count = ?, failed_list = ? WHERE id = ?', [$sent, count($failed), implode("\n", $failed), $mailingId]);
                audit('mailing_sent', $m['subject'] . ' · ' . $sent . ' příjemců');
                flash("Zpráva byla předána k odeslání $sent příjemcům." . ($failed ? ' Nepodařilo se: ' . count($failed) . ' – viz historie.' : ''), $failed ? 'info' : 'ok');
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
    } elseif (($pid = (int) ($_GET['aktualita'] ?? 0)) && ($p = one('SELECT * FROM posts WHERE id = ?', [$pid]))) {
        $docs = post_documents($pid);
        $m['subject'] = $p['title'];
        $m['body'] = trim($p['body']) . "\n\nCelé oznámení" . ($docs ? ' včetně příloh' : '') . ' najdete na webu: ' . absolute_url('/aktuality/' . $pid)
            . ($p['visibility'] === 'residents' || array_filter($docs, fn($d) => $d['visibility'] === 'residents') ? "\n(Pro zobrazení se přihlaste.)" : '');
    }
    render('admin/mailing_form', [
        'title' => 'Nová zpráva e-mailem', 'm' => $m, 'errors' => $errors, 'notice' => $notice,
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
    $entrance = in_array(post('entrance'), ENTRANCES, true) ? post('entrance') : '';
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
        : ['id' => 0, 'entrance' => 'A', 'number' => '', 'floor' => '', 'share' => '', 'owner_note' => '', 'note' => ''];
    $userIds = $id ? array_map('intval', array_column(all('SELECT user_id FROM user_units WHERE unit_id = ?', [$id]), 'user_id')) : [];
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $unit = array_merge($unit, [
            'entrance' => in_array(post('entrance'), ENTRANCES, true) ? post('entrance') : '',
            'number' => post('number'), 'floor' => post('floor'), 'share' => post('share'), 'owner_note' => post('owner_note'), 'note' => post('note'),
        ]);
        $userIds = array_map('intval', (array) ($_POST['users'] ?? []));
        if ($unit['number'] === '' || mb_strlen($unit['number']) > 10) {
            $errors[] = 'Vyplňte číslo jednotky (nejvýše 10 znaků).';
        } elseif (val('SELECT 1 FROM units WHERE entrance = ? AND number = ? AND id <> ?', [$unit['entrance'], $unit['number'], $id])) {
            $errors[] = 'Jednotka s tímto vchodem a číslem už existuje.';
        }
        if (!$errors) {
            $args = [$unit['entrance'], $unit['number'], $unit['floor'], $unit['share'], $unit['owner_note'], $unit['note']];
            if ($id) {
                q('UPDATE units SET entrance = ?, number = ?, floor = ?, share = ?, owner_note = ?, note = ? WHERE id = ?', [...$args, $id]);
            } else {
                q('INSERT INTO units (entrance, number, floor, share, owner_note, note) VALUES (?,?,?,?,?,?)', $args);
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
    $unit = one('SELECT * FROM units WHERE id = ?', [$id]);
    audit('unit_deleted', $unit ? unit_name($unit) : '');
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
        q("INSERT INTO event_rsvp (event_id, unit_id, user_id, status) VALUES (?,?,?,?)
            ON CONFLICT(event_id, unit_id) DO UPDATE SET user_id = excluded.user_id, status = excluded.status, updated_at = datetime('now','localtime')", [$id, $unitId, $admin['id'], $status]);
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
    'setup' => 'Vytvoření prvního správce', 'setup_code_failed' => 'Chybný instalační kód', 'password_changed' => 'Změna hesla',
    '2fa_enabled' => 'Zapnutí dvoufázového ověření', '2fa_disabled' => 'Vypnutí dvoufázového ověření', '2fa_reset' => 'Správce vypnul dvoufázové ověření',
    'user_created' => 'Založení účtu', 'user_updated' => 'Úprava účtu', 'user_deleted' => 'Smazání účtu', 'password_reset' => 'Nové dočasné heslo',
    'settings_saved' => 'Změna nastavení', 'mailing_sent' => 'Rozeslání e-mailu', 'post_deleted' => 'Smazání aktuality', 'document_deleted' => 'Smazání dokumentu',
    'unit_deleted' => 'Smazání jednotky', 'users_imported' => 'Import uživatelů', 'units_imported' => 'Import jednotek z katastru', 'invite_sent' => 'Pozvánka člena domácnosti', 'invite_accepted' => 'Přijetí pozvánky', 'session_expired' => 'Odhlášení po nečinnosti',
];
$route('GET', '/admin/zaznamy', function () {
    require_admin();
    $only = isset($_GET['jen-problemy']);
    $where = $only ? "WHERE a.action IN ('login_failed','login_2fa_failed','setup_code_failed','2fa_disabled','2fa_reset','user_deleted','password_reset')" : '';
    render('admin/audit', ['title' => 'Záznamy', 'only' => $only,
        'rows' => all("SELECT a.*, u.name user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id $where ORDER BY a.id DESC LIMIT 300"),
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
            $new = $updated = 0;
            foreach ($parsed['units'] as $u) {
                $id = val('SELECT id FROM units WHERE entrance = ? AND number = ?', [$u['entrance'], $u['number']]);
                if ($id) {
                    q('UPDATE units SET share = ?, owner_note = ? WHERE id = ?', [$u['share'], $u['owner_note'], $id]);
                    $updated++;
                } else {
                    q('INSERT INTO units (entrance, number, share, owner_note) VALUES (?,?,?,?)', [$u['entrance'], $u['number'], $u['share'], $u['owner_note']]);
                    $new++;
                }
            }
            audit('units_imported', "nových $new, aktualizovaných $updated");
            flash("Import hotov: nových jednotek $new, aktualizovaných $updated. Účty přiřazené k jednotkám zůstaly beze změny.");
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
