<?php
// Záchrana, když se správce nemůže přihlásit: nastaví účtu nové dočasné heslo a vypne dvoufázové ověření.
// Spuštění z příkazové řádky:  php tools/reset-password.php vas@email.cz
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/../app/lib.php';
$u = one('SELECT * FROM users WHERE email = ?', [$argv[1] ?? '']);
if (!$u) {
    fwrite(STDERR, "Účet s tímto e-mailem neexistuje. Použití: php tools/reset-password.php vas@email.cz\n");
    exit(1);
}
$pass = random_password();
q('UPDATE users SET password_hash = ?, must_change_password = 1, active = 1, totp_secret = NULL, totp_enabled = 0, totp_last = 0, invite_hash = NULL WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
q('DELETE FROM login_attempts');
audit('password_reset', $u['email'] . ' (z příkazové řádky serveru)', (int) $u['id']);
echo "Dočasné heslo pro {$u['email']}: $pass\nPo přihlášení si ho hned změňte.\n";
