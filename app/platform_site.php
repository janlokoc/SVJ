<?php
declare(strict_types=1);

// Veřejná část hlavní domény: úvodní stránka služby, ceník a samoobslužná registrace nového společenství.

$GLOBALS['LAYOUT'] = 'platform/site_layout';
if ((int) pq('SELECT COUNT(*) FROM super_admins')->fetchColumn() === 0) {
    redirect('/sprava');   // platforma ještě nemá hlavního správce
}
$open = pset('signup_open') === '1';
$signup = $_SESSION['signup'] ?? [];
$steps = ['/registrace' => 'Společenství', '/registrace/vzhled' => 'Vzhled', '/registrace/spravce' => 'Účet správce'];

if ($PATH === '/' && $METHOD === 'GET') {
    // Ukázkový web se založí sám při prvním zobrazení úvodní stránky (jde vypnout v Nastavení služby).
    if (!demo_tenant() && pset('demo_auto') === '1' && !tenant_row('demo')) {
        try {
            demo_create();
            pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), '', '', 'Založení ukázkového webu', 'demo · automaticky']);
        } catch (Throwable $e) {
            error_log('SVJ web, ukázkový web: ' . $e->getMessage());
        }
    }
    render('platform/landing', ['title' => '', 'open' => $open, 'demo' => demo_tenant()]);
    exit;
}
// Dotaz webového serveru (např. Caddy, „on-demand TLS“): smí pro tuto doménu vystavit certifikát? Ano jen pro zprovozněné domény společenství.
if ($PATH === '/domena-povolena') {
    $d = domain_clean((string) ($_GET['domain'] ?? ''));
    $ok = $d !== '' && pq("SELECT 1 FROM tenants WHERE active = 1 AND custom_domain <> '' AND custom_domain = ?", [$d])->fetchColumn();
    http_response_code($ok ? 200 : 404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $ok ? 'ok' : 'neznama domena';
    exit;
}
if ($METHOD === 'GET' && ($PATH === '/podminky' || $PATH === '/zpracovani-udaju')) {
    $terms = $PATH === '/podminky';
    render('platform/text', ['title' => $terms ? 'Obchodní podmínky' : 'Smlouva o zpracování osobních údajů', 'body' => pset($terms ? 'terms' : 'dpa'),
        'also' => $terms ? ['/zpracovani-udaju', 'Smlouva o zpracování osobních údajů'] : ['/podminky', 'Obchodní podmínky']]);
    exit;
}
if ($PATH === '/hlaseni') {
    $errors = $METHOD === 'POST' ? report_submit(report_who()) : [];
    if ($METHOD === 'POST' && !$errors) {
        flash('Děkujeme, hlášení jsme přijali. Odpovíme Vám e-mailem.');
        redirect(report_back(post('z', '/')));
    }
    if ($errors) {
        http_response_code(422);
    }
    render('reports/page', ['title' => 'Nahlásit problém nebo nápad', 'errors' => $errors, 'mine' => [], 'back' => report_back((string) ($_GET['z'] ?? post('z', '/')))]);
    exit;
}
if ($PATH === '/registrace/adresa' && $METHOD === 'GET') {
    $slug = slug_input((string) ($_GET['adresa'] ?? ''));
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => slug_problem($slug) === null, 'message' => slug_problem($slug) ?? 'Adresa ' . tenant_address($slug) . ' je volná.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($PATH === '/registrace/hotovo' && !empty($_SESSION['signup_done'])) {
    $done = $_SESSION['signup_done'];
    unset($_SESSION['signup_done']);
    $GLOBALS['HEAD_EXTRA'] = '<meta http-equiv="refresh" content="1;url=' . e($done['go']) . '">';
    render('platform/signup_done', ['title' => 'Web je založen', 'done' => $done]);
    exit;
}
if (isset($steps[$PATH])) {
    if (!$open) {
        render('platform/message', ['title' => 'Registrace je pozastavena', 'message' => 'Nová společenství teď zakládáme jen po domluvě.' . (pset('contact_email') !== '' ? ' Napište nám na ' . pset('contact_email') . '.' : '')]);
        exit;
    }
    $errors = [];
    $step = array_search($PATH, array_keys($steps), true) + 1;
    // Na další krok se nejde dostat bez dokončení předchozího.
    if (($step >= 2 && empty($signup['slug'])) || ($step === 3 && empty($signup['look']))) {
        redirect($step === 3 && !empty($signup['slug']) ? '/registrace/vzhled' : '/registrace');
    }
    if ($METHOD === 'POST' && $step === 1) {
        $name = mb_substr(post('name'), 0, 120);
        $slug = slug_input(post('slug')) !== '' ? slug_input(post('slug')) : slugify($name);
        if (mb_strlen($name) < 3) {
            $errors[] = 'Vyplňte název společenství.';
        }
        if ($problem = slug_problem($slug)) {
            $errors[] = $problem;
        }
        $signup = ['name' => $name, 'slug' => $slug, 'address' => mb_substr(post('address'), 0, 200), 'look' => $signup['look'] ?? '', 'style' => $signup['style'] ?? 'klasik'];
        if (!$errors) {
            $_SESSION['signup'] = $signup;
            redirect('/registrace/vzhled');
        }
    } elseif ($METHOD === 'POST' && $step === 2) {
        if (isset(LOOKS[post('look')])) {
            $_SESSION['signup']['look'] = post('look');
            $_SESSION['signup']['style'] = isset(STYLES[post('style')]) ? post('style') : 'klasik';
            redirect('/registrace/spravce');
        }
        $errors[] = 'Vyberte jeden ze vzhledů.';
    } elseif ($METHOD === 'POST' && $step === 3) {
        $pass = (string) ($_POST['password'] ?? '');
        $email = post('email');
        $person = mb_substr(post('person'), 0, 120);
        pq('DELETE FROM signups WHERE at < ?', [time() - 86400]);
        if (post('website') !== '') {
            $errors[] = 'Registraci se nepodařilo dokončit.';   // pole skryté před lidmi vyplnil robot
        } elseif ((int) pq('SELECT COUNT(*) FROM signups WHERE ip = ?', [client_ip()])->fetchColumn() >= 3) {
            $errors[] = 'Z Vaší adresy už dnes vznikly tři weby. Zkuste to prosím zítra, nebo nám napište.';
        }
        if (mb_strlen($person) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Vyplňte jméno a platný e-mail.';
        }
        if ($problem = password_problem($pass, $email, $person)) {
            $errors[] = $problem;
        }
        if (!isset($_POST['agree'])) {
            $errors[] = 'Pro založení webu je potřeba souhlasit s podmínkami služby.';
        }
        if (!$errors && ($problem = slug_problem($signup['slug']))) {
            $errors[] = $problem . ' Vraťte se prosím na první krok.';
        }
        if (!$errors) {
            $slug = $signup['slug'];
            $title = trim(preg_replace('/^(SVJ|Společenství vlastníků( jednotek)?)\s+/iu', '', $signup['name'])) ?: $signup['name'];
            $trialEnds = date('Y-m-d', time() + pset_int('trial_days', 0, 365) * 86400);
            tenant_create(['slug' => $slug, 'name' => $signup['name'], 'title' => $title, 'address' => $signup['address'], 'look' => $signup['look'], 'style' => $signup['style'] ?? 'klasik',
                'billing' => 1, 'terms_at' => date('Y-m-d H:i:s'), 'trial_ends' => $trialEnds, 'owner_name' => $person, 'owner_email' => $email, 'note' => 'registrace z webu']);
            $pdo = tenant_pdo($slug);
            $pdo->prepare("INSERT INTO users (name, email, role, kind, password_hash) VALUES (?,?,'admin','owner',?)")->execute([$person, $email, password_hash($pass, PASSWORD_DEFAULT)]);
            tenant_set($slug, 'webmaster_email', $email);
            if (pset('mail_from') !== '') {
                tenant_set($slug, 'mail_from', pset('mail_from'));   // e-maily webu fungují hned; výbor si odesílatele může změnit
            }
            // Jednorázový odkaz, kterým se nový správce rovnou přihlásí na svém webu (platí 10 minut).
            $token = bin2hex(random_bytes(32));
            tenant_set($slug, 'signup_login', hash('sha256', $token) . '|' . (time() + 600));
            pq('INSERT INTO signups (ip, at) VALUES (?,?)', [client_ip(), time()]);
            platform_log('Registrace společenství', $slug . ' · ' . $signup['name'] . ' · ' . $email);
            $t = tenant_row($slug);
            platform_mail($email, 'Váš web je připraven – ' . $signup['name'], "Dobrý den,\n\nweb společenství " . $signup['name'] . " je založen na adrese " . rtrim(tenant_url($t), '/') . ".\n"
                . "Přihlásíte se e-mailem $email a heslem, které jste si zvolili při registraci.\n\nZkušební doba zdarma trvá do " . cz_date($trialEnds) . ". Potom je cena "
                . money_czk(pset_int('price_first', 0, 1000000)) . ' za ' . period_label() . " (první rok). Platební údaje najdete v administraci v části Předplatné.");
            unset($_SESSION['signup']);
            // Na web společenství (jiná adresa) se z formuláře nedá přesměrovat přímo – pravidla stránky to prohlížeči zakazují. Přechod obstará mezistránka.
            $_SESSION['signup_done'] = ['name' => $signup['name'], 'site' => rtrim(tenant_url($t), '/'), 'go' => tenant_url($t, '/vitejte/' . $token), 'until' => $trialEnds];
            redirect('/registrace/hotovo');
        }
        $signup += ['person' => $person, 'email' => $email];
    }
    if ($errors) {
        http_response_code(422);
    }
    render('platform/signup', ['title' => 'Založení webu', 'errors' => $errors, 'step' => $step, 'steps' => $steps, 's' => $signup + ['name' => '', 'slug' => '', 'address' => '', 'look' => '', 'style' => 'klasik', 'person' => '', 'email' => '']]);
    exit;
}
http_response_code(404);
render('platform/message', ['title' => 'Stránka nenalezena', 'message' => 'Tato stránka neexistuje.']);
