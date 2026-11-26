# Co potřebuji od provozovatele

Stav k 26. 11. 2026. Funkce níže jsou na webu hotové a otestované unit testy. Všechny, které stojí peníze nebo potřebují
smlouvu, jsou v **beta programu vypnuté** (konzole `/sprava` → Beta program, položky `beta_*`). Zapněte je až
po vyřízení bodu níže a vždy nejdřív na stagingu (`beta` = jen domy s Premium, `on` = všichni).

Přístupové údaje patří **jen do `storage/config.php`** na serveru (vzor je v `config.example.php`), nikdy do repozitáře ani do chatu.

## 1. Účty a smlouvy u externích služeb

| # | Služba | Co zařídit | Kam s údaji | Náklady (neověřeno, ověřte u dodavatele) | Beta |
|---|---|---|---|---|---|
| 1 | **SMS brána** – GoSMS (gosms.eu) nebo BulkGate (bulkgate.com) | Firemní účet, ověřit odesílatele (jméno „SVJ“ apod.), dobít kredit | `sms` v config.php; brána a cena SMS pro domy v konzoli → SMS | cca 1 Kč/SMS u brány; cenu pro domy nastavujete sami | `beta_sms` |
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
| Facebook: věřit e-mailu z Facebooku (přihlásit k existujícímu účtu se stejným e-mailem)? | ano; vypnete `sso.facebook.trust_email = false` – pak jen účty, které si uživatel propojil sám |
| Nové hlasování per rollam: ověřovat hlas kódem z e-mailu? | ano u nových, staré beze změny |
| Bank iD vypnuté uprostřed hlasování | hlasování se odmítne (nepřepne se tiše na e-mail) |
| AI pomocník pro obyvatele | zapnutý, 20× denně na osobu, výbor může vypnout |
| AI pomocník v partnerském portálu | 50× denně na firmu, náklady nese platforma |

## 5. Před spuštěním placené bety

Až budou první platící zákazníci: u každé služby výše přepnout `beta_*` z `off` na `beta` (jen Premium) a sledovat náklady
v konzoli (SMS kredit, AI tokeny, lustrace). Na `on` až po ověření na skutečném domě.
