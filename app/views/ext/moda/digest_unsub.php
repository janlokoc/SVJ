<?php /* Odhlášení týdenního přehledu odkazem z e-mailu: $token, $done, $email */ ?>
<div class="wrap page auth">
  <div class="card auth-card">
    <h1>Týdenní přehled</h1>
    <?php if ($done): ?>
      <div class="flash" role="status">Hotovo. Týdenní přehled na adresu <?= e($email) ?> už posílat nebudeme.</div>
      <p class="hint">Zapnout si ho můžete znovu ve svém účtu (Můj účet → Týdenní přehled). Důležité zprávy výboru Vám chodit dál budou, pokud je nemáte vypnuté.</p>
    <?php else: ?>
      <p>Chcete přestat dostávat týdenní přehled novinek na adresu <strong><?= e($email) ?></strong>?</p>
      <form method="post" action="<?= url('/tydenni-prehled/odhlasit/' . $token) ?>" class="form">
        <?= csrf_field() ?>
        <button class="btn btn-big" type="submit">Odhlásit týdenní přehled</button>
      </form>
    <?php endif ?>
    <p class="hint"><a href="<?= url('/') ?>">← Na úvod webu</a></p>
  </div>
</div>
