<?php
$isNew = !(int) $l['id'];
$canPublish = in_array($l['status'], ['draft', 'expired'], true);
$area = $l['area'] ?? null;
$areaValue = is_numeric($area) ? str_replace('.', ',', (string) (float) $area) : (string) $area;
$aiFieldsListing = ['type' => 'Typ inzerátu', 'layout' => 'Dispozice', 'area' => 'Plocha (m²)', 'floor' => 'Patro', 'price' => 'Cena v Kč', 'fees' => 'Poplatky v Kč za měsíc', 'energy' => 'Energetická třída'];
?>
<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/inzeraty/moje') ?>">← Moje inzeráty</a></p>
  <header class="page-head"><h1><?php if ($isNew): ?><span>Nový inzerát</span><?php else: ?><span>Upravit inzerát</span><?php endif ?></h1>
    <p class="lead">Jednotka <?= e(unit_name($unit)) ?>. V inzerátu se ukáže jen ulice a město domu – číslo bytu, vchod ani Váš e-mail ne.</p></header>
  <?php require __DIR__ . '/../../_errors.php'; ?>
  <form method="post" enctype="multipart/form-data" class="card form lst-form">
    <?= csrf_field() ?>
    <div class="form-row">
      <label>Typ inzerátu <select name="type" data-lst-type><?php foreach (LISTING_TYPES as $k => $label): ?><option value="<?= $k ?>"<?= $l['type'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
      <label>Dispozice <select name="layout" required><option value="">– vyberte –</option><?php foreach (LISTING_LAYOUTS as $k): ?><option value="<?= e($k) ?>"<?= $l['layout'] === $k ? ' selected' : '' ?>><?= $k === 'jine' ? 'jiná' : e($k) ?></option><?php endforeach ?></select></label>
    </div>
    <div class="form-row">
      <label>Cena (Kč) <input type="text" name="price" inputmode="numeric" value="<?= e((string) $l['price'] === '0' ? '' : (string) $l['price']) ?>" maxlength="14" required placeholder="Např. 4 250 000"></label>
      <label class="lst-fees">Poplatky za měsíc (Kč) <input type="text" name="fees" inputmode="numeric" value="<?= e((int) $l['fees'] > 0 ? (string) $l['fees'] : '') ?>" maxlength="10" placeholder="Např. 3 500"></label>
    </div>
    <p class="hint">U pronájmu je cena nájemné za měsíc; poplatky (zálohy na služby a energie) jsou nepovinné.</p>
    <div class="form-row">
      <label>Plocha (m²) <input type="text" name="area" inputmode="decimal" value="<?= e($areaValue) ?>" maxlength="10" placeholder="Např. 54,3"></label>
      <label>Patro <input type="text" name="floor" value="<?= e($l['floor']) ?>" maxlength="10"></label>
      <label>Jméno u inzerátu <input type="text" name="contact_name" value="<?= e($l['contact_name']) ?>" maxlength="120" required></label>
    </div>
    <p class="hint">Plochu a patro jsme předvyplnili z evidence výboru. Zájemci Vám píší přes formulář na webu – zprávy chodí na Váš e-mail.</p>

    <fieldset class="lst-energy-field">
      <legend>Energetická náročnost</legend>
      <?php if ($penb['valid']): ?>
        <input type="hidden" name="energy" value="<?= e($penb['class']) ?>">
        <p><span class="penb penb-<?= e(strtolower($penb['class'])) ?>"><?= e($penb['class']) ?></span> <span>Průkaz budovy, platí do <?= e(cz_date($penb['until'])) ?>. Třída se v inzerátu uvede sama.</span></p>
      <?php else: ?>
        <label>Energetická třída bytu <select name="energy">
          <option value="">– vyberte –</option>
          <?php foreach (LISTING_CLASSES as $c): ?><option value="<?= $c ?>"<?= $l['energy_class'] === $c && $l['energy_source'] === 'vlastni' ? ' selected' : '' ?>><?= $c ?></option><?php endforeach ?>
          <option value="nema"<?= $l['energy_source'] === 'nema' ? ' selected' : '' ?>>Průkaz nemám</option>
        </select></label>
        <?php /* NEOVĚŘENO, ověřte s právníkem: povinnost podle zákona č. 406/2000 Sb. (§ 7a) – viz listing_input() v app/ext/listing_lib.php. */ ?>
        <div class="callout"><p><strong>Inzerát by měl uvádět energetickou třídu.</strong> Zákon o hospodaření energií vyžaduje uvádět v inzerátech na prodej i pronájem ukazatel z průkazu energetické náročnosti (budovy nebo bytu).
          Výbor zatím platný průkaz budovy na web nezadal – zeptejte se ho, nebo si nechte zpracovat průkaz bytu.</p></div>
      <?php endif ?>
    </fieldset>

    <label>Popis bytu <textarea name="description" rows="10" maxlength="8000" required placeholder="Stav bytu, vybavení, sklep, kdy je volný, co je v okolí…"><?= e($l['description']) ?></textarea></label>
    <?php $aiShow = ai_resident_available(); $aiUrl = url('/ai-text'); $aiTarget = 'textarea[name=description]'; $aiTasks = ['listing', 'polish', 'shorten']; $aiContext = listing_ai_context(); $aiFields = $aiFieldsListing; require __DIR__ . '/../../_ai_mini.php'; ?>
    <?php if (ai_resident_available()): ?><p class="hint">„Napsat inzerát“: do pole napište pár poznámek (stav, vybavení, okolí) a pomocník z nich a z údajů výše napíše text. Co v poznámkách chybí, označí [doplnit: …].</p><?php endif ?>

    <label>Krátký text pro sdílení (nepovinné) <textarea name="share_text" rows="4" maxlength="1000" placeholder="Použije se v textu pro Bazoš a skupiny na Facebooku. Nevyplněno = začátek popisu."><?= e($l['share_text'] ?? '') ?></textarea></label>
    <?php $aiTarget = 'textarea[name=share_text]'; $aiTasks = ['fb']; $aiFields = $aiFieldsListing + ['description' => 'Text inzerátu']; require __DIR__ . '/../../_ai_mini.php'; ?>

    <fieldset>
      <legend>Fotky (<?= LISTING_PHOTOS_MIN ?>–<?= LISTING_PHOTOS_MAX ?>)</legend>
      <?php if ($photos): ?>
        <ul class="lst-photo-edit">
          <?php foreach ($photos as $i => $p): ?>
            <li><img src="<?= url(listing_photo_url($p, true)) ?>" alt="<?= e(listing_photo_alt($p, $i, count($photos))) ?>" width="160" height="120">
              <label class="lst-caption-field"><span class="sr-only">Popis fotky</span><input type="text" name="photo_caption[<?= (int) $p['id'] ?>]" value="<?= e((string) ($p['caption'] ?? '')) ?>" maxlength="<?= LISTING_CAPTION_MAX ?>" placeholder="Popis fotky, např. Kuchyň"></label>
              <label class="check"><input type="radio" name="cover" value="<?= $p['id'] ?>"<?= $i === 0 ? ' checked' : '' ?>> <span>Úvodní</span></label>
              <label class="check"><input type="checkbox" name="photo_delete[]" value="<?= $p['id'] ?>"> <span>Smazat</span></label></li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
      <?php if (count($photos) < LISTING_PHOTOS_MAX): ?>
        <label>Přidat fotky <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple data-shrink-photos></label>
        <p class="hint">JPG, PNG nebo WebP. Fotky zmenšíme a odstraníme z nich skryté údaje (např. polohu GPS z telefonu). Úvodní fotka se ukáže při sdílení.</p>
      <?php endif ?>
    </fieldset>

    <?php if ($isNew || $l['status'] === 'draft'): ?>
      <label class="check"><input type="checkbox" name="neighbours_first"<?= (int) $l['neighbours_first'] ? ' checked' : '' ?>> <span>Nejdřív sousedé <small>Prvních <?= LISTING_NEIGHBOURS_DAYS ?> dny uvidí inzerát jen přihlášení obyvatelé domu, potom se sám zveřejní pro všechny.</small></span></label>
    <?php endif ?>
    <div class="actions">
      <?php if ($canPublish): ?>
        <button class="btn btn-big" type="submit" name="action" value="publish">Uložit a zveřejnit</button>
        <button class="btn btn-ghost" type="submit" name="action" value="save">Uložit koncept</button>
      <?php else: ?>
        <button class="btn btn-big" type="submit" name="action" value="save">Uložit změny</button>
      <?php endif ?>
      <a class="btn btn-ghost" href="<?= url('/inzeraty/moje') ?>">Zrušit</a>
    </div>
    <?php if ($canPublish): ?><p class="hint">Zveřejněný inzerát je na webu <?= LISTING_DAYS ?> dní; týden před koncem Vám pošleme e-mail s odkazem na prodloužení. Výbor dostane upozornění, že jste inzerát zveřejnili.</p><?php endif ?>
  </form>
</div>
