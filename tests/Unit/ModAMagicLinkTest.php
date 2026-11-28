<?php
declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use Tests\TestCase;

/** Modul A – přihlášení odkazem z e-mailu (app/ext/moda_lib.php). */
final class ModAMagicLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        moda_ready();
        q('DELETE FROM login_attempts');
        q('DELETE FROM login_links');
    }

    protected function tearDown(): void
    {
        q('UPDATE users SET totp_enabled = 0, active = 1');
        q('DELETE FROM login_attempts');
        $_SESSION = [];
        parent::tearDown();
    }

    private function decodedMails(): array
    {
        return array_map(fn($m) => ['to' => $m['to'], 'subject' => mb_decode_mimeheader($m['subject']), 'body' => quoted_printable_decode($m['body'])], $this->mails());
    }

    /** Odkaz z posledního zachyceného e-mailu. */
    private function lastToken(): string
    {
        $mails = $this->decodedMails();
        $this->assertNotEmpty($mails);
        $this->assertSame(1, preg_match('~/prihlaseni/odkaz/([a-f0-9]{64})~', end($mails)['body'], $m));
        return $m[1];
    }

    public function testReadyCreatesTablesOnFreshDatabaseAndIsIdempotent(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        moda_ready($pdo);
        moda_ready($pdo);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('login_links', 'digest_prefs', 'poll_reminders') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['digest_prefs', 'login_links', 'poll_reminders'], $tables);
        $cols = array_column($pdo->query('PRAGMA table_info(login_links)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $this->assertSame(['id', 'user_id', 'token_hash', 'created', 'expires', 'used', 'ip'], $cols);
    }

    public function testRequestSendsOneTimeLinkValidFifteenMinutes(): void
    {
        $this->assertSame('sent', magic_link_request('petr.novak@example.cz'));
        $mails = $this->decodedMails();
        $this->assertCount(1, $mails);
        $this->assertSame('petr.novak@example.cz', $mails[0]['to']);
        $this->assertStringContainsString('Přihlášení na web', $mails[0]['subject']);
        $this->assertStringContainsString('platí 15 minut a jen jednou', $mails[0]['body']);
        $token = $this->lastToken();
        $row = one('SELECT * FROM login_links WHERE token_hash = ?', [hash('sha256', $token)]);
        $this->assertNotNull($row, 'v databázi je jen otisk odkazu');
        $this->assertNull(one('SELECT * FROM login_links WHERE token_hash = ?', [$token]), 'samotný odkaz se neukládá');
        $this->assertEqualsWithDelta(900, (int) $row['expires'] - (int) $row['created'], 1);
        $this->assertSame('Žádost o odkaz pro přihlášení', val('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1'));
    }

    public function testLinkCanBeUsedOnlyOnce(): void
    {
        magic_link_request('petr.novak@example.cz');
        $token = $this->lastToken();
        $this->assertNotNull(magic_link_find($token), 'zobrazení stránky odkaz nespotřebuje');
        $this->assertNotNull(magic_link_find($token));
        $u = magic_link_consume($token);
        $this->assertSame('petr.novak@example.cz', $u['email']);
        $this->assertNull(magic_link_find($token));
        $this->assertNull(magic_link_consume($token), 'podruhé odkaz neplatí');
    }

    public function testExpiredLinkIsRejected(): void
    {
        magic_link_request('petr.novak@example.cz');
        $token = $this->lastToken();
        q('UPDATE login_links SET expires = ? WHERE token_hash = ?', [time() - 1, hash('sha256', $token)]);
        $this->assertNull(magic_link_find($token));
        $this->assertNull(magic_link_consume($token));
    }

    public function testInvalidLinkCountsAsFailedLogin(): void
    {
        $this->assertNull(magic_link_consume(str_repeat('a', 64)));
        $this->assertNull(magic_link_consume('neplatny'));
        $this->assertSame(2, (int) val('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [client_ip()]));
        $this->assertSame('Neplatný odkaz pro přihlášení', val('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1'));
    }

    public function testDeactivatedAccountCannotUseLink(): void
    {
        magic_link_request('tomas.dvorak@example.cz');
        $token = $this->lastToken();
        q("UPDATE users SET active = 0 WHERE email = 'tomas.dvorak@example.cz'");
        $this->assertNull(magic_link_consume($token));
    }

    public function testUnknownEmailGetsSameAnswerAndNoMail(): void
    {
        $this->assertSame('sent', magic_link_request('nikdo@example.cz'));
        $this->assertSame('sent', magic_link_request('neni e-mail'));
        $this->assertSame([], $this->mails());
        $this->assertSame(0, (int) val('SELECT COUNT(*) FROM login_links'));
    }

    public function testAccountLimitThreeLinksPerQuarterHour(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame('sent', magic_link_request('eva.svobodova@example.cz'), 'navenek vždy stejná odpověď');
        }
        $this->assertCount(MAGIC_LINK_PER_ACCOUNT, $this->mails());
        $this->assertStringContainsString('příliš mnoho žádostí', (string) val('SELECT detail FROM audit_log ORDER BY id DESC LIMIT 1'));
    }

    public function testIpLimitBlocksFurtherRequests(): void
    {
        for ($i = 0; $i < MAGIC_LINK_PER_IP; $i++) {
            $this->assertSame('sent', magic_link_request('nikdo' . $i . '@example.cz'));
        }
        $this->assertSame('blocked', magic_link_request('petr.novak@example.cz'));
        $this->assertSame([], $this->mails());
    }

    /** Bezpečnost 11/2026: útok hesly na účet nesmí majiteli zabránit přihlásit se odkazem z e-mailu. */
    public function testAccountUnderPasswordAttackStillGetsLink(): void
    {
        for ($i = 0; $i < LOGIN_FAIL_ACCOUNT; $i++) {
            q('INSERT INTO login_attempts (ip, at, email) VALUES (?,?,?)', ['10.0.' . intdiv($i, 200) . '.' . ($i % 200), time(), 'hana.prochazkova@example.cz']);
        }
        $this->assertTrue(login_blocked('hana.prochazkova@example.cz'), 'heslem se teď nepřihlásí');
        $this->assertSame('sent', magic_link_request('hana.prochazkova@example.cz'));
        $this->assertCount(1, $this->mails(), 'odkaz z e-mailu ale dostane');
    }

    public function testPendingInviteGetsInviteInsteadOfLink(): void
    {
        q('INSERT INTO users (email, name, password_hash, role, kind) VALUES (?,?,?,?,?)', ['pozvany-' . bin2hex(random_bytes(3)) . '@example.cz', 'Pozvaný Nový', NO_PASSWORD, 'resident', 'owner']);
        $email = (string) val('SELECT email FROM users ORDER BY id DESC LIMIT 1');
        $this->assertSame('sent', magic_link_request($email));
        $mails = $this->decodedMails();
        $this->assertCount(1, $mails);
        $this->assertStringNotContainsString('/prihlaseni/odkaz/', $mails[0]['body']);
        $this->assertStringContainsString('/pozvanka/', $mails[0]['body']);
        $this->assertSame(0, (int) val('SELECT COUNT(*) FROM login_links'));
        q('DELETE FROM users WHERE email = ?', [$email]);
    }

    public function testNoMailWithoutSender(): void
    {
        $old = setting('mail_from');
        moda_setting('mail_from', '');
        try {
            $this->assertSame('nomail', magic_link_request('petr.novak@example.cz'));
            $this->assertSame([], $this->mails());
        } finally {
            moda_setting('mail_from', $old);
        }
    }

    public function testLoginWithoutTwoFactorSignsIn(): void
    {
        $u = one("SELECT * FROM users WHERE email = 'petr.novak@example.cz'");
        $this->assertSame('/', magic_link_login($u));
        $this->assertSame((int) $u['id'], $_SESSION['uid']);
        $this->assertSame(pw_fingerprint($u['password_hash']), $_SESSION['pv']);
        $this->assertSame('login', val('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1'));
        $this->assertNotNull(val('SELECT last_login_at FROM users WHERE id = ?', [$u['id']]));
    }

    public function testLoginRedirectTargets(): void
    {
        $admin = one("SELECT * FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        $this->assertSame('/admin', magic_link_login($admin));
        $this->assertSame('/dokumenty', magic_link_login($admin, '/dokumenty'));
        $this->assertSame('/admin', magic_link_login($admin, '//zly.example.com/'), 'přesměrování na cizí web se ignoruje');
    }

    public function testTwoFactorStillRequiresCode(): void
    {
        q("UPDATE users SET totp_enabled = 1, totp_secret = 'JBSWY3DPEHPK3PXP' WHERE email = 'tomas.dvorak@example.cz'");
        $u = one("SELECT * FROM users WHERE email = 'tomas.dvorak@example.cz'");
        $this->assertSame('/prihlaseni/overeni', magic_link_login($u, '/hlasovani'));
        $this->assertArrayNotHasKey('uid', $_SESSION, 'bez kódu z aplikace se nepřihlásí');
        $this->assertSame((int) $u['id'], $_SESSION['pending']['uid']);
        $this->assertSame('/hlasovani', $_SESSION['pending']['after']);
        $this->assertSame('Přihlášení odkazem z e-mailu', val('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1'));
    }

    public function testTwoFactorMailMentionsCode(): void
    {
        q("UPDATE users SET totp_enabled = 1 WHERE email = 'tomas.dvorak@example.cz'");
        magic_link_request('tomas.dvorak@example.cz');
        $this->assertStringContainsString('kód z ověřovací aplikace', $this->decodedMails()[0]['body']);
    }
}
