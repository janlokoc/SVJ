<?php $ex = is_file($f = __DIR__ . '/../../assets/help/api-ukazka.json') ? json_decode((string) file_get_contents($f), true) : null; ?>
<p>Tuto stránku můžete poslat odečtové firmě nebo technikovi, který spravuje dálkové odečty v domě. Popisuje, jak posílat stavy měřidel na web společenství automaticky – bez ručního nahrávání souborů.</p>

<h2>Pro správce: zapnutí příjmu</h2>
<p>V administraci <strong>Odečty → Dálkové odečty a import → Automatický příjem dat</strong> klikněte na <em>Zapnout automatický příjem</em>. Web vytvoří tajnou adresu – tu pošlete odečtové firmě (jen jí, kdo adresu zná, může posílat data). Adresu můžete kdykoli vyměnit nebo příjem vypnout; stará adresa pak přestane fungovat.</p>
<?= help_fig('api-1-adresa.jpg', 'Adresa pro příjem dat (na snímku je tajná část skrytá).') ?>

<h2>Pro odečtovou firmu: jak posílat data</h2>
<ul>
  <li>Požadavek <code>POST</code> na adresu od správce, tvaru <code>https://…/api/odecty/{klíč}</code>.</li>
  <li>Tělo jako <strong>JSON</strong> (<code>Content-Type: application/json</code>) nebo <strong>CSV</strong> (<code>Content-Type: text/csv</code>, středník nebo čárka, první řádek záhlaví).</li>
  <li>Posílat lze kdykoli a opakovaně; stejný odečet (měřidlo + datum) se nezdvojí, jen přepíše.</li>
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
<h3>Neplatná nebo vypnutá adresa</h3>
<p>Odpověď <code><?= (int) $ex['badStatus'] ?></code>:</p>
<pre class="code-sample"><?= e($ex['badResponse']) ?></pre>
<?php endif ?>

<h2>Co se stane s daty</h2>
<ul>
  <li>Obyvatel uvidí na stránce <strong>Odečty</strong> stavy svých měřidel se zdrojem „dálkový odečet“ a spotřebu po měsících.</li>
  <li>U měřidel odečítaných na dálku obyvatelé stav ručně nezadávají.</li>
  <li>Web může obyvatele každý měsíc upozornit e-mailem na novou informaci o spotřebě (zákon č. 67/2013 Sb., § 8a).</li>
</ul>
