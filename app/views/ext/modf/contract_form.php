<?php /* Smlouva nebo pojištění – formulář. $c, $id, $suppliers, $errors. */
$ins = $c['kind'] === 'insurance'; ?>
<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/admin/smlouvy') ?>">← Smlouvy a pojištění</a></p>
  <h1><?= e($title) ?></h1>
  <?php require __DIR__ . '/../../_errors.php'; ?>
  <form method="post" enctype="multipart/form-data" class="card form">
    <?= csrf_field() ?>
    <fieldset class="check-list"><legend>Druh</legend>
      <?php foreach (MODF_CONTRACT_KINDS as $k => $label): ?><label class="check"><input type="radio" name="kind" value="<?= $k ?>"<?= $c['kind'] === $k ? ' checked' : '' ?>> <?= e($label) ?></label><?php endforeach ?></fieldset>
    <label>Předmět <input type="text" name="subject" value="<?= e($c['subject']) ?>" required maxlength="255" placeholder="<?= $ins ? 'Např. Pojištění domu (majetek a odpovědnost)' : 'Např. Úklid společných prostor' ?>"></label>
    <div class="form-row">
      <?php if ($suppliers): ?><label>Dodavatel z evidence <select name="supplier_id"><option value="0">– jiný (vyplňte vedle) –</option><?php foreach ($suppliers as $sid => $sname): ?><option value="<?= (int) $sid ?>"<?= (int) $c['supplier_id'] === (int) $sid ? ' selected' : '' ?>><?= e($sname) ?></option><?php endforeach ?></select></label><?php endif ?>
      <label><?= $suppliers ? 'nebo jiná smluvní strana / pojišťovna' : 'Smluvní strana / pojišťovna' ?> <input type="text" name="party" value="<?= e($c['party']) ?>" maxlength="191"></label>
    </div>
    <label>Číslo smlouvy / pojistky <input type="text" name="number" value="<?= e($c['number']) ?>" maxlength="80"></label>
    <div class="form-row">
      <label>Začátek <input type="date" name="start_date" value="<?= e($c['start_date']) ?>"></label>
      <label>Konec (nebo konec současného období) <input type="date" name="end_date" value="<?= e($c['end_date']) ?>"></label>
    </div>
    <p class="hint">Bez konce = smlouva na dobu neurčitou (termín se nehlídá).</p>
    <label class="check"><input type="checkbox" name="auto_renew" value="1"<?= (int) $c['auto_renew'] ? ' checked' : '' ?>> <span>Automaticky se prodlužuje, pokud ji nevypovíme <small>Web pak hlídá poslední den pro výpověď a po konci posune datum o další období.</small></span></label>
    <div class="form-row">
      <label>Prodlužuje se o (měsíců) <input type="number" name="renew_months" value="<?= (int) $c['renew_months'] ?>" min="1" max="120"></label>
      <label>Výpovědní lhůta <input type="number" name="notice_value" value="<?= (int) $c['notice_value'] ?>" min="0" max="999"></label>
      <label>&nbsp;<select name="notice_unit" aria-label="Jednotka výpovědní lhůty"><?php foreach (MODF_NOTICE_UNITS as $k => $label): ?><option value="<?= $k ?>"<?= $c['notice_unit'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
    </div>
    <?php if ($ins): ?><p class="hint">U pojištění bývá výpověď ke konci pojistného období s lhůtou 6 týdnů (§ 2157 občanského zákoníku), pokud smlouva neurčuje jinak.</p><?php endif ?>
    <div class="form-row">
      <label>Cena bez DPH (Kč) <input type="text" name="price" inputmode="decimal" value="<?= e((float) $c['price'] ? str_replace('.', ',', (string) $c['price']) : '') ?>"></label>
      <label>&nbsp;<select name="price_period" aria-label="Období ceny"><?php foreach (MODF_PRICE_PERIODS as $k => $label): ?><option value="<?= $k ?>"<?= $c['price_period'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
      <label>Připomenout dní předem <input type="number" name="remind_days" value="<?= (int) $c['remind_days'] ?>" min="1" max="365"></label>
    </div>
    <label>Dokument (PDF, sken) <input type="file" name="file" accept=".<?= implode(',.', ALLOWED_EXT) ?>"></label>
    <?php if ($c['filename'] !== ''): ?><p class="hint">Uloženo: <a href="<?= url('/admin/smlouvy/' . $id . '/soubor') ?>" target="_blank" rel="noopener"><?= e($c['original_name']) ?></a>. Nový soubor ho nahradí.
      <label class="check"><input type="checkbox" name="remove_file" value="1"> odstranit dokument</label></p><?php endif ?>
    <label>Poznámka <textarea name="note" rows="3" maxlength="3000"><?= e($c['note']) ?></textarea></label>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit</button><a class="btn btn-ghost" href="<?= url('/admin/smlouvy') ?>">Zrušit</a></div>
  </form>
  <?php if ($id): ?><form method="post" action="<?= url('/admin/smlouvy/' . $id . '/smazat') ?>" data-confirm="Smazat smlouvu i s dokumentem?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat smlouvu</button></form><?php endif ?>
</div>
