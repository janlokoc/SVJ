<?php
declare(strict_types=1);

namespace {
    // Náhradní SMS brána pro testy (skutečnou dodává jiný modul; pokud je načtená, testy SMS se přeskočí).
    if (!function_exists('sms_send')) {
        define('MODB_FAKE_SMS', true);
        function sms_send(string $phone, string $text): bool
        {
            $GLOBALS['MODB_SMS'][] = [$phone, $text];
            return empty($GLOBALS['MODB_SMS_FAIL']);
        }
    }
}

namespace Tests\Unit {

use Tests\TestCase;

/** Modul B: ověření hlasu kódem, záznamy s řetězem otisků, listinné hlasy, protokol (app/ext/rollam_lib.php). */
final class ModBRollamTest extends TestCase
{
    private array $units = [];
    private array $users = [];
    private int $poll = 0;
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        rollam_ready();
        $GLOBALS['MODB_SMS'] = [];
        unset($GLOBALS['MODB_SMS_FAIL']);
        $tag = bin2hex(random_bytes(3));
        foreach (['Novák Jan', 'Svobodová Eva'] as $i => $owner) {
            q('INSERT INTO units (entrance, number, share, owner_note) VALUES (?,?,?,?)', ['T' . $tag, (string) ($i + 1), '1/10', $owner]);
            $this->units[] = (int) db()->lastInsertId();
            q("INSERT INTO users (email, name, password_hash, role, kind, phone) VALUES (?,?,?,'resident','owner','+420 777 123 456')", ["modb$i-$tag@example.cz", $i ? 'Eva Svobodová' : 'Jan Novák', NO_PASSWORD]);
            $this->users[] = (int) db()->lastInsertId();
            q('INSERT INTO user_units (user_id, unit_id) VALUES (?,?)', [end($this->users), end($this->units)]);
        }
        q('INSERT INTO polls (title, body, opens_at, closes_at, threshold, online, published) VALUES (?,?,?,?,?,1,1)',
            ['Výměna výtahu ' . $tag, 'Odůvodnění', date('Y-m-d', time() - 86400), date('Y-m-d', time() + 20 * 86400), 50]);
        $this->poll = (int) db()->lastInsertId();
        foreach (['Schvaluje se výměna výtahu.', 'Schvaluje se úvěr.'] as $n => $question) {
            q('INSERT INTO poll_items (poll_id, question, sort) VALUES (?,?,?)', [$this->poll, $question, $n]);
            $this->items[] = (int) db()->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        q('DELETE FROM poll_votes WHERE item_id IN (SELECT id FROM poll_items WHERE poll_id = ?)', [$this->poll]);
        q('DELETE FROM poll_items WHERE poll_id = ?', [$this->poll]);
        q('DELETE FROM polls WHERE id = ?', [$this->poll]);
        foreach (['rollam_records', 'rollam_pending', 'rollam_polls'] as $t) {
            q("DELETE FROM $t WHERE poll_id = ?", [$this->poll]);
        }
        foreach ($this->users as $id) {
            q('DELETE FROM rollam_identities WHERE user_id = ?', [$id]);
            q('DELETE FROM user_units WHERE user_id = ?', [$id]);
            q('DELETE FROM users WHERE id = ?', [$id]);
        }
        foreach ($this->units as $id) {
            q('DELETE FROM units WHERE id = ?', [$id]);
        }
        $this->units = $this->users = $this->items = [];
    }

    private function poll(): array
    {
        return one('SELECT * FROM polls WHERE id = ?', [$this->poll]);
    }
    private function user(int $i = 0): array
    {
        return one('SELECT * FROM users WHERE id = ?', [$this->users[$i]]);
    }
    private function choices(string $a = 'yes', string $b = 'no'): array
    {
        return [$this->items[0] => $a, $this->items[1] => $b];
    }
    private function setMethod(string $m): void
    {
        q('INSERT INTO rollam_polls (poll_id, verify) VALUES (?,?) ON CONFLICT(poll_id) DO UPDATE SET verify = excluded.verify', [$this->poll, $m]);
    }
    private function votes(int $unit): array
    {
        return rollam_unit_votes($this->poll, $unit);
    }
    /** Kód z posledního zachyceného e-mailu. */
    private function mailedCode(): string
    {
        $m = $this->mails();
        $this->assertNotEmpty($m, 'code e-mail sent');
        $subject = mb_decode_mimeheader(end($m)["subject"]);
        $this->assertMatchesRegularExpression("/(\d{6})/", $subject);
        preg_match("/(\d{6})/", $subject, $mm);
        return $mm[1];
    }

    // ---------- nastavení ----------

    public function testMethodDefaultsToNoneAndValidatesAvailability(): void
    {
        $this->assertSame('none', rollam_method($this->poll), 'older polls keep the previous behaviour');
        $this->assertNull(rollam_set_method($this->poll, 'email'));
        $this->assertSame('email', rollam_method($this->poll));
        $this->assertArrayNotHasKey('bankid', rollam_methods_available(), 'Bank iD is a beta feature, off by default');
        $this->assertNotNull(rollam_set_method($this->poll, 'bankid'), 'unavailable method rejected');
        $this->assertSame('email', rollam_method($this->poll), 'previous setting kept');
        $this->assertNotNull(rollam_set_method($this->poll, 'bogus'));
        $this->assertArrayHasKey('sms', rollam_methods_available(), 'sms offered when sms_send() exists');
    }

    public function testPollSavedHookReadsPostAndFlashesOnError(): void
    {
        $_POST['verify'] = 'email';
        rollam_poll_saved($this->poll);
        $this->assertSame('email', rollam_method($this->poll));
        $_POST['verify'] = 'bankid';
        rollam_poll_saved($this->poll);
        $this->assertSame('email', rollam_method($this->poll));
        $this->assertStringContainsString('není k dispozici', flash()['msg']);
        $_POST = [];
        rollam_poll_saved($this->poll);   // formulář bez pole (např. starší šablona) nic nemění
        $this->assertSame('email', rollam_method($this->poll));
    }

    public function testWarningsForShortDeadlineAndLowThreshold(): void
    {
        $p = $this->poll();
        $this->assertSame([], rollam_warnings($p));
        $w = rollam_warnings(['opens_at' => '2026-01-01', 'closes_at' => '2026-01-10', 'threshold' => 30]);
        $this->assertCount(2, $w);
        $this->assertStringContainsString('10 dní', $w[0]);
        $this->assertStringContainsString('§ 1214', $w[1]);
        $this->assertSame([], rollam_warnings(['opens_at' => '2026-01-01', 'closes_at' => '2026-01-15', 'threshold' => 50]), '15 days inclusive is enough');
    }

    // ---------- hlasování bez ověření ----------

    public function testNoVerificationStoresImmediatelyWithRecord(): void
    {
        $r = rollam_begin($this->poll(), $this->user(), $this->units[0], $this->choices());
        $this->assertSame('stored', $r['status']);
        $this->assertSame([$this->items[0] => 'yes', $this->items[1] => 'no'], $this->votes($this->units[0]));
        $rec = rollam_records($this->poll);
        $this->assertCount(1, $rec);
        $this->assertSame('web', $rec[0]['method']);
        $this->assertSame('127.0.0.1', $rec[0]['ip']);
        $this->assertSame('Jan Novák', $rec[0]['voter_name']);
        $this->assertStringContainsString('přihlášení', $rec[0]['verified']);
        $again = rollam_begin($this->poll(), $this->user(), $this->units[0], $this->choices('no', 'no'));
        $this->assertSame('error', $again['status'], 'second vote for the same unit refused');
        $this->assertSame('yes', $this->votes($this->units[0])[$this->items[0]]);
    }

    public function testCommitIgnoresForeignItemsAndInvalidChoices(): void
    {
        $r = rollam_commit($this->poll, $this->units[0], [$this->items[0] => 'yes', 999999 => 'yes', $this->items[1] => 'maybe'], 'web', []);
        $this->assertIsInt($r);
        $this->assertSame([$this->items[0] => 'yes'], $this->votes($this->units[0]));
        $this->assertSame('Hlas neobsahuje žádnou volbu.', rollam_commit($this->poll, $this->units[1], [999999 => 'yes'], 'web', []));
    }

    // ---------- ověření kódem z e-mailu ----------

    public function testEmailCodeFlowStoresOnlyAfterConfirmation(): void
    {
        $this->setMethod('email');
        $r = rollam_begin($this->poll(), $this->user(), $this->units[0], $this->choices());
        $this->assertSame('code', $r['status']);
        $this->assertSame([], $this->votes($this->units[0]), 'nothing stored before confirmation');
        $mail = $this->mails()[0];
        $this->assertSame($this->user()['email'], $mail['to']);
        $this->assertStringContainsString("Schvaluje se výměna výtahu. — Pro", quoted_printable_decode($mail["body"]));
        $code = $this->mailedCode();
        $this->assertFalse(rollam_confirm($r['pending'], $this->users[1], $code)['ok'], 'another user cannot confirm');
        $ok = rollam_confirm($r['pending'], $this->users[0], substr($code, 0, 3) . ' ' . substr($code, 3));
        $this->assertTrue($ok['ok'], 'spaces in the code are ignored');
        $this->assertSame('yes', $this->votes($this->units[0])[$this->items[0]]);
        $rec = rollam_records($this->poll)[0];
        $this->assertSame('email', $rec['method']);
        $this->assertStringStartsWith('e-mail mo…@example.cz', $rec['verified']);
        $this->assertFalse(rollam_confirm($r['pending'], $this->users[0], $code)['ok'], 'code is one-time');
        $this->assertSame('done', rollam_pending($r['pending'])['status']);
        $this->assertSame('', rollam_pending($r['pending'])['code_hash'], 'code hash wiped after use');
    }

    public function testWrongCodeFiveAttemptsInvalidatesPending(): void
    {
        $this->setMethod('email');
        $r = rollam_begin($this->poll(), $this->user(), $this->units[0], $this->choices());
        $code = $this->mailedCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 1; $i <= 4; $i++) {
            $res = rollam_confirm($r['pending'], $this->users[0], $wrong);
            $this->assertFalse($res['ok']);
            $this->assertFalse($res['final']);
            $this->assertStringContainsString('Zbývá pokusů: ' . (5 - $i), $res['error']);
        }
        $res = rollam_confirm($r['pending'], $this->users[0], $wrong);
        $this->assertTrue($res['final']);
        $this->assertFalse(rollam_confirm($r['pending'], $this->users[0], $code)['ok'], 'even the right code is refused after 5 failures');
        $this->assertSame([], $this->votes($this->units[0]));
    }

    public function testCodeExpiresAfterTenMinutes(): void
    {
        $this->setMethod('email');
        $r = rollam_begin($this->poll(), $this->user(), $this->units[0], $this->choices());
        $code = $this->mailedCode();
        $this->assertEqualsWithDelta(time() + 600, strtotime(rollam_pending($r['pending'])['expires_at']), 5);
        q('UPDATE rollam_pending SET expires_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 1), $r['pending']]);
        $res = rollam_confirm($r['pending'], $this->users[0], $code);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('vypršela', $res['error']);
        $this->assertSame('expired', rollam_pending($r['pending'])['status']);
    }

    public function testResendLimitsAndNewCodeInvalidatesOld(): void
    {
        $this->setMethod('email');
        $r = rollam_begin($this->poll(), $this->user(), $this->units[0], $this->choices());
        $old = $this->mailedCode();
        $this->assertStringContainsString('za minutu', (string) rollam_send_code($r['pending']));
        q('UPDATE rollam_pending SET sent_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 120), $r['pending']]);
        $this->assertNull(rollam_send_code($r['pending']));
        $new = $this->mailedCode();
        if ($new !== $old) {
            $this->assertFalse(rollam_confirm($r['pending'], $this->users[0], $old)['ok'], 'old code no longer valid');
        }
        q('UPDATE rollam_pending SET sent_at = ?, sends = 3 WHERE id = ?', [date('Y-m-d H:i:s', time() - 120), $r['pending']]);
        $this->assertStringContainsString('3×', (string) rollam_send_code($r['pending']));
        $this->assertTrue(rollam_confirm($r['pending'], $this->users[0], $new)['ok']);
    }

    public function testRateLimitOnPendingVotes(): void
    {
        $this->setMethod('email');
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('code', rollam_begin($this->poll(), $this->user(), $this->units[0], $this->choices())['status']);
        }
        $this->assertSame('error', rollam_begin($this->poll(), $this->user(), $this->units[0], $this->choices())['status']);
    }

    public function testConfirmRechecksPollStateAndOwnership(): void
    {
        $this->setMethod('email');
        $r = rollam_begin($this->poll(), $this->user(), $this->units[0], $this->choices());
        $code = $this->mailedCode();
        q('UPDATE polls SET closes_at = ? WHERE id = ?', [date('Y-m-d', time() - 86400), $this->poll]);
        $res = rollam_confirm($r['pending'], $this->users[0], $code);
        $this->assertFalse($res['ok'], 'poll closed meanwhile');
        $this->assertSame([], $this->votes($this->units[0]));

        q('UPDATE polls SET closes_at = ? WHERE id = ?', [date('Y-m-d', time() + 86400), $this->poll]);
        $r = rollam_begin($this->poll(), $this->user(), $this->units[0], $this->choices());
        $code = $this->mailedCode();
        q('DELETE FROM user_units WHERE user_id = ?', [$this->users[0]]);
        $this->assertFalse(rollam_confirm($r['pending'], $this->users[0], $code)['ok'], 'unit sold meanwhile');
    }

    public function testConfirmRefusesWhenUnitVotedMeanwhile(): void
    {
        $this->setMethod('email');
        $r = rollam_begin($this->poll(), $this->user(), $this->units[0], $this->choices());
        $code = $this->mailedCode();
        rollam_commit($this->poll, $this->units[0], $this->choices('no', 'no'), 'listina', []);
        $res = rollam_confirm($r['pending'], $this->users[0], $code);
        $this->assertFalse($res['ok']);
        $this->assertSame('no', $this->votes($this->units[0])[$this->items[0]]);
    }

    // ---------- SMS ----------

    public function testSmsCodeFlow(): void
    {
        if (!defined('MODB_FAKE_SMS')) {
            $this->markTestSkipped('sms_send() is provided by another module');
        }
        $this->setMethod('sms');
        $r = rollam_begin($this->poll(), $this->user(), $this->units[0], $this->choices());
        $this->assertSame('code', $r['status']);
        $this->assertCount(1, $GLOBALS['MODB_SMS']);
        [$phone, $text] = $GLOBALS['MODB_SMS'][0];
        $this->assertSame('+420 777 123 456', $phone);
        $this->assertSame([], $this->mails(), 'SMS method does not send the code by e-mail');
        preg_match('/: (\d{6})\. Plati/', $text, $m);
        $this->assertTrue(rollam_confirm($r['pending'], $this->users[0], $m[1])['ok']);
        $rec = rollam_records($this->poll)[0];
        $this->assertSame('sms', $rec['method']);
        $this->assertSame('SMS na ••••••••••456', $rec['verified']);
    }

    public function testSmsNeedsPhoneAndReportsGatewayFailure(): void
    {
        if (!defined('MODB_FAKE_SMS')) {
            $this->markTestSkipped('sms_send() is provided by another module');
        }
        $this->setMethod('sms');
        $u = $this->user();
        $u['phone'] = '';
        $this->assertStringContainsString('telefon', rollam_begin($this->poll(), $u, $this->units[0], $this->choices())['error']);
        $GLOBALS['MODB_SMS_FAIL'] = true;
        $r = rollam_begin($this->poll(), $this->user(), $this->units[0], $this->choices());
        $this->assertSame('error', $r['status']);
        $this->assertStringContainsString('nepodařilo odeslat', $r['error']);
    }

    // ---------- záznamy a řetěz otisků ----------

    public function testChainDetectsTamperingAndMismatch(): void
    {
        rollam_commit($this->poll, $this->units[0], $this->choices(), 'web', ['voter_name' => 'A']);
        rollam_commit($this->poll, $this->units[1], $this->choices('no', 'abstain'), 'web', ['voter_name' => 'B']);
        $c = rollam_chain_check($this->poll);
        $this->assertTrue($c['ok']);
        $this->assertSame(2, $c['count']);
        $this->assertSame(64, strlen($c['head']));

        // změna hlasu přímo v databázi (mimo web): nesouhlasí s protokolem
        q("UPDATE poll_votes SET choice = 'yes' WHERE item_id = ? AND unit_id = ?", [$this->items[0], $this->units[1]]);
        $c = rollam_chain_check($this->poll);
        $this->assertFalse($c['ok']);
        $this->assertSame([$this->units[1]], $c['mismatch']);
        $this->assertSame(0, $c['bad']);
        q("UPDATE poll_votes SET choice = 'no' WHERE item_id = ? AND unit_id = ?", [$this->items[0], $this->units[1]]);

        // přepsání záznamu: řetěz se přeruší
        $first = rollam_records($this->poll)[0];
        q('UPDATE rollam_records SET ip = ? WHERE id = ?', ['10.0.0.1', $first['id']]);
        $c = rollam_chain_check($this->poll);
        $this->assertFalse($c['ok']);
        $this->assertSame((int) $first['id'], $c['bad']);
    }

    public function testChainIsPerPollAndAdminHookRecordsSnapshot(): void
    {
        $_SESSION = [];
        $admin = $this->loginAs('admin');
        q('INSERT INTO poll_votes (item_id, unit_id, user_id, choice) VALUES (?,?,?,?)', [$this->items[0], $this->units[0], $admin['id'], 'abstain']);
        $this->assertFalse(rollam_chain_check($this->poll)['ok'], 'vote without record = mismatch');
        rollam_record_unit($this->poll, $this->units[0], 'spravce', $admin);
        $c = rollam_chain_check($this->poll);
        $this->assertTrue($c['ok']);
        $rec = rollam_records($this->poll)[0];
        $this->assertSame('spravce', $rec['method']);
        $this->assertSame($admin['name'], $rec['entered_name']);
        $this->assertSame('Novák Jan', $rec['voter_name']);
        // smazání hlasu správcem se zaznamená jako prázdný hlas
        q('DELETE FROM poll_votes WHERE unit_id = ?', [$this->units[0]]);
        rollam_record_unit($this->poll, $this->units[0], 'spravce', $admin);
        $this->assertTrue(rollam_chain_check($this->poll)['ok']);
        $this->assertSame('{}', rollam_records($this->poll)[1]['choices']);
        // první záznam jiného hlasování navazuje na prázdný otisk, ne na toto hlasování
        $this->assertSame(rollam_hash('', rollam_records($this->poll)[0]), rollam_records($this->poll)[0]['hash']);
    }

    // ---------- listinné hlasy ----------

    private function paperInput(array $over = []): array
    {
        return $over + ['unit_id' => $this->units[1], 'choice' => [$this->items[0] => 'yes', $this->items[1] => ''], 'signed_on' => date('Y-m-d'), 'received_on' => date('Y-m-d'), 'note' => 'schránka'];
    }

    public function testPaperVoteValidation(): void
    {
        $admin = $this->loginAs('admin');
        $p = $this->poll();
        $this->assertNotEmpty(rollam_paper_errors($p, $this->paperInput(['unit_id' => 0])));
        $this->assertNotEmpty(rollam_paper_errors($p, $this->paperInput(['choice' => [$this->items[0] => '']])));
        $this->assertStringContainsString('§ 1212', implode(' ', rollam_paper_errors($p, $this->paperInput(['signed_on' => '']))));
        $this->assertNotEmpty(rollam_paper_errors($p, $this->paperInput(['signed_on' => date('Y-m-d', time() + 86400)])));
        $this->assertStringContainsString('po skončení lhůty', implode(' ', rollam_paper_errors(['closes_at' => date('Y-m-d', time() - 86400)] + $p, $this->paperInput())));
        $this->assertNotEmpty(rollam_paper_errors($p, $this->paperInput(['received_on' => date('Y-m-d', time() - 5 * 86400)])), 'before poll opened');
        $this->assertSame([], rollam_paper_errors($p, $this->paperInput()));
        $this->assertIsArray(rollam_paper_vote($p, $this->paperInput(['unit_id' => 0]), $admin), 'nothing stored on error');
        $this->assertSame([], rollam_records($this->poll));
    }

    public function testPaperVoteStoresWithMetadataAndCombinesWithOnline(): void
    {
        $admin = $this->loginAs('admin');
        rollam_begin($this->poll(), $this->user(), $this->units[0], $this->choices('yes', 'yes'));   // online (bez ověření)
        $id = rollam_paper_vote($this->poll(), $this->paperInput(), $admin, ['abc.pdf', 'listek.pdf']);
        $this->assertIsInt($id);
        $rec = one('SELECT * FROM rollam_records WHERE id = ?', [$id]);
        $this->assertSame('listina', $rec['method']);
        $this->assertSame($admin['name'], $rec['entered_name']);
        $this->assertSame('abc.pdf', $rec['scan_file']);
        $this->assertSame('Svobodová Eva', $rec['voter_name']);
        $this->assertSame([$this->items[0] => 'yes'], $this->votes($this->units[1]), 'blank item = no vote on it');
        $res = poll_results($this->poll);
        $this->assertSame(2, $res['voted'], 'online and paper votes are counted together');
        $this->assertSame(2, $res['items'][0]['count']['yes']);
        // druhý lístek za tutéž jednotku jen s výslovným nahrazením
        $this->assertStringContainsString('Nahradit', implode(' ', (array) rollam_paper_vote($this->poll(), $this->paperInput(), $admin)));
        $this->assertIsInt(rollam_paper_vote($this->poll(), $this->paperInput(['replace' => true, 'choice' => [$this->items[1] => 'no']]), $admin));
        $this->assertSame([$this->items[1] => 'no'], $this->votes($this->units[1]), 'replacement removes items left blank');
        $this->assertTrue(rollam_chain_check($this->poll)['ok']);
        $this->assertSame(3, rollam_chain_check($this->poll)['count']);
    }

    // ---------- protokol a lístky ----------

    public function testProtocolAdoptionUsesMajorityOfAllVotes(): void
    {
        q('UPDATE polls SET threshold = 10 WHERE id = ?', [$this->poll]);
        rollam_commit($this->poll, $this->units[0], $this->choices('yes', 'no'), 'web', []);
        $p = rollam_protocol($this->poll);
        $this->assertSame(50, $p['need'], 'per rollam needs a majority even if the poll threshold is lower');
        $total = 0.0;
        foreach ($p['results']['units'] as $un) {
            $total += $p['results']['byShare'] ? share_value($un['share']) : 1.0;
        }
        $yesPct = $p['results']['items'][0]['percent']['yes'];
        $this->assertSame($yesPct > 50, $p['results']['items'][0]['adopted']);
        $this->assertFalse($p['results']['items'][1]['adopted']);
        $this->assertCount(1, $p['records']);
        $this->assertSame([$this->items[0] => 'yes', $this->items[1] => 'no'], $p['records'][0]['choice_list']);
        $this->assertTrue($p['check']['ok']);
        $this->assertSame(64, strlen($p['fingerprint']));
        $this->assertNotEmpty($p['warnings']);
        $this->assertNull(rollam_protocol(99999999));
    }

    public function testProtocolAndBallotViewsRender(): void
    {
        $this->loginAs('admin');
        rollam_commit($this->poll, $this->units[0], $this->choices(), 'listina', ['signed_on' => date('Y-m-d'), 'received_on' => date('Y-m-d'), 'scan_file' => 'x.pdf', 'scan_name' => 'x.pdf']);
        $html = $this->renderView('ext/rollam/protocol', ['title' => 'Protokol'] + rollam_protocol($this->poll));
        $this->assertStringContainsString('Protokol o hlasování', $html);
        $this->assertStringContainsString('Schvaluje se výměna výtahu.', $html);
        $this->assertStringContainsString('listinný hlas', $html);
        $this->assertStringContainsString('V pořádku.', $html);
        $units = rollam_ballot_units($this->units[0]);
        $this->assertSame('Novák Jan', $units[0]['owner_label']);
        $html = $this->renderView('ext/rollam/ballots', ['poll' => $this->poll(), 'items' => all('SELECT * FROM poll_items WHERE poll_id = ? ORDER BY sort', [$this->poll]), 'units' => $units, 'blank' => false]);
        $this->assertStringContainsString('Hlasovací lístek', $html);
        $this->assertStringContainsString('Schvaluje se úvěr.', $html, 'full text of every proposal');
        $this->assertStringContainsString('1/10', $html);
        $this->assertStringContainsString('Vlastnoruční podpis', $html);
        $this->assertStringContainsString(cz_date($this->poll()['closes_at'], true), $html);
        $this->assertCount(2, array_filter(rollam_ballot_units(), fn($u) => in_array((int) $u['id'], $this->units, true)), 'one ballot per unit');
    }

    public function testOwnerFacingPartialsRender(): void
    {
        $poll = $this->poll();
        $this->setMethod('email');
        $html = $this->renderView('ext/rollam/_poll_note', ['poll' => $poll]);
        $this->assertStringContainsString('e-mailem kód', $html);
        $this->setMethod('none');
        $this->assertSame('', trim($this->renderView('ext/rollam/_poll_note', ['poll' => $poll])));
        $html = $this->renderView('ext/rollam/_form_fields', ['poll' => ['id' => 0, 'opens_at' => '2026-01-01', 'closes_at' => '2026-01-05', 'threshold' => 50]]);
        $this->assertStringContainsString('name="verify"', $html);
        $this->assertStringContainsString('value="email" selected', $html, 'new polls default to the e-mail code');
        $this->assertStringContainsString('§ 1211', $html);
    }

    /** Vykreslí šablonu bez rozvržení. */
    private function renderView(string $view, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__, 2) . '/app/views/' . $view . '.php';
        return (string) ob_get_clean();
    }
}

}
