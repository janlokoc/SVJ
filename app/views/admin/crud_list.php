<div class="wrap page">
  <header class="page-head row"><h1><?= e($def['title']) ?></h1>
    <span class="actions"><?php if ($slug === 'odecty'): ?><a class="btn" href="<?= url('/admin/odecty/dalkove') ?>">Dálkové odečty a import</a><a class="btn btn-ghost" href="<?= url('/admin/odecty/export') ?>">Export do Excelu (CSV)</a><?php endif ?>
      <a class="btn" href="<?= url("/admin/$slug/novy") ?>">+ Přidat <?= e($def['one']) ?></a></span></header>
  <p class="lead"><?= e($def['lead']) ?></p>
  <?php if (!$rows): ?><p class="empty">Zatím tu nic není.</p><?php else: ?>
  <table class="table">
    <thead><tr><?php foreach ($def['fields'] as $f): ?><th><?= e(preg_replace('/\s*\(.*$/u', '', $f[0])) ?></th><?php endforeach ?><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $state = $slug === 'revize' ? maintenance_state($r['next_date']) : ''; ?>
      <tr>
        <?php $first = true; foreach ($def['fields'] as $name => $f): ?>
          <td data-label="<?= e(preg_replace('/\s*\(.*$/u', '', $f[0])) ?>"><?php if ($first): ?><a href="<?= url("/admin/$slug/" . $r['id']) ?>"><strong><?= e(crud_display($f, $r[$name])) ?></strong></a><?php else: ?><?= e(crud_display($f, $r[$name])) ?><?php endif ?>
            <?php if ($name === 'next_date' && $state): ?> <span class="status status-<?= $state === 'overdue' ? 'new' : 'in_progress' ?>"><?= $state === 'overdue' ? 'po termínu' : 'blíží se' ?></span><?php endif ?></td>
        <?php $first = false; endforeach ?>
        <td class="t-actions">
          <?php if ($slug === 'revize'): ?><form method="post" action="<?= url('/admin/revize/' . $r['id'] . '/provedeno') ?>" class="done-form"><?= csrf_field() ?><label class="sr-only" for="done-<?= (int) $r['id'] ?>">Kdy proběhla</label><input type="date" id="done-<?= (int) $r['id'] ?>" name="done_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required><button class="link" type="submit">Zapsat provedení</button></form><?php endif ?>
          <form method="post" action="<?= url("/admin/$slug/" . $r['id'] . '/smazat') ?>" data-confirm="Opravdu smazat?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat</button></form></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>
