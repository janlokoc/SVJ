<?php $o = operator(); ?>
<div class="wrap page narrow">
  <h1><?= e($title) ?></h1>
  <div class="card card-soft operator-box">
    <span class="h-small">Provozovatel služby</span>
    <?php if ($o['name'] === ''): ?><p class="muted">Údaje provozovatele zatím nejsou vyplněné.</p><?php else: ?>
    <p><strong><?= e($o['name']) ?></strong><?= $o['address'] !== '' ? ', ' . e($o['address']) : '' ?><?= $o['ico'] !== '' ? ', IČO ' . e($o['ico']) : '' ?><?= $o['dic'] !== '' ? ', DIČ ' . e($o['dic']) : '' ?><?= $o['register'] !== '' ? '<br>' . e($o['register']) : '' ?>
      <?= $o['email'] !== '' ? '<br><a href="mailto:' . e($o['email']) . '">' . e($o['email']) . '</a>' : '' ?><?= $o['phone'] !== '' ? ' · ' . e($o['phone']) : '' ?></p><?php endif ?>
  </div>
  <div class="prose legal"><?= format_text($body) ?></div>
  <?php if (!empty($also)): ?><p><a href="<?= url($also[0]) ?>"><?= e($also[1]) ?></a></p><?php endif ?>
</div>
