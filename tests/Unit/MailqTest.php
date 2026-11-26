<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Fronta e-mailů (app/mailq_lib.php). Limity jsou v config.php (v testech výchozí 800/200 za hodinu); jejich
 * dosažení se navodí záznamy „odesláno teď“ v databázi fronty, které se po testu smažou.
 */
final class MailqTest extends TestCase
{
    private const FILLER = 'zz-test-filler';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['MAIL_BULK'] = false;
        $GLOBALS['MAIL_QUEUED'] = 0;
        unset($GLOBALS['MAIL_LAST']);
        $this->assertSame(0, mailq_pending(), 'queue must be empty before each test');
    }

    protected function tearDown(): void
    {
        mailq_pdo()->exec("DELETE FROM mailq WHERE tenant = '" . self::FILLER . "'");
        // vše, co test nechal čekat, odeslat/uklidit, ať fronta nezůstane plná pro další testy
        mailq_pdo()->exec('DELETE FROM mailq WHERE sent_at IS NULL');
        @unlink(mailq_marker());
        @unlink(ROOT_STORAGE . '/mailq.tick');
        $GLOBALS['MAIL_BULK'] = false;
        $GLOBALS['MAIL_QUEUED'] = 0;
    }

    /** Doplní záznamy odeslaných zpráv tak, aby za poslední hodinu bylo odesláno právě $count. */
    private function fillSentTo(int $count): void
    {
        $missing = $count - mailq_sent_hour();
        $this->assertGreaterThanOrEqual(0, $missing);
        $st = mailq_pdo()->prepare('INSERT INTO mailq (tenant, bulk, created, sent_at) VALUES (?,0,?,?)');
        $now = date('Y-m-d H:i:s');
        mailq_pdo()->beginTransaction();
        for ($i = 0; $i < $missing; $i++) {
            $st->execute([self::FILLER, $now, $now]);
        }
        mailq_pdo()->commit();
        $this->assertSame($count, mailq_sent_hour());
    }

    private function clearFiller(): void
    {
        mailq_pdo()->exec("DELETE FROM mailq WHERE tenant = '" . self::FILLER . "'");
    }

    private function resident(): string
    {
        return (string) val("SELECT email FROM users WHERE role = 'resident' AND active = 1 ORDER BY id LIMIT 1");
    }

    public function testLimitsAndEnabledDefaults(): void
    {
        $this->assertArrayNotHasKey('mail', app_config(), 'test config has no mail section');
        $this->assertSame(['hour' => 800, 'bulk' => 200], mailq_limits());
        $this->assertTrue(mailq_enabled());
    }

    public function testQueueFileHasRandomStableName(): void
    {
        mailq_pdo();
        $file = mailq_file();
        $this->assertMatchesRegularExpression('~^' . preg_quote(ROOT_STORAGE, '~') . '/mailq-[0-9a-f]{24}\.sqlite$~', $file);
        $this->assertFileExists($file);
        $this->assertSame($file, mailq_file(), 'existing file is reused');
        $this->assertSame(ROOT_STORAGE . '/mailq.pending', mailq_marker());
        $this->assertFileDoesNotExist(ROOT_STORAGE . '/mailq.sqlite');
    }

    public function testRegularMailIsSentImmediatelyAndCounted(): void
    {
        $before = mailq_sent_hour();
        $to = $this->resident();
        $this->assertTrue(send_mail($to, 'Okamžitá zpráva', 'Text'));
        $this->assertSame('capture', $GLOBALS['MAIL_LAST']);
        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame($to, $mails[0]['to']);
        $this->assertSame($before + 1, mailq_sent_hour(), 'sent message is recorded for the hourly limit');
        $row = mailq_pdo()->query('SELECT * FROM mailq ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame('dum', $row['tenant']);
        $this->assertSame(0, (int) $row['bulk']);
        $this->assertNull($row['msg'], 'content of sent messages is not kept');
        $this->assertSame(0, mailq_pending());
    }

    public function testInvalidAddressIsNotSentNorQueued(): void
    {
        $before = mailq_sent_hour();
        $this->assertFalse(send_mail('not-an-email', 'X', 'Y'));
        $this->assertSame([], $this->mails());
        $this->assertSame($before, mailq_sent_hour());
    }

    public function testMailBulkSetsAndRestoresFlag(): void
    {
        $this->assertFalse($GLOBALS['MAIL_BULK']);
        $seen = mail_bulk(fn() => $GLOBALS['MAIL_BULK']);
        $this->assertTrue($seen);
        $this->assertFalse($GLOBALS['MAIL_BULK']);

        $this->assertSame('inner', mail_bulk(fn() => mail_bulk(fn() => 'inner')));
        $this->assertFalse($GLOBALS['MAIL_BULK'], 'nested call restores the outer value');

        try {
            mail_bulk(function () {
                throw new \RuntimeException('boom');
            });
            $this->fail('exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertFalse($GLOBALS['MAIL_BULK'], 'flag restored after exception');
    }

    public function testBulkOverLimitIsQueuedAndRegularStillGoesOut(): void
    {
        $lim = mailq_limits();
        $this->fillSentTo($lim['bulk']);
        $to = $this->resident();

        $ok = mail_bulk(fn() => send_mail($to, 'Hromadná zpráva', 'Rozeslání obyvatelům'));
        $this->assertTrue($ok, 'queued message counts as accepted');
        $this->assertSame('queued', $GLOBALS['MAIL_LAST']);
        $this->assertSame(1, $GLOBALS['MAIL_QUEUED']);
        $this->assertSame([], $this->mails(), 'nothing delivered yet');
        $this->assertFileExists(mailq_marker());
        $this->assertSame(1, mailq_pending());
        $this->assertSame(1, mailq_pending(1));
        $this->assertSame(0, mailq_pending(0));
        $this->assertStringContainsString('1 z nich čeká ve frontě a odejde postupně během hodiny', mailq_note());

        $status = mailq_status();
        $this->assertSame(1, $status['pending']);
        $this->assertSame(1, $status['bulk']);
        $this->assertSame($lim, $status['limits']);
        $this->assertNotSame('', $status['oldest']);

        // běžná zpráva je pod celkovým limitem → odchází hned
        $this->assertTrue(send_mail($to, 'Běžná zpráva', 'Heslo'));
        $this->assertSame('capture', $GLOBALS['MAIL_LAST']);
        $this->assertCount(1, $this->mails());

        // dokud čeká hromadná zpráva, další hromadná nepředbíhá ani pod limitem
        $this->clearFiller();
        $this->assertLessThan($lim['bulk'], mailq_sent_hour());
        mail_bulk(fn() => send_mail($to, 'Druhá hromadná', 'X'));
        $this->assertSame('queued', $GLOBALS['MAIL_LAST']);
        $this->assertSame(2, mailq_pending(1));

        // fronta se rozešle
        [$done, $bad, $left] = mailq_run(25, true);
        $this->assertSame([2, 0, 0], [$done, $bad, $left]);
        $mails = $this->mails();
        $this->assertCount(3, $mails);
        $this->assertStringContainsString('Hromadn', mb_decode_mimeheader($mails[1]['subject']));
        $this->assertSame(0, mailq_pending());
        $this->assertFileDoesNotExist(mailq_marker(), 'marker removed when queue is empty');
    }

    public function testRegularOverHourlyLimitIsQueued(): void
    {
        $this->fillSentTo(mailq_limits()['hour']);
        $this->assertTrue(send_mail($this->resident(), 'Nad limit', 'X'));
        $this->assertSame('queued', $GLOBALS['MAIL_LAST']);
        $this->assertSame(1, mailq_pending(0));
        $this->assertSame([], $this->mails());

        // limit stále vyčerpán → běh fronty nic neodešle
        $this->assertSame([0, 0, 1], mailq_run(25, true));
        $this->clearFiller();
        $this->assertSame([1, 0, 0], mailq_run(25, true));
        $this->assertCount(1, $this->mails());
    }

    public function testRunWithoutMarkerDoesNothing(): void
    {
        @unlink(mailq_marker());
        $this->assertSame([0, 0, 0], mailq_run());
    }

    public function testRunIsThrottledByTickUnlessForced(): void
    {
        $this->fillSentTo(mailq_limits()['hour']);
        send_mail($this->resident(), 'Čeká', 'X');
        $this->clearFiller();
        touch(ROOT_STORAGE . '/mailq.tick');
        $this->assertSame([0, 0, 1], mailq_run(), 'ran less than a minute ago');
        $this->assertSame([1, 0, 0], mailq_run(25, true));
    }

    public function testRunRespectsMaxPerBatch(): void
    {
        $this->fillSentTo(mailq_limits()['hour']);
        $to = $this->resident();
        for ($i = 1; $i <= 3; $i++) {
            send_mail($to, "Fronta $i", 'X');
        }
        $this->clearFiller();
        $this->assertSame([2, 0, 1], mailq_run(2, true));
        $this->assertSame([1, 0, 0], mailq_run(2, true));
        $subjects = array_map(fn($m) => mb_decode_mimeheader($m['subject']), $this->mails());
        $this->assertSame(['Fronta 1', 'Fronta 2', 'Fronta 3'], $subjects, 'sent in queue order');
    }

    public function testBrokenQueuedMessageIsRetriedThenMarkedFailed(): void
    {
        $pdo = mailq_pdo();
        $pdo->prepare('INSERT INTO mailq (tenant, bulk, created, msg, attempts) VALUES (?,0,?,?,?)')->execute(['dum', date('Y-m-d H:i:s'), 'not json', 0]);
        $id = (int) $pdo->lastInsertId();
        touch(mailq_marker());

        $this->assertSame([0, 0, 1], mailq_run(25, true));
        $row = $pdo->query("SELECT * FROM mailq WHERE id = $id")->fetch();
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertNull($row['sent_at']);

        $pdo->exec("UPDATE mailq SET attempts = 4 WHERE id = $id");
        $failedBefore = mailq_status()['failed'];
        $this->assertSame([0, 1, 0], mailq_run(25, true));
        $row = $pdo->query("SELECT * FROM mailq WHERE id = $id")->fetch();
        $this->assertSame(1, (int) $row['failed']);
        $this->assertNotNull($row['sent_at']);
        $this->assertNull($row['msg']);
        $this->assertSame($failedBefore + 1, mailq_status()['failed']);
        $this->assertFileDoesNotExist(mailq_marker());
        $pdo->exec("DELETE FROM mailq WHERE id = $id");
    }

    public function testRunDeletesOldSentRecords(): void
    {
        $pdo = mailq_pdo();
        $old = date('Y-m-d H:i:s', time() - 4 * 86400);
        $pdo->prepare('INSERT INTO mailq (tenant, bulk, created, sent_at) VALUES (?,0,?,?)')->execute(['dum', $old, $old]);
        $id = (int) $pdo->lastInsertId();
        touch(mailq_marker());
        mailq_run(25, true);
        $this->assertFalse($pdo->query("SELECT 1 FROM mailq WHERE id = $id")->fetch());
    }

    public function testNoteEmptyWhenNothingQueued(): void
    {
        $GLOBALS['MAIL_QUEUED'] = 0;
        $this->assertSame('', mailq_note());
    }

    public function testNoteEstimatesHours(): void
    {
        $pdo = mailq_pdo();
        $st = $pdo->prepare('INSERT INTO mailq (tenant, bulk, created, msg) VALUES (?,1,?,?)');
        $pdo->beginTransaction();
        for ($i = 0; $i < mailq_limits()['bulk'] * 2 + 1; $i++) {
            $st->execute(['dum', date('Y-m-d H:i:s'), '{}']);
        }
        $pdo->commit();
        touch(mailq_marker());
        $GLOBALS['MAIL_QUEUED'] = 5;
        $this->assertSame(' 5 z nich čeká ve frontě a odejde postupně během 3 hodin – hosting dovoluje odeslat jen omezený počet e-mailů za hodinu.', mailq_note());
    }
}
