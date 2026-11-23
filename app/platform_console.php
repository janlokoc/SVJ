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
        'unit_prefix' => mb_substr(post('unit_prefix'), 0, 10), 'entrances' => post('entrances') !== '' ? post('entrances') : 'A', 'look' => $look, 'style' => isset(STYLES[post('style')]) ? post('style') : 'klasik',
        'lang' => isset(LANGS[post('lang')]) ? post('lang') : 'cs',
        'custom_domain' => strtolower(preg_replace('~^https?://|/.*$~', '', post('custom_domain'))), 'note' => post('note'), 'active' => isset($_POST['active']) ? 1 : 0,
        'billing' => isset($_POST['billing']) ? 1 : 0, 'trial_ends' => valid_date(post('trial_ends')) ? post('trial_ends') : '', 'paid_until' => valid_date(post('paid_until')) ? post('paid_until') : '', 'plan' => in_array(post('plan'), ['standard', 'premium'], true) ? post('plan') : '',
        'owner_name' => post('owner_name'), 'owner_email' => post('owner_email')]);
};
$tenantErrors = function (array $t): array {
    $e = [];
    if ($t['name'] === '' || $t['title'] === '') {
        $e[] = 'Vyplňte název společenství i krátký název na web.';
    }
    if ($t['owner_email'] !== '' && !filter_var($t['owner_email'], FILTER_VALIDATE_EMAIL)) {
        $e[] = 'Kontaktní e-mail pro předplatné nemá správný tvar.';
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
        $t['billing_state'] = billing_state($t);
        $t['trials'] = (int) pq('SELECT COUNT(*) FROM feature_trials WHERE tenant = ? AND ends >= ?', [$t['slug'], date('Y-m-d')])->fetchColumn();
        $t['delete_on'] = tenant_delete_date($t);
        $t['ai_used'] = (int) ($t['stats']['ai_used'] ?? 0);
        $t['last_notice'] = pq('SELECT * FROM tenant_notices WHERE tenant = ? ORDER BY id DESC LIMIT 1', [$t['slug']])->fetch() ?: null;
    }
    unset($t);
    render('platform/dashboard', ['title' => 'Společenství', 'tenants' => $tenants, 'hasDemo' => (bool) demo_tenant()]);
    exit;
}
if ($PATH === '/svj/nove') {
    $t = ['slug' => '', 'name' => '', 'title' => '', 'accent' => '', 'tagline' => 'Společenství vlastníků jednotek', 'full' => '', 'address' => '', 'unit_prefix' => '', 'entrances' => 'A',
        'look' => 'les', 'style' => 'klasik', 'lang' => 'cs', 'custom_domain' => '', 'note' => '', 'active' => 1, 'seed' => '', 'billing' => 0, 'trial_ends' => '', 'paid_until' => '', 'owner_name' => '', 'owner_email' => ''];
    $errors = [];
    if ($METHOD === 'POST') {
        $t = $tenantInput($t);
        $t['slug'] = slug_input(post('slug'));
        $t['seed'] = isset($_POST['seed_tabor']) ? 'tabor' : '';
        $errors = $tenantErrors($t);
        if ($problem = slug_problem($t['slug'])) {
            $errors[] = $problem;
        }
        if (!$errors) {
            tenant_create($t);
            if ($t['seed'] === 'tabor') {
                @set_time_limit(150);
                import_external_files_into(tenant_pdo($t['slug']), tenant_storage($t['slug']) . '/uploads', 500, 100);
            }
            platform_log('Založení společenství', $t['slug'] . ' · ' . $t['name']);
            flash('Web společenství je založen. Předejte jeho výboru adresu a instalační kód, nebo mu rovnou vytvořte účet správce.');
            redirect('/svj/' . $t['slug']);
        }
        http_response_code(422);
    }
    render('platform/tenant_form', ['title' => 'Nové společenství', 't' => $t, 'errors' => $errors, 'new' => true]);
    exit;
}
if (preg_match('~^/svj/([a-z0-9][a-z0-9-]{1,38})(/soubory|/soubor-nahrat|/soubor-smazat|/spravce|/smazat|/import|/domena|/certifikat|/prevod|/predplatne|/zkouska|/vstoupit|/heslo|/overeni)?$~', $PATH, $m) && ($t = tenant_row($m[1]))) {
    $slug = $t['slug'];
    $action = $m[2] ?? '';
    if ($METHOD === 'POST' && $action === '/smazat') {
        if (!empty($t['demo'])) {
            flash('Ukázkový web smazat nejde. Můžete ho obnovit s čerstvými daty.', 'error');
            redirect('/svj/' . $slug);
        }
        if (post('confirm') !== $slug) {
            flash('Pro smazání opište přesně adresu společenství.', 'error');
            redirect('/svj/' . $slug);
        }
        tenant_delete_storage($slug);
        pq('DELETE FROM tenants WHERE slug = ?', [$slug]);
        pq("UPDATE payments SET status = 'cancelled', note = 'společenství smazáno' WHERE tenant = ? AND status IN ('pending','claimed')", [$slug]);
        platform_log('Smazání společenství', $slug . ' · ' . $t['name']);
        flash('Společenství „' . $t['name'] . '“ bylo smazáno včetně všech dat.');
        redirect('/');
    }
    if ($METHOD === 'POST' && $action === '/certifikat') {
        [$ok, $message, $domain] = cert_upload($t) + [2 => ''];
        if ($ok) {
            platform_log('Nahrání certifikátu', $slug . ' · ' . $domain);
        }
        flash($message, $ok ? 'ok' : 'error');
        redirect('/svj/' . $slug);
    }
    if ($METHOD === 'POST' && $action === '/predplatne') {
        $edition = post('edition');
        $before = billing_state($t);
        $base = max(date('Y-m-d'), $t['paid_until'] !== '' && tenant_edition($t) === $edition ? $t['paid_until'] : '');
        $until = match (post('do')) {
            'year' => date('Y-m-d', strtotime($base . ' +1 year')),
            'month' => date('Y-m-d', strtotime($base . ' +1 month')),
            default => post('until'),
        };
        if (in_array(post('do'), ['year', 'month'], true) && !in_array($edition, ['standard', 'premium'], true)) {
            flash('Prodloužit lze jen placenou edici. Vyberte v poli Edice Standard nebo Premium a klikněte znovu.', 'error');
        } elseif (in_array($edition, ['standard', 'premium'], true) && (!valid_date($until) || $until < date('Y-m-d'))) {
            flash('Zadejte datum, do kdy má předplatné platit (dnešní nebo pozdější).', 'error');
        } elseif (billing_set($t, $edition, $until)) {
            $label = ['unlimited' => 'bez placení a omezení', 'free' => 'Zdarma'][$edition] ?? EDITIONS[$edition] . ' do ' . cz_date($until);
            $was = $before['mode'] === 'free' ? 'bez placení' : EDITIONS[$before['edition']] . ($before['until'] !== '' ? ' do ' . $before['until'] : '');
            platform_log('Ruční nastavení předplatného', $slug . ' · ' . $was . ' → ' . $label . (trim(post('reason')) !== '' ? ' · ' . trim(post('reason')) : ''));
            if (isset($_POST['notify']) && $t['owner_email'] !== '' && in_array($edition, ['standard', 'premium'], true)) {
                tenant_notify($t, 'manual', 'Předplatné nastaveno – ' . $t['name'], "Dobrý den,\n\nweb " . $t['name'] . " má nově edici " . EDITIONS[$edition] . " do " . cz_date($until) . ".\n"
                    . (trim(post('reason')) !== '' ? trim(post('reason')) . "\n" : '') . "Přehled edice a limitů najdete v administraci: " . tenant_url($t, '/admin/predplatne'));
            }
            flash('Předplatné nastaveno: ' . $label . '.');
        } else {
            flash('Předplatné se nepodařilo nastavit.', 'error');
        }
        redirect('/svj/' . $slug . '#predplatne');
    }
    if ($METHOD === 'POST' && $action === '/zkouska') {
        $feature = post('feature');
        if (isset(PLAN_FEATURES[$feature])) {
            $cur = pq('SELECT ends FROM feature_trials WHERE tenant = ? AND feature = ?', [$slug, $feature])->fetchColumn();
            $ends = post('do') === 'end' ? '' : date('Y-m-d', strtotime(max(date('Y-m-d'), (string) $cur) . ' +' . PLAN_TRIAL_DAYS . ' day'));
            trial_set($slug, $feature, $ends);
            platform_log('Zkouška funkce nastavena ručně', $slug . ' · ' . PLAN_FEATURES[$feature][1] . ' · ' . ($ends === '' ? 'ukončena' : 'do ' . $ends));
            flash($ends === '' ? 'Zkouška je ukončena.' : 'Zkouška funkce „' . PLAN_FEATURES[$feature][1] . '“ běží do ' . cz_date($ends) . '.');
        }
        redirect('/svj/' . $slug . '#predplatne');
    }
    if ($METHOD === 'POST' && $action === '/prevod') {
        $m = migration_row($slug);
        if ($m && $m['status'] === 'paid' && post('do') === 'done' && ($still = external_doc_count(tenant_pdo($slug)))) {
            flash('Převod nejde označit jako hotový: ' . $still . ' dokumentů je pořád jen odkazem na starý web. Nejdřív je stáhněte (tlačítko „Stáhnout soubory“).', 'error');
            redirect('/svj/' . $slug . '#soubory');
        }
        if ($m && $m['status'] === 'paid' && post('do') === 'done') {
            pq("UPDATE migrations SET status = 'done', done_at = ?, done_note = ? WHERE id = ?", [date('Y-m-d H:i:s'), mb_substr(trim(post('done_note')), 0, 500), $m['id']]);
            platform_log('Převod obsahu dokončen', $slug . ' · ' . $m['url']);
            $to = filter_var($m['contact_email'], FILTER_VALIDATE_EMAIL) ? $m['contact_email'] : $t['owner_email'];
            if ($to !== '') {
                platform_mail($to, 'Převod obsahu je hotový – ' . $t['name'], "Dobrý den,\n\nobsah z webu " . $m['url'] . " jsme převedli na " . rtrim(tenant_url($t), '/') . ".\n"
                    . (trim(post('done_note')) !== '' ? trim(post('done_note')) . "\n" : '') . "Projděte si ho prosím a napište nám, kdyby něco chybělo nebo bylo jinak, než má být.");
            }
            flash('Převod je označen jako hotový a kontaktní osoba dostala e-mail.');
        }
        redirect('/svj/' . $slug . '#prevod');
    }
    if ($METHOD === 'POST' && $action === '/domena') {
        $domain = $t['domain_request'];
        if (post('do') === 'activate' && $t['domain_status'] === 'requested' && $domain !== '') {
            if (pq('SELECT 1 FROM tenants WHERE slug <> ? AND custom_domain = ?', [$slug, $domain])->fetchColumn()) {
                flash('Tuto doménu už používá jiné společenství.', 'error');
                redirect('/svj/' . $slug);
            }
            $until = $t['domain_until'] !== '' ? $t['domain_until'] : date('Y-m-d', strtotime('+1 year -1 day'));
            pq("UPDATE tenants SET custom_domain = ?, domain_status = 'active', domain_until = ? WHERE slug = ?", [$domain, $until, $slug]);
            platform_log('Zprovoznění vlastní domény', $slug . ' · ' . $domain . ' · do ' . $until);
            tenant_notify(tenant_row($slug), 'domain', 'Vlastní doména je zprovozněna – ' . $t['name'], "Dobrý den,\n\nweb " . $t['name'] . " teď běží na adrese https://" . $domain . "/\nPřihlašovací údaje i správa webu zůstávají stejné; původní adresa "
                . tenant_address($slug) . " funguje dál.\nDoména je zaplacena do " . cz_date($until) . '.');
            flash('Doména ' . $domain . ' je zprovozněna a kontaktní osoba dostala e-mail. Ověřte, že web na ní odpovídá přes HTTPS.');
        } elseif (post('do') === 'remove' && $t['domain_status'] !== '') {
            pq("UPDATE tenants SET custom_domain = CASE WHEN custom_domain = domain_request THEN '' ELSE custom_domain END, domain_request = '', domain_status = '', domain_until = '' WHERE slug = ?", [$slug]);
            if ($domain !== '') {
                cert_remove($domain);
            }
            pq("UPDATE payments SET status = 'cancelled', note = 'doména odpojena' WHERE tenant = ? AND kind = 'domain' AND status = 'pending'", [$slug]);
            platform_log('Odpojení vlastní domény', $slug . ' · ' . $domain);
            flash('Vlastní doména je odpojena. Web běží dál na adrese platformy.', 'info');
        }
        redirect('/svj/' . $slug);
    }
    // Ruční náhrada souboru, který starý web nevydal: hlavní správce nahraje soubor místo odkazu.
    if ($METHOD === 'POST' && $action === '/soubor-nahrat') {
        $pdo = tenant_pdo($slug);
        $id = (int) post('doc');
        $f = $_FILES['file'] ?? null;
        $ext = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
        $st = $pdo->prepare('SELECT id, title FROM documents WHERE id = ? AND filename IS NULL');
        $st->execute([$id]);
        $doc = $st->fetch();
        if (!$doc || !$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            flash('Vyberte soubor k nahrání.', 'error');
        } elseif (!in_array($ext, ALLOWED_EXT, true)) {
            flash('Tento typ souboru web neukládá. Povolené: ' . implode(', ', ALLOWED_EXT) . '.', 'error');
        } else {
            $dir = tenant_storage($slug) . '/uploads';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            move_uploaded_file($f['tmp_name'], $dir . '/' . $name);
            $pdo->prepare('UPDATE documents SET filename = ?, original_name = ?, size = ?, external_url = NULL, content = NULL WHERE id = ?')->execute([$name, mb_substr((string) $f['name'], 0, 200), (int) $f['size'], $id]);
            platform_log('Ruční nahrání souboru ze starého webu', $slug . ' · ' . $doc['title']);
            flash('Soubor k dokumentu „' . $doc['title'] . '“ je nahraný.');
        }
        redirect('/svj/' . $slug . '#soubory');
    }
    if ($METHOD === 'POST' && $action === '/soubor-smazat') {
        $pdo = tenant_pdo($slug);
        $st = $pdo->prepare('SELECT id, title FROM documents WHERE id = ? AND filename IS NULL');
        $st->execute([(int) post('doc')]);
        if ($doc = $st->fetch()) {
            $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$doc['id']]);
            platform_log('Odebrání dokumentu bez souboru', $slug . ' · ' . $doc['title']);
            flash('Dokument „' . $doc['title'] . '“ je odebraný.', 'info');
        }
        redirect('/svj/' . $slug . '#soubory');
    }
    // Dotažení souborů ze starého webu: dokud nejsou na našem serveru, převod není hotový.
    if ($METHOD === 'POST' && $action === '/soubory') {
        @set_time_limit(150);
        [$done, $failed, $left] = import_external_files_into(tenant_pdo($slug), tenant_storage($slug) . '/uploads', 500, 100, post('znovu') === '1');
        $still = external_doc_count(tenant_pdo($slug));
        platform_log('Stažení souborů ze starého webu', $slug . ' · staženo ' . $done . ', nepodařilo se ' . $failed . ', zbývá ' . $still);
        flash('Staženo souborů: ' . $done . '.' . ($left ? ' Zbývá vyzkoušet ' . $left . ' – klikněte znovu.' : '') . (!$left && $still ? ' ' . $still . ' souborů se stáhnout nepodařilo – seznam s důvody je níže; nahrajte je ručně, nebo dokument odeberte.' : '') . (!$still ? ' Všechny dokumenty jsou uložené na našem serveru.' : ''), $still ? 'info' : 'ok');
        redirect('/svj/' . $slug . '#soubory');
    }
    if ($METHOD === 'POST' && $action === '/import') {
        $f = $_FILES['file'] ?? null;
        $data = $f && ($f['error'] ?? 1) === UPLOAD_ERR_OK && $f['size'] <= 5 * 1048576 ? json_decode((string) file_get_contents($f['tmp_name']), true) : null;
        $result = is_array($data) ? import_content(tenant_pdo($slug), $data, isset($_POST['replace'])) : 'Vyberte soubor JSON s obsahem k importu (do 5 MB).';
        if (is_string($result)) {
            flash($result, 'error');
        } else {
            // Název z importu se propíše i do seznamu společenství.
            $ts = tenant_settings($slug);
            pq('UPDATE tenants SET name = ?, title = ?, accent = ?, tagline = ?, "full" = ?, address = ? WHERE slug = ?',
                [$ts['site_name'] ?? $t['name'], $ts['site_title'] ?? $t['title'], $ts['site_accent'] ?? $t['accent'], $ts['site_tagline'] ?? $t['tagline'], $ts['site_full'] ?? $t['full'], $ts['address'] ?? $t['address'], $slug]);
            // Soubory se stáhnou hned – převzatý dokument nesmí zůstat odkazem na starý web.
            @set_time_limit(150);
            [$got, $bad] = import_external_files_into(tenant_pdo($slug), tenant_storage($slug) . '/uploads', 500, 100);
            $still = external_doc_count(tenant_pdo($slug));
            platform_log('Import obsahu', $slug . ' · aktualit ' . $result['posts'] . ', dokumentů ' . $result['documents'] . ', kontaktů ' . $result['contacts'] . ' · staženo souborů ' . $got . ($still ? ', zbývá ' . $still : ''));
            flash('Naimportováno: ' . $result['posts'] . ' aktualit, ' . $result['documents'] . ' dokumentů a ' . $result['contacts'] . ' kontaktů. Staženo souborů: ' . $got . '.' . ($still ? ' Zbývá stáhnout ' . $still . ' souborů – dokončete to tlačítkem „Stáhnout soubory“ níže; do té doby převod není hotový.' : ' Všechny soubory jsou uložené na našem serveru.'), $still ? 'info' : 'ok');
        }
        redirect('/svj/' . $slug);
    }
    // Podpora: vstup do webu jako jeho uživatel, odkaz na nové heslo, vypnutí dvoufázového ověření.
    if ($METHOD === 'POST' && in_array($action, ['/vstoupit', '/heslo', '/overeni'], true)) {
        $pdo = tenant_pdo($slug);
        $find = $pdo->prepare(post('email') !== '' ? 'SELECT * FROM users WHERE email = ? AND active = 1' : 'SELECT * FROM users WHERE id = ? AND active = 1');
        $find->execute([post('email') !== '' ? post('email') : (int) post('user')]);
        $u = $find->fetch();
        $reason = mb_substr(trim(post('reason')), 0, 200);
        if (!$u) {
            flash('Takový aktivní účet na webu tohoto společenství není.', 'error');
        } elseif ($action === '/vstoupit') {
            if (mb_strlen($reason) < 3) {
                flash('Napište důvod vstupu (například číslo požadavku nebo kdo o pomoc požádal). Zapíše se do záznamů společenství.', 'error');
                $_SESSION['support_pick'] = (int) $u['id'];
            } else {
                $token = bin2hex(random_bytes(32));
                tenant_set($slug, 'support_login', json_encode(['hash' => hash('sha256', $token), 'expires' => time() + 120, 'uid' => (int) $u['id'], 'by' => $super['email'], 'reason' => $reason], JSON_UNESCAPED_UNICODE));
                platform_log('Vstup podpory do webu', $slug . ' · jako ' . $u['email'] . ' · ' . $reason);
                // Prohlížeč nedovolí formuláři přesměrovat na jinou adresu, proto odkaz ukážeme jako tlačítko.
                $_SESSION['tenant_enter'] = ['name' => $u['name'], 'link' => tenant_url($t, '/podpora-vstup/' . $token)];
            }
        } elseif ($action === '/heslo') {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE users SET invite_hash = ?, invite_expires = ? WHERE id = ?')->execute([hash('sha256', $token), date('Y-m-d H:i:s', time() + 86400), $u['id']]);
            $link = tenant_url($t, ($u['password_hash'] === NO_PASSWORD ? '/pozvanka/' : '/obnova-hesla/') . $token);
            $sent = platform_mail($u['email'], 'Nové heslo na webu ' . $t['name'], "Dobrý den,\n\nna Vaši žádost posíláme odkaz, kterým si nastavíte nové heslo k účtu na webu " . $t['name'] . " (platí 24 hodin):\n" . $link
                . "\n\nDokud si nové heslo nenastavíte, platí to dosavadní. Pokud jste o nové heslo nežádali, zprávu ignorujte.");
            platform_log('Odkaz na nové heslo', $slug . ' · ' . $u['email'] . ($sent ? '' : ' · e-mail se nepodařilo odeslat'));
            $_SESSION['tenant_reset'] = ['name' => $u['name'], 'email' => $u['email'], 'link' => $link, 'sent' => $sent];
        } elseif ((int) $u['totp_enabled'] === 1) {
            $pdo->prepare('UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE id = ?')->execute([$u['id']]);
            platform_mail($u['email'], 'Dvoufázové ověření bylo vypnuto – ' . $t['name'], "Dobrý den,\n\nna Vaši žádost jsme u Vašeho účtu na webu " . $t['name'] . " vypnuli dvoufázové ověření. Přihlásíte se e-mailem a heslem; ověření si pak znovu zapněte v části Můj účet.\n\nPokud jste o to nežádali, ozvěte se nám prosím hned.");
            platform_log('Vypnutí dvoufázového ověření', $slug . ' · ' . $u['email']);
            flash('Dvoufázové ověření účtu ' . $u['email'] . ' je vypnuté. Uživatel o tom dostal e-mail.');
        } else {
            flash('Účet ' . $u['email'] . ' dvoufázové ověření zapnuté nemá.', 'info');
        }
        redirect('/svj/' . $slug . '#podpora');
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
            pq('UPDATE tenants SET name = ?, title = ?, accent = ?, tagline = ?, "full" = ?, address = ?, unit_prefix = ?, entrances = ?, look = ?, style = ?, lang = ?, custom_domain = ?, note = ?, active = ?, billing = ?, trial_ends = ?, paid_until = ?, plan = ?, owner_name = ?, owner_email = ?, reminded = \'\' WHERE slug = ?',
                [$new['name'], $new['title'], $new['accent'], $new['tagline'], $new['full'], $new['address'], $new['unit_prefix'], $new['entrances'], $new['look'], $new['style'], $new['lang'], $new['custom_domain'], $new['note'], $new['active'], $new['billing'], $new['trial_ends'], $new['paid_until'], $new['plan'] ?? '', $new['owner_name'], $new['owner_email'], $slug]);
            // Název a vzhled se zapisují přímo do nastavení webu společenství (jeho správce je může dál měnit).
            foreach (['name', 'title', 'accent', 'tagline', 'full', 'unit_prefix', 'entrances', 'look', 'style', 'lang'] as $k) {
                tenant_set($slug, 'site_' . $k, (string) $new[$k]);
            }
            tenant_set($slug, 'address', $new['address']);
            $billingNote = implode(' ', [$new['billing'], $new['trial_ends'], $new['paid_until']]) !== implode(' ', [$t['billing'], $t['trial_ends'], $t['paid_until']])
                ? ' · předplatné: ' . ($new['billing'] ? 'zkušební doba do ' . ($new['trial_ends'] ?: '—') . ', zaplaceno do ' . ($new['paid_until'] ?: '—') : 'bez placení') : '';
            platform_log('Úprava společenství', $slug . ($new['active'] ? '' : ' · pozastaveno') . $billingNote);
            flash('Uloženo.');
            redirect('/svj/' . $slug);
        }
        $t = $new;
        http_response_code(422);
    } else {
        // Zobrazují se aktuální hodnoty z webu společenství (správce je mohl změnit).
        foreach (['name', 'title', 'accent', 'tagline', 'full', 'unit_prefix', 'entrances', 'look', 'style', 'lang'] as $k) {
            $t[$k] = $settings['site_' . $k] ?? $t[$k];
        }
        $t['address'] = $settings['address'] ?? $t['address'];
    }
    $stats = tenant_stats($slug);
    $invite = $_SESSION['tenant_invite'] ?? null;
    unset($_SESSION['tenant_invite']);
    render('platform/tenant_form', ['title' => $t['name'], 't' => $t, 'errors' => $errors, 'new' => false, 'stats' => $stats,
        'usage' => tenant_usage_report($t), 'externalDocs' => is_dir(tenant_storage($slug)) ? external_doc_list(tenant_pdo($slug)) : [],
        'tenantUsers' => (function () use ($slug) {
            // Přehled účtů webu pro podporu: kdo tam je, s jakou rolí a kdy se naposledy přihlásil. Obsah webu se tu neukazuje.
            $pdo = tenant_pdo($slug);
            $units = [];
            foreach ($pdo->query('SELECT uu.user_id, un.entrance, un.number FROM user_units uu JOIN units un ON un.id = uu.unit_id ORDER BY un.entrance, un.number')->fetchAll() as $r) {
                $units[(int) $r['user_id']][] = ($r['entrance'] !== '' ? $r['entrance'] . '/' : '') . $r['number'];
            }
            $rows = $pdo->query("SELECT id, name, email, role, kind, position, active, last_login_at, totp_enabled, password_hash = '!' AS pending, invite_expires FROM users ORDER BY active DESC, role = 'admin' DESC, last_login_at IS NULL, last_login_at DESC, name")->fetchAll();
            foreach ($rows as &$r) {
                $r['units'] = implode(', ', $units[(int) $r['id']] ?? []);
            }
            return $rows;
        })(),
        'supportUsers' => tenant_pdo($slug)->query("SELECT id, name, email, role, kind, last_login_at, totp_enabled FROM users WHERE active = 1 ORDER BY role = 'admin' DESC, name")->fetchAll(), 'supportPick' => (function () { $r = (int) ($_SESSION['support_pick'] ?? 0); unset($_SESSION['support_pick']); return $r; })(), 'tenantEnter' => (function () { $r = $_SESSION['tenant_enter'] ?? null; unset($_SESSION['tenant_enter']); return $r; })(), 'tenantReset' => (function () { $r = $_SESSION['tenant_reset'] ?? null; unset($_SESSION['tenant_reset']); return $r; })(),
        'setupCode' => $stats['ready'] && !$stats['admins'] ? setup_code(tenant_storage($slug)) : null, 'invite' => $invite,
        'payments' => pq('SELECT * FROM payments WHERE tenant = ? ORDER BY id DESC', [$slug])->fetchAll(),
        'notices' => pq('SELECT * FROM tenant_notices WHERE tenant = ? ORDER BY id DESC LIMIT 50', [$slug])->fetchAll(), 'deleteOn' => tenant_delete_date($t),
        'referrals' => pq('SELECT * FROM referrals WHERE referrer = ? OR referred = ? ORDER BY id DESC', [$slug, $slug])->fetchAll(),
        'bstate' => billing_state($t), 'trials' => array_column(pq('SELECT feature, started, ends FROM feature_trials WHERE tenant = ?', [$slug])->fetchAll(), null, 'feature'),
        'migration' => ($mig = migration_row($slug)), 'migrationPayment' => migration_payment($mig),
        'domainPayment' => pq("SELECT * FROM payments WHERE tenant = ? AND kind = 'domain' ORDER BY id DESC LIMIT 1", [$slug])->fetch() ?: null,
        'certInfo' => cert_info($t['custom_domain'] !== '' ? $t['custom_domain'] : (string) (tenant_row($slug)['domain_request'] ?? '')),
        'domainDns' => ($raw = tenant_row($slug)) && $raw['domain_request'] !== '' ? domain_registered($raw['domain_request']) : null, 'raw' => $raw]);
    exit;
}
if ($METHOD === 'POST' && $PATH === '/demo') {
    // Ukázkový web: založí se (nebo znovu naplní čerstvými daty) pod adresou demo; je jen pro čtení.
    $old = tenant_row('demo');
    if ($old && !$old['demo']) {
        flash('Adresu demo už používá běžné společenství.', 'error');
        redirect('/');
    }
    demo_refresh();
    platform_log($old ? 'Obnovení ukázkového webu' : 'Založení ukázkového webu', 'demo');
    flash('Ukázkový web je připraven a úvodní stránka na něj odkazuje.');
    redirect('/');
}
if (preg_match('~^/platby/(\d+)/doklad$~', $PATH, $m)) {
    $p = pq('SELECT * FROM payments WHERE id = ?', [$m[1]])->fetch();
    $d = $p ? invoice_view($p) : null;
    if (!$d) {
        http_response_code(404);
        render('platform/message', ['title' => 'Doklad nenalezen', 'message' => 'K této platbě doklad není.']);
        exit;
    }
    render('billing_doc', ['title' => $d['paid'] ? 'Faktura ' . $p['invoice_no'] : 'Zálohová faktura ' . $p['vs'], 'd' => $d, 'backUrl' => url('/platby')]);
    exit;
}
if ($PATH === '/platby' || preg_match('~^/platby/(\d+)/(potvrdit|zrusit)$~', $PATH, $m)) {
    if ($METHOD === 'POST' && !empty($m)) {
        $p = pq('SELECT * FROM payments WHERE id = ?', [$m[1]])->fetch();
        if (!$p || !in_array($p['status'], ['pending', 'claimed'], true)) {
            flash('Tuto platbu už nelze změnit.', 'error');
        } elseif ($m[2] === 'potvrdit') {
            $period = billing_confirm($p, $super['email']);
            if ($period) {
                platform_log('Potvrzení platby', $p['tenant'] . ' · ' . $p['amount'] . ' Kč · VS ' . $p['vs'] . ' · zaplaceno do ' . $period['to']);
                flash(($p['kind'] ?? '') === 'migration' ? 'Platba potvrzena. Převod obsahu teď čeká na provedení – najdete ho u společenství.' : 'Platba potvrzena, zaplaceno do ' . cz_date($period['to']) . '.');
            } else {
                flash('Společenství k této platbě už neexistuje.', 'error');
            }
        } else {
            pq("UPDATE payments SET status = 'cancelled', confirmed_by = ? WHERE id = ?", [$super['email'], $p['id']]);
            platform_log('Zrušení výzvy k platbě', $p['tenant'] . ' · VS ' . $p['vs']);
            flash('Výzva k platbě byla zrušena.', 'info');
        }
        redirect('/platby');
    }
    render('platform/payments', ['title' => 'Platby', 'rows' => pq("SELECT p.*, t.name AS tenant_name FROM payments p LEFT JOIN tenants t ON t.slug = p.tenant
        ORDER BY CASE p.status WHEN 'claimed' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END, p.id DESC LIMIT 500")->fetchAll()]);
    exit;
}
if ($PATH === '/nastaveni') {
    $errors = [];
    if ($METHOD === 'POST') {
        $in = [];
        foreach (array_keys(PLATFORM_DEFAULTS) as $k) {
            $in[$k] = post($k);
        }
        $in['signup_open'] = isset($_POST['signup_open']) ? '1' : '0';
        $in['demo_auto'] = isset($_POST['demo_auto']) ? '1' : '0';
        $in['vat_payer'] = isset($_POST['vat_payer']) ? '1' : '0';
        $in['legal_checked'] = isset($_POST['legal_checked']) ? '1' : '0';
        if ($in['vat_payer'] === '1' && trim($in['op_dic']) === '') {
            $errors[] = 'Plátce DPH musí mít vyplněné DIČ.';
        }
        // Výchozí poznámka „není plátcem“ u plátce nedává smysl.
        if ($in['vat_payer'] === '1' && $in['invoice_note'] === PLATFORM_DEFAULTS['invoice_note']) {
            $in['invoice_note'] = '';
        }
        $in['period'] = 'year';
        foreach ((['price_domain' => 'Cena vlastní domény', 'grace_days' => 'Lhůta po splatnosti', 'delete_days' => 'Lhůta pro smazání', 'invoice_due_days' => 'Splatnost zálohové faktury', 'price_standard' => 'Cena edice Standard', 'vat_rate' => 'Sazba DPH', 'referral_reward' => 'Odměna za doporučení', 'referral_welcome' => 'Sleva pro doporučeného', 'storage_pack_mb' => 'Velikost balíčku úložiště', 'price_storage' => 'Cena balíčku úložiště', 'ai_pack' => 'Velikost balíčku AI kreditů', 'price_ai_pack' => 'Cena balíčku AI kreditů', 'demo_refresh_days' => 'Obnova ukázkového webu', 'price_migration' => 'Cena převodu obsahu', 'price_premium' => 'Cena edice Premium'] + array_combine(array_map(fn($k) => $k, array_filter(array_keys(PLATFORM_DEFAULTS), fn($k) => str_starts_with($k, 'lim_'))), array_map(fn($k) => 'Limit ' . $k, array_filter(array_keys(PLATFORM_DEFAULTS), fn($k) => str_starts_with($k, 'lim_'))))) as $k => $label) {
            if (!ctype_digit($in[$k]) || (int) $in[$k] > 1000000) {
                $errors[] = $label . ': zadejte celé číslo.';
            }
        }
        foreach (['contact_email' => 'Kontaktní e-mail', 'mail_from' => 'Odesílatel e-mailů'] as $k => $label) {
            if ($in[$k] !== '' && !filter_var($in[$k], FILTER_VALIDATE_EMAIL)) {
                $errors[] = $label . ' nemá správný tvar.';
            }
        }
        if ($in['account'] !== '' && !cz_iban($in['account'])) {
            $errors[] = 'Číslo účtu není platné. Zadejte ho ve tvaru 19-2000145399/0800 nebo jako IBAN (CZ…).';
        }
        if ($in['service_name'] === '') {
            $errors[] = 'Vyplňte název služby.';
        }
        if (!$errors) {
            $changed = [];
            foreach ($in as $k => $v) {
                if ($v !== pset($k)) {
                    $changed[] = $k;
                    pset_save($k, $v);
                }
            }
            if (array_intersect($changed, ['vat_payer', 'vat_rate'])) {
                // Platby, které ještě čekají na úhradu, se přepočtou podle nového stavu; zaplacené se nemění.
                pq("UPDATE payments SET vat_rate = ? WHERE status IN ('pending', 'claimed')", [$in['vat_payer'] === '1' ? (int) $in['vat_rate'] : 0]);
            }
            platform_log('Změna nastavení služby', implode(', ', $changed));
            flash('Nastavení služby je uloženo.');
            redirect('/nastaveni');
        }
        http_response_code(422);
    }
    $values = [];
    foreach (array_keys(PLATFORM_DEFAULTS) as $k) {
        $values[$k] = $METHOD === 'POST' ? ($in[$k] ?? '') : pset($k);
    }
    render('platform/settings', ['title' => 'Nastavení služby', 'errors' => $errors, 'v' => $values]);
    exit;
}
if ($PATH === '/pristup-ai') {
    // Klíč pro MCP server hlášení: ukáže se jen jednou, uloží se jen jeho otisk.
    $newKey = null;
    if ($METHOD === 'POST' && post('do') === 'new') {
        $newKey = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
        pset_save('mcp_token_hash', hash('sha256', $newKey));
        pset_save('mcp_token_created', date('Y-m-d H:i'));
        pset_save('mcp_token_last', '');
        platform_log('Přístup pro AI: nový klíč');
    } elseif ($METHOD === 'POST' && post('do') === 'off') {
        pset_save('mcp_token_hash', '');
        platform_log('Přístup pro AI: vypnut');
        flash('Přístup pro AI je vypnutý. Dosavadní klíč už nefunguje.');
        redirect('/pristup-ai');
    }
    render('platform/ai_access', ['title' => 'Přístup pro AI k hlášením', 'newKey' => $newKey, 'on' => pset('mcp_token_hash') !== '',
        'created' => pset('mcp_token_created'), 'last' => pset('mcp_token_last'), 'endpoint' => report_page_url('/mcp')]);
    exit;
}
if ($PATH === '/hlaseni' || str_starts_with($PATH, '/hlaseni/')) {
    report_admin_page('/hlaseni', substr($PATH, 8), $METHOD, $super['email']);
    exit;
}
if ($PATH === '/zajem') {
    $days = in_array((int) ($_GET['dni'] ?? 0), [7, 30, 90], true) ? (int) $_GET['dni'] : 0;
    render('platform/interest', ['title' => 'Zájem o funkce', 'trials' => pq('SELECT feature, COUNT(*) AS n, SUM(ends >= ?) AS running FROM feature_trials GROUP BY feature ORDER BY n DESC', [date('Y-m-d')])->fetchAll(), 'stats' => feature_stats($days), 'days' => $days,
        'rows' => pq("SELECT i.*, t.name AS tenant_name FROM feature_interest i LEFT JOIN tenants t ON t.slug = i.tenant ORDER BY i.id DESC LIMIT 300")->fetchAll(),
        'tenantViews' => pq("SELECT v.feature, v.tenant, t.name AS tenant_name, COUNT(*) AS n, MAX(v.at) AS last FROM feature_views v LEFT JOIN tenants t ON t.slug = v.tenant WHERE v.tenant <> '' GROUP BY v.feature, v.tenant ORDER BY last DESC LIMIT 200")->fetchAll()]);
    exit;
}
if ($PATH === '/zaznamy') {
    render('platform/log', ['title' => 'Záznamy platformy', 'rows' => pq('SELECT * FROM platform_log ORDER BY id DESC LIMIT 500')->fetchAll(),
        'notices' => pq('SELECT * FROM tenant_notices ORDER BY id DESC LIMIT 200')->fetchAll()]);
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
