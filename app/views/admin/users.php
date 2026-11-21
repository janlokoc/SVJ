<?php $np = $_SESSION['new_password'] ?? null; unset($_SESSION['new_password']); ?>
<div class="wrap page">
  <header class="page-head row"><h1>Uživatelé</h1><span class="actions"><a class="btn btn-ghost" href="<?= url('/admin/uzivatele/import') ?>">Import ze seznamu</a><a class="btn" href="<?= url('/admin/uzivatele/novy') ?>">+ Nový uživatel</a></span></header>
  <?php $il = $_SESSION['admin_invite_link'] ?? null; unset($_SESSION['admin_invite_link']); if ($il): ?>
  <div class="card card-soft credentials">
    <h2>Pozvánka pro: <?= e($il['name']) ?></h2>
    <p>E-mail se nepodařilo odeslat. Předejte tento odkaz sami – <strong>zobrazuje se jen teď</strong>.</p>
    <p><code><?= e($il['link']) ?></code></p>
  </div>
  <?php endif ?>
  <?php if ($np): ?>
  <div class="card card-soft credentials">
    <h2>Přihlašovací údaje pro: <?= e($np['name']) ?></h2>
    <p>Předejte je osobně nebo bezpečnou cestou. <strong>Heslo se zobrazuje jen teď</strong> – po prvním přihlášení si ho uživatel musí změnit.</p>
    <dl><dt>E-mail</dt><dd><code><?= e($np['email']) ?></code></dd><dt>Dočasné heslo</dt><dd><code><?= e($np['password']) ?></code></dd></dl>
  </div>
  <?php endif ?>
  <table class="table">
    <thead><tr><th>Jméno</th><th>E-mail</th><th>Role</th><th>Jednotka</th><th>Poslední přihlášení</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $x): ?>
      <tr<?= $x['active'] ? '' : ' class="is-off"' ?>>
        <td data-label="Jméno"><a href="<?= url('/admin/uzivatele/' . $x['id']) ?>"><strong><?= e($x['name']) ?></strong></a><?= $x['active'] ? '' : ' <span class="tag">vypnutý</span>' ?><?= $x['totp_enabled'] ? ' <span class="tag tag-lock">2FA</span>' : ($x['role'] === 'admin' && $x['active'] ? ' <span class="tag tag-pin">bez 2FA</span>' : '') ?><?= $x['password_hash'] === NO_PASSWORD ? ' <span class="tag tag-pin">čeká na přijetí pozvánky</span>' : '' ?><?= $x['inviter_name'] ? '<br><small>pozval(a) ' . e($x['inviter_name']) . '</small>' : '' ?></td>
        <td data-label="E-mail"><?= e($x['email']) ?></td>
        <td data-label="Role"><?= ROLES[$x['role']] ?> · <?= mb_strtolower(KINDS[$x['kind']]) ?><?= $x['email_notify'] ? '' : ' <span class="tag">bez e-mailů</span>' ?></td>
        <td data-label="Jednotka"><?= e($x['unit_names'] ?? '') ?></td>
        <td data-label="Poslední přihlášení"><?= $x['last_login_at'] ? cz_datetime($x['last_login_at']) : 'zatím ne' ?></td>
        <td class="t-actions">
          <?php if ($x['password_hash'] === NO_PASSWORD): ?><form method="post" action="<?= url('/admin/uzivatele/' . $x['id'] . '/pozvanka') ?>" data-confirm="Poslat uživateli „<?= e($x['name']) ?>“ e-mail s odkazem na nastavení hesla? Dříve poslaný odkaz přestane platit."><?= csrf_field() ?><button class="link" type="submit">Poslat pozvánku</button></form><?php endif ?>
          <form method="post" action="<?= url('/admin/uzivatele/' . $x['id'] . '/heslo') ?>" data-confirm="Vygenerovat nové dočasné heslo pro „<?= e($x['name']) ?>“? Stávající heslo přestane platit."><?= csrf_field() ?><button class="link" type="submit">Nové heslo</button></form>
          <?php if ($x['totp_enabled'] && (int) $x['id'] !== (int) user()['id']): ?><form method="post" action="<?= url('/admin/uzivatele/' . $x['id'] . '/overeni') ?>" data-confirm="Vypnout dvoufázové ověření uživatele „<?= e($x['name']) ?>“? Udělejte to jen tehdy, když o to prokazatelně požádal on sám."><?= csrf_field() ?><button class="link" type="submit">Vypnout 2FA</button></form><?php endif ?>
          <?php if ((int) $x['id'] !== (int) user()['id']): ?>
          <form method="post" action="<?= url('/admin/uzivatele/' . $x['id'] . '/smazat') ?>" data-confirm="Opravdu smazat účet „<?= e($x['name']) ?>“?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat</button></form>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>
