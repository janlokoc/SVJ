<?php
declare(strict_types=1);

// Modul D – účetnictví v partnerském portálu: export všech domů najednou (ZIP, složka za dům)
// a import jednoho souboru za více domů (sloupec „dům“ – název, IČO nebo adresa webu domu).
// Proměnné z app/partner_portal.php: $PATH, $METHOD, $pu, $partner, $plog.

if ($PATH === '/ucetnictvi' || str_starts_with($PATH, '/ucetnictvi/')) {
    $houses = partner_tenants((int) $partner['id']);
    $mapKey = fn(string $target) => 'acct_map_p' . (int) $partner['id'] . '_' . $target;

    if ($PATH === '/ucetnictvi/export' && $METHOD === 'GET') {
        $params = acct_export_params($_GET);
        $chosen = array_map('strval', (array) ($_GET['domy'] ?? array_column($houses, 'slug')));
        $sel = array_values(array_filter($houses, fn($h) => in_array($h['slug'], $chosen, true)));
        [$zip, $failed] = acct_partner_zip($sel ?: $houses, $params);
        $plog('export do účetnictví', ACCT_FORMATS[$params['format']] . ' · domů ' . count($sel ?: $houses) . ($failed ? ' · nepodařilo se: ' . implode(', ', array_keys($failed)) : ''));
        acct_send($zip, 'ucetnictvi-' . date('Y-m-d') . '.zip', 'application/zip');
    }
    if ($PATH === '/ucetnictvi' && $METHOD === 'POST' && post('action') === 'upload') {
        $f = $_FILES['file'] ?? null;
        $data = $f && ($f['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name']) && $f['size'] <= 5_000_000 ? (string) file_get_contents($f['tmp_name']) : '';
        $table = $data !== '' ? acct_read_table($data, (string) $f['name']) : ['error' => 'Vyberte soubor CSV nebo XLSX do 5 MB.'];
        if ($table['error'] !== '' || !$table['rows']) {
            flash($table['error'] !== '' ? $table['error'] : 'V souboru nejsou žádné řádky s daty.', 'error');
            redirect('/ucetnictvi#import');
        }
        $_SESSION['acct_pimp'] = ['target' => isset(ACCT_TARGETS[post('target')]) ? post('target') : 'accounts', 'name' => mb_substr(basename((string) $f['name']), 0, 120),
            'preset' => isset(ACCT_PRESETS[post('preset')]) ? post('preset') : '', 'table' => $table];
        redirect('/ucetnictvi/import');
    }
    if ($PATH === '/ucetnictvi/import') {
        $imp = $_SESSION['acct_pimp'] ?? null;
        if (!$imp) {
            flash('Nejdřív nahrajte soubor.', 'info');
            redirect('/ucetnictvi#import');
        }
        if ($METHOD === 'POST' && post('action') === 'cancel') {
            unset($_SESSION['acct_pimp']);
            redirect('/ucetnictvi#import');
        }
        $fields = acct_fields($imp['target'], true);
        $table = $imp['table'];
        $restored = false;
        if ($METHOD === 'POST') {
            $map = acct_mapping_from_input((array) ($_POST['map'] ?? []), $table['headers'], $fields);
        } else {
            $saved = acct_mapping_restore(pset($mapKey($imp['target'])), $table['headers'], $fields);
            $restored = $saved !== null && $imp['preset'] === '';
            $map = $restored ? $saved : acct_guess_mapping($table['headers'], array_keys($fields), $imp['preset']);
        }
        $asOf = valid_date(post('as_of')) ? post('as_of') : date('Y-m-d');
        $withIco = acct_partner_houses($houses);
        $split = acct_split_houses($table, $map['house'] ?? null, $withIco);
        $groups = [];
        $totalOk = 0;
        foreach ($houses as $h) {
            if (!isset($split['groups'][$h['slug']])) {
                continue;
            }
            try {
                $pv = acct_preview(tenant_pdo($h['slug']), acct_house_tenant($h), $imp['target'], $split['groups'][$h['slug']], $map);
            } catch (Throwable $e) {
                error_log('SVJ účetnictví, náhled domu ' . $h['slug'] . ': ' . $e->getMessage());
                $pv = ['rows' => [], 'ok' => 0, 'bad' => 0, 'create' => 0, 'missing' => ['Dům se nepodařilo otevřít.']];
            }
            $groups[$h['slug']] = ['house' => $h, 'preview' => $pv];
            $totalOk += $pv['missing'] ? 0 : $pv['ok'];
        }
        if ($METHOD === 'POST' && post('action') === 'save') {
            if (($map['house'] ?? null) !== null && $totalOk > 0) {
                $done = [];
                foreach ($groups as $slug => $g) {
                    if ($g['preview']['missing'] || !$g['preview']['ok']) {
                        continue;
                    }
                    try {
                        $pdo = tenant_pdo($slug);
                        $res = acct_apply($pdo, $imp['target'], $g['preview'], $asOf);
                        acct_tenant_audit($pdo, 'Partner ' . $partner['name'] . ' (' . $pu['email'] . ')', 'Účetnictví: import – ' . ACCT_TARGETS[$imp['target']][0],
                            'soubor ' . $imp['name'] . ', upraveno ' . $res['updated'] . ', založeno ' . $res['created']);
                        $done[] = $g['house']['name'] . ': ' . ($res['updated'] + $res['created']);
                    } catch (Throwable $e) {
                        error_log('SVJ účetnictví, import domu ' . $slug . ': ' . $e->getMessage());
                        $done[] = $g['house']['name'] . ': nepodařilo se';
                    }
                }
                pset_save($mapKey($imp['target']), acct_mapping_export($map, $table['headers']));
                unset($_SESSION['acct_pimp']);
                $plog('import z účetnictví', $imp['name'] . ' · ' . implode('; ', $done));
                flash('Import hotov – ' . implode('; ', $done) . '.' . ($split['unknown'] ? ' Nepoznané domy se vynechaly.' : ''));
                redirect('/ucetnictvi');
            }
            flash('Není co uložit – přiřaďte sloupec „Dům“ a opravte soubor.', 'error');
        }
        render('ext/ucetnictvi/import', ['title' => 'Import za více domů', 'imp' => $imp, 'fields' => $fields, 'map' => $map, 'asOf' => $asOf, 'restored' => $restored,
            'action' => url('/ucetnictvi/import'), 'multi' => true, 'groups' => $groups, 'unknown' => $split['unknown'], 'preview' => ['missing' => ($map['house'] ?? null) === null ? ['Přiřaďte sloupec „Dům“.'] : [], 'rows' => [], 'ok' => $totalOk, 'bad' => 0, 'create' => 0]]);
        exit;
    }
    if ($PATH === '/ucetnictvi') {
        render('ext/ucetnictvi/partner', ['title' => 'Účetnictví', 'houses' => $houses, 'params' => acct_export_params($_GET)]);
        exit;
    }
}
