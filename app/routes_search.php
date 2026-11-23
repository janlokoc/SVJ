<?php
declare(strict_types=1);

// Hledání na webu (/hledat?q=…).
$route('GET', '/hledat', function () {
    $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
    $groups = $q !== '' ? site_search($q) : [];
    render('search', ['title' => $q !== '' ? 'Hledání: ' . $q : 'Hledání', 'q' => $q, 'groups' => $groups, 'terms' => search_terms($q)]);
});
