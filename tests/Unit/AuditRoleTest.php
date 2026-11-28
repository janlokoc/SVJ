<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Kontrolní komise: administrace jen pro čtení (role 'kontrola'). */
final class AuditRoleTest extends TestCase
{
    private int $id;

    protected function setUp(): void
    {
        parent::setUp();
        q("INSERT INTO users (name, email, role, kind, password_hash) VALUES ('Kontrolorka', ?, 'kontrola', 'owner', ?)", ['kk-' . bin2hex(random_bytes(3)) . '@example.cz', password_hash('x', PASSWORD_DEFAULT)]);
        $this->id = (int) db()->lastInsertId();
        $u = one('SELECT * FROM users WHERE id = ?', [$this->id]);
        $_SESSION['uid'] = $this->id;
        $_SESSION['pv'] = pw_fingerprint($u['password_hash']);
        user(true);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['AUDIT_READONLY']);
        $GLOBALS['METHOD'] = 'GET';
        q('DELETE FROM users WHERE id = ?', [$this->id]);
        parent::tearDown();
    }

    public function testRoleViewsAdminReadOnly(): void
    {
        $this->assertArrayHasKey('kontrola', ROLES);
        $this->assertTrue(can_view_admin());
        $this->assertTrue(is_audit());
        $this->assertFalse(is_admin(), 'na webu pro obyvatele se chová jako obyvatel (žádné úpravy)');
        $GLOBALS['METHOD'] = 'GET';
        $this->assertSame($this->id, (int) require_admin()['id'], 'stránky administrace smí zobrazit');
        $this->assertTrue($GLOBALS['AUDIT_READONLY']);
        $before = (int) val('SELECT COUNT(*) FROM settings');
        q("INSERT INTO settings (`key`, value) VALUES ('kk_test', '1')");
        $this->assertSame($before, (int) val('SELECT COUNT(*) FROM settings'), 'zápis do databáze je při prohlížení zamčený');
        unset($GLOBALS['AUDIT_READONLY']);
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/lib.php');
        $this->assertStringContainsString("Kontrolní komise má do administrace přístup jen pro čtení", $src, 'POST v administraci → 403');
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/layout.php');
        $this->assertStringContainsString("can_view_admin()", $layout);
        $this->assertStringContainsString('kontrolní komise', $layout);
    }

    /** Migrace v18: starší databáze s omezením role (admin/resident) dostane i roli kontrola, data zůstanou. */
    public function testOlderDatabaseAllowsAuditRoleAfterUpgrade(): void
    {
        $dir = sys_get_temp_dir() . '/svj-kk-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        try {
            $pdo = open_database($dir, '');
            $pdo->exec("INSERT INTO users (name, email, role, password_hash) VALUES ('Stará', 'stara@example.cz', 'admin', 'x')");
            // Vrátit původní omezení a verzi 17.
            $sql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE name = 'users'")->fetchColumn();
            $ver = (int) $pdo->query('PRAGMA schema_version')->fetchColumn();
            $pdo->exec('PRAGMA writable_schema = ON');
            $pdo->prepare("UPDATE sqlite_master SET sql = ? WHERE name = 'users'")->execute([str_replace("'admin','resident','kontrola'", "'admin','resident'", $sql)]);
            $pdo->exec('PRAGMA schema_version = ' . ($ver + 1));
            $pdo->exec('PRAGMA writable_schema = OFF');
            $pdo->exec('PRAGMA user_version = 17');
            unset($pdo);
            $old = new \PDO('sqlite:' . glob($dir . '/*.sqlite')[0]);
            try {
                $old->exec("INSERT INTO users (name, email, role, password_hash) VALUES ('K', 'k1@example.cz', 'kontrola', 'x')");
                $this->fail('stará databáze roli nezná');
            } catch (\PDOException) {
                $this->assertTrue(true);
            }
            unset($old);
            $pdo = open_database($dir, '');
            $this->assertSame(18, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
            $pdo->exec("INSERT INTO users (name, email, role, password_hash) VALUES ('K', 'k2@example.cz', 'kontrola', 'x')");
            $this->assertSame('admin', (string) $pdo->query("SELECT role FROM users WHERE email = 'stara@example.cz'")->fetchColumn(), 'data zůstala');
            $this->assertSame('ok', (string) $pdo->query('PRAGMA integrity_check')->fetchColumn());
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
