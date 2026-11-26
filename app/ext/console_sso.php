<?php
declare(strict_types=1);

// Modul I: přepínače SSO pro partnerský portál a konzoli (Nastavení služby → Přihlášení přes další účty).

if ($PATH === '/nastaveni/sso' && $METHOD === 'POST') {
    $partnerOn = isset($_POST['sso_partner']) ? '1' : '0';
    $consoleOn = isset($_POST['sso_console']) ? '1' : '0';
    $changed = [];
    foreach (['sso_partner' => $partnerOn, 'sso_console' => $consoleOn] as $k => $v) {
        if ((pset($k) === '' ? ($k === 'sso_partner' ? '1' : '0') : pset($k)) !== $v) {
            $changed[] = $k . '=' . $v;
        }
        pset_save($k, $v);
    }
    platform_log('Přihlášení přes další účty: nastavení', implode(', ', $changed));
    flash('Nastavení přihlášení přes další účty je uloženo.');
    redirect('/nastaveni#sso');
}
