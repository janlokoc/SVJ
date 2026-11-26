<?php
declare(strict_types=1);

// Konzole provozovatele: Katastr a rejstříky (modul E) – ceny pro zákazníky, náklady, stav propojení a přehled spotřeby.

if ($PATH === '/rejstriky') {
    if ($METHOD === 'POST' && post('action') === 'prices') {
        foreach (REJ_PRICES as $key => [$default, $label]) {
            $v = preg_replace('/\s+/', '', post($key));
            if ($v !== '' && ctype_digit($v) && (int) $v <= 1000000 && ($key !== 'rej_katastr_days' || (int) $v >= 1)) {
                pset_save($key, (string) (int) $v);
            }
        }
        platform_log('Katastr a rejstříky: ceny', implode(', ', array_map(fn($k) => $k . '=' . rej_price($k), array_keys(REJ_PRICES))));
        flash('Uloženo.');
        redirect('/rejstriky');
    }
    if ($METHOD === 'POST' && in_array(post('action'), ['test_wsdp', 'test_cee'], true)) {
        if (post('action') === 'test_wsdp') {
            $r = rej_wsdp_call('sestavy', 'stavWS', 'StavWSRequest', []);
            $ok = $r['ok'];
            $text = $ok ? 'Dálkový přístup do katastru odpovídá a přihlášení platí.' : 'Katastr: ' . $r['error'];
        } else {
            $r = rej_cee_token(true);
            $ok = $r['error'] === '';
            $text = $ok ? 'Evidence exekucí vydala token – API klíč platí.' : $r['error'];
        }
        platform_log('Katastr a rejstříky: zkouška spojení', post('action') . ' · ' . ($ok ? 'v pořádku' : 'chyba'));
        flash($text, $ok ? 'ok' : 'error');
        redirect('/rejstriky');
    }
    $month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mesic'] ?? '')) ? (string) $_GET['mesic'] : date('Y-m');
    $from = $month . '-01';
    $to = date('Y-m-d', strtotime($from . ' +1 month'));
    $names = array_column(pq('SELECT slug, name FROM tenants')->fetchAll(), 'name', 'slug');
    render('ext/rejstriky/console', ['title' => 'Katastr a rejstříky', 'month' => $month, 'report' => rej_usage_report($from, $to), 'names' => $names,
        'wsdp' => rej_katastr_configured(), 'cee' => rej_cee_configured(),
        'stages' => array_map(fn($k) => BETA_STAGES[beta_stage($k)], ['katastr' => 'katastr', 'insolvence' => 'insolvence', 'exekuce' => 'exekuce'])]);
    exit;
}
