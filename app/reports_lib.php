<?php
declare(strict_types=1);

// Hlášení problémů a nápadů od uživatelů (a automaticky zachycené chyby webu). Na platformě pro více SVJ je vyřizuje
// hlavní správce v konzoli, na webu jednoho společenství jeho správci v administraci.

const REPORT_TYPES = ['bug' => 'Na webu něco nefunguje', 'idea' => 'Nápad, jak web zlepšit'];
const REPORT_STATUS = ['new' => 'Nové', 'in_progress' => 'Řeší se', 'planned' => 'Plánujeme', 'done' => 'Vyřešeno', 'rejected' => 'Nebudeme řešit'];
const REPORT_STATUS_CLASS = ['new' => 'new', 'in_progress' => 'in_progress', 'planned' => 'in_progress', 'done' => 'done', 'rejected' => 'rsvp-no'];

function reports_central(): bool
{
    return platform_enabled();
}
function reports_pdo(): PDO
{
    return reports_central() ? platform_db() : db();
}
/** Tabulka hlášení se založí při prvním použití (v databázi platformy, nebo v databázi webu jednoho SVJ). */
function reports_ready(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = reports_pdo();
    $mysql = !reports_central() && db_driver() === 'mysql';
    if ($mysql ? $pdo->query("SHOW TABLES LIKE 'reports'")->fetch() : $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'reports'")->fetch()) {
        return;
    }
    $text = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $pdo->exec('CREATE TABLE reports (id ' . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ", tenant {$text(64)}, user_id INT NOT NULL DEFAULT 0, user_name {$text(191)},
        user_email {$text(191)}, user_role {$text(40)}, type {$text(10)}, body " . ($mysql ? 'TEXT NOT NULL' : 'TEXT NOT NULL') . ", url {$text(500)}, page_title {$text(255)}, agent {$text(255)}, viewport {$text(40)},
        screenshot {$text(80)}, status " . ($mysql ? "VARCHAR(20) NOT NULL DEFAULT 'new'" : "TEXT NOT NULL DEFAULT 'new'") . ", reply " . ($mysql ? 'TEXT NULL' : 'TEXT') . ", replied_at {$text(19)}, replied_by {$text(191)},
        note " . ($mysql ? 'TEXT NULL' : 'TEXT') . ", created_at {$text(19)}, updated_at {$text(19)}, hits INT NOT NULL DEFAULT 1, fingerprint {$text(32)}, ip {$text(45)})"
        . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
}
function rq(string $sql, array $params = []): PDOStatement
{
    reports_ready();
    $st = reports_pdo()->prepare($sql);
    $st->execute($params);
    return $st;
}
function reports_dir(): string
{
    $dir = ROOT_STORAGE . '/reports';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}
function reports_open_count(): int
{
    try {
        return (int) rq("SELECT COUNT(*) FROM reports WHERE status = 'new'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
/** Kam se má uživatel po odeslání vrátit: jen místní cesta na tomto webu. */
function report_back(string $z): string
{
    return preg_match('~^/(?![/\\\\])[^\s]*$~', $z) && !str_starts_with($z, '/hlaseni') ? $z : '/';
}
/**
 * Přijme hlášení z formuláře. $who = ['tenant', 'user_id', 'name', 'email', 'role'] (u nepřihlášeného prázdné, e-mail z formuláře).
 * Vrací seznam chyb; prázdný = uloženo.
 */
function report_submit(array $who): array
{
    $errors = [];
    $body = mb_substr(post('body'), 0, 5000);
    $type = isset(REPORT_TYPES[post('type')]) ? post('type') : 'bug';
    if ($who['user_id'] === 0) {
        $who['email'] = post('email');
        if (!filter_var($who['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Vyplňte prosím svůj e-mail, ať Vám můžeme odpovědět.';
        }
    }
    if (post('website') !== '') {
        return ['Hlášení se nepodařilo odeslat.'];
    }
    if (mb_strlen($body) < 5) {
        $errors[] = 'Popište prosím, co se stalo nebo co navrhujete.';
    }
    if ((int) rq('SELECT COUNT(*) FROM reports WHERE ip = ? AND created_at > ? AND type <> ?', [client_ip(), date('Y-m-d H:i:s', time() - 3600), 'auto'])->fetchColumn() >= ($who['user_id'] ? 20 : 5)) {
        $errors[] = 'Za poslední hodinu od Vás přišlo hodně hlášení. Zkuste to prosím později.';
    }
    $shot = $errors ? '' : report_store_shot($errors);
    if ($errors) {
        return $errors;
    }
    $clean = fn(string $k, int $n) => mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', post($k)), 0, $n);
    $url = $clean('url', 500);
    $now = date('Y-m-d H:i:s');
    rq('INSERT INTO reports (tenant, user_id, user_name, user_email, user_role, type, body, url, page_title, agent, viewport, screenshot, created_at, updated_at, ip) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$who['tenant'], $who['user_id'], $who['name'], $who['email'], $who['role'], $type, $body, preg_match('~^https?://~', $url) ? $url : '', $clean('title', 255),
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $clean('viewport', 40), $shot, $now, $now, client_ip()]);
    if (reports_central() && pset('contact_email') !== '') {
        platform_mail(pset('contact_email'), 'Nové hlášení: ' . REPORT_TYPES[$type], ($who['name'] !== '' ? $who['name'] . ' ' : '') . '<' . $who['email'] . '>' . ($who['tenant'] !== '' ? ', web ' . $who['tenant'] : '')
            . "\nStránka: $url\n\n$body\n\nVyřídit: " . platform_url('/sprava/hlaseni'));
    }
    return [];
}
/** Uloží přiložený snímek (pole screenshot) mezi soubory hlášení; vrací název souboru, nebo '' (bez snímku / chyba v $errors). */
function report_store_shot(array &$errors): string
{
    $f = $_FILES['screenshot'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    $mime = ($f['error'] ?? 1) === UPLOAD_ERR_OK ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : '';
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg'][$mime] ?? null;
    if (!$ext || $f['size'] > 6 * 1048576 || !@getimagesize($f['tmp_name'])) {
        $errors[] = 'Snímek se nepodařilo přiložit. Povolený je obrázek PNG nebo JPG do 6 MB.';
        return '';
    }
    $shot = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], reports_dir() . '/' . $shot)) {
        $errors[] = 'Snímek se nepodařilo uložit.';
        return '';
    }
    return $shot;
}
/**
 * Vlákno zpráv k hlášení: doplnění od autora (author = 'user') a odpovědi (author = 'staff'). Tabulka se založí při prvním
 * použití; dřívější jediná odpověď u hlášení se přitom převede do vlákna jako první odpověď.
 */
function report_messages_ready(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    reports_ready();
    $pdo = reports_pdo();
    $mysql = !reports_central() && db_driver() === 'mysql';
    if ($mysql ? $pdo->query("SHOW TABLES LIKE 'report_messages'")->fetch() : $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'report_messages'")->fetch()) {
        return;
    }
    $text = fn(int $n) => $mysql ? "VARCHAR($n) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
    $pdo->exec('CREATE TABLE IF NOT EXISTS report_messages (id ' . ($mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ', report_id INT NOT NULL, author ' . $text(10)
        . ", by_name {$text(191)}, body TEXT NOT NULL, screenshot {$text(80)}, created_at {$text(19)})" . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    $pdo->exec('CREATE INDEX report_messages_report ON report_messages (report_id)');
    $pdo->exec("INSERT INTO report_messages (report_id, author, by_name, body, screenshot, created_at)
        SELECT id, 'staff', replied_by, reply, '', CASE WHEN replied_at <> '' THEN replied_at ELSE updated_at END FROM reports WHERE reply IS NOT NULL AND reply <> ''");
}
/** Zprávy ve vlákně hlášení (nejstarší první); pro více hlášení najednou pole [id hlášení => zprávy]. */
function report_messages(int $id): array
{
    return report_messages_for([$id])[$id] ?? [];
}
function report_messages_for(array $ids): array
{
    report_messages_ready();
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $out = [];
    foreach (rq('SELECT * FROM report_messages WHERE report_id IN (' . implode(',', $ids) . ') ORDER BY id')->fetchAll() as $m) {
        $out[(int) $m['report_id']][] = $m;
    }
    return $out;
}
/** Adresa, kde autor hlášení uvidí vlákno a může odpovědět (jen přihlášený uživatel webu společenství); jinak ''. */
function report_reply_url(array $r): string
{
    if ((int) $r['user_id'] === 0 || $r['type'] === 'auto') {
        return '';
    }
    if (!reports_central()) {
        return report_page_url('/hlaseni') . '#hlaseni-' . $r['id'];
    }
    $t = $r['tenant'] !== '' ? tenant_row($r['tenant']) : null;
    return $t ? tenant_url($t, '/hlaseni#hlaseni-' . $r['id']) : '';
}
/**
 * Autor hlášení doplní informace nebo odpoví na dotaz (Moje hlášení). Hlášení se vrátí mezi nová, aby ho vyřizující viděl.
 * Vrací seznam chyb; prázdný = uloženo.
 */
function report_user_followup(array $r, array $who): array
{
    report_messages_ready();
    $errors = [];
    $body = mb_substr(trim(post('body')), 0, 5000);
    if (mb_strlen($body) < 2) {
        $errors[] = 'Napište prosím, co chcete doplnit.';
    }
    if ((int) rq("SELECT COUNT(*) FROM report_messages WHERE report_id = ? AND author = 'user' AND created_at > ?", [$r['id'], date('Y-m-d H:i:s', time() - 3600)])->fetchColumn() >= 10) {
        $errors[] = 'K tomuto hlášení jste za poslední hodinu poslali hodně zpráv. Zkuste to prosím později.';
    }
    $shot = $errors ? '' : report_store_shot($errors);
    if ($errors) {
        return $errors;
    }
    $now = date('Y-m-d H:i:s');
    rq('INSERT INTO report_messages (report_id, author, by_name, body, screenshot, created_at) VALUES (?,?,?,?,?,?)', [$r['id'], 'user', $who['name'], $body, $shot, $now]);
    rq("UPDATE reports SET status = 'new', updated_at = ? WHERE id = ?", [$now, $r['id']]);
    if (reports_central() && pset('contact_email') !== '') {
        platform_mail(pset('contact_email'), 'Doplnění k hlášení č. ' . $r['id'], ($who['name'] !== '' ? $who['name'] . ' ' : '') . '<' . $who['email'] . '>' . ($r['tenant'] !== '' ? ', web ' . $r['tenant'] : '')
            . "

$body

Vyřídit: " . platform_url('/sprava/hlaseni/' . $r['id']));
    }
    return [];
}
/** Chybu webu (neošetřenou výjimku) zapíše mezi hlášení; opakování téže chyby jen zvýší počet. Nikdy nesmí sama selhat. */
function report_auto(Throwable $e): void
{
    try {
        $where = str_replace(dirname(__DIR__), '', $e->getFile()) . ':' . $e->getLine();
        $fp = md5(get_class($e) . '|' . $where . '|' . mb_substr($e->getMessage(), 0, 200));
        $tenant = defined('TENANT') ? TENANT : '';
        $now = date('Y-m-d H:i:s');
        $old = rq("SELECT id FROM reports WHERE type = 'auto' AND fingerprint = ? AND tenant = ? AND status IN ('new','in_progress','planned') ORDER BY id DESC LIMIT 1", [$fp, $tenant])->fetchColumn();
        if ($old) {
            rq('UPDATE reports SET hits = hits + 1, updated_at = ? WHERE id = ?', [$now, $old]);
            return;
        }
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $url = ($https ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.:\-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) . mb_substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 400);
        rq('INSERT INTO reports (tenant, user_id, type, body, url, agent, created_at, updated_at, fingerprint, ip) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$tenant, (int) ($_SESSION['uid'] ?? 0), 'auto', get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 1500) . "\n" . $where . "\n" . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . $url,
                $url, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $now, $now, $fp, client_ip()]);
    } catch (Throwable $ignored) {
    }
}
/** Uloží rozhodnutí správce (stav, nová odpověď do vlákna, interní poznámka); novou odpověď pošle autorovi e-mailem. */
function report_admin_save(array $r, string $by): void
{
    report_update($r, isset(REPORT_STATUS[post('status')]) ? post('status') : $r['status'], post('reply'), post('note'), $by);
}
/**
 * Změní stav a interní poznámku hlášení a případně přidá do vlákna novou odpověď autorovi ($reply; '' = bez odpovědi).
 * Novou odpověď nebo změnu stavu pošle autorovi e-mailem (formulář v konzoli i MCP).
 */
function report_update(array $r, string $status, string $reply, string $note, string $by): void
{
    report_messages_ready();
    $status = isset(REPORT_STATUS[$status]) ? $status : $r['status'];
    $reply = mb_substr(trim($reply), 0, 5000);
    $now = date('Y-m-d H:i:s');
    if ($reply !== '' && $r['type'] !== 'auto') {
        rq('INSERT INTO report_messages (report_id, author, by_name, body, screenshot, created_at) VALUES (?,?,?,?,?,?)', [$r['id'], 'staff', $by, $reply, '', $now]);
        rq('UPDATE reports SET reply = ?, replied_at = ?, replied_by = ? WHERE id = ?', [$reply, $now, $by, $r['id']]);
    }
    rq('UPDATE reports SET status = ?, note = ?, updated_at = ? WHERE id = ?', [$status, mb_substr($note, 0, 5000), $now, $r['id']]);
    // Autor dostane e-mail s odpovědí, nebo když se hlášení uzavře či naplánuje; samotné „Řeší se“ / „Nové“ bez odpovědi
    // ho neruší (agent tak označuje rozpracované opravy).
    $notify = $reply !== '' || ($status !== $r['status'] && in_array($status, ['done', 'rejected', 'planned'], true));
    if (filter_var($r['user_email'], FILTER_VALIDATE_EMAIL) && $notify && $r['type'] !== 'auto') {
        $link = report_reply_url($r);
        $text = "Dobrý den,\n\nk Vašemu hlášení ze dne " . cz_date($r['created_at']) . " („" . mb_strimwidth($r['body'], 0, 120, '…') . "“):\n\nStav: " . REPORT_STATUS[$status] . ($reply !== '' ? "\n\n" . $reply : '')
            . ($link !== '' ? "\n\nOdpovědět nebo něco doplnit můžete u hlášení na webu (po přihlášení):\n" . $link . "\nOdpověď na tento e-mail se k hlášení nepřipojí."
                : ($r['type'] !== 'auto' && (int) $r['user_id'] === 0 ? "\n\nPokud chcete něco doplnit, odpovězte prosím na tento e-mail." : ''))
            . "\n\nDěkujeme, že pomáháte web zlepšovat.";
        reports_central() ? platform_mail($r['user_email'], 'Vaše hlášení: ' . REPORT_STATUS[$status], $text) : send_mail($r['user_email'], 'Vaše hlášení: ' . REPORT_STATUS[$status] . ' – ' . site('name'), $text);
    }
}
/** Pošle snímek hlášení, případně snímek ze zprávy ve vlákně ($msgId). */
function report_image(array $r, int $msgId = 0): never
{
    if ($msgId) {
        report_messages_ready();
        $r['screenshot'] = (string) (rq('SELECT screenshot FROM report_messages WHERE id = ? AND report_id = ?', [$msgId, $r['id']])->fetchColumn() ?: '');
    }
    $file = reports_dir() . '/' . basename((string) $r['screenshot']);
    if ($r['screenshot'] === '' || !is_file($file)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . (str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg'));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}
function report_who(): array
{
    $u = defined('TENANT') && empty($GLOBALS['IS_PLATFORM']) ? user() : null;
    return ['tenant' => defined('TENANT') ? TENANT : '', 'user_id' => (int) ($u['id'] ?? 0), 'name' => (string) ($u['name'] ?? ''), 'email' => (string) ($u['email'] ?? ''),
        'role' => $u ? ($u['role'] === 'admin' ? 'správce webu' : mb_strtolower(KINDS[$u['kind']] ?? '')) : 'nepřihlášený'];
}
/** Seznam a detail hlášení pro toho, kdo je vyřizuje. $base je adresa seznamu (v konzoli /hlaseni, v administraci /admin/hlaseni). */
function report_admin_page(string $base, string $rest, string $method, string $by): void
{
    if (preg_match('~^/(\d+)(/snimek(?:/(\d+))?)?$~', $rest, $m)) {
        $r = rq('SELECT * FROM reports WHERE id = ?', [$m[1]])->fetch();
        if (!$r) {
            http_response_code(404);
            render(reports_central() ? 'platform/message' : 'error', ['title' => 'Hlášení nenalezeno', 'message' => 'Toto hlášení neexistuje.']);
            return;
        }
        if (!empty($m[2])) {
            report_image($r, (int) ($m[3] ?? 0));
        }
        if ($method === 'POST') {
            report_admin_save($r, $by);
            if (function_exists('platform_log') && reports_central()) {
                platform_log('Vyřízení hlášení', 'č. ' . $r['id'] . ' · ' . (REPORT_STATUS[post('status')] ?? ''));
            }
            flash('Uloženo.' . (filter_var($r['user_email'], FILTER_VALIDATE_EMAIL) && $r['type'] !== 'auto' ? ' Autor hlášení uvidí stav a odpověď v části Moje hlášení.' : ''));
            redirect($base . '/' . $r['id']);
        }
        render('reports/admin_detail', ['title' => 'Hlášení č. ' . $r['id'], 'r' => $r, 'base' => $base, 'msgs' => report_messages((int) $r['id'])]);
        return;
    }
    $filter = (string) ($_GET['stav'] ?? 'open');
    $where = match (true) {
        $filter === 'all' => '1=1',
        $filter === 'auto' => "type = 'auto'",
        isset(REPORT_STATUS[$filter]) => 'status = ' . reports_pdo()->quote($filter),
        default => "status IN ('new','in_progress','planned')",
    };
    report_messages_ready();
    render('reports/admin_list', ['title' => 'Hlášení', 'base' => $base, 'filter' => $filter,
        'rows' => rq("SELECT *, (SELECT author FROM report_messages m WHERE m.report_id = reports.id ORDER BY m.id DESC LIMIT 1) AS last_author FROM reports WHERE $where ORDER BY CASE status WHEN 'new' THEN 0 ELSE 1 END, updated_at DESC LIMIT 500")->fetchAll(),
        'counts' => array_column(rq('SELECT status, COUNT(*) AS n FROM reports GROUP BY status')->fetchAll(), 'n', 'status')]);
}
function report_page_url(string $path): string
{
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) . url($path);
}

/** Odeslání z okna hlášení na pozadí (ajax=1): odpověď jako JSON, stránka s rozdělanou prací zůstane, jak byla. */
function report_ajax_reply(array $errors, string $message): void
{
    if (post('ajax') !== '1') {
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($errors) {
        http_response_code(422);
    }
    echo json_encode($errors ? ['ok' => false, 'errors' => array_values($errors)] : ['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
