<?php
// Nastavení databáze MySQL / MariaDB.
//
// 1. U poskytovatele hostingu si založte databázi (název, uživatel, heslo).
// 2. Tento soubor zkopírujte do složky storage pod názvem config.php
//    (tedy storage/config.php) a doplňte údaje.
// 3. Otevřete web – tabulky a výchozí obsah se vytvoří samy.
//
// Bez souboru storage/config.php web použije databázi SQLite uloženou
// ve složce storage (vhodné pro vyzkoušení na vlastním počítači).
return [
    // Provoz pro více SVJ: odkomentujte a uveďte hlavní doménu. Každé společenství pak má adresu nazev.domena
    // a hlavní správce je zakládá na adrese samotné domény. Bez tohoto řádku web běží pro jedno společenství.
    // 'platform' => ['domain' => 'svj.cz'],

    'db' => [
        'driver' => 'mysql',
        'host' => 'localhost',   // adresu serveru uvádí hosting; někdy to není localhost
        'port' => 3306,
        'name' => '',            // název databáze
        'user' => '',            // uživatel databáze
        'password' => '',        // heslo k databázi
    ],
];
