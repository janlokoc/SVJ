<?php
declare(strict_types=1);

// Konzole provozovatele: Kredit služeb (app/ext/credit_lib.php) – zůstatky domů, ruční oprava, vrácení zůstatku,
// měsíční spotřeba služeb (+ náklady katastru z rej_usage) a ceny služeb a doplňků.

if ($PATH === '/kredit') {
    if ($METHOD === 'POST') {
        $action = post('action');
        $slug = post('tenant');
        if ($action === 'fix') {
            $czk = str_replace([' ', ','], ['', '.'], post('amount'));
            $err = is_numeric($czk) ? credit_fix($slug, (int) round((float) $czk * 100), post('note'), $super['email']) : 'Zadejte částku v Kč (záporná = odečíst).';
            if ($err === null) {
                platform_log('Kredit služeb: oprava', $slug . ' ' . $czk . ' Kč · ' . post('note'));
            }
            flash($err ?? 'Kredit je upravený.', $err ? 'error' : 'ok');
            redirect('/kredit#domy');
        }
        if ($action === 'refund') {
            $h = credit_refund_all($slug, 'vrácení zůstatku' . (post('note') !== '' ? ': ' . post('note') : ''), $super['email']);
            if ($h > 0) {
                platform_log('Kredit služeb: vrácení zůstatku', $slug . ' · ' . credit_money($h));
            }
            flash($h > 0 ? 'Zůstatek ' . credit_money($h) . ' je odepsaný. Pošlete ho společenství převodem a vystavte opravný doklad k poslední faktuře za dobití (Platby → Storno).' : 'Společenství nemá žádný zůstatek.', $h > 0 ? 'ok' : 'info');
            redirect('/kredit#domy');
        }
        if ($action === 'prices') {
            $saved = [];
            foreach (credit_console_fields() as $key => [$label, $unit]) {
                $v = str_replace([' ', "\u{a0}", ','], ['', '', '.'], post($key));
                if ($v === '' || !is_numeric($v) || (float) $v < 0 || (float) $v > 1000000) {
                    continue;
                }
                if (in_array($key, ['credit_valid_months', 'rej_katastr_days'], true) && (int) $v < 1) {
                    continue;
                }
                $store = $unit === 'h' ? (string) (int) round((float) $v * 100) : (string) (int) $v;
                pset_save($key, $store);
                $saved[] = $key . '=' . $store;
            }
            platform_log('Kredit služeb: ceny', implode(', ', $saved));
            flash('Uloženo. Nové ceny platí pro další čerpání; doplněk katastru se za nové ceny účtuje od další faktury.');
            redirect('/kredit#ceny');
        }
        redirect('/kredit');
    }
    $month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mesic'] ?? '')) ? (string) $_GET['mesic'] : date('Y-m');
    $from = $month . '-01';
    $to = date('Y-m-d', strtotime($from . ' +1 month'));
    $names = array_column(pq('SELECT slug, name FROM tenants')->fetchAll(), 'name', 'slug');
    render('ext/credit/console', ['title' => 'Kredit služeb', 'month' => $month, 'names' => $names, 'balances' => credit_balances(),
        'usage' => credit_usage_report($from, $to), 'totals' => credit_totals($from, $to), 'rej' => function_exists('rej_usage_report') ? rej_usage_report($from, $to) : [],
        'fields' => credit_console_fields(), 'addons' => pq('SELECT a.*, t.name FROM tenant_addons a LEFT JOIN tenants t ON t.slug = a.tenant WHERE a.active = 1 ORDER BY t.name')->fetchAll(),
        'ledger' => pq('SELECT * FROM credit_ledger WHERE delta_h <> 0 ORDER BY id DESC LIMIT 40')->fetchAll()]);
    exit;
}
