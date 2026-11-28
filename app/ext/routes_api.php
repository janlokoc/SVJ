<?php
declare(strict_types=1);

// REST API v1 webu domu (app/ext/api_lib.php). Veřejná dokumentace: https://<doména služby>/api .
// Odpovědi JSON; chyby {"ok": false, "error": "…"}; každé volání v api_log.

$apiOut = function (int $code, array $body): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
/** Ověří klíč a rozsah; vrací klíč, nebo odpoví chybou. */
$apiAuth = function (string $scope) use ($apiOut): array {
    $ip = client_ip();
    $k = api_authenticate(api_bearer(), $ip);
    if (isset($k[0]) && is_int($k[0])) {
        $apiOut($k[0], ['ok' => false, 'error' => $k[1]]);
    }
    if (!api_can($k, $scope)) {
        api_log((int) $k['id'], $ip, $GLOBALS['METHOD'], $GLOBALS['PATH'], 403, false, 'chybí oprávnění ' . $scope);
        $apiOut(403, ['ok' => false, 'error' => 'Klíč nemá oprávnění „' . $scope . '“. Správce ho může přidat v Administrace → API a napojení.']);
    }
    $GLOBALS['API_KEY'] = $k;
    return $k;
};
$apiDone = function (int $code, array $body, string $note = '') use ($apiOut): never {
    $k = $GLOBALS['API_KEY'] ?? ['id' => 0];
    api_log((int) $k['id'], client_ip(), $GLOBALS['METHOD'], $GLOBALS['PATH'], $code, $code < 400, $note);
    $apiOut($code, $body);
};
$apiJson = function () use ($apiDone): array {
    $body = (string) file_get_contents('php://input', false, null, 0, API_MAX_BODY + 1);
    if (strlen($body) > API_MAX_BODY) {
        $apiDone(413, ['ok' => false, 'error' => 'Tělo požadavku je větší než 5 MB.']);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        $apiDone(400, ['ok' => false, 'error' => 'Tělo požadavku musí být JSON (pole nebo objekt).']);
    }
    return $data;
};

$route('GET', '/api/v1', function () use ($apiAuth, $apiDone) {
    $k = $apiAuth('units:read');
    $apiDone(200, ['ok' => true, 'house' => site('name'), 'key' => $k['name'], 'scopes' => $k['scope_list'], 'docs' => function_exists('platform_url') ? platform_url('/api') : '']);
});
$route('GET', '/api/v1/units', function () use ($apiAuth, $apiDone) {
    $apiAuth('units:read');
    $apiDone(200, ['ok' => true, 'units' => api_units()]);
});
$route('GET', '/api/v1/ledger/balances', function () use ($apiAuth, $apiDone) {
    $apiAuth('ledger:read');
    $apiDone(200, ['ok' => true, 'balances' => api_balances()]);
});
$apiLedger = function (bool $receivable) use ($apiAuth, $apiDone, $apiJson) {
    $k = $apiAuth('ledger:write');
    if (function_exists('plan_has') && !plan_has('platby')) {
        $apiDone(402, ['ok' => false, 'error' => 'Konto bytu (Platby na jednotku) je součástí edice Premium.']);
    }
    $data = $apiJson();
    [$rows, $errors] = api_ledger_rows(array_is_list($data) ? $data : (array) ($data[$receivable ? 'receivables' : 'payments'] ?? []), $receivable);
    $parsed = ['receivables' => $receivable ? $rows : [], 'payments' => $receivable ? [] : $rows, 'skipped' => 0];
    $unmatched = ledger_preview($parsed)['unmatched'];
    $r = $rows ? ledger_import($parsed, 'api', 'API: ' . $k['name'], 0) : ['saved' => 0, 'units' => 0, 'unmatched' => 0];
    $apiDone(200, ['ok' => true, 'saved' => $r['saved'], 'units' => $r['units'], 'unmatched_vs' => $unmatched, 'errors' => array_slice($errors, 0, 100)],
        'konto: uloženo ' . $r['saved'] . ($errors ? ', chyb ' . count($errors) : ''));
};
$route('PUT', '/api/v1/ledger/receivables', fn() => $apiLedger(true));
$route('PUT', '/api/v1/ledger/payments', fn() => $apiLedger(false));
$route('POST', '/api/v1/ledger/import/pohoda', function () use ($apiAuth, $apiDone) {
    $k = $apiAuth('ledger:write');
    if (function_exists('plan_has') && !plan_has('platby')) {
        $apiDone(402, ['ok' => false, 'error' => 'Konto bytu (Platby na jednotku) je součástí edice Premium.']);
    }
    $parsed = ledger_pohoda_parse((string) file_get_contents('php://input', false, null, 0, LEDGER_MAX_BYTES + 1));
    if (is_string($parsed)) {
        $apiDone(400, ['ok' => false, 'error' => $parsed]);
    }
    $unmatched = ledger_preview($parsed)['unmatched'];
    $r = ledger_import($parsed, 'pohoda', 'API: ' . $k['name'], 0);
    $apiDone(200, ['ok' => true, 'saved' => $r['saved'], 'units' => $r['units'], 'unmatched_vs' => $unmatched, 'skipped' => $parsed['skipped']], 'POHODA: uloženo ' . $r['saved']);
});
// Odečty: náš tvar (pole {unit, meter_no, kind, amount, date}), CSV jako v administraci, nebo tvar služby DOMUS AMR.
$route('POST', '/api/v1/meter-readings', function () use ($apiAuth, $apiDone) {
    $apiAuth('meters:write');
    if (function_exists('plan_has') && !plan_has('odecty')) {
        $apiDone(402, ['ok' => false, 'error' => 'Odečty měřidel nejsou součástí edice tohoto webu.']);
    }
    $body = (string) file_get_contents('php://input', false, null, 0, API_MAX_BODY);
    $json = json_decode($body, true);
    $format = 'csv';
    if (is_array($json)) {
        $amr = api_amr_rows($json);
        $format = $amr !== null ? 'domus-amr' : 'json';
        $rows = $amr ?? array_map(fn($r) => is_array($r) ? ['unit' => $r['unit'] ?? '', 'meter_no' => $r['meter_no'] ?? '', 'kind' => $r['kind'] ?? '', 'amount' => (string) ($r['amount'] ?? ''),
            'date' => $r['date'] ?? ''] : [], array_slice(isset($json['readings']) ? (array) $json['readings'] : $json, 0, API_MAX_ROWS));
    } else {
        $rows = meter_csv_rows($body);
        if (is_string($rows)) {
            $apiDone(400, ['ok' => false, 'error' => $rows]);
        }
    }
    $checked = meter_rows_check($rows);
    [$new, $upd, $held] = meter_rows_save($checked, 'api');
    $errors = array_map(fn($c) => ['row' => $c[0], 'error' => $c[2]], array_values(array_filter($checked, fn($c) => !$c[1])));
    $apiDone(200, ['ok' => true, 'format' => $format, 'saved' => $new, 'updated' => $upd, 'held' => $held, 'errors' => array_slice($errors, 0, 100)],
        "odečty ($format): nových $new, přepsaných $upd" . ($held ? ", ke schválení $held" : ''));
});
$route('GET', '/api/v1/tickets', function () use ($apiAuth, $apiDone) {
    $apiAuth('tickets:read');
    $apiDone(200, ['ok' => true, 'tickets' => api_tickets((string) ($_GET['status'] ?? ''), (int) ($_GET['limit'] ?? 200))]);
});
$route('POST', '/api/v1/posts', function () use ($apiAuth, $apiDone, $apiJson) {
    $k = $apiAuth('posts:write');
    $d = $apiJson();
    $title = trim((string) ($d['title'] ?? ''));
    $body = trim((string) ($d['body'] ?? ''));
    if ($title === '' || mb_strlen($title) > 200 || $body === '' || mb_strlen($body) > 20000) {
        $apiDone(400, ['ok' => false, 'error' => 'Vyplňte title (nejvýše 200 znaků) a body (nejvýše 20 000 znaků).']);
    }
    $vis = in_array($d['visibility'] ?? 'public', ['public', 'residents'], true) ? (string) ($d['visibility'] ?? 'public') : 'public';
    q('INSERT INTO posts (title, body, visibility, pinned, published_at, author_id) VALUES (?,?,?,?,?,?)', [$title, $body, $vis, 0, date('Y-m-d'), null]);   // autor NULL: klíč není uživatel
    $id = (int) db()->lastInsertId();
    audit('api_post', 'aktualita přes API (' . $k['name'] . '): ' . mb_substr($title, 0, 100));
    if (function_exists('push_notify') && function_exists('push_all_user_ids')) {
        push_notify(push_all_user_ids(), site('name'), 'Nová aktualita: ' . $title, '/aktuality/' . $id);
    }
    $apiDone(201, ['ok' => true, 'id' => $id, 'url' => absolute_url('/aktuality/' . $id)], 'aktualita ' . $id);
});

// ---------- Převod obsahu (např. ze starého webu): jednotky, dokumenty, termíny, kontakty, lidé, starší aktuality ----------
$apiRows = function (array $data, string $key) {
    return array_is_list($data) ? $data : (array) ($data[$key] ?? []);
};
$route('PUT', '/api/v1/units', function () use ($apiAuth, $apiDone, $apiJson, $apiRows) {
    $apiAuth('units:write');
    [$new, $upd, $err] = api_units_save($apiRows($apiJson(), 'units'));
    $apiDone(200, ['ok' => true, 'created' => $new, 'updated' => $upd, 'errors' => array_slice($err, 0, 100)], "jednotky: nových $new, upravených $upd");
});
$route('PUT', '/api/v1/events', function () use ($apiAuth, $apiDone, $apiJson, $apiRows) {
    $apiAuth('events:write');
    [$new, $upd, $err] = api_events_save($apiRows($apiJson(), 'events'));
    $apiDone(200, ['ok' => true, 'created' => $new, 'updated' => $upd, 'errors' => array_slice($err, 0, 100)], "termíny: nových $new, upravených $upd");
});
$route('PUT', '/api/v1/contacts', function () use ($apiAuth, $apiDone, $apiJson, $apiRows) {
    $apiAuth('contacts:write');
    [$new, $upd, $err] = api_contacts_save($apiRows($apiJson(), 'contacts'));
    $apiDone(200, ['ok' => true, 'created' => $new, 'updated' => $upd, 'errors' => array_slice($err, 0, 100)], "kontakty: nových $new, upravených $upd");
});
$route('POST', '/api/v1/people', function () use ($apiAuth, $apiDone, $apiJson, $apiRows) {
    $k = $apiAuth('people:write');
    [$new, $linked, $err] = api_people_save($apiRows($apiJson(), 'people'));
    if ($new || $linked) {
        audit('users_imported', "API ({$k['name']}): nových účtů $new, doplněno jednotek u $linked – pozvánky neodeslány");
    }
    $apiDone(200, ['ok' => true, 'created' => $new, 'linked' => $linked, 'invites_sent' => 0, 'errors' => array_slice($err, 0, 100)], "lidé: nových $new, doplněných $linked");
});
// Dokument: multipart/form-data (file + pole), nebo JSON {filename, content_base64, …}. Nejvýše 1 soubor na volání.
$route('POST', '/api/v1/documents', function () use ($apiAuth, $apiDone) {
    $k = $apiAuth('documents:write');
    $file = null;
    if (isset($_FILES['file'])) {
        $m = $_POST;
        $file = store_upload($_FILES['file']);
    } else {
        $body = (string) file_get_contents('php://input', false, null, 0, 3 * API_MAX_BODY);
        $m = json_decode($body, true);
        if (!is_array($m)) {
            $apiDone(400, ['ok' => false, 'error' => 'Pošlete soubor jako multipart/form-data (pole file), nebo JSON s filename a content_base64.']);
        }
        if (($m['content_base64'] ?? '') !== '') {
            $data = base64_decode((string) $m['content_base64'], true);
            if ($data === false || $data === '') {
                $apiDone(400, ['ok' => false, 'error' => 'content_base64 není platný base64.']);
            }
            $file = store_file_data((string) ($m['filename'] ?? 'dokument'), $data);
        }
    }
    if (is_string($file)) {
        $apiDone(400, ['ok' => false, 'error' => $file]);
    }
    $r = api_document_save($m, $file);
    if (is_string($r)) {
        if ($file) {
            @unlink(UPLOAD_DIR . '/' . $file[0]);
        }
        $apiDone(400, ['ok' => false, 'error' => $r]);
    }
    [$id, $created] = $r;
    $apiDone($created ? 201 : 200, ['ok' => true, 'id' => $id, 'created' => $created], ($created ? 'dokument nový ' : 'dokument upraven ') . $id);
});
$route('PUT', '/api/v1/maintenance', function () use ($apiAuth, $apiDone, $apiJson, $apiRows) {
    $apiAuth('maintenance:write');
    if (function_exists('plan_has') && !plan_has('revize')) {
        $apiDone(402, ['ok' => false, 'error' => 'Hlídání revizí je součástí edice Standard a Premium.']);
    }
    [$new, $upd, $err] = api_maintenance_save($apiRows($apiJson(), 'items'));
    $apiDone(200, ['ok' => true, 'created' => $new, 'updated' => $upd, 'errors' => array_slice($err, 0, 100)], "revize: nových $new, upravených $upd");
});
// Starší aktuality ze starého webu: s datem v minulosti a bez upozornění obyvatelům; upsert podle ext_id.
$route('PUT', '/api/v1/posts', function () use ($apiAuth, $apiDone, $apiJson, $apiRows) {
    $apiAuth('posts:write');
    [$new, $upd, $err] = api_posts_import($apiRows($apiJson(), 'posts'));
    $apiDone(200, ['ok' => true, 'created' => $new, 'updated' => $upd, 'notified' => 0, 'errors' => array_slice($err, 0, 100)], "aktuality (převod): nových $new, upravených $upd");
});

// ---------- Administrace: klíče API a MCP ----------

$adminApi = function () {
    $me = require_admin();
    api_ready();
    if ($GLOBALS['METHOD'] === 'POST') {
        if (post('do') === 'create') {
            $ips = function_exists('meter_ip_list_clean') ? meter_ip_list_clean(post('ips')) : '';
            $scopes = array_map('strval', (array) ($_POST['scopes'] ?? []));
            if (is_array($ips)) {
                flash($ips['error'], 'error');
            } elseif (!array_intersect($scopes, array_keys(API_SCOPES))) {
                flash('Vyberte aspoň jedno oprávnění.', 'error');
            } else {
                [, $plain] = api_key_create(post('name'), $scopes, $ips, (int) $me['id']);
                $_SESSION['api_new_key'] = $plain;
                flash('Klíč je vytvořený. Zkopírujte si ho hned – znovu ho už neuvidíte.');
            }
        } elseif (post('do') === 'update') {
            $ips = function_exists('meter_ip_list_clean') ? meter_ip_list_clean(post('ips')) : '';
            if (is_array($ips)) {
                flash($ips['error'], 'error');
            } else {
                flash(api_key_update((int) post('id'), array_map('strval', (array) ($_POST['scopes'] ?? [])), $ips) ? 'Oprávnění klíče jsou upravená – platí hned, klíč zůstává stejný.' : 'Vyberte aspoň jedno oprávnění.', 'info');
            }
        } elseif (post('do') === 'revoke') {
            flash(api_key_revoke((int) post('id')) ? 'Klíč je zrušený, přestal platit okamžitě.' : 'Klíč už zrušený je.', 'info');
        }
        redirect('/admin/api');
    }
    $new = $_SESSION['api_new_key'] ?? '';
    unset($_SESSION['api_new_key']);
    render('ext/api/admin', ['title' => 'API a napojení', 'keys' => all('SELECT * FROM api_keys ORDER BY revoked_at <> \'\', id DESC'), 'new' => $new,
        'log' => all('SELECT l.*, k.name AS key_name FROM api_log l LEFT JOIN api_keys k ON k.id = l.key_id ORDER BY l.id DESC LIMIT 30'),
        'fails' => (int) val('SELECT COUNT(*) FROM api_log WHERE ok = 0 AND at >= ?', [date('Y-m-d H:i:s', strtotime('-7 days'))])]);
};
// Převod ze starého webu: klíč s oprávněními pro převod jedním tlačítkem (starý klíč pro převod se zruší), zadání, zrušení.
$adminMigration = function () {
    $me = require_admin();
    api_ready();
    if ($GLOBALS['METHOD'] === 'POST') {
        $old = api_migration_key();
        if (post('do') === 'key') {
            if ($old) {
                api_key_revoke((int) $old['id']);
            }
            [, $plain] = api_key_create(API_MIGRATION_KEY, API_MIGRATION_SCOPES, '', (int) $me['id']);
            $_SESSION['api_migration_key'] = $plain;
        } elseif (post('do') === 'revoke' && $old) {
            api_key_revoke((int) $old['id']);
            flash('Klíč pro převod je zrušený. Asistent už do webu nic nezapíše.');
        }
        redirect('/admin/prevod');
    }
    $new = $_SESSION['api_migration_key'] ?? '';
    unset($_SESSION['api_migration_key']);
    render('ext/api/migration', ['title' => 'Převod ze starého webu', 'key' => api_migration_key(), 'new' => $new]);
};
$route('GET', '/admin/prevod', $adminMigration);
$route('POST', '/admin/prevod', $adminMigration);
$route('GET', '/admin/api', $adminApi);
$route('POST', '/admin/api', $adminApi);

// ---------- MCP (AI asistenti): POST /mcp, klíč v hlavičce nebo jako poslední část adresy ----------
$mcpHouse = function (string $urlKey = '') use ($apiOut) {
    $ip = client_ip();
    $k = api_authenticate(api_bearer($urlKey), $ip);
    if (isset($k[0]) && is_int($k[0])) {
        header('WWW-Authenticate: Bearer realm="spolecnyweb"');
        $apiOut($k[0], ['error' => $k[1]]);
    }
    $req = json_decode((string) file_get_contents('php://input', false, null, 0, 8 * 1048576), true);
    if (!is_array($req)) {
        $apiOut(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
    }
    $batch = array_is_list($req);
    $responses = array_values(array_filter(array_map(fn($m) => api_mcp_handle($k, $m), $batch ? $req : [$req])));
    $tools = array_filter(array_map(fn($m) => is_array($m) && ($m['method'] ?? '') === 'tools/call' ? (string) ($m['params']['name'] ?? '') : '', $batch ? $req : [$req]));
    if ($tools) {
        api_log((int) $k['id'], $ip, 'MCP', 'mcp: ' . implode(', ', $tools), 200, true);
    }
    if (!$responses) {
        http_response_code(202);
        exit;
    }
    $apiOut(200, $batch ? $responses : $responses[0]);
};
$route('POST', '/mcp', fn() => $mcpHouse(''));
$route('POST', '/mcp/(swk_[a-f0-9]{40})', fn(string $key) => $mcpHouse($key));
$route('GET', '/mcp', function () {
    header('Allow: POST');
    http_response_code(405);
    exit;
});
