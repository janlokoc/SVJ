<?php /* Dálkové odečty: $remote, $prev, $withData, $units, $bySource, $notices, $apiUrl, $preview */
$prevName = month_name((int) substr($prev, 5, 2)) . ' ' . substr($prev, 0, 4); ?>
<div class="wrap page narrow">
  <p><a href="<?= url('/admin/odecty') ?>">← Odečty měřidel</a></p>
  <header class="page-head"><h1>Dálkové odečty</h1>
    <p class="lead">Od 1. 1. 2027 musí být měřidla tepla, teplé vody a indikátory topných nákladů odečitatelná na dálku a obyvatelé mají každý měsíc dostat informaci o své spotřebě. Data Vám dodá odečtová firma (soubor nebo automaticky) – web je ukáže obyvatelům a upozorní je.</p></header>

  <section class="card">
    <h2>Stav za <?= e($prevName) ?></h2>
    <p>Odečty za minulý měsíc má <strong><?= $withData ?> z <?= $units ?></strong> jednotek.<?= $units && $withData < $units ? ' U ostatních data chybí – zkontrolujte s odečtovou firmou, že posílá všechna měřidla.' : '' ?></p>
    <p class="muted">Za posledních 60 dní: <?= (int) ($bySource['api'] ?? 0) ?> odečtů přišlo automaticky, <?= (int) ($bySource['import'] ?? 0) ?> z nahraného souboru, <?= (int) ($bySource['manual'] ?? 0) ?> zadaných ručně.</p>
  </section>

  <form method="post" action="<?= url('/admin/odecty/dalkove') ?>" class="card form" id="nastaveni">
    <?= csrf_field() ?><input type="hidden" name="do" value="kinds">
    <h2>Která měřidla se odečítají na dálku</h2>
    <p class="hint">U těchto měřidel obyvatelé stav nezadávají – přijde od odečtové firmy. Podle zákona jde o teplou vodu, teplo a indikátory; studenou vodu, plyn a elektřinu můžete dál sbírat ručně, nebo je přidat, pokud je máte také na dálku.</p>
    <fieldset class="checks"><?php foreach (READING_KINDS as $k => $label): ?><label class="check"><input type="checkbox" name="kinds[]" value="<?= $k ?>"<?= in_array($k, $remote, true) ? ' checked' : '' ?>> <?= e($label) ?></label><?php endforeach ?></fieldset>
    <label class="check"><input type="checkbox" name="auto"<?= setting('meter_notice_auto') === '1' ? ' checked' : '' ?>> <span>Každý měsíc obyvatele automaticky upozornit e-mailem <small>5. den v měsíci za předchozí měsíc, jen těm, jejichž jednotka má odečty.</small></span></label>
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
    <p>Odečtová firma nebo sběrnice v domě může data posílat na web sama – třeba každý den. Stačí jí předat tuto adresu; přijímá stejné CSV jako výše, nebo JSON.</p>
    <p><?= help_link('odecty-api', 'Návod a technický popis') ?> · odkaz pro odečtovou firmu (bez přihlášení): <code><?= e(absolute_url('/napoveda/odecty-api')) ?></code></p>
    <?php if ($apiUrl): ?>
      <p><input type="text" class="join-url" value="<?= e($apiUrl) ?>" readonly data-select-all aria-label="Adresa pro příjem dat"></p>
      <details><summary>Technický popis pro odečtovou firmu</summary>
        <p>Požadavek <code>POST</code> na adresu výše s tělem CSV (<code>Content-Type: text/csv</code>) nebo JSON (<code>application/json</code>):</p>
        <pre class="code-sample">[{"unit": "A/2", "meter_no": "TV-123456", "kind": "tv", "amount": 41.27, "date": "2027-01-31"}]</pre>
        <p>Druhy: <?= e(implode(', ', array_map(fn($k, $l) => "$k ($l)", array_keys(READING_KINDS), READING_KINDS))) ?>. Jednotku lze vynechat, pokud web zná číslo měřidla z dřívějších odečtů. Odpověď je JSON s počtem uložených řádků a chybami.</p></details>
      <form method="post" action="<?= url('/admin/odecty/dalkove') ?>" class="inline-form" data-confirm="Vytvořit novou adresu? Ta dosavadní přestane fungovat."><?= csrf_field() ?><button class="btn btn-small btn-ghost" name="do" value="token" type="submit">Vytvořit novou adresu</button></form>
      <form method="post" action="<?= url('/admin/odecty/dalkove') ?>" class="inline-form"><?= csrf_field() ?><button class="btn btn-small btn-ghost" name="do" value="token-off" type="submit">Vypnout příjem</button></form>
    <?php else: ?>
      <form method="post" action="<?= url('/admin/odecty/dalkove') ?>"><?= csrf_field() ?><button class="btn" name="do" value="token" type="submit">Zapnout automatický příjem</button></form>
    <?php endif ?>
  </section>

  <section class="card" id="upozorneni">
    <h2>Měsíční informace obyvatelům</h2>
    <p>Obyvatelé vidí spotřebu po měsících na stránce Odečty měřidel. Zákon (67/2013 Sb., § 8a) žádá, aby o ní každý měsíc prokazatelně věděli – proto jim web pošle e-mail s odkazem.</p>
    <form method="post" action="<?= url('/admin/odecty/dalkove') ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="do" value="notice"><input type="hidden" name="mesic" value="<?= e($prev) ?>">
      <button class="btn<?= isset($notices[$prev]) ? ' btn-ghost' : '' ?>" type="submit"><?= isset($notices[$prev]) ? 'Poslat znovu' : 'Poslat upozornění' ?> za <?= e($prevName) ?></button></form>
    <?php if ($notices): ?><table class="table"><thead><tr><th>Měsíc</th><th>Odesláno</th><th>Kdy</th></tr></thead><tbody><?php foreach ($notices as $ym => [$when, $n]): ?><tr><td data-label="Měsíc"><?= e(month_name((int) substr($ym, 5, 2)) . ' ' . substr($ym, 0, 4)) ?></td><td data-label="Odesláno"><?= (int) $n ?> e-mailů</td><td data-label="Kdy"><?= e(cz_datetime($when)) ?></td></tr><?php endforeach ?></tbody></table><?php endif ?>
  </section>
  <p class="hint">Údaje o zákonech jsou shrnutí; přesné povinnosti a lhůty si ověřte u odečtové firmy nebo správce domu.</p>
</div>
