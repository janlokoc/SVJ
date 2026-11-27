<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Záloha dat služby (app/backup_lib.php): co se do zálohy nedává a jak vypadá sestavený ZIP. */
final class BackupTest extends TestCase
{
    private array $created = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        $this->created = [];
    }

    private function put(string $rel, string $content = 'x'): void
    {
        $path = ROOT_STORAGE . '/' . $rel;
        $dir = dirname($path);
        $missing = [];
        while (!is_dir($dir)) {
            $missing[] = $dir;
            $dir = dirname($dir);
        }
        foreach (array_reverse($missing) as $d) {
            mkdir($d, 0775);
            $this->created[] = $d;
        }
        if (!is_file($path)) {
            $this->created[] = $path;
        }
        file_put_contents($path, $content);
    }

    public function testSkipPatternExcludesRuntimeFiles(): void
    {
        $skipped = [
            'tenants/dum/sessions/sess_abc123',
            'platform-sessions/sess_abc',
            'sessions/sess_x',
            'tmp/zaloha-abc.zip',
            'tenants/dum/tmp/upload-part',
            'tmp',
            'mailq.sqlite',
            'mailq-0123456789abcdef01234567.sqlite',
            'mailq-0123456789abcdef01234567.sqlite-wal',
            'mailq.pending',
            'mailq.lock',
            'mailq.tick',
            'tenants/dum/cron.lock',
            'cron.tick',
            'tenants/dum/import.pending',
            'instalacni-kod-0123abcd.txt',
            'instalacni-kod-platformy-0123abcd.txt',
            'tenants/dum/instalacni-kod-0123abcd.txt',
            'obnova-2fa-0123abcd.txt',
            'obnova-pristupu-0123abcd.txt',
            'mail-staging.log',
            'tenants/dum/data-abc.sqlite-wal',
            'tenants/dum/data-abc.sqlite-shm',
            'platform-abc.sqlite-journal',
            'inbound/files/0123abcd.bin',
            'inbound/pending-dum.flag',
            'inbound/run.lock',
        ];
        foreach ($skipped as $rel) {
            $this->assertSame(1, preg_match(BACKUP_SKIP, $rel), "'$rel' must be skipped");
        }
    }

    public function testSkipPatternKeepsDataFiles(): void
    {
        $kept = [
            'config.php',
            'platform-5957fe53608bb082d1e3a002.sqlite',
            'tenants/dum/data-abc.sqlite',
            'tenants/dum/uploads/zapis-2026.pdf',
            'tenants/dum/uploads/tmp-plan.pdf',
            'tenants/dum/uploads/mytmp/plan.pdf',
            'tenants/dum/uploads/sessions-2026.pdf',
            'tenants/dum/uploads/locker.jpg',
            'tenants/dum/uploads/mail-staging.log.pdf',
            'tenants/dum/push-keys.json',
            'tenants/dum/reports/hlaseni-1.json',
            'certs/dum.test.invalid.pem',
            'sitemail.log',
            'inbound/secret.key',
            'inbound/inbound-0123abcd.sqlite',
            'tenants/dum/uploads/inbound-plan.pdf',
        ];
        foreach ($kept as $rel) {
            $this->assertSame(0, preg_match(BACKUP_SKIP, $rel), "'$rel' must be kept");
        }
    }

    public function testBuildCreatesZipWithDataAndWithoutRuntimeFiles(): void
    {
        $u = bin2hex(random_bytes(4));
        $this->put("tenants/dum/uploads/keep-$u.txt", 'dokument');
        $this->put("tenants/dum/sessions/sess_$u", 'secret-session');
        $this->put("instalacni-kod-$u.txt", 'CODE');
        $this->put("obnova-2fa-$u.txt", 'CODE');
        $this->put("job-$u.lock");
        $this->put("job-$u.pending");
        $this->put("tmp/old-$u.txt");

        $zipPath = backup_build();
        $this->created[] = $zipPath;
        $this->assertFileExists($zipPath);
        $this->assertStringStartsWith(ROOT_STORAGE . '/tmp/zaloha-', $zipPath);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $this->assertContains('ZALOHA.txt', $names);
        $info = (string) $zip->getFromName('ZALOHA.txt');
        $this->assertStringContainsString('Záloha dat služby', $info);
        $this->assertMatchesRegularExpression('/Souborů: \d+/', $info);
        preg_match('/Souborů: (\d+)/', $info, $m);
        $this->assertSame(count($names) - 1, (int) $m[1], 'file count matches entries');

        $this->assertContains('storage/config.php', $names);
        $this->assertContains("storage/tenants/dum/uploads/keep-$u.txt", $names);
        $this->assertSame('dokument', $zip->getFromName("storage/tenants/dum/uploads/keep-$u.txt"));
        foreach (["storage/tenants/dum/sessions/sess_$u", "storage/instalacni-kod-$u.txt", "storage/obnova-2fa-$u.txt", "storage/job-$u.lock", "storage/job-$u.pending", "storage/tmp/old-$u.txt"] as $absent) {
            $this->assertNotContains($absent, $names);
        }
        foreach ($names as $n) {
            if ($n === 'ZALOHA.txt') {
                continue;
            }
            $this->assertStringStartsWith('storage/', $n);
            $this->assertSame(0, preg_match(BACKUP_SKIP, substr($n, 8)), "$n should not be in backup");
        }

        // databáze jsou konzistentní kopie, které jdou otevřít
        $platform = array_values(array_filter($names, fn($n) => preg_match('~^storage/platform-[^/]+\.sqlite$~', $n)));
        $this->assertCount(1, $platform);
        $tenantDbs = array_values(array_filter($names, fn($n) => str_starts_with($n, 'storage/tenants/dum/') && str_ends_with($n, '.sqlite')));
        $this->assertNotEmpty($tenantDbs);
        $units = 0;
        foreach ($tenantDbs as $n) {
            $tmp = tempnam(sys_get_temp_dir(), 'svjbk');
            file_put_contents($tmp, $zip->getFromName($n));
            $pdo = new \PDO('sqlite:' . $tmp);
            $has = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'units'")->fetchColumn();
            if ($has) {
                $units = (int) $pdo->query('SELECT COUNT(*) FROM units')->fetchColumn();
            }
            $pdo = null;
            unlink($tmp);
        }
        $this->assertSame((int) val('SELECT COUNT(*) FROM units'), $units);
        $zip->close();

        // pomocné kopie databází jsou smazané
        $this->assertSame([], glob(ROOT_STORAGE . '/tmp/db-*.sqlite') ?: []);
    }

    // ---------- Klíč zálohy (bezpečnost 11/2026) ----------

    public function testBackupKeyOnlyInHeaderFailuresLoggedAndBlocked(): void
    {
        $key = (string) (app_config()['backup_key'] ?? '');
        @unlink(backup_fail_file());
        $ip = '192.0.2.77';
        try {
            if (strlen($key) >= 32) {
                $this->assertTrue(backup_auth_ok($key, false, $ip), 'správný klíč v hlavičce');
                $this->assertFalse(backup_auth_ok($key, true, $ip), 'klíč v adrese se už nepřijímá');
            }
            $this->assertFalse(backup_auth_ok('', false, $ip));
            $this->assertFalse(backup_auth_ok(str_repeat('x', 40), false, $ip));
            $this->assertSame(strlen($key) >= 32 ? 3 : 2, backup_fails($ip));
            while (backup_fails($ip) < BACKUP_FAIL_LIMIT) {
                backup_auth_ok('špatně', false, $ip);
            }
            $this->assertTrue(backup_blocked($ip));
            $this->assertFalse(backup_auth_ok($key !== '' ? $key : 'x', false, $ip), 'zablokovaná adresa neprojde ani se správným klíčem');
            $this->assertFalse(backup_blocked('192.0.2.78'));
        } finally {
            @unlink(backup_fail_file());
        }
        $idx = (string) file_get_contents(dirname(__DIR__, 2) . '/index.php');
        $this->assertStringContainsString("HTTP_X_BACKUP_KEY", $idx);
        $wf = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/zaloha.yml');
        $this->assertStringContainsString('-H "X-Backup-Key: $KEY"', $wf);
        $this->assertStringContainsString('::add-mask::$KEY', $wf);
    }
}
