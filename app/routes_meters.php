<?php
declare(strict_types=1);

// Dálkové odečty: obrazovka správce a adresa pro příjem dat. Funkce jsou v meters_lib.php (potřebuje je i denní úloha webu).

$route('GET', '/admin/odecty/dalkove', function () {
    require_admin();
    meters_ready();
    $prev = date('Y-m', strtotime('first day of last month'));
    $start = $prev . '-01';
    $end = date('Y-m-t', strtotime($start));
    $withData = (int) val('SELECT COUNT(DISTINCT unit_id) FROM readings WHERE read_date BETWEEN ? AND ?', [$start, $end]);
    $notices = [];
    foreach (all("SELECT `key`, value FROM settings WHERE `key` LIKE 'meter\\_notice\\_2%' ESCAPE '\\' ORDER BY `key` DESC LIMIT 12") as $r) {
        $notices[substr($r['key'], 13)] = explode('|', $r['value']);
    }
    $token = setting('meter_api_token');
    render('admin/meters', ['title' => 'Dálkové odečty', 'remote' => remote_kinds(), 'prev' => $prev, 'withData' => $withData, 'units' => (int) val('SELECT COUNT(*) FROM units'),
        'bySource' => array_column(all('SELECT source, COUNT(*) n FROM readings WHERE read_date >= ? GROUP BY source', [date('Y-m-d', strtotime('-60 days'))]), 'n', 'source'),
        'notices' => $notices, 'plan' => meter_notice_plan(), 'missing' => meter_units_missing($prev), 'meterMissing' => meter_missing($prev),
        'pending' => all('SELECT * FROM reading_pending ORDER BY read_date DESC, id DESC LIMIT 200'),
        'apiLast' => one('SELECT * FROM meter_api_log WHERE ok = 1 ORDER BY id DESC LIMIT 1'),
        'apiFails' => (int) val('SELECT COUNT(*) FROM meter_api_log WHERE ok = 0 AND at >= ?', [date('Y-m-d H:i:s', strtotime('-7 days'))]),
        'apiLog' => all('SELECT * FROM meter_api_log ORDER BY id DESC LIMIT 10'),
        'history' => all('SELECT * FROM reading_history ORDER BY id DESC LIMIT 20'), 'apiBase' => absolute_url('/api/odecty'), 'apiToken' => $token, 'apiUrl' => $token !== '' ? absolute_url('/api/odecty/' . $token) : '', 'preview' => $_SESSION['meter_preview'] ?? null]);
    unset($_SESSION['meter_preview']);
});
$route('POST', '/admin/odecty/dalkove', function () {
    require_admin();
    $do = post('do');
    if ($do === 'kinds') {
        $kinds = array_values(array_intersect(array_keys(READING_KINDS), (array) ($_POST['kinds'] ?? [])));
        meter_setting('remote_kinds', implode(',', $kinds) ?: '-');
        meter_setting('meter_compare', isset($_POST['compare']) ? '1' : '0');
        audit('meter_settings', 'na dálku: ' . (implode(', ', array_map(fn($k) => READING_KINDS[$k], $kinds)) ?: 'nic') . ' · srovnání s průměrem domu ' . (isset($_POST['compare']) ? 'zapnuto' : 'vypnuto'));
        flash('Nastavení dálkových odečtů je uložené.');
    } elseif ($do === 'auto') {
        meter_setting('meter_notice_auto', isset($_POST['auto']) ? '1' : '0');
        audit('meter_settings', 'automatické měsíční upozornění ' . (isset($_POST['auto']) ? 'zapnuto' : 'vypnuto'));
        flash(isset($_POST['auto']) ? 'Automatické upozornění je zapnuté.' : 'Automatické upozornění je vypnuté – posílejte ho ručně.', isset($_POST['auto']) ? 'ok' : 'info');
        redirect('/admin/odecty/dalkove#upozorneni');
    } elseif ($do === 'token') {
        meter_setting('meter_api_token', bin2hex(random_bytes(20)));
        audit('meter_settings', 'nový klíč pro příjem dat');
        flash('Vytvořili jsme nový klíč pro příjem dat. Ten dosavadní přestal fungovat – předejte odečtové firmě nový.');
    } elseif ($do === 'token-off') {
        meter_setting('meter_api_token', '');
        flash('Příjem dat přes adresu je vypnutý.', 'info');
    } elseif ($do === 'ips') {
        $list = meter_ip_list_clean(post('ips'));
        if (is_array($list)) {
            flash($list['error'], 'error');
        } else {
            meter_setting('meter_api_ips', $list);
            audit('meter_settings', 'povolené IP pro příjem odečtů: ' . ($list ?: 'všechny'));
            flash($list ? 'Data se teď přijímají jen z adres: ' . $list . '.' : 'Data se přijímají z jakékoli adresy (jen se správným klíčem).');
        }
        redirect('/admin/odecty/dalkove#prijem');
    } elseif ($do === 'pending') {
        $ok = meter_pending_decide((int) post('id'), post('verdict') === 'approve');
        flash($ok ? (post('verdict') === 'approve' ? 'Odečet je schválený a uložený.' : 'Odečet je zamítnutý, neuložil se.') : 'Tento odečet už byl vyřízený.', $ok ? 'ok' : 'info');
        redirect('/admin/odecty/dalkove#ke-schvaleni');
    } elseif ($do === 'firm') {
        $mail = trim(post('firm_email'));
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            flash('E-mail odečtové firmy nemá správný tvar.', 'error');
        } else {
            meter_setting('meter_firm_email', $mail);
            meter_setting('meter_firm_auto', isset($_POST['firm_auto']) && $mail !== '' ? '1' : '0');
            meter_setting('meter_manual_fallback', isset($_POST['fallback']) ? '1' : '0');
            audit('meter_settings', 'chybějící data: firma ' . ($mail ?: '–') . ', automatická žádost ' . (isset($_POST['firm_auto']) ? 'ano' : 'ne') . ', ruční zadání obyvateli ' . (isset($_POST['fallback']) ? 'povoleno' : 'ne'));
            flash('Nastavení pro chybějící data je uložené.');
        }
        redirect('/admin/odecty/dalkove#chybi');
    } elseif ($do === 'ask-firm') {
        $ym = date('Y-m', strtotime('first day of last month'));
        $ok = meter_missing_request($ym, meter_missing($ym));
        flash($ok ? 'Odečtové firmě odešla žádost o doplnění dat.' : 'Žádost neodešla – zkontrolujte e-mail odečtové firmy, nebo už žádná data nechybí.', $ok ? 'ok' : 'error');
        redirect('/admin/odecty/dalkove#chybi');
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

// Klíč patří do hlavičky „Authorization: Bearer <klíč>“ na adrese /api/odecty (doporučeno, klíč se nedostane do záznamů serverů).
// Starší varianta s klíčem v adrese /api/odecty/<klíč> dál funguje. Ochrana: porovnání v konstantním čase, volitelně jen
// povolené IP adresy, blokace adresy po METER_API_FAIL_LIMIT neúspěšných pokusech za hodinu (správci dostanou e-mail),
// záznam každého pokusu, podezřelé řádky čekají na schválení (meter_guard), přepisy mají historii.
$meterApi = function (string $urlToken = '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $answer = function (int $code, array $body): never {
        http_response_code($code);
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
        exit;
    };
    $ip = client_ip();
    $header = meter_bearer();
    $auth = $header !== '' ? 'header' : 'url';
    $token = $header !== '' ? $header : strtolower($urlToken);
    if (meter_api_blocked($ip)) {
        $answer(429, ['ok' => false, 'error' => 'Příliš mnoho neúspěšných pokusů. Zkuste to za hodinu.']);
    }
    $secret = setting('meter_api_token');
    if ($secret === '' || $token === '' || !hash_equals($secret, $token)) {
        meter_api_fail($ip, $auth, $token === '' ? 'chybí klíč' : 'špatný klíč');
        $answer(403, ['ok' => false, 'error' => 'Neplatný klíč pro příjem dat.']);
    }
    if (!meter_ip_allowed($ip, setting('meter_api_ips'))) {
        meter_api_fail($ip, $auth, 'adresa není mezi povolenými');
        $answer(403, ['ok' => false, 'error' => 'Z této IP adresy příjem dat není povolený.']);
    }
    if (!plan_has('odecty')) {
        $answer(402, ['ok' => false, 'error' => 'Odečty měřidel nejsou součástí edice tohoto webu.']);
    }
    $body = (string) file_get_contents('php://input', false, null, 0, 5 * 1048576);
    $json = json_decode($body, true);
    $rows = is_array($json) ? array_map(fn($r) => is_array($r) ? ['unit' => $r['unit'] ?? $r['jednotka'] ?? '', 'meter_no' => $r['meter_no'] ?? $r['meridlo'] ?? '', 'kind' => $r['kind'] ?? $r['druh'] ?? '',
        'amount' => (string) ($r['amount'] ?? $r['stav'] ?? ''), 'date' => $r['date'] ?? $r['datum'] ?? ''] : [], array_slice(isset($json['readings']) ? (array) $json['readings'] : $json, 0, 5000)) : meter_csv_rows($body);
    if (is_string($rows)) {
        meter_api_log($ip, true, $auth, [0, 0, 0, 1], 'chybný formát');
        $answer(400, ['ok' => false, 'error' => $rows]);
    }
    $checked = meter_rows_check($rows);
    [$new, $upd, $held] = meter_rows_save($checked, 'api');
    $errors = array_map(fn($c) => ['row' => $c[0], 'error' => $c[2]], array_values(array_filter($checked, fn($c) => !$c[1])));
    meter_api_log($ip, true, $auth, [$new, $upd, $held, count($errors)]);
    audit('meter_import', 'automaticky (' . $ip . ') · nových ' . $new . ', přepsaných ' . $upd . ($held ? ', ke schválení ' . $held : '') . ($errors ? ', chybných ' . count($errors) : ''));
    if ($held) {
        foreach (meter_admin_emails() as $to) {
            send_mail($to, 'Odečty ke schválení – ' . site('name'), "Dobrý den,\n\nautomatický příjem dat přinesl $held " . ($held === 1 ? 'odečet, který vypadá podezřele' : 'odečtů, které vypadají podezřele')
                . " (pokles stavu, nezvykle vysoká spotřeba nebo přepis staršího či ručního odečtu). Neuložili jsme je – zkontrolujte je prosím a schvalte, nebo zamítněte:\n" . absolute_url('/admin/odecty/dalkove#ke-schvaleni') . "\n");
        }
    }
    $answer(200, ['ok' => true, 'saved' => $new, 'updated' => $upd, 'held' => $held, 'errors' => array_slice($errors, 0, 100)]);
};
$route('POST', '/api/odecty', fn() => $meterApi(''));
$route('POST', '/api/odecty/([a-f0-9]{40})', fn(string|int $token) => $meterApi((string) $token));
