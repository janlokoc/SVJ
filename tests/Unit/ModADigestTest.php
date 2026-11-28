<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Modul A – týdenní přehled pro obyvatele (app/ext/moda_lib.php). */
final class ModADigestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        moda_ready();
        q('DELETE FROM digest_prefs');
        moda_setting('digest_week', '');
        moda_setting('digest_off', '0');
    }

    protected function tearDown(): void
    {
        q('DELETE FROM digest_prefs');
        q('UPDATE users SET email_notify = 1');
        q("DELETE FROM polls WHERE title LIKE 'Hlasování ______'");
        parent::tearDown();
    }

    private function user(string $email): array
    {
        return one('SELECT * FROM users WHERE email = ?', [$email]);
    }

    private function decodedMails(): array
    {
        return array_map(fn($m) => ['to' => $m['to'], 'subject' => mb_decode_mimeheader($m['subject']), 'body' => quoted_printable_decode($m['body'])], $this->mails());
    }

    public function testDefaultIsOnForOwnersOffForTenants(): void
    {
        $this->assertTrue(digest_enabled($this->user('petr.novak@example.cz')));
        $this->assertFalse(digest_enabled($this->user('lukas.cerny@example.cz')), 'nájemník si přehled zapíná sám');
        q("UPDATE users SET email_notify = 0 WHERE email = 'petr.novak@example.cz'");
        $this->assertFalse(digest_enabled($this->user('petr.novak@example.cz')), 'kdo vypnul zprávy e-mailem, přehled nedostane');
    }

    public function testOwnChoiceOverridesDefault(): void
    {
        $tenant = $this->user('lukas.cerny@example.cz');
        digest_set((int) $tenant['id'], true);
        $this->assertTrue(digest_enabled($tenant));
        $owner = $this->user('petr.novak@example.cz');
        digest_set((int) $owner['id'], false);
        $this->assertFalse(digest_enabled($owner));
        $this->assertSame('Týdenní přehled vypnut', val('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1'));
    }

    public function testTokenIsStableAndFindsUser(): void
    {
        $u = $this->user('eva.svobodova@example.cz');
        $t = digest_token((int) $u['id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $t);
        $this->assertSame($t, digest_token((int) $u['id']));
        $this->assertTrue(digest_enabled($u), 'založení tokenu nemění výchozí volbu');
        $this->assertSame($u['email'], digest_user_by_token($t)['email']);
        $this->assertNull(digest_user_by_token(str_repeat('0', 32)));
        $this->assertNull(digest_user_by_token('x'));
    }

    public function testContentRespectsUnitsVotesAndDates(): void
    {
        $u = $this->user('tomas.dvorak@example.cz');   // vlastník jednotky A/3 (id 3)
        $since = date('Y-m-d H:i:s', time() - 60);
        $tag = bin2hex(random_bytes(3));
        q('INSERT INTO posts (title, body, visibility, published_at) VALUES (?,?,?,?)', ['Nová aktualita ' . $tag, 'Text', 'residents', date('Y-m-d H:i:s')]);
        q('INSERT INTO posts (title, body, visibility, published_at) VALUES (?,?,?,?)', ['Budoucí aktualita ' . $tag, 'Text', 'public', date('Y-m-d H:i:s', time() + 86400)]);
        q('INSERT INTO documents (title, category, visibility, unit_id) VALUES (?,?,?,?)', ['Vyúčtování A3 ' . $tag, 'ostatni', 'residents', 3]);
        q('INSERT INTO documents (title, category, visibility, unit_id) VALUES (?,?,?,?)', ['Vyúčtování A4 ' . $tag, 'ostatni', 'residents', 4]);
        q('INSERT INTO events (title, event_date) VALUES (?,?)', ['Brzy ' . $tag, date('Y-m-d', time() + 3 * 86400)]);
        q('INSERT INTO events (title, event_date) VALUES (?,?)', ['Pozdě ' . $tag, date('Y-m-d', time() + 20 * 86400)]);
        q("INSERT INTO tickets (user_id, subject, body, status, updated_at) VALUES (?,?,?,?,?)", [$u['id'], 'Kape kohoutek ' . $tag, 'x', 'in_progress', date('Y-m-d H:i:s')]);

        $d = digest_for($u, $since);
        $this->assertContains('Nová aktualita ' . $tag, array_column($d['posts'], 'title'));
        $this->assertNotContains('Budoucí aktualita ' . $tag, array_column($d['posts'], 'title'), 'naplánovaná aktualita ještě není novinka');
        $this->assertContains('Vyúčtování A3 ' . $tag, array_column($d['docs'], 'title'));
        $this->assertNotContains('Vyúčtování A4 ' . $tag, array_column($d['docs'], 'title'), 'dokument cizí jednotky se neukáže');
        $this->assertContains('Brzy ' . $tag, array_column($d['events'], 'title'));
        $this->assertNotContains('Pozdě ' . $tag, array_column($d['events'], 'title'));
        $this->assertContains('Kape kohoutek ' . $tag, array_column($d['tickets'], 'subject'));
        $this->assertTrue($d['ticket_news']);
        $this->assertFalse(digest_empty($d));

        // Otevřené hlasování: objeví se, dokud za jednotku nikdo nehlasoval.
        q('INSERT INTO polls (title, opens_at, closes_at, online) VALUES (?,?,?,1)', ['Hlasování ' . $tag, date('Y-m-d'), date('Y-m-d', time() + 5 * 86400)]);
        $pollId = (int) db()->lastInsertId();
        q('INSERT INTO poll_items (poll_id, question) VALUES (?,?)', [$pollId, 'Souhlasíte?']);
        $itemId = (int) db()->lastInsertId();
        $this->assertContains('Hlasování ' . $tag, array_column(digest_for($u, $since)['polls'], 'title'));
        q("INSERT INTO poll_votes (item_id, unit_id, user_id, choice) VALUES (?, 3, ?, 'yes')", [$itemId, $u['id']]);
        $this->assertNotContains('Hlasování ' . $tag, array_column(digest_for($u, $since)['polls'], 'title'));
        $this->assertSame([], digest_for($this->user('lukas.cerny@example.cz'), $since)['polls'], 'nájemník nehlasuje');

        $text = digest_text($u, $d, digest_token((int) $u['id']));
        $this->assertStringContainsString('NOVÉ AKTUALITY', $text);
        $this->assertStringContainsString('Vyúčtování A3 ' . $tag . ' (pro Vaši jednotku)', $text);
        $this->assertStringContainsString('VAŠE ROZPRACOVANÁ HLÁŠENÍ', $text);
        $this->assertStringContainsString('Kape kohoutek ' . $tag . ': řeší se', $text);
        $this->assertStringContainsString('/tydenni-prehled/odhlasit/' . digest_token((int) $u['id']), $text);
    }

    public function testUnansweredSurveysOnly(): void
    {
        $u = $this->user('martin.kucera@example.cz');
        q('INSERT INTO surveys (title, body, closes_at, created_at) VALUES (?,?,?,?)', ['Anketa otevřená', '', date('Y-m-d', time() + 86400), date('Y-m-d H:i:s')]);
        $sid = (int) db()->lastInsertId();
        q('INSERT INTO survey_options (survey_id, label) VALUES (?,?)', [$sid, 'Ano']);
        $oid = (int) db()->lastInsertId();
        q('INSERT INTO surveys (title, body, closes_at, created_at) VALUES (?,?,?,?)', ['Anketa skončená', '', date('Y-m-d', time() - 86400), date('Y-m-d H:i:s')]);
        $titles = array_column(digest_for($u, date('Y-m-d H:i:s'))['surveys'], 'title');
        $this->assertContains('Anketa otevřená', $titles);
        $this->assertNotContains('Anketa skončená', $titles);
        q('INSERT INTO survey_answers (survey_id, user_id, option_id, answered_at) VALUES (?,?,?,?)', [$sid, $u['id'], $oid, date('Y-m-d H:i:s')]);
        $this->assertNotContains('Anketa otevřená', array_column(digest_for($u, date('Y-m-d H:i:s'))['surveys'], 'title'));
    }

    public function testEmptyDigestDetection(): void
    {
        $empty = ['posts' => [], 'docs' => [], 'events' => [], 'polls' => [], 'surveys' => [], 'tickets' => [['subject' => 'x']], 'ticket_news' => false];
        $this->assertTrue(digest_empty($empty), 'otevřené hlášení beze změny samo o sobě novinka není');
        $this->assertFalse(digest_empty(['ticket_news' => true] + $empty));
        $this->assertFalse(digest_empty(['events' => [['title' => 'x']]] + $empty));
    }

    public function testRecipientsFollowChoices(): void
    {
        $emails = array_column(digest_recipients(), 'email');
        $this->assertContains('petr.novak@example.cz', $emails);
        $this->assertNotContains('lukas.cerny@example.cz', $emails);
        digest_set((int) $this->user('lukas.cerny@example.cz')['id'], true);
        digest_set((int) $this->user('petr.novak@example.cz')['id'], false);
        $emails = array_column(digest_recipients(), 'email');
        $this->assertContains('lukas.cerny@example.cz', $emails);
        $this->assertNotContains('petr.novak@example.cz', $emails);
    }

    public function testWeeklyRunSendsOncePerWeekAndSkipsEmpty(): void
    {
        q('INSERT INTO events (title, event_date) VALUES (?,?)', ['Úklid sklepa', date('Y-m-d', time() + 86400)]);
        digest_set((int) $this->user('hana.prochazkova@example.cz')['id'], false);
        $r = digest_daily(true);
        $this->assertIsArray($r);
        [$sent] = $r;
        $mails = $this->decodedMails();
        $this->assertSame($sent, count($mails));
        $this->assertGreaterThan(0, $sent);
        $to = array_column($mails, 'to');
        $this->assertContains('petr.novak@example.cz', $to);
        $this->assertNotContains('hana.prochazkova@example.cz', $to, 'kdo přehled vypnul, nedostane ho');
        $this->assertNotContains('lukas.cerny@example.cz', $to);
        $this->assertStringContainsString('Úklid sklepa', $mails[0]['body']);
        $this->assertStringStartsWith('Týdenní přehled', $mails[0]['subject']);
        $this->assertSame(date('o-W'), setting('digest_week'));
        $this->assertSame('Týdenní přehled rozeslán', val('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1'));
        $this->assertNull(digest_daily(), 'podruhé v tomtéž týdnu nic');
    }

    public function testHouseSwitchAndMissingSenderStopEverything(): void
    {
        moda_setting('digest_off', '1');
        $this->assertNull(digest_daily(true));
        moda_setting('digest_off', '0');
        $old = setting('mail_from');
        moda_setting('mail_from', '');
        try {
            $this->assertNull(digest_daily(true));
        } finally {
            moda_setting('mail_from', $old);
        }
        $this->assertSame([], $this->mails());
    }

    /** Nově založený web nic nerozesílá, dokud ho správce neoznámí sousedům prvním hromadným e-mailem. */
    public function testNothingIsSentBeforeTheHouseIsAnnounced(): void
    {
        $week = setting('digest_week');
        $mailings = all('SELECT * FROM mailings');
        moda_setting('digest_week', '');
        q('DELETE FROM mailings');
        try {
            q('INSERT INTO events (title, event_date) VALUES (?,?)', ['Revize výtahu', date('Y-m-d', time() + 86400)]);
            $this->assertFalse(digest_house_launched());
            $this->assertNull(digest_daily(true));
            $this->assertSame([], $this->mails(), 'žádné „Vítejte na novém webu“ hned po registraci');
            q("INSERT INTO mailings (subject, body, audience) VALUES ('Máme nový web', 'Adresa webu…', 'all')");
            $this->assertTrue(digest_house_launched());
            $this->assertIsArray(digest_daily(true));
            q('DELETE FROM mailings');
            $this->assertTrue(digest_house_launched(), 'dům, kterému přehled už chodil, pokračuje');
        } finally {
            q('DELETE FROM mailings');
            foreach ($mailings as $m) {
                q('INSERT INTO mailings (' . implode(',', array_keys($m)) . ') VALUES (' . implode(',', array_fill(0, count($m), '?')) . ')', array_values($m));
            }
            moda_setting('digest_week', $week);
        }
    }

    public function testDailyTasksHookIsWired(): void
    {
        $this->assertStringContainsString("if (function_exists('digest_daily')) digest_daily();", (string) file_get_contents(dirname(__DIR__, 2) . '/app/extra_lib.php'));
    }
}
