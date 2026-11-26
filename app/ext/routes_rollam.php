<?php
declare(strict_types=1);

// Modul B – hlasování mimo zasedání: potvrzení hlasu kódem / Bank iD, protokol, hlasovací lístky, listinné hlasy (app/ext/rollam_lib.php).

// ---------- Vlastník: potvrzení hlasu kódem ----------

$rollamConfirm = function (int $pid) {
    $u = require_login();
    $p = rollam_pending($pid);
    if (!$p || (int) $p['user_id'] !== (int) $u['id'] || !in_array($p['method'], ['email', 'sms'], true)) {
        not_found();
    }
    $poll = one('SELECT * FROM polls WHERE id = ?', [$p['poll_id']]) ?? not_found();
    if ($p['status'] !== 'pending') {
        flash('Tento hlas už nečeká na potvrzení.', 'info');
        redirect('/hlasovani/' . $poll['id']);
    }
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $r = rollam_confirm($pid, (int) $u['id'], post('code'));
        if ($r['ok']) {
            flash('Děkujeme, hlas za jednotku ' . unit_name($r['unit']) . ' je ověřen a zaznamenán.');
            redirect('/hlasovani/' . $poll['id']);
        }
        if ($r['final']) {
            flash($r['error'], 'error');
            redirect('/hlasovani/' . $poll['id']);
        }
        $errors[] = $r['error'];
        http_response_code(422);
    }
    $items = all('SELECT * FROM poll_items WHERE poll_id = ? ORDER BY sort, id', [$poll['id']]);
    render('ext/rollam/confirm', ['title' => 'Potvrzení hlasu', 'poll' => $poll, 'pending' => $p, 'items' => $items, 'errors' => $errors,
        'unit' => one('SELECT * FROM units WHERE id = ?', [$p['unit_id']]), 'target' => rollam_code_target($p), 'choices' => (array) json_decode((string) $p['choices'], true)]);
};
$route('GET', '/hlasovani/potvrdit/(\d+)', $rollamConfirm);
$route('POST', '/hlasovani/potvrdit/(\d+)', $rollamConfirm);
$route('POST', '/hlasovani/potvrdit/(\d+)/znovu', function (int $pid) {
    $u = require_login();
    $p = rollam_pending($pid);
    if (!$p || (int) $p['user_id'] !== (int) $u['id']) {
        not_found();
    }
    $err = rollam_send_code($pid);
    flash($err ?? 'Poslali jsme nový kód. Předchozí kód už neplatí.', $err ? 'error' : 'ok');
    redirect('/hlasovani/potvrdit/' . $pid);
});

// ---------- Vlastník: ověření přes Bank iD ----------

$route('GET', '/hlasovani/bankid/(\d+)', function (int $pid) {
    $u = require_login();
    $r = rollam_bankid_start($pid, (int) $u['id']);
    if (is_array($r)) {
        $p = rollam_pending($pid);
        flash($r[0], 'error');
        redirect($p && (int) $p['user_id'] === (int) $u['id'] ? '/hlasovani/' . $p['poll_id'] : '/hlasovani');
    }
    header('Location: ' . $r, true, 303);
    exit;
});
$route('GET', '/bankid/navrat', function () {
    $u = require_login();
    $r = rollam_bankid_finish((string) ($_GET['state'] ?? ''), (string) ($_GET['code'] ?? ''), (string) ($_GET['error'] ?? ''), (int) $u['id']);
    flash($r['ok'] ? 'Děkujeme, hlas za jednotku ' . unit_name($r['unit']) . ' je ověřen přes Bank iD a zaznamenán.' : $r['error'], $r['ok'] ? 'ok' : 'error');
    redirect(isset($r['poll']) ? '/hlasovani/' . $r['poll'] : '/hlasovani');
});

// ---------- Správa: protokol, lístky, listinné hlasy ----------

$route('GET', '/admin/hlasovani/(\d+)/protokol', function (int $id) {
    require_admin();
    $data = rollam_protocol($id) ?? not_found();
    render('ext/rollam/protocol', ['title' => 'Protokol o hlasování'] + $data);
});
$route('GET', '/admin/hlasovani/(\d+)/listky', function (int $id) {
    require_admin();
    $poll = one('SELECT * FROM polls WHERE id = ?', [$id]) ?? not_found();
    $unitId = (int) ($_GET['jednotka'] ?? 0);
    render('ext/rollam/ballots', ['title' => 'Hlasovací lístky', 'poll' => $poll, 'items' => all('SELECT * FROM poll_items WHERE poll_id = ? ORDER BY sort, id', [$id]),
        'units' => rollam_ballot_units($unitId ?: null), 'blank' => isset($_GET['prazdny'])]);
});
$rollamPaper = function (int $id) {
    $admin = require_admin();
    $poll = one('SELECT * FROM polls WHERE id = ?', [$id]) ?? not_found();
    $errors = [];
    $in = ['unit_id' => (int) ($_GET['jednotka'] ?? 0), 'choice' => [], 'signed_on' => '', 'received_on' => date('Y-m-d') <= $poll['closes_at'] ? date('Y-m-d') : $poll['closes_at'], 'note' => '', 'replace' => false];
    if ($GLOBALS['METHOD'] === 'POST') {
        $in = ['unit_id' => (int) post('unit_id'), 'choice' => (array) ($_POST['choice'] ?? []), 'signed_on' => post('signed_on'), 'received_on' => post('received_on'),
            'note' => mb_substr(post('note'), 0, 255), 'replace' => isset($_POST['replace'])];
        $scan = null;
        $errors = rollam_paper_errors($poll, $in);   // nejdřív kontrola, aby se sken bez platného zápisu vůbec neukládal
        if (!$errors && ($_FILES['scan']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $st = store_upload($_FILES['scan']);
            if (is_string($st)) {
                $errors[] = $st;
            } else {
                $scan = $st;
            }
        }
        if (!$errors) {
            $r = rollam_paper_vote($poll, $in, $admin, $scan);
            if (is_int($r)) {
                flash('Listinný hlas je zapsán.');
                redirect('/admin/hlasovani/' . $id . '/listina');
            }
            $errors = $r;
            if ($scan) {
                @unlink(UPLOAD_DIR . '/' . basename($scan[0]));
            }
        }
        http_response_code(422);
    }
    $paper = array_values(array_filter(rollam_records($id), fn($r) => $r['method'] === 'listina'));
    render('ext/rollam/paper', ['title' => 'Zapsat listinný hlas', 'poll' => $poll, 'in' => $in, 'errors' => $errors, 'units' => all_units(),
        'items' => all('SELECT * FROM poll_items WHERE poll_id = ? ORDER BY sort, id', [$id]), 'paper' => $paper,
        'voted' => array_flip(array_map('intval', array_column(all('SELECT DISTINCT v.unit_id FROM poll_votes v JOIN poll_items i ON i.id = v.item_id WHERE i.poll_id = ?', [$id]), 'unit_id')))]);
};
$route('GET', '/admin/hlasovani/(\d+)/listina', $rollamPaper);
$route('POST', '/admin/hlasovani/(\d+)/listina', $rollamPaper);
$route('GET', '/admin/hlasovani/listina/(\d+)/sken', function (int $rid) {
    require_admin();
    rollam_ready();
    $r = one('SELECT * FROM rollam_records WHERE id = ?', [$rid]) ?? not_found();
    $path = UPLOAD_DIR . '/' . basename((string) $r['scan_file']);
    if ($r['scan_file'] === '' || !is_file($path)) {
        not_found();
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $inline = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    header_remove('Content-Security-Policy');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . ($inline[$ext] ?? 'application/octet-stream'));
    header('Content-Disposition: ' . (isset($inline[$ext]) ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($r['scan_name'] ?: 'listek.' . $ext));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
});
