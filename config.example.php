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
    //
    // Varianta bez subdomén: weby jsou složky hlavní domény (svj.cz/tabor) a stačí běžný certifikát HTTPS
    // pro svj.cz, bez zástupného DNS záznamu i zástupného certifikátu:
    // 'platform' => ['domain' => 'svj.cz', 'mode' => 'path'],
    //
    // Zkoušení z telefonu v domácí síti: přidejte IP adresu počítače mezi další adresy. Na počítači pak weby běží
    // na tabor.localhost, z telefonu se tentýž web otevře jako složka: http://192.168.1.20:8080/tabor/
    // 'platform' => ['domain' => 'localhost', 'aliases' => ['192.168.1.20']],

    // AI pomocník pro výbor (volitelný): návrhy oznámení, uhlazení textu, odpovědi na hlášení.
    // Vyžaduje knihovnu (ve složce webu spusťte: composer install) a klíč ke službě Claude z console.anthropic.com.
    // Text, který správce pomocníkovi zadá, se odesílá společnosti Anthropic; použití se platí podle spotřeby.
    // 'ai' => ['api_key' => 'sk-ant-…', 'model' => 'claude-opus-5-5', 'monthly_limit' => 30],

    'db' => [
        'driver' => 'mysql',
        'host' => 'localhost',   // adresu serveru uvádí hosting; někdy to není localhost
        'port' => 3306,
        'name' => '',            // název databáze
        'user' => '',            // uživatel databáze
        'password' => '',        // heslo k databázi
    ],
];
