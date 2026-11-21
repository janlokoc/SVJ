<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/admin/terminy') ?>">← Termíny</a></p>
  <h1><?= e($title) ?></h1>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <label>Název <input type="text" name="title" value="<?= e($ev['title']) ?>" required></label>
    <div class="form-row">
      <label>Datum <input type="date" name="event_date" value="<?= e($ev['event_date']) ?>" required></label>
      <label>Čas (nepovinné) <input type="time" name="event_time" value="<?= e($ev['event_time']) ?>"></label>
    </div>
    <label>Místo <input type="text" name="place" value="<?= e($ev['place']) ?>" placeholder="Např. sušárna vchodu B"></label>
    <label>Poznámka <input type="text" name="note" value="<?= e($ev['note']) ?>"></label>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit termín</button><a class="btn btn-ghost" href="<?= url('/admin/terminy') ?>">Zrušit</a></div>
  </form>
</div>
