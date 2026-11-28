<?php /* Veřejná dokumentace API pro dodavatele (web služby /api). */
$contact = filter_var(pset('contact_email'), FILTER_VALIDATE_EMAIL) ? pset('contact_email') : ''; ?>
<section class="wrap lp-section lp-page-head">
  <h1>API pro dodavatele</h1>
  <p class="lead">Plňte web domu daty přímo ze svého programu: <strong>odečty měřidel</strong> (odečtové firmy), <strong>předpisy záloh, pohledávky a platby</strong> (účetní programy a správci), <strong>aktuality</strong> a přehled <strong>závad</strong>. Vlastníci pak vše vidí na webu svého domu a v telefonu.</p>
  <p><a class="btn" href="<?= url('/api/openapi.json') ?>">Popis OpenAPI 3.1 (JSON)</a><?php if ($contact !== ''): ?> <span class="muted">Dotazy: <?= e($contact) ?></span><?php endif ?></p>
</section>
<section class="wrap lp-section narrow api-docs">
  <h2>Jak získat přístup</h2>
  <ol>
    <li>Výbor (správce webu) domu v <strong>Administrace → API a napojení</strong> vytvoří klíč a zaškrtne, co smíte (např. jen odečty). Volitelně omezí příjem na Vaše IP adresy.</li>
    <li>Klíč (<code>swk_…</code>) Vám předá bezpečně a adresu webu domu zvlášť. Klíč jde kdykoli zrušit.</li>
    <li>Každý požadavek posílejte s hlavičkou <code>Authorization: Bearer swk_…</code> na <code>https://&lt;dům&gt;.spolecnyweb.cz/api/v1/…</code>.</li>
  </ol>
  <p class="hint">API je součástí placených edic webu (Standard, Premium); konto bytu (předpisy a platby) edice Premium.</p>

  <h2>Oprávnění klíče</h2>
  <ul><?php foreach (API_SCOPES as $k => $label): ?><li><code><?= e($k) ?></code> – <?= e($label) ?></li><?php endforeach ?></ul>

  <h2>Odečty měřidel</h2>
  <p><code>POST /api/v1/meter-readings</code> (oprávnění <code>meters:write</code>). Přijímá tři tvary:</p>
  <pre class="code-sample">[{"unit": "A/2", "meter_no": "TV-123456", "kind": "tv", "amount": 41.27, "date": "2027-01-31"}]</pre>
  <ul>
    <li><strong>JSON</strong> výše; <code>kind</code>: <?= e(implode(', ', array_map(fn($k, $l) => "$k ($l)", array_keys(READING_KINDS), READING_KINDS))) ?>. Jednotku lze vynechat, pokud web zná číslo měřidla.</li>
    <li><strong>CSV</strong> se záhlavím (jednotka, číslo měřidla, druh, stav, datum odečtu), <code>Content-Type: text/csv</code>.</li>
    <li><strong>Tvar služby DOMUS AMR</strong> (<code>{generated, provider, readings: [{radioSN, meterSN, houseID, sensor, data: [{value, unit, date, type, medium}]}]}</code>) – stejná data, jaká posíláte do DOMUSu, přijmeme beze změny. Měřidla se párují podle <code>meterSN</code>.</li>
  </ul>
  <p>Podezřelé hodnoty (pokles stavu, nezvyklý skok, přepis staršího nebo ručního odečtu) se neuloží, ale čekají na schválení výborem – v odpovědi je jejich počet v <code>held</code>.</p>

  <h2>Konto bytu z účetnictví</h2>
  <p><code>PUT /api/v1/ledger/receivables</code> a <code>PUT /api/v1/ledger/payments</code> (oprávnění <code>ledger:write</code>). Doklady se párují k jednotkám podle variabilního symbolu; stejný <code>ext_id</code> se přepíše (pošlete znovu, když přijde úhrada).</p>
  <pre class="code-sample">[{"ext_id": "PH26101", "vs": "4812", "date": "2026-11-01", "due": "2026-11-15",
  "text": "Předpis záloh 11/2026", "amount": 3450, "paid": 1000,
  "items": [{"text": "Fond oprav", "amount": 1500}, {"text": "Teplo", "amount": 1450}, {"text": "Studená voda", "amount": 500}]}]</pre>
  <p>Účetní s programem <strong>POHODA</strong> mohou poslat přímo XML export (ostatní pohledávky a banka): <code>POST /api/v1/ledger/import/pohoda</code>. Stav kont: <code>GET /api/v1/ledger/balances</code> (<code>ledger:read</code>).</p>

  <h2>Další adresy</h2>
  <ul>
    <li><code>GET /api/v1/units</code> – jednotky (označení, podíl, plocha, VS), bez osobních údajů.</li>
    <li><code>GET /api/v1/tickets?status=open</code> – závady a požadavky (bez jmen obyvatel).</li>
    <li><code>POST /api/v1/posts</code> – aktualita <code>{"title", "body", "visibility": "public"|"residents"}</code>, obyvatelé dostanou upozornění.</li>
  </ul>

  <h2 id="prevod">Převod obsahu ze starého webu</h2>
  <p>Obsah starého webu (nebo exportu z jiného portálu) převedete sami – skriptem nebo s pomocí AI asistenta přes MCP. Každý záznam může mít <code>ext_id</code> (označení v původním systému): opakované odeslání ho jen přepíše, nic se nezdvojí. Obyvatelům při převodu nic nechodí – žádná upozornění ani pozvánky.</p>
  <ul>
    <li><code>PUT /api/v1/units</code> (<code>units:write</code>) – jednotky <code>{"entrance": "A", "number": "12", "floor": "3", "share": "652/100000", "area": 54.3}</code>; párují se podle vchodu a čísla.</li>
    <li><code>POST /api/v1/people</code> (<code>people:write</code>) – účty obyvatel <code>{"name", "email", "phone", "kind": "owner"|"tenant", "units": ["A/12"]}</code>. Pozvánky se neposílají, pošle je výbor. Nájemník jen k jednotce s vlastníkem.</li>
    <li><code>POST /api/v1/documents</code> (<code>documents:write</code>) – jeden soubor: <code>multipart/form-data</code> s polem <code>file</code>, nebo JSON <code>{"filename", "content_base64"}</code>; dále <code>title</code>, <code>category</code> (<?= e(implode(', ', array_keys(CATEGORIES))) ?>), <code>date</code>, <code>visibility</code>, <code>unit</code>, <code>ext_id</code>.</li>
    <li><code>PUT /api/v1/events</code> (<code>events:write</code>) – termíny <code>{"ext_id", "title", "date", "time", "place", "note"}</code>.</li>
    <li><code>PUT /api/v1/contacts</code> (<code>contacts:write</code>) – kontakty <code>{"ext_id", "group": "vybor"|"sprava"|"havarie", "role", "name", "phone", "email"}</code>.</li>
    <li><code>PUT /api/v1/maintenance</code> (<code>maintenance:write</code>) – revize a kontroly do Hlídání revizí <code>{"ext_id", "title", "contractor", "last_date", "interval_months", "next_date"}</code>; příští termín se dopočítá z posledního a intervalu.</li>
    <li><code>PUT /api/v1/posts</code> (<code>posts:write</code>) – starší aktuality s datem <code>{"ext_id", "title", "body", "published_at", "visibility"}</code>, bez upozornění.</li>
  </ul>
  <pre class="code-sample">curl -H "Authorization: Bearer swk_…" -F file=@stanovy.pdf -F title="Stanovy SVJ" \
     -F category=zakladni -F visibility=residents -F ext_id=old-17 \
     https://&lt;dům&gt;.spolecnyweb.cz/api/v1/documents</pre>

  <h2>Odpovědi a limity</h2>
  <ul>
    <li>JSON v UTF-8. Chyba: <code>{"ok": false, "error": "…"}</code> s kódem 400 (špatná data), 401 (klíč), 402 (funkce není v edici domu), 403 (oprávnění nebo IP), 413 (nad 5 MB; dokument podle limitu serveru a úložiště edice), 429 (blokace).</li>
    <li>Nejvýše 5 000 řádků v jednom požadavku. Po 10 neplatných pokusech za hodinu z jedné adresy blokace na hodinu.</li>
    <li>Každé volání vidí výbor v administraci (kdy, odkud, výsledek).</li>
  </ul>

  <h2>MCP pro AI asistenty</h2>
  <p>Na adrese <code>https://&lt;dům&gt;.spolecnyweb.cz/mcp</code> běží server Model Context Protocol (Streamable HTTP). Asistent (Claude, ChatGPT, Copilot…) s klíčem od výboru vidí přehled domu, jednotky, závady a nedoplatky, na pokyn zveřejní aktualitu a umí převést obsah starého webu (jednotky, účty, dokumenty, termíny, kontakty, aktuality) – vždy jen v rozsahu oprávnění klíče a bez osobních údajů obyvatel. Klientům, které neumějí hlavičku, lze dát adresu <code>…/mcp/swk_…</code>.</p>
</section>
