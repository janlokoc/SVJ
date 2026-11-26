<div class="wrap page">
  <header class="page-head"><h1>Šablony textů</h1>
    <p class="lead">Texty, které píšete znovu a znovu: pozvánka na shromáždění, oznámení o odečtech, odstávka vody, výzva k úhradě. V administraci každého domu je najdete nad formulářem aktuality a e-mailu („Šablona firmy“), v portálu u hromadného oznámení.</p></header>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <div class="two-col">
    <section>
      <?php if (!$list): ?><p class="empty">Zatím žádné šablony.</p><?php else: ?>
      <ul class="plain-list tpl-list"><?php foreach ($list as $tp): ?><li><strong><?= e($tp['name']) ?></strong> <span class="tag"><?= e(PARTNER_TPL_KINDS[$tp['kind']] ?? $tp['kind']) ?></span><br><small class="muted"><?= e(mb_strimwidth($tp['title'] !== '' ? $tp['title'] : $tp['body'], 0, 90, '…')) ?></small>
        <span class="actions"><a class="btn btn-small btn-ghost" href="<?= url('/sablony?id=' . (int) $tp['id']) ?>">Upravit</a>
        <form method="post" data-confirm="Smazat šablonu?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $tp['id'] ?>"><button class="link-danger" type="submit">Smazat</button></form></span></li><?php endforeach ?></ul>
      <?php endif ?>
    </section>
    <form method="post" class="card form"><h2><?= $edit ? 'Upravit šablonu' : 'Nová šablona' ?></h2><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <label>Název šablony <input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" maxlength="120" required placeholder="např. Odečty vodoměrů"></label>
      <label>Použití <select name="kind"><?php foreach (PARTNER_TPL_KINDS as $k => $l): ?><option value="<?= $k ?>"<?= ($edit['kind'] ?? 'post') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select></label>
      <label>Nadpis / předmět <input type="text" name="title" value="<?= e($edit['title'] ?? '') ?>" maxlength="200"></label>
      <label>Text <textarea name="body" rows="10" required><?= e($edit['body'] ?? '') ?></textarea></label>
      <div class="actions"><button class="btn" type="submit">Uložit šablonu</button><?php if ($edit): ?><a class="btn btn-ghost" href="<?= url('/sablony') ?>">Zrušit</a><?php endif ?></div>
    </form>
  </div>
</div>
