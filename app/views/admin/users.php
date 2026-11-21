<?php $np = $_SESSION['new_password'] ?? null; unset($_SESSION['new_password']); ?>
<div class="wrap page">
  <header class="page-head row"><h1>Uživatelé</h1><a class="btn" href="<?= url('/admin/uzivatele/novy') ?>">+ Nový uživatel</a></header>
  <?php if ($np): ?>
  <div class="card card-soft credentials">
    <h2>Přihlašovací údaje pro: <?= e($np['name']) ?></h2>
    <p>Předejte je osobně nebo bezpečnou cestou. <strong>Heslo se zobrazuje jen teď</strong> – po prvním přihlášení si ho uživatel musí změnit.</p>
    <dl><dt>E-mail</dt><dd><code><?= e($np['email']) ?></code></dd><dt>Dočasné heslo</dt><dd><code><?= e($np['password']) ?></code></dd></dl>
  </div>
  <?php endif ?>
  <table class="table">
    <thead><tr><th>Jméno</th><th>E-mail</th><th>Role</th><th>Vchod / byt</th><th>Poslední přihlášení</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $x): ?>
      <tr<?= $x['active'] ? '' : ' class="is-off"' ?>>
        <td data-label="Jméno"><a href="<?= url('/admin/uzivatele/' . $x['id']) ?>"><strong><?= e($x['name']) ?></strong></a><?= $x['active'] ? '' : ' <span class="tag">vypnutý</span>' ?></td>
        <td data-label="E-mail"><?= e($x['email']) ?></td>
        <td data-label="Role"><?= ROLES[$x['role']] ?> · <?= mb_strtolower(KINDS[$x['kind']]) ?><?= $x['email_notify'] ? '' : ' <span class="tag">bez e-mailů</span>' ?></td>
        <td data-label="Vchod / byt"><?= e(implode(' / ', array_filter([$x['entrance'], $x['unit']]))) ?></td>
        <td data-label="Poslední přihlášení"><?= $x['last_login_at'] ? cz_datetime($x['last_login_at']) : 'zatím ne' ?></td>
        <td class="t-actions">
          <form method="post" action="<?= url('/admin/uzivatele/' . $x['id'] . '/heslo') ?>" data-confirm="Vygenerovat nové dočasné heslo pro „<?= e($x['name']) ?>“? Stávající heslo přestane platit."><?= csrf_field() ?><button class="link" type="submit">Nové heslo</button></form>
          <?php if ((int) $x['id'] !== (int) user()['id']): ?>
          <form method="post" action="<?= url('/admin/uzivatele/' . $x['id'] . '/smazat') ?>" data-confirm="Opravdu smazat účet „<?= e($x['name']) ?>“?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat</button></form>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>
