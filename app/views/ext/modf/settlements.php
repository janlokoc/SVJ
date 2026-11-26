<?php /* Vyúčtování služeb – seznam a založení. $rows = modf_settlements(), $form, $errors. */
$today = date('Y-m-d'); ?>
<div class="wrap page">
  <header class="page-head"><h1>Vyúčtování služeb</h1>
    <p class="lead">Vyúčtování jednotek na webu: vlastníci ho dostanou e-mailem, potvrdí převzetí a mohou podat námitku. Web hlídá lhůty podle zákona č.&nbsp;67/2013&nbsp;Sb.: doručení do 4 měsíců od konce období, námitky do 30 dnů od doručení a jejich vyřízení do 30 dnů.</p>
    <?= function_exists('help_link') ? help_link('vyuctovani-sluzeb') : '' ?></header>
  <?php if (!$rows): ?><p class="empty">Zatím tu žádné vyúčtování není.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Vyúčtování</th><th>Období</th><th>Doručení</th><th>Jednotky</th><th>Potvrzeno</th><th>Námitky</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $dl = modf_delivery_deadline($r['period_to']); $late = $r['published_at'] === '' && $dl < $today; ?>
      <tr>
        <td data-label="Vyúčtování"><a href="<?= url('/admin/vyuctovani/' . $r['id']) ?>"><strong><?= e($r['title']) ?></strong></a></td>
        <td data-label="Období"><?= cz_date($r['period_from']) ?> – <?= cz_date($r['period_to']) ?></td>
        <td data-label="Doručení"><?php if ($r['published_at'] !== ''): ?><span class="status status-done">doručeno <?= cz_date($r['delivery_date']) ?></span>
          <?php else: ?><span class="status <?= $late ? 'status-new' : 'status-poll-planned' ?>">připravuje se</span> <small class="muted">doručit do <?= cz_date($dl) ?></small><?php endif ?></td>
        <td data-label="Jednotky"><?= (int) $r['units'] ?><?= (int) $r['unmatched'] ? ' <span class="tag tag-late">' . (int) $r['unmatched'] . ' bez jednotky</span>' : '' ?></td>
        <td data-label="Potvrzeno"><?= (int) $r['confirmed'] ?> z <?= (int) $r['units'] ?></td>
        <td data-label="Námitky"><?= (int) $r['open_objections'] ? '<span class="tag tag-late">' . (int) $r['open_objections'] . ' k vyřízení</span>' : '–' ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>

  <section class="card form narrow" aria-labelledby="modf-new-h">
    <h2 id="modf-new-h">Nové vyúčtování</h2>
    <?php require __DIR__ . '/../../_errors.php'; ?>
    <form method="post" action="<?= url('/admin/vyuctovani') ?>">
      <?= csrf_field() ?>
      <label>Název <input type="text" name="title" value="<?= e($form['title']) ?>" required maxlength="191"></label>
      <div class="form-row">
        <label>Zúčtovací období od <input type="date" name="period_from" value="<?= e($form['period_from']) ?>" required></label>
        <label>do <input type="date" name="period_to" value="<?= e($form['period_to']) ?>" required></label>
      </div>
      <label>Poznámka pro vlastníky (nepovinné) <textarea name="note" rows="2" maxlength="2000"><?= e($form['note']) ?></textarea></label>
      <p class="hint">Soubory jednotek nahrajete v dalším kroku. Den doručení zadáte při zveřejnění.</p>
      <div class="actions"><button class="btn" type="submit">Založit vyúčtování</button></div>
    </form>
  </section>
</div>
