<?php
$visible = array_values(array_filter($docs, 'can_open_document'));
$hidden = count(array_filter($docs, fn($d) => !$d['unit_id'])) - count(array_filter($visible, fn($d) => !$d['unit_id']));
?>
<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/aktuality') ?>">← Aktuality</a></p>
  <article class="article">
    <p class="post-meta"><time datetime="<?= e($post['published_at']) ?>"><?= cz_date($post['published_at'], true) ?></time>
      <?php if ($post['visibility'] === 'residents'): ?><span class="tag tag-lock"><?= icon('lock') ?> jen pro přihlášené</span><?php endif ?></p>
    <h1><?= e($post['title']) ?></h1>
    <div class="prose"><?= format_text($post['body']) ?></div>
    <?php if ($visible): ?>
      <h2 class="h-small">Přílohy</h2>
      <?php $docs = $visible; require __DIR__ . '/_doclist.php'; ?>
    <?php endif ?>
    <?php if ($hidden): ?>
      <p class="note"><?= icon('lock') ?> <?= $hidden === 1 ? 'Jedna příloha je dostupná' : 'Další přílohy (' . $hidden . ') jsou dostupné' ?> jen přihlášeným obyvatelům domu. <a href="<?= url('/prihlaseni') ?>">Přihlásit se</a></p>
    <?php endif ?>
    <?php if (is_admin()): ?><p><a class="btn btn-small btn-ghost" href="<?= url('/admin/aktuality/' . $post['id']) ?>">Upravit aktualitu</a></p><?php endif ?>
  </article>
</div>
