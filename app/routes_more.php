<?php
declare(strict_types=1);

// Ankety, zápis ze shromáždění, veřejná vývěska a přehled plateb na jednotku.

// ---------- Ankety (nezávazné zjištění názoru; hlasuje každý přihlášený jednou) ----------

/** Anketa s možnostmi a počty odpovědí. */
function survey_load(int $id): ?array
{
    $s = one('SELECT * FROM surveys WHERE id = ?', [$id]);
    if (!$s) {
        return null;
    }
    $s['options'] = all('SELECT o.*, (SELECT COUNT(*) FROM survey_answers a WHERE a.option_id = o.id) AS votes FROM survey_options o WHERE o.survey_id = ? ORDER BY o.sort, o.id', [$id]);
    $s['total'] = array_sum(array_map(fn($o) => (int) $o['votes'], $s['options']));
    $s['open'] = $s['closes_at'] === '' || $s['closes_at'] >= date('Y-m-d');
    return $s;
}
$route('GET', '/ankety', function () {
    $u = require_login();
    $list = [];
    foreach (all('SELECT id FROM surveys WHERE published = 1 ORDER BY id DESC LIMIT 50') as $row) {
        $s = survey_load((int) $row['id']);
        $s['mine'] = (int) val('SELECT option_id FROM survey_answers WHERE survey_id = ? AND user_id = ?', [$s['id'], $u['id']]);
        $list[] = $s;
    }
    render('surveys', ['title' => 'Ankety', 'surveys' => $list]);
});
$route('POST', '/ankety/(\d+)', function (int $id) {
    $u = require_login();
    $s = survey_load($id);
    if (!$s || !$s['published']) {
        not_found();
    }
    $option = (int) post('option');
    if (!$s['open']) {
        flash('Tato anketa už skončila.', 'error');
    } elseif (!in_array($option, array_map(fn($o) => (int) $o['id'], $s['options']), true)) {
        flash('Vyberte jednu z možností.', 'error');
    } else {
        q('DELETE FROM survey_answers WHERE survey_id = ? AND user_id = ?', [$id, $u['id']]);
        q('INSERT INTO survey_answers (survey_id, user_id, option_id, answered_at) VALUES (?,?,?,?)', [$id, $u['id'], $option, date('Y-m-d H:i:s')]);
        flash('Děkujeme, Vaše odpověď je zaznamenaná. Do konce ankety ji můžete změnit.');
    }
    redirect('/ankety#a' . $id);
});
$route('GET', '/admin/ankety', function () {
    require_admin();
    render('admin/surveys', ['title' => 'Ankety', 'surveys' => array_map(fn($r) => survey_load((int) $r['id']), all('SELECT id FROM surveys ORDER BY id DESC'))]);
});
$surveyForm = function (int $id = 0) {
    require_admin();
    $s = $id ? (survey_load($id) ?? not_found()) : ['id' => 0, 'title' => '', 'body' => '', 'closes_at' => date('Y-m-d', time() + 14 * 86400), 'anonymous' => 1, 'published' => 1, 'options' => [], 'total' => 0];
    $errors = [];
    $optionsText = implode("\n", array_map(fn($o) => $o['label'], $s['options']));
    if ($GLOBALS['METHOD'] === 'POST') {
        $s = array_merge($s, ['title' => mb_substr(post('title'), 0, 250), 'body' => mb_substr(post('body'), 0, 5000), 'closes_at' => valid_date(post('closes_at')) ? post('closes_at') : '',
            'anonymous' => isset($_POST['anonymous']) ? 1 : 0, 'published' => isset($_POST['published']) ? 1 : 0]);
        $optionsText = (string) ($_POST['options'] ?? '');
        $labels = array_values(array_unique(array_filter(array_map(fn($l) => mb_substr(trim($l), 0, 250), preg_split('/\R/u', $optionsText)), fn($l) => $l !== '')));
        if ($s['title'] === '') {
            $errors[] = 'Vyplňte otázku.';
        }
        if (!$s['total'] && (count($labels) < 2 || count($labels) > 12)) {
            $errors[] = 'Zadejte 2 až 12 možností, každou na samostatný řádek.';
        }
        if (!$errors) {
            if ($id) {
                q('UPDATE surveys SET title = ?, body = ?, closes_at = ?, anonymous = ?, published = ? WHERE id = ?', [$s['title'], $s['body'], $s['closes_at'], $s['anonymous'], $s['published'], $id]);
            } else {
                q('INSERT INTO surveys (title, body, closes_at, anonymous, published, created_at) VALUES (?,?,?,?,?,?)', [$s['title'], $s['body'], $s['closes_at'], $s['anonymous'], $s['published'], date('Y-m-d H:i:s')]);
                $id = (int) db()->lastInsertId();
            }
            if (!$s['total']) {   // možnosti jdou měnit jen dokud nikdo neodpověděl
                q('DELETE FROM survey_options WHERE survey_id = ?', [$id]);
                foreach ($labels as $i => $label) {
                    q('INSERT INTO survey_options (survey_id, label, sort) VALUES (?,?,?)', [$id, $label, $i + 1]);
                }
            }
            flash('Anketa je uložená.');
            redirect('/admin/ankety');
        }
        http_response_code(422);
    }
    $names = $id && !$s['anonymous'] ? all('SELECT a.option_id, u.name FROM survey_answers a JOIN users u ON u.id = a.user_id WHERE a.survey_id = ? ORDER BY u.name', [$id]) : [];
    render('admin/survey_form', ['title' => $id ? 'Úprava ankety' : 'Nová anketa', 's' => $s, 'errors' => $errors, 'optionsText' => $optionsText, 'names' => $names]);
};
$route('GET', '/admin/ankety/nova', $surveyForm);
$route('POST', '/admin/ankety/nova', $surveyForm);
$route('GET', '/admin/ankety/(\d+)', $surveyForm);
$route('POST', '/admin/ankety/(\d+)', $surveyForm);
$route('POST', '/admin/ankety/(\d+)/smazat', function (int $id) {
    require_admin();
    q('DELETE FROM surveys WHERE id = ?', [$id]);
    flash('Anketa byla smazána.');
    redirect('/admin/ankety');
});

// ---------- Zápis ze shromáždění: sestaví se z účasti, programu a hlasování přiřazených k termínu ----------

/** Otisk všech odevzdaných hlasů: kdokoli si může ověřit, že se výsledek po vytištění protokolu nezměnil. */
function poll_fingerprint(int $pollId): string
{
    $rows = all('SELECT v.item_id, v.unit_id, v.choice, v.voted_at FROM poll_votes v JOIN poll_items i ON i.id = v.item_id WHERE i.poll_id = ? ORDER BY v.item_id, v.unit_id', [$pollId]);
    return hash('sha256', json_encode($rows));
}
$route('GET', '/admin/terminy/(\d+)/zapis', function (int $id) {
    require_admin();
    $ev = one('SELECT * FROM events WHERE id = ?', [$id]) ?? not_found();
    $polls = [];
    foreach (all('SELECT * FROM polls WHERE event_id = ? ORDER BY id', [$id]) as $p) {
        $polls[] = ['poll' => $p, 'res' => poll_results((int) $p['id']), 'hash' => poll_fingerprint((int) $p['id'])];
    }
    render('admin/minutes', ['title' => 'Zápis – ' . $ev['title'], 'ev' => $ev, 'att' => ($pres = val('SELECT 1 FROM event_presence WHERE event_id = ?', [$id]) ? presence($id) : null) ?? attendance($id), 'polls' => $polls,
        'motions' => $pres ? meeting_motions($id, $pres) : [],
        'docs' => all('SELECT d.title FROM documents d JOIN event_documents ed ON ed.document_id = d.id WHERE ed.event_id = ? ORDER BY d.title', [$id])]);
});

// ---------- Veřejná vývěska: jedna přehledná stránka bez přihlášení (QR kód u vchodu, obrazovka v hale) ----------

$route('GET', '/vyveska', function () {
    $GLOBALS['LAYOUT'] = 'layout_bare';
    $GLOBALS['HEAD_EXTRA'] = '<meta http-equiv="refresh" content="900">';
    render('board', ['title' => 'Vývěska',
        'posts' => all("SELECT * FROM posts WHERE visibility = 'public' ORDER BY pinned DESC, published_at DESC, id DESC LIMIT 5"),
        'events' => all("SELECT * FROM events WHERE event_date >= date('now','localtime') ORDER BY event_date LIMIT 4"),
        'phones' => all("SELECT * FROM contacts WHERE grp = 'havarie' AND phone <> '' ORDER BY sort, id LIMIT 4")]);
});
$route('GET', '/admin/vyveska', function () {
    require_admin();
    render('admin/board', ['title' => 'Vývěska', 'address' => absolute_url('/vyveska'), 'qr' => qr_svg(absolute_url('/vyveska'), 'QR kód vývěsky')]);
});

// ---------- Platby na jednotku: předpis záloh a stav účtu, který výbor nahraje z účetnictví ----------

function account_money(float $v): string
{
    return number_format($v, abs($v - round($v)) < 0.005 ? 0 : 2, ',', "\u{a0}") . "\u{a0}Kč";
}
/** QR platba na účet společenství (číslo účtu z Nastavení); bez platného účtu nebo částky vrací prázdný text. */
function account_qr(float $amount, string $vs, string $message): string
{
    $iban = cz_iban(setting('account'));
    return $iban && $amount > 0 ? qr_svg(spayd($iban, $amount, mb_substr(preg_replace('/\D/', '', $vs), 0, 10), $message), 'QR platba') : '';
}
$route('GET', '/platby', function () {
    $u = require_login();
    $rows = my_unit_ids() ? all('SELECT un.*, a.prescribed, a.balance, a.vs, a.as_of, a.note AS account_note FROM units un JOIN unit_accounts a ON a.unit_id = un.id WHERE un.id IN (' . implode(',', array_map('intval', my_unit_ids())) . ') ORDER BY un.entrance, un.number') : [];
    foreach ($rows as &$r) {
        $debt = -(float) $r['balance'];
        $r['qr_debt'] = $debt > 0 ? account_qr($debt, (string) $r['vs'], 'Doplatek ' . unit_name($r)) : '';
        $r['qr_month'] = (float) $r['prescribed'] > 0 ? account_qr((float) $r['prescribed'], (string) $r['vs'], 'Zaloha ' . unit_name($r)) : '';
    }
    unset($r);
    render('payments', ['title' => 'Platby a zálohy', 'rows' => $rows, 'hasUnits' => (bool) my_unit_ids()]);
});
$adminAccounts = function () {
    require_admin();
    $errors = [];
    $report = null;
    if ($GLOBALS['METHOD'] === 'POST') {
        $action = post('action');
        if ($action === 'import') {
            $byName = [];
            foreach (all_units() as $un) {
                $byName[mb_strtolower(unit_name($un))] = (int) $un['id'];
                $byName[mb_strtolower($un['entrance'] . '/' . $un['number'])] = (int) $un['id'];
            }
            $num = fn(string $v) => (float) str_replace([' ', "\u{a0}", 'Kč', ','], ['', '', '', '.'], $v);
            $ok = 0;
            $unknown = [];
            foreach (preg_split('/\R/u', (string) ($_POST['text'] ?? '')) as $line) {
                $cells = array_map('trim', preg_split('/[;\t]/', $line));
                if (count($cells) < 3 || $cells[0] === '' || !preg_match('/\d/', $cells[1] . $cells[2])) {
                    continue;   // prázdný řádek nebo záhlaví
                }
                $unitId = $byName[mb_strtolower(str_replace(' ', '', $cells[0]))] ?? null;
                if (!$unitId) {
                    $unknown[] = $cells[0];
                    continue;
                }
                q('DELETE FROM unit_accounts WHERE unit_id = ?', [$unitId]);
                q('INSERT INTO unit_accounts (unit_id, prescribed, balance, vs, as_of, note) VALUES (?,?,?,?,?,?)', [$unitId, $num($cells[1]), $num($cells[2]), mb_substr(preg_replace('/\D/', '', $cells[3] ?? ''), 0, 10),
                    valid_date(post('as_of')) ? post('as_of') : date('Y-m-d'), mb_substr($cells[4] ?? '', 0, 250)]);
                $ok++;
            }
            audit('Platby na jednotku: import', "jednotek $ok" . ($unknown ? ', nerozpoznáno ' . count($unknown) : ''));
            flash("Nahráno jednotek: $ok." . ($unknown ? ' Nerozpoznané jednotky: ' . implode(', ', array_slice($unknown, 0, 10)) . '.' : ''), $ok ? 'ok' : 'error');
            redirect('/admin/platby');
        }
        if ($action === 'clear') {
            q('DELETE FROM unit_accounts');
            audit('Platby na jednotku: smazání přehledu');
            flash('Přehled plateb byl smazán.', 'info');
            redirect('/admin/platby');
        }
        if ($action === 'remind') {
            $sent = $failed = 0;
            foreach (all('SELECT un.*, a.balance, a.vs, a.as_of FROM unit_accounts a JOIN units un ON un.id = a.unit_id WHERE a.balance < 0') as $r) {
                $text = "Dobrý den,\n\nu jednotky " . unit_name($r) . " evidujeme k " . cz_date($r['as_of']) . " nedoplatek " . account_money(-(float) $r['balance']) . ".\n"
                    . 'Prosíme o úhradu na účet společenství ' . setting('account') . ($r['vs'] !== '' ? ', variabilní symbol ' . $r['vs'] : '') . ".\nPlatební údaje včetně QR kódu najdete po přihlášení: " . absolute_url('/platby')
                    . "\n\nPokud jste platbu mezitím odeslali, považujte tuto zprávu za bezpředmětnou.\n\n" . site('name');
                foreach (all("SELECT u.email FROM users u JOIN user_units uu ON uu.user_id = u.id WHERE uu.unit_id = ? AND u.active = 1 AND u.kind = 'owner'", [$r['id']]) as $owner) {
                    send_mail($owner['email'], 'Nedoplatek – ' . unit_name($r) . ' – ' . site('name'), $text) ? $sent++ : $failed++;
                }
                q('UPDATE unit_accounts SET reminded_at = ? WHERE unit_id = ?', [date('Y-m-d H:i:s'), $r['id']]);
            }
            audit('Platby na jednotku: upomínky', "odesláno $sent" . ($failed ? ", neodesláno $failed" : ''));
            flash("Upomínky: odesláno $sent" . ($failed ? ", neodesláno $failed (zkontrolujte odesílatele e-mailů v Nastavení)" : '') . '. Jednotky bez přiřazeného účtu vlastníka upomínku nedostaly.', $sent || !$failed ? 'ok' : 'error');
            redirect('/admin/platby');
        }
    }
    render('admin/accounts', ['title' => 'Platby na jednotku', 'errors' => $errors,
        'rows' => all('SELECT un.*, a.prescribed, a.balance, a.vs, a.as_of, a.reminded_at, (SELECT COUNT(*) FROM user_units uu JOIN users u ON u.id = uu.user_id WHERE uu.unit_id = un.id AND u.active = 1) AS accounts
            FROM units un LEFT JOIN unit_accounts a ON a.unit_id = un.id ORDER BY un.entrance, un.number + 0, un.number'), 'iban' => cz_iban(setting('account'))]);
};
$route('GET', '/admin/platby', $adminAccounts);
$route('POST', '/admin/platby', $adminAccounts);

// ---------- AI pomocník (volitelný) ----------

$route('POST', '/admin/prepis', function () {
    require_admin();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $f = $_FILES['file'] ?? null;
    $result = (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']))
        ? ['error' => 'Soubor se nepodařilo nahrát. Zkuste to znovu, nebo text vložte ručně.']
        : transcript_text($f['tmp_name'], (string) $f['name']);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
});

$route('POST', '/admin/ai', function () {
    require_admin();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    @set_time_limit(180);
    if (post('task') === 'minutes' && !plan_has('ai-zapis')) {
        echo json_encode(['error' => 'Zápis pomocí AI je součástí edice Premium. Vyzkoušet ho můžete v části Předplatné.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = ai_run(post('task'), (string) ($_POST['text'] ?? ''), mb_substr((string) ($_POST['context'] ?? ''), 0, 8000));
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
});
