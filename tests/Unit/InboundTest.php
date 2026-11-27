<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Falešná schránka: zprávy v paměti, zaznamenává, co se s nimi stalo. */
final class FakeMailbox implements \InboundMailbox
{
    public array $finished = [];
    public array $purged = [];
    public bool $closed = false;

    public function __construct(public array $messages, public ?\Throwable $failFetch = null)
    {
    }
    public function unseen(int $max): array
    {
        return array_slice(array_values(array_diff(array_keys($this->messages), array_keys($this->finished))), 0, $max);
    }
    public function fetch(int $uid): string
    {
        if ($this->failFetch) {
            throw $this->failFetch;
        }
        return $this->messages[$uid];
    }
    public function finish(int $uid, string $folder): void
    {
        $this->finished[$uid] = $folder;
    }
    public function purge(string $folder, int $days): int
    {
        $this->purged[] = [$folder, $days];
        return 0;
    }
    public function close(): void
    {
        $this->closed = true;
    }
}

/** app/ext/inbound_lib.php – značky vláken, ověření odesílatele, připojení odpovědí k hlášením a závadám, běh nad schránkou. */
final class InboundTest extends TestCase
{
    private const CONFIG = ['host' => 'imap.test.invalid', 'port' => 993, 'secure' => 'ssl', 'user' => 'odpovedi@example.cz', 'password' => 'x', 'address' => 'odpovedi@example.cz'];
    private array $restore = [];
    private string $origIp = '';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['INBOUND_CONFIG'] = self::CONFIG;
        $GLOBALS['INBOUND_STAGING'] = false;
        @unlink(inbound_dir() . '/state.json');
        $this->origIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $_SERVER['REMOTE_ADDR'] = '10.9.' . random_int(0, 255) . '.' . random_int(1, 254);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['INBOUND_CONFIG'], $GLOBALS['INBOUND_STAGING']);
        foreach (array_reverse($this->restore) as $fn) {
            $fn();
        }
        $this->restore = [];
        $_POST = [];
        $_SERVER['REMOTE_ADDR'] = $this->origIp;
    }

    // ---------- Pomocné ----------

    private function decodedMails(): array
    {
        return array_map(fn($m) => ['to' => $m['to'], 'subject' => mb_decode_mimeheader($m['subject']), 'body' => quoted_printable_decode($m['body']), 'reply_to' => $m['reply_to'] ?? ''], $this->mails());
    }
    private function resident(): array
    {
        return one("SELECT * FROM users WHERE role = 'resident' AND active = 1 AND email <> '' ORDER BY id LIMIT 1");
    }
    private function admin(): array
    {
        return one("SELECT * FROM users WHERE role = 'admin' AND active = 1 AND email <> '' ORDER BY id LIMIT 1");
    }
    private function ticket(array $over = []): array
    {
        ticket_ready();
        $t = $over + ['user_id' => (int) $this->resident()['id'], 'subject' => 'Kape kohoutek ' . bin2hex(random_bytes(3)), 'status' => 'in_progress'];
        q('INSERT INTO tickets (user_id, subject, location, body, status) VALUES (?,?,?,?,?)', [$t['user_id'], $t['subject'], 'sklep', 'Popis', $t['status']]);
        return one('SELECT * FROM tickets WHERE id = ?', [(int) db()->lastInsertId()]);
    }
    private function report(array $over = []): array
    {
        $u = $this->resident();
        $r = $over + ['tenant' => 'dum', 'user_id' => (int) $u['id'], 'user_name' => $u['name'], 'user_email' => $u['email'], 'type' => 'bug', 'status' => 'in_progress', 'body' => 'Nejde tisk ' . bin2hex(random_bytes(3))];
        rq('INSERT INTO reports (tenant, user_id, user_name, user_email, user_role, type, body, status, created_at, updated_at, ip) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$r['tenant'], $r['user_id'], $r['user_name'], $r['user_email'], 'vlastník', $r['type'], $r['body'], $r['status'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $_SERVER['REMOTE_ADDR']]);
        return rq('SELECT * FROM reports WHERE id = ?', [(int) reports_pdo()->lastInsertId()])->fetch();
    }
    private function eml(string $from, string $subject, string $body, array $headers = []): string
    {
        $h = ['From' => $from, 'To' => 'odpovedi@example.cz', 'Subject' => $subject, 'Message-ID' => '<' . bin2hex(random_bytes(8)) . '@test>', 'Content-Type' => 'text/plain; charset=utf-8'] + $headers;
        $out = '';
        foreach ($h as $k => $v) {
            $out .= "$k: $v\r\n";
        }
        return $out . "\r\n" . str_replace("\n", "\r\n", $body);
    }
    private function fixture(string $name, string $from, string $token): string
    {
        return strtr((string) file_get_contents(__DIR__ . '/fixtures/inbound/' . $name), ['{FROM}' => $from, '{TOKEN}' => $token, '{MID}' => bin2hex(random_bytes(6))]);
    }
    private function pset(string $key, string $value): void
    {
        $had = pq('SELECT value FROM platform_settings WHERE key = ?', [$key])->fetchColumn();
        pset_save($key, $value);
        $this->restore[] = function () use ($key, $had) {
            if ($had === false) {
                pq('DELETE FROM platform_settings WHERE key = ?', [$key]);
                pset('__reset');
            } else {
                pset_save($key, (string) $had);
            }
        };
    }
    private function tokenFromMail(array $m): string
    {
        $found = inbound_tokens_in($m['subject']);
        $this->assertCount(1, $found, 'značka v předmětu: ' . $m['subject']);
        return $found[0]['token'];
    }

    // ---------- Značka vlákna ----------

    public function testTokenSignAndVerify(): void
    {
        $tok = inbound_token('Z', 'dum', 45, 'Petr@Example.cz');
        $this->assertMatchesRegularExpression('/^Z45\.dum\.[0-9a-f]{16}$/', $tok);
        [$t] = inbound_tokens_in('Re: Kohoutek [#' . $tok . ']');
        $this->assertSame(['kind' => 'Z', 'id' => 45, 'tenant' => 'dum'], array_intersect_key($t, ['kind' => 1, 'id' => 1, 'tenant' => 1]));
        $this->assertTrue(inbound_token_valid($t, 'petr@example.cz'), 'adresa bez ohledu na velikost písmen');
        $this->assertFalse(inbound_token_valid($t, 'jiny@example.cz'), 'jiný příjemce');
        $this->assertFalse(inbound_token_valid(['id' => 46] + $t, 'petr@example.cz'), 'jiné vlákno');
        $this->assertFalse(inbound_token_valid(['tenant' => 'jiny-dum'] + $t, 'petr@example.cz'), 'jiný dům');
        $this->assertFalse(inbound_token_valid(['kind' => 'V'] + $t, 'petr@example.cz'), 'jiný druh');
        $this->assertFalse(inbound_token_valid(['mac' => substr($t['mac'], 0, 15) . ($t['mac'][15] === '0' ? '1' : '0')] + $t, 'petr@example.cz'), 'pozměněný podpis');
        $this->assertFalse(inbound_token_valid(['kind' => 'X'] + $t, 'petr@example.cz'));
        // Hlášení provozovateli nemá dům; značka se najde i v adrese odpovedi+…@ a ve velkých písmenech.
        $h = inbound_token('H', '', 7, 'a@b.cz');
        $this->assertMatchesRegularExpression('/^H7\.[0-9a-f]{16}$/', $h);
        $found = inbound_tokens_in('odpovedi+' . strtolower($h) . '@example.cz ' . strtoupper($h));
        $this->assertCount(1, $found, 'stejná značka dvakrát = jeden kandidát');
        $this->assertTrue(inbound_token_valid($found[0], 'a@b.cz'));
        $this->assertSame([], inbound_tokens_in('Z45.dum.0123 a #12345'));
    }

    public function testReplyPartsAndPlusAddressing(): void
    {
        $p = inbound_reply('Z', 'dum', 3, 'petr@example.cz');
        $this->assertSame('odpovedi@example.cz', $p['reply_to']);
        $this->assertSame('[#' . $p['token'] . ']', $p['tag']);
        $this->assertStringContainsString('#' . $p['token'], $p['line']);
        $this->assertNull(inbound_reply('Z', 'dum', 3, 'neplatna-adresa'));
        $this->assertNull(inbound_reply('X', 'dum', 3, 'petr@example.cz'));
        $GLOBALS['INBOUND_CONFIG'] = self::CONFIG + ['plus' => true];
        $this->assertSame('odpovedi+' . strtolower($p['token']) . '@example.cz', inbound_reply('Z', 'dum', 3, 'petr@example.cz')['reply_to']);
    }

    // ---------- Zapnutí, staging ----------

    public function testConfigOffAndStagingGuard(): void
    {
        unset($GLOBALS['INBOUND_CONFIG']);
        $this->assertFalse(inbound_on(), 'testovací config.php sekci inbound nemá');
        $this->assertStringContainsString('není nastavená', inbound_off_reason());
        $this->assertNull(inbound_reply('Z', 'dum', 1, 'a@b.cz'));
        $GLOBALS['INBOUND_CONFIG'] = ['password' => ''] + self::CONFIG;
        $this->assertStringContainsString('password', inbound_off_reason());
        $GLOBALS['INBOUND_CONFIG'] = ['address' => 'x'] + self::CONFIG;
        $this->assertStringContainsString('address', inbound_off_reason());
        $GLOBALS['INBOUND_CONFIG'] = self::CONFIG + ['enabled' => false];
        $this->assertFalse(inbound_on());
        // Zkušební prostředí: bez výslovného staging_ok vypnuto, s ním zapnuto.
        $GLOBALS['INBOUND_STAGING'] = true;
        $GLOBALS['INBOUND_CONFIG'] = self::CONFIG;
        $this->assertFalse(inbound_on());
        $this->assertStringContainsString('staging_ok', inbound_off_reason());
        $box = new FakeMailbox([1 => 'x']);
        $r = inbound_run($box);
        $this->assertStringContainsString('staging_ok', $r['skipped']);
        $this->assertSame([], $box->finished, 'schránky se nedotkne');
        $GLOBALS['INBOUND_CONFIG'] = self::CONFIG + ['staging_ok' => true];
        $this->assertTrue(inbound_on());
        $GLOBALS['INBOUND_STAGING'] = false;
        $this->assertTrue(inbound_on());
    }

    public function testMailsUnchangedWhenOff(): void
    {
        unset($GLOBALS['INBOUND_CONFIG']);
        $this->pset('contact_email', 'podpora@example.cz');
        $r = $this->report();
        report_update($r, 'in_progress', 'Díváme se na to.', '', 'Podpora');
        $t = $this->ticket();
        ticket_notify_author($t, 'Technik přijde zítra.');
        ticket_notify_admins($t, 'Odpověď obyvatele', 'text');
        $mails = $this->decodedMails();
        $this->assertNotEmpty($mails);
        foreach ($mails as $m) {
            $this->assertSame([], inbound_tokens_in($m['subject'] . $m['body']), 'bez značky');
            $this->assertNotSame('odpovedi@example.cz', $m['reply_to']);
        }
        $rep = array_values(array_filter($mails, fn($m) => str_starts_with($m['subject'], 'Vaše hlášení')))[0];
        $this->assertSame('Vaše hlášení: Řeší se', $rep['subject']);
        $this->assertStringContainsString('Odpověď na tento e-mail se k hlášení nepřipojí.', $rep['body']);
        $this->assertSame('podpora@example.cz', $rep['reply_to']);
        $res = array_values(array_filter($mails, fn($m) => str_starts_with($m['subject'], 'Odpověď na Vaše hlášení')))[0];
        $this->assertStringContainsString("Odpovědět můžete na webu: ", $res['body']);
        $this->assertStringNotContainsString('přímo na tento e-mail', $res['body']);
    }

    // ---------- Hlášení provozovateli ----------

    public function testReportReplyByEmailIsAddedToThread(): void
    {
        $this->pset('contact_email', 'podpora@example.cz');
        $r = $this->report(['status' => 'done']);
        report_update($r, 'done', 'Opraveno, zkuste to prosím znovu.', '', 'Podpora');
        $mail = array_values(array_filter($this->decodedMails(), fn($m) => $m['to'] === $r['user_email']))[0];
        $this->assertSame('odpovedi@example.cz', $mail['reply_to']);
        $this->assertStringContainsString('Odpovědět nebo něco doplnit můžete přímo odpovědí na tento e-mail, nebo u hlášení na webu', $mail['body']);
        $this->assertStringNotContainsString('nepřipojí', $mail['body']);
        $token = $this->tokenFromMail($mail);
        $this->assertStringContainsString('#' . $token, $mail['body'], 'značka i v patičce');
        $before = count($this->mails());

        $res = inbound_handle($this->fixture('gmail-plain-qp.eml', strtoupper($r['user_email']), $token));
        $this->assertSame('attached', $res['status'], $res['reason']);
        $msgs = report_messages((int) $r['id']);
        $last = end($msgs);
        $this->assertSame('user', $last['author']);
        $this->assertSame($r['user_name'], $last['by_name']);
        $this->assertStringStartsWith("Dobrý den,\n\nkapající kohoutek ve sklepě", $last['body']);
        $this->assertStringNotContainsString('Značka vlákna', $last['body']);
        $this->assertSame('new', rq('SELECT status FROM reports WHERE id = ?', [$r['id']])->fetchColumn(), 'jako odpověď z webu: hlášení se vrátí mezi nová');
        $new = array_slice($this->decodedMails(), $before);
        $this->assertCount(1, $new, 'provozovatel dostane upozornění, odesílatel nic');
        $this->assertSame('podpora@example.cz', $new[0]['to']);
        $this->assertSame('Doplnění k hlášení č. ' . $r['id'], $new[0]['subject']);
        $this->assertSame(1, (int) inbound_pdo()->query("SELECT COUNT(*) FROM inbound_log WHERE status = 'attached' AND kind = 'H' AND thread_id = " . (int) $r['id'])->fetchColumn());
    }

    public function testReportReplyStoresImageAsScreenshotAndNotesOtherAttachments(): void
    {
        $r = $this->report();
        $token = inbound_token('H', '', (int) $r['id'], $r['user_email']);
        $res = inbound_handle($this->fixture('multipart-attachments.eml', $r['user_email'], $token));
        $this->assertSame('attached', $res['status'], $res['reason']);
        $msgs = report_messages((int) $r['id']);
        $last = end($msgs);
        $this->assertSame("Posílám fotku kohoutku a protokol.\n\n(příloha z e-mailu nebyla uložena)", $last['body'], 'PDF se k hlášení neukládá, logo v podpisu se nepočítá');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $last['screenshot']);
        $this->assertFileExists(reports_dir() . '/' . $last['screenshot']);
    }

    public function testReportReplyRejectedForAnonymousReportOfSomeoneElseAndAutoReports(): void
    {
        $r = $this->report();
        $token = inbound_token('H', '', (int) $r['id'], $r['user_email']);
        // Cizí odesílatel se značkou příjemce.
        $res = inbound_handle($this->eml('utocnik@example.org', 'Re: [#' . $token . ']', 'Podvržená odpověď'));
        $this->assertSame('rejected', $res['status']);
        $this->assertStringContainsString('nesedí na odesílatele', $res['reason']);
        // Automaticky zachycená chyba webu nemá autora, kterému by šlo odpovídat.
        $auto = $this->report(['type' => 'auto', 'user_email' => 'x@example.cz']);
        $res = inbound_handle($this->eml('x@example.cz', '[#' . inbound_token('H', '', (int) $auto['id'], 'x@example.cz') . ']', 'text'));
        $this->assertSame('rejected', $res['status']);
        $this->assertCount(0, report_messages((int) $r['id']));
        $this->assertCount(0, report_messages((int) $auto['id']));
        // Hlášení bez účtu (z formuláře e-mailem): autor podle adresy může odpovědět.
        $anon = $this->report(['user_id' => 0, 'user_email' => 'anonym@example.cz', 'user_name' => '']);
        $res = inbound_handle($this->eml('anonym@example.cz', 'Re: [#' . inbound_token('H', '', (int) $anon['id'], 'anonym@example.cz') . ']', 'Doplňuji: stalo se to v Safari.'));
        $this->assertSame('attached', $res['status'], $res['reason']);
        $this->assertSame('Doplňuji: stalo se to v Safari.', report_messages((int) $anon['id'])[0]['body']);
    }

    // ---------- Závady ----------

    public function testResidentReplyToCommitteeMessageReopensAndNotifies(): void
    {
        $u = $this->resident();
        $t = $this->ticket(['status' => 'done']);
        ticket_admin_reply($t, $this->admin(), 'Technik závadu opravil.');
        $mail = array_values(array_filter($this->decodedMails(), fn($m) => $m['to'] === $u['email'] && str_contains($m['subject'], $t['subject'])))[0];
        $this->assertSame('odpovedi@example.cz', $mail['reply_to']);
        $this->assertStringContainsString('Odpovědět můžete přímo na tento e-mail, nebo na webu: ', $mail['body']);
        $token = $this->tokenFromMail($mail);
        $this->assertStringStartsWith('Z' . $t['id'] . '.dum.', $token);
        $before = count($this->mails());

        $res = inbound_handle($this->fixture('multipart-attachments.eml', $u['email'], $token));
        $this->assertSame('attached', $res['status'], $res['reason']);
        $log = ticket_log([(int) $t['id']])[(int) $t['id']];
        $kinds = array_column($log, 'kind');
        $this->assertSame(['msg_admin', 'status', 'msg_author'], $kinds);
        $this->assertSame('Řeší se (znovu otevřeno odpovědí obyvatele)', $log[1]['text']);
        $this->assertSame((int) $u['id'], (int) $log[2]['user_id']);
        $this->assertSame($u['name'], $log[2]['user_name']);
        $this->assertSame("Posílám fotku kohoutku a protokol.\n\nPřílohy z e-mailu: kohoutek.png, protokol č. 1.pdf", $log[2]['text']);
        $this->assertSame('in_progress', val('SELECT status FROM tickets WHERE id = ?', [$t['id']]));
        $files = ticket_files([(int) $t['id']])[(int) $t['id']];
        $this->assertSame(['kohoutek.png', 'protokol č. 1.pdf'], array_column($files, 'original_name'), 'logo z podpisu se neukládá');
        foreach ($files as $f) {
            $this->assertFileExists(UPLOAD_DIR . '/' . $f['filename']);
        }
        $new = array_slice($this->decodedMails(), $before);
        $this->assertNotEmpty($new);
        foreach ($new as $m) {
            $this->assertNotSame($u['email'], $m['to'], 'odesílateli se nic neposílá');
            $this->assertStringStartsWith('Odpověď obyvatele: ' . $t['subject'], $m['subject']);
            $this->assertStringContainsString('Posílám fotku kohoutku', $m['body']);
        }
        $this->assertContains($this->admin()['email'], array_column($new, 'to'));
    }

    public function testCommitteeReplyByEmailBecomesMessageToResident(): void
    {
        $admin = $this->admin();
        $u = $this->resident();
        $t = $this->ticket(['assignee_id' => 0]);
        ticket_notify_admins($t, 'Nové hlášení', $u['name'] . ":\nKape kohoutek");
        $mail = array_values(array_filter($this->decodedMails(), fn($m) => $m['to'] === $admin['email'] && str_contains($m['subject'], $t['subject'])))[0];
        $this->assertStringContainsString('Odpovědět obyvateli můžete i přímo odpovědí na tento e-mail.', $mail['body']);
        $token = $this->tokenFromMail($mail);
        $this->assertStringStartsWith('V' . $t['id'] . '.dum.', $token);
        $before = count($this->mails());

        $res = inbound_handle($this->fixture('outlook-cp1250-qp.eml', $admin['email'], $token));
        $this->assertSame('attached', $res['status'], $res['reason']);
        $log = ticket_log([(int) $t['id']])[(int) $t['id']];
        $this->assertSame('msg_admin', end($log)['kind']);
        $this->assertSame($admin['name'], end($log)['user_name']);
        $this->assertStringContainsString('ventil v předsíni je opravený', end($log)['text']);
        $new = array_slice($this->decodedMails(), $before);
        $this->assertSame([$u['email']], array_column($new, 'to'), 'obyvatel dostane zprávu (s vlastní značkou pro odpověď)');
        $this->assertStringStartsWith('Z' . $t['id'] . '.dum.', $this->tokenFromMail($new[0]));

        // Značka pro správce použitá obyvatelem (podepsaná na jeho adresu) neprojde – není správce domu.
        $res = inbound_handle($this->eml($u['email'], '[#' . inbound_token('V', 'dum', (int) $t['id'], $u['email']) . ']', 'Já nejsem výbor'));
        $this->assertSame('rejected', $res['status']);
        $this->assertStringContainsString('správcem', $res['reason']);
    }

    public function testRejectionsLeaveThreadsUntouchedAndSendNothing(): void
    {
        $u = $this->resident();
        $t = $this->ticket();
        $token = inbound_token('Z', 'dum', (int) $t['id'], $u['email']);
        $before = count($this->mails());
        $cases = [
            'neznámý odesílatel' => [$this->eml('cizi@example.org', 'Re: [#' . $token . ']', 'Ahoj'), 'rejected', 'nesedí'],
            'pozměněná značka' => [$this->eml($u['email'], 'Re: [#' . substr($token, 0, -1) . (str_ends_with($token, 'a') ? 'b' : 'a') . ']', 'Ahoj'), 'rejected', 'nesedí'],
            'jiné vlákno' => [$this->eml($u['email'], 'Re: [#Z' . ((int) $t['id'] + 1000) . '.dum.' . substr($token, -16) . ']', 'Ahoj'), 'rejected', 'nesedí'],
            'bez značky' => [$this->eml($u['email'], 'Re: Kohoutek', 'Ahoj'), 'rejected', 'bez značky'],
            'automatická odpověď' => [$this->fixture('autoreply.eml', $u['email'], $token), 'ignored', 'Auto-Submitted'],
            'nedoručenka' => [$this->fixture('bounce.eml', $u['email'], $token), 'ignored', ''],
            'Precedence: bulk' => [$this->eml($u['email'], 'Re: [#' . $token . ']', 'x', ['Precedence' => 'bulk']), 'ignored', 'Precedence'],
            'X-Autoreply' => [$this->eml($u['email'], 'Re: [#' . $token . ']', 'x', ['X-Autoreply' => 'yes']), 'ignored', 'x-autoreply'],
            'prázdná odpověď' => [$this->eml($u['email'], 'Re: [#' . $token . ']', "\n> jen citace\n"), 'rejected', 'prázdná'],
            'DMARC' => [$this->eml($u['email'], 'Re: [#' . $token . ']', 'x', ['Authentication-Results' => 'mx.example.cz; dmarc=fail (p=none) header.from=example.cz']), 'rejected', 'DMARC'],
        ];
        foreach ($cases as $label => [$raw, $status, $reason]) {
            $res = inbound_handle($raw);
            $this->assertSame($status, $res['status'], $label . ': ' . $res['reason']);
            $this->assertFalse($res['ok'], $label);
            $this->assertStringContainsString($reason, $res['reason'], $label);
        }
        $this->assertArrayNotHasKey((int) $t['id'], ticket_log([(int) $t['id']]), 'do vlákna nic nepřibylo');
        $this->assertSame([], array_slice($this->mails(), $before), 'nikomu se nic neposlalo');
    }

    public function testInactiveUserIsRejected(): void
    {
        $u = $this->resident();
        $t = $this->ticket();
        $token = inbound_token('Z', 'dum', (int) $t['id'], $u['email']);
        q('UPDATE users SET active = 0 WHERE id = ?', [$u['id']]);
        try {
            $res = inbound_handle($this->eml($u['email'], 'Re: [#' . $token . ']', 'Odpověď po deaktivaci'));
        } finally {
            q('UPDATE users SET active = 1 WHERE id = ?', [$u['id']]);
        }
        $this->assertSame('rejected', $res['status']);
        $this->assertStringContainsString('aktivním', $res['reason']);
        $this->assertArrayNotHasKey((int) $t['id'], ticket_log([(int) $t['id']]));
        // Hlášení provozovateli: účet autora na webu domu musí být aktivní.
        $r = $this->report();
        q('UPDATE users SET active = 0 WHERE id = ?', [$r['user_id']]);
        try {
            $res = inbound_handle($this->eml($r['user_email'], '[#' . inbound_token('H', '', (int) $r['id'], $r['user_email']) . ']', 'Text'));
        } finally {
            q('UPDATE users SET active = 1 WHERE id = ?', [$r['user_id']]);
        }
        $this->assertSame('rejected', $res['status']);
        $this->assertSame('účet autora není aktivní', $res['reason']);
    }

    public function testDuplicateMessageIsNotAttachedTwice(): void
    {
        $u = $this->resident();
        $t = $this->ticket();
        $raw = $this->eml($u['email'], 'Re: [#' . inbound_token('Z', 'dum', (int) $t['id'], $u['email']) . ']', 'Jen jednou', ['Message-ID' => '<dup-' . $t['id'] . '@test>']);
        $this->assertSame('attached', inbound_handle($raw)['status']);
        $this->assertSame('duplicate', inbound_handle($raw)['status']);
        $this->assertCount(1, array_filter(ticket_log([(int) $t['id']])[(int) $t['id']], fn($l) => $l['kind'] === 'msg_author'));
    }

    public function testLongReplyIsTruncatedAndTokenLineRemoved(): void
    {
        $u = $this->resident();
        $t = $this->ticket();
        $token = inbound_token('Z', 'dum', (int) $t['id'], $u['email']);
        $res = inbound_handle($this->eml($u['email'], 'Re: [#' . $token . ']', str_repeat('Dlouhý text. ', 600) . "\nZnačka vlákna (při odpovědi ji prosím ponechte): #" . $token));
        $this->assertSame('attached', $res['status']);
        $text = end(ticket_log([(int) $t['id']])[(int) $t['id']])['text'];
        $this->assertLessThanOrEqual(5000, mb_strlen($text));
        $this->assertStringEndsWith('… (zkráceno)', $text);
        $this->assertStringNotContainsString($token, $text);
    }

    /** Běh v konzoli platformy (jiný dům, než v jehož kontextu běží): odpověď ve frontě, dům ji převezme. */
    public function testQueueForHouseIsAppliedInHouseContext(): void
    {
        $u = $this->resident();
        $t = $this->ticket();
        $raw = $this->fixture('apple-base64.eml', $u['email'], inbound_token('Z', 'dum', (int) $t['id'], $u['email']));
        $route = inbound_route($raw);
        $this->assertTrue($route['ok'], $route['reason']);
        $logId = inbound_log_add(['status' => 'queued'] + $route);
        inbound_queue($route, $logId);
        $this->assertFileExists(inbound_marker('dum'));
        $this->assertSame(1, inbound_apply_pending());
        $this->assertFileDoesNotExist(inbound_marker('dum'), 'fronta je prázdná');
        $this->assertSame('Dobrý den, děkuji, zítra budu doma od 8 do 12 hodin.', end(ticket_log([(int) $t['id']])[(int) $t['id']])['text']);
        $this->assertSame('attached', inbound_pdo()->query('SELECT status FROM inbound_log WHERE id = ' . $logId)->fetchColumn());
        $this->assertSame(0, (int) inbound_pdo()->query("SELECT COUNT(*) FROM inbound_pending WHERE tenant = 'dum'")->fetchColumn());
        $this->assertSame(0, inbound_apply_pending(), 'nic dalšího');
    }

    // ---------- Běh nad schránkou ----------

    public function testRunProcessesMovesAndPurges(): void
    {
        $u = $this->resident();
        $t = $this->ticket();
        $token = inbound_token('Z', 'dum', (int) $t['id'], $u['email']);
        $box = new FakeMailbox([
            11 => $this->fixture('seznam.eml', $u['email'], $token),
            12 => $this->fixture('autoreply.eml', $u['email'], $token),
            13 => $this->eml('nekdo@example.org', 'Dotaz', 'Dobrý den, prodáte byt?'),
        ]);
        $r = inbound_run($box);
        $this->assertSame(3, $r['checked']);
        $this->assertSame([1, 1, 1, ''], [$r['attached'], $r['ignored'], $r['rejected'], $r['error']]);
        $this->assertSame([11 => 'Zpracovano', 12 => 'Nezpracovano', 13 => 'Nezpracovano'], $box->finished);
        $this->assertSame([['Zpracovano', 30], ['Nezpracovano', 30]], $box->purged);
        $this->assertTrue($box->closed);
        $this->assertSame('Ano, souhlasím.', end(ticket_log([(int) $t['id']])[(int) $t['id']])['text']);
        $this->assertStringContainsString('připojeno 1, odmítnuto 1, automatických 1', inbound_run_summary($r));
        $st = inbound_status();
        $this->assertTrue($st['on']);
        $this->assertNotSame('', $st['last_check']);
        $this->assertGreaterThanOrEqual(1, $st['counts']['attached']);
        $this->assertSame('nekdo@example.org', $st['recent'][0]['sender']);

        // Úklid jen jednou denně; keep_days = 0 zpracované zprávy maže hned.
        $GLOBALS['INBOUND_CONFIG'] = self::CONFIG + ['keep_days' => 0];
        $box2 = new FakeMailbox([21 => $this->eml($u['email'], 'Re: [#' . $token . ']', 'Druhá odpověď')]);
        inbound_run($box2);
        $this->assertSame([21 => ''], $box2->finished);
        $this->assertSame([], $box2->purged);
    }

    public function testRunLimitsMessagesAndRecordsMailboxErrors(): void
    {
        $GLOBALS['INBOUND_CONFIG'] = self::CONFIG + ['max_per_run' => 2];
        $box = new FakeMailbox([1 => $this->eml('a@example.org', 'x', 'y'), 2 => $this->eml('a@example.org', 'x', 'y'), 3 => $this->eml('a@example.org', 'x', 'y')]);
        $this->assertSame(2, inbound_run($box)['checked']);
        $this->assertCount(2, $box->finished);

        $bad = new FakeMailbox([1 => 'x'], new \RuntimeException('Přihlášení ke schránce se nepovedlo'));
        $r = inbound_run($bad);
        $this->assertSame('Přihlášení ke schránce se nepovedlo', $r['error']);
        $this->assertTrue($bad->closed);
        $st = inbound_status();
        $this->assertSame('Přihlášení ke schránce se nepovedlo', $st['last_error']);
        $this->assertStringContainsString('nepodařilo zkontrolovat', inbound_run_summary($r));

        // Souběh: druhý běh se zámkem nezačne.
        $lock = fopen(inbound_dir() . '/run.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->assertStringContainsString('už právě běží', inbound_run(new FakeMailbox([]))['skipped']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testStoreFileDataChecksTypeAndContent(): void
    {
        $this->assertSame('Nepovolený typ souboru. Povolené jsou: ' . implode(', ', ALLOWED_EXT) . '.', store_file_data('virus.exe', 'MZ...'));
        $this->assertStringContainsString('neodpovídá jeho příponě', store_file_data('foto.jpg', '<?php echo 1;'));
        $ok = store_file_data('../../etc/plan.pdf', "%PDF-1.4\n%%EOF\n");
        $this->assertIsArray($ok);
        $this->assertSame('plan.pdf', $ok[1]);
        $this->assertFileExists(UPLOAD_DIR . '/' . $ok[0]);
        @unlink(UPLOAD_DIR . '/' . $ok[0]);
        $this->assertSame([], glob(UPLOAD_DIR . '/.tmp-*'), 'dočasné soubory se uklidí');
    }
}
