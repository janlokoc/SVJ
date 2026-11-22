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
   **Bez subdomén:** s nastavením
   `'platform' => ['domain' => 'svj.cz', 'mode' => 'path']` jsou weby
   složkami hlavní domény (`svj.cz/tabor`, `svj.cz/zahradecka`). Stačí
   běžný certifikát pro `svj.cz` a žádný zástupný DNS záznam, takže to
   zvládne i běžný hosting. Viz „Subdomény, nebo složky" níže.
3. Na adrese hlavní domény je veřejná úvodní stránka služby s ceníkem
   a registrací; konzole **hlavního správce** je na adrese `/sprava`.
   První účet se vytvoří instalačním kódem ze souboru
   `storage/instalacni-kod-platformy-….txt`.
4. Hlavní správce zakládá weby společenství (adresa, název, vzhled,
   jazyk, značení jednotek), pozastavuje je a maže, vytváří jim první
   správce a vidí základní přehled. Do obsahu webů nevidí.

Každé společenství si v Nastavení samo volí jednu z pěti šablon
(Klasická, Moderní, Věcná, Novinová, Vlídná), k ní jednu z pěti
barevných variant, logo,
název, jazyk a to, které části menu se zobrazují. Web může běžet i na
vlastní doméně společenství.

Databáze: doporučena SQLite (každé společenství má vlastní soubor). Pro
MySQL musí název databáze v `storage/config.php` obsahovat `{tenant}`,
např. `svj_{tenant}`, a účet musí mít právo databáze zakládat.

Nástroje z příkazové řádky pracují se společenstvím zvoleným proměnnou
prostředí: `SVJ_TENANT=tabor php tools/reset-password.php e-mail`.

### Subdomény, nebo složky

| | Subdomény (`tabor.svj.cz`) | Složky (`svj.cz/tabor`) |
|---|---|---|
| DNS a certifikát | zástupný záznam `*.svj.cz` a zástupný certifikát | jen `svj.cz`, běžný certifikát |
| Hosting | server nebo hosting se zástupnými subdoménami | jakýkoli hosting s PHP |
| Oddělení webů v prohlížeči | každý web je samostatný původ | všechny weby sdílejí jeden původ |

Data, soubory i přihlášení jsou v obou variantách oddělené na serveru
stejně. Ve variantě se složkami má každý web vlastní přihlašovací
cookie platnou jen pro svou složku. Prohlížeč ale weby na jedné doméně
nepovažuje za navzájem cizí, takže případná chyba typu vložení skriptu
do jednoho webu by mohla zasáhnout i ostatní – u subdomén by zůstala
uvnitř jednoho webu. Web proto nedovoluje vkládat vlastní HTML ani
skripty a posílá přísná bezpečnostní pravidla stránky.

Variantu zvolte před spuštěním. Pozdější přepnutí změní adresy všech
webů: staré odkazy v e-mailech a záložkách přestanou fungovat a
všichni se musí znovu přihlásit. Adresy `sprava`, `registrace`,
`podminky` a několik dalších patří platformě a nejdou použít jako
adresa společenství. Vlastní doména společenství funguje v obou
variantách.

### Vlastní doména společenství

Společenství může web provozovat na vlastní adrese (např. `tabor48.cz`)
jako placený doplněk (výchozí 999 Kč ročně, mění se v Nastavení služby).

1. Správce společenství o doménu požádá v **Předplatném**: zadá název a
   zvolí, zda ji už vlastní, nebo ji má provozovatel zaregistrovat.
   Hned vidí platební údaje s QR kódem a zálohovou fakturu.
2. Hlavní správce vidí žádost v konzoli u společenství. Po přijetí
   platby ji potvrdí v části Platby (vystaví se faktura).
3. Doménu zaregistruje u registrátora, nebo ji společenství nasměruje
   samo podle pokynů z Nastavení služby. Na serveru pro ni zapne HTTPS.
4. Teprve potom ji v konzoli **zprovozní**. Web od té chvíle běží na
   vlastní doméně i na původní adrese platformy; účty, obsah ani správa
   se nemění a hlavní správce web dál spravuje z konzole.
5. Měsíc před koncem zaplaceného roku vznikne výzva na další rok a
   kontaktní osobě přijde e-mail. Doménu po nezaplacení odpojuje hlavní
   správce ručně.

Registraci domény a certifikát aplikace sama nezařídí. Certifikáty jde
zautomatizovat serverem Caddy („on-demand TLS“): adresa
`https://<doména platformy>/domena-povolena?domain=…` odpoví 200 jen pro
zprovozněné domény společenství, takže Caddy vystaví certifikát jen jim.

### Zkoušení z telefonu v domácí síti

Web spuštěný na vlastním počítači otevřete z telefonu na stejné Wi-Fi:

1. Server spusťte tak, aby poslouchal na všech adresách:
   `php -S 0.0.0.0:8080 index.php`.
2. Zjistěte IP adresu počítače v síti (na Macu `ipconfig getifaddr en0`).
3. Na platformě pro více SVJ přidejte IP adresu mezi další adresy
   v `storage/config.php`:
   `'platform' => ['domain' => 'localhost', 'aliases' => ['192.168.1.20']]`.
   Na počítači zůstávají subdomény (`tabor.localhost`); přes IP adresu,
   kde subdomény neexistují, se tentýž web otevírá jako složka. Texty na
   webu vždy ukazují adresy v podobě hlavní domény.
4. V telefonu otevřete `http://192.168.1.20:8080/` (web jednoho SVJ), nebo
   `http://192.168.1.20:8080/tabor/` (platforma).

Vestavěný server PHP je jen na zkoušení; do ostrého provozu nepatří.

### Kolik společenství jedna instalace unese

Měřeno na zkušebních 300 společenstvích (SQLite, varianta se složkami,
jeden proces PHP): stránka webu 2–4 ms, přehled všech společenství
v konzoli 0,4 s. Samotná aplikace tedy stovky webů zvládá; dřív narazíte
na limity hostingu:

- **odesílání e-mailů** – sdílené hostingy povolují řádově stovky zpráv
  za hodinu; pozvánka pro jeden větší dům limit vyčerpá,
- **místo na disku** – dokumenty jednoho domu mají desítky až stovky MB,
- **počet databází** – s MySQL potřebuje každé společenství vlastní
  databázi, což běžný hosting nedovolí; pro více SVJ proto použijte SQLite.

#### Vlastní certifikát společenství

Společenství, které už certifikát pro svou doménu má, ho může nahrát
v Předplatném v části Vlastní doména (certifikát s celým řetězcem a
soukromý klíč bez hesla, formát PEM). Totéž může udělat hlavní správce.
Web před uložením ověří, že klíč k certifikátu patří, že je certifikát
vydaný pro danou doménu a že platí; tři týdny před koncem platnosti pošle
kontaktní osobě upozornění.

Soubory se ukládají do `storage/certs/<doména>/fullchain.pem` a
`privkey.pem`. **Aplikace je sama nepoužívá** – HTTPS obsluhuje webový
server, který si je odtud musí načítat. Pro nginx (od verze 1.15.9, která
umí v cestě k certifikátu proměnnou) vypadá nastavení zhruba takto:

```
server {
    listen 443 ssl;
    server_name ~^(www\.)?(?<vlastni>.+)$;
    ssl_certificate     /cesta/k/webu/storage/certs/$vlastni/fullchain.pem;
    ssl_certificate_key /cesta/k/webu/storage/certs/$vlastni/privkey.pem;
    …
}
```

Proces nginx musí mít ke složce `storage/certs` právo čtení. Toto
nastavení nebylo vyzkoušeno na skutečném serveru – berte ho jako výchozí
bod. Pro domény bez nahraného certifikátu zůstává jednodušší cesta
automatické vystavování (viz výše).

### Registrace, zkušební doba a předplatné

- Z úvodní stránky si kdokoli sám založí web: název a adresa → vzhled →
  účet správce. Web hned běží ve zkušební době (výchozí 30 dní).
- V konzoli v části **Nastavení služby** se nastavují ceny (výchozí
  1 490 Kč v prvním roce, potom 2 390 Kč), zda se platí měsíčně nebo
  ročně, délka zkušební doby, lhůta po splatnosti, číslo účtu, ceník
  podpory a úprav, kontakt a odesílatel e-mailů. Registraci lze vypnout.
  Výchozí je roční platba.
- Správce společenství vidí v administraci stránku **Předplatné**
  s QR platbou (částka, účet, variabilní symbol). Platba kartou není
  zapojena – vyžaduje smlouvu s platební bránou.
- Platby se nepárují s bankou automaticky: hlavní správce po připsání
  na účet platbu v konzoli v části **Platby** potvrdí a předplatné se
  prodlouží.
- Před koncem období chodí připomenutí e-mailem. Po splatnosti web běží
  ještě nastavenou lhůtu, potom se pozastaví: obyvatelé vidí oznámení,
  správce se dostane jen na Předplatné a Zálohu. Data zůstávají.
- Weby založené hlavním správcem v konzoli jsou bez placení, dokud jim
  placení nezapne.
- **Faktury:** správce společenství si v Předplatném vytiskne zálohovou
  fakturu (výzvu k platbě s QR kódem). Potvrzením platby v konzoli se
  vystaví faktura s pořadovým číslem; údaje dodavatele a odběratele se
  na ni uloží tak, jak platily v den vystavení. Doklady počítají
  s dodavatelem, který není plátcem DPH.
- **Provozovatel a podmínky:** v Nastavení služby se vyplňuje název,
  sídlo, IČO a kontakt provozovatele a texty obchodních podmínek a
  smlouvy o zpracování osobních údajů (stránky `/podminky` a
  `/zpracovani-udaju`). Výchozí texty jsou návrh – před otevřením
  registrace je nechte projít právníkem.
- **Ukázkový web:** tlačítkem v konzoli se založí web `demo` se
  smyšleným společenstvím. Návštěvník si ho z úvodní stránky prohlédne
  jako obyvatel i jako správce; je jen pro čtení, žádná změna se
  neuloží. Tlačítkem Obnovit ukázkový web se naplní čerstvými daty
  (termíny v ukázce jsou počítané ode dne založení).
- **Mazání nezaplacených zkušebních webů:** web, který po zkušební době
  nikdy nezaplatil, se po nastavené lhůtě (výchozí 60 dní od konce
  zkušební doby, 0 = nemazat) smaže včetně dat. Kontaktní osobě chodí
  upozornění 14 dní a 3 dny předem a potvrzení po smazání. Bez
  prokazatelně odeslaného upozornění starého aspoň 3 dny se nic nemaže;
  web s přijatou nebo nahlášenou platbou se nemaže nikdy. Všechna
  odeslaná upozornění (kdy, komu, zda odešla) jsou v konzoli u
  společenství, v seznamu společenství a v Záznamech.
- Ochrana proti zneužití registrace: nejvýše tři weby z jedné adresy za
  den a skryté pole proti robotům. E-mail se při registraci neověřuje.

### Převod stávajícího webu jednoho SVJ pod platformu

Když už web běží pro jedno společenství (SQLite) a chcete zapnout
platformu, přesuňte jeho data pod vlastní adresu:

1. Udělejte zálohu složky `storage`.
2. Do `storage/config.php` doplňte `'platform' => ['domain' => '…']`
   (pro zkoušku na vlastním počítači `localhost`).
3. Spusťte `php tools/move-to-platform.php tabor`.

Web pak běží na `tabor.<doména>` se stejnými účty a hesly; hlavní doména
zobrazuje úvodní stránku služby a `/sprava` konzoli hlavního správce.
Na vlastním počítači fungují adresy `tabor.localhost:8080` v běžných
prohlížečích bez dalšího nastavení; ve variantě se složkami je to
`localhost:8080/tabor`.

### Import obsahu ze starého webu

Aktuality, dokumenty, kontakty a název lze nahrát ze souboru JSON
(formát `svj-import-1`) připraveného převodem původního webu:

- v konzoli hlavního správce na stránce společenství, část **Import
  obsahu ze starého webu**, nebo
- příkazem `SVJ_TENANT=adresa php tools/import-content.php soubor.json`
  (na webu jednoho SVJ bez `SVJ_TENANT`; `--pridat` obsah nepřepíše,
  jen přidá).

Dokumenty se nejdřív založí jako odkazy na původní web. Skutečné soubory
stáhne správce společenství tlačítkem v administraci na stránce Přehled,
nebo příkaz `php tools/import-webnode.php`. Soubory, které už původní
web nevydá, zůstanou jako odkaz.

Bez nastavení `platform` web funguje jako dosud pro jedno společenství.

## Hlášení problémů a nápadů

Každá stránka má v hlavičce tlačítko **Nahlásit**. Uživatel vybere, zda
jde o problém nebo nápad, napíše text a může přiložit snímek stránky –
buď ho pořídí prohlížeč (zeptá se na svolení; funguje v počítačových
prohlížečích), nebo se přiloží obrázek. S hlášením se uloží adresa
stránky, prohlížeč a velikost okna. Nepřihlášený návštěvník uvádí e-mail.

- Na platformě hlášení ze všech webů vyřizuje hlavní správce v konzoli
  v části **Hlášení**; na webu jednoho SVJ jeho správci v administraci.
- Správce nastaví stav (nové, řeší se, plánujeme, vyřešeno, nebudeme
  řešit), napíše odpověď a interní poznámku. Stav a odpověď vidí autor
  v části **Moje hlášení** a přijdou mu e-mailem.
- Mezi hlášení se samy zapisují i chyby, které web zachytil (stejná
  chyba se nezapisuje znovu, jen se zvyšuje počet).
- Snímky jsou ve složce `storage/reports` a vidí je jen autor a ten,
  kdo hlášení vyřizuje. Hlášení nejsou součástí zálohy webu.

## Ankety, zápis, úkoly, vývěska a platby na jednotku

- **Ankety** (Pro obyvatele → Ankety): nezávazné zjištění názoru, každý
  přihlášený odpoví jednou; výsledky vidí po odpovědi. Anketa může být
  anonymní, nebo se jmény pro výbor.
- **Zápis ze shromáždění** (Termíny → Zápis): sestaví se z potvrzené
  účasti, programu a hlasování přiřazených k termínu, včetně otisku
  odevzdaných hlasů. Průběh jednání se doplní přímo na stránce a zápis se
  vytiskne nebo uloží jako PDF.
- **Úkoly a usnesení** a **Dodavatelé**: jednoduché evidence pro výbor;
  dodavatele jde přiřadit k nahlášené závadě a obyvatel vidí, kdo ji řeší.
- **Vývěska** (`/vyveska`): veřejná stránka bez přihlášení s veřejnými
  oznámeními, termíny a havarijními čísly – na obrazovku v hale nebo přes
  QR kód na cedulce u vchodu (Administrace → Vývěska).
- **Platby na jednotku**: výbor nahraje z účetnictví měsíční zálohu a stav
  účtu každé jednotky (řádky `jednotka ; záloha ; stav ; variabilní
  symbol`). Vlastník vidí jen své jednotky s QR platbou na účet
  společenství; dlužníkům jde poslat upomínku e-mailem. Web sám neúčtuje.
- **Web jako aplikace**: na telefonu jde web „přidat na plochu“ a otevírat
  bez adresního řádku. Upozornění do telefonu zatím neposílá.

## AI pomocník (volitelný)

U textu aktuality, hromadného e-mailu a odpovědi na závadu nabídne
tlačítka: napsat návrh z poznámek, uhladit, zkrátit, navrhnout odpověď.
Výsledek pole přepíše a jde jedním kliknutím vrátit.

Jakmile je na serveru nastaven, mají ho všechny weby zapnutý a v ceně;
správce společenství ho může v Nastavení vypnout. Úvodní stránka služby
ho pak uvádí mezi funkcemi.

Zapnutí:

1. ve složce webu spusťte `composer install` (stáhne oficiální knihovnu
   Anthropic; bez ní web běží celý, jen bez pomocníka),
2. do `storage/config.php` doplňte
   `'ai' => ['api_key' => 'sk-ant-…', 'model' => 'claude-opus-5-5', 'monthly_limit' => 30]`.

Klíč získáte v Claude Console (platform.claude.com) – je to jiný účet
a jiné placení než předplatné aplikace Claude; použití se platí podle
spotřeby z předplaceného kreditu.

Na webu jednoho SVJ určuje `monthly_limit` nejvyšší počet použití za
měsíc. Na platformě pro více SVJ platí jeden klíč pro všechny weby a
limity nastavuje hlavní správce v Nastavení služby: základní počet použití
je v ceně webu (výchozí 30 měsíčně), vyšší (výchozí 300) má web
s placeným doplňkem **AI Plus** (výchozí 990 Kč ročně), který si správce
společenství objedná v Předplatném stejně jako vlastní doménu. V seznamu
společenství je vidět, kolikrát který web pomocníka tento měsíc použil.
Tamtéž lze zvolit model.

U termínu typu shromáždění umí pomocník napsat část zápisu „Průběh
jednání“ z poznámek zapisovatele nebo z nahraného přepisu schůze (.txt,
.docx, .vtt, .srt – třeba z Teams, Google Meet nebo diktafonu v telefonu;
soubor .docx vyžaduje rozšíření PHP zip). Přepis se neukládá. Zvukovou
nahrávku zpracovat nelze – Claude API zvuk nepřijímá.

Ceník na úvodní stránce má část **Připravujeme** s tlačítkem „Mám zájem“
(stejná je v Předplatném). Počty zájemců a kontakty jsou v konzoli pod
**Zájem o funkce**; seznam funkcí je v `PLANNED_FEATURES`
v `app/platform_lib.php`.

Text, který správce pomocníkovi zadá, se odesílá ke zpracování společnosti
Anthropic – nic se neposílá samo a správce je na to u tlačítek upozorněn.
Pokud pomocníka zapnete, uveďte Anthropic mezi dalšími zpracovateli ve
smlouvě o zpracování osobních údajů.

## Základní dokumenty

V administraci v části Dokumenty → **Základní dokumenty** je přehled
toho, co vlastníci na webu hledají nejčastěji: stanovy, domovní řád,
prohlášení vlastníka, výpis z rejstříku, smlouva o správě, pojistná
smlouva, průkaz energetické náročnosti, požární řád, zásady zpracování
osobních údajů, rozpočet a tři formuláře. U každého je vidět, zda je
nahraný, a jde nahrát nebo nahradit novou verzí jedním krokem; odkazy
z aktualit na něj zůstanou platné. Ostatní dokumenty se nahrávají jako
dosud.

## Termíny do kalendáře

U každého termínu je tlačítko **Přidat do kalendáře** (soubor .ics pro
Apple, Outlook i další aplikace) a odkaz do Google Kalendáře. Stránka
**Termíny** (Pro obyvatele) nabízí i odběr všech termínů: kalendářová
aplikace si je stahuje sama z adresy s tajným kódem a nové termíny se
v ní objeví bez dalšího klikání.

## Jazyky

Veřejná část a část pro obyvatele jsou dostupné česky, slovensky,
anglicky, ukrajinsky a vietnamsky. Výchozí jazyk volí správce
společenství v Nastavení; každý návštěvník si ho může přepnout v patičce.
Překládá se rozhraní webu (menu, formuláře, hlášky, data). Obsah, který
píše výbor a obyvatelé, administrace a e-maily zůstávají v původním
znění. Slovníky jsou v `app/lang/` (český text → překlad); překlady jsou
strojové a je vhodné nechat je projít rodilým mluvčím.

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
