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
    // Weby společenství běží vždy na subdoménách (tabor.svj.cz); potřebují zástupný DNS záznam a certifikát pro *.svj.cz.
    //
    // Zkoušení z telefonu v domácí síti: přidejte IP adresu počítače mezi další adresy. Na počítači pak weby běží
    // na tabor.localhost, z telefonu se tentýž web otevře jako složka: http://192.168.1.20:8080/tabor/
    // 'platform' => ['domain' => 'localhost', 'aliases' => ['192.168.1.20']],
    //
    // Zkoušení přes internet (iPad, telefon, další lidé) přes Cloudflare Tunnel – viz README a tools/tunel.sh.
    // 'tunnel' => true přijme dočasnou adresu https://…trycloudflare.com; weby SVJ se na ní otevírají jako složky.
    // 'platform' => ['domain' => 'localhost', 'tunnel' => true],

    // AI pomocník pro výbor (volitelný): návrhy oznámení, uhlazení textu, odpovědi na hlášení.
    // Vyžaduje knihovnu (ve složce webu spusťte: composer install) a klíč ke službě Claude z console.anthropic.com.
    // Text, který správce pomocníkovi zadá, se odesílá společnosti Anthropic; použití se platí podle spotřeby.
    // Zkušební prostředí: pruh na každé stránce, zákaz indexování a e-maily jen na jednu adresu (bez ní se neodesílají).
    // 'env' => 'staging', 'staging_mail' => 'vy@example.cz',

    // Odesílání e-mailů přes SMTP server (doporučeno na vlastním počítači a tam, kde hosting neumí funkci mail()).
    // Seznam: host smtp.seznam.cz, port 465, secure ssl; Gmail: smtp.gmail.com, 465, ssl a „heslo aplikace“;
    // Brevo: smtp-relay.brevo.com, 587, tls. Odesílatel (Nastavení webu → e-mail odesílatele) musí patřit k tomuto účtu.
    // 'smtp' => ['host' => 'smtp.seznam.cz', 'port' => 465, 'secure' => 'ssl', 'user' => 'vy@seznam.cz', 'password' => '…'],

    // Plánovač hostingu (webcron) volá https://vase-domena.cz/cron/<klíč> – odešle frontu e-mailů a spustí denní úlohy
    // i u webů, kam ten den nikdo nepřišel. Klíč: náhodný řetězec (např. `openssl rand -hex 20`).
    // 'cron_key' => '…',

    // Pravidelná záloha (GitHub Actions stahuje https://vase-domena.cz/zaloha/<klíč>), klíč aspoň 32 znaků: `openssl rand -hex 32`.
    // 'backup_key' => '…',

    // Limit odesílání e-mailů za hodinu pro celou službu (Český hosting: nejvýš 1 000 na doménu, doporučeno ~200).
    // Hromadné zprávy (rozeslání, upozornění na spotřebu, upomínky) nad limit čekají ve frontě a odcházejí postupně.
    // 'mail' => ['per_hour' => 800, 'bulk_per_hour' => 200],

    // Klíč z Claude Console → API keys. Osobní klíč (sk-ant-usr…), který není vázaný na jeden workspace,
    // potřebuje i ID workspace (Console → Workspaces, sloupec ID); klíč vytvořený pro jeden workspace ho nepotřebuje.
    // 'ai' => ['api_key' => 'sk-ant-…', 'workspace_id' => 'wrkspc_…', 'model' => 'claude-opus-5-5', 'monthly_limit' => 30],

    'db' => [
        'driver' => 'mysql',
        'host' => 'localhost',   // adresu serveru uvádí hosting; někdy to není localhost
        'port' => 3306,
        'name' => '',            // název databáze
        'user' => '',            // uživatel databáze
        'password' => '',        // heslo k databázi
    ],
];
