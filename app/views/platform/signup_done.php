<div class="wrap page narrow center">
  <h1>Web je založen</h1>
  <p class="lead">Web <strong><?= e($done['name']) ?></strong> běží na adrese <?= e(preg_replace('~^https?://~', '', $done['site'])) ?>. Zkušební doba zdarma trvá do <?= cz_date($done['until']) ?>.</p>
  <p>Za okamžik Vás přihlásíme do administrace.</p>
  <p><a class="btn btn-big" href="<?= e($done['go']) ?>">Otevřít administraci <?= icon('arrow') ?></a></p>
</div>
