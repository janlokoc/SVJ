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
    flash('Web je založen' . ($b['mode'] === 'trial' ? ' a zkušební doba běží do ' . cz_date($b['until']) : '') . '. Začněte nastavením, kontakty na výbor a seznamem jednotek.');
    redirect('/admin');
});

$route('GET', '/admin/predplatne', function () {
    require_admin();
    $t = tenant_row(TENANT);
    $state = billing_state($t);
    $payment = $state['mode'] === 'free' ? null : billing_pending($t);
    $iban = cz_iban(pset('account'));
    render('admin/billing', ['title' => 'Předplatné', 't' => $t, 'state' => $state, 'payment' => $payment, 'iban' => $iban, 'deleteOn' => tenant_delete_date($t), 'domainPayment' => domain_payment($t), 'aiPayment' => ai_payment($t),
        'aiQr' => ($ap = ai_payment($t)) && $iban ? qr_svg(spayd($iban, (int) $ap['amount'], $ap['vs'], 'AI Plus ' . $t['slug'], pset('recipient')), 'QR platba za AI Plus') : '',
        'domainQr' => ($dp = domain_payment($t)) && $iban ? qr_svg(spayd($iban, (int) $dp['amount'], $dp['vs'], 'Domena ' . $dp['item'], pset('recipient')), 'QR platba za doménu') : '',
        'qr' => $payment && $iban ? qr_svg(spayd($iban, (int) $payment['amount'], $payment['vs'], 'Web SVJ ' . $t['slug'], pset('recipient')), 'QR platba') : '',
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
        $p = domain_payment_create(tenant_row(TENANT), $domain);
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
$route('POST', '/admin/predplatne/ai', function () {
    require_admin();
    $t = tenant_row(TENANT);
    $action = post('action');
    $p = ai_payment($t);
    if ($action === 'request' && !$p && ai_configured()) {
        $p = ai_payment_create($t);
        audit('AI Plus: objednávka', $p['amount'] . ' Kč, variabilní symbol ' . $p['vs']);
        flash('Doplněk AI Plus je objednán. Zapne se po připsání platby.');
    } elseif ($action === 'claim' && $p && $p['status'] === 'pending') {
        pq("UPDATE payments SET status = 'claimed', claimed_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $p['id']]);
        audit('AI Plus: nahlášena odeslaná platba', $p['amount'] . ' Kč, variabilní symbol ' . $p['vs']);
        flash('Děkujeme. Jakmile platbu uvidíme na účtu, AI Plus zapneme.');
    } elseif ($action === 'cancel' && $p && $p['status'] === 'pending') {
        pq("UPDATE payments SET status = 'cancelled', note = 'objednávka zrušena správcem webu' WHERE id = ?", [$p['id']]);
        flash('Objednávka AI Plus je zrušena.', 'info');
    }
    redirect('/admin/predplatne#ai');
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
