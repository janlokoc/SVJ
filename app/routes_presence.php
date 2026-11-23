<?php
/*
 * Prezence na shromáždění: správce na místě odklikává, kdo je přítomen (osobně nebo přes zmocněnce),
 * web průběžně sčítá spoluvlastnické podíly a hlídá usnášeníschopnost. K zastoupení lze nahrát
 * podepsanou plnou moc předem na stránce termínu; na místě zmocněnec předkládá papírovou a správce to jen zaškrtne.
 */

const PRESENCE = ['self' => 'Osobně', 'proxy' => 'Zmocněnec'];

/** Prezence na místě: stejné klíče jako attendance(), aby ji uměl použít i zápis ze shromáždění. */
function presence(int $eventId): array
{
    $rows = all('SELECT un.*, p.mode, p.proxy_name AS p_proxy, p.proxy_ok, p.checked_at, r.status AS rsvp, r.proxy_name AS rsvp_proxy, f.id AS file_id, f.original_name AS file_name, f.uploaded_at AS file_at
        FROM units un
        LEFT JOIN event_presence p ON p.unit_id = un.id AND p.event_id = ?
        LEFT JOIN event_rsvp r ON r.unit_id = un.id AND r.event_id = ?
        LEFT JOIN event_proxy_files f ON f.unit_id = un.id AND f.event_id = ?
        ORDER BY un.entrance, un.number + 0, un.number', [$eventId, $eventId, $eventId]);
    $byShare = (bool) $rows;
    foreach ($rows as $r) {
        $byShare = $byShare && share_value($r['share']) > 0;
    }
    $total = $present = 0.0;
    $counts = ['yes' => 0, 'proxy' => 0, 'no' => 0, 'none' => 0];
    $missingDocs = 0;
    foreach ($rows as &$r) {
        $r['weight'] = $byShare ? share_value($r['share']) : 1.0;
        $total += $r['weight'];
        if ($r['mode'] === 'self' || $r['mode'] === 'proxy') {
            $present += $r['weight'];
            $counts[$r['mode'] === 'self' ? 'yes' : 'proxy']++;
            $missingDocs += $r['mode'] === 'proxy' && !$r['proxy_ok'] && !$r['file_id'] ? 1 : 0;
        } else {
            $counts['no']++;
        }
    }
    unset($r);
    foreach ($rows as &$r) {
        $r['pct'] = $total > 0 ? $r['weight'] / $total * 100 : 0.0;
    }
    unset($r);
    $threshold = setting_int('quorum_percent', 50, 1, 99);
    $percent = $total > 0 ? $present / $total * 100 : 0.0;
    return ['rows' => $rows, 'byShare' => $byShare, 'percent' => $percent, 'threshold' => $threshold, 'quorate' => $percent > $threshold,
        'counts' => $counts, 'units' => count($rows), 'missingDocs' => $missingDocs, 'started' => (bool) val('SELECT 1 FROM event_presence WHERE event_id = ?', [$eventId])];
}
/** Potřebná většina pro přijetí návrhu: [popis, základ (present|all), čitatel, jmenovatel, ostře větší?]. */
const MAJORITY = [
    'half' => ['nadpoloviční většina přítomných', 'present', 1, 2, true],
    'two_thirds' => ['alespoň 2/3 hlasů přítomných', 'present', 2, 3, false],
    'three_quarters' => ['alespoň 3/4 hlasů přítomných', 'present', 3, 4, false],
    'all_half' => ['nadpoloviční většina všech vlastníků', 'all', 1, 2, true],
    'all_three_quarters' => ['alespoň 3/4 hlasů všech vlastníků', 'all', 3, 4, false],
    'all_unanimous' => ['souhlas všech vlastníků', 'all', 1, 1, false],
];
const MOTION_CHOICES = ['yes' => 'Pro', 'no' => 'Proti', 'abstain' => 'Zdržel se'];

/**
 * Výsledek hlasování o návrhu na shromáždění. Kdo je přítomen a neřekl jinak, hlasuje pro
 * („Kdo je proti? Kdo se zdržel?“). Uzavřený návrh vrací uložený výsledek – pozdější odchody ho nezmění.
 */
function motion_result(array $m, array $p): array
{
    if ($m['status'] === 'closed' && $m['result']) {
        return json_decode($m['result'], true) + ['closed' => true];
    }
    $votes = [];
    foreach (all('SELECT unit_id, choice FROM meeting_votes WHERE motion_id = ?', [$m['id']]) as $v) {
        $votes[(int) $v['unit_id']] = $v['choice'];
    }
    $sum = ['yes' => 0.0, 'no' => 0.0, 'abstain' => 0.0];
    $count = ['yes' => 0, 'no' => 0, 'abstain' => 0];
    $present = $all = 0.0;
    foreach ($p['rows'] as $r) {
        $all += $r['weight'];
        if ($r['mode'] === 'self' || $r['mode'] === 'proxy') {
            $c = $votes[(int) $r['id']] ?? 'yes';
            $sum[$c] += $r['weight'];
            $count[$c]++;
            $present += $r['weight'];
        }
    }
    [$label, $basis, $num, $den, $strict] = MAJORITY[$m['majority']] ?? MAJORITY['half'];
    $base = $basis === 'all' ? $all : $present;
    $ratio = $base > 0 ? $sum['yes'] / $base : 0.0;
    $need = $num / $den;
    $enough = $strict ? $ratio > $need + 1e-9 : $ratio >= $need - 1e-9;
    $pct = fn(float $x) => $present > 0 ? $x / $present * 100 : 0.0;
    return ['yes' => $pct($sum['yes']), 'no' => $pct($sum['no']), 'abstain' => $pct($sum['abstain']), 'count' => $count,
        'yesOfAll' => $all > 0 ? $sum['yes'] / $all * 100 : 0.0, 'basis' => $basis, 'label' => $label, 'quorate' => $p['quorate'],
        'passed' => $p['quorate'] && $enough && $base > 0, 'presentPct' => $p['percent'], 'closed' => false, 'votes' => $votes];
}
function motion_verdict(array $r): string
{
    if (!$r['quorate']) {
        return 'Shromáždění není usnášeníschopné – usnesení nelze přijmout.';
    }
    return ($r['passed'] ? 'Návrh prošel' : 'Návrh neprošel') . ' – pro hlasovalo ' . percent_cz($r['basis'] === 'all' ? $r['yesOfAll'] : $r['yes']) . ($r['basis'] === 'all' ? ' všech vlastníků' : ' přítomných') . ', potřeba je ' . $r['label'] . '.';
}
function motion_summary(array $r): array
{
    return ['yes' => percent_cz($r['yes']), 'no' => percent_cz($r['no']), 'abstain' => percent_cz($r['abstain']), 'cyes' => $r['count']['yes'], 'cno' => $r['count']['no'], 'cabstain' => $r['count']['abstain'],
        'wyes' => round($r['yes'], 1), 'wno' => round($r['no'], 1), 'wabstain' => round($r['abstain'], 1), 'passed' => $r['passed'], 'verdict' => motion_verdict($r)];
}
/**
 * Body programu jako kandidáti na hlasování: [text, hlasuje se?]. Procedurální body (zahájení, diskuze,
 * ukončení) a poznámky typu „Účast nutná“ web předvybere jako nehlasované; správce volbu může změnit.
 */
function program_points(string $program): array
{
    $out = [];
    $bullet = '/^\s*(?:[-–•*]|\d+[.)])\s+/u';
    $lines = preg_split('/\R/u', $program);
    // Má-li program odrážky, řádky bez odrážky jsou jen poznámky k bodu („Účast nutná“), ne samostatné body.
    $hasBullets = (bool) preg_grep($bullet, $lines);
    foreach ($lines as $line) {
        if ($hasBullets && !preg_match($bullet, $line)) {
            continue;
        }
        $t = trim(preg_replace($bullet, '', $line));
        if ($t === '' || mb_strlen($t) < 4) {
            continue;
        }
        $skip = (bool) preg_match('/^(zahájení|ukončení|závěr|podněty|diskuz|různé|informace|zpráva|účast|prezence|kontrola usnášeníschopnosti)/iu', $t);
        $out[] = [mb_substr($t, 0, 300), !$skip];
    }
    return $out;
}
/** Návrhy k termínu s výsledky. */
function meeting_motions(int $eventId, array $p): array
{
    $out = [];
    foreach (all('SELECT * FROM meeting_motions WHERE event_id = ? ORDER BY sort, id', [$eventId]) as $m) {
        $out[] = $m + ['res' => motion_result($m, $p)];
    }
    return $out;
}

/** Souhrn pro průběžnou aktualizaci stránky (JSON). */
function presence_summary(array $p): array
{
    return ['percent' => percent_cz($p['percent']), 'width' => round(min(100, $p['percent']), 1), 'quorate' => $p['quorate'], 'self' => $p['counts']['yes'], 'proxy' => $p['counts']['proxy'],
        'absent' => $p['counts']['no'], 'units' => $p['units'], 'missingDocs' => $p['missingDocs'],
        'verdict' => $p['quorate'] ? 'Shromáždění je usnášeníschopné.' : 'Shromáždění zatím není usnášeníschopné – chybí ' . percent_cz(max(0, $p['threshold'] - $p['percent'])) . ' a alespoň jeden další hlas.'];
}
/** Uloží nahranou plnou moc k jednotce (nahradí dřívější). Vrací text chyby, nebo null. */
function proxy_file_save(int $eventId, int $unitId, int $userId): ?string
{
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'Vyberte soubor s podepsanou plnou mocí (PDF nebo fotografie).';
    }
    if (!in_array(strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png'], true)) {
        return 'Plnou moc nahrajte jako PDF nebo fotografii (JPG, PNG).';
    }
    $up = store_upload($f);
    if (is_string($up)) {
        return $up;
    }
    proxy_file_delete($eventId, $unitId);
    q('INSERT INTO event_proxy_files (event_id, unit_id, filename, original_name, size, uploaded_by, uploaded_at) VALUES (?,?,?,?,?,?,?)',
        [$eventId, $unitId, $up[0], mb_substr($up[1], 0, 250), $up[2], $userId, date('Y-m-d H:i:s')]);
    return null;
}
function proxy_file_delete(int $eventId, int $unitId): void
{
    foreach (all('SELECT id, filename FROM event_proxy_files WHERE event_id = ? AND unit_id = ?', [$eventId, $unitId]) as $old) {
        if (preg_match('/^[a-f0-9]{32}\.[a-z]+$/', $old['filename'])) {
            @unlink(UPLOAD_DIR . '/' . $old['filename']);
        }
        q('DELETE FROM event_proxy_files WHERE id = ?', [$old['id']]);
    }
}

// ---------- Správce: prezence na místě ----------

$route('GET', '/admin/terminy/(\d+)/prezence-na-miste', function (int $id) {
    require_admin();
    $ev = one('SELECT * FROM events WHERE id = ?', [$id]) ?? not_found();
    $p = presence($id);
    render('admin/presence', ['title' => 'Prezence: ' . $ev['title'], 'ev' => $ev, 'p' => $p, 'motions' => meeting_motions($id, $p), 'errors' => array_filter([$_SESSION['presence_error'] ?? null])]);
    unset($_SESSION['presence_error']);
});
$route('POST', '/admin/terminy/(\d+)/prezence-na-miste', function (int $id) {
    $admin = require_admin();
    one('SELECT id FROM events WHERE id = ?', [$id]) ?? not_found();
    $do = post('do');
    $unitId = (int) post('unit_id');
    if ($do === 'reset') {
        q('DELETE FROM event_presence WHERE event_id = ?', [$id]);
        audit('presence', 'Prezence vynulována');
        flash('Prezence byla vynulována. Plné moci zůstaly nahrané.');
        redirect('/admin/terminy/' . $id . '/prezence-na-miste');
    }
    // ---- Návrhy a hlasování na shromáždění ----
    if ($do === 'motion-add') {
        $title = trim(mb_substr(post('title'), 0, 300));
        if ($title === '') {
            $_SESSION['presence_error'] = 'Napište znění návrhu.';
        } else {
            q("INSERT INTO meeting_motions (event_id, title, majority, sort, status, closed_at) VALUES (?,?,?,?,'open','')",
                [$id, $title, isset(MAJORITY[post('majority')]) ? post('majority') : 'half', (int) val('SELECT COALESCE(MAX(sort), 0) + 1 FROM meeting_motions WHERE event_id = ?', [$id])]);
            audit('presence', 'Návrh k hlasování: ' . $title);
        }
        redirect('/admin/terminy/' . $id . '/prezence-na-miste#navrhy');
    }
    if ($do === 'motion-program') {
        $points = program_points((string) val('SELECT program FROM events WHERE id = ?', [$id]));
        $have = array_flip(array_column(all('SELECT title FROM meeting_motions WHERE event_id = ?', [$id]), 'title'));
        $sort = (int) val('SELECT COALESCE(MAX(sort), 0) FROM meeting_motions WHERE event_id = ?', [$id]);
        $added = 0;
        foreach ((array) ($_POST['points'] ?? []) as $i) {
            $t = $points[(int) $i][0] ?? null;
            if ($t !== null && !isset($have[$t])) {
                q("INSERT INTO meeting_motions (event_id, title, majority, sort, status, closed_at) VALUES (?,?,?,?,'open','')", [$id, $t, 'half', ++$sort]);
                $added++;
            }
        }
        audit('presence', 'Návrhy z programu: ' . $added);
        flash($added ? "Z programu vzniklo $added návrhů k hlasování. U každého můžete upravit znění a potřebnou většinu." : 'Nevybrali jste žádný nový bod programu.', $added ? 'ok' : 'info');
        redirect('/admin/terminy/' . $id . '/prezence-na-miste#navrhy');
    }
    if (str_starts_with($do, 'motion-') || $do === 'vote') {
        $m = one('SELECT * FROM meeting_motions WHERE id = ? AND event_id = ?', [(int) post('motion_id'), $id]) ?? not_found();
        if ($do === 'motion-edit') {
            $title = trim(mb_substr(post('title'), 0, 300));
            if ($title !== '') {
                q('UPDATE meeting_motions SET title = ?, majority = ? WHERE id = ?', [$title, isset(MAJORITY[post('majority')]) ? post('majority') : $m['majority'], $m['id']]);
            }
        } elseif ($do === 'motion-delete') {
            q('DELETE FROM meeting_votes WHERE motion_id = ?', [$m['id']]);
            q('DELETE FROM meeting_motions WHERE id = ?', [$m['id']]);
        } elseif ($do === 'motion-close') {
            $r = motion_result($m, presence($id));
            unset($r['closed']);
            q("UPDATE meeting_motions SET status = 'closed', result = ?, closed_at = ? WHERE id = ?", [json_encode($r, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $m['id']]);
            audit('presence', 'Hlasování uzavřeno: ' . $m['title'] . ' · ' . ($r['passed'] ? 'přijato' : 'nepřijato'));
        } elseif ($do === 'motion-reopen') {
            q("UPDATE meeting_motions SET status = 'open', result = NULL, closed_at = '' WHERE id = ?", [$m['id']]);
        } elseif ($do === 'vote' && $m['status'] !== 'closed') {
            $choice = post('choice');
            $unit = (int) post('unit_id');
            if ($choice === 'no' || $choice === 'abstain') {
                q('INSERT INTO meeting_votes (motion_id, unit_id, choice) VALUES (?,?,?) ON CONFLICT(motion_id, unit_id) DO UPDATE SET choice = excluded.choice', [$m['id'], $unit, $choice]);
            } else {
                q('DELETE FROM meeting_votes WHERE motion_id = ? AND unit_id = ?', [$m['id'], $unit]);
            }
            if (post('ajax') === '1') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true] + motion_summary(motion_result($m, presence($id))), JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        redirect('/admin/terminy/' . $id . '/prezence-na-miste#m' . $m['id']);
    }
    if (!val('SELECT 1 FROM units WHERE id = ?', [$unitId])) {
        not_found();
    }
    $mode = post('mode');
    if (isset(PRESENCE[$mode])) {
        $proxy = $mode === 'proxy' ? mb_substr(post('proxy_name'), 0, 190) : '';
        if ($mode === 'proxy' && $proxy === '') {
            $proxy = (string) val("SELECT proxy_name FROM event_rsvp WHERE event_id = ? AND unit_id = ? AND status = 'proxy'", [$id, $unitId]);
        }
        q("INSERT INTO event_presence (event_id, unit_id, mode, proxy_name, proxy_ok, checked_by, checked_at) VALUES (?,?,?,?,?,?,?)
            ON CONFLICT(event_id, unit_id) DO UPDATE SET mode = excluded.mode, proxy_name = excluded.proxy_name, proxy_ok = excluded.proxy_ok, checked_by = excluded.checked_by, checked_at = excluded.checked_at",
            [$id, $unitId, $mode, $proxy, $mode === 'proxy' && post('proxy_ok') === '1' ? 1 : 0, $admin['id'], date('Y-m-d H:i:s')]);
    } else {
        q('DELETE FROM event_presence WHERE event_id = ? AND unit_id = ?', [$id, $unitId]);
    }
    if (post('ajax') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        $p = presence($id);
        $motions = [];
        foreach (meeting_motions($id, $p) as $m) {
            if ($m['status'] !== 'closed') {
                $motions[$m['id']] = motion_summary($m['res']);
            }
        }
        echo json_encode(['ok' => true, 'motions' => $motions] + presence_summary($p), JSON_UNESCAPED_UNICODE);
        exit;
    }
    redirect('/admin/terminy/' . $id . '/prezence-na-miste#j' . $unitId);
});

// ---------- Plná moc: nahrání vlastníkem a zobrazení ----------

$route('POST', '/terminy/(\d+)/plna-moc/nahrat', function (int $id) {
    $u = require_login();
    $ev = one('SELECT * FROM events WHERE id = ?', [$id]) ?? not_found();
    $unitId = (int) post('unit_id');
    if ($u['kind'] !== 'owner' || !in_array($unitId, user_unit_ids((int) $u['id']), true)) {
        flash('Plnou moc může nahrát jen vlastník za svou jednotku.', 'error');
    } elseif ($ev['event_date'] < date('Y-m-d')) {
        flash('Termín už proběhl. Plnou moc předejte výboru.', 'error');
    } elseif (post('do') === 'delete') {
        proxy_file_delete($id, $unitId);
        flash('Nahraná plná moc byla odstraněna.');
    } elseif ($err = proxy_file_save($id, $unitId, (int) $u['id'])) {
        flash($err, 'error');
    } else {
        flash('Děkujeme, podepsaná plná moc je nahraná. Výbor ji uvidí u prezence; originál ať má zmocněnec s sebou, pokud ho stanovy vyžadují.');
    }
    redirect('/terminy/' . $id . '#j' . $unitId);
});
$route('GET', '/terminy/(\d+)/plna-moc/soubor', function (int $id) {
    require_login();
    $unitId = (int) ($_GET['jednotka'] ?? 0);
    if (!is_admin() && !in_array($unitId, my_unit_ids(), true)) {
        not_found();
    }
    $f = one('SELECT * FROM event_proxy_files WHERE event_id = ? AND unit_id = ?', [$id, $unitId]) ?? not_found();
    $path = UPLOAD_DIR . '/' . $f['filename'];
    if (!preg_match('/^[a-f0-9]{32}\.[a-z]+$/', $f['filename']) || !is_file($path)) {
        not_found();
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    header_remove('Content-Security-Policy');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . (['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream'));
    header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($f['original_name'] ?: 'plna-moc.' . $ext));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
});
