<?php
declare(strict_types=1);

/*
 * Fronta e-mailů kvůli limitu hostingu (Český hosting: nejvýš 1 000 zpráv za hodinu na doménu, doporučeno ~200).
 * Limit sdílí všechny weby na jedné doméně, proto je fronta jedna pro celou službu: soubor storage/mailq.sqlite.
 *
 * - Běžné zprávy (heslo, pozvánka, odpověď na hlášení) odcházejí hned, dokud není dosažen celkový hodinový limit.
 * - Hromadné zprávy (rozeslání obyvatelům, upozornění na spotřebu, upomínky) odcházejí hned jen do nižšího limitu
 *   pro hromadné zprávy; zbytek čeká ve frontě a odchází postupně (při návštěvách webu, případně z cronu).
 * Obsah zprávy je ve frontě jen do odeslání; pak se smaže a zůstane jen čas (kvůli počítání limitu).
 */

function mailq_limits(): array
{
    $c = (array) (app_config()['mail'] ?? []);
    $hour = max(1, (int) ($c['per_hour'] ?? 800));
    return ['hour' => $hour, 'bulk' => max(1, min($hour, (int) ($c['bulk_per_hour'] ?? 200)))];
}
function mailq_enabled(): bool
{
    return (bool) ((app_config()['mail']['queue'] ?? true));
}
function mailq_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . ROOT_STORAGE . '/mailq.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 10]);
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS mailq (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant TEXT NOT NULL DEFAULT \'\', bulk INTEGER NOT NULL DEFAULT 0,
            created TEXT NOT NULL, sent_at TEXT, failed INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0, error TEXT, msg TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS mailq_sent ON mailq (sent_at)');
    }
    return $pdo;
}
function mailq_marker(): string
{
    return ROOT_STORAGE . '/mailq.pending';
}
/** Počet zpráv odeslaných za poslední hodinu (všechny weby dohromady). */
function mailq_sent_hour(): int
{
    return (int) mailq_pdo()->query("SELECT COUNT(*) FROM mailq WHERE sent_at >= '" . date('Y-m-d H:i:s', time() - 3600) . "' AND failed = 0")->fetchColumn();
}
function mailq_pending(?int $bulk = null): int
{
    if (!is_file(mailq_marker())) {
        return 0;
    }
    return (int) mailq_pdo()->query('SELECT COUNT(*) FROM mailq WHERE sent_at IS NULL' . ($bulk === null ? '' : ' AND bulk = ' . $bulk))->fetchColumn();
}
/** Hromadné rozeslání: zprávy odeslané uvnitř $fn se počítají do limitu hromadných zpráv a případně čekají ve frontě. */
function mail_bulk(callable $fn): mixed
{
    $prev = $GLOBALS['MAIL_BULK'] ?? false;
    $GLOBALS['MAIL_BULK'] = true;
    try {
        return $fn();
    } finally {
        $GLOBALS['MAIL_BULK'] = $prev;
    }
}
/**
 * Rozhodne, zda zprávu poslat hned, nebo ji zařadit do fronty. $msg je hotová zpráva (viz mail_deliver).
 * Vrací true, když zprávu server převzal nebo když čeká ve frontě ($GLOBALS['MAIL_LAST'] === 'queued').
 */
function mailq_send(array $msg): bool
{
    if (!mailq_enabled()) {
        return mail_deliver($msg);
    }
    $bulk = !empty($GLOBALS['MAIL_BULK']);
    $lim = mailq_limits();
    try {
        $sent = mailq_sent_hour();
        // Hromadné zprávy předbíhat nesmí: když už nějaké čekají, nová jde na konec fronty.
        $now = $bulk ? ($sent < $lim['bulk'] && mailq_pending(1) === 0) : $sent < $lim['hour'];
        if (!$now) {
            mailq_pdo()->prepare('INSERT INTO mailq (tenant, bulk, created, msg) VALUES (?,?,?,?)')
                ->execute([defined('TENANT') ? TENANT : '', $bulk ? 1 : 0, date('Y-m-d H:i:s'), json_encode($msg, JSON_UNESCAPED_UNICODE)]);
            @touch(mailq_marker());
            $GLOBALS['MAIL_LAST'] = 'queued';
            $GLOBALS['MAIL_QUEUED'] = ($GLOBALS['MAIL_QUEUED'] ?? 0) + 1;
            return true;
        }
    } catch (Throwable $e) {
        error_log('SVJ web, fronta e-mailů: ' . $e->getMessage());
        return mail_deliver($msg);   // fronta nedostupná – raději poslat hned než nic
    }
    $ok = mail_deliver($msg);
    if ($ok) {
        try {
            mailq_pdo()->prepare('INSERT INTO mailq (tenant, bulk, created, sent_at) VALUES (?,?,?,?)')
                ->execute([defined('TENANT') ? TENANT : '', $bulk ? 1 : 0, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            error_log('SVJ web, fronta e-mailů: ' . $e->getMessage());
        }
    }
    return $ok;
}
/**
 * Odešle čekající zprávy, kolik limit dovolí (nejvýš $max najednou). Spouští se po vyřízení požadavku,
 * jednou za minutu; jen jeden běh najednou (zámek). Vrací [odesláno, nepodařilo se, zbývá].
 */
function mailq_run(int $max = 25, bool $force = false): array
{
    if (!is_file(mailq_marker())) {
        return [0, 0, 0];
    }
    $tick = ROOT_STORAGE . '/mailq.tick';
    if (!$force && is_file($tick) && filemtime($tick) > time() - 60) {
        return [0, 0, mailq_pending()];
    }
    $lock = @fopen(ROOT_STORAGE . '/mailq.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return [0, 0, mailq_pending()];
    }
    @touch($tick);
    $done = $bad = 0;
    try {
        $pdo = mailq_pdo();
        $lim = mailq_limits();
        $rows = $pdo->query('SELECT id, bulk, attempts, msg FROM mailq WHERE sent_at IS NULL ORDER BY bulk, id LIMIT ' . (int) $max)->fetchAll();
        foreach ($rows as $r) {
            $sent = mailq_sent_hour();
            if ($sent >= ($r['bulk'] ? $lim['bulk'] : $lim['hour'])) {
                if ($r['bulk']) {
                    break;   // ostatní hromadné zprávy počkají na další hodinu
                }
                continue;
            }
            $msg = json_decode((string) $r['msg'], true);
            $ok = is_array($msg) && mail_deliver($msg);
            if ($ok) {
                $done++;
                $pdo->prepare('UPDATE mailq SET sent_at = ?, msg = NULL, error = NULL WHERE id = ?')->execute([date('Y-m-d H:i:s'), $r['id']]);
            } elseif ((int) $r['attempts'] >= 4) {
                $bad++;
                $pdo->prepare('UPDATE mailq SET sent_at = ?, failed = 1, msg = NULL, error = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), mb_substr((string) ($GLOBALS['MAIL_ERROR'] ?? 'odeslání se nepodařilo'), 0, 300), $r['id']]);
            } else {
                $pdo->prepare('UPDATE mailq SET attempts = attempts + 1, error = ? WHERE id = ?')->execute([mb_substr((string) ($GLOBALS['MAIL_ERROR'] ?? 'odeslání se nepodařilo'), 0, 300), $r['id']]);
            }
        }
        // Staré záznamy o odeslání nejsou potřeba (limit se počítá za poslední hodinu).
        $pdo->exec("DELETE FROM mailq WHERE sent_at IS NOT NULL AND sent_at < '" . date('Y-m-d H:i:s', time() - 3 * 86400) . "'");
        $left = (int) $pdo->query('SELECT COUNT(*) FROM mailq WHERE sent_at IS NULL')->fetchColumn();
        if ($left === 0) {
            @unlink(mailq_marker());
        }
    } catch (Throwable $e) {
        error_log('SVJ web, fronta e-mailů: ' . $e->getMessage());
        $left = -1;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return [$done, $bad, $left];
}
/** Věta pro správce po rozeslání: kolik zpráv čeká ve frontě a zhruba dokdy odejdou. Prázdné, když nic nečeká. */
function mailq_note(): string
{
    $q = (int) ($GLOBALS['MAIL_QUEUED'] ?? 0);
    if ($q === 0) {
        return '';
    }
    $hours = (int) ceil(mailq_pending(1) / max(1, mailq_limits()['bulk']));
    return ' ' . $q . ' z nich čeká ve frontě a odejde postupně' . ($hours > 1 ? ' během ' . $hours . ' hodin' : ' během hodiny') . ' – hosting dovoluje odeslat jen omezený počet e-mailů za hodinu.';
}
/** Přehled pro konzoli: [odesláno za hodinu, čeká, z toho hromadných, nejstarší čekající, limity]. */
function mailq_status(): array
{
    try {
        $oldest = is_file(mailq_marker()) ? (string) mailq_pdo()->query('SELECT MIN(created) FROM mailq WHERE sent_at IS NULL')->fetchColumn() : '';
        return ['sent' => mailq_sent_hour(), 'pending' => mailq_pending(), 'bulk' => mailq_pending(1), 'oldest' => $oldest, 'limits' => mailq_limits(),
            'failed' => (int) mailq_pdo()->query("SELECT COUNT(*) FROM mailq WHERE failed = 1 AND sent_at >= '" . date('Y-m-d H:i:s', time() - 86400) . "'")->fetchColumn()];
    } catch (Throwable $e) {
        return ['sent' => 0, 'pending' => 0, 'bulk' => 0, 'oldest' => '', 'limits' => mailq_limits(), 'failed' => 0];
    }
}
