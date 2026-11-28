<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Konzole: ztracená ověřovací aplikace – jednorázový kód e-mailem (vedle kódu v souboru na serveru). */
final class ConsoleMailCodeTest extends TestCase
{
    private string $from;

    protected function setUp(): void
    {
        parent::setUp();
        $this->from = pset('mail_from');
        pset_save('mail_from', 'sluzba@example.org');
        pset_save('super_2fa_mail', '');
    }

    protected function tearDown(): void
    {
        pset_save('mail_from', $this->from);
        pset_save('super_2fa_mail', '');
        parent::tearDown();
    }

    private function admin(): array
    {
        $email = 'spravce.' . bin2hex(random_bytes(4)) . '@example.org';
        pq('INSERT INTO super_admins (email, name, password_hash, created_at, totp_enabled) VALUES (?,?,?,?,1)', [$email, 'Správce', password_hash('heslo-pro-test-1', PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
        return pq('SELECT * FROM super_admins WHERE email = ?', [$email])->fetch();
    }

    private function sentCode(array $a): string
    {
        $this->assertNull(console_mail_code_send($a));
        $mail = array_values(array_filter($this->mails(), fn($m) => $m['to'] === $a['email']));
        $this->assertCount(1, $mail);
        $this->assertMatchesRegularExpression('/\b(\d{8})\b/', $mail[0]['body']);
        preg_match('/\b(\d{8})\b/', $mail[0]['body'], $m);
        return $m[1];
    }

    public function testCodeSignsInOnceAndKeepsTwoFactor(): void
    {
        $a = $this->admin();
        $code = $this->sentCode($a);
        $this->assertStringNotContainsString($code, pset('super_2fa_mail'), 'uložen jen otisk');
        $this->assertFalse(console_mail_code_check((int) $a['id'] + 999, $code), 'jen pro svůj účet');
        $this->assertTrue(console_mail_code_check((int) $a['id'], " $code "));
        $this->assertFalse(console_mail_code_check((int) $a['id'], $code), 'jednorázový');
        $this->assertSame(1, (int) pq('SELECT totp_enabled FROM super_admins WHERE id = ?', [$a['id']])->fetchColumn(), '2FA zůstává');
    }

    public function testFiveWrongTriesKillTheCode(): void
    {
        $a = $this->admin();
        $code = $this->sentCode($a);
        for ($i = 0; $i < CONSOLE_MAIL_CODE_TRIES; $i++) {
            $this->assertFalse(console_mail_code_check((int) $a['id'], '00000000'));
        }
        $this->assertFalse(console_mail_code_check((int) $a['id'], $code), 'po 5 chybách už ani správný kód');
    }

    public function testExpiredCodeIsRejected(): void
    {
        $a = $this->admin();
        $code = $this->sentCode($a);
        $s = json_decode(pset('super_2fa_mail'), true);
        $s['exp'] = time() - 1;
        pset_save('super_2fa_mail', json_encode($s));
        $this->assertFalse(console_mail_code_check((int) $a['id'], $code));
    }

    public function testRefusedAfterEmailPasswordResetAndWithoutSender(): void
    {
        $a = $this->admin();
        pset_save('super_pw_reset_' . $a['id'], (string) (time() - 86400));
        $this->assertStringContainsString('7 dnech', (string) console_mail_code_send($a), 'samotná schránka nesmí stačit k převzetí účtu');
        $this->assertSame('', pset('super_2fa_mail'));
        pset_save('super_pw_reset_' . $a['id'], (string) (time() - 8 * 86400));
        $this->assertNull(console_mail_code_refusal($a));
        pset_save('mail_from', '');
        $this->assertNotNull(console_mail_code_refusal($a));
    }

    public function testConsoleWiring(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/app/platform_console.php');
        $this->assertStringContainsString("pset_save('super_pw_reset_'", $src, 'obnova hesla e-mailem zapíše čas');
        $this->assertMatchesRegularExpression("~action'\) === 'mail_code' && !empty\(\\\$pending\['mail'\]\)~", $src);
        $this->assertStringContainsString('name="action" value="mail_start"', file_get_contents(dirname(__DIR__, 2) . '/app/views/platform/login.php'));
    }
}
