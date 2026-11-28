# Co potřebuji od provozovatele

Stav k 26. 11. 2026. Funkce níže jsou na webu hotové a otestované unit testy. Všechny, které stojí peníze nebo potřebují
smlouvu, jsou v **beta programu vypnuté** (konzole `/sprava` → Beta program, položky `beta_*`). Zapněte je až
po vyřízení bodu níže a vždy nejdřív na stagingu (`beta` = jen domy s Premium, `on` = všichni).

Přístupové údaje patří **jen do `storage/config.php`** na serveru (vzor je v `config.example.php`), nikdy do repozitáře ani do chatu.

## 1. Účty a smlouvy u externích služeb

| # | Služba | Co zařídit | Kam s údaji | Náklady (neověřeno, ověřte u dodavatele) | Beta |
|---|---|---|---|---|---|
| 1 | **SMS brána** – GoSMS (gosms.eu) nebo BulkGate (bulkgate.com) | Firemní účet, ověřit odesílatele (jméno „SVJ“ apod.), dobít kredit | `sms` v config.php; brána v konzoli → SMS, cena SMS pro domy v konzoli → Kredit | cca 1 Kč/SMS u brány; cenu pro domy nastavujete sami | `beta_sms` |
| 2 | **GoCardless Bank Account Data** (PSD2 – ČS, KB, ČSOB, Raiffeisen, Moneta…) | Registrace v portálu, vytvořit Secret ID a Secret Key. **Ověřte, zda nové registrace přijímají** – neměl jsem jak ověřit. | `bank.gocardless_id`, `bank.gocardless_key` | dle ceníku GoCardless | `beta_bank-psd2` |
| 3 | **ČÚZK – WSDP** (sledování změn v katastru) | Žádost o účet do Webové služby dálkového přístupu (smlouva s ČÚZK, placený přístup) | `katastr.user`, `katastr.password` | cca 100 Kč za výpis LV (neověřeno) | `beta_katastr` |
| 4 | **Centrální evidence exekucí** (Exekutorská komora) | Smlouva o přístupu přes API; nejdřív testovací klíč | `exekuce.api_key`, `exekuce.api_secret` (+ `url` testovacího prostředí) | cca 60 Kč za lustraci (neověřeno) | `beta_exekuce` |
| 5 | **Bank iD** (ověření hlasu v per rollam) | Smlouva s Bankovní identita, a.s., registrace aplikace na developer.bankid.cz (scopes `openid profile.name profile.birthdate`, `client_secret_post`). Rozhodnout: návratová adresa každého domu `https://<dům>/bankid/navrat`, nebo jedna centrální. | `bankid.client_id`, `client_secret`, `issuer` (`sandbox`/`production`) | veřejný ceník jsem nenašel | `beta_bankid` |
| 6 | **Přihlášení Google** | Google Cloud Console → OAuth klient (Web), návratová adresa `https://spolecnyweb.cz/sso/callback/google` | `sso.google` | zdarma | `beta_sso` |
| 7 | **Přihlášení Microsoft** | Microsoft Entra → Registrace aplikace (osobní i pracovní účty), návrat `…/sso/callback/microsoft` | `sso.microsoft` | zdarma | `beta_sso` |
| 8 | **Přihlášení Apple** | Apple Developer Program (99 USD/rok), Services ID + klíč „Sign in with Apple“ (.p8 mimo web), návrat `…/sso/callback/apple` | `sso.apple` | 99 USD/rok | `beta_sso` |
| 9 | **Přihlášení Facebook** | Meta for Developers → aplikace typu „Consumer“, Facebook Login, ověření firmy, návrat `…/sso/callback/facebook` | `sso.facebook` | zdarma | `beta_sso` |
| 10 | **Google Play** (aplikace webu v obchodě) | Vývojářský účet Google Play (25 USD jednorázově), vygenerovat balíček TWA | v konzoli `twa_package`, `twa_sha256` | 25 USD | – |
| 11 | **Insolvenční rejstřík** (ISIR) | Nic – veřejná služba. Jen ověřit na produkci, že hlídání osob vrací výsledky (názvy polí pro fyzické osoby jsem nemohl ověřit proti živé službě). | – | zdarma | `beta_insolvence` |

## 2. Ověření s reálnými programy a daty

- **Účetnictví:** zkušební import exportů z webu do skutečné POHODY, Money S3 a ABRA Flexi (formáty jsou podle dokumentace, ale neprošly skutečným programem).
- **Programy správců:** ukázkové exporty z DOMSYS, IDES, MECOM a Integri (bez osobních údajů, nebo s Vaším svolením), ať doladím import.

## 3. Údaje a texty

- Údaje provozovatele v konzoli (`op_*` – název, IČO, adresa, kontakt) a poznámka o hostingu (`hosting_note`) – používají se ve smlouvě o zpracování osobních údajů (Data a smlouva).
- **Právník:** zkontrolovat stránku `/srovnani` (srovnání s konkurencí – je vypnutá, `compare_on`), vzorová ustanovení stanov (shromáždění online, hlasování per rollam) a smlouvu o zpracování.
- **GDPR:** doplnit do zásad zpracovatele nové příjemce – SMS brána, poskytovatelé přihlášení (Google, Microsoft, Apple, Meta), GoCardless, ČÚZK, Exekutorská komora, Bank iD; a zálohy v artefaktech GitHubu.

## 4. Rozhodnutí, která čekají na Vás (zatím platí moje výchozí volba)

| Otázka | Výchozí stav teď |
|---|---|
| Kdo je online na shromáždění a nehlasuje – počítat jako „zdržel se“? | ano, zdržel se |
| Plán oprav a fond – jen Premium? | ano, Premium |
| Vyúčtování služeb vidí jen vlastníci (ne nájemníci)? | ano, jen vlastníci |
| Facebook: věřit e-mailu z Facebooku (přihlásit k existujícímu účtu se stejným e-mailem)? | ne (od 11/2026, bezpečnost) – jen účty, které si uživatel propojil sám; zapnete `sso.facebook.trust_email = true` (v konzoli a portálu se podle e-mailu nepřihlašuje nikdy) |
| Nové hlasování per rollam: ověřovat hlas kódem z e-mailu? | ano u nových, staré beze změny |
| Bank iD vypnuté uprostřed hlasování | hlasování se odmítne (nepřepne se tiše na e-mail) |
| AI pomocník pro obyvatele | zapnutý, 20× denně na osobu, výbor může vypnout |
| AI pomocník v partnerském portálu | 50× denně na firmu, náklady nese platforma |
| Nezaplacené sledování katastru | běží 30 dní od výzvy k platbě, pak se kontroly pozastaví do zaplacení |
| Kredit došel těsně před ověřeným hlasem / lustrací (souběh) | služba proběhne, haléřový rozdíl nese provozovatel |

## 5. Ceny doplňků a kredit

Služby placené podle spotřeby se platí z jednoho **předplaceného kreditu služeb v Kč** na dům (bez DPH). Dům si ho dobije
v administraci (Kredit služeb, 300 / 500 / 1 000 / 2 000 Kč nebo vlastní částka od 300 Kč) zálohovou fakturou s QR kódem;
po potvrzení platby v konzoli (Platby) se kredit připíše a vystaví se faktura. Dům, za který platí správcovská firma,
dostane dobití na její společnou fakturu. Kredit platí 24 měsíců, zbytek pak propadne.

| Služba | Cena bez DPH (výchozí) | Klíč nastavení |
|---|---|---|
| SMS | 1,50 Kč za SMS; Premium 100 SMS ročně v ceně | `sms_price_h` (haléře), `sms_free_premium` |
| Lustrace exekucí | 99 Kč za úspěšný dotaz | `rej_price_exekuce` |
| Ověření hlasu přes Bank iD | 19 Kč za ověřený hlas | `bankid_price` |
| Shromáždění online | v ceně (Standard i Premium), výchozí 0 Kč | `price_online_meeting` |
| Sledování katastru (doplněk k ročnímu předplatnému) | 1 490 Kč za dům a rok vč. 1 LV, další LV 990 Kč; kontrola po 30 dnech | `rej_price_katastr_house`, `rej_price_katastr_lv_extra`, `rej_katastr_days` |
| Kredit | platnost 24 měsíců, upozornění správcům pod 100 Kč | `credit_valid_months`, `credit_low_h` (haléře) |

V ceně edice bez příplatku: hlídání insolvencí a propojení s bankami (PSD2) v Premium, přihlášení přes Google/Microsoft/Apple/Facebook
ve všech edicích, export do účetnictví od Standardu.

**Kde se mění:** konzole `/sprava/kredit` – ceny, zůstatky domů, ruční oprava, „Vrátit zůstatek“ při zrušení služby
(kredit se vynuluje, peníze pošlete převodem a k faktuře za dobití vystavíte opravný doklad) a měsíční spotřeba.
Dosavadní SMS kredit domů (počet SMS) se při aktualizaci převedl na Kč podle tehdejší ceny SMS.

**Ověřit s účetní:** kredit považujeme za **jednoúčelový poukaz** – DPH se odvádí už při platbě za dobití (všechny služby
mají sazbu 21 %) a čerpání se nefakturuje znovu. Tento režim, text faktury za dobití a postup při vrácení zůstatku
(opravný doklad) musí potvrdit účetní, než kredit spustíte.

## 6. Před spuštěním placené bety

Až budou první platící zákazníci: u každé služby výše přepnout `beta_*` z `off` na `beta` (jen Premium) a sledovat náklady
v konzoli (Kredit služeb, AI tokeny, Rejstříky). Na `on` až po ověření na skutečném domě.

## 7. Odpovědi e-mailem (schránka pro odpovědi)

Funkce je hotová a otestovaná unit testy, **bez nastavení je vypnutá** (e-maily dál píšou, že odpověď na e-mail se nepřipojí).
Po zapnutí se odpověď na e-mail připojí ke správnému vláknu: odpověď autora hlášení na e-mail od podpory (jako „Moje hlášení“,
hlášení se vrátí mezi nová), odpověď obyvatele na zprávu výboru k závadě (jako odpověď na webu, vyřešená závada se znovu otevře,
výbor dostane upozornění) a odpověď správce domu na upozornění o závadě (uloží se jako zpráva obyvateli).

1. **Schránka:** u hostingu založte schránku, např. `odpovedi@spolecnyweb.cz` (u Českého hostingu v klientské sekci
   u domény v části e-mailů – přesný název položek jsem **neověřil**). Schránku používejte jen pro tuto funkci.
2. **Údaje IMAP:** server, port a šifrování najdete u schránky v klientské sekci nebo v nápovědě hostingu.
   Obvyklé je port 993 se SSL – adresu serveru (např. `imap.<něco>.cz`) jsem pro Český hosting **neověřil**.
3. **Do `storage/config.php` na produkci** (heslo jen sem, nikam jinam):
   ```php
   'inbound' => ['host' => 'IMAP-SERVER', 'port' => 993, 'secure' => 'ssl', 'user' => 'odpovedi@spolecnyweb.cz',
                 'password' => 'HESLO-SCHRANKY', 'address' => 'odpovedi@spolecnyweb.cz', 'folder' => 'INBOX', 'keep_days' => 30],
   ```
   `keep_days`: po kolika dnech se zpracované a odmítnuté e-maily ve schránce smažou (0 = zpracované hned).
   Plus-adresy (`odpovedi+značka@…`) jsou vypnuté – zapněte `'plus' => true` jen tehdy, když ověříte, že je hosting doručuje.
4. **Webcron:** stávající volání `https://spolecnyweb.cz/cron/<klíč>` (klíč `cron_key`) schránku kontroluje při každém
   spuštění. Doporučuji ho spouštět **každých 5–15 minut** (o tolik se odpověď nejvýš zpozdí); každý běh zpracuje nejvýš
   20 zpráv (`max_per_run`) a trvá nejvýš 30 s.
5. **Kontrola:** konzole → Nastavení služby → **Odpovědi e-mailem** (stav, poslední kontrola, počty za 7 dní, poslední
   chyba, odmítnuté zprávy) a tlačítko **Zkontrolovat schránku teď**.
6. **Zkouška od začátku do konce:** v konzoli odpovězte na své zkušební hlášení (Hlášení → odpověď) → v e-mailu klikněte
   na Odpovědět, napište text a odešlete → v konzoli „Zkontrolovat schránku teď“ → text je ve vlákně hlášení a hlášení je
   mezi novými. Pro závady totéž na webu domu: výbor napíše obyvateli zprávu u závady, obyvatel odpoví na e-mail, odpověď
   je u závady (Závady a požadavky) a výbor dostal upozornění.
7. **Staging:** má vlastní `storage/config.php`. Sekci `inbound` tam dávejte jen s **jinou schránkou** (např.
   `odpovedi-test@…`) a s `'staging_ok' => true` – bez toho je funkce na stagingu vypnutá, takže produkční schránku nikdy
   nepřečte. Staging posílá e-maily jen na `staging_mail`; aby odpověď prošla, musí mít zkušební uživatel (autor hlášení
   / obyvatel) stejnou adresu jako `staging_mail` – odpověď z jiné adresy se odmítne (značka je vázaná na adresu příjemce).
8. **GDPR:** do zásad doplnit, že odpovědi e-mailem se ukládají do vlákna (jen text, u závad i přílohy, u hlášení snímek);
   původní e-maily zůstávají ve schránce nejvýš `keep_days` dní, záznam o zpracování (odesílatel, výsledek, bez textu) 30 dní.
