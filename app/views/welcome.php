<?php /* Uvítání po přijetí pozvánky: upozornění do telefonu a způsob upozorňování. */ ?>
<div class="wrap page narrow welcome">
  <header class="page-head"><h1>Vítejte, <?= e(cz_vocative(explode(' ', trim($u['name']))[0])) ?>!</h1>
    <p class="lead">Účet je aktivní. Ještě jedna věc na minutu: jak Vám máme dávat vědět o novinkách v domě.</p></header>
  <?php if (plan_has('push')): ?>
  <section class="card form push-card" id="upozorneni">
    <h2>Upozornění do telefonu</h2>
    <p>Nové oznámení výboru, odstávka, zpráva od souseda nebo vyřízená závada Vám přijde přímo na displej – jako zpráva z aplikace.</p>
    <?php require __DIR__ . '/_push_box.php'; ?>
    <p class="hint"><a href="<?= url('/napoveda/upozorneni-do-telefonu') ?>">Návod: web na ploše telefonu a upozornění (iPhone i Android)</a></p>
  </section>
  <?php endif ?>
  <form method="post" action="<?= url('/vitejte') ?>" class="card form">
    <?= csrf_field() ?>
    <h2>Jak Vám máme dávat vědět</h2>
    <label class="check"><input type="checkbox" name="email_notify"<?= $u['email_notify'] ? ' checked' : '' ?>> <span>E-mailem <small>Důležité zprávy výboru a upozornění z webu na <?= e($u['email']) ?>.</small></span></label>
    <?php if (plan_has('push')): ?><label class="check"><input type="checkbox" name="push_notify"<?= !isset($u['push_notify']) || $u['push_notify'] ? ' checked' : '' ?>> <span>Do telefonu <small>Na zařízeních, kde upozornění zapnete.</small></span></label><?php endif ?>
    <p class="hint">Kdykoli to změníte v části Můj účet.</p>
    <button class="btn btn-big" type="submit">Hotovo, pokračovat na web</button>
  </form>
</div>
