<?php
declare(strict_types=1);

// Modul G na webu společenství: edice Zdarma podle velikosti domu, jednorázové hlasování, ověření aplikace pro Android.

// Velký dům nad hranicí edice Zdarma dostane jednou zkušební dobu Standardu (levná kontrola: jen dokud nic neplatil ani nezkoušel).
if (TENANT !== '' && function_exists('plan_managed') && plan_managed()) {
    free_size_check(plan_tenant());
}

// Digital Asset Links pro aplikaci z Google Play (Trusted Web Activity).
$route('GET', '/\.well-known/assetlinks\.json', function () {
    twa_send();
});

/** Objednávka, nahlášení a zrušení jednorázového hlasování. */
$route('GET', '/admin/hlasovani/jednorazove', function () {
    require_admin();
    $t = plan_managed() ? tenant_row(TENANT) : null;
    if (!$t) {
        not_found();
    }
    $p = vote_payment($t);
    $iban = cz_iban(pset('account'));
    render('ext/pricing/vote_payment', ['title' => 'Jednorázové hlasování', 'p' => $p, 'iban' => $iban, 'active' => vote_extra_polls($t),
        'qr' => $p && $iban ? qr_svg(spayd($iban, pay_total($p), $p['vs'], 'Hlasovani ' . $t['slug'], pset('recipient'), true), 'QR platba za jednorázové hlasování') : '']);
});
$route('POST', '/admin/hlasovani/jednorazove', function () {
    require_admin();
    $t = plan_managed() ? tenant_row(TENANT) : null;
    if (!$t) {
        not_found();
    }
    $p = vote_payment($t);
    $action = post('action');
    if ($action === 'order') {
        $r = vote_order($t);
        if (is_string($r)) {
            flash($r, 'error');
            redirect('/admin/hlasovani');
        }
        audit('Jednorázové hlasování: objednávka', $r['amount'] . ' Kč, variabilní symbol ' . $r['vs']);
        flash('Jednorázové hlasování je objednané. Po připsání platby ho zapneme – platební údaje jsou níže.');
    } elseif ($action === 'claim' && $p && $p['status'] === 'pending') {
        pq("UPDATE payments SET status = 'claimed', claimed_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $p['id']]);
        audit('Jednorázové hlasování: nahlášena odeslaná platba', $p['amount'] . ' Kč, variabilní symbol ' . $p['vs']);
        flash('Děkujeme. Jakmile platbu uvidíme na účtu, hlasování navíc zapneme.');
    } elseif ($action === 'cancel' && $p && $p['status'] === 'pending') {
        pq("UPDATE payments SET status = 'cancelled', note = 'objednávka zrušena správcem webu' WHERE id = ?", [$p['id']]);
        flash('Objednávka jednorázového hlasování je zrušena.', 'info');
        redirect('/admin/hlasovani');
    }
    redirect('/admin/hlasovani/jednorazove');
});
