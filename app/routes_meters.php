<?php
declare(strict_types=1);

// Dálkové odečty: obrazovka správce a adresa pro příjem dat. Funkce jsou v meters_lib.php (potřebuje je i denní úloha webu).

$route('GET', '/admin/odecty/dalkove', function () {
    require_admin();
    $prev = date('Y-m', strtotime('first day of last month'));
    $start = $prev . '-01';
    $end = date('Y-m-t', strtotime($start));
    $withData = (int) val('SELECT COUNT(DISTINCT unit_id) FROM readings WHERE read_date BETWEEN ? AND ?', [$start, $end]);
    $notices = [];
    foreach (all("SELECT `key`, value FROM settings WHERE `key` LIKE 'meter_notice_2%' ORDER BY `key` DESC LIMIT 12") as $r) {
        $notices[substr($r['key'], 13)] = explode('|', $r['value']);
    }
    $token = setting('meter_api_token');
    render('admin/meters', ['title' => 'Dálkové odečty', 'remote' => remote_kinds(), 'prev' => $prev, 'withData' => $withData, 'units' => (int) val('SELECT COUNT(*) FROM units'),
        'bySource' => array_column(all('SELECT source, COUNT(*) n FROM readings WHERE read_date >= ? GROUP BY source', [date('Y-m-d', strtotime('-60 days'))]), 'n', 'source'),
        'notices' => $notices, 'apiUrl' => $token !== '' ? absolute_url('/api/odecty/' . $token) : '', 'preview' => $_SESSION['meter_preview'] ?? null]);
    unset($_SESSION['meter_preview']);
});
$route('POST', '/admin/odecty/dalkove', function () {
    require_admin();
    $do = post('do');
    if ($do === 'kinds') {
        $kinds = array_values(array_intersect(array_keys(READING_KINDS), (array) ($_POST['kinds'] ?? [])));
        meter_setting('remote_kinds', implode(',', $kinds) ?: '-');
        meter_setting('meter_notice_auto', isset($_POST['auto']) ? '1' : '0');
        audit('meter_settings', 'na dálku: ' . (implode(', ', array_map(fn($k) => READING_KINDS[$k], $kinds)) ?: 'nic') . ' · automatické upozornění ' . (isset($_POST['auto']) ? 'zapnuto' : 'vypnuto'));
        flash('Nastavení dálkových odečtů je uložené.');
    } elseif ($do === 'token') {
        meter_setting('meter_api_token', bin2hex(random_bytes(20)));
        audit('meter_settings', 'nová adresa pro příjem dat');
        flash('Vytvořili jsme novou adresu pro příjem dat. Ta dosavadní přestala fungovat – pošlete odečtové firmě novou.');
    } elseif ($do === 'token-off') {
        meter_setting('meter_api_token', '');
        flash('Příjem dat přes adresu je vypnutý.', 'info');
    } elseif ($do === 'notice') {
        $ym = preg_match('/^\d{4}-\d{2}$/', post('mesic')) ? post('mesic') : date('Y-m', strtotime('first day of last month'));
        $n = meter_notice_send($ym);
        flash($n ? 'Upozornění odeslali jsme ' . $n . ' obyvatelům.' : 'Za tento měsíc nejsou žádné odečty, nebo web neumí posílat e-maily.', $n ? 'ok' : 'error');
    } elseif ($do === 'preview' || $do === 'save') {
        $text = $do === 'preview' ? (is_uploaded_file($_FILES['file']['tmp_name'] ?? '') && ($_FILES['file']['size'] ?? 0) <= 5 * 1048576 ? (string) file_get_contents($_FILES['file']['tmp_name']) : '') : (string) base64_decode(post('data'), true);
        $rows = $text !== '' ? meter_csv_rows($text) : 'Vyberte soubor CSV (do 5 MB).';
        if (is_string($rows)) {
            flash($rows, 'error');
        } elseif ($do === 'preview') {
            $checked = meter_rows_check($rows);
            $_SESSION['meter_preview'] = ['data' => base64_encode($text), 'ok' => count(array_filter($checked, fn($c) => $c[1])), 'bad' => array_slice(array_values(array_filter($checked, fn($c) => !$c[1])), 0, 50),
                'sample' => array_slice(array_values(array_filter($checked, fn($c) => $c[1])), 0, 10), 'total' => count($checked)];
        } else {
            [$new, $upd] = meter_rows_save(meter_rows_check($rows), 'import');
            audit('meter_import', 'nových ' . $new . ', přepsaných ' . $upd);
            flash('Uloženo: nových odečtů ' . $new . ($upd ? ', přepsaných ' . $upd : '') . '.');
        }
    }
    redirect('/admin/odecty/dalkove');
});

// ---------- Automatický příjem dat (odečtová firma nebo vlastní sběrnice) ----------

$route('POST', '/api/odecty/([a-f0-9]{40})', function (string|int $token) {
    $token = (string) $token;
    header('Content-Type: application/json; charset=utf-8');
    $answer = function (int $code, array $body): never {
        http_response_code($code);
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (setting('meter_api_token') === '' || !hash_equals(setting('meter_api_token'), $token)) {
        $answer(403, ['ok' => false, 'error' => 'Neplatná adresa pro příjem dat.']);
    }
    if (!plan_has('odecty')) {
        $answer(402, ['ok' => false, 'error' => 'Odečty měřidel nejsou součástí edice tohoto webu.']);
    }
    $body = (string) file_get_contents('php://input', false, null, 0, 5 * 1048576);
    $json = json_decode($body, true);
    $rows = is_array($json) ? array_map(fn($r) => is_array($r) ? ['unit' => $r['unit'] ?? $r['jednotka'] ?? '', 'meter_no' => $r['meter_no'] ?? $r['meridlo'] ?? '', 'kind' => $r['kind'] ?? $r['druh'] ?? '',
        'amount' => (string) ($r['amount'] ?? $r['stav'] ?? ''), 'date' => $r['date'] ?? $r['datum'] ?? ''] : [], array_slice(isset($json['readings']) ? (array) $json['readings'] : $json, 0, 5000)) : meter_csv_rows($body);
    if (is_string($rows)) {
        $answer(400, ['ok' => false, 'error' => $rows]);
    }
    $checked = meter_rows_check($rows);
    [$new, $upd] = meter_rows_save($checked, 'api');
    $errors = array_map(fn($c) => ['row' => $c[0], 'error' => $c[2]], array_values(array_filter($checked, fn($c) => !$c[1])));
    audit('meter_import', 'automaticky · nových ' . $new . ', přepsaných ' . $upd . ($errors ? ', chybných ' . count($errors) : ''));
    $answer(200, ['ok' => true, 'saved' => $new, 'updated' => $upd, 'errors' => array_slice($errors, 0, 100)]);
});
