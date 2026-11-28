<?php
declare(strict_types=1);

// Konto bytu z účetnictví: import XML exportu POHODY (app/ext/ledger_lib.php). Náhled → uložení.

$route('POST', '/admin/platby/pohoda', function () {
    $me = require_admin();
    ledger_ready();
    if (post('do') === 'save') {
        $p = $_SESSION['ledger_preview'] ?? null;
        unset($_SESSION['ledger_preview']);
        if (!$p) {
            flash('Náhled vypršel, nahrajte soubor znovu.', 'error');
            redirect('/admin/platby#pohoda');
        }
        $parsed = ledger_pohoda_parse((string) base64_decode($p['data'], true));
        if (is_string($parsed)) {
            flash($parsed, 'error');
            redirect('/admin/platby#pohoda');
        }
        $r = ledger_import($parsed, 'pohoda', $p['file'], (int) $me['id']);
        flash('Uloženo dokladů: ' . $r['saved'] . ', přiřazeno k ' . $r['units'] . ' jednotkám' . ($r['unmatched'] ? '; ' . $r['unmatched'] . ' dokladů bez přiřazené jednotky (neznámý variabilní symbol)' : '') . '. Vlastníci je vidí v Platby a zálohy.', $r['unmatched'] ? 'info' : 'ok');
        redirect('/admin/platby#pohoda');
    }
    $f = $_FILES['file'] ?? null;
    $data = $f && ($f['error'] ?? 1) === UPLOAD_ERR_OK && $f['size'] <= LEDGER_MAX_BYTES ? (string) file_get_contents($f['tmp_name']) : '';
    $parsed = $data !== '' ? ledger_pohoda_parse($data) : 'Vyberte XML soubor exportu z POHODY (nejvýše 20 MB).';
    if (is_string($parsed)) {
        flash($parsed, 'error');
        redirect('/admin/platby#pohoda');
    }
    $_SESSION['ledger_preview'] = ['data' => base64_encode($data), 'file' => basename((string) $f['name']), 'summary' => ledger_preview($parsed)];
    redirect('/admin/platby#pohoda');
});
