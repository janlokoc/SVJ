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

// Registrační odkaz správcovské firmy (?partner=KÓD): web po založení spravuje firma, když s tím výbor souhlasí.
if ($METHOD === 'GET' && isset($_GET['partner']) && ($sp = partner_by_code((string) $_GET['partner']))) {
    $_SESSION['spartner'] = ['id' => (int) $sp['id'], 'name' => $sp['name']];
}
// Odkaz s doporučením (?ref=KÓD) si zapamatujeme do konce registrace.
if ($METHOD === 'GET' && isset($_GET['ref']) && ($refOwner = referral_owner((string) $_GET['ref']))) {
    if (($_SESSION['ref']['code'] ?? '') !== $refOwner['ref_code'] && !preg_match('~bot|crawl|spider|preview~i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
        pq('UPDATE tenants SET ref_clicks = ref_clicks + 1 WHERE slug = ?', [$refOwner['slug']]);   // jednou za návštěvu
    }
    $_SESSION['ref'] = ['code' => $refOwner['ref_code'], 'name' => $refOwner['name']];
}
if ($PATH === '/' && $METHOD === 'GET') {
    demo_update_content();   // ukázka ze staršího obsahu (např. bez zpráv nebo anket) se založí znovu
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
if ($METHOD === 'GET' && $PATH === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo platform_robots_txt();
    exit;
}
if ($METHOD === 'GET' && $PATH === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    echo platform_sitemap_xml();
    exit;
}
if ($METHOD === 'GET' && $PATH === '/pro-spravce') {
    render('platform/for_managers', ['title' => 'Pro správcovské firmy', 'demo' => demo_tenant()]);
    exit;
}
if ($METHOD === 'GET' && ($PATH === '/funkce' || $PATH === '/cenik')) {
    render($PATH === '/funkce' ? 'platform/features' : 'platform/pricing', ['title' => $PATH === '/funkce' ? 'Co web umí' : 'Ceník', 'open' => pset('signup_open') === '1', 'demo' => demo_tenant()]);
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
if ($METHOD === 'GET' && preg_match('~^/pripravujeme/([a-z0-9-]+)$~', $PATH, $fm) && isset(PLANNED_FEATURES[$fm[1]])) {
    feature_view($fm[1]);
    $notified = ($_SESSION['feature_notified'] ?? []);
    render('platform/feature', ['title' => PLANNED_FEATURES[$fm[1]][0], 'key' => $fm[1], 'notified' => in_array($fm[1], $notified, true)]);
    exit;
}
if ($PATH === '/zajem' && $METHOD === 'POST') {
    $feature = post('feature');
    if (!isset(PLANNED_FEATURES[$feature])) {
        redirect('/#pripravujeme');
    }
    if (post('website') === '' && filter_var(post('email'), FILTER_VALIDATE_EMAIL)) {
        interest_add($feature, '', post('email'));
        $_SESSION['feature_notified'][] = $feature;
    }
    redirect('/pripravujeme/' . $feature);
}
if ($METHOD === 'GET' && $PATH === '/ochrana-udaju') {
    render('platform/text', ['title' => 'Ochrana osobních údajů', 'body' => PLATFORM_PRIVACY, 'also' => ['/zpracovani-udaju', 'Smlouva o zpracování osobních údajů (pro weby společenství)']]);
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
    if ($METHOD === 'POST') {
        report_ajax_reply($errors, 'Děkujeme, hlášení jsme přijali. Odpovíme Vám e-mailem.');
    }
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
if ($PATH === '/registrace/ares' && $METHOD === 'GET') {
    // Veřejné, proto s omezením: nejvýš 30 dotazů za hodinu z jednoho prohlížeče.
    $hits = array_filter($_SESSION['ares_hits'] ?? [], fn($t) => $t > time() - 3600);
    if (count($hits) >= 30) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Hledali jste v ARES už mnohokrát. Údaje prosím vyplňte ručně, nebo to zkuste za hodinu.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION['ares_hits'] = [...$hits, time()];
    ares_lookup_json(mb_substr((string) ($_GET['q'] ?? ''), 0, 120));
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
        $ico = preg_replace('/\s+/', '', post('ico'));
        if ($ico !== '' && ares_ico($ico) === null) {
            $errors[] = 'IČO není platné – zkontrolujte ho, nebo pole nechte prázdné.';
        }
        // Celý úřední název z ARES platí, jen pokud název nezměnil člověk ručně na úplně jiný.
        $full = mb_substr(post('full'), 0, 200);
        $signup = ['name' => $name, 'slug' => $slug, 'address' => mb_substr(post('address'), 0, 200), 'ico' => $ico !== '' ? (string) ares_ico($ico) : '',
            'full' => $full !== '' && $ico !== '' ? $full : '', 'look' => $signup['look'] ?? '', 'style' => $signup['style'] ?? 'klasik'];
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
            tenant_create(['slug' => $slug, 'name' => $signup['name'], 'title' => $title, 'full' => $signup['full'] ?? '', 'address' => $signup['address'], 'look' => $signup['look'], 'style' => $signup['style'] ?? 'klasik',
                'billing' => 1, 'terms_at' => date('Y-m-d H:i:s'), 'trial_ends' => '', 'owner_name' => $person, 'owner_email' => $email, 'note' => 'registrace z webu']);
            if (!empty($_SESSION['ref']['code'])) {
                referral_register($_SESSION['ref']['code'], tenant_row($slug));
                unset($_SESSION['ref']);
            }
            if (!empty($_SESSION['spartner']['id']) && isset($_POST['partner_ok']) && ($sp = partner_row((int) $_SESSION['spartner']['id'])) && $sp['active']) {
                pq("UPDATE tenants SET partner_id = ?, partner_status = 'active', partner_billing = 1, partner_since = ? WHERE slug = ?", [$sp['id'], date('Y-m-d H:i:s'), $slug]);
                pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), $email, client_ip(), 'Registrace přes odkaz partnera', $sp['name'] . ' · ' . $slug]);
                foreach (pq('SELECT email FROM partner_users WHERE partner_id = ? AND active = 1 AND role = \'owner\'', [$sp['id']])->fetchAll(PDO::FETCH_COLUMN) as $pe) {
                    platform_mail($pe, 'Nový dům přes Váš odkaz: ' . $signup['name'], 'Dobrý den,' . "\n\nspolečenství " . $signup['name'] . ' si založilo web přes Váš registrační odkaz a souhlasilo se správou Vaší firmou.' . "\n\nDům najdete v partnerském portálu: " . platform_url('/partner'));
                }
            }
            unset($_SESSION['spartner']);
            // Jiná šablona než základní je funkce edice Standard: nový web ji dostane rovnou na zkoušku.
            if (($signup['style'] ?? 'klasik') !== 'klasik') {
                pq('INSERT INTO feature_trials (tenant, feature, started, ends) VALUES (?,?,?,?)', [$slug, 'sablony', date('Y-m-d'), date('Y-m-d', strtotime('+' . PLAN_TRIAL_DAYS . ' day'))]);
            }
            $pdo = tenant_pdo($slug);
            // Zakládající, který je zapsaný ve výboru, dostane i funkci (porovnává se jméno bez titulů).
            $norm = 'ares_name_key';
            $position = '';
            foreach ($_SESSION['signup']['officers'] ?? [] as $o) {
                if ($norm($o['name']) === $norm($person)) {
                    $position = $o['position'];
                }
            }
            $pdo->prepare("INSERT INTO users (name, email, role, kind, position, password_hash) VALUES (?,?,'admin','owner',?,?)")->execute([$person, $email, $position, password_hash($pass, PASSWORD_DEFAULT)]);
            tenant_set($slug, 'webmaster_email', $email);
            tenant_set($slug, 'ico', $signup['ico'] ?? '');
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
                . "Přihlásíte se e-mailem $email a heslem, které jste si zvolili při registraci.\n\nWeb běží v edici Zdarma bez časového omezení. Funkce vyšších edic si můžete každou na 30 dní vyzkoušet v administraci v části Předplatné.");
            unset($_SESSION['signup']);
            // Na web společenství (jiná adresa) se z formuláře nedá přesměrovat přímo – pravidla stránky to prohlížeči zakazují. Přechod obstará mezistránka.
            $_SESSION['signup_done'] = ['name' => $signup['name'], 'site' => rtrim(tenant_url($t), '/'), 'go' => tenant_url($t, '/vitejte/' . $token)];
            redirect('/registrace/hotovo');
        }
        $signup += ['person' => $person, 'email' => $email];
    }
    if ($errors) {
        http_response_code(422);
    }
    // Poslední krok: členové výboru z veřejného rejstříku (jen jméno a funkce), načtené jednou za registraci.
    $officers = [];
    if ($step === 3 && !empty($_SESSION['signup']['ico'])) {
        if (!isset($_SESSION['signup']['officers'])) {
            $r = ares_officers($_SESSION['signup']['ico']);
            $_SESSION['signup']['officers'] = $r['ok'] ? array_map(fn($m) => array_intersect_key($m, array_flip(['name', 'function', 'position', 'body'])), $r['members']) : [];
        }
        $officers = $_SESSION['signup']['officers'];
    }
    render('platform/signup', ['title' => 'Založení webu', 'officers' => $officers, 'errors' => $errors, 'step' => $step, 'steps' => $steps, 's' => $signup + ['name' => '', 'slug' => '', 'address' => '', 'look' => '', 'style' => 'klasik', 'person' => '', 'email' => '']]);
    exit;
}
// Rozšíření veřejného webu služby: app/ext/site_*.php (stránka, kterou modul obslouží, skončí exit).
foreach (glob(__DIR__ . '/ext/site_*.php') ?: [] as $extSite) {
    require $extSite;
}
http_response_code(404);
render('platform/message', ['title' => 'Stránka nenalezena', 'message' => 'Tato stránka neexistuje.']);
