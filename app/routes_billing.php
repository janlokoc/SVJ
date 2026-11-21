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
    render('admin/billing', ['title' => 'Předplatné', 't' => $t, 'state' => $state, 'payment' => $payment, 'iban' => $iban,
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
$route('POST', '/admin/predplatne/zaplaceno', function () {
    require_admin();
    $p = pq("SELECT * FROM payments WHERE tenant = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [TENANT])->fetch();
    if ($p) {
        pq("UPDATE payments SET status = 'claimed', claimed_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $p['id']]);
        audit('Předplatné: nahlášena odeslaná platba', $p['amount'] . ' Kč, variabilní symbol ' . $p['vs']);
        flash('Děkujeme. Jakmile platbu uvidíme na účtu, předplatné prodloužíme a pošleme Vám potvrzení.');
    }
    redirect('/admin/predplatne');
});
