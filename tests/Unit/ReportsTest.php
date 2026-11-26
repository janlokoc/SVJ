<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/** app/reports_lib.php – hlášení problémů a nápadů (na platformě v databázi služby). */
final class ReportsTest extends TestCase
{
    private string $ip = '';
    private string $origIp = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Každý test z vlastní adresy, aby ho neomezil limit hlášení za hodinu z jiných testů.
        $this->origIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $this->ip = '10.' . random_int(0, 255) . '.' . random_int(0, 255) . '.' . random_int(1, 254);
        $_SERVER['REMOTE_ADDR'] = $this->ip;
    }

    protected function tearDown(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->origIp;
        $_POST = [];
        $_FILES = [];
    }

    private function decodedMails(): array
    {
        return array_map(fn($m) => ['to' => $m['to'], 'subject' => mb_decode_mimeheader($m['subject']), 'body' => quoted_printable_decode($m['body'])], $this->mails());
    }

    private function who(array $over = []): array
    {
        return $over + ['tenant' => 'dum', 'user_id' => 2, 'name' => 'Petr Novák', 'email' => 'petr.novak@example.cz', 'role' => 'vlastník'];
    }

    private function lastReport(): array
    {
        return rq('SELECT * FROM reports WHERE ip = ? ORDER BY id DESC LIMIT 1', [$this->ip])->fetch();
    }

    /** Uloží nastavení služby a vrátí funkci, která ho vrátí zpět. */
    private function psetTemp(string $key, string $value): \Closure
    {
        $had = pq('SELECT value FROM platform_settings WHERE key = ?', [$key])->fetchColumn();
        pset_save($key, $value);
        return function () use ($key, $had) {
            if ($had === false) {
                pq('DELETE FROM platform_settings WHERE key = ?', [$key]);
                pset('__reset');
            } else {
                pset_save($key, (string) $had);
            }
        };
    }

    public function testCentralStorageOnPlatform(): void
    {
        $this->assertTrue(reports_central());
        $this->assertSame(platform_db(), reports_pdo());
        rq('SELECT COUNT(*) FROM reports');
        $this->assertNotFalse(platform_db()->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'reports'")->fetchColumn());
    }

    public function testSubmitValidLoggedInReport(): void
    {
        $before = reports_open_count();
        $_POST = ['body' => 'Nejde uložit aktualitu.', 'type' => 'nesmysl', 'url' => 'https://dum.test.invalid/admin/aktuality', 'title' => "Aktuality\x01\x02 správa", 'viewport' => '390x844'];
        $this->assertSame([], report_submit($this->who()));
        $r = $this->lastReport();
        $this->assertSame('bug', $r['type'], 'Neznámý typ = chyba.');
        $this->assertSame('new', $r['status']);
        $this->assertSame('dum', $r['tenant']);
        $this->assertSame(2, (int) $r['user_id']);
        $this->assertSame('petr.novak@example.cz', $r['user_email']);
        $this->assertSame('https://dum.test.invalid/admin/aktuality', $r['url']);
        $this->assertSame('Aktuality  správa', $r['page_title'], 'Řídicí znaky se nahradí mezerou.');
        $this->assertSame('', $r['screenshot']);
        $this->assertSame(1, (int) $r['hits']);
        $this->assertSame($before + 1, reports_open_count());
    }

    public function testSubmitDropsNonHttpUrlAndKeepsIdea(): void
    {
        $_POST = ['body' => 'Chtělo by to tmavý režim.', 'type' => 'idea', 'url' => 'javascript:alert(1)'];
        $this->assertSame([], report_submit($this->who()));
        $r = $this->lastReport();
        $this->assertSame('idea', $r['type']);
        $this->assertSame('', $r['url']);
    }

    public function testSubmitValidation(): void
    {
        $_POST = ['body' => 'abc'];
        $this->assertSame(['Popište prosím, co se stalo nebo co navrhujete.'], report_submit($this->who()));

        $_POST = ['body' => 'Dostatečně dlouhý text', 'email' => 'neplatny'];
        $this->assertSame(['Vyplňte prosím svůj e-mail, ať Vám můžeme odpovědět.'], report_submit($this->who(['user_id' => 0, 'email' => ''])));

        $_POST = ['body' => 'Dostatečně dlouhý text', 'website' => 'http://spam'];
        $this->assertSame(['Hlášení se nepodařilo odeslat.'], report_submit($this->who()));
        $this->assertFalse(rq('SELECT 1 FROM reports WHERE ip = ?', [$this->ip])->fetchColumn(), 'Nic se neuložilo.');

        $_POST = ['body' => 'Dostatečně dlouhý text', 'email' => 'host@example.org'];
        $this->assertSame([], report_submit($this->who(['user_id' => 0, 'email' => '', 'name' => ''])));
        $this->assertSame('host@example.org', $this->lastReport()['user_email']);
    }

    public function testSubmitRateLimitForAnonymous(): void
    {
        $who = $this->who(['user_id' => 0, 'email' => '', 'name' => '']);
        for ($i = 0; $i < 5; $i++) {
            $_POST = ['body' => 'Hlášení číslo ' . $i, 'email' => 'host@example.org'];
            $this->assertSame([], report_submit($who));
        }
        $_POST = ['body' => 'Šesté hlášení', 'email' => 'host@example.org'];
        $this->assertSame(['Za poslední hodinu od Vás přišlo hodně hlášení. Zkuste to prosím později.'], report_submit($who));
        // Přihlášený uživatel má limit 20.
        $_POST = ['body' => 'Hlášení přihlášeného'];
        $this->assertSame([], report_submit($this->who()));
    }

    public function testSubmitMailsOperatorWhenContactSet(): void
    {
        $restore = $this->psetTemp('contact_email', 'provozovatel@example.org');
        try {
            $_POST = ['body' => 'Rozbitý kalendář ' . $this->ip, 'type' => 'bug', 'url' => 'https://x.test.invalid/terminy'];
            $this->assertSame([], report_submit($this->who()));
            $m = array_values(array_filter($this->decodedMails(), fn($m) => str_contains($m['body'], 'Rozbitý kalendář ' . $this->ip)));
            $this->assertCount(1, $m);
            $this->assertSame('provozovatel@example.org', $m[0]['to']);
            $this->assertSame('Nové hlášení: Na webu něco nefunguje', $m[0]['subject']);
            $this->assertStringContainsString('Petr Novák <petr.novak@example.cz>, web dum', $m[0]['body']);
            $this->assertStringContainsString('/sprava/hlaseni', $m[0]['body']);
        } finally {
            $restore();
        }
    }

    public function testBackUrlOnlyLocal(): void
    {
        $this->assertSame('/admin/aktuality?x=1', report_back('/admin/aktuality?x=1'));
        $this->assertSame('/', report_back('//evil.example'));
        $this->assertSame('/', report_back('/\\evil.example'));
        $this->assertSame('/', report_back('https://evil.example/'));
        $this->assertSame('/', report_back('/hlaseni'));
        $this->assertSame('/', report_back('/a b'));
        $this->assertSame('/', report_back(''));
    }

    private function createReport(array $over = []): array
    {
        $now = date('Y-m-d H:i:s');
        $r = $over + ['tenant' => 'dum', 'user_id' => 2, 'user_name' => 'Petr Novák', 'user_email' => 'petr.novak@example.cz', 'type' => 'bug', 'body' => 'Problém ' . bin2hex(random_bytes(4)), 'status' => 'new'];
        rq('INSERT INTO reports (tenant, user_id, user_name, user_email, type, body, status, created_at, updated_at, ip) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$r['tenant'], $r['user_id'], $r['user_name'], $r['user_email'], $r['type'], $r['body'], $r['status'], $now, $now, $this->ip]);
        return rq('SELECT * FROM reports WHERE id = ?', [(int) reports_pdo()->lastInsertId()])->fetch();
    }

    public function testUserFollowupAddsMessageAndReopens(): void
    {
        $r = $this->createReport(['status' => 'in_progress']);
        $_POST = ['body' => ' '];
        $this->assertSame(['Napište prosím, co chcete doplnit.'], report_user_followup($r, $this->who()));
        $this->assertSame([], report_messages((int) $r['id']));

        $_POST = ['body' => '  Ještě snímek obrazovky.  '];
        $this->assertSame([], report_user_followup($r, $this->who()));
        $msgs = report_messages((int) $r['id']);
        $this->assertCount(1, $msgs);
        $this->assertSame('user', $msgs[0]['author']);
        $this->assertSame('Petr Novák', $msgs[0]['by_name']);
        $this->assertSame('Ještě snímek obrazovky.', $msgs[0]['body']);
        $this->assertSame('new', rq('SELECT status FROM reports WHERE id = ?', [$r['id']])->fetchColumn());
    }

    public function testFollowupRateLimit(): void
    {
        report_messages_ready();   // tabulka vláken nesmí záviset na pořadí testů
        $r = $this->createReport();
        for ($i = 0; $i < 10; $i++) {
            rq("INSERT INTO report_messages (report_id, author, by_name, body, created_at) VALUES (?, 'user', 'x', 'y', ?)", [$r['id'], date('Y-m-d H:i:s')]);
        }
        $_POST = ['body' => 'Jedenáctá zpráva'];
        $this->assertSame(['K tomuto hlášení jste za poslední hodinu poslali hodně zpráv. Zkuste to prosím později.'], report_user_followup($r, $this->who()));
    }

    public function testMessagesForSeveralReports(): void
    {
        $a = $this->createReport();
        $b = $this->createReport();
        $c = $this->createReport();
        report_update($a, 'in_progress', 'Odpověď A', '', 'Podpora');
        report_update($b, 'in_progress', 'Odpověď B1', '', 'Podpora');
        report_update($b, 'in_progress', 'Odpověď B2', '', 'Podpora');
        $all = report_messages_for([$a['id'], $b['id'], $c['id'], 'x']);
        $this->assertSame(['Odpověď A'], array_column($all[(int) $a['id']], 'body'));
        $this->assertSame(['Odpověď B1', 'Odpověď B2'], array_column($all[(int) $b['id']], 'body'));
        $this->assertArrayNotHasKey((int) $c['id'], $all);
        $this->assertSame([], report_messages_for([]));
    }

    public function testUpdateWithReplyStoresThreadAndMailsAuthor(): void
    {
        $r = $this->createReport(['user_email' => 'autor.' . bin2hex(random_bytes(3)) . '@example.org']);
        report_update($r, 'in_progress', '  Díváme se na to.  ', 'interní', 'Podpora');
        $row = rq('SELECT * FROM reports WHERE id = ?', [$r['id']])->fetch();
        $this->assertSame('in_progress', $row['status']);
        $this->assertSame('interní', $row['note']);
        $this->assertSame('Díváme se na to.', $row['reply']);
        $this->assertSame('Podpora', $row['replied_by']);
        $msgs = report_messages((int) $r['id']);
        $this->assertSame(['staff'], array_column($msgs, 'author'));
        $m = array_values(array_filter($this->decodedMails(), fn($m) => $m['to'] === $r['user_email']));
        $this->assertCount(1, $m);
        $this->assertSame('Vaše hlášení: Řeší se', $m[0]['subject']);
        $this->assertStringContainsString('Díváme se na to.', $m[0]['body']);
        $this->assertStringContainsString('/hlaseni#hlaseni-' . $r['id'], $m[0]['body']);
    }

    public function testUpdateNotifyRules(): void
    {
        $email = 'pravidla.' . bin2hex(random_bytes(3)) . '@example.org';
        $r = $this->createReport(['user_email' => $email]);
        $count = fn() => count(array_filter($this->decodedMails(), fn($m) => $m['to'] === $email));

        report_update($r, 'in_progress', '', '', 'Podpora');
        $this->assertSame(0, $count(), '„Řeší se“ bez odpovědi autora neruší.');
        $r = rq('SELECT * FROM reports WHERE id = ?', [$r['id']])->fetch();

        report_update($r, 'nesmysl', '', 'pozn', 'Podpora');
        $this->assertSame('in_progress', rq('SELECT status FROM reports WHERE id = ?', [$r['id']])->fetchColumn(), 'Neplatný stav = beze změny.');
        $this->assertSame(0, $count());

        report_update($r, 'planned', '', '', 'Podpora');
        $this->assertSame(1, $count());
        $r = rq('SELECT * FROM reports WHERE id = ?', [$r['id']])->fetch();
        report_update($r, 'planned', '', '', 'Podpora');
        $this->assertSame(1, $count(), 'Stav se nezměnil – bez e-mailu.');
        report_update($r, 'done', '', '', 'Podpora');
        $this->assertSame(2, $count());
        $last = array_values(array_filter($this->decodedMails(), fn($m) => $m['to'] === $email))[1];
        $this->assertSame('Vaše hlášení: Vyřešeno', $last['subject']);

        // Automaticky zachycená chyba: žádná zpráva do vlákna ani e-mail.
        $auto = $this->createReport(['type' => 'auto', 'user_email' => $email]);
        report_update($auto, 'done', 'Opraveno', '', 'Podpora');
        $this->assertSame([], report_messages((int) $auto['id']));
        $this->assertSame(2, $count());
        $this->assertSame('done', rq('SELECT status FROM reports WHERE id = ?', [$auto['id']])->fetchColumn());
    }

    public function testAnonymousAuthorGetsReplyByMailHint(): void
    {
        $email = 'anon.' . bin2hex(random_bytes(3)) . '@example.org';
        $r = $this->createReport(['user_id' => 0, 'user_email' => $email, 'tenant' => '']);
        report_update($r, 'done', 'Hotovo', '', 'Podpora');
        $m = array_values(array_filter($this->decodedMails(), fn($m) => $m['to'] === $email));
        $this->assertCount(1, $m);
        $this->assertStringContainsString('odpovězte prosím na tento e-mail', $m[0]['body']);
    }

    public function testReplyUrl(): void
    {
        $this->assertSame('', report_reply_url(['id' => 5, 'user_id' => 0, 'type' => 'bug', 'tenant' => 'dum']));
        $this->assertSame('', report_reply_url(['id' => 5, 'user_id' => 3, 'type' => 'auto', 'tenant' => 'dum']));
        $this->assertSame('', report_reply_url(['id' => 5, 'user_id' => 3, 'type' => 'bug', 'tenant' => '']));
        $this->assertSame('', report_reply_url(['id' => 5, 'user_id' => 3, 'type' => 'bug', 'tenant' => 'neexistuje-' . bin2hex(random_bytes(3))]));
        $this->assertSame(tenant_url(tenant_row('dum'), '/hlaseni#hlaseni-5'), report_reply_url(['id' => 5, 'user_id' => 3, 'type' => 'idea', 'tenant' => 'dum']));
    }

    private function boom(string $msg): \RuntimeException
    {
        return new \RuntimeException($msg);
    }

    public function testAutoReportDeduplicatesOpenErrors(): void
    {
        $msg = 'Testovací chyba ' . bin2hex(random_bytes(4));
        report_auto($this->boom($msg));
        report_auto($this->boom($msg));
        $rows = rq("SELECT * FROM reports WHERE type = 'auto' AND body LIKE ?", ['%' . $msg . '%'])->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]['hits']);
        $this->assertSame('dum', $rows[0]['tenant']);
        $this->assertStringStartsWith('RuntimeException: ' . $msg, $rows[0]['body']);
        $this->assertStringContainsString('/tests/Unit/ReportsTest.php:', $rows[0]['body']);
        $this->assertSame(32, strlen($rows[0]['fingerprint']));

        // Po vyřešení se stejná chyba zapíše znovu jako nové hlášení.
        rq("UPDATE reports SET status = 'done' WHERE id = ?", [$rows[0]['id']]);
        report_auto($this->boom($msg));
        $this->assertCount(2, rq("SELECT * FROM reports WHERE type = 'auto' AND body LIKE ?", ['%' . $msg . '%'])->fetchAll());
    }

    public function testPrioritySortPutsOpenPremiumFirst(): void
    {
        $this->assertArrayHasKey('dum', report_premium_tenants(), 'Web bez placení má edici Premium.');
        $other = 'neni-premium-' . bin2hex(random_bytes(3));
        $rows = [
            ['id' => 'A', 'tenant' => $other, 'status' => 'new'],
            ['id' => 'B', 'tenant' => 'dum', 'status' => 'in_progress'],
            ['id' => 'C', 'tenant' => 'dum', 'status' => 'new'],
            ['id' => 'D', 'tenant' => 'dum', 'status' => 'done'],
            ['id' => 'E', 'tenant' => $other, 'status' => 'planned'],
            ['id' => 'F', 'status' => 'rejected'],
            ['id' => 'G', 'tenant' => $other, 'status' => 'new'],
        ];
        $sorted = report_priority_sort($rows);
        $this->assertSame(['C', 'A', 'G', 'B', 'E', 'D', 'F'], array_column($sorted, 'id'));
        $this->assertSame([true, false, false, true, false, true, false], array_column($sorted, 'premium'));
        $this->assertSame([], report_priority_sort([]));
    }

    #[RunInSeparateProcess]
    public function testPremiumTenantsOnlyActiveNonDemoPremium(): void
    {
        $until = date('Y-m-d', strtotime('+100 day'));
        tenant_create(['slug' => 'prem-a', 'name' => 'A', 'title' => 'A', 'billing' => 1, 'paid_until' => $until]);
        pq("UPDATE tenants SET plan = 'premium' WHERE slug = 'prem-a'");
        tenant_create(['slug' => 'std-b', 'name' => 'B', 'title' => 'B', 'billing' => 1, 'paid_until' => $until]);
        pq("UPDATE tenants SET plan = 'standard' WHERE slug = 'std-b'");
        tenant_create(['slug' => 'free-c', 'name' => 'C', 'title' => 'C', 'billing' => 1]);
        tenant_create(['slug' => 'off-d', 'name' => 'D', 'title' => 'D', 'active' => 0]);
        tenant_create(['slug' => 'demo-e', 'name' => 'E', 'title' => 'E', 'demo' => 2]);
        $prem = report_premium_tenants();
        $this->assertSame(['dum' => true, 'prem-a' => true], array_intersect_key($prem, array_flip(['dum', 'prem-a', 'std-b', 'free-c', 'off-d', 'demo-e'])));
        $this->assertArrayNotHasKey('std-b', $prem);
        $this->assertArrayNotHasKey('demo-e', $prem);
    }
}
