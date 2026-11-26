<?php
declare(strict_types=1);

// Modul I: přihlášení přes Google, Microsoft, Apple a Facebook na webu domu (logika v app/ext/sso_lib.php).

$ssoCheck = function (string $p): void {
    if (!isset(sso_available('site')[$p])) {
        not_found();
    }
};

// Tlačítko na přihlašovací stránce: přesměrování k poskytovateli.
$route('GET', '/prihlaseni/sso/(google|microsoft|apple|facebook)', function (string $p) use ($ssoCheck) {
    $ssoCheck($p);
    if (user()) {
        redirect(is_admin() ? '/admin' : '/');
    }
    header('Location: ' . sso_start($p, 'site', ['tenant' => TENANT, 'after' => sso_safe_path($_SESSION['after_login'] ?? '')]), true, 303);
    exit;
});

// Můj účet: propojení účtu poskytovatele (stálý identifikátor uživatele, funguje i se skrytým e-mailem Apple).
$route('POST', '/muj-ucet/sso/(google|microsoft|apple|facebook)/propojit', function (string $p) use ($ssoCheck) {
    $u = require_login();
    $ssoCheck($p);
    if (!empty($_SESSION['partner']) || $u['password_hash'] === NO_PASSWORD) {
        flash('Tento účet se přihlašuje přes partnerský portál, propojení s dalším účtem tu není k dispozici.', 'error');
        redirect('/muj-ucet');
    }
    redirect_external(sso_start($p, 'site', ['tenant' => TENANT, 'mode' => 'link', 'uid' => (int) $u['id'], 'after' => '/muj-ucet#sso']), 'Pokračovat na ' . SSO_PROVIDERS[$p]);   // po odeslání formuláře (CSP form-action)
});
$route('POST', '/muj-ucet/sso/(google|microsoft|apple|facebook)/odpojit', function (string $p) {
    $u = require_login();
    if (sso_site_unlink((int) $u['id'], $p)) {
        audit('sso_unlinked', SSO_PROVIDERS[$p]);
        flash('Propojení s účtem ' . SSO_PROVIDERS[$p] . ' je zrušené.');
    }
    redirect('/muj-ucet#sso');
});

// Návrat z návratové adresy (/sso/callback/…) s jednorázovým tokenem: přihlášení nebo dokončení propojení.
$route('GET', '/prihlaseni/sso/vstup/([a-f0-9]{64})', function (string $token) {
    $h = sso_handoff_take($token, 'site', TENANT);
    if (!$h) {
        flash(sso_message('expired', ''), 'error');
        redirect(user() ? '/muj-ucet' : '/prihlaseni');
    }
    $p = (string) $h['provider'];
    $label = SSO_PROVIDERS[$p] ?? $p;
    if (isset($h['error'])) {
        audit('sso_failed', $label . ' · ' . $h['error']);
        flash(sso_message((string) $h['error'], $p), 'error');
        redirect($h['mode'] === 'link' && user() ? '/muj-ucet#sso' : '/prihlaseni');
    }
    $id = (array) $h['identity'];
    if ($h['mode'] === 'link') {
        $u = user();
        if (!$u || (int) $u['id'] !== (int) $h['uid']) {
            flash(sso_message('link_wrong_user', $p), 'error');
            redirect('/prihlaseni');
        }
        if ($err = sso_site_link((int) $u['id'], $id)) {
            flash(sso_message($err, $p), 'error');
            redirect('/muj-ucet#sso');
        }
        audit('sso_linked', $label . ($id['email'] !== '' ? ' · ' . $id['email'] : ''));
        flash('Účet ' . $label . ' je propojený. Příště se můžete přihlásit tlačítkem „Přihlásit přes ' . $label . '“.');
        redirect('/muj-ucet#sso');
    }
    if (login_blocked($id['email'])) {
        flash(sso_message('blocked', $p), 'error');
        redirect('/prihlaseni');
    }
    $r = sso_site_resolve($id);
    if (isset($r['error'])) {
        q('INSERT INTO login_attempts (ip, at, email) VALUES (?,?,?)', [client_ip(), time(), $id['email']]);
        audit('sso_failed', $label . ' · ' . ($id['email'] !== '' ? $id['email'] : 'bez e-mailu') . ' · ' . $r['error']);
        flash(sso_message((string) $r['error'], $p, $id['email']), 'error');
        redirect('/prihlaseni');
    }
    $u = $r['user'];
    audit('sso_login', $label . ' · ' . $u['email'] . ($u['totp_enabled'] ? ' · následuje ověřovací kód' : ''), (int) $u['id']);
    $after = $h['after'] !== '' ? $h['after'] : null;
    if ($u['totp_enabled']) {
        // Dvoufázové ověření platí i po přihlášení přes poskytovatele (stejně jako po zadání hesla).
        session_regenerate_id(true);
        $_SESSION = ['t' => TENANT, 'pending' => ['uid' => (int) $u['id'], 'at' => time(), 'after' => $after]];
        redirect('/prihlaseni/overeni');
    }
    finish_login($u, $after);
});

// Samostatná instalace (bez platformy): návratová adresa je přímo na webu. Na platformě je na hlavní doméně (app/ext/site_sso.php).
if (!platform_enabled()) {
    $ssoCallback = fn(string $p) => sso_callback_respond($p);
    $route('GET', '/sso/callback/(google|microsoft|apple|facebook)', $ssoCallback);
    $route('POST', '/sso/callback/(google|microsoft|apple|facebook)', $ssoCallback);
}
