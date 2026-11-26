<?php /* Úprava opravy v plánu. $r, $errors. */ ?>
<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/admin/plan-oprav') ?>#opravy">← Plán oprav a fond oprav</a></p>
  <h1><?= e($title) ?></h1>
  <?php require __DIR__ . '/../../_errors.php'; ?>
  <form method="post" class="card form"><?= csrf_field() ?>
    <?php require __DIR__ . '/_repair_fields.php'; ?>
    <p class="hint">Hotové opravy se do výhledu fondu nepočítají; nehotové opravy z minulých let se počítají do prvního roku výhledu.</p>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit</button><a class="btn btn-ghost" href="<?= url('/admin/plan-oprav') ?>#opravy">Zrušit</a></div>
  </form>
</div>
