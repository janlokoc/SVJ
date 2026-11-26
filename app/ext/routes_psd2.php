<?php
declare(strict_types=1);

// Propojení s účty u dalších bank (PSD2, funkce v betě 'bank-psd2'). Adresy patří pod /admin/platby – hlídá je i edice (Platby na jednotku).

$psd2Guard = function (): array {
    $me = require_admin();
    if (!psd2_available()) {
        not_found();
    }
    psd2_ready();
    return $me;
};
$route('GET', '/admin/platby/banky', function () use ($psd2Guard) {
    $psd2Guard();
    $inst = psd2_configured() ? psd2_institutions() : 'Propojení s bankami zatím provozovatel služby nenastavil.';
    render('ext/bank/psd2', ['title' => 'Propojení s bankou', 'links' => psd2_links(), 'institutions' => is_array($inst) ? $inst : [], 'instError' => is_string($inst) ? $inst : null]);
});
$route('POST', '/admin/platby/banky', function () use ($psd2Guard) {
    $me = $psd2Guard();
    $action = post('action');
    if ($action === 'connect') {
        if (!psd2_configured()) {
            flash('Propojení s bankami zatím provozovatel služby nenastavil.', 'error');
            redirect('/admin/platby/banky');
        }
        $r = psd2_connect(post('institution'), $me);
        if (isset($r['error'])) {
            flash($r['error'], 'error');
            redirect('/admin/platby/banky');
        }
        audit('Platby na jednotku: propojení s bankou', post('institution'));
        redirect_external($r['link'], 'Pokračovat do banky');   // přihlášení do bankovnictví (adresa od agregátoru, https); po odeslání formuláře ne hlavičkou Location (CSP form-action)
    }
    $id = (int) post('link');
    $link = one('SELECT * FROM psd2_links WHERE id = ?', [$id]);
    if (!$link) {
        redirect('/admin/platby/banky');
    }
    if ($action === 'refresh') {
        [$new, $matched, $notes] = psd2_refresh($id);
        audit('Platby na jednotku: stažení z banky', $link['institution_name'] . ": nových $new");
        flash("Staženo: nových plateb $new, spárováno $matched." . ($notes ? ' ' . implode(' ', $notes) : ''), $notes && !$new ? 'error' : 'ok');
    } elseif ($action === 'delete') {
        psd2_delete($id);
        audit('Platby na jednotku: zrušení propojení s bankou', $link['institution_name']);
        flash('Propojení s bankou ' . $link['institution_name'] . ' je zrušené. Už stažené platby zůstávají.', 'info');
    }
    redirect('/admin/platby/banky');
});
// Návrat z bankovnictví: agregátor přidá ?ref=<reference> (a při zrušení ?error=…).
$route('GET', '/admin/platby/banky/navrat', function () use ($psd2Guard) {
    $psd2Guard();
    $ref = (string) ($_GET['ref'] ?? '');
    if (!preg_match('/^svj-[a-f0-9]{20}$/', $ref)) {
        flash('Neplatný návrat z banky. Zkuste propojení znovu.', 'error');
        redirect('/admin/platby/banky');
    }
    [$ok, $msg] = psd2_callback($ref, mb_substr((string) ($_GET['error'] ?? ''), 0, 80));
    audit('Platby na jednotku: návrat z banky', $ok ? 'propojeno' : 'nedokončeno');
    flash($msg, $ok ? 'ok' : 'error');
    redirect($ok ? '/admin/platby#banka' : '/admin/platby/banky');
});
