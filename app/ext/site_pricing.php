<?php
declare(strict_types=1);

// Modul G na hlavním webu služby: srovnání s konkurencí, kampaň k měsíční informaci o spotřebě, partnerský program,
// ověření aplikace pro Android a popis aplikace pro Bubblewrap.

if ($METHOD === 'GET' && $PATH === '/.well-known/assetlinks.json') {
    twa_send();
}
if ($METHOD === 'GET' && $PATH === '/manifest.webmanifest') {
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo json_encode(platform_manifest(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($METHOD === 'GET' && $PATH === '/srovnani' && compare_enabled()) {
    render('ext/pricing/compare', ['title' => 'Srovnání s jinými službami pro SVJ']);
    exit;
}
if ($METHOD === 'GET' && $PATH === '/mesicni-informace-o-spotrebe') {
    render('ext/pricing/consumption', ['title' => 'Měsíční informace o spotřebě tepla a teplé vody']);
    exit;
}
if ($METHOD === 'GET' && $PATH === '/pro-spravce/program') {
    render('ext/pricing/program', ['title' => 'Partnerský program pro správcovské firmy']);
    exit;
}
