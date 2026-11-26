<?php
declare(strict_types=1);

// Konzole provozovatele: SMS brána (volba, stav klíčů v config.php, cena pro zákazníky) a zkušební SMS. Kredit služeb domů: /kredit (console_kredit.php).

if ($PATH === '/sms') {
    if ($METHOD === 'POST') {
        $action = post('action');
        if ($action === 'settings') {
            $provider = post('provider');
            $price = str_replace([',', ' '], ['.', ''], post('price'));
            if (!isset(SMS_PROVIDERS[$provider]) || !is_numeric($price) || (float) $price < 0 || (float) $price > 100) {
                flash('Zkontrolujte bránu a cenu (0–100 Kč).', 'error');
                redirect('/sms');
            }
            pset_save('sms_provider', $provider);
            pset_save('sms_price_h', (string) (int) round((float) $price * 100));
            platform_log('SMS: nastavení', SMS_PROVIDERS[$provider] . ', ' . $price . ' Kč/SMS');
            flash('Uloženo.' . (sms_config_missing($provider) ? ' Pozor: v config.php chybí ' . implode(', ', sms_config_missing($provider)) . '.' : ''));
            redirect('/sms');
        }
        if ($action === 'test') {
            $to = sms_normalize(post('phone'));
            $text = trim(post('text')) !== '' ? trim(post('text')) : 'Zkouska SMS brany ' . pset('service_name');
            $r = $to === null ? ['ok' => false, 'error' => 'Číslo nemá správný tvar.'] : sms_provider()->send($to, $text, sms_segments($text)['encoding'] === 'UCS-2');
            platform_log('SMS: zkouška brány', sms_provider_name() . ' · ' . ($r['ok'] ? 'v pořádku' : 'chyba'));
            flash($r['ok'] ? 'Zpráva předána bráně ' . SMS_PROVIDERS[sms_provider_name()] . '.' : 'Nepovedlo se: ' . $r['error'], $r['ok'] ? 'ok' : 'error');
            redirect('/sms#zkouska');
        }
        redirect('/sms');
    }
    render('ext/sms/console', ['title' => 'SMS brána', 'provider' => pset('sms_provider') !== '' ? pset('sms_provider') : SMS_DEFAULTS['sms_provider'], 'effective' => sms_provider_name(),
        'price' => sms_price_h(), 'free' => credit_setting('sms_free_premium'), 'stage' => beta_stage('sms')]);
    exit;
}
