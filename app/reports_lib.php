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
    $shot = '';
    $f = $_FILES['screenshot'] ?? null;
    if (!$errors && $f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $mime = ($f['error'] ?? 1) === UPLOAD_ERR_OK ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : '';
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg'][$mime] ?? null;
        if (!$ext || $f['size'] > 6 * 1048576 || !@getimagesize($f['tmp_name'])) {
            $errors[] = 'Snímek se nepodařilo přiložit. Povolený je obrázek PNG nebo JPG do 6 MB.';
        } else {
            $shot = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], reports_dir() . '/' . $shot)) {
                $errors[] = 'Snímek se nepodařilo uložit.';
            }
        }
    }
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
/** Uloží rozhodnutí správce (stav, odpověď uživateli, interní poznámka); novou odpověď pošle autorovi e-mailem. */
function report_admin_save(array $r, string $by): void
{
    report_update($r, isset(REPORT_STATUS[post('status')]) ? post('status') : $r['status'], post('reply'), post('note'), $by);
}
/** Změní stav, odpověď a interní poznámku hlášení; novou odpověď nebo změnu stavu pošle autorovi e-mailem (formulář v konzoli i MCP). */
function report_update(array $r, string $status, string $reply, string $note, string $by): void
{
    $status = isset(REPORT_STATUS[$status]) ? $status : $r['status'];
    $reply = mb_substr($reply, 0, 5000);
    $changedReply = $reply !== (string) $r['reply'];
    rq('UPDATE reports SET status = ?, reply = ?, note = ?, updated_at = ?, replied_at = ?, replied_by = ? WHERE id = ?',
        [$status, $reply, mb_substr($note, 0, 5000), date('Y-m-d H:i:s'), $changedReply ? date('Y-m-d H:i:s') : $r['replied_at'], $changedReply ? $by : $r['replied_by'], $r['id']]);
    if (filter_var($r['user_email'], FILTER_VALIDATE_EMAIL) && ($changedReply && $reply !== '' || $status !== $r['status']) && $r['type'] !== 'auto') {
        $text = "Dobrý den,\n\nk Vašemu hlášení ze dne " . cz_date($r['created_at']) . " („" . mb_strimwidth($r['body'], 0, 120, '…') . "“):\n\nStav: " . REPORT_STATUS[$status] . ($reply !== '' ? "\n\n" . $reply : '') . "\n\nDěkujeme, že pomáháte web zlepšovat.";
        reports_central() ? platform_mail($r['user_email'], 'Vaše hlášení: ' . REPORT_STATUS[$status], $text) : send_mail($r['user_email'], 'Vaše hlášení: ' . REPORT_STATUS[$status] . ' – ' . site('name'), $text);
    }
}
function report_image(array $r): never
{
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
    if (preg_match('~^/(\d+)(/snimek)?$~', $rest, $m)) {
        $r = rq('SELECT * FROM reports WHERE id = ?', [$m[1]])->fetch();
        if (!$r) {
            http_response_code(404);
            render(reports_central() ? 'platform/message' : 'error', ['title' => 'Hlášení nenalezeno', 'message' => 'Toto hlášení neexistuje.']);
            return;
        }
        if (!empty($m[2])) {
            report_image($r);
        }
        if ($method === 'POST') {
            report_admin_save($r, $by);
            if (function_exists('platform_log') && reports_central()) {
                platform_log('Vyřízení hlášení', 'č. ' . $r['id'] . ' · ' . (REPORT_STATUS[post('status')] ?? ''));
            }
            flash('Uloženo.' . (filter_var($r['user_email'], FILTER_VALIDATE_EMAIL) && $r['type'] !== 'auto' ? ' Autor hlášení uvidí stav a odpověď v části Moje hlášení.' : ''));
            redirect($base . '/' . $r['id']);
        }
        render('reports/admin_detail', ['title' => 'Hlášení č. ' . $r['id'], 'r' => $r, 'base' => $base]);
        return;
    }
    $filter = (string) ($_GET['stav'] ?? 'open');
    $where = match (true) {
        $filter === 'all' => '1=1',
        $filter === 'auto' => "type = 'auto'",
        isset(REPORT_STATUS[$filter]) => 'status = ' . reports_pdo()->quote($filter),
        default => "status IN ('new','in_progress','planned')",
    };
    render('reports/admin_list', ['title' => 'Hlášení', 'base' => $base, 'filter' => $filter,
        'rows' => rq("SELECT * FROM reports WHERE $where ORDER BY CASE status WHEN 'new' THEN 0 ELSE 1 END, updated_at DESC LIMIT 500")->fetchAll(),
        'counts' => array_column(rq('SELECT status, COUNT(*) AS n FROM reports GROUP BY status')->fetchAll(), 'n', 'status')]);
}
function report_page_url(string $path): string
{
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) . url($path);
}
