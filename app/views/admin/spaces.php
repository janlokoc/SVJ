<div class="wrap page">
  <header class="page-head row"><h1>Prostory a rezervace</h1>
    <span class="actions"><a class="btn btn-ghost" href="<?= url('/admin/prostory/novy') ?>">+ Přidat prostor</a><?php if ($resources): ?><a class="btn" href="<?= url('/admin/rezervace/novy') ?>">+ Přidat rezervaci</a><?php endif ?></span></header>
  <p class="lead">Prostory a věci, které si obyvatelé mohou rezervovat (sušárna, společenská místnost, vozík…), a u každého jeho nadcházející rezervace. Obyvatelé si rezervace zadávají sami; zde je můžete upravit nebo zrušit.</p>
  <?php if (!$resources): ?><p class="empty">Zatím tu není žádný prostor. Přidejte první – potom si ho obyvatelé budou moci rezervovat.</p><?php endif ?>
  <?php foreach ($resources as $s): $rows = $byResource[(int) $s['id']] ?? []; ?>
  <section class="card space-card">
    <div class="space-head">
      <h2><a href="<?= url('/admin/prostory/' . $s['id']) ?>"><?= e($s['name']) ?></a> <?= $s['active'] ? '' : '<span class="status status-new">rezervace vypnuté</span>' ?></h2>
      <span class="actions"><a class="btn btn-small btn-ghost" href="<?= url('/admin/prostory/' . $s['id']) ?>">Upravit</a>
        <form method="post" action="<?= url('/admin/prostory/' . $s['id'] . '/smazat') ?>" data-confirm="Smazat prostor „<?= e($s['name']) ?>“ i se všemi jeho rezervacemi?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat</button></form></span>
    </div>
    <?php if ($s['note'] !== '' && $s['note'] !== null): ?><p class="muted"><?= e($s['note']) ?></p><?php endif ?>
    <?php if (!$rows): ?><p class="empty">Žádná nadcházející rezervace.</p><?php else: ?>
    <table class="table">
      <thead><tr><th>Den</th><th>Čas</th><th>Kdo</th><th>Poznámka</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr><td data-label="Den"><a href="<?= url('/admin/rezervace/' . $r['id']) ?>"><strong><?= cz_date($r['res_date']) ?></strong></a> <small class="muted"><?= weekday_name((int) date('w', strtotime($r['res_date']))) ?></small></td>
          <td data-label="Čas"><?= e($r['time_from']) ?>–<?= e($r['time_to']) ?></td>
          <td data-label="Kdo"><?= e($r['author_name'] ?? '—') ?><?= !empty($r['author_units']) ? ' <small class="muted">' . e($r['author_units']) . '</small>' : '' ?></td>
          <td data-label="Poznámka"><?= e($r['note']) ?></td>
          <td class="t-actions"><form method="post" action="<?= url('/admin/rezervace/' . $r['id'] . '/smazat') ?>" data-confirm="Zrušit tuto rezervaci?"><?= csrf_field() ?><button class="link-danger" type="submit">Zrušit</button></form></td></tr>
      <?php endforeach ?>
      </tbody>
    </table>
    <?php endif ?>
    <?php if (!empty($past[$s['id']])): ?><p class="hint">Proběhlých rezervací: <?= (int) $past[$s['id']] ?>.</p><?php endif ?>
  </section>
  <?php endforeach ?>
</div>
