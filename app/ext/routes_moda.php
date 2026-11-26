<?php
declare(strict_types=1);

// Modul A: přihlášení odkazem, týdenní přehled, plakát do vchodu, připomenutí hlasování, export dat a zpracovatelská smlouva.

// ---------- Přihlášení odkazem z e-mailu ----------

$magicRequest = function () {
    if (user()) {
        redirect('/');
    }
    if (!empty($GLOBALS['DEMO'])) {
        redirect('/prihlaseni');
    }
    $state = '';
    $email = '';
    if ($GLOBALS['METHOD'] === 'POST') {
        $email = mb_substr(post('email'), 0, 190);
        $state = magic_link_request($email);
        if ($state === 'blocked') {
            http_response_code(429);
        }
    }
    render('ext/moda/magic_request', ['title' => 'Přihlášení odkazem', 'state' => $state, 'email' => $email, 'mailReady' => moda_mail_ready()]);
};
$route('GET', '/prihlaseni/odkaz', $magicRequest);
$route('POST', '/prihlaseni/odkaz', $magicRequest);

// Odkaz z e-mailu nejdřív jen ukáže tlačítko: kontrola odkazů v poštovních programech (např. Outlook) by jinak jednorázový odkaz spotřebovala.
$route('GET', '/prihlaseni/odkaz/([a-f0-9]{64})', function (string $token) {
    $found = magic_link_find($token);
    if (!$found) {
        http_response_code(404);
        render('error', ['title' => 'Odkaz neplatí', 'message' => 'Odkaz pro přihlášení už byl použit nebo vypršel (platí 15 minut). Požádejte o nový na stránce Přihlášení.']);
        return;
    }
    render('ext/moda/magic_confirm', ['title' => 'Přihlášení odkazem', 'token' => $token, 'name' => $found[1]['name']]);
});
$route('POST', '/prihlaseni/odkaz/([a-f0-9]{64})', function (string $token) {
    if (login_blocked('')) {
        flash('Příliš mnoho neúspěšných pokusů. Zkuste to prosím znovu za 15 minut.', 'error');
        redirect('/prihlaseni');
    }
    $u = magic_link_consume($token);
    if (!$u) {
        flash('Odkaz pro přihlášení už byl použit nebo vypršel. Požádejte o nový.', 'error');
        redirect('/prihlaseni/odkaz');
    }
    redirect(magic_link_login($u, $_SESSION['after_login'] ?? null));
});

// ---------- Týdenní přehled ----------

$route('POST', '/muj-ucet/tydenni-prehled', function () {
    $u = require_login();
    digest_set((int) $u['id'], isset($_POST['digest']));
    if (is_admin() && post('house') !== '') {
        $off = post('house') === 'off';
        if ($off !== !digest_house_on()) {
            moda_setting('digest_off', $off ? '1' : '0');
            audit($off ? 'Týdenní přehled pro celý dům vypnut' : 'Týdenní přehled pro celý dům zapnut');
        }
    }
    flash(isset($_POST['digest']) ? 'Týdenní přehled Vám budeme posílat e-mailem.' : 'Týdenní přehled Vám už posílat nebudeme.');
    redirect('/muj-ucet#tydenni-prehled');
});
$digestUnsub = function (string $token) {
    $u = digest_user_by_token($token);
    if (!$u) {
        http_response_code(404);
        render('error', ['title' => 'Odkaz neplatí', 'message' => 'Tento odkaz pro odhlášení přehledu neplatí. Přehled si můžete vypnout ve svém účtu.']);
        return;
    }
    $done = false;
    if ($GLOBALS['METHOD'] === 'POST') {
        digest_set((int) $u['id'], false);
        $done = true;
    }
    render('ext/moda/digest_unsub', ['title' => 'Odhlášení týdenního přehledu', 'token' => $token, 'done' => $done, 'email' => $u['email']]);
};
$route('GET', '/tydenni-prehled/odhlasit/([a-f0-9]{32})', $digestUnsub);
$route('POST', '/tydenni-prehled/odhlasit/([a-f0-9]{32})', $digestUnsub);

// ---------- Plakát do vchodu ----------

$route('GET', '/admin/plakat', function () {
    require_admin();
    $hasJoin = moda_join_link() !== '';
    $withJoin = $hasJoin && ($_GET['odkaz'] ?? '1') !== '0';
    render('ext/moda/poster', ['title' => 'Plakát do vchodu', 'p' => moda_poster($withJoin), 'hasJoin' => $hasJoin, 'withJoin' => $withJoin]);
});

// ---------- Připomenutí hlasování ----------

$route('POST', '/admin/hlasovani/(\d+)/pripomenout', function (int $id) {
    $admin = require_admin();
    $r = moda_poll_remind($id, $admin);
    if (is_string($r)) {
        flash($r, 'error');
    } else {
        flash('Připomenutí jsme poslali ' . $r['people'] . ' vlastníkům (e-mailů ' . $r['mails'] . ', upozornění do telefonu ' . $r['push'] . ').' . (function_exists('mailq_note') ? mailq_note() : ''));
    }
    redirect('/admin/hlasovani/' . $id . '/vysledky');
});

// ---------- Data domu: export a zpracovatelská smlouva ----------

$dataPage = function (?string $error = null) {
    require_admin();
    [$allowed, $next] = moda_export_allowed();
    render('ext/moda/data', ['title' => 'Data a smlouva', 'allowed' => $allowed, 'next' => $next, 'error' => $error, 'dpa' => moda_dpa() !== null,
        'backup' => !function_exists('plan_has') || plan_has('zaloha'), 'last' => setting('export_last'),
        'files' => (int) val('SELECT COUNT(*) FROM documents WHERE filename IS NOT NULL'), 'size' => (int) val('SELECT COALESCE(SUM(size), 0) FROM documents WHERE filename IS NOT NULL')]);
};
$route('GET', '/admin/data-domu', fn() => $dataPage());
$route('POST', '/admin/data-domu/export', function () use ($dataPage) {
    $admin = require_admin();
    if (!password_verify((string) ($_POST['current_password'] ?? ''), $admin['password_hash'])) {
        http_response_code(422);
        $dataPage('Heslo není správně. Export obsahuje osobní údaje, proto ho lze stáhnout jen po zadání hesla.');
        return;
    }
    [$allowed, $next] = moda_export_allowed();
    if (!$allowed) {
        flash('V edici Zdarma lze export stáhnout jednou za ' . EXPORT_FREE_DAYS . ' dní. Další bude možný ' . cz_date($next) . '.', 'error');
        redirect('/admin/data-domu');
    }
    @set_time_limit(600);
    try {
        $tmp = moda_export_zip();
    } catch (Throwable $e) {
        flash($e->getMessage() . ' Požádejte prosím provozovatele o export dat.', 'error');
        redirect('/admin/data-domu');
    }
    moda_setting('export_last', date('Y-m-d H:i:s'));
    audit('Export všech dat domu', 'ZIP, ' . file_size((int) filesize($tmp)));
    header_remove('Content-Security-Policy');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="export-dat-' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
});
$route('GET', '/admin/data-domu/smlouva', function () {
    require_admin();
    $dpa = moda_dpa() ?? not_found();
    render('ext/moda/dpa', ['title' => 'Smlouva o zpracování osobních údajů', 'd' => $dpa]);
});
