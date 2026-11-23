<?php /* Článek nápovědy: $slug, $a, $public, $verified */ ?>
<div class="wrap page narrow help-article">
  <?php if (!$public): ?><p class="crumbs"><a href="<?= url('/admin/napoveda') ?>">← Nápověda</a></p><?php endif ?>
  <header class="page-head"><p class="eyebrow">Nápověda</p><h1><?= e($a[0]) ?></h1>
    <p class="lead"><?= e($a[1]) ?></p>
    <p class="help-verified<?= $verified ? ' is-ok' : '' ?>"><?= $verified ? '✓ Postup ověřen ' . e(cz_date($verified['date'], true)) . ' automatickým průchodem na skutečném webu (' . (int) $verified['checks'] . ' kontrol).' : 'Postup zatím nebyl ověřen automatickým průchodem.' ?>
      <?= !empty($verified['note']) ? '<br><small>' . e($verified['note']) . '</small>' : '' ?></p>
    <?php if (!empty($verified['steps'])): ?><details class="help-steps"><summary>Co přesně jsme ověřili</summary><ul><?php foreach ($verified['steps'] as $st): ?><li><?= e($st) ?></li><?php endforeach ?></ul></details><?php endif ?></header>
  <div class="prose help-body"><?php require __DIR__ . '/../../help/' . $slug . '.php'; ?></div>
</div>
