<?php
declare(strict_types=1);

// Modul I: jedna návratová adresa pro každého poskytovatele SSO na hlavní doméně služby (https://<doména>/sso/callback/<poskytovatel>).
// Odsud se prohlížeč s jednorázovým tokenem vrátí na web domu, do partnerského portálu nebo do konzole (viz app/ext/sso_lib.php).
// Apple odpovídá metodou POST (form_post) z cizí domény – proto je tato adresa vyjmutá z kontroly formulářového tokenu (index.php);
// ochranu zajišťuje podepsaný state a jednorázový záznam toku na serveru.

if (preg_match('~^/sso/callback/(google|microsoft|apple|facebook)$~', $PATH, $ssoM)) {
    sso_callback_respond($ssoM[1]);
}
