<div class="wrap page narrow">
  <h1>Tým</h1>
  <p class="lead">Pracovníci firmy <?= e($partner['name']) ?> s přístupem do portálu. Každý má vlastní přihlášení; ve webech domů je u změn vidět jeho jméno.</p>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <table class="table"><thead><tr><th>Jméno</th><th>E-mail</th><th>Role</th><th>Poslední přihlášení</th><th></th></tr></thead><tbody>
    <?php foreach ($people as $p): ?><tr<?= $p['active'] ? '' : ' class="is-off"' ?>>
      <td data-label="Jméno"><strong><?= e($p['name']) ?></strong><?= $p['totp_enabled'] ? ' <span class="tag">2FA</span>' : '' ?></td>
      <td data-label="E-mail"><?= e($p['email']) ?></td>
      <td data-label="Role"><?= $p['role'] === 'owner' ? 'správce účtu' : 'pracovník' ?></td>
      <td data-label="Poslední přihlášení"><?= !$p['active'] ? 'vypnuto' : ($p['invite_hash'] !== '' ? 'čeká na přijetí pozvánky' : ($p['last_login_at'] ? cz_datetime($p['last_login_at']) : 'zatím ne')) ?></td>
      <td class="t-actions"><?php if ($owner && $p['active'] && (int) $p['id'] !== (int) $me['id']): ?><form method="post" class="inline-form" data-confirm="Vypnout účet <?= e($p['name']) ?>? Do portálu ani do domů se už nepřihlásí."><?= csrf_field() ?><input type="hidden" name="action" value="disable"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="link-btn" type="submit">vypnout</button></form><?php endif ?></td>
    </tr><?php endforeach ?>
  </tbody></table>
  <?php if ($owner): ?>
  <form method="post" class="card form"><h2>Pozvat kolegu</h2><?= csrf_field() ?><input type="hidden" name="action" value="invite">
    <div class="form-row"><label>Jméno a příjmení <input type="text" name="name" required></label><label>E-mail <input type="email" name="email" required></label></div>
    <label>Role <select name="role"><option value="member">pracovník – spravuje domy</option><option value="owner">správce účtu – navíc zve a vypíná kolegy</option></select></label>
    <p class="hint">Kolega dostane e-mail s odkazem na nastavení hesla (platí 7 dní).</p>
    <button class="btn" type="submit">Poslat pozvánku</button></form>
  <?php else: ?><p class="hint">Kolegy zve a vypíná správce účtu firmy.</p><?php endif ?>
</div>
