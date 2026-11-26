<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** app/extra_lib.php (hlasování, revize, denní úlohy, kalendář, záloha) a ankety/otisk hlasování z app/routes_more.php. */
final class ExtraTest extends TestCase
{
    private static function loadRoutesMore(): void
    {
        if (!function_exists('survey_load')) {
            $route = function (): void {};   // soubor tras: zde se trasy jen „zaregistrují“ naprázdno
            require_once dirname(__DIR__, 2) . '/app/routes_more.php';
        }
    }

    private static function day(int $offset): string
    {
        return date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' day'));
    }

    // ---------- Hlasování ----------

    public function testPollStateFollowsDates(): void
    {
        $this->assertSame('planned', poll_state(['opens_at' => self::day(1), 'closes_at' => self::day(10)]));
        $this->assertSame('open', poll_state(['opens_at' => self::day(0), 'closes_at' => self::day(0)]));
        $this->assertSame('open', poll_state(['opens_at' => self::day(-5), 'closes_at' => self::day(5)]));
        $this->assertSame('closed', poll_state(['opens_at' => self::day(-10), 'closes_at' => self::day(-1)]));
        $this->assertSame(['planned', 'open', 'closed'], array_keys(POLL_STATES));
    }

    /** Vytvoří hlasování s položkami a hlasy [položka => [unit_id => choice]]; vrací [pollId, [itemId…]]. */
    private function makePoll(int $threshold, array $votes): array
    {
        q('INSERT INTO polls (title, body, opens_at, closes_at, threshold, published) VALUES (?,?,?,?,?,1)', ['Test hlasování ' . uniqid(), '', self::day(-1), self::day(5), $threshold]);
        $pollId = (int) db()->lastInsertId();
        $items = [];
        foreach ($votes as $i => $byUnit) {
            q('INSERT INTO poll_items (poll_id, question, sort) VALUES (?,?,?)', [$pollId, 'Otázka ' . $i, $i]);
            $itemId = (int) db()->lastInsertId();
            $items[] = $itemId;
            foreach ($byUnit as $unitId => $choice) {
                q('INSERT INTO poll_votes (item_id, unit_id, user_id, choice) VALUES (?,?,NULL,?)', [$itemId, $unitId, $choice]);
            }
        }
        return [$pollId, $items];
    }

    private function dropPoll(int $pollId): void
    {
        q('DELETE FROM poll_votes WHERE item_id IN (SELECT id FROM poll_items WHERE poll_id = ?)', [$pollId]);
        q('DELETE FROM poll_items WHERE poll_id = ?', [$pollId]);
        q('DELETE FROM polls WHERE id = ?', [$pollId]);
    }

    public function testPollResultsWeightByShares(): void
    {
        $units = array_column(all('SELECT id, share FROM units'), 'share', 'id');
        $total = array_sum(array_map(fn($s) => share_value((string) $s), $units));
        $this->assertGreaterThan(0, $total);
        foreach ($units as $s) {
            $this->assertGreaterThan(0, share_value((string) $s), 'precondition: all units have a share');
        }
        $ids = array_keys($units);
        $yes = array_slice($ids, 0, 7);
        [$pollId, $items] = $this->makePoll(50, [
            1 => array_fill_keys($yes, 'yes') + [$ids[7] => 'no'],
            2 => [$ids[0] => 'yes', $ids[8] => 'abstain'],
        ]);
        try {
            $r = poll_results($pollId);
            $this->assertTrue($r['byShare']);
            $this->assertSame(50, $r['threshold']);
            $this->assertCount(2, $r['items']);
            [$a, $b] = $r['items'];
            $this->assertSame($items[0], (int) $a['id']);
            $sumYes = array_sum(array_map(fn($id) => share_value((string) $units[$id]), $yes));
            $this->assertEqualsWithDelta($sumYes, $a['sum']['yes'], 1e-9);
            $this->assertEqualsWithDelta(share_value((string) $units[$ids[7]]), $a['sum']['no'], 1e-9);
            $this->assertSame(['yes' => 7, 'no' => 1, 'abstain' => 0], $a['count']);
            $this->assertEqualsWithDelta($sumYes / $total * 100, $a['percent']['yes'], 1e-9);
            $this->assertSame($sumYes / $total * 100 > 50, $a['passed']);
            $this->assertSame(['yes' => 1, 'no' => 0, 'abstain' => 1], $b['count']);
            $this->assertFalse($b['passed'], 'one unit of twelve can never exceed 50 %');
            // 9 různých jednotek hlasovalo (7 + 1 u první otázky, 1 navíc u druhé)
            $this->assertSame(9, $r['voted']);
            $turnout = array_sum(array_map(fn($id) => share_value((string) $units[$id]), array_slice($ids, 0, 9)));
            $this->assertEqualsWithDelta($turnout / $total * 100, $r['turnout'], 1e-9);
            $this->assertSame(count($units), count($r['units']));
            $this->assertSame('yes', $r['votes'][$items[0]][$ids[0]]['choice']);
        } finally {
            $this->dropPoll($pollId);
        }
    }

    public function testPollResultsFallBackToOneVotePerUnitAndThresholdIsStrict(): void
    {
        $ids = array_map('intval', array_column(all('SELECT id FROM units ORDER BY id'), 'id'));
        $this->assertCount(12, $ids, 'demo house has 12 units');
        $last = end($ids);
        $origShare = (string) val('SELECT share FROM units WHERE id = ?', [$last]);
        q("UPDATE units SET share = '' WHERE id = ?", [$last]);
        [$pollId, $items] = $this->makePoll(50, [
            1 => array_fill_keys(array_slice($ids, 0, 6), 'yes') + array_fill_keys(array_slice($ids, 6, 2), 'no'),
            2 => array_fill_keys(array_slice($ids, 0, 7), 'yes'),
        ]);
        try {
            $r = poll_results($pollId);
            $this->assertFalse($r['byShare'], 'one missing share switches to 1 vote per unit');
            $this->assertSame(['yes' => 6.0, 'no' => 2.0, 'abstain' => 0.0], $r['items'][0]['sum']);
            $this->assertEqualsWithDelta(50.0, $r['items'][0]['percent']['yes'], 1e-9);
            $this->assertFalse($r['items'][0]['passed'], 'exactly the threshold is not enough – must exceed it');
            $this->assertTrue($r['items'][1]['passed']);
            $this->assertSame(8, $r['voted']);
            $this->assertEqualsWithDelta(8 / 12 * 100, $r['turnout'], 1e-9);
        } finally {
            q('UPDATE units SET share = ? WHERE id = ?', [$origShare, $last]);
            $this->dropPoll($pollId);
        }
    }

    public function testPollResultsThresholdOfAllUnitsNotOnlyVoters(): void
    {
        $ids = array_map('intval', array_column(all('SELECT id FROM units ORDER BY id'), 'id'));
        // Jediný hlas PRO – 100 % odevzdaných hlasů, ale málo ze všech hlasů v domě.
        [$pollId] = $this->makePoll(50, [1 => [$ids[0] => 'yes']]);
        try {
            $r = poll_results($pollId);
            $this->assertFalse($r['items'][0]['passed']);
            $this->assertLessThan(50, $r['items'][0]['percent']['yes']);
        } finally {
            $this->dropPoll($pollId);
        }
    }

    public function testPollFingerprintChangesWithVotes(): void
    {
        self::loadRoutesMore();
        $ids = array_map('intval', array_column(all('SELECT id FROM units ORDER BY id'), 'id'));
        [$pollId, $items] = $this->makePoll(50, [1 => [$ids[0] => 'yes', $ids[1] => 'no']]);
        try {
            $h = poll_fingerprint($pollId);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h);
            $this->assertSame($h, poll_fingerprint($pollId));
            q("UPDATE poll_votes SET choice = 'yes' WHERE item_id = ? AND unit_id = ?", [$items[0], $ids[1]]);
            $this->assertNotSame($h, poll_fingerprint($pollId));
        } finally {
            $this->dropPoll($pollId);
        }
    }

    // ---------- Ankety ----------

    public function testSurveyLoadCountsAndOpenState(): void
    {
        self::loadRoutesMore();
        $this->assertNull(survey_load(987654));
        $make = function (string $closes): int {
            q('INSERT INTO surveys (title, body, closes_at, anonymous, published, created_at) VALUES (?,?,?,1,1,?)', ['Anketa ' . uniqid(), '', $closes, date('Y-m-d H:i:s')]);
            return (int) db()->lastInsertId();
        };
        $sid = $make('');
        $closedId = $make(self::day(-1));
        $todayId = $make(self::day(0));
        try {
            $opts = [];
            foreach (['B', 'A', 'C'] as $i => $label) {
                q('INSERT INTO survey_options (survey_id, label, sort) VALUES (?,?,?)', [$sid, $label, [2, 1, 3][$i]]);
                $opts[$label] = (int) db()->lastInsertId();
            }
            $users = array_map('intval', array_column(all('SELECT id FROM users WHERE active = 1 ORDER BY id LIMIT 3'), 'id'));
            q('INSERT INTO survey_answers (survey_id, user_id, option_id, answered_at) VALUES (?,?,?,?)', [$sid, $users[0], $opts['A'], date('Y-m-d H:i:s')]);
            q('INSERT INTO survey_answers (survey_id, user_id, option_id, answered_at) VALUES (?,?,?,?)', [$sid, $users[1], $opts['A'], date('Y-m-d H:i:s')]);
            q('INSERT INTO survey_answers (survey_id, user_id, option_id, answered_at) VALUES (?,?,?,?)', [$sid, $users[2], $opts['C'], date('Y-m-d H:i:s')]);

            $s = survey_load($sid);
            $this->assertSame(['A', 'B', 'C'], array_column($s['options'], 'label'), 'ordered by sort');
            $this->assertSame([2, 0, 1], array_map(fn($o) => (int) $o['votes'], $s['options']));
            $this->assertSame(3, $s['total']);
            $this->assertTrue($s['open'], 'no closing date = open');
            $this->assertFalse(survey_load($closedId)['open']);
            $this->assertTrue(survey_load($todayId)['open'], 'closing day itself is still open');
            $this->assertSame(0, survey_load($closedId)['total']);
        } finally {
            foreach ([$sid, $closedId, $todayId] as $id) {
                q('DELETE FROM survey_answers WHERE survey_id = ?', [$id]);
                q('DELETE FROM survey_options WHERE survey_id = ?', [$id]);
                q('DELETE FROM surveys WHERE id = ?', [$id]);
            }
        }
    }

    // ---------- Revize a denní úlohy ----------

    public function testMaintenanceState(): void
    {
        $this->assertSame('', maintenance_state(null));
        $this->assertSame('', maintenance_state(''));
        $this->assertSame('overdue', maintenance_state(self::day(-1)));
        $this->assertSame('soon', maintenance_state(self::day(0)));
        $this->assertSame('soon', maintenance_state(self::day(10)));
        $this->assertSame('soon', maintenance_state(self::day(30)));
        $this->assertSame('', maintenance_state(self::day(31)));
    }

    /** Zachycené e-maily, jejichž (dekódované) tělo obsahuje značku. */
    private function tagged(string $tag): array
    {
        return array_values(array_filter($this->mails(), fn($m) => str_contains(quoted_printable_decode($m['body']), $tag)));
    }

    public function testDailyTasksRunOncePerDayAndRemindAdmins(): void
    {
        $tag = 'T' . bin2hex(random_bytes(3));
        $rows = ['soon' => self::day(10), 'late' => self::day(-3), 'far' => self::day(120)];
        $ids = [];
        foreach ($rows as $k => $d) {
            q('INSERT INTO maintenance (title, contractor, next_date) VALUES (?,?,?)', ["Revize $tag $k", $k === 'soon' ? 'Firma ' . $tag : '', $d]);
            $ids[$k] = (int) db()->lastInsertId();
        }
        $origLast = setting('last_daily');
        try {
            q("DELETE FROM settings WHERE `key` = 'last_daily'");
            setting('__reset');
            daily_tasks();
            setting('__reset');
            $this->assertSame(date('Y-m-d'), setting('last_daily'));

            $mails = $this->tagged($tag);
            $admins = array_column(all("SELECT email FROM users WHERE role = 'admin' AND active = 1"), 'email');
            $this->assertNotEmpty($admins);
            $this->assertCount(count($admins), $mails, 'one reminder per active admin');
            $this->assertEqualsCanonicalizing($admins, array_column($mails, 'to'));
            $body = quoted_printable_decode($mails[0]['body']);
            $this->assertStringStartsWith('Blíží se termín revize – ', mb_decode_mimeheader($mails[0]['subject']));
            $this->assertStringContainsString("- Revize $tag soon: " . cz_date($rows['soon']) . ", Firma $tag", $body);
            $this->assertStringContainsString("- Revize $tag late: " . cz_date($rows['late']) . ' (PO TERMÍNU)', $body);
            $this->assertStringNotContainsString("Revize $tag far", $body);
            $this->assertStringContainsString(absolute_url('/admin/revize'), $body);
            $this->assertSame($rows['soon'], val('SELECT reminded_for FROM maintenance WHERE id = ?', [$ids['soon']]));
            $this->assertSame($rows['late'], val('SELECT reminded_for FROM maintenance WHERE id = ?', [$ids['late']]));
            $this->assertNull(val('SELECT reminded_for FROM maintenance WHERE id = ?', [$ids['far']]));

            // Podruhé týž den nic.
            $before = count($this->mails());
            daily_tasks();
            $this->assertCount($before, $this->mails());

            // Další den (zde: smazané last_daily) se už připomenuté termíny znovu neposílají.
            q("DELETE FROM settings WHERE `key` = 'last_daily'");
            setting('__reset');
            daily_tasks();
            $again = $this->tagged($tag);
            $this->assertCount(count($admins), $again, 'already reminded terms are not repeated');

            // Posunutý termín se připomene znovu.
            q('UPDATE maintenance SET next_date = ? WHERE id = ?', [self::day(5), $ids['soon']]);
            q("DELETE FROM settings WHERE `key` = 'last_daily'");
            setting('__reset');
            daily_tasks();
            $again = $this->tagged($tag);
            $this->assertCount(2 * count($admins), $again);
            $this->assertStringContainsString("Revize $tag soon", quoted_printable_decode(end($again)['body']));
            $this->assertStringNotContainsString("Revize $tag late", quoted_printable_decode(end($again)['body']));
        } finally {
            q('DELETE FROM maintenance WHERE id IN (' . implode(',', $ids) . ')');
            q("DELETE FROM settings WHERE `key` = 'last_daily'");
            if ($origLast !== '') {
                q("INSERT INTO settings (`key`, value) VALUES ('last_daily', ?)", [$origLast]);
            }
            setting('__reset');
        }
    }

    // ---------- Kalendář ----------

    public function testIcsTextEscaping(): void
    {
        $this->assertSame('a\;b\,c\\\\d\ne\nf', ics_text("  a;b,c\\d\r\ne\nf  "));
        $this->assertSame('bez zmen', ics_text('bez zmen'));
        $this->assertSame('x', ics_text("x\r"));
    }

    private static function assertIcsLinesValid(string $out): void
    {
        self::assertStringEndsWith("\r\n", $out);
        foreach (explode("\r\n", substr($out, 0, -2)) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), 'line longer than 75 octets: ' . $line);
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), 'fold split a UTF-8 character');
            self::assertStringNotContainsString("\n", $line);
        }
    }

    public function testIcsFoldAscii(): void
    {
        $long = 'DESCRIPTION:' . str_repeat('abcdefghij', 20);
        $out = ics_fold(['SHORT:x', $long]);
        self::assertIcsLinesValid($out);
        $this->assertStringStartsWith("SHORT:x\r\n", $out);
        $this->assertSame("SHORT:x\r\n" . $long . "\r\n", str_replace("\r\n ", '', $out), 'unfolding restores the line');
        $this->assertSame(2, substr_count($out, "\r\n "), '212 octets = 73 + 73 + 66');
        $this->assertSame("\r\n", ics_fold(['']));
        $exact = str_repeat('x', 73);
        $this->assertSame($exact . "\r\n", ics_fold([$exact]), '73 octets are not folded');
    }

    public function testIcsFoldDoesNotSplitUtf8(): void
    {
        foreach (['', 'X', 'XY'] as $pad) {   // různé zarovnání vícebajtových znaků vůči hranici zlomu
            $line = 'SUMMARY:' . $pad . str_repeat('čšž€ř', 30);
            $out = ics_fold([$line]);
            self::assertIcsLinesValid($out);
            $this->assertSame($line . "\r\n", str_replace("\r\n ", '', $out));
        }
    }

    private static function event(array $over = []): array
    {
        return $over + ['id' => 4242, 'title' => 'Shromáždění; vlastníků', 'event_date' => '2027-03-10', 'event_time' => '18:00',
            'note' => 'Přineste plnou moc, prosím', 'program' => "- **Zahájení**\n- Rozpočet", 'place' => 'Sušárna, vchod A'];
    }

    public function testEventTimes(): void
    {
        [$s, $e, $all] = event_times(self::event());
        $this->assertFalse($all);
        $this->assertSame(strtotime('2027-03-10 18:00:00'), $s);
        $this->assertSame($s + 7200, $e);

        [$s] = event_times(self::event(['event_time' => 'od 9.15 hod.']));
        $this->assertSame(strtotime('2027-03-10 09:15:00'), $s);

        foreach (['', 'odpoledne', '25:00', '12:75'] as $t) {
            [$s, $e, $all] = event_times(self::event(['event_time' => $t]));
            $this->assertTrue($all, "'$t' is all-day");
            $this->assertSame(strtotime('2027-03-10 00:00:00'), $s);
            $this->assertSame(strtotime('2027-03-11 00:00:00'), $e);
        }
        // Přes změnu času (poslední neděle v březnu) je konec celodenního termínu stále další den o půlnoci.
        [$s, $e] = event_times(self::event(['event_date' => '2027-03-28', 'event_time' => '']));
        $this->assertSame('2027-03-29 00:00', date('Y-m-d H:i', $e));
    }

    public function testIcsCalendarStructure(): void
    {
        $timed = self::event();
        $allDay = self::event(['id' => 4243, 'title' => 'Odečty', 'event_time' => '', 'place' => '', 'program' => '', 'note' => '']);
        $out = ics_calendar([$timed, $allDay]);
        self::assertIcsLinesValid($out);
        $lines = explode("\r\n", rtrim(str_replace("\r\n ", '', $out), "\r\n"));
        $this->assertSame('BEGIN:VCALENDAR', $lines[0]);
        $this->assertSame('END:VCALENDAR', end($lines));
        $this->assertContains('VERSION:2.0', $lines);
        $this->assertContains('X-WR-CALNAME:' . ics_text(site('name')), $lines);
        $this->assertSame(2, count(array_keys($lines, 'BEGIN:VEVENT')));
        $this->assertSame(2, count(array_keys($lines, 'END:VEVENT')));
        $this->assertSame(1, count(array_keys($lines, 'BEGIN:VTIMEZONE')));
        $host = parse_url(absolute_url('/'), PHP_URL_HOST);
        $this->assertContains('UID:termin-4242@' . $host, $lines);
        $this->assertContains('UID:termin-4243@' . $host, $lines);
        $this->assertContains('DTSTART;TZID=Europe/Prague:20270310T180000', $lines);
        $this->assertContains('DTEND;TZID=Europe/Prague:20270310T200000', $lines);
        $this->assertContains('DTSTART;VALUE=DATE:20270310', $lines);
        $this->assertContains('DTEND;VALUE=DATE:20270311', $lines);
        $this->assertContains('SUMMARY:' . ics_text('Shromáždění; vlastníků – ' . site('name')), $lines);
        $this->assertContains('LOCATION:Sušárna\, vchod A', $lines);
        $this->assertSame(1, count(array_filter($lines, fn($l) => str_starts_with($l, 'LOCATION:'))), 'no LOCATION for an event without place');
        $desc = current(array_filter($lines, fn($l) => str_starts_with($l, 'DESCRIPTION:Přineste')));
        $this->assertStringContainsString('Program:\n- Zahájení\n- Rozpočet', $desc, 'program without ** marks');
        $this->assertStringContainsString(ics_text(absolute_url('/terminy/4242')), $desc);
        $this->assertSame(2, count(array_keys($lines, 'TRIGGER:-P1D')));
        $this->assertMatchesRegularExpression('/^DTSTAMP:\d{8}T\d{6}Z$/', current(array_filter($lines, fn($l) => str_starts_with($l, 'DTSTAMP:'))));
    }

    public function testGoogleAndOutlookCalendarUrls(): void
    {
        $url = google_calendar_url(self::event());
        $this->assertStringStartsWith('https://calendar.google.com/calendar/render?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('TEMPLATE', $q['action']);
        $this->assertSame('Shromáždění; vlastníků – ' . site('name'), $q['text']);
        $this->assertSame('20270310T180000/20270310T200000', $q['dates']);
        $this->assertSame('Europe/Prague', $q['ctz']);
        $this->assertSame("Přineste plnou moc, prosím\n" . absolute_url('/terminy/4242'), $q['details']);
        $this->assertSame('Sušárna, vchod A', $q['location']);

        parse_str((string) parse_url(google_calendar_url(self::event(['event_time' => ''])), PHP_URL_QUERY), $q);
        $this->assertSame('20270310/20270311', $q['dates']);

        $o = outlook_calendar_url(self::event(['event_time' => '']), 'outlook.live.com');
        $this->assertStringStartsWith('https://outlook.live.com/calendar/0/deeplink/compose?', $o);
        parse_str((string) parse_url($o, PHP_URL_QUERY), $q);
        $this->assertSame(['2027-03-10', '2027-03-11', 'true'], [$q['startdt'], $q['enddt'], $q['allday']]);
        $this->assertStringNotContainsString('+', $o, 'RFC 3986 encoding (spaces as %20)');
    }

    // ---------- Záloha ----------

    public function testBackupRestoreRoundtrip(): void
    {
        $snap = backup_data();
        $this->assertSame('svj-backup-1', $snap['format']);
        $this->assertSame(BACKUP_TABLES, array_keys($snap['tables']));
        $countsBefore = array_map('count', $snap['tables']);
        $this->assertGreaterThan(0, $countsBefore['units']);
        $this->assertGreaterThan(0, $countsBefore['posts']);

        try {
            // Změna, kterou má obnova vrátit.
            q("INSERT INTO contacts (grp, role, name, phone, email, note, sort) VALUES ('vybor', '', 'Smazat po obnově', '', '', '', 999)");
            q('DELETE FROM posts WHERE id = ?', [(int) $snap['tables']['posts'][0]['id']]);

            $counts = restore_data($snap);
        } finally {
            if (!isset($counts)) {
                restore_data($snap);   // test selhal uprostřed – data vrátit v každém případě
            }
            setting('__reset');
        }
        $this->assertSame($countsBefore, $counts);
        $after = backup_data();
        foreach (BACKUP_TABLES as $t) {
            $a = $snap['tables'][$t];
            $b = $after['tables'][$t];
            if ($t === 'settings') {
                // číslo verze struktury patří k databázi, ne k záloze
                $strip = fn($rows) => array_values(array_filter($rows, fn($r) => $r['key'] !== 'schema_version'));
                [$a, $b] = [$strip($a), $strip($b)];
            }
            $this->assertEquals($a, $b, "table $t restored exactly");
        }
        $this->assertSame(0, (int) val("SELECT COUNT(*) FROM contacts WHERE name = 'Smazat po obnově'"));
    }

    public function testRestoreRejectsForeignFile(): void
    {
        $units = (int) val('SELECT COUNT(*) FROM units');
        try {
            restore_data(['format' => 'neco-jineho', 'tables' => ['units' => []]]);
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('Soubor není záloha tohoto webu.', $e->getMessage());
        }
        $this->assertSame($units, (int) val('SELECT COUNT(*) FROM units'), 'nothing deleted');
    }

    public function testPrivacyDefaultText(): void
    {
        $this->assertNotSame('', trim(PRIVACY_DEFAULT));
        foreach (['## Kdo údaje zpracovává', '## Cookies', '## Vaše práva a jak je uplatnit', 'www.uoou.cz', 'Anthropic'] as $needle) {
            $this->assertStringContainsString($needle, PRIVACY_DEFAULT);
        }
    }
}
