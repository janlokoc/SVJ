<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Modul A – kolik chybí k přijetí a připomenutí hlasování nehlasujícím (app/ext/moda_lib.php). */
final class ModAPollTest extends TestCase
{
    protected function tearDown(): void
    {
        q("DELETE FROM polls WHERE title LIKE 'Připomínané hlasování %'");
        parent::tearDown();
    }

    /** Výsledky ve tvaru poll_results() pro jeden bod: $votes = [id jednotky => volba], jednotky 1..$n po jednom hlasu. */
    private function results(int $n, array $votes, int $threshold = 50, array $shares = []): array
    {
        $units = [];
        for ($i = 1; $i <= $n; $i++) {
            $units[] = ['id' => $i, 'share' => $shares[$i] ?? ''];
        }
        $byShare = (bool) $shares;
        $total = $byShare ? array_sum(array_map('share_value', $shares)) : (float) $n;
        $sum = ['yes' => 0.0, 'no' => 0.0, 'abstain' => 0.0];
        $v = [];
        foreach ($votes as $unit => $choice) {
            $sum[$choice] += $byShare ? share_value($shares[$unit]) : 1.0;
            $v[$unit] = ['choice' => $choice];
        }
        $item = ['id' => 7, 'sum' => $sum, 'percent' => array_map(fn($x) => $x / $total * 100, $sum)];
        return ['items' => [$item], 'votes' => [7 => $v], 'units' => $units, 'byShare' => $byShare, 'threshold' => $threshold];
    }

    public function testMissingVotesPerItem(): void
    {
        $g = moda_poll_gap($this->results(4, [1 => 'yes']))[7];
        $this->assertFalse($g['passed']);
        $this->assertEqualsWithDelta(25.0, $g['missing'], 0.001);
        $this->assertEqualsWithDelta(75.0, $g['open'], 0.001);
        $this->assertTrue($g['reachable']);
        $this->assertSame(2, $g['units'], '25 % + 2 × 25 % = 75 % > 50 %; jedna jednotka (50 %) nestačí – musí být víc než hranice');
    }

    public function testPassedItem(): void
    {
        $g = moda_poll_gap($this->results(4, [1 => 'yes', 2 => 'yes', 3 => 'yes']))[7];
        $this->assertTrue($g['passed']);
        $this->assertSame(0.0, $g['missing']);
        $this->assertSame(0, $g['units']);
    }

    public function testUnreachableItem(): void
    {
        $g = moda_poll_gap($this->results(4, [1 => 'no', 2 => 'no', 3 => 'abstain']))[7];
        $this->assertFalse($g['passed']);
        $this->assertFalse($g['reachable'], 'zbývá 25 %, ani to nestačí');
        $this->assertSame(0, $g['units']);
        $this->assertEqualsWithDelta(25.0, $g['open'], 0.001);
    }

    public function testSharesPickLargestUnitsFirst(): void
    {
        $shares = [1 => '10/100', 2 => '20/100', 3 => '30/100', 4 => '40/100'];
        $g = moda_poll_gap($this->results(4, [1 => 'yes'], 50, $shares))[7];
        $this->assertEqualsWithDelta(40.0, $g['missing'], 0.001);
        $this->assertEqualsWithDelta(90.0, $g['open'], 0.001);
        $this->assertTrue($g['reachable']);
    }

    public function testSharesNeedStrictlyMore(): void
    {
        $shares = [1 => '10/100', 2 => '20/100', 3 => '30/100', 4 => '40/100'];
        $g = moda_poll_gap($this->results(4, [2 => 'yes'], 50, $shares))[7];
        $this->assertSame(1, $g['units'], '20 % + 40 % = 60 % > 50 %');
        $g = moda_poll_gap($this->results(4, [1 => 'yes'], 50, $shares))[7];
        $this->assertSame(2, $g['units'], '10 % + 40 % = 50 % není víc než 50 %, další jednotka nutná');
    }

    private function openPoll(): int
    {
        q('INSERT INTO polls (title, opens_at, closes_at, online, threshold) VALUES (?,?,?,1,50)', ['Připomínané hlasování ' . bin2hex(random_bytes(3)), date('Y-m-d', time() - 86400), date('Y-m-d', time() + 5 * 86400)]);
        $id = (int) db()->lastInsertId();
        q('INSERT INTO poll_items (poll_id, question) VALUES (?,?)', [$id, 'Souhlasíte s opravou?']);
        q("INSERT INTO poll_votes (item_id, unit_id, user_id, choice) VALUES (?, 2, 2, 'yes')", [(int) db()->lastInsertId()]);
        return $id;
    }

    public function testNonvotersAreOwnersOfUnitsWithoutVote(): void
    {
        $emails = array_column(moda_poll_nonvoters($this->openPoll()), 'email');
        $this->assertNotContains('petr.novak@example.cz', $emails, 'za jeho jednotku už se hlasovalo');
        $this->assertNotContains('marie.novakova@example.cz', $emails, 'spoluvlastnice téže jednotky');
        $this->assertNotContains('lukas.cerny@example.cz', $emails, 'nájemník nehlasuje');
        $this->assertContains('tomas.dvorak@example.cz', $emails);
        $this->assertContains('jana.vyborova@example.cz', $emails);
    }

    public function testRemindSendsMailsOnceIn24Hours(): void
    {
        $id = $this->openPoll();
        $admin = one("SELECT * FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        $people = count(moda_poll_nonvoters($id));
        $r = moda_poll_remind($id, $admin);
        $this->assertIsArray($r);
        $this->assertSame($people, $r['people']);
        $this->assertSame($people, $r['mails']);
        $mails = $this->mails();
        $this->assertCount($people, $mails);
        $this->assertStringContainsString('/hlasovani/' . $id, quoted_printable_decode($mails[0]['body']));
        $this->assertSame('Připomenutí hlasování nehlasujícím', val('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1'));
        $this->assertGreaterThan(time() - 5, moda_poll_last_reminder($id));

        $again = moda_poll_remind($id, $admin);
        $this->assertIsString($again);
        $this->assertStringContainsString('za 24 hodin', $again);
        $this->assertCount($people, $this->mails(), 'druhé připomenutí neodešlo');

        q('UPDATE poll_reminders SET at = ? WHERE poll_id = ?', [time() - 86401, $id]);
        $this->assertIsArray(moda_poll_remind($id, $admin), 'po 24 hodinách znovu');
    }

    public function testRemindRefusesClosedOrPaperPoll(): void
    {
        $admin = one("SELECT * FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        $id = $this->openPoll();
        q('UPDATE polls SET closes_at = ? WHERE id = ?', [date('Y-m-d', time() - 86400), $id]);
        $this->assertStringContainsString('právě probíhá', moda_poll_remind($id, $admin));
        q('UPDATE polls SET closes_at = ?, online = 0 WHERE id = ?', [date('Y-m-d', time() + 86400), $id]);
        $this->assertStringContainsString('právě probíhá', moda_poll_remind($id, $admin));
        $this->assertSame('Hlasování neexistuje.', moda_poll_remind(999999, $admin));
        $this->assertSame([], $this->mails());
    }

    public function testRemindWhenEveryoneVoted(): void
    {
        $admin = one("SELECT * FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        $id = $this->openPoll();
        $item = (int) val('SELECT id FROM poll_items WHERE poll_id = ?', [$id]);
        foreach (all('SELECT id FROM units') as $un) {
            q("INSERT OR IGNORE INTO poll_votes (item_id, unit_id, choice) VALUES (?, ?, 'no')", [$item, $un['id']]);
        }
        $this->assertStringContainsString('už hlasovali', moda_poll_remind($id, $admin));
    }
}
