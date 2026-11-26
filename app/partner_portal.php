<?php
declare(strict_types=1);

// Partnerský portál správcovských firem (/partner na hlavní doméně): domy firmy, zakládání webů, společná faktura, tým.

$GLOBALS['LAYOUT'] = 'partner/layout';
$logout = function (): void {
    unset($_SESSION['pid'], $_SESSION['ppv'], $_SESSION['pseen'], $_SESSION['ppending'], $_SESSION['pdemo']);
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

// ---------- Ukázka: vstup jedním kliknutím jako ukázková správcovská firma (jen pro čtení) ----------
if ($PATH === '/ukazka' && $METHOD === 'GET') {
    $du = partner_demo_ensure();
    if (!$du) {
        redirect('/');
    }
    session_regenerate_id(true);
    $logout();
    $_SESSION['t'] = '#platform';
    $_SESSION['pid'] = (int) $du['id'];
    $_SESSION['ppv'] = pw_fingerprint($du['password_hash']);
    $_SESSION['pseen'] = time();
    $_SESSION['pdemo'] = 1;
    redirect('/');
}
$GLOBALS['PARTNER_DEMO'] = $pu && !empty($_SESSION['pdemo']) && (int) $pu['partner_id'] === partner_demo_id();

// Odběr kalendáře (Google, Outlook, telefon): tajný odkaz pracovníka, bez přihlášení.
if ($PATH === '/kalendar.ics' && preg_match('/^[a-f0-9]{40}$/', (string) ($_GET['t'] ?? ''))) {
    $cu = pq("SELECT u.* FROM partner_users u JOIN partners p ON p.id = u.partner_id WHERE u.cal_token = ? AND u.active = 1 AND p.active = 1", [$_GET['t']])->fetch();
    if (!$cu) {
        http_response_code(404);
        exit;
    }
    $cp = partner_row((int) $cu['partner_id']);
    send_ics(partner_calendar_ics($cp, partner_calendar(partner_tenants((int) $cp['id']), date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('+365 days')))), 'domy.ics');
}

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
if ($METHOD === 'POST' && $GLOBALS['PARTNER_DEMO'] && $PATH !== '/odhlaseni') {
    flash('V ukázce se změny neukládají. Ve Vašem portálu by se tato akce provedla.', 'info');
    $ref = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
    $ref = str_starts_with($ref, $BASE) ? (string) substr($ref, strlen($BASE)) : '/';
    redirect(preg_match('~^/(?![/\\\\])~', $ref) ? $ref : '/');
}
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
    if ($GLOBALS['PARTNER_DEMO']) {
        // Ukázka: domy jsou jen pro čtení – vstup jako ukázkový správce bez zápisu do databáze domu.
        if (!$t || (int) $t['partner_id'] !== (int) $partner['id'] || empty($t['demo'])) {
            redirect('/');
        }
        header('Location: ' . tenant_url($t, '/demo-vstup' . (partner_next() !== '' ? '?k=' . rawurlencode(partner_next()) : '')), true, 302);
        exit;
    }
    $r = $t ? partner_enter_link($pu, $partner, $t, partner_next()) : ['error' => 'Dům nenalezen.'];
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
// Přehled správy: ukazatele všech domů v jedné tabulce (dům, který potřebuje pozornost, nahoře).
if ($PATH === '/prehled') {
    $days = dash_period();
    $rows = [];
    foreach (partner_tenants((int) $partner['id']) as $h) {
        try {
            $rows[] = ['h' => $h] + house_dashboard(tenant_pdo($h['slug']), $days, false);
        } catch (Throwable $e) {
            error_log('SVJ partner, přehled domu ' . $h['slug'] . ': ' . $e->getMessage());
        }
    }
    $sorts = ['pozornost' => 'Potřebuje pozornost', 'otevrene' => 'Otevřená hlášení', 'reakce' => 'První reakce', 'nazev' => 'Název'];
    $sort = isset($sorts[$_GET['razeni'] ?? '']) ? $_GET['razeni'] : 'pozornost';
    $score = fn($r) => $r['late'] * 3 + $r['revize_late'] * 3 + $r['unassigned'] + $r['open'];
    usort($rows, match ($sort) {
        'otevrene' => fn($a, $b) => $b['open'] <=> $a['open'],
        'reakce' => fn($a, $b) => ($b['react_hours'] ?? -1) <=> ($a['react_hours'] ?? -1),
        'nazev' => fn($a, $b) => strcoll($a['h']['name'], $b['h']['name']),
        default => fn($a, $b) => $score($b) <=> $score($a),
    });
    $sum = fn(string $k) => array_sum(array_column($rows, $k));
    $react = array_filter(array_column($rows, 'react_hours'), fn($v) => $v !== null);
    render('partner/overview', ['title' => 'Přehled správy', 'rows' => $rows, 'days' => $days, 'sort' => $sort, 'sorts' => $sorts, 'score' => $score,
        'tot' => ['open' => $sum('open'), 'late' => $sum('late'), 'resolved' => $sum('resolved'), 'created' => $sum('created'), 'revize_late' => $sum('revize_late'), 'unassigned' => $sum('unassigned'),
            'react' => $react ? round(array_sum($react) / count($react), 1) : null]]);
    exit;
}
// Společný kalendář: termíny, revize, termíny hlášení a konce hlasování ve všech domech.
if ($PATH === '/kalendar') {
    if ($METHOD === 'POST' && post('action') === 'token') {
        pq('UPDATE partner_users SET cal_token = ? WHERE id = ?', [bin2hex(random_bytes(20)), $pu['id']]);
        $plog('nový odkaz na kalendář');
        flash('Odkaz pro odběr kalendáře je připravený.');
        redirect('/kalendar#odber');
    }
    $houses = partner_tenants((int) $partner['id']);
    $house = (string) ($_GET['dum'] ?? '');
    $kind = isset(PARTNER_CAL_KINDS[$_GET['druh'] ?? '']) ? $_GET['druh'] : '';
    $items = array_values(array_filter(partner_calendar($houses, date('Y-m-d'), date('Y-m-d', strtotime('+120 days'))),
        fn($it) => ($house === '' || $it['slug'] === $house) && ($kind === '' || $it['kind'] === $kind)));
    render('partner/calendar', ['title' => 'Kalendář domů', 'items' => $items, 'houses' => $houses, 'house' => $house, 'kind' => $kind,
        'icsUrl' => $pu['cal_token'] !== '' ? platform_url('/partner/kalendar.ics?t=' . $pu['cal_token']) : '']);
    exit;
}
// Hlášení závad a požadavků ze všech domů firmy (jen čtení – vyřizuje se ve webu domu).
if ($PATH === '/hlaseni') {
    $houses = partner_tenants((int) $partner['id']);
    $filter = in_array($_GET['f'] ?? '', ['moje', 'po-terminu', 'vse'], true) ? $_GET['f'] : 'otevrene';
    $house = (string) ($_GET['dum'] ?? '');
    $rows = [];
    $counts = ['otevrene' => 0, 'moje' => 0, 'po-terminu' => 0, 'vse' => 0];
    $today = date('Y-m-d');
    foreach ($houses as $h) {
        try {
            $pdo = tenant_pdo($h['slug']);
            ticket_ready($pdo);
            $map = (array) json_decode((string) (tenant_settings($h['slug'])['partner_users'] ?? ''), true);
            $myUid = (int) ($map[strtolower($pu['email'])] ?? 0);
            foreach ($pdo->query("SELECT t.id, t.subject, t.location, t.status, t.created_at, t.updated_at, t.due_date, t.assignee_id, u.name author_name, a.name assignee_name,
                    (SELECT MAX(at) FROM ticket_log l WHERE l.ticket_id = t.id) last_at FROM tickets t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN users a ON a.id = t.assignee_id")->fetchAll() as $r) {
                $r += ['slug' => $h['slug'], 'house' => $h['name'], 'mine' => $myUid && (int) $r['assignee_id'] === $myUid, 'late' => $r['status'] !== 'done' && $r['due_date'] !== '' && $r['due_date'] < $today];
                $open = $r['status'] !== 'done';
                $in = ['otevrene' => $open, 'moje' => $open && $r['mine'], 'po-terminu' => $r['late'], 'vse' => true];
                if ($house === '' || $house === $h['slug']) {
                    foreach ($in as $k => $v) {
                        $counts[$k] += $v ? 1 : 0;
                    }
                    if ($in[$filter]) {
                        $rows[] = $r;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('SVJ partner, hlášení domu ' . $h['slug'] . ': ' . $e->getMessage());
        }
    }
    usort($rows, fn($a, $b) => [$a['status'] === 'done', !$a['late'], $a['due_date'] === '' ? '9999' : $a['due_date'], $b['created_at']] <=> [$b['status'] === 'done', !$b['late'], $b['due_date'] === '' ? '9999' : $b['due_date'], $a['created_at']]);
    render('partner/tickets', ['title' => 'Hlášení', 'rows' => $rows, 'houses' => $houses, 'filter' => $filter, 'house' => $house, 'counts' => $counts]);
    exit;
}
// Faktury společenstvím: SVJ platí za web partnerovi (jeho cenou), partner platí platformě společnou fakturou.
if (preg_match('~^/fakturace/svj/(\d+)$~', $PATH, $m)) {
    $inv = pq('SELECT * FROM partner_svj_invoices WHERE id = ? AND partner_id = ?', [(int) $m[1], $partner['id']])->fetch() ?: not_found();
    $GLOBALS['LAYOUT'] = 'partner/layout';
    render('partner/svj_invoice', ['title' => 'Faktura ' . $inv['number'], 'd' => partner_svj_view($inv), 'backUrl' => url('/fakturace/svj')]);
    exit;
}
if ($PATH === '/fakturace/svj') {
    $houses = partner_tenants((int) $partner['id']);
    $errors = [];
    if ($METHOD === 'POST') {
        $action = post('action');
        if ($action === 'issue') {
            $t = tenant_row(post('slug'));
            $amount = (int) preg_replace('/\D/', '', post('amount'));
            if (!$t || (int) $t['partner_id'] !== (int) $partner['id'] || $t['partner_status'] !== 'active') {
                $errors[] = 'Dům nenalezen.';
            } elseif ($missing = partner_svj_missing($partner)) {
                $errors[] = 'Před vystavením faktury doplňte na stránce Firma: ' . implode(', ', $missing) . '.';
            } elseif ($amount <= 0 || !valid_date(post('from')) || !valid_date(post('to')) || post('to') < post('from')) {
                $errors[] = 'Zadejte částku a platné období.';
            } else {
                $id = partner_svj_issue($partner, $t, post('from'), post('to'), $amount, $pu['name']);
                if (isset($_POST['send'])) {
                    partner_svj_notify($partner, $t, $id);
                }
                $plog('faktura společenství', $t['slug'] . ' · ' . $amount);
                flash('Faktura je vystavená' . (isset($_POST['send']) ? ' a výbor dostal e-mail s odkazem.' : '.'));
                redirect('/fakturace/svj');
            }
        } elseif (in_array($action, ['paid', 'unpaid', 'cancel'], true)) {
            $to = ['paid' => 'paid', 'unpaid' => 'issued', 'cancel' => 'cancelled'][$action];
            pq('UPDATE partner_svj_invoices SET status = ?, paid_at = ? WHERE id = ? AND partner_id = ?', [$to, $to === 'paid' ? date('Y-m-d') : '', (int) post('id'), $partner['id']]);
            $plog('faktura společenství: ' . PARTNER_SVJ_STATUS[$to], (string) (int) post('id'));
            redirect('/fakturace/svj');
        }
        http_response_code(422);
    }
    $invoices = pq('SELECT * FROM partner_svj_invoices WHERE partner_id = ? ORDER BY id DESC LIMIT 300', [$partner['id']])->fetchAll();
    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="faktury-svj.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Číslo', 'VS', 'Společenství', 'IČO', 'Vystaveno', 'Splatnost', 'Období od', 'Období do', 'Bez DPH', 'DPH %', 'Celkem', 'Stav', 'Zaplaceno'], ';');
        foreach ($invoices as $i) {
            $c = (array) (json_decode($i['data'], true)['customer'] ?? []);
            fputcsv($out, [$i['number'], $i['vs'], $c['name'] ?? $i['tenant'], $c['ico'] ?? '', $i['issued'], $i['due'], $i['period_from'], $i['period_to'], $i['amount'], $i['vat_rate'],
                $i['amount'] + (int) round($i['amount'] * $i['vat_rate'] / 100), PARTNER_SVJ_STATUS[$i['status']], $i['paid_at']], ';');
        }
        exit;
    }
    $last = [];
    foreach ($invoices as $i) {
        if ($i['status'] !== 'cancelled' && (!isset($last[$i['tenant']]) || $i['period_to'] > $last[$i['tenant']])) {
            $last[$i['tenant']] = $i['period_to'];
        }
    }
    render('partner/svj_billing', ['title' => 'Faktury společenstvím', 'partner' => $partner, 'houses' => $houses, 'invoices' => $invoices, 'last' => $last, 'errors' => $errors,
        'missing' => partner_svj_missing($partner)]);
    exit;
}
// Hromadné oznámení: aktualita (s přílohou) do vybraných domů najednou.
if ($PATH === '/hromadne') {
    $houses = partner_tenants((int) $partner['id']);
    $errors = [];
    $in = ['title' => '', 'body' => '', 'visibility' => 'residents', 'houses' => array_column($houses, 'slug')];
    if ($METHOD === 'POST') {
        $in = ['title' => mb_substr(post('title'), 0, 200), 'body' => mb_substr(post('body'), 0, 20000), 'visibility' => post('visibility') === 'public' ? 'public' : 'residents',
            'houses' => array_values(array_intersect(array_map('strval', (array) ($_POST['houses'] ?? [])), array_column($houses, 'slug')))];
        $file = null;
        if (($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES['file'];
            $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
            $mime = $f['error'] === UPLOAD_ERR_OK ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : '';
            if ($f['error'] !== UPLOAD_ERR_OK || !in_array($ext, ALLOWED_EXT, true) || (int) $f['size'] > 10485760
                || (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true) && !in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true))) {
                $errors[] = 'Příloha musí být PDF, dokument Office nebo obrázek do 10 MB.';
            } else {
                $file = ['tmp' => $f['tmp_name'], 'name' => basename((string) $f['name'])];
            }
        }
        if ($in['title'] === '' || $in['body'] === '') {
            $errors[] = 'Vyplňte nadpis i text oznámení.';
        }
        if (!$in['houses']) {
            $errors[] = 'Vyberte aspoň jeden dům.';
        }
        if (!$errors) {
            [$done, $failed] = partner_bulk_post($pu, $partner, $in['houses'], $in['title'], $in['body'], $in['visibility'], $file);
            $plog('hromadné oznámení', $in['title'] . ' · domů ' . $done);
            flash('Oznámení je zveřejněné – počet domů: ' . $done . '.' . ($failed ? ' Nepodařilo se: ' . implode('; ', $failed) : ''), $failed ? 'error' : 'ok');
            redirect('/hromadne');
        }
        http_response_code(422);
    }
    render('partner/bulk', ['title' => 'Hromadné oznámení', 'houses' => $houses, 'in' => $in, 'errors' => $errors, 'templates' => partner_templates((int) $partner['id'], 'post')]);
    exit;
}
// Šablony textů firmy: použijí se v hromadném oznámení i ve formulářích aktualit a e-mailů v každém domě.
if ($PATH === '/sablony') {
    $errors = [];
    $edit = ctype_digit((string) ($_GET['id'] ?? '')) ? pq('SELECT * FROM partner_templates WHERE id = ? AND partner_id = ?', [(int) $_GET['id'], $partner['id']])->fetch() : null;
    if ($METHOD === 'POST') {
        if (post('action') === 'delete') {
            pq('DELETE FROM partner_templates WHERE id = ? AND partner_id = ?', [(int) post('id'), $partner['id']]);
            flash('Šablona je smazaná.', 'info');
            redirect('/sablony');
        }
        $kind = isset(PARTNER_TPL_KINDS[post('kind')]) ? post('kind') : 'post';
        if (post('name') === '' || post('body') === '') {
            $errors[] = 'Vyplňte název šablony a text.';
        } else {
            $args = [$kind, mb_substr(post('name'), 0, 120), mb_substr(post('title'), 0, 200), mb_substr(post('body'), 0, 20000), date('Y-m-d H:i:s')];
            if ((int) post('id') && pq('SELECT 1 FROM partner_templates WHERE id = ? AND partner_id = ?', [(int) post('id'), $partner['id']])->fetchColumn()) {
                pq('UPDATE partner_templates SET kind = ?, name = ?, title = ?, body = ?, updated_at = ? WHERE id = ?', [...$args, (int) post('id')]);
            } else {
                pq('INSERT INTO partner_templates (kind, name, title, body, updated_at, partner_id) VALUES (?,?,?,?,?,?)', [...$args, $partner['id']]);
            }
            $plog('šablona', post('name'));
            flash('Šablona je uložená.');
            redirect('/sablony');
        }
        http_response_code(422);
    }
    render('partner/templates', ['title' => 'Šablony textů', 'list' => partner_templates((int) $partner['id']), 'edit' => $edit, 'errors' => $errors]);
    exit;
}
// Firma: značka na webech domů, registrační odkaz, cena pro SVJ.
if ($PATH === '/firma') {
    $errors = [];
    if ($METHOD === 'POST' && $owner) {
        $action = post('action');
        if ($action === 'brand') {
            $web = trim(post('web'));
            if ($web !== '' && !preg_match('~^https?://~i', $web)) {
                $web = 'https://' . $web;
            }
            if ($web !== '' && !filter_var($web, FILTER_VALIDATE_URL)) {
                $errors[] = 'Adresa webu firmy nemá správný tvar.';
            } elseif (post('email') !== '' && !filter_var(post('email'), FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'E-mail nemá správný tvar.';
            } else {
                pq('UPDATE partners SET web = ?, phone = ?, email = ?, brand = ? WHERE id = ?', [$web, mb_substr(post('phone'), 0, 40), post('email'), isset($_POST['brand']) ? 1 : 0, $partner['id']]);
                if (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && ($err = partner_logo_save($partner, $_FILES['logo']))) {
                    $errors[] = $err;
                } elseif (isset($_POST['logo_remove']) && ($old = partner_logo_path($partner)) !== '') {
                    @unlink($old);
                    pq("UPDATE partners SET logo = '' WHERE id = ?", [$partner['id']]);
                }
                if (!$errors) {
                    $plog('úprava údajů firmy');
                    flash('Uloženo.');
                    redirect('/firma');
                }
            }
        } elseif ($action === 'svjbill') {
            $iban = strtoupper(preg_replace('/\s+/', '', post('iban')));
            if ($iban !== '' && !preg_match('/^CZ\d{22}$/', $iban)) {
                $errors[] = 'IBAN má tvar CZ a 22 číslic (najdete ho v internetovém bankovnictví).';
            } elseif (isset($_POST['vat']) && trim((string) $partner['dic']) === '') {
                $errors[] = 'Plátce DPH musí mít na faktuře DIČ – napište ho prosím provozovateli služby, doplní ho k údajům firmy.';
            } else {
                pq('UPDATE partners SET account = ?, iban = ?, vat_rate = ?, inv_due = ? WHERE id = ?', [mb_substr(post('account'), 0, 40), $iban, isset($_POST['vat']) ? 21 : 0, max(0, min(90, (int) post('inv_due'))), $partner['id']]);
                $plog('fakturační údaje pro SVJ');
                flash('Fakturační údaje jsou uložené.');
                redirect('/firma#fakturace-svj');
            }
        } elseif ($action === 'resale') {
            $price = post('resale') === '' ? 0 : (int) preg_replace('/\D/', '', post('resale'));
            pq('UPDATE partners SET resale = ? WHERE id = ?', [$price ? (string) $price : '', $partner['id']]);
            foreach ((array) ($_POST['house'] ?? []) as $slug => $v) {
                $v = trim((string) $v);
                pq('UPDATE tenants SET partner_resale = ? WHERE slug = ? AND partner_id = ?', [$v === '' ? 0 : (int) preg_replace('/\D/', '', $v), (string) $slug, $partner['id']]);
            }
            $plog('cena pro SVJ', (string) $price);
            flash('Ceny pro SVJ jsou uložené.');
            redirect('/firma#cena');
        }
        http_response_code(422);
    }
    $partner = partner_row((int) $partner['id']);
    render('partner/company', ['title' => 'Firma', 'partner' => $partner, 'owner' => $owner, 'errors' => $errors, 'logo' => partner_logo_data($partner),
        'refUrl' => platform_url('/registrace?partner=' . partner_ref_code($partner)), 'houses' => partner_tenants((int) $partner['id'])]);
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
        } elseif ($action === 'notify') {
            pq('UPDATE partner_users SET notify_tickets = ? WHERE id = ?', [isset($_POST['notify_tickets']) ? 1 : 0, $pu['id']]);
            flash('Uloženo.');
            redirect('/ucet');
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
// Rozšíření portálu: app/ext/partner_*.php (stránka, kterou modul obslouží, skončí exit).
foreach (glob(__DIR__ . '/ext/partner_*.php') ?: [] as $extPartner) {
    require $extPartner;
}
not_found();
