<?php $ex = is_file($f = __DIR__ . '/../../assets/help/api-ukazka.json') ? json_decode((string) file_get_contents($f), true) : null; ?>
<p>Tuto stránku můžete poslat odečtové firmě nebo technikovi, který spravuje dálkové odečty v domě. Popisuje, jak posílat stavy měřidel na web společenství automaticky – bez ručního nahrávání souborů.</p>

<h2>Pro správce: zapnutí příjmu</h2>
<p>V administraci <strong>Odečty → Dálkové odečty a import → Automatický příjem dat</strong> klikněte na <em>Zapnout automatický příjem</em>. Web ukáže <strong>adresu</strong> a zvlášť tajný <strong>klíč</strong>. Adresu pošlete odečtové firmě e-mailem, klíč jí předejte jinou cestou (telefonicky, SMS) – kdo klíč nezná, data poslat nemůže. Klíč můžete kdykoli vyměnit (<em>Vytvořit nový klíč</em>) nebo příjem vypnout.</p>
<?= help_fig('api-1-adresa.jpg', 'Adresa a klíč pro příjem dat (na snímku je klíč skrytý), seznam povolených IP adres a přehled posledního příjmu.') ?>
<p><strong>Ochrana příjmu:</strong></p>
<ul>
  <li><strong>Povolené IP adresy</strong> (nepovinné): když je vyplníte, web přijme data jen z nich – z jiné adresy je odmítne i se správným klíčem.</li>
  <li><strong>Neúspěšné pokusy</strong> (špatný klíč, nepovolená adresa) se zapisují; po 10 pokusech za hodinu web adresu odesílatele na hodinu zablokuje a pošle správcům e-mail. Počet neúspěšných pokusů a poslední příjem dat (kdy, odkud, kolik řádků) vidíte v administraci.</li>
  <li><strong>Podezřelé řádky se neuloží</strong>, ale čekají na Vaše schválení: stav nižší než předchozí odečet, nezvykle vysoká spotřeba, přepis ručně zadaného odečtu, odečtu staršího než 45 dní nebo odečtu za měsíc, o kterém už obyvatelé dostali upozornění. Správcům přijde e-mail a v administraci je najdete v části <em>Odečty ke schválení</em> s tlačítky <em>Schválit</em> a <em>Zamítnout</em>.</li>
  <li>Každý přepsaný odečet si web pamatuje i s původní hodnotou (<em>Přepsané odečty</em>).</li>
</ul>
<?= help_fig('api-2-schvaleni.jpg', 'Odečet se stavem nižším než předchozí čeká na schválení správcem.') ?>

<h2>Když data nedorazí</h2>
<p>Od 3. dne v měsíci web porovná, která měřidla posílala data předchozí měsíc, a když některé za minulý měsíc mlčí, pošle správcům e-mail (jednou za měsíc). V části <strong>Když data nedorazí</strong> vidíte seznam chybějících měřidel a můžete:</p>
<ul>
  <li>vyplnit <strong>e-mail odečtové firmy</strong> a tlačítkem <em>Požádat odečtovou firmu o doplnění</em> jí poslat seznam chybějících měřidel (nebo zaškrtnout, ať to web udělá sám);</li>
  <li>dočasně povolit obyvatelům zadat stav ručně i u měřidel odečítaných na dálku (náhradní odečet).</li>
</ul>
<p>Obyvatel, kterému data chybí, vidí na stránce Odečty, že zatím nedorazila a výbor je řeší. Až data přijdou, dostane upozornění dodatečně.</p>
<?= help_fig('api-3-chybi.jpg', 'Chybějící měřidlo za minulý měsíc a žádost odečtové firmě.') ?>

<h2>Pro odečtovou firmu: jak posílat data</h2>
<ul>
  <li>Požadavek <code>POST</code> na adresu od správce (<code>https://…/api/odecty</code>) s klíčem v hlavičce <code>Authorization: Bearer {klíč}</code>, případně <code>X-Api-Key: {klíč}</code>.</li>
  <li>Starší způsob s klíčem přímo v adrese (<code>…/api/odecty/{klíč}</code>) zatím funguje, ale klíč se pak může dostat do záznamů serverů – doporučujeme hlavičku.</li>
  <li>Tělo jako <strong>JSON</strong> (<code>Content-Type: application/json</code>) nebo <strong>CSV</strong> (<code>Content-Type: text/csv</code>, středník nebo čárka, první řádek záhlaví).</li>
  <li>Posílat lze kdykoli a opakovaně; stejný odečet (měřidlo + datum) se nezdvojí, jen přepíše (s výjimkami výše, které čekají na schválení – v odpovědi je jejich počet v <code>held</code>).</li>
</ul>
<table class="table">
  <thead><tr><th>Pole JSON</th><th>Sloupec CSV</th><th>Význam</th></tr></thead>
  <tbody>
    <tr><td><code>unit</code></td><td>jednotka</td><td>Označení jednotky na webu, např. <code>A/2</code>. Lze vynechat, pokud web zná číslo měřidla z dřívějška.</td></tr>
    <tr><td><code>meter_no</code></td><td>číslo měřidla</td><td>Výrobní číslo měřidla (doporučeno).</td></tr>
    <tr><td><code>kind</code></td><td>druh</td><td><?= e(implode(', ', array_map(fn($k, $l) => "$k = $l", array_keys(READING_KINDS), READING_KINDS))) ?>. V CSV stačí i slovy („teplá voda“).</td></tr>
    <tr><td><code>amount</code></td><td>stav</td><td>Stav měřidla (desetinná tečka v JSON, v CSV i čárka).</td></tr>
    <tr><td><code>date</code></td><td>datum odečtu</td><td><code>RRRR-MM-DD</code> (v CSV i <code>DD.MM.RRRR</code>).</td></tr>
  </tbody>
</table>

<?php if ($ex): ?>
<h2>Ověřené ukázky</h2>
<p>Tyto požadavky a odpovědi jsme skutečně poslali na zkušební web při ověření návodu.</p>
<h3>JSON – jeden řádek v pořádku, jeden s chybou</h3>
<pre class="code-sample"><?= e($ex['jsonRequest']) ?></pre>
<p>Odpověď <code>200</code>:</p>
<pre class="code-sample"><?= e($ex['jsonResponse']) ?></pre>
<p>Řádky s chybou se neuloží a vrátí se v <code>errors</code> s číslem řádku; ostatní se uloží.</p>
<h3>CSV</h3>
<pre class="code-sample"><?= e($ex['csvRequest']) ?></pre>
<p>Odpověď <code>200</code> (stejné měřidlo a den – stav se přepsal):</p>
<pre class="code-sample"><?= e($ex['csvResponse']) ?></pre>
<?php if (isset($ex['heldResponse'])): ?><h3>Podezřelý řádek (stav nižší než předchozí)</h3>
<p>Odpověď <code>200</code> – řádek se neuložil a čeká na schválení správcem:</p>
<pre class="code-sample"><?= e($ex['heldResponse']) ?></pre><?php endif ?>
<h3>Neplatný klíč nebo vypnutý příjem</h3>
<p>Odpověď <code><?= (int) $ex['badStatus'] ?></code>:</p>
<pre class="code-sample"><?= e($ex['badResponse']) ?></pre>
<?php endif ?>

<h2>Co se stane s daty</h2>
<ul>
  <li>Obyvatel uvidí na stránce <strong>Odečty</strong> stavy svých měřidel se zdrojem „dálkový odečet“ a u každého měřidla graf spotřeby po měsících se součtem za období (od druhého měsíce s daty, spotřeba je rozdíl stavů na konci měsíců).</li>
  <li>U měřidel odečítaných na dálku obyvatelé stav ručně nezadávají (výjimkou je dočasně povolený náhradní odečet při výpadku dat).</li>
  <li>Web může obyvatele každý měsíc upozornit e-mailem na novou informaci o spotřebě (zákon č. 67/2013 Sb., § 8a). Automatiku zapnete přímo v rámečku <strong>Měsíční informace obyvatelům</strong>; rámeček ukazuje, kdy a za jaký měsíc odejde další upozornění.</li>
</ul>
