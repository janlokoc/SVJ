<div class="wrap page">
  <header class="page-head row"><h1>Hlasování</h1><a class="btn" href="<?= url('/admin/hlasovani/nove') ?>">+ Nové hlasování</a></header>
  <p class="lead">Hlasování vlastníků mimo zasedání (per rollam) a zápis hlasování ze shromáždění. Hlasy se počítají podle spoluvlastnických podílů jednotek.</p>
  <?php if (!$polls): ?><p class="empty">Zatím žádné hlasování.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Název</th><th>Období</th><th>Stav</th><th>Bodů</th><th>Způsob</th><th></th></tr></thead>
    <tbody><?php foreach ($polls as $p): $st = poll_state($p); ?>
      <tr><td data-label="Název"><a href="<?= url('/admin/hlasovani/' . $p['id']) ?>"><strong><?= e($p['title']) ?></strong></a></td>
        <td data-label="Období"><?= cz_date($p['opens_at']) ?> – <?= cz_date($p['closes_at']) ?></td>
        <td data-label="Stav"><span class="status status-poll-<?= $st ?>"><?= POLL_STATES[$st] ?></span></td><td data-label="Bodů"><?= (int) $p['items'] ?></td>
        <td data-label="Způsob"><?= $p['online'] ? 'přes web' : 'zapisuje výbor' ?></td>
        <td class="t-actions"><a href="<?= url('/admin/hlasovani/' . $p['id'] . '/vysledky') ?>">Hlasy a výsledky</a>
          <form method="post" action="<?= url('/admin/hlasovani/' . $p['id'] . '/smazat') ?>" data-confirm="Opravdu smazat hlasování „<?= e($p['title']) ?>“ včetně všech odevzdaných hlasů?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat</button></form></td></tr>
    <?php endforeach ?></tbody>
  </table>
  <?php endif ?>
</div>
