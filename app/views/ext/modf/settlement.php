<?php /* Detail vyúčtování: soubory jednotek, ruční přiřazení, zveřejnění, stav po jednotkách, námitky. $s, $ov, $missing, $units, $errors. */
$today = date('Y-m-d');
$published = $s['published_at'] !== '';
$dl = modf_delivery_deadline($s['period_to']);
$unmatched = array_values(array_filter($ov['rows'], fn($r) => $r['state'] === 'unmatched'));
$matched = array_values(array_filter($ov['rows'], fn($r) => $r['state'] !== 'unmatched'));
$objections = [];
foreach ($ov['rows'] as $r) {
    foreach ($r['objections'] as $o) {
        $objections[] = $o + ['unit_label' => $r['unit_label'], 'item' => $r];
    }
}
$act = fn(string $a) => csrf_field() . '<input type="hidden" name="action" value="' . $a . '">'; ?>
<div class="wrap page">
  <p class="crumbs"><a href="<?= url('/admin/vyuctovani') ?>">← Vyúčtování služeb</a></p>
  <header class="page-head"><h1><?= e($s['title']) ?></h1>
    <p class="lead">Zúčtovací období <?= cz_date($s['period_from']) ?> – <?= cz_date($s['period_to']) ?>.
      <?php if ($published): ?>Doručeno <strong><?= cz_date($s['delivery_date']) ?></strong>; námitky lze podat do <strong><?= cz_date(modf_objection_deadline($s['delivery_date'])) ?></strong>.
      <?php else: ?>Zákonná lhůta pro doručení: <strong<?= $dl < $today ? ' class="is-neg"' : '' ?>><?= cz_date($dl) ?></strong><?= $dl < $today ? ' – už uplynula' : '' ?>.<?php endif ?></p>
    <?= function_exists('help_link') ? help_link('vyuctovani-sluzeb') : '' ?></header>
  <?php require __DIR__ . '/../../_errors.php'; ?>

  <div class="dash-tiles dash-tiles-4">
    <div class="dash-tile"><span class="dash-num"><?= count($matched) ?></span><span class="dash-label">jednotek s vyúčtováním</span><span class="dash-sub"><?= count($missing) ? count($missing) . ' jednotek bez souboru' : 'všechny jednotky' ?></span></div>
    <div class="dash-tile"><span class="dash-num"><?= $ov['sum']['confirmed'] + $ov['sum']['resolved'] + $ov['sum']['objection'] ?></span><span class="dash-label">potvrdilo převzetí nebo podalo námitku</span><span class="dash-sub"><?= $ov['sum']['delivered'] ?> zatím nepotvrdilo</span></div>
    <div class="dash-tile<?= $ov['sum']['objection'] ? ' is-bad' : '' ?>"><span class="dash-num"><?= $ov['sum']['objection'] ?></span><span class="dash-label">námitek k vyřízení</span><span class="dash-sub"><?= $ov['sum']['resolved'] ?> vyřízeno</span></div>
    <div class="dash-tile<?= $unmatched ? ' is-bad' : '' ?>"><span class="dash-num"><?= count($unmatched) ?></span><span class="dash-label">souborů bez jednotky</span><span class="dash-sub">přiřaďte je ručně níže</span></div>
  </div>

  <div class="two-col">
    <form method="post" enctype="multipart/form-data" class="card form">
      <h2>Nahrát soubory jednotek</h2>
      <?= $act('upload') ?>
      <label>Soubory PDF <input type="file" name="files[]" multiple required accept=".pdf,application/pdf"></label>
      <p class="hint">Jednotka se pozná z názvu souboru, např. <code>A-5.pdf</code>, <code><?= e(unit_prefix()) ?>B10.pdf</code> nebo <code>vyuctovani_A_3.pdf</code>. Ostatní soubory přiřadíte ručně. Najednou lze nahrát nejvýše <?= e(ini_get('max_file_uploads')) ?> souborů (celkem <?= e(ini_get('post_max_size')) ?>).<?= $published ? ' Vyúčtování je zveřejněné – nově přiřazené soubory se vlastníkům doručí hned (dnešním dnem).' : '' ?></p>
      <div class="actions"><button class="btn" type="submit">Nahrát</button></div>
    </form>
    <?php if (!$published): ?>
    <form method="post" class="card form" data-confirm="Zveřejnit vyúčtování? Vlastníci dostanou e-mail a od dne doručení běží lhůta 30 dnů pro námitky. Soubory pak už nepůjde smazat.">
      <h2>Zveřejnit a doručit</h2>
      <?= $act('publish') ?>
      <label>Den doručení <input type="date" name="delivery_date" value="<?= e($s['delivery_date'] !== '' ? $s['delivery_date'] : $today) ?>" max="<?= $today ?>" required></label>
      <p class="hint">Obvykle dnešní den. Pokud jste vyúčtování vlastníkům předali i jinak (osobně, poštou), zadejte den toho předání – od něj se počítá lhůta pro námitky.</p>
      <?php if ($unmatched): ?><p class="hint is-neg"><?= count($unmatched) ?> souborů nemá jednotku – vlastníci je neuvidí, dokud je nepřiřadíte.</p><?php endif ?>
      <div class="actions"><button class="btn btn-accent" type="submit"<?= $matched ? '' : ' disabled' ?>>Zveřejnit <?= count($matched) ?> vyúčtování</button></div>
    </form>
    <?php endif ?>
  </div>

  <?php if ($unmatched): ?>
  <section class="card" aria-labelledby="modf-un-h">
    <h2 id="modf-un-h">Soubory bez jednotky</h2>
    <p class="hint">Z názvu souboru se jednotka nepoznala. Otevřete soubor, vyberte jednotku a uložte.</p>
    <table class="table"><thead><tr><th>Soubor</th><th>Jednotka</th><th></th></tr></thead><tbody>
      <?php foreach ($unmatched as $r): ?><tr>
        <td data-label="Soubor"><a href="<?= url('/vyuctovani/soubor/' . $r['id']) ?>" target="_blank" rel="noopener"><?= e($r['original_name']) ?></a> <small class="muted"><?= file_size((int) $r['size']) ?></small></td>
        <td data-label="Jednotka"><form method="post" class="inline-form"><?= $act('assign') ?><input type="hidden" name="item_id" value="<?= (int) $r['id'] ?>">
          <select name="unit_id" required aria-label="Jednotka pro soubor <?= e($r['original_name']) ?>"><option value="">– vyberte –</option><?php foreach ($units as $un): ?><option value="<?= (int) $un['id'] ?>"><?= e(unit_name($un)) ?></option><?php endforeach ?></select>
          <button class="btn btn-small" type="submit">Přiřadit</button></form></td>
        <td class="t-actions"><form method="post" data-confirm="Smazat soubor <?= e($r['original_name']) ?>?"><?= $act('delete_item') ?><input type="hidden" name="item_id" value="<?= (int) $r['id'] ?>"><button class="link-danger" type="submit">Smazat</button></form></td>
      </tr><?php endforeach ?>
    </tbody></table>
  </section>
  <?php endif ?>

  <section class="card" aria-labelledby="modf-units-h">
    <h2 id="modf-units-h">Stav po jednotkách</h2>
    <?php if (!$matched): ?><p class="empty">Zatím žádný soubor přiřazený k jednotce.</p><?php else: ?>
    <table class="table modf-units"><thead><tr><th>Jednotka</th><th>Stav</th><th>Doručeno</th><th>Potvrzeno</th><th>Námitky do</th><th>Soubor</th><th></th></tr></thead><tbody>
      <?php foreach ($matched as $r): [$sl, $sc] = MODF_ITEM_STATES[$r['state']]; ?><tr id="j<?= (int) $r['id'] ?>">
        <td data-label="Jednotka"><strong><?= e($r['unit_label']) ?></strong><?= !$r['owners'] ? ' <span class="tag tag-late" title="Jednotka nemá na webu vlastníka s účtem – vyúčtování mu doručte jinak.">bez účtu vlastníka</span>' : '' ?></td>
        <td data-label="Stav"><span class="status <?= $sc ?>"><?= e($sl) ?></span><?php if ($r['answer_until'] !== ''): ?> <small class="<?= $r['answer_late'] ? 'is-neg' : 'muted' ?>">vyřídit do <?= cz_date($r['answer_until']) ?></small><?php endif ?></td>
        <td data-label="Doručeno"><?= $r['delivered_on'] !== '' ? cz_date($r['delivered_on']) : '–' ?></td>
        <td data-label="Potvrzeno"><?= $r['confirmed_at'] !== '' ? e(cz_datetime($r['confirmed_at'])) . '<br><small class="muted">' . e($r['confirmed_name']) . '</small>' : '–' ?></td>
        <td data-label="Námitky do"><?= $r['objection_until'] !== '' ? '<span class="' . ($r['objection_until'] < $today ? 'muted' : '') . '">' . cz_date($r['objection_until']) . '</span>' : '–' ?></td>
        <td data-label="Soubor"><a href="<?= url('/vyuctovani/soubor/' . $r['id']) ?>" target="_blank" rel="noopener"><?= e(mb_strimwidth($r['original_name'], 0, 40, '…')) ?></a></td>
        <td class="t-actions"><?php if ($r['delivered_on'] === ''): ?><form method="post" data-confirm="Smazat soubor <?= e($r['original_name']) ?>?"><?= $act('delete_item') ?><input type="hidden" name="item_id" value="<?= (int) $r['id'] ?>"><button class="link-danger" type="submit">Smazat</button></form><?php endif ?></td>
      </tr><?php endforeach ?>
    </tbody></table>
    <?php endif ?>
    <?php if ($missing): ?><p class="hint">Bez souboru: <?= e(implode(', ', array_map('unit_name', $missing))) ?>.</p><?php endif ?>
  </section>

  <?php if ($objections): ?>
  <section class="card" aria-labelledby="modf-obj-h">
    <h2 id="modf-obj-h">Námitky</h2>
    <?php foreach ($objections as $o): $odl = modf_answer_deadline(substr($o['created_at'], 0, 10)); ?>
      <article class="modf-objection" id="n<?= (int) $o['id'] ?>">
        <p><strong><?= e($o['unit_label']) ?> · <?= e($o['user_name']) ?></strong> <span class="muted"><?= e(cz_datetime($o['created_at'])) ?></span>
          <?php if ($o['answered_at'] === ''): ?><span class="tag tag-late">vyřídit do <?= cz_date($odl) ?><?= $odl < $today ? ' – po lhůtě' : '' ?></span><?php else: ?><span class="tag tag-ok">vyřízeno <?= cz_date(substr($o['answered_at'], 0, 10)) ?></span><?php endif ?></p>
        <div class="prose"><?= format_text($o['text']) ?></div>
        <?php if ($o['filename'] !== ''): ?><p><a href="<?= url('/vyuctovani/namitka/' . $o['id'] . '/priloha') ?>" target="_blank" rel="noopener">Příloha: <?= e($o['original_name']) ?></a></p><?php endif ?>
        <?php if ($o['answered_at'] !== ''): ?>
          <div class="modf-answer"><p class="h-small">Odpověď (<?= e($o['answered_by']) ?>, <?= e(cz_datetime($o['answered_at'])) ?>)</p><div class="prose"><?= format_text($o['answer']) ?></div></div>
        <?php else: ?>
          <form method="post" class="form"><?= $act('answer') ?><input type="hidden" name="objection_id" value="<?= (int) $o['id'] ?>">
            <label>Jak byla námitka vyřízena <textarea name="answer" rows="4" required minlength="5" maxlength="5000" placeholder="Např. Námitce vyhovujeme, opravené vyúčtování nahrajeme do … / Námitku zamítáme, protože …"></textarea></label>
            <div class="actions"><button class="btn btn-small" type="submit">Odeslat odpověď a uzavřít</button></div></form>
        <?php endif ?>
      </article>
    <?php endforeach ?>
  </section>
  <?php endif ?>

  <details class="card">
    <summary>Údaje vyúčtování<?= $published ? '' : ' a smazání' ?></summary>
    <form method="post" class="form"><?= $act('save') ?>
      <label>Název <input type="text" name="title" value="<?= e($s['title']) ?>" required maxlength="191"></label>
      <?php if (!$published): ?><div class="form-row">
        <label>Zúčtovací období od <input type="date" name="period_from" value="<?= e($s['period_from']) ?>" required></label>
        <label>do <input type="date" name="period_to" value="<?= e($s['period_to']) ?>" required></label>
      </div><?php else: ?><p class="hint">Po zveřejnění nelze měnit období ani den doručení – podle nich běží zákonné lhůty.</p><?php endif ?>
      <label>Poznámka pro vlastníky <textarea name="note" rows="2" maxlength="2000"><?= e($s['note']) ?></textarea></label>
      <div class="actions"><button class="btn btn-small" type="submit">Uložit</button></div>
    </form>
    <?php if (!$published): ?><form method="post" data-confirm="Smazat celé vyúčtování včetně nahraných souborů?"><?= $act('delete') ?><button class="link-danger" type="submit">Smazat vyúčtování</button></form><?php endif ?>
  </details>
</div>
