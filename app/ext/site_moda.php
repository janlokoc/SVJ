<?php
declare(strict_types=1);

// Modul A: veřejná stránka hlavního webu služby „Bezpečnost a ochrana dat“.

if ($METHOD === 'GET' && $PATH === '/bezpecnost') {
    render('ext/moda/security', ['title' => 'Bezpečnost a ochrana dat', 's' => moda_security_facts()]);
    exit;
}
