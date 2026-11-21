<div class="wrap page">
  <header class="page-head row"><h1>Aktuality</h1><a class="btn" href="<?= url('/admin/aktuality/nova') ?>">+ Nová aktualita</a></header>
  <table class="table">
    <thead><tr><th>Nadpis</th><th>Zveřejněno</th><th>Viditelnost</th><th>Přílohy</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($posts as $p): ?>
      <tr>
        <td data-label="Nadpis"><a href="<?= url('/admin/aktuality/' . $p['id']) ?>"><strong><?= e($p['title']) ?></strong></a><?php if ($p['pinned']): ?> <span class="tag tag-pin">důležité</span><?php endif ?></td>
        <td data-label="Zveřejněno"><?= cz_date($p['published_at']) ?><?= $p['published_at'] > date('Y-m-d') ? ' <span class="tag">naplánováno</span>' : '' ?></td>
        <td data-label="Viditelnost"><?= VISIBILITY[$p['visibility']] ?></td>
        <td data-label="Přílohy"><?= (int) $p['docs'] ?></td>
        <td class="t-actions"><a href="<?= url('/aktuality/' . $p['id']) ?>">Zobrazit</a>
          <form method="post" action="<?= url('/admin/aktuality/' . $p['id'] . '/smazat') ?>" data-confirm="Opravdu smazat aktualitu „<?= e($p['title']) ?>“?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat</button></form></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>
