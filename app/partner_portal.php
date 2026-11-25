<?php
declare(strict_types=1);

// Partnerský portál správcovských firem (/partner na hlavní doméně): domy firmy, zakládání webů, společná faktura, tým.

$GLOBALS['LAYOUT'] = 'partner/layout';
$logout = function (): void {
    unset($_SESSION['pid'], $_SESSION['ppv'], $_SESSION['pseen'], $_SESSION['ppending']);
};
$pu = empty($_SESSION['pid']) ? null : (pq('SELECT * FROM partner_users WHERE id = ? AND active = 1', [$_SESSION['pid']])->fetch() ?: null);
$partner = $pu ? partner_row((int) $pu['partner_id']) : null;
if ($pu && (!$partner || !$partner['active'] || !hash_equals(pw_fingerprint($pu['password_hash']), (string) ($_SESSION['ppv'] ?? '')) || time() - ($_SESSION['pseen'] ?? 0) > IDLE_LIMIT)) {
    $pu = $partner = null;
    $logout();
}
if ($pu) {
    $_SESSION['pseen'] = time();
    header('Cache-Control: no-store, private');
}
$GLOBALS['PARTNER_USER'] = $pu;
$GLOBALS['PARTNER'] = $partner;
$plog = fn(string $action, string $detail = '') => pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)',
    [date('Y-m-d H:i:s'), $pu['email'] ?? '', client_ip(), 'Partner' . ($partner ? ' ' . $partner['name'] : '') . ': ' . $action, mb_substr($detail, 0, 500)]);
$blocked = function (): bool {
    pq('DELETE FROM attempts WHERE at < ?', [time() - 900]);
    return (int) pq('SELECT COUNT(*) FROM attempts WHERE ip = ?', [client_ip()])->fetchColumn() >= 8;
};
$failed = fn() => pq('INSERT INTO attempts (ip, at) VALUES (?,?)', [client_ip(), time()]);
$signIn = function (array $u) use ($logout): never {
    session_regenerate_id(true);
    $logout();
    $_SESSION['t'] = '#platform';
    $_SESSION['pid'] = (int) $u['id'];
    $_SESSION['ppv'] = pw_fingerprint($u['password_hash']);
    $_SESSION['pseen'] = time();
    pq('UPDATE partner_users SET last_login_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $u['id']]);
    pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), $u['email'], client_ip(), 'Partner: přihlášení', '']);
    redirect('/');
};

// ---------- Bez přihlášení: pozvánka, přihlášení, ověření ----------
if (preg_match('~^/pozvanka/([a-f0-9]{64})$~', $PATH, $m)) {
    $u = pq('SELECT * FROM partner_users WHERE invite_hash = ? AND invite_expires > ? AND active = 1', [hash('sha256', $m[1]), date('Y-m-d H:i:s')])->fetch();
    $errors = [];
    if ($u && $METHOD === 'POST') {
        $pass = (string) ($_POST['password'] ?? '');
        if ($problem = password_problem($pass, $u['email'], $u['name'])) {
            $errors[] = $problem;
        } elseif ($pass !== (string) ($_POST['password2'] ?? '')) {
            $errors[] = 'Hesla se neshodují.';
        } else {
            pq("UPDATE partner_users SET password_hash = ?, invite_hash = '', invite_expires = '' WHERE id = ?", [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            $signIn(pq('SELECT * FROM partner_users WHERE id = ?', [$u['id']])->fetch());
        }
        http_response_code(422);
    }
    render('partner/login', ['title' => 'Nastavení hesla', 'mode' => $u ? 'invite' : 'invite-bad', 'error' => $errors ? implode(' ', $errors) : null, 'invitee' => $u]);
    exit;
}
if (!$pu) {
    $error = null;
    $pending = $_SESSION['ppending'] ?? null;
    if ($PATH === '/overeni' && $pending && time() - $pending['at'] < 300) {
        $u = pq('SELECT * FROM partner_users WHERE id = ? AND active = 1', [$pending['id']])->fetch();
        if ($METHOD === 'POST' && $u) {
            $step = $blocked() ? null : totp_verify((string) $u['totp_secret'], post('code'), (int) $u['totp_last']);
            if ($step !== null) {
                pq('UPDATE partner_users SET totp_last = ? WHERE id = ?', [$step, $u['id']]);
                $signIn($u);
            }
            $failed();
            $error = 'Kód není správný nebo už vypršel.';
            http_response_code(422);
        }
        render('partner/login', ['title' => 'Ověření přihlášení', 'mode' => 'verify', 'error' => $error]);
        exit;
    }
    if ($METHOD === 'POST' && $PATH === '/prihlaseni') {
        if ($blocked()) {
            $error = 'Příliš mnoho neúspěšných pokusů. Zkuste to znovu za 15 minut.';
        } else {
            $u = pq('SELECT u.* FROM partner_users u JOIN partners p ON p.id = u.partner_id WHERE u.email = ? AND u.active = 1 AND p.active = 1', [post('email')])->fetch();
            if (!$u || !password_verify((string) ($_POST['password'] ?? ''), $u['password_hash'])) {
                $failed();
                pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), '', client_ip(), 'Partner: neúspěšné přihlášení', post('email')]);
                $error = 'Nesprávný e-mail nebo heslo.';
            } elseif ($u['totp_enabled']) {
                session_regenerate_id(true);
                $_SESSION['ppending'] = ['id' => (int) $u['id'], 'at' => time()];
                redirect('/overeni');
            } else {
                $signIn($u);
            }
        }
        http_response_code(422);
    }
    if ($PATH !== '/' && $PATH !== '/prihlaseni') {
        redirect('/');
    }
    render('partner/login', ['title' => 'Přihlášení', 'mode' => 'login', 'error' => $error]);
    exit;
}

// ---------- Přihlášený pracovník partnera ----------
$owner = $pu['role'] === 'owner';
if ($METHOD === 'POST' && $PATH === '/odhlaseni') {
    $plog('odhlášení');
    $logout();
    session_regenerate_id(true);
    redirect('/');
}
if ($PATH === '/ares' && $METHOD === 'GET') {
    ares_lookup_json(mb_substr((string) ($_GET['q'] ?? ''), 0, 120));
    exit;
}
if ($PATH === '/') {
    $houses = partner_tenants((int) $partner['id'], '');
    foreach ($houses as &$h) {
        $h['stats'] = $h['partner_status'] === 'active' ? partner_house_stats($h['slug']) : null;
        $h['billing_state'] = billing_state($h);
    }
    unset($h);
    $enter = $_SESSION['partner_enter_error'] ?? null;
    unset($_SESSION['partner_enter_error']);
    render('partner/dashboard', ['title' => 'Domy ve správě', 'partner' => $partner, 'houses' => $houses, 'enterError' => $enter,
        'discount' => partner_discount(count(array_filter($houses, fn($h) => $h['partner_status'] === 'active')))]);
    exit;
}
// Vstup do administrace domu: jednorázový odkaz na web domu (běžný odkaz, ne formulář – prohlížeč by přesměrování formuláře na jinou adresu zablokoval).
if (preg_match('~^/domy/([a-z0-9][a-z0-9-]{1,38})/vstoupit$~', $PATH, $m) && $METHOD === 'GET') {
    $t = tenant_row($m[1]);
    $r = $t ? partner_enter_link($pu, $partner, $t) : ['error' => 'Dům nenalezen.'];
    if (isset($r['error'])) {
        $_SESSION['partner_enter_error'] = $r['error'];
        redirect('/');
    }
    $plog('vstup do domu', $t['slug']);
    header('Location: ' . $r['link'], true, 302);
    exit;
}
if (preg_match('~^/domy/([a-z0-9][a-z0-9-]{1,38})/zrusit-zadost$~', $PATH, $m) && $METHOD === 'POST') {
    $t = tenant_row($m[1]);
    if ($t && (int) $t['partner_id'] === (int) $partner['id'] && $t['partner_status'] === 'pending') {
        pq("UPDATE tenants SET partner_id = 0, partner_status = '', partner_since = '' WHERE slug = ?", [$t['slug']]);
        $plog('zrušení žádosti o přístup', $t['slug']);
        flash('Žádost o přístup je zrušená.', 'info');
    }
    redirect('/');
}
if ($PATH === '/domy/novy') {
    $in = ['name' => '', 'title' => '', 'full' => '', 'address' => '', 'ico' => '', 'slug' => '', 'contact_name' => '', 'contact_email' => ''];
    $errors = [];
    if ($METHOD === 'POST') {
        foreach ($in as $k => $_) {
            $in[$k] = trim(post($k));
        }
        $in['ico'] = preg_replace('/\s+/', '', $in['ico']);
        $in['slug'] = slug_input($in['slug'] !== '' ? $in['slug'] : ($in['title'] !== '' ? $in['title'] : $in['name']));
        if ($in['name'] === '' || $in['title'] === '') {
            $errors[] = 'Vyplňte název společenství a krátký název na web.';
        }
        if ($in['ico'] !== '' && ares_ico($in['ico']) === null) {
            $errors[] = 'IČO není platné – zkontrolujte ho, nebo pole nechte prázdné.';
        }
        if ($problem = slug_problem($in['slug'])) {
            $errors[] = 'Adresa webu: ' . $problem;
        }
        if ($in['contact_email'] !== '' && (!filter_var($in['contact_email'], FILTER_VALIDATE_EMAIL) || $in['contact_name'] === '')) {
            $errors[] = 'U kontaktu na výbor vyplňte jméno i platný e-mail, nebo obě pole nechte prázdná.';
        }
        if (!$errors) {
            tenant_create(['slug' => $in['slug'], 'name' => $in['name'], 'title' => $in['title'], 'full' => $in['full'], 'address' => $in['address'], 'billing' => 1,
                'terms_at' => date('Y-m-d H:i:s'), 'owner_name' => $partner['name'], 'owner_email' => $partner['email'], 'note' => 'založil správce ' . $partner['name'] . ' (' . $pu['email'] . ')']);
            if ($in['ico'] !== '') {
                tenant_set($in['slug'], 'ico', $in['ico']);
            }
            if ($in['address'] !== '') {
                tenant_set($in['slug'], 'address', $in['address']);
            }
            partner_attach_created($partner, $in['slug']);
            $t = tenant_row($in['slug']);
            if ($in['contact_email'] !== '') {
                $token = bin2hex(random_bytes(32));
                tenant_pdo($in['slug'])->prepare("INSERT INTO users (name, email, role, kind, password_hash, invite_hash, invite_expires) VALUES (?,?,'admin','owner',?,?,?)")
                    ->execute([$in['contact_name'], $in['contact_email'], NO_PASSWORD, hash('sha256', $token), date('Y-m-d H:i:s', time() + 14 * 86400)]);
                platform_mail($in['contact_email'], 'Web společenství ' . $in['name'], "Dobrý den,\n\nsprávce domu " . $partner['name'] . " pro Vaše společenství založil web " . tenant_url($t, '/') . ".\n"
                    . "Jako člen výboru do něj máte přístup správce. Heslo si nastavíte tímto odkazem (platí 14 dní):\n" . tenant_url($t, '/pozvanka/' . $token)
                    . "\n\nV administraci webu, v části Správce domu, vidíte, že web spravuje " . $partner['name'] . ", a přístup firmy můžete kdykoli ukončit.");
            }
            $plog('založení webu', $in['slug']);
            flash('Web ' . $in['name'] . ' je založený na adrese ' . tenant_address($in['slug']) . '. Edici objednáte ve Fakturaci; do té doby běží jako Zdarma.');
            redirect('/');
        }
        http_response_code(422);
    }
    render('partner/house_new', ['title' => 'Založit web domu', 'in' => $in, 'errors' => $errors]);
    exit;
}
if ($PATH === '/domy/pripojit') {
    $errors = [];
    if ($METHOD === 'POST') {
        $raw = strtolower(trim(post('address')));
        $host = (string) (parse_url(str_contains($raw, '://') ? $raw : 'https://' . $raw, PHP_URL_HOST) ?: $raw);
        $path = trim((string) parse_url(str_contains($raw, '://') ? $raw : 'https://' . $raw, PHP_URL_PATH), '/');
        $t = tenant_by_host($host) ?? ($path !== '' ? tenant_row(explode('/', $path)[0]) : null) ?? tenant_row($raw);
        if (!$t) {
            $errors[] = 'Web s touto adresou jsme nenašli. Zadejte adresu, kterou společenství používá (např. ' . tenant_address('nazev-domu') . ').';
        } elseif ($problem = partner_request($partner, $t['slug'], post('ico'))) {
            $errors[] = $problem;
        } else {
            $plog('žádost o přístup', $t['slug']);
            flash('Žádost o přístup k webu ' . $t['name'] . ' je odeslaná výboru. Jakmile ji schválí, dům se Vám objeví mezi domy ve správě.');
            redirect('/');
        }
        http_response_code(422);
    }
    render('partner/house_join', ['title' => 'Připojit existující web', 'errors' => $errors]);
    exit;
}
if (preg_match('~^/fakturace/doklad/(\d+)$~', $PATH, $m)) {
    $p = pq("SELECT * FROM payments WHERE id = ? AND partner_id = ? AND kind = 'partner'", [$m[1], $partner['id']])->fetch();
    $d = $p ? invoice_view($p) : null;
    if (!$d) {
        not_found();
    }
    render('billing_doc', ['title' => $d['paid'] ? 'Faktura ' . $p['invoice_no'] : 'Zálohová faktura ' . $p['vs'], 'd' => $d, 'backUrl' => url('/fakturace')]);
    exit;
}
if ($PATH === '/fakturace') {
    $error = null;
    if ($METHOD === 'POST' && post('action') === 'order') {
        $choice = [];
        foreach ((array) ($_POST['houses'] ?? []) as $slug) {
            $choice[(string) $slug] = (string) ($_POST['edition'][$slug] ?? '');
        }
        $r = partner_order($partner, $choice);
        if (is_string($r)) {
            $error = $r;
        } else {
            $plog('vystavení společné faktury', 'VS ' . $r['vs'] . ' · ' . $r['amount'] . ' Kč');
            flash('Zálohová faktura je připravená. Po připsání platby se předplatné domů prodlouží o rok.');
            redirect('/fakturace#platba');
        }
    } elseif ($METHOD === 'POST' && post('action') === 'claim') {
        $open = partner_open_payment((int) $partner['id']);
        if ($open && $open['status'] === 'pending') {
            pq("UPDATE payments SET status = 'claimed', claimed_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $open['id']]);
            $plog('nahlášení platby', 'VS ' . $open['vs']);
            if (pset('contact_email') !== '') {
                platform_mail(pset('contact_email'), 'Partner nahlásil platbu – ' . $partner['name'], $partner['name'] . ' nahlásil odeslání platby ' . pay_money($open) . ', VS ' . $open['vs'] . ".\nPotvrdit: " . platform_url('/sprava/platby'));
            }
            flash('Děkujeme. Jakmile platbu uvidíme na účtu, předplatné domů prodloužíme.');
        }
        redirect('/fakturace#platba');
    } elseif ($METHOD === 'POST' && post('action') === 'cancel') {
        $open = partner_open_payment((int) $partner['id']);
        if ($open && $open['status'] === 'pending') {
            pq("UPDATE payments SET status = 'cancelled', note = 'zrušeno partnerem' WHERE id = ?", [$open['id']]);
            $plog('zrušení zálohové faktury', 'VS ' . $open['vs']);
            flash('Zálohová faktura je zrušená.', 'info');
        }
        redirect('/fakturace');
    }
    $houses = array_values(array_filter(partner_tenants((int) $partner['id']), fn($h) => (int) $h['partner_billing']));
    foreach ($houses as &$h) {
        $h['billing_state'] = billing_state($h);
    }
    unset($h);
    $open = partner_open_payment((int) $partner['id']);
    $iban = cz_iban(pset('account'));
    if ($error) {
        http_response_code(422);
    }
    render('partner/billing', ['title' => 'Fakturace', 'partner' => $partner, 'houses' => $houses, 'error' => $error, 'open' => $open, 'iban' => $iban,
        'qr' => $open && $iban ? qr_svg(spayd($iban, pay_total($open), $open['vs'], 'Sprava domu ' . $partner['name'], pset('recipient'), true), 'QR kód pro okamžitou platbu') : '',
        'discount' => partner_discount(count(partner_tenants((int) $partner['id']))), 'tiers' => partner_discount_tiers(),
        'history' => pq("SELECT * FROM payments WHERE partner_id = ? AND kind = 'partner' AND status IN ('paid','storno') ORDER BY id DESC LIMIT 50", [$partner['id']])->fetchAll()]);
    exit;
}
if ($PATH === '/tym') {
    $newLink = null;
    $errors = [];
    if ($METHOD === 'POST' && $owner) {
        if (post('action') === 'invite') {
            $email = post('email');
            if (post('name') === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Vyplňte jméno a platný e-mail.';
            } elseif (pq('SELECT 1 FROM partner_users WHERE email = ?', [$email])->fetchColumn()) {
                $errors[] = 'Tento e-mail už v partnerském portálu má účet.';
            } else {
                partner_invite($partner, post('name'), $email, post('role') === 'owner' ? 'owner' : 'member');
                $plog('pozvání pracovníka', $email);
                flash('Pozvánka odešla na ' . $email . '.');
                redirect('/tym');
            }
        } elseif (post('action') === 'disable' && (int) post('id') !== (int) $pu['id']) {
            pq('UPDATE partner_users SET active = 0 WHERE id = ? AND partner_id = ?', [(int) post('id'), $partner['id']]);
            $plog('vypnutí účtu pracovníka', (string) (int) post('id'));
            flash('Účet je vypnutý. Do portálu ani do domů se už nepřihlásí.', 'info');
            redirect('/tym');
        }
        http_response_code(422);
    }
    render('partner/team', ['title' => 'Tým', 'partner' => $partner, 'owner' => $owner, 'errors' => $errors, 'me' => $pu,
        'people' => pq('SELECT * FROM partner_users WHERE partner_id = ? ORDER BY active DESC, name', [$partner['id']])->fetchAll()]);
    exit;
}
if ($PATH === '/ucet') {
    $errors = [];
    if ($METHOD === 'POST') {
        $action = post('action');
        if ($action === 'password') {
            $new = (string) ($_POST['new_password'] ?? '');
            if (!password_verify((string) ($_POST['current_password'] ?? ''), $pu['password_hash'])) {
                $errors[] = 'Současné heslo není správně.';
            } elseif ($problem = password_problem($new, $pu['email'], $pu['name'])) {
                $errors[] = $problem;
            } elseif ($new !== (string) ($_POST['new_password2'] ?? '')) {
                $errors[] = 'Nová hesla se neshodují.';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                pq('UPDATE partner_users SET password_hash = ? WHERE id = ?', [$hash, $pu['id']]);
                $_SESSION['ppv'] = pw_fingerprint($hash);
                $plog('změna hesla');
                flash('Heslo bylo změněno.');
                redirect('/ucet');
            }
        } elseif ($action === 'totp_start' && !$pu['totp_enabled']) {
            $_SESSION['ptotp_setup'] = totp_new_secret();
            redirect('/ucet');
        } elseif ($action === 'totp_confirm' && !$pu['totp_enabled'] && !empty($_SESSION['ptotp_setup'])) {
            $step = totp_verify($_SESSION['ptotp_setup'], post('code'));
            if ($step === null) {
                $errors[] = 'Kód nesouhlasí. Zadejte aktuální kód z aplikace.';
            } else {
                pq('UPDATE partner_users SET totp_secret = ?, totp_enabled = 1, totp_last = ? WHERE id = ?', [$_SESSION['ptotp_setup'], $step, $pu['id']]);
                unset($_SESSION['ptotp_setup']);
                $plog('zapnutí dvoufázového ověření');
                flash('Dvoufázové ověření je zapnuté.');
                redirect('/ucet');
            }
        } elseif ($action === 'totp_disable' && $pu['totp_enabled'] && password_verify((string) ($_POST['current_password'] ?? ''), $pu['password_hash'])) {
            pq('UPDATE partner_users SET totp_secret = NULL, totp_enabled = 0, totp_last = 0 WHERE id = ?', [$pu['id']]);
            $plog('vypnutí dvoufázového ověření');
            flash('Dvoufázové ověření bylo vypnuto.', 'info');
            redirect('/ucet');
        }
        if ($errors) {
            http_response_code(422);
        }
    }
    render('partner/account', ['title' => 'Můj účet', 'errors' => $errors, 'me' => $pu, 'partner' => $partner, 'totpSetup' => $pu['totp_enabled'] ? null : ($_SESSION['ptotp_setup'] ?? null)]);
    exit;
}
not_found();
