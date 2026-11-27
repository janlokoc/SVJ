<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Jednoduchý server IMAP v paměti (bez sítě): odpovídá na příkazy, které klient posílá, a zapisuje je.
 * $caps: schopnosti serveru; $prefix: server vyžaduje složky pod INBOX. (např. Courier).
 */
final class ScriptedImap implements \ImapConnection
{
    public array $commands = [];
    public array $folders = ['INBOX'];
    public array $flags = [];
    public array $moved = [];
    private string $out = "* OK IMAP4rev1 ready\r\n";
    private string $selected = '';

    public function __construct(public array $messages, public array $caps = ['IMAP4rev1', 'MOVE', 'UIDPLUS'], public bool $prefix = false, public string $password = 'heslo "s" \\ znaky')
    {
    }
    public function write(string $data): void
    {
        $line = rtrim($data, "\r\n");
        $this->commands[] = $line;
        [$tag, $cmd] = explode(' ', $line, 2);
        $ok = fn(string $t = 'done') => $this->out .= "$tag OK $t\r\n";
        if (preg_match('/^LOGIN "(.*)" "(.*)"$/', $cmd, $m)) {
            stripcslashes($m[2]) === $this->password ? $ok() : $this->out .= "$tag NO [AUTHENTICATIONFAILED] Invalid credentials\r\n";
        } elseif ($cmd === 'CAPABILITY') {
            $this->out .= '* CAPABILITY ' . implode(' ', $this->caps) . "\r\n";
            $ok();
        } elseif (preg_match('/^SELECT "(.*)"$/', $cmd, $m)) {
            $this->selected = $m[1];
            in_array($m[1], $this->folders, true) ? $ok() : $this->out .= "$tag NO no such mailbox\r\n";
        } elseif ($cmd === 'UID SEARCH UNSEEN') {
            $this->out .= '* SEARCH ' . implode(' ', array_keys(array_filter($this->messages, fn($u) => !in_array('\\Seen', $this->flags[$u] ?? [], true), ARRAY_FILTER_USE_KEY))) . "\r\n";
            $ok();
        } elseif (preg_match('/^UID SEARCH BEFORE (\d+-\w{3}-\d{4})$/', $cmd)) {
            $this->out .= $this->selected === 'Zpracovano' || $this->selected === 'INBOX.Zpracovano' ? "* SEARCH 7 8\r\n" : "* SEARCH\r\n";
            $ok();
        } elseif (preg_match('/^UID FETCH (\d+) \(BODY\.PEEK\[\]\)$/', $cmd, $m)) {
            $raw = $this->messages[(int) $m[1]];
            $this->out .= '* 1 FETCH (UID ' . $m[1] . ' BODY[] {' . strlen($raw) . "}\r\n" . $raw . ")\r\n";
            $ok();
        } elseif (preg_match('/^UID STORE ([\d,]+) \+FLAGS\.SILENT \((.+)\)$/', $cmd, $m)) {
            foreach (explode(',', $m[1]) as $u) {
                $this->flags[(int) $u][] = $m[2];
            }
            $ok();
        } elseif (preg_match('/^UID (MOVE|COPY) (\d+) "(.*)"$/', $cmd, $m)) {
            if (($m[1] === 'MOVE' && !in_array('MOVE', $this->caps, true)) || !in_array($m[3], $this->folders, true)) {
                $this->out .= "$tag NO [TRYCREATE] no\r\n";
                return;
            }
            $this->moved[(int) $m[2]] = $m[3];
            $ok();
        } elseif (preg_match('/^LIST "" "\*"$/', $cmd)) {
            foreach ($this->folders as $f) {
                $this->out .= '* LIST (\\HasNoChildren) "." "' . $f . "\"\r\n";
            }
            $ok();
        } elseif (preg_match('/^CREATE "(.*)"$/', $cmd, $m)) {
            if ($this->prefix && !str_starts_with($m[1], 'INBOX.')) {
                $this->out .= "$tag NO Invalid mailbox name\r\n";
                return;
            }
            $this->folders[] = $m[1];
            $ok();
        } elseif (in_array($cmd, ['EXPUNGE', 'LOGOUT'], true) || str_starts_with($cmd, 'UID EXPUNGE') || str_starts_with($cmd, 'SUBSCRIBE')) {
            $cmd === 'LOGOUT' && $this->out .= "* BYE\r\n";
            $ok();
        } else {
            $this->out .= "$tag BAD unknown\r\n";
        }
    }
    public function readLine(): string|false
    {
        if ($this->out === '') {
            return false;
        }
        $p = strpos($this->out, "\n");
        $line = $p === false ? $this->out : substr($this->out, 0, $p + 1);
        $this->out = (string) substr($this->out, strlen($line));
        return $line;
    }
    public function read(int $n): string
    {
        $s = substr($this->out, 0, $n);
        $this->out = (string) substr($this->out, $n);
        return $s;
    }
    public function startTls(): bool
    {
        return true;
    }
    public function close(): void
    {
    }
}

/** app/ext/inbound_imap_lib.php – klient IMAP proti skriptovanému serveru (bez sítě). */
final class InboundImapTest extends TestCase
{
    private const MSG = "From: a@b.cz\r\nSubject: Ahoj\r\n\r\nPrvni radek\r\n{5}\r\nliteral uvnitr textu\r\n";

    private function box(ScriptedImap $s): \ImapMailbox
    {
        return new \ImapMailbox($s, ['user' => 'odpovedi@example.cz', 'password' => $s->password, 'folder' => 'INBOX', 'secure' => 'ssl']);
    }

    public function testLoginSearchFetchAndMove(): void
    {
        $s = new ScriptedImap([3 => self::MSG, 5 => 'x', 4 => 'y']);
        $box = $this->box($s);
        $this->assertSame('A1 LOGIN "odpovedi@example.cz" "heslo \\"s\\" \\\\ znaky"', $s->commands[0], 'uvozovky a zpětné lomítko v hesle se escapují');
        $this->assertSame([3, 4], $box->unseen(2), 'nejstarší první, nejvýš $max');
        $this->assertSame(self::MSG, $box->fetch(3), 'literál se přečte přesně (i text, který vypadá jako další literál)');
        $box->finish(3, 'Zpracovano');
        $this->assertContains('CREATE "Zpracovano"', array_map(fn($c) => substr($c, strpos($c, ' ') + 1), $s->commands), 'chybějící složka se založí');
        $this->assertSame([3 => 'Zpracovano'], $s->moved);
        $this->assertSame(['\\Seen'], $s->flags[3]);
        $this->assertSame([4, 5], $box->unseen(10));
        $box->finish(4, 'Zpracovano');
        $this->assertCount(1, array_filter($s->commands, fn($c) => str_contains($c, 'CREATE')), 'složka se zakládá jen jednou');
        $box->finish(5, '');
        $this->assertSame(['\\Seen', '\\Deleted'], $s->flags[5], 'smazání');
        $this->assertStringEndsWith('UID EXPUNGE 5', end($s->commands));
        $box->close();
        $this->assertStringEndsWith('LOGOUT', end($s->commands));
    }

    public function testWithoutMoveUsesCopyAndPrefixedFolders(): void
    {
        $s = new ScriptedImap([1 => 'x'], ['IMAP4rev1'], true);
        $box = $this->box($s);
        $box->finish(1, 'Nezpracovano');
        $this->assertContains('INBOX.Nezpracovano', $s->folders, 'server chce složky pod INBOX.');
        $this->assertSame([1 => 'INBOX.Nezpracovano'], $s->moved);
        $this->assertSame(['\\Seen', '\\Deleted'], $s->flags[1]);
        $this->assertStringEndsWith(' EXPUNGE', end($s->commands), 'bez UIDPLUS obyčejný EXPUNGE');
        $this->assertSame([], array_filter($s->commands, fn($c) => str_contains($c, 'MOVE')));
    }

    public function testPurgeDeletesOldMessagesAndReturnsToInbox(): void
    {
        $s = new ScriptedImap([], ['IMAP4rev1', 'MOVE']);
        $s->folders[] = 'Zpracovano';
        $box = $this->box($s);
        $this->assertSame(2, $box->purge('Zpracovano', 30));
        $this->assertSame(['\\Deleted'], $s->flags[7]);
        $tail = array_map(fn($c) => substr($c, strpos($c, ' ') + 1), array_slice($s->commands, -5));
        $this->assertSame('SELECT "Zpracovano"', $tail[0]);
        $this->assertMatchesRegularExpression('/^UID SEARCH BEFORE \d{1,2}-[A-Z][a-z]{2}-\d{4}$/', $tail[1]);
        $this->assertSame(['UID STORE 7,8 +FLAGS.SILENT (\\Deleted)', 'EXPUNGE', 'SELECT "INBOX"'], array_slice($tail, 2));
    }

    public function testErrors(): void
    {
        try {
            new \ImapMailbox(new ScriptedImap([], ['IMAP4rev1'], false, 'spravne'), ['user' => 'u', 'password' => 'spatne']);
            $this->fail('Špatné heslo musí skončit výjimkou.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Přihlášení ke schránce se nepovedlo', $e->getMessage());
            $this->assertStringContainsString('AUTHENTICATIONFAILED', $e->getMessage());
        }
        $s = new ScriptedImap([]);
        $this->box($s);
        $broken = new class implements \ImapConnection {
            public function write(string $data): void {}
            public function readLine(): string|false { return "HTTP/1.1 400 Bad Request\r\n"; }
            public function read(int $n): string { return ''; }
            public function startTls(): bool { return true; }
            public function close(): void {}
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('neodpověděl jako IMAP');
        new \ImapMailbox($broken, ['user' => 'u', 'password' => 'p']);
    }
}
