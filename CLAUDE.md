# Pravidla pro vývoj webu SVJ

- Komunikace s provozovatelem česky, formálně (Vy), stručně; poctivě rozlišovat „ověřeno“ a „neověřeno“.
- Vývoj jen na větvi `claude/svj-web-redesign-admin-1m52yt` (staging). `main` je produkce – nic tam bez výslovného souhlasu.
- Ceny se uvádějí jen bez DPH (i na veřejném ceníku, rozhodnutí provozovatele 11/2026); DPH se dopočítá až na faktuře.

## Nápověda (od 11/2026 závazné)

Co není z obrazovky intuitivní, dostane článek v nápovědě – a to **se snímky a ověřeným postupem**:

1. Nejdřív zvážit, jestli obrazovku neupravit tak, aby návod nebyl potřeba. Návod jen pro to, co si
   správce přináší zvenku (katastr, odečtová firma, doména, SMTP…) nebo pro postupy o více krocích.
2. Článek: `app/help/{slug}.php`, zápis do `HELP` v `app/routes_help.php`, odkaz `help_link()` přímo
   na obrazovce, kde se věc dělá.
3. Postup projít v `tools/napoveda/overit.js` (krok za krokem v prohlížeči, s kontrolami) a odtud
   pořídit snímky do `assets/help/`. Každé tvrzení v článku musí být ověřené tímto během; co ověřit
   nejde (cizí weby), musí být v článku i v `note` výslovně uvedeno.
4. Po změně funkce, které se návod týká, spustit `node tools/napoveda/overit.js` znovu – snímky
   a datum ověření se obnoví; když kontrola selže, návod se musí opravit.

## Testy (od 11/2026 závazné)

- Každá nová funkce nebo změna chování má **unit testy** (PHPUnit, `tests/Unit/`), ve stejném commitu jako kód.
  Opravená chyba dostane test, který by ji zachytil.
- Spuštění: `vendor/bin/phpunit` (po `composer install`). Testy běží nad dočasnou kopií platformy s ukázkovým domem
  (`tests/bootstrap.php`), e-maily se jen zachytávají, nic nesmí volat síť.
- GitHub Actions spouští testy před každým nasazením; když neprojdou, na staging ani do produkce se nic nenasadí.
- End-to-end testy v prohlížeči (Playwright) a `tools/napoveda/overit.js` jsou doplněk, ne náhrada unit testů.
