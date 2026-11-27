<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/admin/jednotky') ?>">← Jednotky</a></p>
  <h1><?= e($title) ?></h1>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <div class="form-row">
      <label>Vchod <select name="entrance"><?php foreach (entrances() as $en): ?><option value="<?= e($en) ?>"<?= $unit['entrance'] === $en ? ' selected' : '' ?>><?= $en === '' ? '—' : e(unit_prefix() . ' ' . $en) ?></option><?php endforeach ?></select></label>
      <label>Číslo jednotky <input type="text" name="number" value="<?= e($unit['number']) ?>" maxlength="10" required></label>
      <label>Patro <input type="text" name="floor" value="<?= e($unit['floor']) ?>" maxlength="10"></label>
    </div>
    <div class="form-row">
      <label>Spoluvlastnický podíl <input type="text" name="share" value="<?= e($unit['share']) ?>" placeholder="Např. 652/100000"></label>
      <label>Plocha (m²) <input type="text" name="area" inputmode="decimal" value="<?= e(is_numeric($unit['area'] ?? null) ? str_replace('.', ',', (string) (float) $unit['area']) : (string) ($unit['area'] ?? '')) ?>" maxlength="10" placeholder="Např. 54,3"></label>
    </div>
    <p class="hint">Podlahová plocha jednotky (nepovinné). Vlastník ji vidí ve svém účtu a předvyplní se mu do inzerátu; měnit ji může jen výbor.</p>
    <label>Vlastník dle evidence <input type="text" name="owner_note" value="<?= e($unit['owner_note']) ?>" placeholder="Např. Novákovi (SJM)"></label>
    <p class="hint">Jméno vlastníka podle katastru nebo evidence SVJ. Vyplňte i tehdy, když vlastník účet na webu nemá.</p>
    <label>Adresa pro doručování <textarea name="owner_contact" rows="3" placeholder="Jméno a adresa každého vlastníka na samostatném řádku"><?= e($unit['owner_contact']) ?></textarea></label>
    <p class="hint">Jméno a adresa vlastníků podle katastru (doplní je import z katastru). Vidí je jen správci webu; slouží pro seznam členů a doručování.</p>
    <label>Poznámka <input type="text" name="note" value="<?= e($unit['note']) ?>"></label>
    <fieldset>
      <legend>Účty přiřazené k jednotce</legend>
      <?php if (!$users): ?><p class="hint">Zatím nejsou žádné účty.</p><?php else: ?>
      <label>Hledat uživatele <input type="search" data-filter="#user-pick" placeholder="Jméno nebo e-mail…"></label>
      <div class="pick" id="user-pick">
        <?php foreach ($users as $x): ?>
          <label class="check"><input type="checkbox" name="users[]" value="<?= $x['id'] ?>"<?= in_array((int) $x['id'], $userIds, true) ? ' checked' : '' ?>>
            <span><?= e($x['name']) ?> <small><?= e($x['email']) ?> · <?= mb_strtolower(KINDS[$x['kind']]) ?><?= $x['active'] ? '' : ' · vypnutý' ?></small></span></label>
        <?php endforeach ?>
      </div>
      <?php endif ?>
    </fieldset>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit jednotku</button><a class="btn btn-ghost" href="<?= url('/admin/jednotky') ?>">Zrušit</a></div>
  </form>
</div>
