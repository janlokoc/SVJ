<div class="wrap page">
  <header class="page-head row"><h1>Kontakty</h1><a class="btn" href="<?= url('/admin/kontakty/novy') ?>">+ Nový kontakt</a></header>
  <table class="table">
    <thead><tr><th>Jméno</th><th>Skupina</th><th>Funkce</th><th>Telefon</th><th>E-mail</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($contacts as $c): ?>
      <tr>
        <td data-label="Jméno"><a href="<?= url('/admin/kontakty/' . $c['id']) ?>"><strong><?= e($c['name']) ?></strong></a></td>
        <td data-label="Skupina"><?= CONTACT_GROUPS[$c['grp']] ?></td><td data-label="Funkce"><?= e($c['role']) ?></td>
        <td data-label="Telefon"><?= e($c['phone']) ?></td><td data-label="E-mail"><?= e($c['email']) ?></td>
        <td class="t-actions"><form method="post" action="<?= url('/admin/kontakty/' . $c['id'] . '/smazat') ?>" data-confirm="Opravdu smazat kontakt „<?= e($c['name']) ?>“?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat</button></form></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>
