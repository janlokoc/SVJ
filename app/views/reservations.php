<div class="wrap page narrow">
  <header class="page-head"><h1>Rezervace</h1><p class="lead">Rezervace společných prostor a věcí v domě.</p></header>
  <?php require __DIR__ . '/_errors.php'; ?>
  <?php if (!$resources): ?><p class="empty">Výbor zatím nenabídl nic k rezervaci.</p><?php else: ?>
  <form method="post" action="<?= url('/rezervace') ?>" class="card form">
    <h2>Nová rezervace</h2>
    <?= csrf_field() ?>
    <label>Co <select name="resource_id" id="res-resource"><?php foreach ($resources as $r): ?><option value="<?= $r['id'] ?>"<?= (int) post('resource_id') === (int) $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach ?></select></label>
    <div class="form-row">
      <label>Den <input type="date" name="res_date" value="<?= e(post('res_date') ?: date('Y-m-d')) ?>" min="<?= date('Y-m-d') ?>" required></label>
      <label>Od <input type="time" name="time_from" value="<?= e(post('time_from')) ?>" required></label>
      <label>Do <input type="time" name="time_to" value="<?= e(post('time_to')) ?>" required></label>
    </div>
    <label>Poznámka (nepovinné) <input type="text" name="note" value="<?= e(post('note')) ?>" maxlength="255"></label>
    <button class="btn" type="submit">Rezervovat</button>
    <?php foreach ($resources as $r): if ($r['note']): ?><p class="hint"><strong><?= e($r['name']) ?>:</strong> <?= e($r['note']) ?></p><?php endif; endforeach ?>
  </form>
  <?php endif ?>
  <h2>Nadcházející rezervace</h2>
  <?php if (!$rows): ?><p class="empty">Žádná rezervace.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Den</th><th>Čas</th><th>Co</th><th>Kdo</th><th></th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td data-label="Den"><strong><?= cz_date($r['res_date']) ?></strong> <small class="muted"><?= WEEKDAYS[(int) date('w', strtotime($r['res_date']))] ?></small></td>
        <td data-label="Čas"><?= e($r['time_from']) ?>–<?= e($r['time_to']) ?></td><td data-label="Co"><?= e($r['resource']) ?><?= $r['note'] ? '<br><small class="muted">' . e($r['note']) . '</small>' : '' ?></td>
        <td data-label="Kdo"><?= e(author_label($r)) ?></td>
        <td class="t-actions"><?php if ((int) $r['user_id'] === (int) user()['id']): ?><form method="post" action="<?= url('/rezervace/' . $r['id'] . '/zrusit') ?>" data-confirm="Zrušit tuto rezervaci?"><?= csrf_field() ?><button class="link-danger" type="submit">Zrušit</button></form><?php endif ?></td></tr>
    <?php endforeach ?></tbody>
  </table>
  <?php endif ?>
</div>
