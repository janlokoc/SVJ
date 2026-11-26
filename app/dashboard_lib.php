<?php
declare(strict_types=1);

/*
 * Přehled správy domu: čísla za zvolené období z databáze domu (hlášení, revize, komunikace, práce správců).
 * Počítá se nad PDO domu, aby stejná funkce sloužila administraci domu i partnerskému portálu (víc domů najednou).
 */

const DASH_PERIODS = [30 => '30 dní', 90 => '90 dní', 365 => '12 měsíců'];
/** Záznamy, které do časové osy „co se dělo“ nepatří (přihlášení apod.). */
const DASH_QUIET = ['login', 'logout', 'login_failed', 'login_2fa_failed', 'session_expired', 'partner_login', 'support_login', 'support_logout', 'password_changed', '2fa_enabled', '2fa_disabled', 'gdpr_export', 'setup_code_failed', 'sso_login', 'sso_failed', 'sso_linked', 'sso_unlinked'];

function dash_period(): int
{
    $d = (int) ($_GET['obdobi'] ?? 90);
    return isset(DASH_PERIODS[$d]) ? $d : 90;
}
/**
 * Ukazatele domu. $full = i graf a časová osa (pro stránku jednoho domu); $staffIds = účty správcovské firmy (zvýrazní se v časové ose).
 */
function house_dashboard(PDO $pdo, int $days, bool $full = true, array $staffIds = []): array
{
    ticket_ready($pdo);
    $one = function (string $sql, array $p = []) use ($pdo) {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchColumn();
    };
    $now = time();
    $today = date('Y-m-d', $now);
    $since = date('Y-m-d H:i:s', $now - $days * 86400);
    $done = TICKET_STATUS['done'];
    // Vyřešení podle historie (první přechod do stavu Vyřešeno); starší hlášení bez historie podle data poslední změny.
    $resolved = [];
    $st = $pdo->prepare("SELECT t.id, t.created_at, COALESCE((SELECT MIN(l.at) FROM ticket_log l WHERE l.ticket_id = t.id AND l.kind = 'status' AND l.text LIKE ?), t.updated_at, t.created_at) AS done_at
        FROM tickets t WHERE t.status = 'done'");
    $st->execute([$done . '%']);
    foreach ($st->fetchAll() as $r) {
        if ((string) $r['done_at'] >= $since) {
            $resolved[] = max(0, (strtotime((string) $r['done_at']) - strtotime((string) $r['created_at'])) / 86400);
        }
    }
    // První reakce správce: zpráva obyvateli, změna stavu nebo přiřazení řešitele.
    $react = [];
    $st = $pdo->prepare("SELECT t.created_at, (SELECT MIN(l.at) FROM ticket_log l WHERE l.ticket_id = t.id AND l.kind IN ('msg_admin','status','assign')) AS first_at FROM tickets t WHERE t.created_at >= ?");
    $st->execute([$since]);
    foreach ($st->fetchAll() as $r) {
        if ($r['first_at']) {
            $react[] = max(0, (strtotime((string) $r['first_at']) - strtotime((string) $r['created_at'])) / 3600);
        }
    }
    $d = [
        'days' => $days,
        'open' => (int) $one("SELECT COUNT(*) FROM tickets WHERE status <> 'done'"),
        'late' => (int) $one("SELECT COUNT(*) FROM tickets WHERE status <> 'done' AND due_date <> '' AND due_date < ?", [$today]),
        'unassigned' => (int) $one("SELECT COUNT(*) FROM tickets WHERE status <> 'done' AND assignee_id = 0"),
        'created' => (int) $one('SELECT COUNT(*) FROM tickets WHERE created_at >= ?', [$since]),
        'resolved' => count($resolved),
        'avg_days' => $resolved ? round(array_sum($resolved) / count($resolved), 1) : null,
        'react_hours' => $react ? round(array_sum($react) / count($react), 1) : null,
        'revize_late' => (int) $one('SELECT COUNT(*) FROM maintenance WHERE next_date IS NOT NULL AND next_date <> \'\' AND next_date < ?', [$today]),
        'revize_soon' => (int) $one('SELECT COUNT(*) FROM maintenance WHERE next_date IS NOT NULL AND next_date >= ? AND next_date <= ?', [$today, date('Y-m-d', $now + 30 * 86400)]),
        'posts' => (int) $one('SELECT COUNT(*) FROM posts WHERE published_at >= ?', [substr($since, 0, 10)]),
        'mailings' => (int) $one('SELECT COUNT(*) FROM mailings WHERE sent_at >= ?', [$since]),
        'events' => (int) $one('SELECT COUNT(*) FROM events WHERE event_date >= ? AND event_date <= ?', [substr($since, 0, 10), $today]),
        'next_event' => $pdo->query("SELECT title, event_date FROM events WHERE event_date >= " . $pdo->quote($today) . ' ORDER BY event_date LIMIT 1')->fetch() ?: null,
    ];
    if (!$full) {
        return $d;
    }
    // Graf: nová hlášení po týdnech (30 a 90 dní), po měsících (12 měsíců).
    $buckets = [];
    if ($days >= 365) {
        for ($i = 11; $i >= 0; $i--) {
            $m = date('Y-m', strtotime(date('Y-m-01', $now) . " -$i month"));
            $buckets[$m] = ['label' => cz_month_short($m), 'from' => $m . '-01', 'n' => 0, 'done' => 0];
        }
    } else {
        $monday = strtotime('monday this week', $now);
        for ($i = (int) ceil($days / 7) - 1; $i >= 0; $i--) {
            $from = date('Y-m-d', $monday - $i * 7 * 86400);
            $buckets[$from] = ['label' => date('j. n.', strtotime($from)), 'from' => $from, 'n' => 0, 'done' => 0];
        }
    }
    $keys = array_keys($buckets);
    $find = function (string $date) use ($keys, $days): ?string {
        $k = $days >= 365 ? substr($date, 0, 7) : null;
        if ($k !== null) {
            return in_array($k, $keys, true) ? $k : null;
        }
        $hit = null;
        foreach ($keys as $from) {
            if ($date >= $from) {
                $hit = $from;
            }
        }
        return $hit;
    };
    $first = $buckets[$keys[0]]['from'];
    $st = $pdo->prepare('SELECT created_at FROM tickets WHERE created_at >= ?');
    $st->execute([$first]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
        if ($k = $find(substr((string) $c, 0, 10))) {
            $buckets[$k]['n']++;
        }
    }
    $d['series'] = array_values($buckets);
    // Časová osa: co správci webu (výbor i správcovská firma) za období udělali.
    $quiet = "'" . implode("','", DASH_QUIET) . "'";
    $st = $pdo->prepare("SELECT at, user_id, user_name, action, detail FROM audit_log WHERE at >= ? AND action NOT IN ($quiet) AND user_id IN (SELECT id FROM users WHERE role = 'admin') ORDER BY id DESC LIMIT 40");
    $st->execute([$since]);
    $timeline = [];
    foreach ($st->fetchAll() as $r) {
        $timeline[] = ['at' => $r['at'], 'who' => $r['user_name'], 'what' => $r['action'], 'detail' => $r['detail'], 'staff' => in_array((int) $r['user_id'], $staffIds, true)];
    }
    $st = $pdo->prepare("SELECT l.at, l.user_id, l.user_name, l.kind, l.text, t.subject FROM ticket_log l JOIN tickets t ON t.id = l.ticket_id WHERE l.at >= ? AND l.kind IN ('msg_admin','status','assign','due','supplier') ORDER BY l.id DESC LIMIT 40");
    $st->execute([$since]);
    foreach ($st->fetchAll() as $r) {
        $timeline[] = ['at' => $r['at'], 'who' => $r['user_name'], 'what' => 'Hlášení „' . $r['subject'] . '“: ' . ticket_log_label($r), 'detail' => $r['kind'] === 'msg_admin' ? mb_strimwidth($r['text'], 0, 120, '…') : $r['text'],
            'staff' => in_array((int) $r['user_id'], $staffIds, true)];
    }
    usort($timeline, fn($a, $b) => strcmp($b['at'], $a['at']));
    $d['timeline'] = array_slice($timeline, 0, 40);
    $d['staff_actions'] = count(array_filter($timeline, fn($t) => $t['staff']));
    $d['revize_next'] = $pdo->query("SELECT title, next_date FROM maintenance WHERE next_date IS NOT NULL AND next_date <> '' ORDER BY next_date LIMIT 4")->fetchAll();
    // Co se blíží: termíny i revize v jednom seznamu podle data.
    $upcoming = array_map(fn($r) => ['date' => $r['next_date'], 'title' => 'Revize: ' . $r['title'], 'late' => $r['next_date'] < $today], $d['revize_next']);
    $st = $pdo->prepare('SELECT title, event_date FROM events WHERE event_date >= ? ORDER BY event_date LIMIT 3');
    $st->execute([$today]);
    foreach ($st->fetchAll() as $r) {
        $upcoming[] = ['date' => $r['event_date'], 'title' => $r['title'], 'late' => false];
    }
    $st = $pdo->prepare("SELECT subject, due_date FROM tickets WHERE status <> 'done' AND due_date <> '' ORDER BY due_date LIMIT 3");
    $st->execute();
    foreach ($st->fetchAll() as $r) {
        $upcoming[] = ['date' => $r['due_date'], 'title' => 'Termín hlášení: ' . $r['subject'], 'late' => $r['due_date'] < $today];
    }
    $upcoming = function_exists('modf_upcoming') ? [...$upcoming, ...modf_upcoming($pdo, $today)] : $upcoming;   // smlouvy, vyúčtování
    usort($upcoming, fn($a, $b) => strcmp($a['date'], $b['date']));
    $d['upcoming'] = array_slice($upcoming, 0, 6);
    return $d;
}
function cz_month_short(string $ym): string
{
    $m = ['led', 'úno', 'bře', 'dub', 'kvě', 'čvn', 'čvc', 'srp', 'zář', 'říj', 'lis', 'pro'][(int) substr($ym, 5, 2) - 1];
    return $m . ' ' . substr($ym, 2, 2);
}
/** Doba v hodinách srozumitelně („3 h“, „2,5 dne“). */
function dash_hours(?float $h): string
{
    if ($h === null) {
        return '—';
    }
    return $h < 48 ? str_replace('.', ',', (string) round($h, $h < 10 ? 1 : 0)) . ' h' : str_replace('.', ',', (string) round($h / 24, 1)) . ' dne';
}
function dash_days(?float $d): string
{
    if ($d === null) {
        return '—';
    }
    $word = $d != (int) $d ? 'dne' : ($d == 1 ? 'den' : ($d >= 2 && $d <= 4 ? 'dny' : 'dní'));
    return str_replace('.', ',', (string) $d) . ' ' . $word;
}
