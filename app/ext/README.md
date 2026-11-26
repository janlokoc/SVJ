# Rozšíření (moduly)

Samostatné moduly webu. Soubory se načítají samy:

- `app/ext/*_lib.php` – funkce (načte `app/lib.php`, i v unit testech a nástrojích),
- `app/ext/routes_*.php` – adresy webu společenství (načte `index.php`; používají `$route(...)` jako ostatní soubory routes_*.php).

Tabulky v databázi domu zakládá modul sám při prvním použití (vzor: `ticket_ready()` v `app/ticket_lib.php`).
Funkce, které stojí peníze nebo potřebují smlouvu s třetí stranou, se zapínají v konzoli přes `BETA_FEATURES` (app/plan_lib.php).
- `app/ext/console_*.php` – stránky konzole provozovatele (`$PATH`, `$METHOD`, `$super` jako v app/platform_console.php),
- `app/ext/partner_*.php` – stránky partnerského portálu (`$pu`, `$partner`, `$plog`),
- `app/ext/site_*.php` – veřejné stránky hlavního webu služby.

Položku v menu administrace domu přidá modul v `*_lib.php`: `$GLOBALS['EXT_ADMIN_NAV']['/admin/neco'] = ['Název', 'Provoz domu', fn() => beta_visible('klic')];`
(skupiny: Obsah webu, Komunikace, Dům a lidé, Provoz domu, Správa webu; třetí prvek je nepovinný – vyhodnotí se až při vykreslení stránky).
