<?php
declare(strict_types=1);

// Veřejná dokumentace API pro dodavatele (odečtové firmy, účetní programy, správce) a popis OpenAPI.

if ($METHOD === 'GET' && $PATH === '/api') {
    $GLOBALS['PAGE_META'] = ['description' => 'API Společného WEBu pro dodavatele: odečty měřidel, předpisy a platby z účetnictví, závady a aktuality. Klíč vydá výbor domu.'];
    render('ext/api/docs', ['title' => 'API pro dodavatele']);
    exit;
}
if ($METHOD === 'GET' && $PATH === '/api/openapi.json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo json_encode(api_openapi(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
