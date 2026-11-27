<?php
declare(strict_types=1);

/*
 * Příchozí e-maily: malý klient IMAP (PHP 8.4 nemá rozšíření imap). Umí jen to, co potřebuje app/ext/inbound_lib.php:
 * přihlášení (LOGIN přes SSL/TLS), výběr složky, nepřečtené zprávy (UID SEARCH UNSEEN), stažení zprávy bez označení
 * jako přečtené (UID FETCH BODY.PEEK[]), označení přečtené, přesun do složky (UID MOVE, jinak COPY + smazání),
 * založení složky a smazání starých zpráv ze složky.
 *
 * Rozhraní InboundMailbox odděluje zpracování od skutečné schránky – testy používají falešnou schránku
 * a spojení ImapConnection lze nahradit skriptovaným (bez sítě).
 */

interface InboundMailbox
{
    /** UID nepřečtených zpráv ve sledované složce (nejstarší první), nejvýš $max. */
    public function unseen(int $max): array;
    /** Celá zpráva (RFC 822) podle UID; zpráva se tím neoznačí jako přečtená. */
    public function fetch(int $uid): string;
    /** Označí zprávu jako přečtenou a přesune ji do složky $folder; $folder = '' zprávu smaže. */
    public function finish(int $uid, string $folder): void;
    /** Smaže ze složky zprávy starší než $days dní; vrací jejich počet. */
    public function purge(string $folder, int $days): int;
    public function close(): void;
}

interface ImapConnection
{
    public function write(string $data): void;
    /** Jeden řádek včetně CRLF; false = spojení skončilo. */
    public function readLine(): string|false;
    /** Přesně $n bajtů (literál {n}). */
    public function read(int $n): string;
    public function startTls(): bool;
    public function close(): void;
}

final class ImapStreamConnection implements ImapConnection
{
    /** @var resource */
    private $fp;

    public function __construct(string $host, int $port, string $secure, int $timeout = 20)
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException('Nepodařilo se připojit k ' . $host . ':' . $port . ' (' . ($errstr ?: 'neznámá chyba') . ').');
        }
        stream_set_timeout($fp, $timeout);
        $this->fp = $fp;
    }
    public function write(string $data): void
    {
        if (@fwrite($this->fp, $data) === false) {
            throw new RuntimeException('Spojení se schránkou se přerušilo.');
        }
    }
    public function readLine(): string|false
    {
        $l = fgets($this->fp, 65536);
        if ($l === false && (stream_get_meta_data($this->fp)['timed_out'] ?? false)) {
            throw new RuntimeException('Schránka neodpověděla včas.');
        }
        return $l;
    }
    public function read(int $n): string
    {
        $out = '';
        while (strlen($out) < $n) {
            $chunk = fread($this->fp, min(65536, $n - strlen($out)));
            if ($chunk === false || $chunk === '') {
                if (feof($this->fp) || (stream_get_meta_data($this->fp)['timed_out'] ?? false)) {
                    throw new RuntimeException('Zprávu se nepodařilo celou stáhnout.');
                }
                continue;
            }
            $out .= $chunk;
        }
        return $out;
    }
    public function startTls(): bool
    {
        return (bool) @stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
    }
    public function close(): void
    {
        @fclose($this->fp);
    }
}

final class ImapMailbox implements InboundMailbox
{
    private int $tag = 0;
    private array $caps = [];
    private ?array $folders = null;
    private string $delim = '.';
    private array $resolved = [];

    /** $c = ['user', 'password', 'folder', 'secure'] (secure 'tls' = STARTTLS po připojení). */
    public function __construct(private ImapConnection $conn, private array $c)
    {
        $greeting = (string) $this->conn->readLine();
        if (!preg_match('/^\* (OK|PREAUTH)/i', $greeting)) {
            throw new RuntimeException('Server neodpověděl jako IMAP: ' . mb_substr(trim($greeting), 0, 120));
        }
        if (($c['secure'] ?? 'ssl') === 'tls') {
            $this->command('STARTTLS');
            if (!$this->conn->startTls()) {
                throw new RuntimeException('Šifrované spojení (STARTTLS) se nepodařilo navázat.');
            }
        }
        $this->command('LOGIN ' . self::quote((string) $c['user']) . ' ' . self::quote((string) $c['password']), 'Přihlášení ke schránce se nepovedlo – zkontrolujte user a password v inbound v storage/config.php');
        $this->caps = $this->capabilities();
        $this->select($this->inbox());
    }

    /** Připojení podle konfigurace inbound z config.php. */
    public static function open(array $c): self
    {
        $secure = strtolower((string) ($c['secure'] ?? 'ssl'));
        $port = (int) ($c['port'] ?? ($secure === 'ssl' ? 993 : 143));
        return new self(new ImapStreamConnection((string) $c['host'], $port, $secure), $c + ['secure' => $secure]);
    }

    public static function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    /**
     * Pošle příkaz a přečte odpověď až po řádek se značkou. Vrací [netagované řádky, literály v pořadí].
     * Při odpovědi NO/BAD vyhodí výjimku (s $error, je-li zadán), u $soft vrátí null.
     */
    public function command(string $cmd, string $error = '', bool $soft = false): ?array
    {
        $tag = 'A' . (++$this->tag);
        $this->conn->write($tag . ' ' . $cmd . "\r\n");
        $lines = [];
        $literals = [];
        while (true) {
            $line = $this->conn->readLine();
            if ($line === false) {
                throw new RuntimeException('Schránka ukončila spojení.');
            }
            // Literál {n}: následuje přesně n bajtů dat, pak pokračování řádku.
            while (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $literals[] = $this->conn->read((int) $m[1]);
                $rest = $this->conn->readLine();
                $line = substr($line, 0, -strlen($m[0])) . '<literal ' . (count($literals) - 1) . '>' . ($rest === false ? '' : $rest);
            }
            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . $tag . ' OK/i', $line)) {
                    if ($soft) {
                        return null;
                    }
                    $why = trim(substr($line, strlen($tag) + 1));
                    throw new RuntimeException(($error !== '' ? $error . ': ' : 'Schránka odmítla příkaz ' . strtok($cmd, ' ') . ': ') . mb_substr($why, 0, 160));
                }
                return [$lines, $literals];
            }
            $lines[] = rtrim($line, "\r\n");
        }
    }

    private function capabilities(): array
    {
        [$lines] = $this->command('CAPABILITY');
        foreach ($lines as $l) {
            if (preg_match('/^\* CAPABILITY (.*)$/i', $l, $m)) {
                return array_map('strtoupper', preg_split('/\s+/', trim($m[1])));
            }
        }
        return [];
    }
    private function inbox(): string
    {
        return (string) ($this->c['folder'] ?? 'INBOX') ?: 'INBOX';
    }
    private function select(string $folder): void
    {
        $this->command('SELECT ' . self::quote($folder), 'Složku ' . $folder . ' nejde otevřít');
    }

    public function unseen(int $max): array
    {
        [$lines] = $this->command('UID SEARCH UNSEEN');
        $uids = [];
        foreach ($lines as $l) {
            if (preg_match('/^\* SEARCH\s*(.*)$/i', $l, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $u) {
                    if (ctype_digit($u)) {
                        $uids[] = (int) $u;
                    }
                }
            }
        }
        sort($uids);
        return array_slice($uids, 0, max(0, $max));
    }

    public function fetch(int $uid): string
    {
        [, $literals] = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[])');
        if (!$literals) {
            throw new RuntimeException('Zprávu ' . $uid . ' se nepodařilo stáhnout.');
        }
        return $literals[0];
    }

    public function finish(int $uid, string $folder): void
    {
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\\Seen)');
        if ($folder === '') {
            $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\\Deleted)');
            $this->expunge($uid);
            return;
        }
        $target = $this->folder($folder);
        if ($target === null) {
            return;   // složku nejde založit – zpráva zůstane přečtená ve schránce
        }
        if (in_array('MOVE', $this->caps, true)) {
            $this->command('UID MOVE ' . $uid . ' ' . self::quote($target));
            return;
        }
        $this->command('UID COPY ' . $uid . ' ' . self::quote($target));
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\\Deleted)');
        $this->expunge($uid);
    }
    private function expunge(int $uid): void
    {
        // S UIDPLUS jen tuto zprávu; bez něj EXPUNGE (smaže jen zprávy označené ke smazání – ty označuje jen tento program).
        in_array('UIDPLUS', $this->caps, true) ? $this->command('UID EXPUNGE ' . $uid) : $this->command('EXPUNGE');
    }

    /** Skutečný název složky na serveru (s předponou INBOX., pokud ji server vyžaduje); složku případně založí. */
    public function folder(string $name): ?string
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }
        if ($this->folders === null) {
            $this->folders = [];
            [$lines] = $this->command('LIST "" "*"', '', true) ?? [[]];
            foreach ($lines as $l) {
                if (preg_match('/^\* LIST \([^)]*\) (NIL|"((?:[^"\\\\]|\\\\.)*)") (.+)$/i', $l, $m)) {
                    $this->delim = $m[1] === 'NIL' ? $this->delim : stripcslashes($m[2]);
                    $f = trim($m[3]);
                    $this->folders[] = str_starts_with($f, '"') ? stripcslashes(substr($f, 1, -1)) : $f;
                }
            }
        }
        foreach ([$name, 'INBOX' . $this->delim . $name] as $cand) {
            foreach ($this->folders as $f) {
                if (strcasecmp($f, $cand) === 0) {
                    return $this->resolved[$name] = $f;
                }
            }
        }
        foreach ([$name, 'INBOX' . $this->delim . $name] as $cand) {
            if ($this->command('CREATE ' . self::quote($cand), '', true) !== null) {
                $this->command('SUBSCRIBE ' . self::quote($cand), '', true);
                $this->folders[] = $cand;
                return $this->resolved[$name] = $cand;
            }
        }
        return null;
    }

    public function purge(string $folder, int $days): int
    {
        $target = $this->folder($folder);
        if ($target === null) {
            return 0;
        }
        $this->select($target);
        try {
            $before = date('j-M-Y', time() - max(0, $days) * 86400);
            [$lines] = $this->command('UID SEARCH BEFORE ' . $before);
            $uids = [];
            foreach ($lines as $l) {
                if (preg_match('/^\* SEARCH\s*(.*)$/i', $l, $m)) {
                    $uids = array_merge($uids, array_filter(preg_split('/\s+/', trim($m[1])), 'ctype_digit'));
                }
            }
            if ($uids) {
                $this->command('UID STORE ' . implode(',', $uids) . ' +FLAGS.SILENT (\\Deleted)');
                $this->command('EXPUNGE');
            }
            return count($uids);
        } finally {
            $this->select($this->inbox());
        }
    }

    public function close(): void
    {
        try {
            $this->command('LOGOUT', '', true);
        } catch (Throwable $e) {
        }
        $this->conn->close();
    }
}
