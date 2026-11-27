<?php /* Dálkové odečty: $remote, $prev, $withData, $units, $bySource, $notices, $plan, $missing, $meterMissing, $pending, $apiUrl, $apiBase, $apiToken, $apiLast, $apiFails, $apiLog, $history, $preview */
$prevName = month_label($prev); ?>
<div class="wrap page narrow">
  <p><a href="<?= url('/admin/odecty') ?>">← Odečty měřidel</a></p>
  <header class="page-head"><h1>Dálkové odečty</h1>
    <p class="lead">Od 1. 1. 2027 musí být měřidla tepla, teplé vody a indikátory topných nákladů odečitatelná na dálku a obyvatelé mají každý měsíc dostat informaci o své spotřebě. Data Vám dodá odečtová firma (soubor nebo automaticky) – web je ukáže obyvatelům a upozorní je.</p></header>

  <section class="card">
    <h2>Stav za <?= e($prevName) ?></h2>
    <p>Odečty za minulý měsíc má <strong><?= $withData ?> z <?= $units ?></strong> jednotek.<?= $units && $withData < $units ? ' U ostatních data chybí – zkontrolujte s odečtovou firmou, že posílá všechna měřidla.' : '' ?></p>
    <?php if ($missing && $withData): ?><details><summary>Jednotky bez dat za <?= e($prevName) ?> (<?= count($missing) ?>)</summary><p><?= e(implode(', ', $missing)) ?></p></details><?php endif ?>
    <p class="muted">Za posledních 60 dní: <?= (int) ($bySource['api'] ?? 0) ?> odečtů přišlo automaticky, <?= (int) ($bySource['import'] ?? 0) ?> z nahraného souboru, <?= (int) ($bySource['manual'] ?? 0) ?> zadaných ručně.</p>
  </section>

  <?php $un = unit_options(); $num = fn($v) => str_replace('.', ',', (string) (float) $v); ?>
  <?php if ($pending): ?>
  <section class="card" id="ke-schvaleni">
    <h2>Odečty ke schválení (<?= count($pending) ?>)</h2>
    <p>Automatický příjem přinesl řádky, které vypadají podezřele. Neuložili jsme je – rozhodněte, jestli jsou v pořádku. Zamítnutý se neuloží; když je chyba u odečtové firmy, požádejte ji o opravu.</p>
    <table class="table"><thead><tr><th>Jednotka</th><th>Měřidlo</th><th>Stav</th><th>Datum</th><th>Proč</th><th></th></tr></thead><tbody>
    <?php foreach ($pending as $p): ?><tr><td data-label="Jednotka"><?= e($un[$p['unit_id']] ?? '') ?></td><td data-label="Měřidlo"><?= e(READING_KINDS[$p['kind']] ?? $p['kind']) ?><?= $p['meter_no'] !== '' ? ' <small class="muted">č. ' . e($p['meter_no']) . '</small>' : '' ?></td>
      <td data-label="Stav"><strong><?= e($num($p['amount'])) ?></strong></td><td data-label="Datum"><?= e(cz_date($p['read_date'])) ?></td><td data-label="Proč"><?= e($p['reason']) ?></td>
      <td data-label=""><form method="post" action="<?= url('/admin/odecty/dalkove') ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="do" value="pending"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
        <button class="btn btn-small" name="verdict" value="approve" type="submit">Schválit</button> <button class="btn btn-small btn-ghost" name="verdict" value="reject" type="submit">Zamítnout</button></form></td></tr><?php endforeach ?>
    </tbody></table>
  </section>
  <?php endif ?>

  <section class="card" id="chybi">
    <h2>Když data nedorazí</h2>
    <?php if ($meterMissing): ?>
      <p class="callout"><strong>Za <?= e($prevName) ?> chybí data</strong> z měřidel, která předchozí měsíc data posílala (počet: <?= count($meterMissing) ?>):</p>
      <ul><?php foreach (array_slice($meterMissing, 0, 30) as [$uid, $k, $mno]): ?><li><?= e($un[$uid] ?? '') ?> – <?= e(READING_KINDS[$k] ?? $k) ?><?= $mno !== '' ? ' <small class="muted">č. ' . e($mno) . '</small>' : '' ?></li><?php endforeach ?><?= count($meterMissing) > 30 ? '<li>… a další</li>' : '' ?></ul>
      <?php if (setting('meter_missing_asked_' . $prev) !== ''): ?><p class="muted">Odečtovou firmu jste požádali <?= e(cz_datetime(setting('meter_missing_asked_' . $prev))) ?>.</p><?php endif ?>
      <?php if (filter_var(setting('meter_firm_email'), FILTER_VALIDATE_EMAIL)): ?>
      <form method="post" action="<?= url('/admin/odecty/dalkove') ?>" class="inline-form"><?= csrf_field() ?><button class="btn" name="do" value="ask-firm" type="submit">Požádat odečtovou firmu o doplnění</button></form>
      <?php endif ?>
    <?php else: ?>
      <p>Za <?= e($prevName) ?> nechybí data z žádného měřidla, které posílalo data předchozí měsíc.</p>
    <?php endif ?>
    <p class="hint">Web to hlídá sám: od <?= METER_MISSING_DAY ?>. dne v měsíci porovná, která měřidla posílala data minulý měsíc, a když některá mlčí, pošle správcům e-mail (jednou za měsíc). Až data přijdou, obyvatelé dostanou upozornění dodatečně.</p>
    <form method="post" action="<?= url('/admin/odecty/dalkove') ?>" class="form"><?= csrf_field() ?><input type="hidden" name="do" value="firm">
      <label>E-mail odečtové firmy <input type="email" name="firm_email" value="<?= e(setting('meter_firm_email')) ?>" placeholder="odecty@firma.cz"></label>
      <label class="check"><input type="checkbox" name="firm_auto"<?= setting('meter_firm_auto') === '1' ? ' checked' : '' ?>> <span>Když data chybí, požádat firmu o doplnění automaticky <small>E-mail se seznamem chybějících měřidel, jednou za měsíc; odpověď půjde správcům.</small></span></label>
      <label class="check"><input type="checkbox" name="fallback"<?= meter_manual_fallback() ? ' checked' : '' ?>> <span>Dočasně povolit obyvatelům zadat stav i u měřidel odečítaných na dálku <small>Náhradní odečet při výpadku. Pozdější data od firmy ručně zadaný stav nepřepíšou bez Vašeho schválení.</small></span></label>
      <button class="btn btn-small" type="submit">Uložit</button></form>
  </section>

  <form method="post" action="<?= url('/admin/odecty/dalkove') ?>" class="card form" id="nastaveni">
    <?= csrf_field() ?><input type="hidden" name="do" value="kinds">
    <h2>Která měřidla se odečítají na dálku</h2>
    <p class="hint">U těchto měřidel obyvatelé stav nezadávají – přijde od odečtové firmy. Podle zákona jde o teplou vodu, teplo a indikátory; studenou vodu, plyn a elektřinu můžete dál sbírat ručně, nebo je přidat, pokud je máte také na dálku.</p>
    <fieldset class="checks"><?php foreach (READING_KINDS as $k => $label): ?><label class="check"><input type="checkbox" name="kinds[]" value="<?= $k ?>"<?= in_array($k, $remote, true) ? ' checked' : '' ?>> <?= e($label) ?></label><?php endforeach ?></fieldset>
    <label class="check"><input type="checkbox" name="compare"<?= meter_compare_on() ? ' checked' : '' ?>> <span>Ukazovat obyvatelům srovnání s průměrem domu <small>Spotřeba na m² proti průměru domu – jen průměr, nikdy jednotlivé byty, a jen když má data aspoň <?= METER_COMPARE_MIN_UNITS ?> jednotek s vyplněnou plochou. Indikátory topení se mezi byty nesrovnávají.</small></span></label>
    <button class="btn" type="submit">Uložit</button>
  </form>

  <section class="card" id="import">
    <h2>Nahrát data od odečtové firmy</h2>
    <p>Soubor CSV (z Excelu „Uložit jako CSV“). Sloupce web pozná podle záhlaví: <strong>jednotka</strong> nebo <strong>číslo měřidla</strong>, <strong>druh</strong> (teplá voda, studená voda, teplo, indikátor…), <strong>stav</strong> a <strong>datum odečtu</strong>. Opakované nahrání stejných dat nic nezdvojí.</p>
    <?php if ($preview): ?>
      <div class="flash flash-<?= $preview['bad'] ? 'info' : 'ok' ?>">V souboru je <?= $preview['total'] ?> řádků: <strong><?= $preview['ok'] ?> v pořádku</strong><?= $preview['bad'] ? ', ' . count($preview['bad']) . (count($preview['bad']) >= 50 ? ' a více' : '') . ' s chybou (neuloží se)' : '' ?>.</div>
      <?php if ($preview['sample']): ?><table class="table"><thead><tr><th>Jednotka</th><th>Měřidlo</th><th>Stav</th><th>Datum</th></tr></thead><tbody>
        <?php $un = unit_options(); foreach ($preview['sample'] as [, $d]): ?><tr><td data-label="Jednotka"><?= e($un[$d['unit_id']] ?? '') ?></td><td data-label="Měřidlo"><?= e(READING_KINDS[$d['kind']]) ?><?= $d['meter_no'] !== '' ? ' <small class="muted">č. ' . e($d['meter_no']) . '</small>' : '' ?></td><td data-label="Stav"><?= e(str_replace('.', ',', (string) $d['amount'])) ?></td><td data-label="Datum"><?= cz_date($d['read_date']) ?></td></tr><?php endforeach ?>
      </tbody></table><?php endif ?>
      <?php if ($preview['bad']): ?><details><summary>Řádky s chybou</summary><ul><?php foreach ($preview['bad'] as [$no, , $err]): ?><li>Řádek <?= $no + 1 ?>: <?= e($err) ?></li><?php endforeach ?></ul></details><?php endif ?>
      <?php if ($preview['ok']): ?><form method="post" action="<?= url('/admin/odecty/dalkove') ?>"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="data" value="<?= e($preview['data']) ?>"><button class="btn" type="submit">Uložit <?= $preview['ok'] ?> odečtů</button></form><?php endif ?>
    <?php else: ?>
      <form method="post" action="<?= url('/admin/odecty/dalkove') ?>" enctype="multipart/form-data" class="form"><?= csrf_field() ?><input type="hidden" name="do" value="preview">
        <label>Soubor CSV <input type="file" name="file" accept=".csv,.txt" required></label>
        <button class="btn" type="submit">Zkontrolovat soubor</button></form>
    <?php endif ?>
  </section>

  <section class="card" id="prijem">
    <h2>Automatický příjem dat</h2>
    <p>Odečtová firma nebo sběrnice v domě může data posílat na web sama – třeba každý den. Přijímá stejné CSV jako výše, nebo JSON.</p>
    <p><?= help_link('odecty-api', 'Návod a technický popis') ?> · odkaz pro odečtovou firmu (bez přihlášení): <code><?= e(absolute_url('/napoveda/odecty-api')) ?></code></p>
    <?php if ($apiUrl): ?>
      <p><strong>Adresa:</strong> <code><?= e($apiBase) ?></code></p>
      <p><strong>Klíč</strong> (předejte ho firmě bezpečně, ne ve stejném e-mailu jako adresu – třeba telefonicky nebo SMS):</p>
      <p><input type="text" class="join-url" value="<?= e($apiToken) ?>" readonly data-select-all aria-label="Klíč pro příjem dat"></p>
      <details><summary>Technický popis pro odečtovou firmu</summary>
        <p>Požadavek <code>POST</code> na adresu výše s hlavičkou <code>Authorization: Bearer &lt;klíč&gt;</code> (nebo <code>X-Api-Key: &lt;klíč&gt;</code>) a tělem CSV (<code>Content-Type: text/csv</code>) nebo JSON (<code>application/json</code>):</p>
        <pre class="code-sample">[{"unit": "A/2", "meter_no": "TV-123456", "kind": "tv", "amount": 41.27, "date": "2027-01-31"}]</pre>
        <p>Druhy: <?= e(implode(', ', array_map(fn($k, $l) => "$k ($l)", array_keys(READING_KINDS), READING_KINDS))) ?>. Jednotku lze vynechat, pokud web zná číslo měřidla z dřívějších odečtů. Odpověď je JSON s počtem uložených řádků, řádků ke schválení a chybami.</p>
        <p class="muted">Starší způsob s klíčem přímo v adrese (<code><?= e($apiBase) ?>/&lt;klíč&gt;</code>) zatím funguje, ale klíč se pak může dostat do záznamů serverů. Doporučujeme hlavičku.</p></details>
      <p class="meter-plan<?= $apiLast ? ' is-on' : '' ?>"><?php if ($apiLast): ?><strong>Poslední příjem dat:</strong> <?= e(cz_datetime($apiLast['at'])) ?> z adresy <?= e($apiLast['ip']) ?> – nových <?= (int) $apiLast['saved'] ?>, přepsaných <?= (int) $apiLast['updated'] ?><?= $apiLast['held'] ? ', ke schválení ' . (int) $apiLast['held'] : '' ?><?= $apiLast['errors'] ? ', chybných ' . (int) $apiLast['errors'] : '' ?>.<?php else: ?>Zatím nepřišla žádná data.<?php endif ?>
        <?= $apiFails ? '<br><strong>Neúspěšné pokusy za 7 dní: ' . $apiFails . '</strong> (špatný klíč nebo nepovolená adresa). Pokud to nebyla Vaše firma, vytvořte nový klíč.' : '' ?></p>
      <form method="post" action="<?= url('/admin/odecty/dalkove') ?>" class="form"><?= csrf_field() ?><input type="hidden" name="do" value="ips">
        <label>Přijímat jen z IP adres odečtové firmy (nepovinné) <input type="text" name="ips" value="<?= e(setting('meter_api_ips')) ?>" placeholder="203.0.113.10, 198.51.100.0/24"></label>
        <p class="hint">Adresy Vám sdělí odečtová firma. Prázdné = odkudkoli, ale vždy jen se správným klíčem. Po 10 neúspěšných pokusech za hodinu web adresu odesílatele na hodinu zablokuje a pošle Vám e-mail.</p>
        <button class="btn btn-small" type="submit">Uložit adresy</button></form>
      <?php if ($apiLog): ?><details><summary>Posledních <?= count($apiLog) ?> pokusů o příjem dat</summary><table class="table"><thead><tr><th>Kdy</th><th>Odkud</th><th>Výsledek</th></tr></thead><tbody>
        <?php foreach ($apiLog as $l): ?><tr><td data-label="Kdy"><?= e(cz_datetime($l['at'])) ?></td><td data-label="Odkud"><?= e($l['ip']) ?></td><td data-label="Výsledek"><?= $l['ok'] ? 'přijato: nových ' . (int) $l['saved'] . ', přepsaných ' . (int) $l['updated'] . ($l['held'] ? ', ke schválení ' . (int) $l['held'] : '') . ($l['errors'] ? ', chybných ' . (int) $l['errors'] : '') . ($l['auth'] === 'url' ? ' <small class="muted">(klíč v adrese)</small>' : '') : '<strong>odmítnuto</strong> – ' . e($l['note']) ?></td></tr><?php endforeach ?>
      </tbody></table></details><?php endif ?>
      <?php if ($history): ?><details><summary>Přepsané odečty (původní hodnoty)</summary><table class="table"><thead><tr><th>Jednotka</th><th>Měřidlo</th><th>Datum odečtu</th><th>Původně</th><th>Nově</th><th>Kdy</th></tr></thead><tbody>
        <?php foreach ($history as $h): ?><tr><td data-label="Jednotka"><?= e($un[$h['unit_id']] ?? '') ?></td><td data-label="Měřidlo"><?= e(READING_KINDS[$h['kind']] ?? $h['kind']) ?></td><td data-label="Datum odečtu"><?= e(cz_date($h['read_date'])) ?></td><td data-label="Původně"><?= e($num($h['old_amount'])) ?></td><td data-label="Nově"><?= e($num($h['new_amount'])) ?></td><td data-label="Kdy"><?= e(cz_datetime($h['changed_at'])) ?></td></tr><?php endforeach ?>
      </tbody></table></details><?php endif ?>
      <form method="post" action="<?= url('/admin/odecty/dalkove') ?>" class="inline-form" data-confirm="Vytvořit nový klíč? Ten dosavadní přestane fungovat."><?= csrf_field() ?><button class="btn btn-small btn-ghost" name="do" value="token" type="submit">Vytvořit nový klíč</button></form>
      <form method="post" action="<?= url('/admin/odecty/dalkove') ?>" class="inline-form"><?= csrf_field() ?><button class="btn btn-small btn-ghost" name="do" value="token-off" type="submit">Vypnout příjem</button></form>
    <?php else: ?>
      <form method="post" action="<?= url('/admin/odecty/dalkove') ?>"><?= csrf_field() ?><button class="btn" name="do" value="token" type="submit">Zapnout automatický příjem</button></form>
    <?php endif ?>
  </section>

  <section class="card" id="upozorneni">
    <h2>Měsíční informace obyvatelům</h2>
    <p>Obyvatelé vidí spotřebu po měsících na stránce Odečty měřidel (graf, srovnání s loňskem<?= meter_compare_on() ? ' a s průměrem domu' : '' ?>). Zákon (67/2013 Sb., § 8a) žádá, aby o ní každý měsíc prokazatelně věděli – proto jim web pošle e-mail s odkazem a upozornění do telefonu.</p>
    <form method="post" action="<?= url('/admin/odecty/dalkove') ?>" class="form"><?= csrf_field() ?><input type="hidden" name="do" value="auto">
      <label class="check"><input type="checkbox" name="auto"<?= $plan['on'] ? ' checked' : '' ?>> <span>Posílat automaticky každý měsíc <small>Od 5. dne v měsíci za předchozí měsíc, jakmile jsou data. Jednotkám, kterým data přijdou později, odejde upozornění dodatečně.</small></span></label>
      <button class="btn btn-small" type="submit">Uložit</button></form>
    <p class="meter-plan<?= $plan['on'] ? ' is-on' : '' ?>"><?php if (!$plan['on']): ?><strong>Automatika je vypnutá.</strong> Upozornění pošlete ručně tlačítkem níže.
      <?php elseif ($plan['waiting']): ?><strong>Čeká na data za <?= e(month_label($plan['month'])) ?>.</strong> Upozornění odejde samo, jakmile je pošle odečtová firma<?= $withData ? ' (zatím data má ' . $withData . ' z ' . $units . ' jednotek)' : '' ?>.
      <?php else: ?><strong>Zapnuto.</strong> Další upozornění odejde <?= e(cz_date($plan['date'])) ?> za <?= e(month_label($plan['month'])) ?>.<?php endif ?></p>
    <details<?= $plan['on'] ? '' : ' open' ?>><summary>Poslat ručně</summary>
    <form method="post" action="<?= url('/admin/odecty/dalkove') ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="do" value="notice"><input type="hidden" name="mesic" value="<?= e($prev) ?>">
      <button class="btn<?= isset($notices[$prev]) || $plan['on'] ? ' btn-ghost' : '' ?>" type="submit"><?= isset($notices[$prev]) ? 'Poslat znovu' : 'Poslat teď' ?> za <?= e($prevName) ?></button></form>
    </details>
    <?php if ($notices): ?><table class="table"><thead><tr><th>Měsíc</th><th>Odesláno</th><th>Kdy</th></tr></thead><tbody><?php foreach ($notices as $ym => [$when, $n]): ?><tr><td data-label="Měsíc"><?= e(month_label($ym)) ?></td><td data-label="Odesláno"><?= (int) $n ?> e-mailů</td><td data-label="Kdy"><?= e(cz_datetime($when)) ?></td></tr><?php endforeach ?></tbody></table><?php endif ?>
  </section>
  <p class="hint">Údaje o zákonech jsou shrnutí; přesné povinnosti a lhůty si ověřte u odečtové firmy nebo správce domu.</p>
</div>
