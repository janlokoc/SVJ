<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Units, shares, attendance, cadastre/user import, settings and e-mail helpers from app/lib.php. */
final class LibDataTest extends TestCase
{
    /** @var array<string, ?string> original values of settings changed by a test */
    private array $savedSettings = [];

    protected function tearDown(): void
    {
        foreach ($this->savedSettings as $key => $value) {
            q('DELETE FROM settings WHERE `key` = ?', [$key]);
            if ($value !== null) {
                q('INSERT INTO settings (`key`, value) VALUES (?, ?)', [$key, $value]);
            }
        }
        $this->savedSettings = [];
        setting('__reset');
        parent::tearDown();
    }

    private function setSetting(string $key, ?string $value): void
    {
        if (!array_key_exists($key, $this->savedSettings)) {
            $old = one('SELECT value FROM settings WHERE `key` = ?', [$key]);
            $this->savedSettings[$key] = $old ? (string) $old['value'] : null;
        }
        q('DELETE FROM settings WHERE `key` = ?', [$key]);
        if ($value !== null) {
            q('INSERT INTO settings (`key`, value) VALUES (?, ?)', [$key, $value]);
        }
        setting('__reset');
    }

    // ---------- Shares ----------

    public function testShareValueVariants(): void
    {
        $this->assertEqualsWithDelta(0.00652, share_value('652/100000'), 1e-12);
        $this->assertEqualsWithDelta(0.5, share_value(' 1 / 2 '), 1e-12);
        $this->assertEqualsWithDelta(0.0065, share_value('0,65 %'), 1e-12);
        $this->assertEqualsWithDelta(0.0065, share_value('0.65%'), 1e-12);
        $this->assertEqualsWithDelta(0.25, share_value('2,5/10'), 1e-12, 'decimal numerator');
        $this->assertEqualsWithDelta(0.12, share_value('12 %'), 1e-12);
        foreach (['', 'abc', '1/0', '-1/2', '1/2/3', '65', '%', '1/2 + 1/4'] as $bad) {
            $this->assertSame(0.0, share_value($bad), "'$bad' is unreadable");
        }
    }

    public function testSharesGapAndReady(): void
    {
        $before = shares_gap();
        $this->assertSame((int) val('SELECT COUNT(*) FROM units'), $before['units']);
        $number = 'gap' . random_int(1000, 9999);
        q("INSERT INTO units (entrance, number, share) VALUES ('Z', ?, '')", [$number]);
        $id = (int) db()->lastInsertId();
        q("INSERT INTO units (entrance, number, share) VALUES ('Z', ?, 'nečitelné')", [$number . 'b']);
        $id2 = (int) db()->lastInsertId();
        try {
            $gap = shares_gap();
            $this->assertSame($before['units'] + 2, $gap['units']);
            $this->assertSame($before['missing'] + 2, $gap['missing']);
            $this->assertFalse(shares_ready());
            q("UPDATE units SET share = '1/1000' WHERE id IN (?, ?)", [$id, $id2]);
            $this->assertSame($before['missing'], shares_gap()['missing']);
            $this->assertSame($before['missing'] === 0, shares_ready());
        } finally {
            q('DELETE FROM units WHERE id IN (?, ?)', [$id, $id2]);
        }
    }

    // ---------- Units ----------

    public function testUnitPrefixAndUnitName(): void
    {
        $this->assertSame('12', unit_prefix());
        $this->assertSame('12A/5', unit_name(['entrance' => 'A', 'number' => '5']));
        $this->assertSame('12/7', unit_name(['entrance' => '', 'number' => '7']));

        $this->setSetting('site_unit_prefix', 'č.p. <b>1200</b>&quot;12345');
        $this->assertSame('č.p. b1200', unit_prefix(), 'markup characters stripped, max 10 chars');
    }

    public function testEntrances(): void
    {
        $this->assertSame(['', 'A', 'B'], entrances());
        $this->setSetting('site_entrances', ' a, b ,A, toolong, C!, 1 , č');
        $this->assertSame(['', 'A', 'B', '1', 'Č'], entrances(), 'uppercased, deduplicated, invalid dropped');
        $this->setSetting('site_entrances', implode(',', range(1, 40)));
        $this->assertCount(27, entrances(), 'at most 26 entrances plus the empty one');
    }

    public function testUnitsSqlListsUserUnits(): void
    {
        $email = 'units-sql-' . bin2hex(random_bytes(3)) . '@example.cz';
        q("INSERT INTO users (email, name, password_hash) VALUES (?, 'Test Jednotky', '!')", [$email]);
        $uid = (int) db()->lastInsertId();
        try {
            $a1 = (int) val("SELECT id FROM units WHERE entrance = 'A' AND number = '1'");
            $b2 = (int) val("SELECT id FROM units WHERE entrance = 'B' AND number = '2'");
            q('INSERT INTO user_units (user_id, unit_id) VALUES (?, ?), (?, ?)', [$uid, $a1, $uid, $b2]);
            $names = explode(', ', (string) val('SELECT ' . units_sql('u') . ' FROM users u WHERE u.id = ?', [$uid]));
            sort($names);
            $this->assertSame(['12A/1', '12B/2'], $names);
            $ids = user_unit_ids($uid);
            sort($ids);
            $this->assertSame([$a1, $b2], $ids);
            $this->assertNull(val('SELECT ' . units_sql('u') . ' FROM users u WHERE u.id = 1 AND 0') ?: null);
        } finally {
            q('DELETE FROM user_units WHERE user_id = ?', [$uid]);
            q('DELETE FROM users WHERE id = ?', [$uid]);
        }
    }

    // ---------- Attendance ----------

    public function testAttendanceWeightsByShares(): void
    {
        q("INSERT INTO events (title, event_date, rsvp) VALUES ('Unit test shromáždění', '2030-01-15', 1)");
        $eventId = (int) db()->lastInsertId();
        try {
            $big = one("SELECT * FROM units WHERE entrance = 'B' AND number = '2'");     // 1010/10000
            $small = one("SELECT * FROM units WHERE entrance = 'A' AND number = '3'");   // 640/10000
            $absent = one("SELECT * FROM units WHERE entrance = 'A' AND number = '1'");
            q("INSERT INTO event_rsvp (event_id, unit_id, user_id, status) VALUES (?, ?, 1, 'yes')", [$eventId, $big['id']]);
            q("INSERT INTO event_rsvp (event_id, unit_id, user_id, status, proxy_name) VALUES (?, ?, 1, 'proxy', 'Zmocněnec')", [$eventId, $small['id']]);
            q("INSERT INTO event_rsvp (event_id, unit_id, user_id, status) VALUES (?, ?, 1, 'no')", [$eventId, $absent['id']]);

            $a = attendance($eventId);
            $units = (int) val('SELECT COUNT(*) FROM units');
            $this->assertSame($units, $a['units']);
            $this->assertSame(['yes' => 1, 'proxy' => 1, 'no' => 1, 'none' => $units - 3], $a['counts']);
            $this->assertSame(shares_ready(), $a['byShare']);

            $total = array_sum(array_column($a['rows'], 'weight'));
            $expected = $a['byShare'] ? (share_value($big['share']) + share_value($small['share'])) / $total * 100 : 2 / $units * 100;
            $this->assertEqualsWithDelta($expected, $a['percent'], 1e-9);
            if ($a['byShare']) {
                $this->assertEqualsWithDelta(16.5, $a['percent'] * $total, 1e-9, '(1010 + 640) / 10000 of the house');
            }
            $this->assertSame(50, $a['threshold']);
            $this->assertFalse($a['quorate']);
            $row = array_values(array_filter($a['rows'], fn($r) => (int) $r['id'] === (int) $small['id']))[0];
            $this->assertSame('proxy', $row['status']);
            $this->assertSame('Zmocněnec', $row['proxy_name']);

            // Everyone else says yes → quorate.
            q("INSERT INTO event_rsvp (event_id, unit_id, status) SELECT ?, id, 'yes' FROM units WHERE id NOT IN (SELECT unit_id FROM event_rsvp WHERE event_id = ?)", [$eventId, $eventId]);
            $a = attendance($eventId);
            $this->assertTrue($a['quorate']);
            $this->assertSame(0, $a['counts']['none']);
        } finally {
            q('DELETE FROM event_rsvp WHERE event_id = ?', [$eventId]);
            q('DELETE FROM events WHERE id = ?', [$eventId]);
        }
    }

    public function testAttendanceFallsBackToEqualWeightsWithoutShares(): void
    {
        q("INSERT INTO events (title, event_date) VALUES ('Unit test bez podílů', '2030-02-01')");
        $eventId = (int) db()->lastInsertId();
        q("INSERT INTO units (entrance, number, share) VALUES ('Z', ?, '')", ['nos' . random_int(1000, 9999)]);
        $unitId = (int) db()->lastInsertId();
        $this->setSetting('quorum_percent', '5');
        try {
            q("INSERT INTO event_rsvp (event_id, unit_id, status) VALUES (?, ?, 'yes')", [$eventId, $unitId]);
            $a = attendance($eventId);
            $this->assertFalse($a['byShare']);
            foreach ($a['rows'] as $r) {
                $this->assertSame(1.0, $r['weight']);
            }
            $this->assertEqualsWithDelta(100 / $a['units'], $a['percent'], 1e-9);
            $this->assertSame(5, $a['threshold']);
            $this->assertSame($a['percent'] > 5, $a['quorate']);
        } finally {
            q('DELETE FROM event_rsvp WHERE event_id = ?', [$eventId]);
            q('DELETE FROM events WHERE id = ?', [$eventId]);
            q('DELETE FROM units WHERE id = ?', [$unitId]);
        }
    }

    public function testAttendanceOfUnknownEventHasNoResponses(): void
    {
        $a = attendance(987654321);
        $this->assertSame($a['units'], $a['counts']['none']);
        $this->assertSame(0.0, $a['percent']);
        $this->assertFalse($a['quorate']);
    }

    public function testGcdInt(): void
    {
        $this->assertSame(6, gcd_int(54, 24));
        $this->assertSame(6, gcd_int(24, 54));
        $this->assertSame(7, gcd_int(7, 0));
        $this->assertSame(1, gcd_int(0, 0), 'never returns 0 (used as divisor)');
        $this->assertSame(1, gcd_int(17, 5));
        $this->assertSame(4, gcd_int(-8, 12));
    }

    // ---------- Cadastre import ----------

    /** Text as copied (Ctrl+A, Ctrl+C) from the building page of nahlizenidokn.cuzk.gov.cz. */
    private const CADASTRE = <<<'TXT'
Informace o stavbě
Součástí je stavba:	č. p. 1200
Vymezené jednotky
1200/1, 1200/2, 1200/3, 1200/4, 1200/5, 1200/6
Vlastníci, jiní oprávnění
Vlastnické právo	Podíl
Dvořák Karel, Zahradní 1200/12, Veveří, 60200 Brno
Jednotka: 1200/1	1100/10000
SJ Malý Tomáš a Malá Jana, Zahradní 1200/12, Veveří, 60200 Brno
Jednotka: 1200/2	1650/10000
Horáková Eva, Lipová 15, Žabovřesky, 61600 Brno
Jednotka: 1200/3	1650/10000
Kučera Martin, Zahradní 1200/12, Veveří, 60200 Brno
Jednotka: 1200/4	1650/10000
Svobodová Alena, Zahradní 1200/12, Veveří, 60200 Brno
Jednotka: 1200/5	1650/10000
SJ Novák Petr a Nováková Marie, Zahradní 1200/12, Veveří, 60200 Brno
Jednotka: 1200/6	2300/10000
Způsob ochrany nemovitosti
Nejsou evidovány žádné způsoby ochrany.
TXT;

    public function testParseCadastreSample(): void
    {
        $r = parse_cadastre(self::CADASTRE);
        $this->assertSame([], $r['warnings']);
        $this->assertSame([], $r['by_cp']);
        $this->assertSame(['1200/1', '1200/2', '1200/3', '1200/4', '1200/5', '1200/6'], array_keys($r['units']));
        $this->assertEqualsWithDelta(1.0, $r['total'], 1e-12, 'shares add up to 100 %');

        $u1 = $r['units']['1200/1'];
        $this->assertSame(1200, $u1['cp']);
        $this->assertSame('1', $u1['number']);
        $this->assertSame('A', $u1['entrance'], 'single house number maps to the first entrance');
        $this->assertSame('11/100', $u1['share'], 'share is reduced');
        $this->assertSame('Dvořák Karel', $u1['owner_note']);
        $this->assertSame('Dvořák Karel, Zahradní 1200/12, Veveří, 60200 Brno', $u1['owner_contact']);
        $this->assertSame('SJ Malý Tomáš a Malá Jana', $r['units']['1200/2']['owner_note']);
        $this->assertSame('33/200', $r['units']['1200/2']['share']);
        $this->assertSame('23/100', $r['units']['1200/6']['share']);
    }

    public function testParseCadastreMarkdownLinksCoOwnersAndJointShare(): void
    {
        $text = <<<'TXT'
Vlastníci, jiní oprávnění
Vlastnické právo	Podíl
[Bílá Anna](https://nahlizenidokn.cuzk.gov.cz/x), Hlavní 1, 11000 Praha
Jednotka: [500/1](https://nahlizenidokn.cuzk.gov.cz/j1)	300/1000
Bílý Jan, Hlavní 1, 11000 Praha
Jednotka: 500/1	300/1000
SJ Černý Ivan a Černá Olga
Jednotka: 500/2	200/1000
Černý Ivan, Nová 5, 11000 Praha
Černá Olga, Stará 7, 12000 Praha
Zelený Petr, Hlavní 1, 11000 Praha
Jednotky: 500/3, 500/4	200/1000
Šedá Lenka, Hlavní 1, 11000 Praha
Jednotka: 500/5
TXT;
        $r = parse_cadastre($text);
        $u = $r['units'];
        $this->assertSame(['500/1', '500/2', '500/3', '500/4'], array_keys($u));

        // Two co-owners of one unit: shares are summed.
        $this->assertSame('3/5', $u['500/1']['share']);
        $this->assertCount(2, $u['500/1']['owners']);
        $this->assertSame('Bílá Anna (300/1000); Bílý Jan (300/1000)', $u['500/1']['owner_note']);
        $this->assertSame("Bílá Anna, Hlavní 1, 11000 Praha\nBílý Jan, Hlavní 1, 11000 Praha", $u['500/1']['owner_contact']);

        // SJM without common address: both following address lines are collected.
        $this->assertSame('SJ Černý Ivan a Černá Olga | Černý Ivan, Nová 5, 11000 Praha | Černá Olga, Stará 7, 12000 Praha', $u['500/2']['owner_contact']);
        $this->assertSame('1/5', $u['500/2']['share']);

        // One share for two units is split equally, with a warning.
        $this->assertSame('1/10', $u['500/3']['share']);
        $this->assertSame('1/10', $u['500/4']['share']);
        $joint = array_values(array_filter($r['warnings'], fn($w) => str_starts_with($w, 'Zelený Petr')));
        $this->assertCount(1, $joint);
        $this->assertStringContainsString('500/3, 500/4', $joint[0]);

        // A unit line without a share is reported, not imported.
        $this->assertCount(1, array_filter($r['warnings'], fn($w) => str_contains($w, 'Jednotka: 500/5')));
        $this->assertEqualsWithDelta(1.0, $r['total'], 1e-12);
    }

    public function testParseCadastreMoreHouseNumbersThanEntrances(): void
    {
        $text = "Vlastnické právo\tPodíl\n"
            . "Jedna Eva, Ulice 1, Brno\nJednotka: 301/1\t1/3\n"
            . "Dva Jan, Ulice 2, Brno\nJednotka: 302/1\t1/3\n"
            . "Tři Ota, Ulice 3, Brno\nJednotka: 303/1\t1/3\n";
        $r = parse_cadastre($text);
        $this->assertSame(['301', '302', '303'], $r['by_cp'], 'three house numbers, only two entrances configured');
        $this->assertSame(['301', '302', '303'], array_column(array_values($r['units']), 'entrance'));

        // Two house numbers fit the two entrances A, B in ascending order.
        $r = parse_cadastre("Vlastnické právo\nX Y, a\nJednotka: 20/1\t1/2\nZ W, b\nJednotka: 10/1\t1/2\n");
        $this->assertSame([], $r['by_cp']);
        $this->assertSame('A', $r['units']['10/1']['entrance']);
        $this->assertSame('B', $r['units']['20/1']['entrance']);
        $this->assertSame(['10/1', '20/1'], array_keys($r['units']), 'sorted by house number');
    }

    public function testParseCadastreGarbage(): void
    {
        $r = parse_cadastre("nic tu není\n\n");
        $this->assertSame([], $r['units']);
        $this->assertSame([], $r['warnings']);
        $this->assertSame(0.0, $r['total']);
    }

    // ---------- User import ----------

    public function testParseUserImport(): void
    {
        $new = 'import-' . bin2hex(random_bytes(3)) . '@example.cz';
        $text = "Jméno\tPříjmení\tTelefon\tE-mail\tVztah\tJednotka\n"
            . "Karel\tNový\t777 111 222\t$new\tvlastník\t12A/1, B 2\n"
            . "Olga;Nájemná;;olga-$new;nájemník;A/99\n"
            . "Petr,Novák,,petr.novak@example.cz,vlastník,\n"
            . "Bez,Mailu,,neplatny-email,vlastník,\n"
            . ";;;x-$new;;\n"
            . "Dvojník\tNový\t\t" . strtoupper($new) . "\tT\tbyt\n";
        $rows = parse_user_import($text);
        $this->assertCount(6, $rows, 'header skipped');

        [$karel, $olga, $petr, $bad, $noname, $dup] = $rows;
        $this->assertSame(2, $karel['line']);
        $this->assertSame('ok', $karel['status']);
        $this->assertSame('Karel Nový', $karel['name']);
        $this->assertSame('777 111 222', $karel['phone']);
        $this->assertSame('owner', $karel['kind']);
        $this->assertSame(['A/1', 'B/2'], array_map(fn($u) => $u['entrance'] . '/' . $u['number'], $karel['units']));

        $this->assertSame('ok', $olga['status'], 'semicolon separated');
        $this->assertSame('tenant', $olga['kind']);
        $this->assertSame([], $olga['units']);
        $this->assertStringContainsString('Jednotka 12A/99 v seznamu jednotek není.', $olga['message']);

        $this->assertSame('exists', $petr['status']);
        $this->assertSame('error', $bad['status']);
        $this->assertSame('E-mail nemá správný tvar.', $bad['message']);
        $this->assertSame('error', $noname['status']);
        $this->assertSame('Chybí jméno.', $noname['message']);
        $this->assertSame('error', $dup['status']);
        $this->assertStringContainsString('dvakrát', $dup['message']);
        $this->assertSame('tenant', $dup['kind']);
        $this->assertStringContainsString('Jednotku „byt“ se nepodařilo přečíst', $dup['message']);
    }

    // ---------- Settings & features ----------

    public function testSettingHelpers(): void
    {
        $k = 'unit_test_' . bin2hex(random_bytes(3));
        $this->assertSame('', setting($k));
        $this->assertSame('dflt', setting($k, 'dflt'));
        $this->assertTrue(setting_on($k));
        $this->assertFalse(setting_on($k, false));
        $this->assertSame(7, setting_int($k, 7, 1, 10));

        $this->setSetting($k, '0');
        $this->assertSame('0', setting($k));
        $this->assertFalse(setting_on($k));
        $this->assertSame(1, setting_int($k, 7, 1, 10), 'clamped to min');

        $this->setSetting($k, '1');
        $this->assertTrue(setting_on($k, false));
        $this->setSetting($k, 'yes');
        $this->assertFalse(setting_on($k), "only '1' means on");
        $this->assertSame(7, setting_int($k, 7, 1, 10), 'non-numeric → default');
        $this->setSetting($k, '500');
        $this->assertSame(10, setting_int($k, 7, 1, 10), 'clamped to max');
        $this->setSetting($k, '-3');
        $this->assertSame(7, setting_int($k, 7, 1, 10), 'negative is not digits → default');

        // Cache: a direct DB change is only visible after reset.
        q('UPDATE settings SET value = ? WHERE `key` = ?', ['4', $k]);
        $this->assertSame('-3', setting($k));
        setting('__reset');
        $this->assertSame(4, setting_int($k, 7, 1, 10));
    }

    public function testFeatureOn(): void
    {
        $this->setSetting('hidden_features', null);
        $this->assertTrue(feature_on('forum'));
        $this->setSetting('hidden_features', 'forum,,polls');
        $this->assertFalse(feature_on('forum'));
        $this->assertFalse(feature_on('polls'));
        $this->assertTrue(feature_on('documents'));
        $this->assertTrue(feature_on('for'), 'exact match only');
    }

    // ---------- Documents ----------

    public function testExternalDocExt(): void
    {
        $this->assertSame('pdf', external_doc_ext(['original_name' => 'Zápis 2026.PDF', 'external_url' => 'https://x.cz/a.doc']));
        $this->assertSame('docx', external_doc_ext(['original_name' => '', 'external_url' => 'https://x.cz/a/b.docx?x=1.pdf']));
        $this->assertSame('xls', external_doc_ext(['external_url' => 'https-failed://x.cz/f.xls']));
        $this->assertSame('xls', external_doc_ext(['external_url' => 'failed:https://x.cz/f.xls']));
        $this->assertSame('', external_doc_ext(['external_url' => 'https://x.cz/stahnout']));
        $this->assertSame('', external_doc_ext([]));
    }

    // ---------- Mail ----------

    public function testMailTextHtml(): void
    {
        $html = mail_text_html("Dobrý den,\r\nřádek 2\n\nViz https://example.cz/a. <b>x</b>");
        $this->assertStringContainsString('<p style="margin:0 0 14px">Dobrý den,<br>' . "\n" . 'řádek 2</p>', $html);
        $this->assertStringContainsString('<a href="https://example.cz/a">https://example.cz/a</a>.', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringStartsWith('<!doctype html>', $html);
    }

    public function testBuildMailWithoutTracking(): void
    {
        [$text, $html] = build_mail("Dobrý den,\n**Důležité:** viz http://dum.test.invalid/akce/5", null);
        $this->assertStringContainsString('Důležité: viz http://dum.test.invalid/akce/5', $text, '** removed in plain text');
        $this->assertStringContainsString('Zprávu posílá výbor SVJ Zahradní 12', $text);
        $this->assertStringContainsString('http://dum.test.invalid/muj-ucet', $text);
        $this->assertStringNotContainsString('/e/', $text);
        $this->assertStringContainsString('<strong>Důležité:</strong>', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function testBuildMailWithTrackingRewritesOnlyOwnLinks(): void
    {
        [$text, $html] = build_mail("Viz http://dum.test.invalid/akce/5 a https://www.example.org/x.\nhttp://dum.test.invalid", 'tok123');
        $this->assertStringContainsString('http://dum.test.invalid/e/tok123?u=%2Fakce%2F5', $text);
        $this->assertStringContainsString('http://dum.test.invalid/e/tok123?u=%2F', $text, 'bare site URL tracks to /');
        $this->assertStringContainsString('https://www.example.org/x.', $text, 'foreign links untouched');
        $this->assertStringContainsString('Web eviduje otevření zprávy', $text);
        $this->assertStringContainsString('href="http://dum.test.invalid/e/tok123?u=%2Fakce%2F5"', $html);
        $this->assertStringContainsString('href="https://www.example.org/x"', $html);
        $this->assertStringContainsString('<img src="http://dum.test.invalid/e/tok123.gif"', $html);
    }

    public function testSendMailIsCapturedWithRecipientAndSubject(): void
    {
        $to = 'mail-' . bin2hex(random_bytes(3)) . '@example.cz';
        $this->assertTrue(send_mail($to, "Pozvánka\r\nBcc: evil@example.org na  schůzi", "Dobrý den,\nodkaz: https://example.cz/x"));
        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $m = $mails[0];
        $this->assertSame($to, $m['to']);
        $subject = mb_decode_mimeheader($m['subject']);
        $this->assertSame('Pozvánka Bcc: evil@example.org na schůzi', $subject, 'newlines collapsed (no header injection)');
        $this->assertStringNotContainsString("\n", $subject);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $m['body']);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $m['body']);
        $this->assertStringContainsString('Dobr=C3=BD den,', $m['body'], 'quoted-printable text part');
        $this->assertSame('capture', $GLOBALS['MAIL_LAST']);
    }

    public function testSendMailRejectsInvalidAddresses(): void
    {
        $this->assertFalse(send_mail('not-an-email', 'x', 'y'));
        $this->assertFalse(send_mail('ok@example.cz', 'x', 'y', null, 'bad-from'));
        $this->assertSame([], $this->mails());
    }

    // ---------- Positions / authors ----------

    public function testPositionLabel(): void
    {
        $this->assertSame('Předseda výboru', position_label(['position' => 'predseda']));
        $this->assertSame('', position_label(['position' => '']));
        $this->assertSame('', position_label(['position' => 'neznama']));
        $this->assertSame('Předseda výboru', position_label(['id' => 1]), 'looked up by id (demo admin is chair)');
        $this->assertSame('', position_label(['id' => 2]));
        $this->assertSame('', position_label([]));
        $this->assertSame(' <span class="tag tag-position">Člen výboru</span>', position_tag(['position' => 'clen_vyboru']));
        $this->assertSame('', position_tag(['position' => '']));
    }

    public function testAuthorLabel(): void
    {
        $this->assertSame('Bývalý uživatel', author_label(null));
        $this->assertSame('Bývalý uživatel', author_label(['author_name' => '']));
        $this->assertSame('Jana Výborová (12A/1) · předseda výboru', author_label(['author_name' => 'Jana Výborová', 'author_id' => 1, 'author_units' => '12A/1']));
        $this->assertSame('Petr Novák', author_label(['author_name' => 'Petr Novák', 'user_id' => 2]));
    }
}
