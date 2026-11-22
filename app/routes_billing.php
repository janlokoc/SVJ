<?php
declare(strict_types=1);

// Web společenství na platformě: první přihlášení po registraci a stránka Předplatné s výzvou k platbě.

$route('GET', '/vitejte/([a-f0-9]{64})', function (string $token) {
    [$hash, $expires] = explode('|', setting('signup_login') . '|');
    q("DELETE FROM settings WHERE `key` = 'signup_login'");   // odkaz platí jen jednou
    $admin = one("SELECT * FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1");
    if ($hash === '' || !hash_equals($hash, hash('sha256', $token)) || time() > (int) $expires || !$admin) {
        flash('Odkaz pro první přihlášení už neplatí. Přihlaste se prosím e-mailem a heslem z registrace.', 'info');
        redirect('/prihlaseni');
    }
    login_user($admin);
    q("UPDATE users SET last_login_at = datetime('now','localtime') WHERE id = ?", [$admin['id']]);
    audit('login', $admin['email'] . ' (první přihlášení po registraci)');
    $b = billing_state(tenant_row(TENANT));
    flash('Web je založen a běží zdarma. Začněte nastavením, kontakty na výbor a seznamem jednotek.');
    redirect('/admin');
});

$route('GET', '/admin/predplatne', function () {
    require_admin();
    $t = tenant_row(TENANT);
    $state = billing_state($t);
    $payment = $state['mode'] === 'free' ? null : billing_open($t);
    $iban = cz_iban(pset('account'));
    render('admin/billing', ['title' => 'Předplatné', 't' => $t, 'state' => $state, 'payment' => $payment, 'iban' => $iban, 'migration' => ($mig = migration_row(TENANT)), 'migrationPayment' => ($mp = migration_payment($mig)),
        'refLink' => referral_link($t), 'ref' => referral_overview($t),
        'storagePayment' => ($sp = storage_payment($t)), 'storageQr' => $sp && $iban ? qr_svg(spayd($iban, pay_total($sp), $sp['vs'], 'Uloziste ' . $t['slug'], pset('recipient')), 'QR platba za úložiště') : '',
        'migrationQr' => $mp && $iban && in_array($mp['status'], ['pending', 'claimed'], true) ? qr_svg(spayd($iban, pay_total($mp), $mp['vs'], 'Prevod webu ' . $t['slug'], pset('recipient')), 'QR platba za převod obsahu') : '', 'edition' => $state['edition'],
        'usage' => array_map(fn($k) => ['used' => plan_used($k), 'limit' => plan_limit($k)], array_combine(array_keys(PLAN_LIMITS), array_keys(PLAN_LIMITS))), 'deleteOn' => tenant_delete_date($t), 'domainPayment' => domain_payment($t), 'certInfo' => cert_info($t['custom_domain'] !== '' ? $t['custom_domain'] : $t['domain_request']), 'aiPayment' => ai_payment($t),
        'aiQr' => ($ap = ai_payment($t)) && $iban ? qr_svg(spayd($iban, pay_total($ap), $ap['vs'], 'AI Plus ' . $t['slug'], pset('recipient')), 'QR platba za AI Plus') : '',
        'domainQr' => ($dp = domain_payment($t)) && $iban ? qr_svg(spayd($iban, pay_total($dp), $dp['vs'], 'Domena ' . $dp['item'], pset('recipient')), 'QR platba za doménu') : '',
        'qr' => $payment && $iban ? qr_svg(spayd($iban, pay_total($payment), $payment['vs'], 'Web SVJ ' . $t['slug'], pset('recipient')), 'QR platba') : '',

        'history' => pq("SELECT * FROM payments WHERE tenant = ? AND status = 'paid' ORDER BY id DESC", [TENANT])->fetchAll()]);
});
$route('GET', '/admin/predplatne/doklad/(\d+)', function (int $id) {
    require_admin();
    $p = pq('SELECT * FROM payments WHERE id = ? AND tenant = ?', [$id, TENANT])->fetch();
    $d = $p ? invoice_view($p) : null;
    if (!$d) {
        not_found();
    }
    render('billing_doc', ['title' => $d['paid'] ? 'Faktura ' . $p['invoice_no'] : 'Zálohová faktura ' . $p['vs'], 'd' => $d, 'backUrl' => url('/admin/predplatne')]);
});
$route('POST', '/admin/predplatne/domena', function () {
    require_admin();
    $t = tenant_row(TENANT);
    $action = post('action');
    if ($action === 'cancel' && $t['domain_status'] === 'requested') {
        $p = domain_payment($t);
        if ($p && $p['status'] === 'claimed') {
            flash('Platbu jste už nahlásili jako odeslanou. Zrušení domluvte s provozovatelem.', 'error');
            redirect('/admin/predplatne#domena');
        }
        pq("UPDATE payments SET status = 'cancelled', note = 'žádost zrušena správcem webu' WHERE tenant = ? AND kind = 'domain' AND status = 'pending'", [TENANT]);
        pq("UPDATE tenants SET domain_request = '', domain_status = '' WHERE slug = ?", [TENANT]);
        audit('Vlastní doména: žádost zrušena', $t['domain_request']);
        flash('Žádost o vlastní doménu je zrušena.', 'info');
        redirect('/admin/predplatne#domena');
    }
    if ($action === 'claim' && ($p = domain_payment($t)) && $p['status'] === 'pending') {
        pq("UPDATE payments SET status = 'claimed', claimed_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $p['id']]);
        audit('Vlastní doména: nahlášena odeslaná platba', $p['amount'] . ' Kč, variabilní symbol ' . $p['vs']);
        flash('Děkujeme. Jakmile platbu uvidíme na účtu, doménu zprovozníme.');
        redirect('/admin/predplatne#domena');
    }
    if ($action === 'request' && $t['domain_status'] === '') {
        if (plan_edition() === 'free') {
            flash('Vlastní doménu lze objednat od edice Standard.', 'error');
            redirect('/admin/predplatne#edice');
        }
        $domain = domain_clean(post('domain'));
        if ($domain === '' || str_ends_with($domain, '.' . platform_domain()) || $domain === platform_domain()) {
            flash('Zadejte doménu ve tvaru tabor48.cz (bez https:// a bez www).', 'error');
            redirect('/admin/predplatne#domena');
        }
        if (pq('SELECT 1 FROM tenants WHERE slug <> ? AND (custom_domain = ? OR domain_request = ?)', [TENANT, $domain, $domain])->fetchColumn()) {
            flash('Tuto doménu už používá jiné společenství.', 'error');
            redirect('/admin/predplatne#domena');
        }
        $own = post('own') === '1';
        $registered = domain_registered($domain);
        if (!$own && $registered === true) {
            flash('Doména ' . $domain . ' je už registrovaná. Pokud patří Vám, zvolte „Doménu už máme“; jinak zkuste jiný název.', 'error');
            redirect('/admin/predplatne#domena');
        }
        pq("UPDATE tenants SET domain_request = ?, domain_status = 'requested' WHERE slug = ?", [$domain, TENANT]);
        $included = plan_edition() === 'premium';   // v edici Premium je doména v ceně
        if ($included) {
            pq('UPDATE tenants SET domain_until = ? WHERE slug = ?', [$t['paid_until'], TENANT]);
        }
        $p = $included ? ['vs' => 'v ceně edice Premium'] : domain_payment_create(tenant_row(TENANT), $domain);
        audit('Vlastní doména: žádost', $domain . ($own ? ' (doménu už vlastníme)' : ' (zajistit registraci)'));
        pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), '', client_ip(), 'Žádost o vlastní doménu', TENANT . ' · ' . $domain . ($own ? ' · doménu vlastní' : ' · zajistit registraci')]);
        if (pset('contact_email') !== '') {
            platform_mail(pset('contact_email'), 'Žádost o vlastní doménu: ' . $domain, 'Web ' . $t['name'] . ' (' . TENANT . ') žádá o doménu ' . $domain . ($own ? ', kterou už vlastní.' : ' a o její registraci.')
                . "\nVariabilní symbol platby: " . $p['vs'] . "\nVyřídit: " . platform_url('/sprava/svj/' . TENANT));
        }
        flash('Žádost o doménu ' . $domain . ' je odeslaná.' . ($registered === false && !$own ? ' Podle rychlé kontroly vypadá volná; registraci potvrdíme po zaplacení.' : ''));
        redirect('/admin/predplatne#domena');
    }
    redirect('/admin/predplatne#domena');
});
$route('POST', '/admin/predplatne/certifikat', function () {
    require_admin();
    $t = tenant_row(TENANT);
    if (post('action') === 'remove') {
        $domain = $t['custom_domain'] !== '' ? $t['custom_domain'] : $t['domain_request'];
        cert_remove($domain);
        audit('Vlastní doména: certifikát odebrán', $domain);
        flash('Nahraný certifikát byl odebrán.', 'info');
        redirect('/admin/predplatne#domena');
    }
    [$ok, $message, $domain] = cert_upload($t) + [2 => ''];
    if ($ok) {
        audit('Vlastní doména: nahrán certifikát', $domain);
        pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), '', client_ip(), 'Nahrání certifikátu společenstvím', TENANT . ' · ' . $domain]);
    }
    flash($message, $ok ? 'ok' : 'error');
    redirect('/admin/predplatne#domena');
});
$route('GET', '/admin/predplatne/funkce/([a-z0-9-]+)', function (string $key) {
    require_admin();
    if (!isset(PLANNED_FEATURES[$key])) {
        not_found();
    }
    feature_view($key, !empty($GLOBALS['DEMO']) ? '' : TENANT);   // prokliky z ukázkového webu se počítají jako návštěvníci, ne jako společenství
    render('admin/feature', ['title' => PLANNED_FEATURES[$key][0], 'key' => $key,
        'notified' => (bool) pq('SELECT 1 FROM feature_interest WHERE feature = ? AND tenant = ?', [$key, TENANT])->fetchColumn()]);
});
$route('POST', '/admin/predplatne/zajem', function () {
    $u = require_admin();
    $feature = post('feature');
    if (!isset(PLANNED_FEATURES[$feature])) {
        redirect('/admin/predplatne#pripravujeme');
    }
    interest_add($feature, TENANT, $u['email']);
    redirect('/admin/predplatne/funkce/' . $feature);
});
$route('POST', '/admin/predplatne/ai', function () {
    require_admin();
    $t = tenant_row(TENANT);
    $action = post('action');
    $p = ai_payment($t);
    if ($action === 'claim' && $p && $p['status'] === 'pending') {
        pq("UPDATE payments SET status = 'claimed', claimed_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $p['id']]);
        audit('AI Plus: nahlášena odeslaná platba', $p['amount'] . ' Kč, variabilní symbol ' . $p['vs']);
        flash('Děkujeme. Jakmile platbu uvidíme na účtu, AI Plus zapneme.');
    } elseif ($action === 'cancel' && $p && $p['status'] === 'pending') {
        pq("UPDATE payments SET status = 'cancelled', note = 'objednávka zrušena správcem webu' WHERE id = ?", [$p['id']]);
        flash('Objednávka AI Plus je zrušena.', 'info');
    }
    redirect('/admin/predplatne#ai');
});
$route('POST', '/admin/predplatne/uloziste', function () {
    require_admin();
    $t = tenant_row(TENANT);
    if (!$t || empty($t['billing'])) {
        redirect('/admin/predplatne');
    }
    $p = storage_payment($t);
    $action = post('action');
    if ($action === 'claim' && $p && $p['status'] === 'pending') {
        pq("UPDATE payments SET status = 'claimed', claimed_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $p['id']]);
        audit('Úložiště navíc: nahlášena odeslaná platba', $p['amount'] . ' Kč, variabilní symbol ' . $p['vs']);
        flash('Děkujeme. Jakmile platbu uvidíme na účtu, úložiště zvětšíme.');
    } elseif ($action === 'cancel' && $p && $p['status'] === 'pending') {
        pq("UPDATE payments SET status = 'cancelled', note = 'objednávka zrušena správcem webu' WHERE id = ?", [$p['id']]);
        flash('Objednávka úložiště je zrušena.', 'info');
    } elseif ($action === 'order') {
        if ($p && $p['status'] === 'claimed') {
            flash('Jednu platbu za úložiště jste už nahlásili. Počkejte prosím na její potvrzení.', 'error');
        } else {
            $p = storage_order($t, (int) post('packs'));
            audit('Úložiště navíc: objednávka', $p['item'] . ' MB · ' . $p['amount'] . ' Kč, variabilní symbol ' . $p['vs']);
            flash('Úložiště navíc je objednané. Zvětšíme ho po připsání platby – údaje jsou níže.');
        }
    }
    redirect('/admin/predplatne#uloziste');
});
$route('POST', '/admin/predplatne/prevod', function () {
    $u = require_admin();
    $t = tenant_row(TENANT);
    $m = migration_row(TENANT);
    $p = migration_payment($m);
    $action = post('action');
    if ($action === 'claim' && $p && $p['status'] === 'pending') {
        pq("UPDATE payments SET status = 'claimed', claimed_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $p['id']]);
        audit('Převod obsahu: nahlášena odeslaná platba', $p['amount'] . ' Kč, variabilní symbol ' . $p['vs']);
        flash('Děkujeme. Jakmile platbu uvidíme na účtu, pustíme se do převodu.');
        redirect('/admin/predplatne#prevod');
    }
    if ($action === 'cancel' && $m && $m['status'] === 'ordered' && $p && $p['status'] === 'pending') {
        pq("UPDATE payments SET status = 'cancelled', note = 'objednávka zrušena správcem webu' WHERE id = ?", [$p['id']]);
        pq("UPDATE migrations SET status = 'cancelled' WHERE id = ?", [$m['id']]);
        audit('Převod obsahu: objednávka zrušena', $m['url']);
        flash('Objednávka převodu je zrušena.', 'info');
        redirect('/admin/predplatne#prevod');
    }
    if ($action !== 'order' || ($m && $m['status'] !== 'done')) {
        redirect('/admin/predplatne#prevod');
    }
    $url = trim(post('url'));
    if ($url !== '' && !preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }
    $scope = array_values(array_intersect(array_keys(MIGRATION_SCOPE), (array) ($_POST['scope'] ?? [])));
    $in = ['url' => mb_substr($url, 0, 300), 'scope' => implode(',', $scope), 'access' => isset(MIGRATION_ACCESS[post('access')]) ? post('access') : '', 'note' => mb_substr(trim(post('note')), 0, 2000),
        'contact_name' => mb_substr(trim(post('contact_name')), 0, 120), 'contact_email' => trim(post('contact_email')), 'contact_phone' => mb_substr(trim(post('contact_phone')), 0, 40)];
    $errors = [];
    $host = (string) parse_url($in['url'], PHP_URL_HOST);
    if (!filter_var($in['url'], FILTER_VALIDATE_URL) || !str_contains($host, '.')) {
        $errors[] = 'Zadejte adresu starého webu, například https://svj-zahradni.webnode.cz.';
    }
    if (!$scope) {
        $errors[] = 'Zaškrtněte, co chcete převést.';
    }
    if ($in['access'] === '') {
        $errors[] = 'Vyberte, jak se k obsahu dostaneme.';
    }
    if ($in['contact_name'] === '' || !filter_var($in['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Vyplňte kontaktní osobu a její e-mail.';
    }
    if (!isset($_POST['rights'])) {
        $errors[] = 'Potvrďte prosím, že společenství má k obsahu práva a s převodem souhlasí.';
    }
    if ($errors) {
        $_SESSION['migration_form'] = $in + ['scope_list' => $scope];
        flash(implode(' ', $errors), 'error');
        redirect('/admin/predplatne#prevod');
    }
    unset($_SESSION['migration_form']);
    $m = migration_order($t, $in);
    $p = migration_payment($m);
    audit('Převod obsahu: objednávka', $in['url'] . ' · ' . $p['amount'] . ' Kč, variabilní symbol ' . $p['vs']);
    pq('INSERT INTO platform_log (at, admin, ip, action, detail) VALUES (?,?,?,?,?)', [date('Y-m-d H:i:s'), '', client_ip(), 'Objednávka převodu obsahu', TENANT . ' · ' . $in['url']]);
    if (pset('contact_email') !== '') {
        platform_mail(pset('contact_email'), 'Objednávka převodu obsahu: ' . $t['name'], 'Web ' . $t['name'] . ' (' . TENANT . ') objednal převod obsahu z ' . $in['url'] . ".\nRozsah: " . implode(', ', $scope)
            . "\nPřístup: " . MIGRATION_ACCESS[$in['access']] . "\nKontakt: " . $in['contact_name'] . ', ' . $in['contact_email'] . ($in['contact_phone'] !== '' ? ', ' . $in['contact_phone'] : '')
            . ($in['note'] !== '' ? "\nPoznámka: " . $in['note'] : '') . "\nVariabilní symbol platby: " . $p['vs'] . "\nVyřídit: " . platform_url('/sprava/svj/' . TENANT));
    }
    flash('Převod je objednán. Pustíme se do něj po připsání platby – údaje jsou níže.');
    redirect('/admin/predplatne#prevod');
});
$route('POST', '/admin/predplatne/edice', function () {
    require_admin();
    $t = tenant_row(TENANT);
    if (empty($t['billing'])) {
        redirect('/admin/predplatne');
    }
    $open = billing_open($t);
    if (post('action') === 'cancel') {
        if ($open && $open['status'] === 'pending') {
            pq("UPDATE payments SET status = 'cancelled', note = 'objednávka zrušena správcem webu' WHERE id = ?", [$open['id']]);
            audit('Předplatné: objednávka zrušena', EDITIONS[$open['item']] ?? '');
            flash('Objednávka je zrušena.', 'info');
        }
        redirect('/admin/predplatne');
    }
    $edition = post('edition');
    if (!in_array($edition, ['standard', 'premium'], true)) {
        redirect('/admin/predplatne');
    }
    if ($open && $open['status'] === 'claimed') {
        flash('Jednu platbu jste už nahlásili jako odeslanou. Počkejte prosím na její potvrzení.', 'error');
        redirect('/admin/predplatne#platba');
    }
    if ($problem = billing_order_problem($t, $edition)) {
        flash($problem, 'info');
        redirect('/admin/predplatne#edice');
    }
    $credit = billing_credit($t, $edition);
    $p = billing_order($t, $edition);
    audit('Předplatné: objednávka edice', EDITIONS[$edition] . ' na rok · ' . $p['amount'] . ' Kč, variabilní symbol ' . $p['vs']);
    flash('Edice ' . EDITIONS[$edition] . ' je objednaná. Zapne se po připsání platby – údaje jsou níže.' . ($credit ? ' Od ceny jsme odečetli ' . money_czk($credit) . ' za nevyčerpanou část edice ' . EDITIONS[tenant_edition($t)] . '.' : ''));
    redirect('/admin/predplatne#platba');
});
$route('POST', '/admin/predplatne/zkusit', function () {
    require_admin();
    $feature = post('feature');
    if (!isset(PLAN_FEATURES[$feature])) {
        redirect('/admin/predplatne#funkce');
    }
    if (plan_trial_start($feature)) {
        $ends = plan_trials()[$feature]['ends'];
        audit('Zkouška funkce', PLAN_FEATURES[$feature][1] . ' do ' . $ends);
        flash('Funkce „' . PLAN_FEATURES[$feature][1] . '“ je zapnutá na zkoušku do ' . cz_date($ends) . '.');
        $back = post('back');
        redirect(preg_match('~^/admin/[a-z0-9/-]*$~', $back) ? $back : '/admin/predplatne#funkce');
    }
    flash(plan_state($feature) === 'expired' ? 'Tuto funkci jste už jednou zkoušeli. Dál ji můžete používat s edicí ' . EDITIONS[PLAN_FEATURES[$feature][0]] . '.' : 'Funkce je už zapnutá.', 'info');
    redirect('/admin/predplatne#funkce');
});
// Automatické prodlužování: vypnutí zruší i připravenou (ještě nenahlášenou) platbu na další období.
$route('POST', '/admin/predplatne/prodluzovani', function () {
    require_admin();
    $t = tenant_row(TENANT);
    if (!$t || empty($t['billing'])) {
        redirect('/admin/predplatne');
    }
    $on = post('action') === 'on';
    pq("UPDATE tenants SET auto_renew = ?, reminded = '' WHERE slug = ?", [$on ? 1 : 0, TENANT]);
    if (!$on) {
        pq("UPDATE payments SET status = 'cancelled', note = 'automatické prodlužování vypnuto správcem webu' WHERE tenant = ? AND kind = 'service' AND status = 'pending'", [TENANT]);
    }
    audit('Předplatné: automatické prodlužování ' . ($on ? 'zapnuto' : 'vypnuto'));
    flash($on ? 'Automatické prodlužování je zapnuté.' : 'Automatické prodlužování je vypnuté. Edice doběhne do konce zaplaceného období a potom web přejde na edici Zdarma.', $on ? 'ok' : 'info');
    redirect('/admin/predplatne');
});
// Zrušení celého webu na žádost správce: smaže se po týdnu, do té doby lze žádost vzít zpět. V ukázkovém webu to nejde.
$route('POST', '/admin/predplatne/zrusit-web', function () {
    $u = require_admin();
    $t = tenant_row(TENANT);
    if (!$t || !empty($t['demo']) || !empty($GLOBALS['DEMO'])) {
        flash('Ukázkový web zrušit nelze.', 'error');
        redirect('/admin/predplatne');
    }
    if (post('action') === 'cancel') {
        if ($t['delete_at'] !== '') {
            tenant_delete_cancel($t, $u['email']);
            audit('Zrušení webu: žádost vzata zpět');
            flash('Žádost o smazání webu je zrušena. Web běží dál beze změny.');
        }
        redirect('/admin/predplatne#zrusit');
    }
    $errors = [];
    if (!password_verify((string) ($_POST['password'] ?? ''), $u['password_hash'])) {
        $errors[] = 'Heslo není správně.';
    }
    if (mb_strtolower(trim(post('confirm'))) !== TENANT) {
        $errors[] = 'Pro potvrzení opište adresu webu: ' . TENANT . '.';
    }
    if (!isset($_POST['understand'])) {
        $errors[] = 'Potvrďte, že rozumíte tomu, že se smažou všechna data.';
    }
    if ($errors) {
        flash(implode(' ', $errors), 'error');
        redirect('/admin/predplatne#zrusit');
    }
    $on = tenant_delete_request($t, $u['name'] . ' <' . $u['email'] . '>');
    audit('Zrušení webu: žádost o smazání', 'smazat ' . $on);
    $text = "Dobrý den,\n\n" . $u['name'] . " požádal(a) o zrušení webu " . $t['name'] . " (" . tenant_address(TENANT) . ").\nWeb včetně všech účtů, dokumentů a dalších dat bude nenávratně smazán "
        . cz_date($on) . ".\n\nDo té doby web běží normálně a kterýkoli správce může žádost vzít zpět: " . tenant_url($t, '/admin/predplatne') . "\nZálohu dat si stáhnete zde: " . tenant_url($t, '/admin/zaloha');
    $to = array_unique(array_filter(array_merge(array_column(all("SELECT email FROM users WHERE role = 'admin' AND active = 1"), 'email'), [$t['owner_email']])));
    foreach ($to as $email) {
        platform_mail($email, 'Web bude smazán ' . cz_date($on) . ' – ' . $t['name'], $text);
    }
    if (pset('contact_email') !== '') {
        platform_mail(pset('contact_email'), 'Žádost o smazání webu: ' . $t['name'], 'Správce ' . $u['name'] . ' <' . $u['email'] . '> požádal o smazání webu ' . TENANT . '. Smaže se ' . cz_date($on) . ', pokud žádost nevezme zpět.');
    }
    flash('Web bude smazán ' . cz_date($on) . '. Do té doby můžete žádost vzít zpět. Všem správcům jsme poslali e-mail.', 'info');
    redirect('/admin/predplatne#zrusit');
});
$route('POST', '/admin/predplatne/zaplaceno', function () {
    require_admin();
    $p = pq("SELECT * FROM payments WHERE tenant = ? AND kind = 'service' AND status = 'pending' ORDER BY id DESC LIMIT 1", [TENANT])->fetch();
    if ($p) {
        pq("UPDATE payments SET status = 'claimed', claimed_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $p['id']]);
        audit('Předplatné: nahlášena odeslaná platba', $p['amount'] . ' Kč, variabilní symbol ' . $p['vs']);
        flash('Děkujeme. Jakmile platbu uvidíme na účtu, předplatné prodloužíme a pošleme Vám potvrzení.');
    }
    redirect('/admin/predplatne');
});
