<?php
declare(strict_types=1);

// Kredit služeb domu (app/ext/credit_lib.php): zůstatek, výpis pohybů, dobití zálohovou fakturou s QR, ceny služeb.

$kreditPage = function () {
    $me = require_admin();
    if (!credit_managed() || !($t = tenant_row(TENANT))) {
        not_found();
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        $p = credit_topup_payment($t);
        if ($action === 'order') {
            $czk = post('amount') === 'jina' ? (int) preg_replace('/\D/', '', post('custom')) : (int) post('amount');
            $r = credit_topup_order($t, $czk);
            if (is_string($r)) {
                flash($r, 'error');
                redirect('/admin/kredit#dobit');
            }
            if (($r['kind'] ?? '') === 'partner') {
                audit('Kredit služeb: dobití na fakturu správce', money_czk($czk) . ' · VS ' . $r['vs']);
                flash('Dobití ' . money_czk($czk) . ' je na zálohové faktuře správce domu. Kredit připíšeme, až správce fakturu zaplatí.');
            } else {
                audit('Kredit služeb: objednávka dobití', money_czk($czk) . ' · VS ' . $r['vs']);
                flash('Dobití je objednané. Kredit připíšeme po připsání platby – zálohová faktura s QR kódem je níže.');
            }
            redirect('/admin/kredit#platba');
        }
        if ($action === 'claim' && $p && $p['status'] === 'pending') {
            pq("UPDATE payments SET status = 'claimed', claimed_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $p['id']]);
            audit('Kredit služeb: nahlášena odeslaná platba', $p['amount'] . ' Kč, variabilní symbol ' . $p['vs']);
            if (pset('contact_email') !== '') {
                platform_mail(pset('contact_email'), 'Nahlášená platba za kredit – ' . $t['name'], $t['name'] . ' (' . TENANT . ') nahlásil(a) platbu ' . pay_money($p) . ', VS ' . $p['vs'] . ".\nPotvrdit: " . platform_url('/sprava/platby'));
            }
            flash('Děkujeme. Jakmile platbu uvidíme na účtu, kredit připíšeme.');
        } elseif ($action === 'cancel' && $p && $p['status'] === 'pending') {
            pq("UPDATE payments SET status = 'cancelled', note = 'objednávka zrušena správcem webu' WHERE id = ?", [$p['id']]);
            audit('Kredit služeb: objednávka zrušena', $p['amount'] . ' Kč');
            flash('Objednávka dobití je zrušená.', 'info');
        }
        redirect('/admin/kredit');
    }
    $p = credit_topup_payment($t);
    $iban = cz_iban(pset('account'));
    render('ext/credit/admin', ['title' => 'Kredit služeb', 't' => $t, 'balance' => credit_balance_h(TENANT), 'rows' => credit_statement(TENANT, 50), 'payment' => $p,
        'qr' => $p && $iban ? qr_svg(spayd($iban, pay_total($p), $p['vs'], 'Kredit ' . TENANT, pset('recipient'), true), 'QR platba za kredit služeb') : '',
        'partner' => credit_partner_billed($t) ? partner_row((int) $t['partner_id']) : null, 'partnerPending' => credit_partner_pending($t), 'expiring' => credit_expiring(TENANT),
        'prices' => array_values(array_filter(credit_price_list(), fn($r) => $r['credit'] && beta_visible($r['key']))), 'months' => credit_setting('credit_valid_months'),
        'freeLeft' => function_exists('sms_free_left') && beta_visible('sms') ? sms_free_left(TENANT) : 0, 'freeYear' => function_exists('sms_free_allowance') && beta_visible('sms') ? sms_free_allowance(TENANT) : 0]);
};
$route('GET', '/admin/kredit', $kreditPage);
$route('POST', '/admin/kredit', $kreditPage);

// Doplněk k předplatnému: správce nahlásí odeslanou platbu za zbytek období (výzvu vystavil addon_set()).
$route('POST', '/admin/predplatne/doplnek', function () {
    require_admin();
    $p = pq("SELECT * FROM payments WHERE id = ? AND tenant = ? AND kind = 'addon' AND status = 'pending'", [(int) post('id'), TENANT])->fetch();
    if ($p) {
        pq("UPDATE payments SET status = 'claimed', claimed_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $p['id']]);
        audit('Doplněk: nahlášena odeslaná platba', $p['amount'] . ' Kč, variabilní symbol ' . $p['vs']);
        flash('Děkujeme. Jakmile platbu uvidíme na účtu, potvrdíme ji.');
    }
    redirect('/admin/predplatne#doplnky');
});
