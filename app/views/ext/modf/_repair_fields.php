<?php /* Pole formuláře opravy: $r. */ ?>
<label>Oprava <input type="text" name="title" value="<?= e($r['title']) ?>" required maxlength="191" placeholder="Např. Výměna střešní krytiny"></label>
<div class="form-row">
  <label>Rok <input type="number" name="year" value="<?= (int) $r['year'] ?>" min="<?= (int) date('Y') - 30 ?>" max="<?= (int) date('Y') + 50 ?>" required></label>
  <label>Odhad nákladů (Kč) <input type="text" name="cost" inputmode="decimal" value="<?= e($r['cost'] === '' ? '' : (string) (int) $r['cost']) ?>" required></label>
</div>
<div class="form-row">
  <label>Priorita <select name="priority"><?php foreach (MODF_PRIORITY as $k => $label): ?><option value="<?= $k ?>"<?= (int) $r['priority'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
  <label>Stav <select name="status"><?php foreach (MODF_REPAIR_STATUS as $k => $label): ?><option value="<?= $k ?>"<?= $r['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
</div>
<label>Poznámka <textarea name="note" rows="2" maxlength="2000"><?= e($r['note']) ?></textarea></label>
