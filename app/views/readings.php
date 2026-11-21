<div class="wrap page narrow">
  <header class="page-head"><h1>Odečty měřidel</h1><p class="lead">Nahlaste výboru stav vodoměru nebo jiného měřidla ve své jednotce.</p></header>
  <?php require __DIR__ . '/_errors.php'; ?>
  <?php if (!$units): ?><p class="note">K Vašemu účtu není přiřazena žádná jednotka, proto odečet zadat nejde. Obraťte se na výbor.</p><?php else: ?>
  <form method="post" action="<?= url('/odecty') ?>" class="card form">
    <h2>Nový odečet</h2>
    <?= csrf_field() ?>
    <div class="form-row">
      <label>Jednotka <select name="unit_id" id="reading-unit"><?php foreach ($units as $id => $name): ?><option value="<?= $id ?>"><?= e($name) ?></option><?php endforeach ?></select></label>
      <label>Měřidlo <select name="kind" id="reading-kind"><?php foreach (READING_KINDS as $k => $label): ?><option value="<?= $k ?>"<?= post('kind') === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
    </div>
    <div class="form-row">
      <label>Stav měřidla <input type="text" name="amount" id="reading-amount" inputmode="decimal" value="<?= e(post('amount')) ?>" placeholder="např. 123,45" required></label>
      <label>Datum odečtu <input type="date" name="read_date" value="<?= e(post('read_date') ?: date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>" required></label>
    </div>
    <label>Číslo měřidla (nepovinné) <input type="text" name="meter_no" value="<?= e(post('meter_no')) ?>" maxlength="50"></label>
    <label>Poznámka (nepovinné) <input type="text" name="note" value="<?= e(post('note')) ?>" maxlength="255"></label>
    <button class="btn" type="submit">Uložit odečet</button>
  </form>
  <h2>Odečty mé jednotky</h2>
  <?php if (!$rows): ?><p class="empty">Zatím žádný odečet.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Datum</th><th>Jednotka</th><th>Měřidlo</th><th>Stav</th><th>Zapsal</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td data-label="Datum"><?= cz_date($r['read_date']) ?></td><td data-label="Jednotka"><?= e($units[$r['unit_id']] ?? '') ?></td>
        <td data-label="Měřidlo"><?= READING_KINDS[$r['kind']] ?? e($r['kind']) ?><?= $r['meter_no'] ? ' <small class="muted">č. ' . e($r['meter_no']) . '</small>' : '' ?></td>
        <td data-label="Stav"><strong><?= e(crud_display(['', 'decimal'], $r['amount'])) ?></strong></td><td data-label="Zapsal"><?= e($r['author'] ?? '') ?></td></tr>
    <?php endforeach ?></tbody>
  </table>
  <?php endif; endif ?>
</div>
