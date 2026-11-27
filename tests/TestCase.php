<?php
declare(strict_types=1);

namespace Tests;

/** Společný základ: přístup k zachyceným e-mailům a úklid stavu mezi testy. */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** Zachycené e-maily od začátku testu: [['to', 'subject', 'body'], …]. */
    protected function mails(): array
    {
        $f = getenv('SVJ_MAIL_CAPTURE');
        if (!is_file($f)) {
            return [];
        }
        return array_map(fn($l) => json_decode($l, true), array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), $this->mailStart));
    }
    private int $mailStart = 0;

    protected function setUp(): void
    {
        $f = getenv('SVJ_MAIL_CAPTURE');
        $this->mailStart = is_file($f) ? count(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
        $_SESSION = [];
        $_POST = $_GET = [];
        user(true);   // přihlášení z předchozího testu nesmí přetrvat
        // Hodinový limit fronty e-mailů počítá odeslané zprávy všech testů dohromady; bez úklidu by podle pořadí testů
        // (--order-by=random) hromadné zprávy v pozdějších testech začaly čekat ve frontě.
        if (function_exists('mailq_pdo')) {
            mailq_pdo()->exec('DELETE FROM mailq WHERE sent_at IS NOT NULL');
        }
    }
    /** Přihlásí uživatele (bez hesla) jako v požadavku webu. */
    protected function loginAs(string $role = 'admin'): array
    {
        $u = one('SELECT * FROM users WHERE role = ? AND active = 1 ORDER BY id LIMIT 1', [$role]);
        $_SESSION['uid'] = (int) $u['id'];
        $_SESSION['pv'] = pw_fingerprint($u['password_hash']);   // jinak ho user() odhlásí
        user(true);
        return $u;
    }
}
