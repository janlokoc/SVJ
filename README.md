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
- Hlášení závad výboru a sledování stavu řešení
- Vlastní účet (kontakt, změna hesla)
- Vlastník může pozvat manžela, manželku nebo dalšího spoluvlastníka –
  pozvaný si přes odkaz z e-mailu nastaví heslo a účet se přiřadí k téže
  jednotce

**Administrace (správce webu)**
- Aktuality: psaní, plánování na datum, připnutí, viditelnost, přílohy
- Dokumenty: nahrávání, kategorie, viditelnost
- Termíny, kontakty, texty webu
- Zprávy e-mailem: rozeslání důležitého oznámení vlastníkům nebo všem
  obyvatelům s účtem, zkušební zpráva, historie odeslaných zpráv
- Uživatelé: zakládání účtů, dočasná hesla, vypnutí účtu
- Jednotky: seznam jednotek s vlastníkem dle evidence, patrem a podílem,
  přiřazení účtů k jednotkám, hromadné založení jednotek
- Závady: stav a odpověď obyvateli, moderace diskuze

## Požadavky

PHP 8.1+ s rozšířeními `pdo_sqlite` a `mbstring`. Žádná databáze ani další
knihovny nejsou potřeba – data jsou v souboru `storage/svj.sqlite`,
nahrané soubory v `storage/uploads/`. Stačí běžný český webhosting s PHP.

## Spuštění na vlastním počítači

```
php -S localhost:8000 index.php
```

a otevřít <http://localhost:8000>.

## Nasazení na hosting

1. Nahrajte obsah repozitáře do kořenové složky webu (FTP).
2. Složka `storage/` musí být zapisovatelná pro PHP.
3. Hosting musí mít Apache s `mod_rewrite` (soubor `.htaccess` je součástí).
   Na Nginx nasměrujte všechny požadavky mimo `/assets/` na `index.php`
   a zakažte přístup do `app/`, `storage/` a `tools/`.
4. Zapněte HTTPS.
5. Otevřete web. **Při prvním spuštění se zobrazí formulář pro vytvoření
   účtu správce** – vyplňte ho hned po nahrání.
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

## Zálohování

Zálohujte složku `storage/` (databáze i nahrané soubory). Nic jiného se
za provozu nemění.

## Struktura

```
index.php            vstupní bod a směrování
app/lib.php          databáze, přihlášení, pomocné funkce
app/routes_*.php     veřejná část, část pro obyvatele, administrace
app/views/           šablony stránek
app/schema.sql       struktura databáze
app/seed.json|php    výchozí obsah převzatý ze starého webu
assets/              vzhled (CSS, JS, písma Fraunces a Figtree – licence OFL)
storage/             data (není v gitu)
tools/               převod souborů ze starého webu
```
