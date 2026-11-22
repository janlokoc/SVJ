<div class="wrap page">
  <header class="page-head row"><h1>Termíny</h1><a class="btn" href="<?= url('/admin/terminy/novy') ?>">+ Nový termín</a></header>
  <p class="hint">Nejbližší budoucí termín se zobrazuje na úvodní stránce.</p>
  <?php if (!$events): ?><p class="empty">Žádný termín.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Název</th><th>Datum</th><th>Čas</th><th>Místo</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($events as $ev): ?>
      <tr>
        <td data-label="Název"><a href="<?= url('/admin/terminy/' . $ev['id']) ?>"><strong><?= e($ev['title']) ?></strong></a><?= $ev['event_date'] < date('Y-m-d') ? ' <span class="tag">proběhlo</span>' : '' ?></td>
        <td data-label="Datum"><?= cz_date($ev['event_date']) ?></td><td data-label="Čas"><?= e($ev['event_time']) ?></td><td data-label="Místo"><?= e($ev['place']) ?></td>
        <td class="t-actions"><?php if ($ev['rsvp']): ?><a href="<?= url('/admin/terminy/' . $ev['id'] . '/ucast') ?>">Účast</a> <a href="<?= url('/admin/terminy/' . $ev['id'] . '/zapis') ?>">Zápis</a> <?php endif ?><a href="<?= url('/admin/zpravy/nova?termin=' . $ev['id']) ?>">Pozvat e-mailem</a>
          <form method="post" action="<?= url('/admin/terminy/' . $ev['id'] . '/smazat') ?>" data-confirm="Opravdu smazat termín?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat</button></form></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>
