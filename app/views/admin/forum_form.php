<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/diskuze/' . ($p['parent_id'] ?: $p['id'])) ?>">← Zpět do diskuze</a></p>
  <h1><?= e($title) ?></h1>
  <p class="lead">Autor: <?= e($p['author_name'] ?? 'bývalý uživatel') ?>, <?= cz_datetime($p['created_at']) ?>. U upraveného příspěvku uvidí všichni poznámku, že ho upravil správce, a úprava se zapíše do záznamů.</p>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <?php if (!$p['parent_id']): ?><label>Název tématu <input type="text" name="title" value="<?= e($p['title']) ?>" maxlength="150" required></label><?php endif ?>
    <label>Text <textarea name="body" rows="8" maxlength="5000" required><?= e($p['body']) ?></textarea></label>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit úpravu</button><a class="btn btn-ghost" href="<?= url('/diskuze/' . ($p['parent_id'] ?: $p['id'])) ?>">Zrušit</a></div>
  </form>
</div>
