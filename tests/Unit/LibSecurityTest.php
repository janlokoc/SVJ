<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Passwords, TOTP, CSRF, audit chain and login throttling from app/lib.php. */
final class LibSecurityTest extends TestCase
{
    /** RFC 6238 SHA-1 seed '12345678901234567890' in base32. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private string $savedIp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    protected function tearDown(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->savedIp;
        parent::tearDown();
    }

    // ---------- password_problem ----------

    public function testPasswordTooShortOrTooLong(): void
    {
        $this->assertSame('Heslo musí mít alespoň 10 znaků.', password_problem('Kr4tke!'));
        $this->assertNotNull(password_problem('ŽluťKůň12'), '9 multibyte characters are still too short');
        $this->assertNull(password_problem('ŽluťKůň123'), '10 multibyte characters are enough');
        $this->assertStringContainsString('příliš dlouhé', (string) password_problem(str_repeat('ab', 101)));
        $this->assertNull(password_problem(str_repeat('ab', 100)));
    }

    public function testCommonAndTrivialPasswordsRejected(): void
    {
        foreach (['password123', 'PASSWORD123', 'Heslo12345', 'qwertzuiop', 'aaaaaaaaaaaa', '12345678901', '98765432109876'] as $p) {
            $this->assertStringContainsString('snadné uhodnout', (string) password_problem($p), $p);
        }
        $this->assertNull(password_problem('modrý slon tančí'));
    }

    public function testPasswordMustNotContainEmailOrEqualName(): void
    {
        $msg = 'Heslo nesmí obsahovat Váš e-mail ani jméno.';
        $this->assertSame($msg, password_problem('xxjana.vyborova!!', 'Jana.Vyborova@example.cz'));
        $this->assertSame($msg, password_problem('JANA.VYBOROVA2024', 'jana.vyborova@example.cz'), 'case-insensitive');
        // Short local parts (< 4 chars) are not checked – they would block too many passwords.
        $this->assertNull(password_problem('abcxyz-tajne', 'abc@example.cz'));
        $this->assertSame($msg, password_problem('Jana Výborová', '', 'jana výborová'));
        $this->assertNull(password_problem('jana výborová a pes', '', 'Jana Výborová'), 'only an exact name match is rejected');
        $this->assertNull(password_problem('úplně jiné heslo', 'jana.vyborova@example.cz', 'Jana Výborová'));
    }

    // ---------- random_password / pw_fingerprint ----------

    public function testRandomPasswordFormatAndStrength(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $p = random_password();
            $this->assertMatchesRegularExpression('/^[a-hjkmnp-zA-HJ-NP-Z2-9]{12}$/', $p, 'no ambiguous characters (0, O, 1, l, I, i, o)');
            $this->assertNull(password_problem($p), "generated password '$p' must pass the policy");
            $seen[$p] = true;
        }
        $this->assertCount(50, $seen, 'passwords are random');
    }

    public function testPwFingerprint(): void
    {
        $h = password_hash('nějaké heslo 123', PASSWORD_DEFAULT);
        $fp = pw_fingerprint($h);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $fp);
        $this->assertSame($fp, pw_fingerprint($h), 'stable');
        $this->assertSame(substr(hash('sha256', 'svj-session|' . $h), 0, 32), $fp);
        $this->assertNotSame($fp, pw_fingerprint($h . 'x'));
        $this->assertNotSame($fp, pw_fingerprint(password_hash('nějaké heslo 123', PASSWORD_DEFAULT)), 'new salt, new fingerprint');
    }

    // ---------- TOTP ----------

    public function testTotpNewSecretIsBase32Of160Bits(): void
    {
        $a = totp_new_secret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $a);
        $this->assertNotSame($a, totp_new_secret());
    }

    public function testTotpCodeMatchesRfc6238Vectors(): void
    {
        // RFC 6238 appendix B (SHA-1), 8-digit values truncated to the 6 least significant digits.
        $this->assertSame('287082', totp_code(self::RFC_SECRET, intdiv(59, 30)));           // 94287082
        $this->assertSame('081804', totp_code(self::RFC_SECRET, intdiv(1111111109, 30)));   // 07081804
        $this->assertSame('050471', totp_code(self::RFC_SECRET, intdiv(1111111111, 30)));   // 14050471
        $this->assertSame('005924', totp_code(self::RFC_SECRET, intdiv(1234567890, 30)));   // 89005924
        $this->assertSame('279037', totp_code(self::RFC_SECRET, intdiv(2000000000, 30)));   // 69279037
        $this->assertSame('353130', totp_code(self::RFC_SECRET, intdiv(20000000000, 30)));  // 65353130
        $this->assertSame('287082', totp_code(strtolower(self::RFC_SECRET), 1), 'secret is case-insensitive');
    }

    public function testTotpVerifyAcceptsWindowAndRejectsReplay(): void
    {
        $secret = totp_new_secret();
        if (time() % 30 >= 27) {
            sleep(4);   // avoid a step boundary in the middle of the test
        }
        $now = intdiv(time(), 30);
        $code = totp_code($secret, $now);

        $step = totp_verify($secret, $code);
        $this->assertNotNull($step);
        $this->assertSame($now, $step);
        $this->assertSame($now, totp_verify($secret, substr($code, 0, 3) . ' ' . substr($code, 3)), 'spaces are ignored');

        // Replay: a step that was already used (or older) is refused.
        $this->assertNull(totp_verify($secret, $code, $step));
        $this->assertNull(totp_verify($secret, $code, $step + 5));

        // ±1 step tolerance.
        $this->assertSame($now - 1, totp_verify($secret, totp_code($secret, $now - 1)));
        $this->assertSame($now + 1, totp_verify($secret, totp_code($secret, $now + 1)));
        $this->assertNull(totp_verify($secret, totp_code($secret, $now - 1), $now - 1), 'older step equal to last is refused');
        $this->assertNull(totp_verify($secret, totp_code($secret, $now - 3)), 'outside the window');
        $this->assertNull(totp_verify($secret, totp_code($secret, $now + 3)), 'outside the window');
    }

    public function testTotpVerifyRejectsMalformedCodes(): void
    {
        $secret = totp_new_secret();
        foreach (['', '12345', '1234567', 'abcdef', '12345a'] as $bad) {
            $this->assertNull(totp_verify($secret, $bad), "'$bad'");
        }
    }

    public function testTotpUri(): void
    {
        $uri = totp_uri('ABCDEF234567', 'SVJ: Zahradní 12', 'jana@example.cz');
        $this->assertSame(
            'otpauth://totp/SVJ%20%20Zahradn%C3%AD%2012:jana%40example.cz?secret=ABCDEF234567&issuer=SVJ%20%20Zahradn%C3%AD%2012&algorithm=SHA1&digits=6&period=30',
            $uri,
            'colon in issuer is replaced so it cannot split the label'
        );
    }

    // ---------- CSRF ----------

    public function testCsrfTokenIsStablePerSession(): void
    {
        $t = csrf_token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $t);
        $this->assertSame($t, csrf_token());
        $this->assertSame($t, $_SESSION['csrf']);
        $this->assertSame('<input type="hidden" name="_csrf" value="' . $t . '">', csrf_field());

        $_SESSION = [];
        $this->assertNotSame($t, csrf_token(), 'new session, new token');
    }

    public function testCheckCsrfPassesWithMatchingToken(): void
    {
        $_POST['_csrf'] = csrf_token();
        check_csrf();   // must return normally (failure would exit)
        $this->addToAssertionCount(1);
        // The failure branch calls exit and cannot be exercised in-process.
    }

    // ---------- Audit chain ----------

    public function testAuditWritesChainedRows(): void
    {
        $tag = 'unit-audit-' . bin2hex(random_bytes(4));
        $this->loginAs('admin');
        audit('test_action', $tag . str_repeat('x', 1200));
        $row = one('SELECT * FROM audit_log WHERE detail LIKE ? ORDER BY id DESC LIMIT 1', [$tag . '%']);
        $this->assertNotNull($row);
        $this->assertSame('test_action', $row['action']);
        $this->assertSame(1000, mb_strlen($row['detail']), 'detail is capped at 1000 chars');
        $this->assertSame((string) val('SELECT name FROM users WHERE id = ?', [$_SESSION['uid']]), $row['user_name']);
        $this->assertSame('příkazová řádka', $row['ip']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row['chain']);

        $prev = (string) val('SELECT chain FROM audit_log WHERE id < ? AND chain IS NOT NULL ORDER BY id DESC LIMIT 1', [$row['id']]);
        $this->assertSame(hash('sha256', implode('|', [$prev, $row['at'], $row['user_name'], $row['ip'], $row['action'], $row['detail']])), $row['chain']);
    }

    public function testAuditChainCheckDetectsTampering(): void
    {
        $tag = 'unit-chain-' . bin2hex(random_bytes(4));
        audit('test_0', $tag . '-0', null);   // tag-1 nesmí být první záznam s otiskem (ten smí navazovat na starší bez otisku)
        audit('test_a', $tag . '-1', null);
        audit('test_b', $tag . '-2', null);
        audit('test_c', $tag . '-3', null);
        [$ok, $bad, $checked] = audit_chain_check();
        $this->assertTrue($ok);
        $this->assertSame(0, $bad);
        $this->assertGreaterThanOrEqual(3, $checked);

        $target = one('SELECT id, detail FROM audit_log WHERE detail = ?', [$tag . '-2']);
        try {
            q('UPDATE audit_log SET detail = ? WHERE id = ?', ['přepsáno', $target['id']]);
            $this->assertSame([false, (int) $target['id']], array_slice(audit_chain_check(), 0, 2));
        } finally {
            q('UPDATE audit_log SET detail = ? WHERE id = ?', [$target['detail'], $target['id']]);
        }
        $this->assertTrue(audit_chain_check()[0], 'restored row validates again');

        // Deleting a row breaks the chain at the following row.
        $row = one('SELECT * FROM audit_log WHERE detail = ?', [$tag . '-1']);
        $next = (int) val('SELECT id FROM audit_log WHERE id > ? ORDER BY id LIMIT 1', [$row['id']]);
        try {
            q('DELETE FROM audit_log WHERE id = ?', [$row['id']]);
            $this->assertSame([false, $next], array_slice(audit_chain_check(), 0, 2));
        } finally {
            q('INSERT INTO audit_log (id, at, user_id, user_name, ip, action, detail, chain) VALUES (?,?,?,?,?,?,?,?)',
                [$row['id'], $row['at'], $row['user_id'], $row['user_name'], $row['ip'], $row['action'], $row['detail'], $row['chain']]);
        }
        $this->assertTrue(audit_chain_check()[0]);
    }

    // ---------- Login throttling ----------

    public function testLoginBlockedAfterEightFailuresFromOneIp(): void
    {
        $ip = '10.250.' . random_int(0, 255) . '.' . random_int(1, 254);
        $_SERVER['REMOTE_ADDR'] = $ip;
        $email = 'throttle-ip-' . bin2hex(random_bytes(3)) . '@example.cz';
        try {
            q('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
            for ($i = 0; $i < 7; $i++) {
                login_failed($email . $i);
            }
            $this->assertFalse(login_blocked(''));
            login_failed($email . '7');
            $this->assertTrue(login_blocked(''), '8th failure from the same IP blocks');
            $this->assertTrue(login_blocked('someone.else@example.cz'), 'IP block applies to any account');

            $_SERVER['REMOTE_ADDR'] = '10.251.0.1';
            $this->assertFalse(login_blocked('fresh-' . $email), 'other IPs are unaffected');

            // Attempts older than 15 minutes expire.
            q('UPDATE login_attempts SET at = ? WHERE ip = ?', [time() - 901, $ip]);
            $_SERVER['REMOTE_ADDR'] = $ip;
            $this->assertFalse(login_blocked(''));
            $this->assertSame(0, (int) val('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [$ip]), 'expired rows are purged');

            $this->assertSame('login_failed', val('SELECT action FROM audit_log WHERE detail = ? ORDER BY id DESC LIMIT 1', [$email . '7']));
        } finally {
            q('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
        }
    }

    /** Bezpečnost 11/2026: cizí účet nejde zablokovat pár pokusy z různých adres; z jedné adresy 10, celkem 50. */
    public function testAccountLockNeedsManyAddressesOrRepeatsFromOne(): void
    {
        $email = 'Throttle-Acct-' . bin2hex(random_bytes(3)) . '@Example.cz';
        try {
            for ($i = 0; $i < LOGIN_FAIL_ACCOUNT - 1; $i++) {
                $_SERVER['REMOTE_ADDR'] = '10.252.' . intdiv($i, 250) . '.' . ($i % 250 + 1);
                login_failed($email, 'login_failed_test');
            }
            $_SERVER['REMOTE_ADDR'] = '10.253.0.1';
            $this->assertFalse(login_blocked($email), '49 chyb z různých adres účet ještě nezablokuje');
            $_SERVER['REMOTE_ADDR'] = '10.252.9.9';
            login_failed($email, 'login_failed_test');
            $_SERVER['REMOTE_ADDR'] = '10.253.0.1';
            $this->assertTrue(login_blocked($email), '50 chyb na účet celkem → blokace');
            $this->assertTrue(login_blocked(mb_strtolower($email)), 'e-mail bez ohledu na velikost písmen');
            $this->assertFalse(login_blocked('other-' . $email));
            q('DELETE FROM login_attempts WHERE email = ?', [mb_strtolower($email)]);
            // Z jedné adresy: 10 chyb na účet (adresní limit 8 platí pro všechny účty – proto se tu adresa střídá s jinou).
            for ($i = 0; $i < LOGIN_FAIL_ACCOUNT_IP; $i++) {
                $_SERVER['REMOTE_ADDR'] = '10.254.0.' . ($i % 2 ? 1 : 2);
                login_failed($email, 'login_failed_test');
            }
            q('DELETE FROM login_attempts WHERE ip = ? AND email <> ?', ['10.254.0.1', mb_strtolower($email)]);
            $_SERVER['REMOTE_ADDR'] = '10.254.0.3';
            $this->assertFalse(login_blocked($email), 'jiná adresa se k účtu dostane');
        } finally {
            q('DELETE FROM login_attempts WHERE email = ?', [mb_strtolower($email)]);
            q("DELETE FROM login_attempts WHERE ip LIKE '10.25%'");
        }
        $sso = (string) file_get_contents(dirname(__DIR__, 2) . '/app/ext/routes_sso.php');
        $this->assertStringContainsString("if (login_blocked('')) {", $sso, 'SSO jen podle adresy');
    }
}
