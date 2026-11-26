<?php
declare(strict_types=1);

// Konzole provozovatele: SMS brána (volba, stav klíčů v config.php, cena pro zákazníky), kredity společenství a zkušební SMS.

if ($PATH === '/sms') {
    sms_ledger_ready();
    if ($METHOD === 'POST') {
        $action = post('action');
        if ($action === 'settings') {
            $provider = post('provider');
            $price = str_replace([',', ' '], ['.', ''], post('price'));
            $pack = post('pack');
            if (!isset(SMS_PROVIDERS[$provider]) || !is_numeric($price) || (float) $price < 0 || (float) $price > 100 || !ctype_digit($pack) || (int) $pack < 1 || (int) $pack > 100000) {
                flash('Zkontrolujte bránu, cenu (0–100 Kč) a velikost balíčku.', 'error');
                redirect('/sms');
            }
            pset_save('sms_provider', $provider);
            pset_save('sms_price_h', (string) (int) round((float) $price * 100));
            pset_save('sms_pack', (string) (int) $pack);
            platform_log('SMS: nastavení', SMS_PROVIDERS[$provider] . ', ' . $price . ' Kč/SMS, balíček ' . $pack);
            flash('Uloženo.' . (sms_config_missing($provider) ? ' Pozor: v config.php chybí ' . implode(', ', sms_config_missing($provider)) . '.' : ''));
            redirect('/sms');
        }
        if ($action === 'credit') {
            $n = (int) post('count');
            $err = sms_credit_add(post('tenant'), $n, post('note'), $super['email']);
            if ($err === null) {
                platform_log('SMS: kredit', post('tenant') . ' ' . ($n > 0 ? '+' : '') . $n . (post('note') !== '' ? ' · ' . post('note') : ''));
                $t = tenant_row(post('tenant'));
                if ($n > 0 && $t && filter_var($t['owner_email'], FILTER_VALIDATE_EMAIL)) {
                    platform_mail($t['owner_email'], 'SMS kredit připsán – ' . $t['name'], "Dobrý den,\n\nwebu " . $t['name'] . " jsme připsali $n SMS. Zůstatek je " . sms_balance($t['slug'])
                        . " SMS.\nZprávy vlastníkům pošlete v administraci: " . tenant_url($t, '/admin/sms') . "\n\nDěkujeme.");
                }
            }
            flash($err ?? 'Kredit je upravený.', $err ? 'error' : 'ok');
            redirect('/sms#kredity');
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
    $balances = array_column(pq('SELECT tenant, SUM(delta) AS n, MAX(at) AS last FROM sms_ledger GROUP BY tenant')->fetchAll(), null, 'tenant');
    render('ext/sms/console', ['title' => 'SMS brána a kredity', 'provider' => pset('sms_provider') !== '' ? pset('sms_provider') : SMS_DEFAULTS['sms_provider'], 'effective' => sms_provider_name(),
        'price' => sms_price_h(), 'pack' => sms_pack(), 'tenants' => pq('SELECT slug, name FROM tenants ORDER BY name')->fetchAll(), 'balances' => $balances,
        'ledger' => pq('SELECT * FROM sms_ledger ORDER BY id DESC LIMIT 40')->fetchAll(), 'stage' => beta_stage('sms')]);
    exit;
}
