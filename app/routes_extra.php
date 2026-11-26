<?php
declare(strict_types=1);

// ---------- Ochrana osobních údajů (veřejné) ----------

$route('GET', '/ochrana-udaju', function () {
    render('privacy', ['title' => 'Ochrana osobních údajů', 'text' => setting('privacy_text') !== '' ? setting('privacy_text') : PRIVACY_DEFAULT]);
});

// ---------- Hlasování ----------

$route('GET', '/hlasovani', function () {
    require_login();
    $polls = all('SELECT * FROM polls ORDER BY closes_at DESC, id DESC');
    render('polls', ['title' => 'Hlasování', 'polls' => is_admin() ? $polls : array_values(array_filter($polls, fn($p) => poll_state($p) !== 'planned'))]);
});

$pollPage = function (int $id) {
    $u = require_login();
    $poll = one('SELECT * FROM polls WHERE id = ?', [$id]) ?? not_found();
    $state = poll_state($poll);
    if ($state === 'planned' && !is_admin()) {
        not_found();
    }
    $items = all('SELECT * FROM poll_items WHERE poll_id = ? ORDER BY sort, id', [$id]);
    $mine = $u['kind'] === 'owner' ? all('SELECT un.* FROM units un JOIN user_units uu ON uu.unit_id = un.id WHERE uu.user_id = ? ORDER BY un.entrance, un.number', [$u['id']]) : [];
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $unitId = (int) post('unit_id');
        $choices = (array) ($_POST['choice'] ?? []);
        $already = (int) val('SELECT COUNT(*) FROM poll_votes v JOIN poll_items i ON i.id = v.item_id WHERE i.poll_id = ? AND v.unit_id = ?', [$id, $unitId]);
        if (!$poll['online'] || $state !== 'open') {
            $errors[] = 'Toto hlasování teď přes web neprobíhá.';
        } elseif (!in_array($unitId, array_map(fn($x) => (int) $x['id'], $mine), true)) {
            $errors[] = 'Hlasovat může jen vlastník za svou jednotku.';
        } elseif ($already) {
            $errors[] = 'Za tuto jednotku už bylo odhlasováno. Opravu může provést jen výbor.';
        } else {
            foreach ($items as $it) {
                if (!isset(CHOICES[$choices[$it['id']] ?? ''])) {
                    $errors[] = 'Vyjádřete se prosím ke všem bodům.';
                    break;
                }
            }
        }
        if (!$errors) {
            function_exists('rollam_web_vote') && rollam_web_vote($poll, $u, $unitId, array_intersect_key($choices, array_flip(array_column($items, 'id'))));   // modul B: ověření hlasu a záznam (přesměruje)
            foreach ($items as $it) {
                q('INSERT INTO poll_votes (item_id, unit_id, user_id, choice) VALUES (?,?,?,?)', [$it['id'], $unitId, $u['id'], $choices[$it['id']]]);
            }
            $unit = one('SELECT * FROM units WHERE id = ?', [$unitId]);
            audit('Odevzdání hlasu', $poll['title'] . ' · ' . unit_name($unit));
            flash('Děkujeme, hlas za jednotku ' . unit_name($unit) . ' je zaznamenán.');
            redirect('/hlasovani/' . $id);
        }
        http_response_code(422);
    }
    $myVotes = [];
    foreach ($mine as $un) {
        foreach (all('SELECT v.*, u.name voter FROM poll_votes v JOIN poll_items i ON i.id = v.item_id LEFT JOIN users u ON u.id = v.user_id WHERE i.poll_id = ? AND v.unit_id = ?', [$id, $un['id']]) as $v) {
            $myVotes[$un['id']][$v['item_id']] = $v;
        }
    }
    $showResults = is_admin() || ($state === 'closed' && $poll['published']);
    render('poll', ['title' => $poll['title'], 'poll' => $poll, 'state' => $state, 'items' => $items, 'mine' => $mine, 'myVotes' => $myVotes, 'errors' => $errors,
        'results' => poll_results($id), 'showResults' => $showResults]);
};
$route('GET', '/hlasovani/(\d+)', $pollPage);
$route('POST', '/hlasovani/(\d+)', $pollPage);

// ---------- Odečty měřidel ----------

$readingsPage = function () {
    $u = require_login();
    $mine = my_unit_ids();
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $unitId = (int) post('unit_id');
        $amount = str_replace([' ', ','], ['', '.'], post('amount'));
        if (!in_array($unitId, $mine, true)) {
            $errors[] = 'Odečet lze zadat jen za vlastní jednotku.';
        }
        if (!isset(READING_KINDS[post('kind')])) {
            $errors[] = 'Vyberte měřidlo.';
        } elseif (in_array(post('kind'), remote_kinds(), true)) {
            $errors[] = 'Toto měřidlo se odečítá na dálku – stav není potřeba zadávat.';
        }
        if (!is_numeric($amount) || (float) $amount < 0 || (float) $amount > 99999999) {
            $errors[] = 'Stav měřidla zadejte jako číslo (např. 123,45).';
        }
        if (!valid_date(post('read_date')) || post('read_date') > date('Y-m-d')) {
            $errors[] = 'Zadejte platné datum odečtu (ne v budoucnosti).';
        }
        if (!$errors) {
            q('INSERT INTO readings (unit_id, kind, meter_no, amount, read_date, user_id, note) VALUES (?,?,?,?,?,?,?)',
                [$unitId, post('kind'), mb_substr(post('meter_no'), 0, 50), (float) $amount, post('read_date'), $u['id'], mb_substr(post('note'), 0, 255)]);
            flash('Odečet je uložen. Děkujeme.');
            redirect('/odecty');
        }
        http_response_code(422);
    }
    $rows = $mine ? all('SELECT r.*, u.name author FROM readings r LEFT JOIN users u ON u.id = r.user_id WHERE r.unit_id IN (' . implode(',', $mine) . ') ORDER BY r.read_date DESC, r.id DESC LIMIT 100') : [];
    render('readings', ['title' => 'Odečty měřidel', 'errors' => $errors, 'rows' => $rows, 'units' => array_intersect_key(unit_options(), array_flip($mine)),
        'manualKinds' => array_diff_key(READING_KINDS, array_flip(remote_kinds())), 'remote' => remote_kinds(), 'monthly' => monthly_consumption($mine)]);
};
$route('GET', '/odecty', $readingsPage);
$route('POST', '/odecty', $readingsPage);

// ---------- Rezervace společných prostor ----------

$reservationsPage = function () {
    $u = require_login();
    $resources = all('SELECT * FROM resources WHERE active = 1 ORDER BY name');
    $errors = [];
    if ($GLOBALS['METHOD'] === 'POST') {
        $rid = (int) post('resource_id');
        [$day, $from, $to] = [post('res_date'), post('time_from'), post('time_to')];
        $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
        if (!in_array($rid, array_map(fn($r) => (int) $r['id'], $resources), true)) {
            $errors[] = 'Vyberte, co si chcete rezervovat.';
        }
        if (!valid_date($day) || $day < date('Y-m-d') || $day > date('Y-m-d', time() + 90 * 86400)) {
            $errors[] = 'Rezervovat lze ode dneška nejvýše 90 dní dopředu.';
        }
        if (!preg_match($time, $from) || !preg_match($time, $to) || $from >= $to) {
            $errors[] = 'Zadejte čas od–do; konec musí být po začátku.';
        }
        // Rezervace na více dní po sobě: stejný čas každý den až do zvoleného posledního dne (nejvýše 14 dní).
        $last = post('res_date_to') !== '' ? post('res_date_to') : $day;
        $days = [];
        if (!$errors) {
            if (!valid_date($last) || $last < $day) {
                $errors[] = 'Poslední den rezervace musí být stejný nebo pozdější než první.';
            } elseif ((strtotime($last) - strtotime($day)) / 86400 >= 14) {
                $errors[] = 'Najednou lze rezervovat nejvýše 14 dní po sobě. Delší dobu domluvte s výborem.';
            } elseif ($last > date('Y-m-d', time() + 90 * 86400)) {
                $errors[] = 'Rezervovat lze ode dneška nejvýše 90 dní dopředu.';
            } else {
                for ($d = $day; $d <= $last; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                    $days[] = $d;
                }
            }
        }
        $taken = [];
        foreach ($days as $d) {
            if (val('SELECT 1 FROM reservations WHERE resource_id = ? AND res_date = ? AND time_from < ? AND time_to > ?', [$rid, $d, $to, $from])) {
                $taken[] = cz_date($d);
            }
        }
        if ($taken) {
            $errors[] = count($days) === 1 ? 'V tomto čase už má rezervaci někdo jiný. Zvolte jiný čas.' : 'V tomto čase už má rezervaci někdo jiný v těchto dnech: ' . implode(', ', $taken) . '. Zvolte jiný čas nebo jiné dny.';
        }
        if (!$errors && (int) val('SELECT COUNT(*) FROM reservations WHERE user_id = ? AND res_date >= ?', [$u['id'], date('Y-m-d')]) + count($days) > 30) {
            $errors[] = 'Můžete mít nejvýše 30 nadcházejících rezervovaných dnů. Některou rezervaci nejdřív zrušte.';
        }
        if (!$errors) {
            foreach ($days as $d) {
                q('INSERT INTO reservations (resource_id, user_id, res_date, time_from, time_to, note) VALUES (?,?,?,?,?,?)', [$rid, $u['id'], $d, $from, $to, mb_substr(post('note'), 0, 255)]);
            }
            flash(count($days) === 1 ? 'Rezervace je uložena.' : 'Rezervace je uložena na ' . count($days) . ' dní: ' . cz_date($day) . ' – ' . cz_date($last) . '.');
            redirect('/rezervace');
        }
        http_response_code(422);
    }
    $rows = all('SELECT r.*, s.name resource, u.name author_name, ' . units_sql('u') . ' author_units FROM reservations r JOIN resources s ON s.id = r.resource_id LEFT JOIN users u ON u.id = r.user_id
        WHERE r.res_date >= ? ORDER BY r.res_date, r.time_from', [date('Y-m-d')]);
    render('reservations', ['title' => 'Rezervace', 'resources' => $resources, 'rows' => $rows, 'errors' => $errors]);
};
$route('GET', '/rezervace', $reservationsPage);
$route('POST', '/rezervace', $reservationsPage);
$route('POST', '/rezervace/(\d+)/zrusit', function (int $id) {
    $u = require_login();
    q('DELETE FROM reservations WHERE id = ? AND user_id = ?', [$id, $u['id']]);
    flash('Rezervace byla zrušena.');
    redirect('/rezervace');
});

// ---------- Plná moc k tisku ----------

$route('GET', '/terminy/(\d+)/plna-moc', function (int $id) {
    $u = require_login();
    $ev = one('SELECT * FROM events WHERE id = ?', [$id]) ?? not_found();
    $unitId = (int) ($_GET['jednotka'] ?? 0);
    if (!in_array($unitId, my_unit_ids(), true) && !is_admin()) {
        not_found();
    }
    $unit = one('SELECT * FROM units WHERE id = ?', [$unitId]) ?? not_found();
    $rsvp = one('SELECT * FROM event_rsvp WHERE event_id = ? AND unit_id = ?', [$id, $unitId]);
    render('proxy_print', ['title' => 'Plná moc', 'ev' => $ev, 'unit' => $unit, 'owner' => $unit['owner_note'] !== '' ? $unit['owner_note'] : $u['name'], 'proxy' => $rsvp['proxy_name'] ?? '']);
});

// ====================== ADMINISTRACE ======================

// ---------- Obecné evidence: seznam, formulář, smazání ----------

foreach (crud_defs() as $slug => $def) {
    $route('GET', "/admin/$slug", function () use ($slug, $def) {
        require_admin();
        // Prostory a jejich rezervace jsou na jedné stránce.
        if ($slug === 'prostory') {
            redirect('/admin/rezervace');
        }
        if ($slug === 'rezervace') {
            $today = date('Y-m-d');
            $res = all('SELECT r.*, u.name author_name, ' . units_sql('u') . ' author_units FROM reservations r LEFT JOIN users u ON u.id = r.user_id WHERE r.res_date >= ? ORDER BY r.res_date, r.time_from', [$today]);
            $byResource = [];
            foreach ($res as $r) {
                $byResource[(int) $r['resource_id']][] = $r;
            }
            render('admin/spaces', ['title' => 'Prostory a rezervace', 'resources' => all('SELECT * FROM resources ORDER BY active DESC, name'), 'byResource' => $byResource,
                'past' => array_column(all('SELECT resource_id, COUNT(*) AS n FROM reservations WHERE res_date < ? GROUP BY resource_id', [$today]), 'n', 'resource_id')]);
            return;
        }
        render('admin/crud_list', ['title' => $def['title'], 'slug' => $slug, 'def' => $def, 'rows' => all("SELECT * FROM {$def['table']} ORDER BY {$def['order']} LIMIT 1000")]);
    });
    $form = function (int $id = 0) use ($slug, $def) {
        require_admin();
        $row = $id ? (one("SELECT * FROM {$def['table']} WHERE id = ?", [$id]) ?? not_found()) : ['id' => 0];
        $errors = [];
        if ($GLOBALS['METHOD'] === 'POST') {
            $data = [];
            foreach ($def['fields'] as $name => $f) {
                $v = post($name);
                $required = $f[2] ?? false;
                switch ($f[1]) {
                    case 'check':
                        $v = isset($_POST[$name]) ? 1 : 0;
                        break;
                    case 'date':
                        if ($v !== '' && !valid_date($v)) {
                            $errors[] = $f[0] . ': zadejte platné datum.';
                        }
                        $v = $v === '' ? null : $v;
                        break;
                    case 'time':
                        if ($v !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
                            $errors[] = $f[0] . ': zadejte čas ve tvaru HH:MM.';
                        }
                        break;
                    case 'number':
                        $v = max(0, (int) $v);
                        break;
                    case 'decimal':
                        $v = str_replace([' ', ','], ['', '.'], $v);
                        if ($v !== '' && !is_numeric($v)) {
                            $errors[] = $f[0] . ': zadejte číslo.';
                        }
                        $v = $v === '' ? null : (float) $v;
                        break;
                    case 'select':
                        if ($v !== '' && !array_key_exists($v, $f[3]())) {
                            $errors[] = $f[0] . ': neplatná volba.';
                        }
                        $v = $v === '' ? null : $v;
                        break;
                    default:
                        $v = mb_substr($v, 0, 250);
                }
                if ($required && ($v === null || $v === '')) {
                    $errors[] = $f[0] . ': pole je povinné.';
                }
                $data[$name] = $v;
            }
            // U revize se příští termín dopočítá z posledního provedení a intervalu.
            if ($slug === 'revize' && !$data['next_date'] && $data['last_date'] && $data['interval_months'] > 0) {
                $data['next_date'] = date('Y-m-d', strtotime($data['last_date'] . ' +' . $data['interval_months'] . ' months'));
            }
            if (!$errors) {
                $cols = array_keys($data);
                $id ? q("UPDATE {$def['table']} SET " . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?', [...array_values($data), $id])
                    : q("INSERT INTO {$def['table']} (" . implode(', ', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($data));
                flash('Uloženo.');
                redirect("/admin/$slug");
            }
            $row = array_merge($row, $data);
            http_response_code(422);
        }
        render('admin/crud_form', ['title' => ($id ? 'Upravit ' : 'Přidat ') . $def['one'], 'slug' => $slug, 'def' => $def, 'row' => $row, 'errors' => $errors]);
    };
    $route('GET', "/admin/$slug/novy", $form);
    $route('POST', "/admin/$slug/novy", $form);
    $route('GET', "/admin/$slug/(\\d+)", $form);
    $route('POST', "/admin/$slug/(\\d+)", $form);
    $route('POST', "/admin/$slug/(\\d+)/smazat", function (int $id) use ($slug, $def) {
        require_admin();
        q("DELETE FROM {$def['table']} WHERE id = ?", [$id]);
        flash('Smazáno.');
        redirect("/admin/$slug");
    });
}

$route('POST', '/admin/revize/(\d+)/provedeno', function (int $id) {
    require_admin();
    $m = one('SELECT * FROM maintenance WHERE id = ?', [$id]) ?? not_found();
    $next = $m['interval_months'] > 0 ? date('Y-m-d', strtotime('+' . (int) $m['interval_months'] . ' months')) : null;
    q('UPDATE maintenance SET last_date = ?, next_date = ?, reminded_for = NULL WHERE id = ?', [date('Y-m-d'), $next, $id]);
    flash('Zapsáno jako provedeno dnes.' . ($next ? ' Příští termín: ' . cz_date($next) . '.' : ''));
    redirect('/admin/revize');
});

// ---------- Exporty do tabulky (CSV pro Excel) ----------

$csv = function (string $filename, array $header, array $rows): never {
    header_remove('Content-Security-Policy');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // značka kódování, aby Excel zobrazil češtinu správně
    foreach ([$header, ...$rows] as $r) {
        // Buňka začínající znakem vzorce se uvodí apostrofem, aby ji Excel nespustil jako vzorec.
        fputcsv($out, array_map(fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v, $r), ';', '"', '');
    }
    exit;
};
$route('GET', '/admin/odecty/export', function () use ($csv) {
    require_admin();
    audit('Export odečtů měřidel');
    $rows = all('SELECT r.*, un.entrance, un.number, u.name author FROM readings r JOIN units un ON un.id = r.unit_id LEFT JOIN users u ON u.id = r.user_id ORDER BY un.entrance, un.number + 0, r.kind, r.read_date');
    $csv('odecty-' . date('Y-m-d') . '.csv', ['Jednotka', 'Měřidlo', 'Číslo měřidla', 'Stav', 'Datum odečtu', 'Zapsal', 'Poznámka'],
        array_map(fn($r) => [unit_name($r), READING_KINDS[$r['kind']] ?? $r['kind'], $r['meter_no'], str_replace('.', ',', (string) $r['amount']), cz_date($r['read_date']), $r['author'] ?? '', $r['note']], $rows));
});
$route('GET', '/admin/jednotky/export', function () use ($csv) {
    require_admin();
    audit('Export seznamu členů');
    $people = [];
    foreach (all('SELECT uu.unit_id, u.name, u.email, u.phone, u.kind FROM user_units uu JOIN users u ON u.id = uu.user_id WHERE u.active = 1 ORDER BY u.kind, u.name') as $p) {
        $people[$p['unit_id']][] = $p['name'] . ' (' . mb_strtolower(KINDS[$p['kind']]) . ') ' . $p['email'] . ($p['phone'] ? ' ' . $p['phone'] : '');
    }
    $csv('seznam-clenu-' . date('Y-m-d') . '.csv', ['Jednotka', 'Patro', 'Podíl', 'Vlastník dle evidence', 'Adresa pro doručování', 'Účty na webu', 'Poznámka'],
        array_map(fn($un) => [unit_name($un), $un['floor'], $un['share'], $un['owner_note'], str_replace("\n", ' | ', $un['owner_contact']), implode(' | ', $people[$un['id']] ?? []), $un['note']], all_units()));
});

// ---------- Hlasování: správa ----------

$route('GET', '/admin/hlasovani', function () {
    require_admin();
    render('admin/polls', ['title' => 'Hlasování', 'polls' => all('SELECT p.*, (SELECT COUNT(*) FROM poll_items i WHERE i.poll_id = p.id) items FROM polls p ORDER BY p.closes_at DESC, p.id DESC')]);
});
$pollForm = function (int $id = 0) {
    $admin = require_admin();
    $poll = $id ? (one('SELECT * FROM polls WHERE id = ?', [$id]) ?? not_found())
        : ['id' => 0, 'title' => '', 'body' => '', 'opens_at' => date('Y-m-d'), 'closes_at' => date('Y-m-d', time() + 15 * 86400), 'threshold' => setting_int('quorum_percent', 50, 1, 99), 'online' => 1, 'published' => 1, 'event_id' => null];
    $itemsText = $id ? implode("\n", array_column(all('SELECT question FROM poll_items WHERE poll_id = ? ORDER BY sort, id', [$id]), 'question')) : '';
    $hasVotes = $id && (int) val('SELECT COUNT(*) FROM poll_votes v JOIN poll_items i ON i.id = v.item_id WHERE i.poll_id = ?', [$id]) > 0;
    $errors = [];
    $sharesBlock = !$id && !shares_ready();
    if ($GLOBALS['METHOD'] === 'POST' && $sharesBlock) {
        $errors[] = 'Nejdřív doplňte spoluvlastnické podíly u všech jednotek.';
    } elseif ($GLOBALS['METHOD'] === 'POST') {
        if (!$id && ($limitError = plan_limit_error('polls'))) {
            $errors[] = $limitError;
        }
        $poll = array_merge($poll, ['title' => post('title'), 'body' => post('body'), 'opens_at' => post('opens_at'), 'closes_at' => post('closes_at'),
            'threshold' => max(1, min(99, (int) post('threshold'))), 'online' => isset($_POST['online']) ? 1 : 0, 'published' => isset($_POST['published']) ? 1 : 0,
            'event_id' => (int) post('event_id') ?: null]);
        // Odrážky, číslování a hvězdičky (např. z textu od AI nebo z Wordu) do znění usnesení nepatří.
        $questions = array_values(array_filter(array_map(fn($x) => trim(str_replace('**', '', preg_replace('/^\s*(?:[-–•*]|\d+[.)])\s+/u', '', $x))), preg_split('/\R/u', post('items'))), fn($x) => $x !== ''));
        if (!$hasVotes) {
            $itemsText = post('items');
        }
        if ($poll['title'] === '') {
            $errors[] = 'Vyplňte název hlasování.';
        }
        if (!valid_date($poll['opens_at']) || !valid_date($poll['closes_at']) || $poll['closes_at'] < $poll['opens_at']) {
            $errors[] = 'Zadejte platné datum zahájení a ukončení (ukončení nesmí být dřív než zahájení).';
        }
        if (!$hasVotes && !$questions) {
            $errors[] = 'Napište alespoň jeden bod, o kterém se hlasuje.';
        }
        if ($poll['event_id'] && !val('SELECT 1 FROM events WHERE id = ?', [$poll['event_id']])) {
            $poll['event_id'] = null;
        }
        if (!$errors) {
            $args = [$poll['title'], $poll['body'], $poll['opens_at'], $poll['closes_at'], $poll['threshold'], $poll['online'], $poll['published'], $poll['event_id']];
            if ($id) {
                q('UPDATE polls SET title = ?, body = ?, opens_at = ?, closes_at = ?, threshold = ?, online = ?, published = ?, event_id = ? WHERE id = ?', [...$args, $id]);
            } else {
                q('INSERT INTO polls (title, body, opens_at, closes_at, threshold, online, published, event_id, created_by) VALUES (?,?,?,?,?,?,?,?,?)', [...$args, $admin['id']]);
                $id = (int) db()->lastInsertId();
            }
            if (!$hasVotes) {
                q('DELETE FROM poll_items WHERE poll_id = ?', [$id]);
                foreach ($questions as $n => $question) {
                    q('INSERT INTO poll_items (poll_id, question, sort) VALUES (?,?,?)', [$id, mb_substr($question, 0, 1000), $n]);
                }
            }
            flash('Hlasování bylo uloženo.');
            function_exists('rollam_poll_saved') && rollam_poll_saved($id);   // modul B: ověření hlasu
            redirect('/admin/hlasovani');
        }
        http_response_code(422);
    }
    render('admin/poll_form', ['title' => $poll['id'] ? 'Upravit hlasování' : 'Nové hlasování', 'poll' => $poll, 'itemsText' => $itemsText, 'hasVotes' => $hasVotes, 'errors' => $errors, 'sharesBlock' => $sharesBlock,
        'events' => all('SELECT id, title, event_date FROM events ORDER BY event_date DESC')]);
};
$route('GET', '/admin/hlasovani/nove', $pollForm);
$route('POST', '/admin/hlasovani/nove', $pollForm);
$route('GET', '/admin/hlasovani/(\d+)', $pollForm);
$route('POST', '/admin/hlasovani/(\d+)', $pollForm);
$route('POST', '/admin/hlasovani/(\d+)/smazat', function (int $id) {
    require_admin();
    q('DELETE FROM polls WHERE id = ?', [$id]);
    flash('Hlasování bylo smazáno včetně odevzdaných hlasů.');
    redirect('/admin/hlasovani');
});
$route('GET', '/admin/hlasovani/(\d+)/vysledky', function (int $id) {
    require_admin();
    $poll = one('SELECT * FROM polls WHERE id = ?', [$id]) ?? not_found();
    render('admin/poll_results', ['title' => 'Výsledky: ' . $poll['title'], 'poll' => $poll, 'state' => poll_state($poll), 'results' => poll_results($id)]);
});
$route('POST', '/admin/hlasovani/(\d+)/vysledky', function (int $id) {
    $admin = require_admin();
    $poll = one('SELECT * FROM polls WHERE id = ?', [$id]) ?? not_found();
    $unit = one('SELECT * FROM units WHERE id = ?', [(int) post('unit_id')]) ?? not_found();
    $choices = (array) ($_POST['choice'] ?? []);
    $written = [];
    foreach (all('SELECT * FROM poll_items WHERE poll_id = ? ORDER BY sort, id', [$id]) as $n => $it) {
        $c = (string) ($choices[$it['id']] ?? '');
        if (isset(CHOICES[$c])) {
            q("INSERT INTO poll_votes (item_id, unit_id, user_id, choice) VALUES (?,?,?,?)
                ON CONFLICT(item_id, unit_id) DO UPDATE SET user_id = excluded.user_id, choice = excluded.choice, voted_at = datetime('now','localtime')", [$it['id'], $unit['id'], $admin['id'], $c]);
            $written[] = ($n + 1) . '. ' . mb_strtolower(CHOICES[$c]);
        } else {
            q('DELETE FROM poll_votes WHERE item_id = ? AND unit_id = ?', [$it['id'], $unit['id']]);
            $written[] = ($n + 1) . '. bez hlasu';
        }
    }
    function_exists('rollam_record_unit') && rollam_record_unit($id, (int) $unit['id']);   // modul B: záznam do protokolu
    audit('Zápis hlasu správcem', $poll['title'] . ' · ' . unit_name($unit) . ' · ' . implode(', ', $written));
    redirect('/admin/hlasovani/' . $id . '/vysledky#j' . $unit['id']);
});

// ---------- Prezenční listina k tisku ----------

$route('GET', '/admin/terminy/(\d+)/prezence', function (int $id) {
    require_admin();
    $ev = one('SELECT * FROM events WHERE id = ?', [$id]) ?? not_found();
    // Před shromážděním: prázdná listina k podpisům, zmocněnci podle přihlášek. Jakmile běží prezence na místě, vyplní se podle ní.
    $pres = val('SELECT 1 FROM event_presence WHERE event_id = ?', [$id]) ? presence($id) : null;
    render('admin/attendance_print', ['title' => 'Prezenční listina', 'ev' => $ev, 'pres' => $pres,
        'rows' => $pres ? $pres['rows'] : all('SELECT un.*, r.status, r.proxy_name FROM units un LEFT JOIN event_rsvp r ON r.unit_id = un.id AND r.event_id = ? ORDER BY un.entrance, un.number + 0, un.number', [$id])]);
});

// ---------- Hromadné nahrání dokumentů pro jednotky (např. roční vyúčtování) ----------

$bulkDocs = function () {
    require_admin();
    $report = null;
    $errors = [];
    $form = ['title' => post('title'), 'category' => isset(CATEGORIES[post('category')]) ? post('category') : 'oznameni', 'doc_date' => post('doc_date') ?: date('Y-m-d')];
    if ($GLOBALS['METHOD'] === 'POST') {
        if ($form['title'] === '') {
            $errors[] = 'Vyplňte název (např. Vyúčtování služeb 2026).';
        }
        if (!valid_date($form['doc_date'])) {
            $errors[] = 'Zadejte platné datum.';
        }
        $index = [];
        foreach (all_units() as $un) {
            $index[mb_strtoupper($un['entrance']) . '/' . $un['number']] = $un;
        }
        $files = uploaded_list('files');
        if (!$files) {
            $errors[] = 'Vyberte soubory.';
        }
        if (!$errors) {
            $report = ['ok' => [], 'skipped' => []];
            foreach ($files as $f) {
                // Jednotka se pozná z názvu souboru: 48A-5.pdf, A5.pdf, vyuctovani_48B_10.pdf …
                if (!preg_match('~(?<![\p{L}])(?:' . preg_quote(unit_prefix(), '~') . ')?([\p{L}])[ _.\-/]?(\d{1,3})(?!\d)~u', pathinfo((string) $f['name'], PATHINFO_FILENAME), $m) || !isset($index[mb_strtoupper($m[1]) . '/' . $m[2]])) {
                    $report['skipped'][] = $f['name'] . ' – v názvu není jednotka, kterou web zná';
                    continue;
                }
                $un = $index[mb_strtoupper($m[1]) . '/' . $m[2]];
                $up = store_upload($f);
                if (is_string($up)) {
                    $report['skipped'][] = $f['name'] . ' – ' . $up;
                    continue;
                }
                q("INSERT INTO documents (title, category, visibility, doc_date, filename, original_name, size, unit_id) VALUES (?,?,'residents',?,?,?,?,?)",
                    [$form['title'] . ' – ' . unit_name($un), $form['category'], $form['doc_date'], $up[0], $up[1], $up[2], $un['id']]);
                $report['ok'][] = $f['name'] . ' → ' . unit_name($un);
            }
            audit('Hromadné nahrání dokumentů jednotek', $form['title'] . ' · nahráno ' . count($report['ok']) . ', přeskočeno ' . count($report['skipped']));
        } else {
            http_response_code(422);
        }
    }
    render('admin/documents_bulk', ['title' => 'Dokumenty pro jednotky', 'form' => $form, 'errors' => $errors, 'report' => $report]);
};
$route('GET', '/admin/dokumenty/hromadne', $bulkDocs);
$route('POST', '/admin/dokumenty/hromadne', $bulkDocs);

// ---------- Záloha ----------

$route('GET', '/admin/zaloha', function () {
    require_admin();
    render('admin/backup', ['title' => 'Záloha dat', 'error' => null,
        'files' => (int) val('SELECT COUNT(*) FROM documents WHERE filename IS NOT NULL'), 'size' => (int) val('SELECT COALESCE(SUM(size), 0) FROM documents WHERE filename IS NOT NULL')]);
});
$route('POST', '/admin/zaloha', function () {
    $admin = require_admin();
    if (!password_verify((string) ($_POST['current_password'] ?? ''), $admin['password_hash'])) {
        http_response_code(422);
        render('admin/backup', ['title' => 'Záloha dat', 'error' => 'Heslo není správně. Záloha obsahuje osobní údaje, proto ji lze stáhnout jen po zadání hesla.',
            'files' => (int) val('SELECT COUNT(*) FROM documents WHERE filename IS NOT NULL'), 'size' => (int) val('SELECT COALESCE(SUM(size), 0) FROM documents WHERE filename IS NOT NULL')]);
        return;
    }
    if (!class_exists('ZipArchive')) {
        flash('Na tomto hostingu chybí podpora ZIP. Zálohujte složku storage a databázi přes správu hostingu.', 'error');
        redirect('/admin/zaloha');
    }
    @set_time_limit(600);
    audit('Stažení zálohy dat');
    $tmp = tempnam(sys_get_temp_dir(), 'svj');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('data.json', json_encode(backup_data(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    $zip->addFromString('CTI-ME.txt', "Záloha webu " . site('name') . " ze dne " . date('j. n. Y H:i') . ".\n\ndata.json  – všechna data webu (účty, jednotky, aktuality, hlasování, záznamy…)\nuploads/   – nahrané dokumenty\n\nObnova: na serveru s nainstalovaným webem spusťte\n  php tools/restore-backup.php cesta/k/teto-zaloze.zip\nStávající obsah webu se nahradí obsahem zálohy.\n\nZáloha obsahuje osobní údaje. Uchovávejte ji bezpečně.\n");
    foreach (glob(UPLOAD_DIR . '/*') as $file) {
        if (is_file($file) && basename($file) !== 'index.html') {
            $zip->addFile($file, 'uploads/' . basename($file));
        }
    }
    $zip->close();
    header_remove('Content-Security-Policy');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="zaloha-svj-' . date('Y-m-d-Hi') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
});

// ---------- Základní dokumenty ----------

$route('GET', '/admin/dokumenty/zakladni', function () {
    require_admin();
    $slots = [];
    foreach (CORE_DOCS as $key => $def) {
        $slots[$key] = $def + ['doc' => core_doc($key)];
    }
    render('admin/core_docs', ['title' => 'Základní dokumenty', 'slots' => $slots]);
});
$route('POST', '/admin/dokumenty/zakladni', function () {
    require_admin();
    $key = post('key');
    $def = CORE_DOCS[$key] ?? null;
    if (!$def) {
        not_found();
    }
    $stored = store_upload($_FILES['file'] ?? []);
    if (is_string($stored)) {
        flash($def[0] . ': ' . $stored, 'error');
        redirect('/admin/dokumenty/zakladni');
    }
    [$filename, $original, $size] = $stored;
    $old = core_doc($key);
    $visibility = isset(VISIBILITY[post('visibility')]) ? post('visibility') : $def[2];
    if ($old) {
        // Nová verze nahradí soubor u téhož dokumentu, takže odkazy z aktualit a termínů zůstanou platné.
        if ($old['filename'] && preg_match('/^[a-f0-9]{32}\.[a-z]+$/', $old['filename'])) {
            @unlink(UPLOAD_DIR . '/' . $old['filename']);
        }
        q('UPDATE documents SET filename = ?, original_name = ?, size = ?, external_url = NULL, content = NULL, visibility = ?, doc_date = ? WHERE id = ?', [$filename, $original, $size, $visibility, date('Y-m-d'), $old['id']]);
    } else {
        q('INSERT INTO documents (title, category, visibility, filename, original_name, size, doc_date) VALUES (?,?,?,?,?,?,?)', [$def[0], $def[1], $visibility, $filename, $original, $size, date('Y-m-d')]);
        $id = (int) db()->lastInsertId();
        q('DELETE FROM settings WHERE `key` = ?', ['core_doc_' . $key]);
        q('INSERT INTO settings (`key`, value) VALUES (?,?)', ['core_doc_' . $key, (string) $id]);
    }
    audit('Základní dokument ' . ($old ? 'nahrazen' : 'nahrán'), $def[0] . ' · ' . $original);
    flash($def[0] . ': ' . ($old ? 'nová verze je nahraná a nahradila původní.' : 'dokument je nahraný.'));
    redirect('/admin/dokumenty/zakladni');
});
