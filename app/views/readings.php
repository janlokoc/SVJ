<div class="wrap page narrow">
  <header class="page-head"><h1>Odečty měřidel</h1><p class="lead"><?= $remote ? 'Spotřeba Vaší jednotky po měsících' . ($manualKinds ? ' a hlášení stavu měřidel, která se odečítají ručně.' : '.') : 'Nahlaste výboru stav vodoměru nebo jiného měřidla ve své jednotce.' ?></p></header>
  <?php require __DIR__ . '/_errors.php'; ?>
  <?php if (!$units): ?><p class="note">K Vašemu účtu není přiřazena žádná jednotka, proto odečet zadat nejde. Obraťte se na výbor.</p><?php else: ?>
  <?php if ($remote): ?>
  <section class="card meter-monthly">
    <h2>Spotřeba po měsících</h2>
    <p class="hint"><?= e(implode(', ', array_map(fn($k) => READING_KINDS[$k], $remote))) ?> se odečítá na dálku. Spotřeba za měsíc je rozdíl stavů na konci měsíce a na konci předchozího měsíce.</p>
    <?php if (!$monthly): ?><p class="empty">Zatím tu nejsou data za celý měsíc. Objeví se, jakmile je pošle odečtová firma.</p><?php else: ?>
    <?php foreach ($overview ?? [] as $m): $ul = meter_unit_label($m['kind']); $f = fn(float $v) => str_replace('.', ',', (string) round($v, $v >= 10 ? 1 : 2));
      $yoy = $m['yearAgo'] !== null ? meter_delta($m['use'], $m['yearAgo']) : null;
      $houseLast = $m['house'][$m['last']] ?? null; $vsHouse = $houseLast !== null && $m['perM2'] !== null ? meter_delta($m['perM2'], $houseLast) : null; ?>
    <div class="meter-box">
      <h3><?= e(READING_KINDS[$m['kind']] ?? $m['kind']) ?><?= count($units) > 1 ? ' · ' . e($units[$m['unit']] ?? '') : '' ?></h3>
      <?php $yoyText = $yoy === null ? '' : ($yoy === 0 ? 'stejně jako loni' : ($yoy > 0 ? 'o ' . $yoy . ' % více než loni' : 'o ' . abs($yoy) . ' % méně než loni'));
        $houseText = $vsHouse === null ? '' : ($vsHouse === 0 ? 'stejně jako průměr' : ($vsHouse > 0 ? 'o ' . $vsHouse . ' % více než průměr' : 'o ' . abs($vsHouse) . ' % méně než průměr')); ?>
      <p class="meter-stat"><strong><?= e($f($m['use']) . ' ' . $ul) ?></strong> <span>za <?= e(month_label($m['last'])) ?></span><?php if ($yoyText !== ''): ?> <span class="meter-delta <?= $yoy > 0 ? 'is-up' : 'is-down' ?>"><?= e($yoyText) ?></span><?php endif ?></p>
      <?php if ($houseText !== ''): ?><p class="meter-stat"><span>Na m²: <?= e($f($m['perM2']) . ' ' . $ul) ?> · průměr domu: <?= e($f($houseLast) . ' ' . $ul) ?></span> <span class="meter-delta <?= $vsHouse > 0 ? 'is-up' : 'is-down' ?>"><?= e($houseText) ?></span></p><?php endif ?>
      <?php if (count($m['series']) > 1): require __DIR__ . '/_meter_chart.php'; endif ?>
      <p class="hint"><span>Za posledních <?= count($m['series']) ?> měsíců celkem <?= e($f($m['total12']) . ' ' . $ul) ?>.</span><?php if ($m['house']): ?> <span>Tečky v grafu: průměr domu pro byt Vaší velikosti (<?= (int) $m['houseN'] ?> jednotek, bez údajů jednotlivých bytů).</span><?php endif ?></p>
    </div>
    <?php endforeach ?>
    <details<?= ($overview ?? []) ? '' : ' open' ?>><summary>Tabulka po měsících</summary>
    <table class="table"><thead><tr><th>Měsíc</th><th>Měřidlo</th><th>Spotřeba</th><th>Stav na konci měsíce</th></tr></thead><tbody>
      <?php foreach ($monthly as $ym => $items): foreach ($items as $i => [$unitId, $kind, $meter, $use, $state]): ?><tr>
        <td data-label="Měsíc"><?= $i === 0 ? e(month_label($ym)) : '' ?></td>
        <td data-label="Měřidlo"><?= e(READING_KINDS[$kind] ?? $kind) ?><?= count($units) > 1 ? ' · ' . e($units[$unitId] ?? '') : '' ?><?= $meter !== '' ? ' <small class="muted">č. ' . e($meter) . '</small>' : '' ?></td>
        <td data-label="Spotřeba"><strong><?= e(str_replace('.', ',', (string) round($use, 3))) ?></strong> <?= $kind === 'itn' ? 'dílků' : (in_array($kind, ['sv', 'tv', 'plyn'], true) ? 'm³' : ($kind === 'el' ? 'kWh' : '')) ?></td>
        <td data-label="Stav na konci měsíce"><?= e(str_replace('.', ',', (string) $state)) ?></td></tr><?php endforeach; endforeach ?>
    </tbody></table></details><?php endif ?>
  </section>
  <?php endif ?>
  <?php if ($manualKinds): ?>
  <form method="post" action="<?= url('/odecty') ?>" class="card form">
    <h2>Nový odečet</h2>
    <?= csrf_field() ?>
    <div class="form-row">
      <label>Jednotka <select name="unit_id" id="reading-unit"><?php foreach ($units as $id => $name): ?><option value="<?= $id ?>"><?= e($name) ?></option><?php endforeach ?></select></label>
      <label>Měřidlo <select name="kind" id="reading-kind"><?php foreach ($manualKinds as $k => $label): ?><option value="<?= $k ?>"<?= post('kind') === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
    </div>
    <div class="form-row">
      <label>Stav měřidla <input type="text" name="amount" id="reading-amount" inputmode="decimal" value="<?= e(post('amount')) ?>" placeholder="např. 123,45" required></label>
      <label>Datum odečtu <input type="date" name="read_date" value="<?= e(post('read_date') ?: date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>" required></label>
    </div>
    <label>Číslo měřidla (nepovinné) <input type="text" name="meter_no" value="<?= e(post('meter_no')) ?>" maxlength="50"></label>
    <label>Poznámka (nepovinné) <input type="text" name="note" value="<?= e(post('note')) ?>" maxlength="255"></label>
    <button class="btn" type="submit">Uložit odečet</button>
  </form>
  <?php endif ?>
  <h2>Odečty mé jednotky</h2>
  <?php if (!$rows): ?><p class="empty">Zatím žádný odečet.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Datum</th><th>Jednotka</th><th>Měřidlo</th><th>Stav</th><th>Zdroj</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td data-label="Datum"><?= cz_date($r['read_date']) ?></td><td data-label="Jednotka"><?= e($units[$r['unit_id']] ?? '') ?></td>
        <td data-label="Měřidlo"><?= READING_KINDS[$r['kind']] ?? e($r['kind']) ?><?= $r['meter_no'] ? ' <small class="muted">č. ' . e($r['meter_no']) . '</small>' : '' ?></td>
        <td data-label="Stav"><strong><?= e(crud_display(['', 'decimal'], $r['amount'])) ?></strong></td><td data-label="Zdroj"><?= ($r['source'] ?? 'manual') === 'manual' ? e($r['author'] ?? 'ručně') : '<span class="muted">' . (($r['source'] ?? '') === 'api' ? 'dálkový odečet' : 'od odečtové firmy') . '</span>' ?></td></tr>
    <?php endforeach ?></tbody>
  </table>
  <?php endif; endif ?>
</div>
