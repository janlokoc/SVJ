<?php
declare(strict_types=1);

// Závady v bytě: nájemník ↔ vlastník (app/ext/unitcase_lib.php). Výbor sem nevidí.

$unitcaseGuard = function (): array {
    $u = require_login();
    if (!unitcase_enabled()) {
        not_found();
    }
    unitcase_ready();
    return $u;
};

$route('GET', '/byt', function () use ($unitcaseGuard) {
    $u = $unitcaseGuard();
    render('ext/unitcase/list', ['title' => 'Závady v bytě', 'units' => unitcase_my_units($u), 'cases' => unitcase_list($u), 'side' => unitcase_side($u)]);
});

$unitcaseForm = function () use ($unitcaseGuard) {
    $u = $unitcaseGuard();
    $units = unitcase_my_units($u);
    $error = '';
    if ($GLOBALS['METHOD'] === 'POST') {
        $files = unitcase_store_files($_FILES['files'] ?? []);
        if (is_string($files)) {
            $error = $files . ' Nic nebylo odesláno.';
        } else {
            $r = unitcase_create($u, (int) post('unit_id'), post('kind'), post('subject'), post('body'), $files);
            if (is_int($r)) {
                flash(unitcase_side($u) === 'tenant' ? 'Odesláno. Vlastník dostal e-mail a upozornění; jeho odpověď uvidíte tady.' : 'Odesláno. Nájemník dostal e-mail a upozornění.');
                redirect('/byt/' . $r);
            }
            foreach ($files as $f) {
                @unlink(UPLOAD_DIR . '/' . $f[0]);
            }
            $error = $r;
        }
        http_response_code(422);
    }
    render('ext/unitcase/form', ['title' => unitcase_side($u) === 'tenant' ? 'Napsat vlastníkovi' : 'Napsat nájemníkovi', 'units' => $units, 'side' => unitcase_side($u), 'error' => $error]);
};
$route('GET', '/byt/novy', $unitcaseForm);
$route('POST', '/byt/novy', $unitcaseForm);

$unitcaseOne = function (int $id) use ($unitcaseGuard): array {
    $u = $unitcaseGuard();
    $case = one('SELECT * FROM unit_cases WHERE id = ?', [$id]);
    if (!$case || !unitcase_can_see($case, $u)) {
        not_found();
    }
    return [$u, $case];
};

$route('GET', '/byt/(\d+)', function (int $id) use ($unitcaseOne) {
    [$u, $case] = $unitcaseOne($id);
    $msgs = all('SELECT m.*, u.name AS author FROM unit_case_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.case_id = ? ORDER BY m.id', [$id]);
    unitcase_mark_read($id, (int) $u['id']);
    render('ext/unitcase/case', ['title' => $case['subject'], 'case' => $case, 'msgs' => $msgs, 'files' => unitcase_files($id), 'u' => $u,
        'unit' => unit_options()[(int) $case['unit_id']] ?? '', 'other' => unitcase_people((int) $case['unit_id'], unitcase_other(unitcase_side($u)))]);
});
$route('POST', '/byt/(\d+)', function (int $id) use ($unitcaseOne) {
    [$u, $case] = $unitcaseOne($id);
    if (post('status') !== '') {
        flash(unitcase_set_status($case, $u, post('status')) ? 'Stav je změněný, druhá strana dostala upozornění.' : 'Stav se nezměnil.', 'info');
        redirect('/byt/' . $id);
    }
    $body = trim(post('body'));
    if ($body === '' || mb_strlen($body) > UNITCASE_MAX) {
        flash('Napište prosím zprávu (nejvýše ' . UNITCASE_MAX . ' znaků).', 'error');
        redirect('/byt/' . $id . '#odpoved');
    }
    $files = unitcase_store_files($_FILES['files'] ?? []);
    if (is_string($files)) {
        flash($files . ' Zpráva nebyla odeslána.', 'error');
        redirect('/byt/' . $id . '#odpoved');
    }
    unitcase_add_message($id, $u, $body, $files);
    flash('Odpověď je odeslaná.');
    redirect('/byt/' . $id . '#konec');
});

// Příloha: jen účty přiřazené k jednotce vlákna (nájemník i vlastník), ne výbor.
$route('GET', '/byt/priloha/(\d+)', function (int $fileId) use ($unitcaseGuard) {
    $u = $unitcaseGuard();
    $f = one('SELECT f.*, c.unit_id FROM unit_case_files f JOIN unit_cases c ON c.id = f.case_id WHERE f.id = ?', [$fileId]);
    $path = $f ? UPLOAD_DIR . '/' . basename($f['filename']) : '';
    if (!$f || !unitcase_can_see(['unit_id' => $f['unit_id']], $u) || !is_file($path)) {
        not_found();
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $inline = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    header_remove('Content-Security-Policy');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . ($inline[$ext] ?? 'application/octet-stream'));
    header('Content-Disposition: ' . (isset($inline[$ext]) ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($f['original_name'] ?: 'priloha.' . $ext));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=3600');
    readfile($path);
});
