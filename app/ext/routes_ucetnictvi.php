<?php
declare(strict_types=1);

// Modul D – účetnictví: export do POHODY, Money S3, ABRA Flexi a CSV, import jednotek a předpisů s přiřazením sloupců.
// Knihovna: app/ext/acct_lib.php. Předpisy, stavy účtů a platby z banky patří k funkci Platby na jednotku (plan_has('platby')).

$acctPlatby = fn(): bool => !function_exists('plan_has') || plan_has('platby');

$route('GET', '/admin/ucetnictvi', function () use ($acctPlatby) {
    require_admin();
    $house = acct_house_current();
    render('ext/ucetnictvi/admin', ['title' => 'Účetnictví – export a import', 'house' => $house, 'platby' => $acctPlatby(), 'params' => acct_export_params($_GET),
        'stats' => ['units' => (int) val('SELECT COUNT(*) FROM units'), 'accounts' => acct_table_exists(db(), 'unit_accounts') ? (int) val('SELECT COUNT(*) FROM unit_accounts') : 0,
            'bank' => acct_table_exists(db(), 'bank_tx') ? (int) val('SELECT COUNT(*) FROM bank_tx') : 0]]);
});
$route('POST', '/admin/ucetnictvi', function () use ($acctPlatby) {
    require_admin();
    if (post('action') === 'settings') {
        $in = ['acct_bank_code' => mb_substr(post('acct_bank_code'), 0, 20), 'acct_due_day' => (string) max(1, min(28, (int) post('acct_due_day'))),
            'acct_flexi_receivable' => mb_substr(post('acct_flexi_receivable'), 0, 20), 'acct_flexi_bank_type' => mb_substr(post('acct_flexi_bank_type'), 0, 20)];
        foreach ($in as $k => $v) {
            acct_setting_save(db(), $k, preg_replace('/[\x00-\x1F<>&"]/u', '', $v));
        }
        setting('__reset');
        audit('Účetnictví: nastavení exportu');
        flash('Nastavení exportu je uložené.');
        redirect('/admin/ucetnictvi#nastaveni');
    }
    if (post('action') === 'upload') {
        $target = isset(ACCT_TARGETS[post('target')]) ? post('target') : 'units';
        if ($target === 'accounts' && !$acctPlatby()) {
            flash('Předpisy a stavy účtů patří k funkci Platby na jednotku (edice Premium).', 'error');
            redirect('/admin/ucetnictvi#import');
        }
        $f = $_FILES['file'] ?? null;
        $data = $f && ($f['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name']) && $f['size'] <= 5_000_000 ? (string) file_get_contents($f['tmp_name']) : '';
        $table = $data !== '' ? acct_read_table($data, (string) $f['name']) : ['error' => 'Vyberte soubor CSV nebo XLSX do 5 MB.'];
        if ($table['error'] !== '' || !$table['rows']) {
            flash($table['error'] !== '' ? $table['error'] : 'V souboru nejsou žádné řádky s daty.', 'error');
            redirect('/admin/ucetnictvi#import');
        }
        $_SESSION['acct_imp'] = ['target' => $target, 'name' => mb_substr(basename((string) $f['name']), 0, 120), 'preset' => isset(ACCT_PRESETS[post('preset')]) ? post('preset') : '', 'table' => $table];
        redirect('/admin/ucetnictvi/import');
    }
    redirect('/admin/ucetnictvi');
});
$route('GET', '/admin/ucetnictvi/export', function () use ($acctPlatby) {
    require_admin();
    if (function_exists('plan_has') && !plan_has('ucetnictvi-export')) {
        // Export patří k edici Standard (i po skončené zkoušce – data zůstávají na webu, jen se nestahují).
        flash('Export do účetnictví je součástí edice ' . EDITIONS[PLAN_FEATURES['ucetnictvi-export'][0]] . '.', 'error');
        redirect('/admin/predplatne#funkce');
    }
    $params = acct_export_params($_GET);
    if (!$acctPlatby()) {
        $params['parts'] = ['addressbook'];   // předpisy a platby z banky jen s funkcí Platby na jednotku
    }
    $house = acct_house_current();
    $files = acct_export_files(db(), $house, $params);
    if (!$acctPlatby()) {
        $files = array_filter($files, fn($n) => !str_ends_with($n, '-salda.csv'), ARRAY_FILTER_USE_KEY);
    }
    audit('Účetnictví: export', ACCT_FORMATS[$params['format']] . ' · ' . implode(', ', array_map(fn($p) => ACCT_PARTS[$p], $params['parts'])));
    if (count($files) === 1) {
        $name = (string) array_key_first($files);
        acct_send($files[$name], $name, str_ends_with($name, '.csv') ? 'text/csv; charset=utf-8' : 'application/xml; charset=' . ($params['format'] === 'pohoda' ? 'windows-1250' : 'utf-8'));
    }
    acct_send(acct_zip($files), acct_filename_base($house) . '-ucetnictvi-' . date('Y-m-d') . '.zip', 'application/zip');
});
$acctImport = function () use ($acctPlatby) {
    require_admin();
    $imp = $_SESSION['acct_imp'] ?? null;
    if (!$imp) {
        flash('Nejdřív nahrajte soubor.', 'info');
        redirect('/admin/ucetnictvi#import');
    }
    $house = acct_house_current();
    $fields = acct_fields($imp['target']);
    $table = $imp['table'];
    $restored = false;
    if ($GLOBALS['METHOD'] === 'POST') {
        if (post('action') === 'cancel') {
            unset($_SESSION['acct_imp']);
            redirect('/admin/ucetnictvi#import');
        }
        $map = acct_mapping_from_input((array) ($_POST['map'] ?? []), $table['headers'], $fields);
    } else {
        $saved = acct_mapping_restore(setting('acct_map_' . $imp['target']), $table['headers'], $fields);
        $restored = $saved !== null && $imp['preset'] === '';
        $map = $restored ? $saved : acct_guess_mapping($table['headers'], array_keys($fields), $imp['preset']);
    }
    $asOf = valid_date(post('as_of')) ? post('as_of') : date('Y-m-d');
    $preview = acct_preview(db(), $house, $imp['target'], $table, $map);
    if ($GLOBALS['METHOD'] === 'POST' && post('action') === 'save') {
        if ($imp['target'] === 'accounts' && !$acctPlatby()) {
            redirect('/admin/ucetnictvi');
        }
        if (!$preview['missing'] && $preview['ok'] > 0) {
            if ($imp['target'] === 'accounts') {
                bank_ready();
            }
            $res = acct_apply(db(), $imp['target'], $preview, $asOf);
            acct_setting_save(db(), 'acct_map_' . $imp['target'], acct_mapping_export($map, $table['headers']));
            setting('__reset');
            unset($_SESSION['acct_imp']);
            audit('Účetnictví: import – ' . ACCT_TARGETS[$imp['target']][0], 'soubor ' . $imp['name'] . ', upraveno ' . $res['updated'] . ', založeno ' . $res['created'] . ($preview['bad'] ? ', s chybou vynecháno ' . $preview['bad'] : ''));
            flash('Import hotov: upraveno ' . $res['updated'] . ($res['created'] ? ', nově založeno ' . $res['created'] : '') . ($preview['bad'] ? '. Řádky s chybou (' . $preview['bad'] . ') se vynechaly.' : '.'));
            redirect($imp['target'] === 'accounts' ? '/admin/platby' : '/admin/jednotky');
        }
        flash('Není co uložit – opravte přiřazení sloupců nebo soubor.', 'error');
    }
    render('ext/ucetnictvi/import', ['title' => 'Import – přiřazení sloupců', 'imp' => $imp, 'fields' => $fields, 'map' => $map, 'preview' => $preview, 'asOf' => $asOf,
        'restored' => $restored, 'action' => url('/admin/ucetnictvi/import'), 'multi' => false, 'groups' => []]);
};
$route('GET', '/admin/ucetnictvi/import', $acctImport);
$route('POST', '/admin/ucetnictvi/import', $acctImport);
