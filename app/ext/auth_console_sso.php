<?php
declare(strict_types=1);

// Modul I: přihlášení do konzole provozovatele přes Google, Microsoft, Apple a Facebook (adresy /sprava/sso/…).
// Výchozí stav vypnuto (Nastavení služby → Přihlášení přes další účty). Načítá app/platform_console.php před přihlašovací
// stránkou; proměnné $super, $signIn, $blocked, $failed jsou z konzole.

sso_platform_routes('console', $PATH, $METHOD, $super, $signIn, $blocked, $failed);
