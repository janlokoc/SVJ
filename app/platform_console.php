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
        'billing' => isset($_POST['billing']) ? 1 : 0, 'trial_ends' => valid_date(post('trial_ends')) ? post('trial_ends') : '', 'paid_until' => valid_date(post('paid_until')) ? post('paid_until') : '',
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
            platform_log('Založení společenství', $t['slug'] . ' · ' . $t['name']);
            flash('Web společenství je založen. Předejte jeho výboru adresu a instalační kód, nebo mu rovnou vytvořte účet správce.');
            redirect('/svj/' . $t['slug']);
        }
        http_response_code(422);
    }
    render('platform/tenant_form', ['title' => 'Nové společenství', 't' => $t, 'errors' => $errors, 'new' => true]);
    exit;
}
if (preg_match('~^/svj/([a-z0-9][a-z0-9-]{1,38})(/spravce|/smazat|/import|/domena|/certifikat)?$~', $PATH, $m) && ($t = tenant_row($m[1]))) {
    $slug = $t['slug'];
    $action = $m[2] ?? '';
    if ($METHOD === 'POST' && $action === '/smazat') {
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
            platform_log('Import obsahu', $slug . ' · aktualit ' . $result['posts'] . ', dokumentů ' . $result['documents'] . ', kontaktů ' . $result['contacts']);
            flash('Naimportováno: ' . $result['posts'] . ' aktualit, ' . $result['documents'] . ' dokumentů a ' . $result['contacts'] . ' kontaktů. Dokumenty zatím odkazují na původní web; správce společenství je stáhne v administraci na stránce Přehled.');
        }
        redirect('/svj/' . $slug);
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
            pq('UPDATE tenants SET name = ?, title = ?, accent = ?, tagline = ?, "full" = ?, address = ?, unit_prefix = ?, entrances = ?, look = ?, style = ?, lang = ?, custom_domain = ?, note = ?, active = ?, billing = ?, trial_ends = ?, paid_until = ?, owner_name = ?, owner_email = ?, reminded = \'\' WHERE slug = ?',
                [$new['name'], $new['title'], $new['accent'], $new['tagline'], $new['full'], $new['address'], $new['unit_prefix'], $new['entrances'], $new['look'], $new['style'], $new['lang'], $new['custom_domain'], $new['note'], $new['active'], $new['billing'], $new['trial_ends'], $new['paid_until'], $new['owner_name'], $new['owner_email'], $slug]);
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
        'setupCode' => $stats['ready'] && !$stats['admins'] ? setup_code(tenant_storage($slug)) : null, 'invite' => $invite,
        'payments' => pq('SELECT * FROM payments WHERE tenant = ? ORDER BY id DESC', [$slug])->fetchAll(),
        'notices' => pq('SELECT * FROM tenant_notices WHERE tenant = ? ORDER BY id DESC LIMIT 50', [$slug])->fetchAll(), 'deleteOn' => tenant_delete_date($t),
        'domainPayment' => pq("SELECT * FROM payments WHERE tenant = ? AND kind = 'domain' ORDER BY id DESC LIMIT 1", [$slug])->fetch() ?: null,
        'certInfo' => cert_info($t['custom_domain'] !== '' ? $t['custom_domain'] : (string) (tenant_row($slug)['domain_request'] ?? '')),
        'domainDns' => ($raw = tenant_row($slug)) && $raw['domain_request'] !== '' ? domain_registered($raw['domain_request']) : null, 'raw' => $raw]);
    exit;
}
if ($METHOD === 'POST' && $PATH === '/demo') {
    // Ukázkový web: založí se (nebo znovu naplní čerstvými daty) pod adresou demo; je jen pro čtení.
    if ($old = tenant_row('demo')) {
        if (!$old['demo']) {
            flash('Adresu demo už používá běžné společenství.', 'error');
            redirect('/');
        }
        tenant_delete_storage('demo');
        pq('DELETE FROM tenants WHERE slug = ?', ['demo']);
    }
    demo_create();
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
                flash('Platba potvrzena, předplatné je uhrazeno do ' . cz_date($period['to']) . '.');
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
        $in['period'] = $in['period'] === 'year' ? 'year' : 'month';
        foreach (['price_ai' => 'Cena AI Plus', 'ai_free_limit' => 'Limit AI v ceně', 'ai_plus_limit' => 'Limit AI Plus', 'price_domain' => 'Cena vlastní domény', 'trial_days' => 'Délka zkušební doby', 'grace_days' => 'Lhůta po splatnosti', 'delete_days' => 'Lhůta pro smazání', 'invoice_due_days' => 'Splatnost zálohové faktury', 'price_first' => 'Cena v prvním roce', 'price_next' => 'Cena v dalších letech'] as $k => $label) {
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
if ($PATH === '/hlaseni' || str_starts_with($PATH, '/hlaseni/')) {
    report_admin_page('/hlaseni', substr($PATH, 8), $METHOD, $super['email']);
    exit;
}
if ($PATH === '/zajem') {
    render('platform/interest', ['title' => 'Zájem o funkce', 'counts' => interest_counts(),
        'rows' => pq("SELECT i.*, t.name AS tenant_name FROM feature_interest i LEFT JOIN tenants t ON t.slug = i.tenant ORDER BY i.id DESC LIMIT 300")->fetchAll()]);
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
