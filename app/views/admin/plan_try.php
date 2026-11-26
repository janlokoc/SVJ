<?php [$min, $fname, $fdesc] = PLAN_FEATURES[$feature]; ?>
<div class="wrap page narrow">
  <header class="page-head"><span class="tag">edice <?= e(EDITIONS[$min]) ?></span><h1><?= e($fname) ?></h1><p class="lead"><?= e($fdesc) ?></p></header>
  <div class="card card-soft">
    <p>Tato funkce není součástí edice <?= e(EDITIONS[plan_edition()]) ?>. Můžete si ji <strong>jednou na <?= PLAN_TRIAL_DAYS ?> dní zdarma vyzkoušet</strong> – zapne se hned a nic neplatíte.</p>
    <p class="muted">Po skončení zkoušky zůstane vše, co do ní zapíšete, uložené a viditelné. Dál ji pak můžete používat s edicí <?= e(EDITIONS[$min]) ?> (<?= money_czk(edition_price($min, plan_tenant() ?? null)) ?> ročně).</p>
    <div class="actions">
      <form method="post" action="<?= url('/admin/predplatne/zkusit') ?>"><?= csrf_field() ?><input type="hidden" name="feature" value="<?= e($feature) ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
        <button class="btn btn-big" type="submit">Vyzkoušet <?= PLAN_TRIAL_DAYS ?> dní zdarma</button></form>
      <a class="btn btn-ghost" href="<?= url('/admin/predplatne') ?>#edice">Porovnat edice</a>
    </div>
  </div>
</div>
