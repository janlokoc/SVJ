<?php
declare(strict_types=1);

// Modul I: přihlášení do partnerského portálu přes Google, Microsoft, Apple a Facebook (adresy /partner/sso/…).
// Načítá app/partner_portal.php ještě před přihlašovací stránkou; proměnné $pu, $signIn, $blocked, $failed jsou z portálu.

sso_platform_routes('partner', $PATH, $METHOD, $pu, $signIn, $blocked, $failed);
