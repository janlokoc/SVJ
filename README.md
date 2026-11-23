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

### Adresy webů

Web každého společenství běží na subdoméně hlavní domény
(`tabor.svj.cz`). Varianta se složkami (`svj.cz/tabor`, dříve volba
`'mode' => 'path'`) byla zrušena a nastavení se ignoruje. Jako složky se
weby otevírají jen při přístupu přes další adresu serveru uvedenou
v `aliases` (IP adresa při zkoušení z telefonu); v textech a e-mailech se
i tehdy uvádí adresa na subdoméně.

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

### Zkoušení přes internet (Cloudflare Tunnel)

Web běžící na vlastním počítači jde zdarma a bez účtu zpřístupnit přes
internet s HTTPS – z iPadu, telefonu na mobilních datech nebo pro další
lidi.

1. Jednou nainstalujte program: `brew install cloudflared`.
2. V `storage/config.php` přidejte do části `platform` volbu `'tunnel' => true`:
   `'platform' => ['domain' => 'localhost', 'tunnel' => true]`.
3. Ve složce webu spusťte `./tools/tunel.sh`. Skript spustí vlastní
   server PHP na volném portu (od 8780; port zabraný jiným programem
   přeskočí, takže se nikdy nepřipojí k jinému webu), ověří, že odpovídá
   tento web, otevře tunel a vypíše adresu
   `https://<náhodná-slova>.trycloudflare.com`. Konkrétní port jde zvolit:
   `PORT=8790 ./tools/tunel.sh`. Data jsou stejná jako u webu spuštěného
   ručně.

Na počítači dál platí `nazev.localhost:8000`. Přes tunel subdomény nejsou,
proto se weby společenství otevírají jako složky:
`https://…trycloudflare.com/krouzova/`, konzole na `…/sprava`, ukázka na
`…/demo/vstup`. Adresa se mění při každém spuštění a platí, jen dokud
skript běží a počítač nespí; vyhledávače ji neindexují. Web bere skutečnou
IP adresu návštěvníka a HTTPS z hlaviček Cloudflare, ale jen u požadavků
z tohoto počítače (od programu cloudflared). Odkazy v e-mailech vedou na
adresu, přes kterou akce proběhla.

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

### Registrace, edice a předplatné

- Z úvodní stránky si kdokoli sám založí web: název a adresa → vzhled →
  účet správce. Web běží hned, **zdarma a bez časového omezení** (edice
  Zdarma). Žádná zkušební doba ani platební karta.
- **Tři edice** – Zdarma, Standard (výchozí 2 990 Kč ročně) a Premium
  (5 990 Kč ročně). Liší se limity (správci, účty, hromadné e-maily za
  měsíc, úložiště, použití AI, hlasování za rok) a funkcemi. Ceny a limity
  se nastavují v konzoli v **Nastavení služby**; 0 = bez omezení. Které
  funkce patří do které edice, je v `PLAN_FEATURES` v `app/plan_lib.php`.
  Platby na jednotku, rezervace a jazykové verze jsou v Premium; web se
  Standardem zaplaceným před jejich přesunem (`PLAN_MOVED`) je má do konce
  zaplaceného období.
- **Limity nebrání v používání.** Po dosažení limitu web běží dál a nic se
  nemaže – jen nejde přidat další účet, odeslat další hromadný e-mail,
  nahrát další soubor nebo založit další hlasování.
- **Zkoušení funkcí.** Každou funkci vyšší edice si správce v části
  Předplatné (nebo přímo kliknutím na ni v menu administrace) jednou na
  30 dní zdarma zapne. Sedm a dva dny před koncem přijde e-mail. Po
  skončení zůstanou data uložená a viditelná, funkce je jen pro čtení.
  Web založený s jinou šablonou než Klasická ji dostane rovnou na zkoušku.
- **Objednávka edice.** Správce v Předplatném klikne na „Objednat
  Standard/Premium“, dostane zálohovou fakturu s QR platbou (účet,
  částka, variabilní symbol) a po připsání platby ji hlavní správce
  v konzoli v části Platby potvrdí. Stejná edice se prodlužuje od konce
  období, přechod na jinou začíná dnem potvrzení (bez přepočtu zbytku).
  Platba kartou není zapojena – vyžaduje smlouvu s platební bránou.
- **Když se edice neprodlouží:** běží ještě nastavenou lhůtu (výchozí
  14 dní) a potom web přejde na edici Zdarma. Nic se nepozastavuje ani
  nemaže; účty nad limit fungují dál.
- **Mazání nepoužívaných webů:** web v edici Zdarma, na který se nikdo
  nepřihlásil déle než nastavený počet dní (výchozí 365, 0 = nemazat), se
  smaže včetně dat. Kontaktní osobě chodí upozornění 14 a 3 dny předem;
  bez prokazatelně odeslaného upozornění se nic nemaže a přihlášení lhůtu
  vynuluje. Odeslaná upozornění jsou v konzoli v části Záznamy.
- **Platby:** edice se platí jen ročně (zálohová faktura s QR kódem,
  platba převodem). Vedle toho jsou roční doplňky (vlastní doména,
  úložiště navíc, AI Plus) a jednorázové platby (převod starého webu).
  Měsíční platba se nenabízí.
- **Zrušení webu správcem:** správce společenství může v Předplatném
  požádat o smazání celého webu (opíše adresu webu a zadá heslo). Web se
  smaže po 7 dnech; do té doby běží, všichni správci dostanou e-mail
  a kterýkoli z nich může žádost vzít zpět. V ukázkovém webu to nejde.
- **Automatické prodlužování:** před koncem období se připraví zálohová
  faktura na další období a správci přijde upozornění. Nic se nestrhává –
  platí se převodem. Správce může prodlužování v Předplatném vypnout;
  edice pak doběhne a web přejde na edici Zdarma.
- **Úložiště:** výchozí limity jsou 50 MB (Zdarma), 100 MB (Standard)
  a 500 MB (Premium). Správce vidí stav v Nastavení webu a v Předplatném;
  od 80 % zaplnění dostane upozornění. V kterékoli edici si může přikoupit
  **úložiště navíc** (výchozí 500 MB za 290 Kč ročně, nastavitelné; cena 0
  nabídku vypne) – platí se stejně jako edice, QR platbou.
- Standard má výchozí limit 5 hlasování ročně, Premium bez omezení.
- **Záloha dat ke stažení** je součástí placených edic (v edici Zdarma
  ji lze jednou na 30 dní vyzkoušet). Vlastní údaje si každý uživatel
  stáhne v kterékoli edici.
- **Ukázkový web** nejde smazat a sám se obnovuje s čerstvými daty
  (výchozí každých 7 dní, Nastavení služby), aby termíny a lhůty vypadaly
  aktuálně. Obnovit ho lze i ručně v konzoli.
- **Doporučení a kredity:** každé společenství má v Předplatném svůj odkaz
  (`…/?ref=KÓD`). Kdo se přes něj zaregistruje, dostane slevu na první
  roční platbu (výchozí 150 Kč). Doporučitel dostane odměnu (výchozí
  300 Kč), jakmile doporučené společenství poprvé zaplatí roční edici – jen
  jednou za každé společenství.
  Odměna se připíše jako kredit a sama se odečte od příští roční platby;
  nevyplácí se. Obě částky jsou v Nastavení služby (0 = vypnuto). Sám sebe
  (stejný kontaktní e-mail) doporučit nejde.
  Správce vidí počet otevření odkazu, registrací a platících, stav každého
  doporučeného společenství (zaregistrovalo se, zkouší funkce, objednalo,
  zaplatilo) a kolik kreditu získal a uplatnil.
- **DPH:** v Nastavení služby (Faktury a DPH) lze zapnout „Provozovatel je
  plátce DPH“ a sazbu. Ceny v nastavení i v ceníku jsou **bez DPH**; daň se
  připočte k platbě (QR kód, zálohová faktura) a faktura je
  daňový doklad s DIČ, základem daně a DPH. Sazba se ukládá u každé platby,
  takže zaplacené doklady se změnou nastavení nemění; čekající platby se
  přepočtou. Podobu dokladů si nechte potvrdit účetní.
- **Podpora (konzole → společenství → Podpora: přihlášení a hesla):**
  - *Vstup do webu očima uživatele:* hlavní správce vybere kterýkoli
    aktivní účet (správce, vlastník, nájemník), napíše důvod a dostane
    jednorázový odkaz (2 minuty). Web se otevře přihlášený za toho
    uživatele **jen pro čtení**: žádný formulář se neprovede, zápisy do
    databáze jsou zablokované (u SQLite i zámkem `query_only`), denní
    úlohy se nespouštějí a soukromé zprávy mezi uživateli se nezobrazují.
    Nahoře je červený pruh „Režim podpory – jen pro čtení“. Vstup
    (kdo, za koho, proč) i odchod se zapisují do záznamů společenství a
    platformy. Heslo ani přihlášení uživatele se nemění.
  - *Pomoc s přihlášením:* odkaz na nové heslo pro kterýkoli účet (platí
    24 hodin, zobrazí se i v konzoli) a vypnutí dvoufázového ověření.
  - Obchodní podmínky, smlouva o zpracování i zásady ochrany údajů tento
    režim popisují.
- **Právní texty (vzory):** obchodní podmínky, smlouva o zpracování údajů a
  zásady ochrany údajů jsou výchozí texty v `app/platform_lib.php`
  (`PLATFORM_TERMS`, `PLATFORM_DPA`, `PLATFORM_PRIVACY`). Pokrývají mimo
  jiné: zákazník není spotřebitel, roční platba předem a ceny bez DPH,
  doplňky a jednorázové služby, automatické prodloužení bez dluhu,
  reklamace, dostupnost bez záruky, zálohy, režim podpory jen pro čtení,
  AI pomocník (text odchází třetí straně), omezení odpovědnosti, zrušení
  webu, ukončení služby a lhůty pro oznámení změn. Texty uložené
  v Nastavení služby mají přednost před výchozími – po aktualizaci je tam
  porovnejte. Před spuštěním je nechte projít právníkem.
- **Přiřazení k jednotce přes odkaz:** v Uživatelích → „Odkaz pro
  obyvatele“ správce zapne odkaz (s QR kódem na nástěnku) a rozešle ho.
  Obyvatel vyplní jméno, e-mail, jednotku a zda je vlastník, nebo
  nájemník. Účet nevznikne, dokud správce žádost neschválí; před
  schválením musí potvrdit, že e-mail ověřil (stránka ukazuje vlastníka
  podle evidence z katastru, kdo už je k jednotce přiřazen a jestli na
  stejnou jednotku čeká víc žádostí). Po schválení přijde pozvánka
  s odkazem na nastavení hesla; existujícímu účtu se jednotka jen přidá.
  Odkaz jde vypnout nebo vyměnit, formulář má ochranu proti robotům
  a limit 5 žádostí za hodinu z jedné adresy. Vyřízené žádosti se po
  30 dnech mažou.
- **Průvodce pro nového správce:** Administrace → Průvodce nastavením
  (a karta na přehledu) vede správce kroky od údajů společenství přes import
  jednotek a obyvatel, kontakty a dokumenty po oznámení sousedům. U každého
  kroku je semafor: hotovo (pozná se podle obsahu webu, nebo se odškrtne),
  čeká – důležité, čeká, přeskočeno.
- **Seznam před spuštěním:** nahoře v Nastavení služby je přehled toho, co
  ještě chybí vyplnit (provozovatel, účet, e-maily, právní texty); na
  totéž upozorňuje i přehled společenství.
- **Ruční nastavení předplatného:** hlavní správce může u kteréhokoli
  společenství v konzoli (karta Předplatné) nastavit edici a datum, do
  kdy platí, prodloužit ji o měsíc nebo rok, ukončit ji, nebo placení
  vypnout úplně – bez platby a faktury (dar, sleva, platba mimo systém).
  Tamtéž zapne, prodlouží nebo ukončí zkoušku jednotlivé funkce. Každá
  změna se zapíše do Záznamů; společenství lze poslat e-mail.
- **Přechod mezi edicemi:** při přechodu na vyšší edici se od ceny odečte
  nevyčerpaná část té dosavadní. Na nižší edici lze přejít až po skončení
  zaplaceného období.
- Web založený hlavním správcem v konzoli s vypnutou volbou „Web je
  placený“ nemá žádné limity a nic neplatí.
- Vlastní doména je doplněk za příplatek k edici Standard i Premium (v žádné edici není v ceně).
  Dřívější doplněk AI Plus nahradila edice Premium.
- Platby se nepárují s bankou automaticky: hlavní správce po připsání
  na účet platbu v konzoli v části **Platby** potvrdí a předplatné se
  prodlouží.
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

Převod je i placená služba: správce společenství si ho objedná
v **Předplatném** formulářem „Převod obsahu ze starého webu“ (adresa
starého webu, co převést, jak se k obsahu dostaneme, kontaktní osoba,
potvrzení práv k obsahu). Vznikne zálohová faktura s QR platbou (výchozí
1 500 Kč jednorázově, mění se v Nastavení služby). Po potvrzení platby
v konzoli najdete objednávku u společenství; obsah naimportujete nástroji
níže a převod označíte jako hotový – kontaktní osoba dostane e-mail.
Samotný import se nespouští automaticky.

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

### Zkušební prostředí (staging)

Stejný kód lze provozovat jako zkušební prostředí s vlastní složkou
`storage` (tedy vlastními daty). V `storage/config.php` přidejte:

```php
'env' => 'staging',
'staging_mail' => 'vy@example.cz',   // nepovinné
```

Na každé stránce se pak zobrazí pruh „Zkušební prostředí“, vyhledávačům
se zakáže indexování a **žádný e-mail neodejde skutečným lidem**: všechny
se přesměrují na adresu `staging_mail` (původní příjemce je v předmětu),
bez ní se neodešlou vůbec. Vývoj probíhá ve zkušební větvi; do produkční
větve patří jen to, co bylo na stagingu vyzkoušeno.

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

## Přílohy u závad

K nahlášené závadě lze přiložit až 4 fotografie nebo dokumenty (JPG, PNG,
PDF). Velké fotografie z telefonu se před odesláním v prohlížeči zmenší.
Přílohy vidí jen ten, kdo závadu nahlásil, a správci; počítají se do
limitu úložiště edice. Do souboru zálohy se zatím nezahrnují.

## Zprávy mezi sousedy a funkce ve společenství

- **Zprávy** (Pro obyvatele → Zprávy): soukromé rozhovory dvou přihlášených
  lidí z téhož domu. Vidí je jen odesílatel a příjemce; v administraci se
  nezobrazují. Na první nepřečtenou zprávu v rozhovoru přijde e-mail (bez
  textu zprávy), pokud má příjemce zapnutá upozornění. Stránka rozhovoru
  si nové zprávy sama dotahuje. Kdo zprávy od sousedů nechce, vypne je ve
  svém účtu – výbor, kontrolní komise a správci mu psát mohou vždy.
  Výbor může celou funkci schovat v Nastavení (Části webu).
- **Funkce ve společenství:** u uživatele lze nastavit, zda je předseda,
  místopředseda nebo člen výboru, předseda či člen kontrolní komise,
  předseda společenství nebo správce domu. Funkce je vidět u jména
  v kontaktech (oddíl „Kdo je kdo“), ve zprávách, v diskuzi i v hlavičce.
  S oprávněním na webu nesouvisí – to určuje role Správce webu.

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
měsíc. Na platformě pro více SVJ platí jeden klíč pro všechny weby a počet
použití určuje edice webu (výchozí 5 / 30 / 300 měsíčně, mění se v tabulce
limitů v Nastavení služby). V seznamu společenství je vidět, kolikrát
který web pomocníka tento měsíc použil. Tamtéž lze zvolit model. Zápis ze
shromáždění pomocí AI je funkce edice Premium.

U termínu typu shromáždění umí pomocník napsat část zápisu „Průběh
jednání“ z poznámek zapisovatele nebo z nahraného přepisu schůze (.txt,
.docx, .vtt, .srt – třeba z Teams, Google Meet nebo diktafonu v telefonu;
soubor .docx vyžaduje rozšíření PHP zip). Přepis se neukládá. Zvukovou
nahrávku zpracovat nelze – Claude API zvuk nepřijímá.

Ceník na úvodní stránce má část **Připravujeme** (stejná je v Předplatném).
Každá funkce má stránku s detailem – jaký problém řeší a co přesně bude
dělat. Zájem se měří počtem prokliků na detail: v konzoli pod **Zájem
o funkce** jsou unikátní návštěvníci (jednou za den, roboti se nepočítají),
prokliky celkem, počet společenství a žádosti o zprávu. Texty jsou
v `PLANNED_FEATURES` a `PLANNED_DETAILS` v `app/platform_lib.php`.

Návštěvník si v patičce volí světlý nebo tmavý vzhled, případně vzhled
podle zařízení (výchozí). Volba se ukládá do cookie `svj_theme`.

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

## Ochrana osobních údajů (GDPR)

- **Moje údaje a soukromí** (Můj účet): přehled toho, co web o člověku
  ukládá, stažení všech údajů v JSON (přístup a přenositelnost) a zrušení
  vlastního účtu heslem (výmaz). Poslední správce svůj účet zrušit nemůže.
- Při zrušení účtu se smažou kontaktní údaje, zprávy, rezervace
  a přiřazení k jednotce. Zůstávají příspěvky a závady bez jména, hlasy
  a účast jednotky (součást zápisů), vlastník podle katastru a provozní
  záznamy o přihlášení.
- Výbor může údaje uživatele stáhnout za něj (formulář uživatele) –
  pro žádosti lidí, kteří se sami nepřihlásí.
- Stránka **Ochrana osobních údajů** webu společenství popisuje práva,
  zpracovatele, cookies a lhůty; výbor si text může upravit v Nastavení
  (weby s vlastním textem se nemění). Služba sama má vlastní zásady na
  adrese `/ochrana-udaju` hlavní domény.
- Texty jsou výchozí vzor, ne právní posouzení – před ostrým provozem je
  nechte zkontrolovat.

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

## Vzhled stránek služby a šablon

- **Stránky služby** (úvodní stránka, Funkce, Ceník, registrace) mají vlastní
  vzhled podle vchodu bytového domu: pozadí v barvě omítky, tmavě modrá jako
  smaltovaná cedule s číslem domu a jediný teplý akcent – mosazné tlačítko
  zvonku. Úvodní ilustrace je zvonkové tablo, na kterém jsou místo jmenovek
  části webu (`app/views/platform/_tablo.php`); zvonky se při načtení jednou
  postupně rozsvítí (při omezení pohybu v systému ne). Jinak se na stránce
  nic nehýbe. Barvy a písmo jsou v `assets/style.css` pod
  `[data-site="sluzba"]`, takže se nepletou do webů společenství.
- **Písmo:** Archivo (nadpisy) a Atkinson Hyperlegible Next (text, navržené
  pro dobrou čitelnost). Obě jsou uložená ve `assets/fonts` a šíří se pod
  licencí SIL Open Font License 1.1 (texty licencí jsou vedle souborů písma).
- **Šablony webů SVJ:** štítek nad nadpisem je obyčejná věta (jen Novinová
  šablona si nechává verzálky), druhý řádek nadpisu (např. čísla popisná) je
  klidný podnadpis, barva Les má místo krémové chladnější papír a hlubší
  cihlovou. Tlačítka a odkazy jsou bez šipek.

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
