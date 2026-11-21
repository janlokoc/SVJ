# Web SVJ Tábor 48 a–d

Nový web Společenství vlastníků jednotek Tábor 48 a–d (Brno), náhrada za
`svjtabor48.webnode.cz`. Veřejná část, neveřejná část pro obyvatele domu
a administrace pro výbor.

## Co web umí

**Veřejně**
- Úvod s nejbližším termínem, aktualitami a havarijními čísly
- Aktuality (oznámení výboru s přílohami)
- Dokumenty podle kategorií s vyhledáváním
- Kontakty na výbor, účetní a havarijní servis

**Po přihlášení (obyvatel domu – vlastník i nájemník)**
- Neveřejné aktuality a dokumenty
- Diskuze se sousedy
- Hlášení závad a požadavků výboru a sledování stavu řešení
- Vlastní účet (kontakt, změna hesla); zapomenuté heslo si obnoví sám
  odkazem z e-mailu
- Vlastník může pozvat manžela, manželku nebo dalšího spoluvlastníka –
  pozvaný si přes odkaz z e-mailu nastaví heslo a účet se přiřadí k téže
  jednotce

**Administrace (správce webu)**
- Aktuality: psaní, plánování na datum, připnutí, viditelnost, přílohy
- Dokumenty: nahrávání, kategorie, viditelnost
- Termíny, kontakty, texty webu
- Zprávy e-mailem: rozeslání důležitého oznámení vlastníkům nebo všem
  obyvatelům s účtem, zkušební zpráva, historie; u každé zprávy přehled,
  kdo ji otevřel a kdo klikl na odkaz (lze vypnout v Nastavení)
- Účast na shromáždění: vlastníci na webu potvrdí účast za svou jednotku,
  správce vidí přehled po jednotkách a odhad usnášeníschopnosti podle
  spoluvlastnických podílů; odpověď může zapsat i za vlastníka bez účtu
- Nastavení: odesílatel e-mailů, pravidla pozvánek členů domácnosti,
  hranice usnášeníschopnosti
- Uživatelé: zakládání účtů, dočasná hesla, vypnutí účtu, hromadný import
  ze seznamu (jméno, příjmení, telefon, e-mail, vlastník/nájemník,
  volitelně jednotka) s rozesláním pozvánek k nastavení hesla
- Jednotky: seznam jednotek s vlastníkem dle evidence, patrem a podílem,
  přiřazení účtů k jednotkám, hromadné založení jednotek, import
  vlastníků a podílů vložením textu z Nahlížení do katastru nemovitostí
- Závady a požadavky: stav, odpověď obyvateli a úprava textu; úprava
  a mazání příspěvků v diskuzi
- Záznamy: každá změna v administraci se zapíše (kdo, kdy, co a co se
  změnilo); záznamy nejdou z webu upravit ani smazat a web hlídá, že do
  nich nikdo nezasáhl přímo v databázi

**Další funkce**
- Hlasování mimo zasedání (per rollam) i zápis hlasování ze shromáždění,
  počítané podle spoluvlastnických podílů
- Dokumenty jen pro jednu jednotku (vyúčtování, předpis záloh) včetně
  hromadného nahrání podle názvu souboru
- Shromáždění: program, podklady, plná moc a prezenční listina k tisku
- Odečty měřidel, rezervace společných prostor, evidence klíčů a čipů
- Revize a údržba s připomenutím správcům e-mailem
- Adresy vlastníků a export seznamu členů do tabulky
- Stránka Ochrana osobních údajů s upravitelným textem
- Záloha všech dat jedním tlačítkem; obnova příkazem
  `php tools/restore-backup.php zaloha.zip` (i mezi SQLite a MySQL)

## Požadavky

PHP 8.1+ s rozšířením `mbstring` a jednou z databází:

- **MySQL / MariaDB** (`pdo_mysql`) – má ji každý hosting; doporučeno pro
  ostrý provoz. Údaje k databázi se zapisují do `storage/config.php`.
- **SQLite** (`pdo_sqlite`) – nic se nenastavuje, data jsou v souboru ve
  složce `storage/`. Hodí se pro vyzkoušení na vlastním počítači.

Nahrané soubory jsou vždy ve `storage/uploads/`. Žádné další knihovny
nejsou potřeba.

## Spuštění na vlastním počítači

```
php -S localhost:8000 index.php
```

a otevřít <http://localhost:8000>.

## Nasazení na hosting

1. Nahrajte obsah repozitáře do kořenové složky webu (FTP).
   U poskytovatele založte databázi MySQL, soubor `config.example.php`
   zkopírujte jako `storage/config.php` a doplňte do něj její údaje.
   Tabulky a výchozí obsah se při prvním otevření webu vytvoří samy.
2. Složka `storage/` musí být zapisovatelná pro PHP.
3. Hosting musí mít Apache s `mod_rewrite` (soubor `.htaccess` je součástí).
   Na Nginx nasměrujte všechny požadavky mimo `/assets/` na `index.php`
   a zakažte přístup do `app/`, `storage/` a `tools/`.
4. Zapněte HTTPS.
5. Otevřete web. **Při prvním spuštění se zobrazí formulář pro vytvoření
   účtu správce.** Vyžaduje instalační kód ze souboru
   `storage/instalacni-kod-….txt`, který si přečtete přes FTP – cizí člověk
   se k němu nedostane. Po vytvoření správce se soubor sám smaže.
6. V Administraci → Přehled stáhněte tlačítkem soubory ze starého webu
   (nebo `php tools/import-webnode.php`). Do té doby dokumenty odkazují na
   Webnode a po jeho zrušení by přestaly fungovat.

### Rozesílání e-mailů

E-maily se odesílají funkcí PHP `mail()`, kterou musí hosting povolovat.
V Administraci → Nastavení vyplňte adresu odesílatele na doméně webu
(např. `vybor@vase-domena.cz`) a u domény nastavte záznamy SPF a DKIM podle
návodu poskytovatele – jinak zprávy často končí ve spamu. Před prvním
rozesláním pošlete zkušební zprávu sami sobě.

Limit velikosti nahrávaného souboru určuje hosting (`upload_max_filesize`
a `post_max_size` v nastavení PHP).

## Provoz pro více SVJ (platforma)

Jedna instalace umí obsluhovat stovky společenství. Každé má vlastní
adresu (`tabor.svj.cz`, `zahradecka.svj.cz`), vlastní správce a úplně
oddělená data: vlastní databázi, soubory i přihlášení ve složce
`storage/tenants/<adresa>/`.

1. V `storage/config.php` nastavte hlavní doménu:
   `'platform' => ['domain' => 'svj.cz']` (viz `config.example.php`).
2. U domény nastavte zástupný DNS záznam `*.svj.cz` na server a pořiďte
   certifikát HTTPS pro `svj.cz` i `*.svj.cz`. Server musí všechny tyto
   adresy směrovat do téže složky s webem. Běžný sdílený hosting to
   zpravidla neumí – počítejte s vlastním serverem (VPS) nebo hostingem,
   který zástupné subdomény podporuje.
3. Na adrese hlavní domény se zobrazí konzole **hlavního správce**:
   první účet se vytvoří instalačním kódem ze souboru
   `storage/instalacni-kod-platformy-….txt`.
4. Hlavní správce zakládá weby společenství (adresa, název, vzhled,
   jazyk, značení jednotek), pozastavuje je a maže, vytváří jim první
   správce a vidí základní přehled. Do obsahu webů nevidí.

Každé společenství si v Nastavení samo volí jeden z pěti vzhledů, logo,
název, jazyk a to, které části menu se zobrazují. Web může běžet i na
vlastní doméně společenství.

Databáze: doporučena SQLite (každé společenství má vlastní soubor). Pro
MySQL musí název databáze v `storage/config.php` obsahovat `{tenant}`,
např. `svj_{tenant}`, a účet musí mít právo databáze zakládat.

Nástroje z příkazové řádky pracují se společenstvím zvoleným proměnnou
prostředí: `SVJ_TENANT=tabor php tools/reset-password.php e-mail`.

Bez nastavení `platform` web funguje jako dosud pro jedno společenství.

## Zabezpečení

- Hesla jsou uložena jen jako otisk (bcrypt); slabá a běžná hesla web odmítne.
- Dvoufázové ověření kódem z aplikace v telefonu (Můj účet) – správcům
  důrazně doporučeno, administrace na chybějící ověření upozorňuje.
- Ochrana proti hádání hesel: nejvýše 8 chyb z jedné adresy a 10 chyb na
  jeden účet za 15 minut.
- Odhlášení po 2 hodinách nečinnosti, nejdéle po 12 hodinách. Změna nebo
  reset hesla odhlásí všechna ostatní zařízení.
- Všechny formuláře jsou chráněné proti podvrženým požadavkům (CSRF),
  výstup proti vložení skriptů (XSS + přísná CSP), dotazy do databáze proti
  SQL injection.
- Nahrávat lze jen vyjmenované typy souborů a obsah musí odpovídat příponě;
  soubory i databáze mají náhodné názvy a leží ve složce, kam web zvenku
  nepouští.
- Administrace → Záznamy ukazuje přihlášení, neúspěšné pokusy a citlivé
  změny za poslední rok.
- **Podmínky na straně hostingu:** zapnuté HTTPS a fungující `.htaccess`
  (Apache). Přehled administrace sám zkontroluje, zda nejsou vnitřní složky
  čitelné zvenku, a případně zobrazí varování.
- Zapomenuté heslo jediného správce: `php tools/reset-password.php e-mail`.

## Zálohování

Zálohujte složku `storage/` (nahrané soubory, nastavení a u SQLite i
databáze). Při použití MySQL zálohujte navíc databázi – export nabízí
administrace hostingu (phpMyAdmin).

## Struktura

```
index.php            vstupní bod a směrování
app/lib.php          databáze, přihlášení, pomocné funkce
app/routes_*.php     veřejná část, část pro obyvatele, administrace
app/views/           šablony stránek
app/schema.sql       struktura databáze (SQLite)
app/schema.mysql.sql struktura databáze (MySQL / MariaDB)
config.example.php   vzor nastavení databáze MySQL
app/seed.json|php    výchozí obsah převzatý ze starého webu
assets/              vzhled (CSS, JS, písma Fraunces a Figtree – licence OFL)
storage/             data (není v gitu)
tools/               převod souborů ze starého webu
```
