<?php
// Modul F – provoz domu: vyúčtování služeb, plán oprav a fond oprav, smlouvy a pojištění. Logika je v app/ext/modf_*_lib.php.
// O edici rozhoduje PLAN_FEATURES (vyuctovani, smlouvy: Standard; plan-oprav: Premium) podle adresy.

/** Pošle uložený soubor (inline u PDF a obrázků). */
$modfSendFile = function (string $filename, string $name): never {
    $path = UPLOAD_DIR . '/' . basename($filename);
    if ($filename === '' || !is_file($path)) {
        not_found();
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $inline = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    header_remove('Content-Security-Policy');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . ($inline[$ext] ?? 'application/octet-stream'));
    header('Content-Disposition: ' . (isset($inline[$ext]) ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name !== '' ? $name : 'soubor.' . $ext));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=600');
    readfile($path);
    exit;
};

// ---------- Vyúčtování služeb: správce ----------

$route('GET', '/admin/vyuctovani', function () {
    require_admin();
    $y = (int) date('Y') - 1;
    render('ext/modf/settlements', ['title' => 'Vyúčtování služeb', 'rows' => modf_settlements(), 'errors' => [],
        'form' => ['title' => 'Vyúčtování služeb za rok ' . $y, 'period_from' => $y . '-01-01', 'period_to' => $y . '-12-31', 'delivery_date' => '', 'note' => '']]);
});
$route('POST', '/admin/vyuctovani', function () {
    $admin = require_admin();
    [$d, $errors] = modf_settlement_validate($_POST);
    if ($errors) {
        http_response_code(422);
        render('ext/modf/settlements', ['title' => 'Vyúčtování služeb', 'rows' => modf_settlements(), 'errors' => $errors, 'form' => $d]);
        return;
    }
    $id = modf_settlement_save($d, 0, (int) $admin['id']);
    audit('Nové vyúčtování služeb', $d['title']);
    flash('Vyúčtování je založené. Nahrajte soubory jednotek.');
    redirect('/admin/vyuctovani/' . $id);
});
$route('GET', '/admin/vyuctovani/(\d+)', function (int $id) {
    require_admin();
    $s = modf_settlement($id) ?? not_found();
    render('ext/modf/settlement', ['title' => $s['title'], 's' => $s, 'ov' => modf_settlement_overview($id), 'missing' => modf_units_missing($id), 'units' => all_units(), 'errors' => []]);
});
$route('POST', '/admin/vyuctovani/(\d+)', function (int $id) {
    $admin = require_admin();
    $s = modf_settlement($id) ?? not_found();
    $back = '/admin/vyuctovani/' . $id;
    $fail = function (string $msg) use ($back): never {
        flash($msg, 'error');
        redirect($back);
    };
    switch (post('action')) {
        case 'save':
            // Po zveřejnění už nejde měnit období ani den doručení (podle nich běží lhůty), jen název a poznámka.
            $in = $s['published_at'] !== '' ? array_merge($_POST, ['period_from' => $s['period_from'], 'period_to' => $s['period_to'], 'delivery_date' => $s['delivery_date']]) : $_POST;
            [$d, $errors] = modf_settlement_validate($in);
            if ($errors) {
                http_response_code(422);
                render('ext/modf/settlement', ['title' => $s['title'], 's' => array_merge($s, $d), 'ov' => modf_settlement_overview($id), 'missing' => modf_units_missing($id), 'units' => all_units(), 'errors' => $errors]);
                return;
            }
            modf_settlement_save($d, $id);
            audit('Úprava vyúčtování služeb', $d['title']);
            flash('Uloženo.');
            break;
        case 'upload':
            $files = uploaded_list('files');
            if (!$files) {
                $fail('Vyberte soubory vyúčtování (PDF).');
            }
            $ok = $unmatched = 0;
            $skipped = [];
            foreach ($files as $f) {
                if (!modf_settlement_file_ok((string) $f['name'])) {
                    $skipped[] = $f['name'] . ' (není PDF)';
                    continue;
                }
                $up = store_upload($f);
                if (is_string($up)) {
                    $skipped[] = $f['name'] . ' (' . $up . ')';
                    continue;
                }
                $it = modf_settlement_add_file($id, $up);
                (int) $it['unit_id'] ? $ok++ : $unmatched++;
            }
            audit('Nahrání vyúčtování jednotek', $s['title'] . ' · přiřazeno ' . $ok . ', bez jednotky ' . $unmatched . ', přeskočeno ' . count($skipped));
            flash('Nahráno a přiřazeno k jednotkám: ' . $ok . '.' . ($unmatched ? ' U ' . $unmatched . ' souborů se jednotka z názvu nepoznala – přiřaďte je níže.' : '')
                . ($skipped ? ' Přeskočeno: ' . implode(', ', $skipped) . '.' : ''), $skipped && !$ok && !$unmatched ? 'error' : 'ok');
            break;
        case 'assign':
            $err = modf_item_assign((int) post('item_id'), (int) post('unit_id'));
            $err ? $fail($err) : flash('Soubor je přiřazený k jednotce' . ($s['published_at'] !== '' ? ' a vlastníkům doručený.' : '.'));
            audit('Přiřazení vyúčtování k jednotce', $s['title']);
            break;
        case 'delete_item':
            $err = modf_item_delete((int) post('item_id'));
            $err ? $fail($err) : flash('Soubor je smazaný.');
            audit('Smazání souboru vyúčtování', $s['title']);
            break;
        case 'publish':
            $r = modf_settlement_publish($id, post('delivery_date'));
            if (is_string($r)) {
                $fail($r);
            }
            audit('Zveřejnění vyúčtování služeb', $s['title'] . ' · doručeno ' . $r . ' jednotkám k ' . cz_date(post('delivery_date')));
            flash('Vyúčtování je zveřejněné a doručené ' . $r . ' jednotkám. Vlastníci dostali e-mail' . (plan_has('push') ? ' a upozornění do telefonu' : '') . '.');
            break;
        case 'answer':
            $err = modf_objection_answer((int) post('objection_id'), $admin, post('answer'));
            $err ? $fail($err) : flash('Námitka je vyřízená, vlastník dostal odpověď.');
            break;
        case 'delete':
            $err = modf_settlement_delete($id);
            if ($err) {
                $fail($err);
            }
            audit('Smazání vyúčtování služeb', $s['title']);
            flash('Vyúčtování je smazané.');
            redirect('/admin/vyuctovani');
        default:
            not_found();
    }
    redirect($back);
});

// ---------- Vyúčtování služeb: vlastník ----------

$route('GET', '/vyuctovani', function () {
    $u = require_login();
    $items = modf_items_for_user($u);
    render('ext/modf/my', ['title' => 'Moje vyúčtování', 'items' => $items, 'objections' => modf_objections(array_column($items, 'id'))]);
});
$route('POST', '/vyuctovani/(\d+)/potvrdit', function (int $id) {
    $u = require_login();
    modf_confirm($id, $u) ? flash('Děkujeme, převzetí vyúčtování je potvrzené.') : flash('Převzetí už bylo potvrzené, nebo vyúčtování nepatří k Vaší jednotce.', 'error');
    redirect('/vyuctovani#v' . $id);
});
$route('POST', '/vyuctovani/(\d+)/namitka', function (int $id) {
    $u = require_login();
    $stored = null;
    if (($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (!modf_owns_item($u, $id)) {
            not_found();
        }
        $stored = store_upload($_FILES['file']);
        if (is_string($stored)) {
            flash('Příloha: ' . $stored, 'error');
            redirect('/vyuctovani#v' . $id);
        }
    }
    $r = modf_objection_add($id, $u, post('text'), $stored);
    if (is_string($r)) {
        if ($stored) {
            @unlink(UPLOAD_DIR . '/' . basename($stored[0]));
        }
        flash($r, 'error');
    } else {
        flash('Námitka je podaná. Výbor ji musí vyřídit do 30 dnů; odpověď uvidíte zde a přijde Vám e-mailem.');
    }
    redirect('/vyuctovani#v' . $id);
});
$route('GET', '/vyuctovani/soubor/(\d+)', function (int $id) use ($modfSendFile) {
    $u = require_login();
    $it = modf_item($id) ?? not_found();
    if (!modf_can_view_item($u, $it)) {
        not_found();
    }
    $modfSendFile($it['filename'], $it['original_name']);
});
$route('GET', '/vyuctovani/namitka/(\d+)/priloha', function (int $id) use ($modfSendFile) {
    $u = require_login();
    modf_ready();
    $o = one('SELECT * FROM modf_objections WHERE id = ?', [$id]) ?? not_found();
    if ($u['role'] !== 'admin' && (int) $o['user_id'] !== (int) $u['id']) {
        not_found();
    }
    $modfSendFile($o['filename'], $o['original_name']);
});

// ---------- Plán oprav a fond oprav ----------

$modfFundPage = function (array $errors = [], ?array $fundForm = null, ?array $repairForm = null) {
    $s = modf_fund_settings();
    $units = all_units();
    $repairs = modf_repairs();
    $proj = modf_fund_projection($s, $repairs, count($units));
    render('ext/modf/fund', ['title' => 'Plán oprav a fond oprav', 's' => $s, 'fundForm' => $fundForm ?? $s, 'repairs' => $repairs, 'proj' => $proj, 'chart' => modf_fund_chart($proj),
        'contrib' => modf_unit_contributions($s, $units), 'units' => $units, 'errors' => $errors,
        'repairForm' => $repairForm ?? ['title' => '', 'year' => (int) date('Y') + 1, 'cost' => '', 'priority' => 2, 'status' => 'planned', 'note' => '']]);
};
$route('GET', '/admin/plan-oprav', function () use ($modfFundPage) {
    require_admin();
    $modfFundPage();
});
$route('POST', '/admin/plan-oprav', function () use ($modfFundPage) {
    require_admin();
    if (post('action') === 'fund') {
        [$d, $errors] = modf_fund_validate($_POST);
        if ($errors) {
            http_response_code(422);
            $modfFundPage($errors, $d);
            return;
        }
        modf_fund_save($d);
        audit('Nastavení fondu oprav', 'stav ' . modf_money($d['balance']) . ' k ' . cz_date($d['balance_date']) . ' · příspěvek ' . modf_money($d['monthly']) . ($d['mode'] === 'flat' ? ' na jednotku' : ' za dům') . ' měsíčně' . ($d['published'] ? ' · zveřejněno obyvatelům' : ''));
        flash('Nastavení fondu je uložené.');
        redirect('/admin/plan-oprav');
    }
    [$d, $errors] = modf_repair_validate($_POST);
    if ($errors) {
        http_response_code(422);
        $modfFundPage($errors, null, $d);
        return;
    }
    modf_repair_save($d);
    audit('Nová oprava v plánu oprav', $d['title'] . ' · ' . $d['year'] . ' · ' . modf_money($d['cost']));
    flash('Oprava je v plánu.');
    redirect('/admin/plan-oprav#opravy');
});
$modfRepairForm = function (int $id) {
    require_admin();
    modf_ready();
    $r = one('SELECT * FROM modf_repairs WHERE id = ?', [$id]) ?? not_found();
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        [$d, $errors] = modf_repair_validate($_POST);
        if (!$errors) {
            modf_repair_save($d, $id);
            audit('Úprava opravy v plánu oprav', $d['title'] . ' · ' . $d['year'] . ' · ' . MODF_REPAIR_STATUS[$d['status']]);
            flash('Uloženo.');
            redirect('/admin/plan-oprav#opravy');
        }
        http_response_code(422);
        $r = $d + $r;
    }
    render('ext/modf/repair_form', ['title' => 'Úprava opravy', 'r' => $r, 'errors' => $errors]);
};
$route('GET', '/admin/plan-oprav/(\d+)', $modfRepairForm);
$route('POST', '/admin/plan-oprav/(\d+)', $modfRepairForm);
$route('POST', '/admin/plan-oprav/(\d+)/smazat', function (int $id) {
    require_admin();
    modf_ready();
    $r = one('SELECT * FROM modf_repairs WHERE id = ?', [$id]) ?? not_found();
    q('DELETE FROM modf_repairs WHERE id = ?', [$id]);
    audit('Smazání opravy z plánu oprav', $r['title']);
    flash('Oprava je odebraná z plánu.');
    redirect('/admin/plan-oprav#opravy');
});
// Obyvatelé: přehled jen pro čtení, pokud ho výbor zveřejnil.
$route('GET', '/plan-oprav', function () {
    $u = require_login();
    $s = modf_fund_settings();
    if (!$s['published'] && $u['role'] !== 'admin') {
        not_found();
    }
    $units = all_units();
    $repairs = array_values(array_filter(modf_repairs(), fn($r) => $r['status'] !== 'done'));
    $proj = modf_fund_projection($s, $repairs, count($units));
    $mine = array_values(array_filter(modf_unit_contributions($s, $units), fn($c) => in_array((int) $c['unit']['id'], user_unit_ids((int) $u['id']), true)));
    render('ext/modf/fund_public', ['title' => 'Plán oprav a fond oprav', 's' => $s, 'repairs' => $repairs, 'proj' => $proj, 'chart' => modf_fund_chart($proj), 'mine' => $mine]);
});

// ---------- Smlouvy a pojištění ----------

$route('GET', '/admin/smlouvy', function () {
    require_admin();
    $kind = (string) ($_GET['druh'] ?? '');
    render('ext/modf/contracts', ['title' => 'Smlouvy a pojištění', 'rows' => modf_contracts($kind), 'kind' => isset(MODF_CONTRACT_KINDS[$kind]) ? $kind : '',
        'feed' => absolute_url('/kalendar-spravy/' . modf_ics_token() . '.ics')]);
});
$modfContractForm = function (int $id = 0) {
    require_admin();
    $c = $id ? (modf_contract($id) ?? not_found()) : ['kind' => ($_GET['druh'] ?? '') === 'insurance' ? 'insurance' : 'contract', 'supplier_id' => 0, 'party' => '', 'subject' => '', 'number' => '',
        'start_date' => '', 'end_date' => '', 'notice_value' => 3, 'notice_unit' => 'm', 'auto_renew' => 0, 'renew_months' => 12, 'price' => '', 'price_period' => 'year', 'remind_days' => 30, 'note' => '',
        'filename' => '', 'original_name' => ''];
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        [$d, $errors] = modf_contract_validate($_POST);
        $file = null;
        if (!$errors && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $file = store_upload($_FILES['file']);
            if (is_string($file)) {
                $errors[] = 'Dokument: ' . $file;
                $file = null;
            }
        }
        if (!$errors) {
            if ($id && post('remove_file') === '1' && !$file) {
                modf_contract_file_remove($id);
            }
            $newId = modf_contract_save($d, $id, $file);
            audit($id ? 'Úprava smlouvy' : 'Nová smlouva', modf_contract_title($d));
            flash('Uloženo.' . (($ev = modf_contract_events($d)) ? ' Připomínka přijde ' . (int) $d['remind_days'] . ' dní před termínem „' . mb_strtolower($ev[0]['label']) . '“ (' . cz_date($ev[0]['date']) . ').' : ''));
            redirect('/admin/smlouvy' . ($newId ? '#c' . $newId : ''));
        }
        http_response_code(422);
        $c = $d + $c;
    }
    render('ext/modf/contract_form', ['title' => $id ? 'Úprava: ' . $c['subject'] : 'Nová smlouva nebo pojištění', 'c' => $c, 'id' => $id, 'suppliers' => modf_suppliers(), 'errors' => $errors]);
};
$route('GET', '/admin/smlouvy/nova', $modfContractForm);
$route('POST', '/admin/smlouvy/nova', $modfContractForm);
$route('GET', '/admin/smlouvy/(\d+)', $modfContractForm);
$route('POST', '/admin/smlouvy/(\d+)', $modfContractForm);
$route('POST', '/admin/smlouvy/(\d+)/smazat', function (int $id) {
    require_admin();
    $c = modf_contract($id) ?? not_found();
    modf_contract_delete($id);
    audit('Smazání smlouvy', modf_contract_title($c));
    flash('Smlouva je smazaná.');
    redirect('/admin/smlouvy');
});
$route('GET', '/admin/smlouvy/(\d+)/soubor', function (int $id) use ($modfSendFile) {
    require_admin();
    $c = modf_contract($id) ?? not_found();
    $modfSendFile($c['filename'], $c['original_name']);
});
$route('POST', '/admin/smlouvy/kalendar', function () {
    require_admin();
    modf_ics_token(true);
    audit('Nový odkaz na kalendář smluv a lhůt');
    flash('Odkaz na kalendář je nový; ten předchozí přestal fungovat. Vložte nový odkaz do svého kalendáře.');
    redirect('/admin/smlouvy#kalendar');
});
// Kalendář termínů pro správce: odběr v kalendářové aplikaci přes tajný odkaz (bez přihlášení).
$route('GET', '/kalendar-spravy/([a-f0-9]{32})\.ics', function (string|int $token) {   // samé číslice předá směrovač jako číslo
    if (!plan_has('smlouvy') || !hash_equals(modf_ics_token(), (string) $token)) {
        not_found();
    }
    send_ics(modf_ics(), 'smlouvy-a-lhuty.ics');
});
