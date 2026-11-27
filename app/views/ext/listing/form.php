<?php
$isNew = !(int) $l['id'];
$canPublish = in_array($l['status'], ['draft', 'expired'], true);
$area = $l['area'] ?? null;
$areaValue = is_numeric($area) ? str_replace('.', ',', (string) (float) $area) : (string) $area;
$outdoor = listing_outdoor_type($l);
$oArea = $l['outdoor_area'] ?? null;
$outdoorValue = is_numeric($oArea) ? str_replace('.', ',', (string) (float) $oArea) : (string) $oArea;
$aiFieldsListing = listing_ai_fields();   // vč. vybavení a příslušenství (app/ext/listing_lib.php)
// Vybavení a příslušenství: uložené údaje; po chybě ve formuláři textová pole tak, jak je vlastník napsal.
$feat = listing_features($l);
$fPost = is_array($l['features_post'] ?? null) ? $l['features_post'] : null;
$fText = function (string $k, string $stored) use ($fPost): string {
    return $fPost !== null ? (is_string($fPost[$k] ?? null) ? $fPost[$k] : '') : $stored;
};
$fCheck = fn(string $k) => !empty($feat[$k]) ? ' checked' : '';
$fSelect = function (string $k) use ($feat): void {
    echo '<select name="' . e(listing_feature_name($k)) . '"><option value="">– neuvedeno –</option>';
    foreach (LISTING_FEATURES[$k]['options'] as $v => $label) {
        echo '<option value="' . e($v) . '"' . (($feat[$k] ?? '') === $v ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select>';
};
$sklepArea = isset($feat['sklep_plocha']) ? str_replace('.', ',', (string) (float) $feat['sklep_plocha']) : '';
$moveDate = isset($feat['nastehovani']) && $feat['nastehovani'] !== 'ihned' ? $feat['nastehovani'] : '';
?>
<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/inzeraty/moje') ?>">← Moje inzeráty</a></p>
  <header class="page-head"><h1><?php if ($isNew): ?><span>Nový inzerát</span><?php else: ?><span>Upravit inzerát</span><?php endif ?></h1>
    <p class="lead">Jednotka <?= e(unit_name($unit)) ?>. V inzerátu se ukáže jen ulice a město domu – číslo bytu, vchod ani Váš e-mail ne.</p></header>
  <?php if (!$isNew): $ic = listing_inquiry_counts((int) $l['id']); ?><p class="lst-inq-badge"><a class="btn btn-small<?= $ic['new'] ? '' : ' btn-ghost' ?>" href="<?= url('/inzeraty/' . $l['id'] . '/zajemci') ?>"><?= e(listing_inquiry_label($ic['new'], $ic['total'])) ?></a></p><?php endif ?>
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
      <label>Podlahová plocha bytu (m²) <input type="text" name="area" inputmode="decimal" value="<?= e($areaValue) ?>" maxlength="10" placeholder="Např. 54,3"></label>
      <label>Patro <input type="text" name="floor" value="<?= e($l['floor']) ?>" maxlength="10"></label>
      <label>Jméno u inzerátu <input type="text" name="contact_name" value="<?= e($l['contact_name']) ?>" maxlength="120" required></label>
    </div>
    <div class="form-row">
      <label>Balkon / lodžie <select name="outdoor_type" data-lst-outdoor><?php foreach (LISTING_OUTDOOR as $k => $label): ?><option value="<?= $k ?>"<?= $outdoor === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
      <label class="lst-outdoor-area">Plocha balkonu / lodžie (m²) <input type="text" name="outdoor_area" inputmode="decimal" value="<?= e($outdoorValue) ?>" maxlength="10" placeholder="Např. 9,7"></label>
    </div>
    <p class="hint">Plocha bytu je podlahová plocha bez balkonu a lodžie – ty uveďte zvlášť, v inzerátu se ukážou vedle sebe.</p>
    <p class="hint">Plochu a patro jsme předvyplnili z evidence výboru. Zájemci Vám píší přes formulář na webu – zprávy chodí na Váš e-mail.</p>

    <fieldset class="lst-features-field">
      <legend>Vybavení a příslušenství <small>(nepovinné)</small></legend>
      <p class="hint">Vyplňte, co platí. V inzerátu se ukáže jako přehled s fajfkami; co nevyplníte, se nezobrazí.</p>
      <fieldset class="lst-fgroup">
        <legend>Příslušenství</legend>
        <div class="lst-checks">
          <label class="check"><input type="checkbox" name="features[sklep]" value="1"<?= $fCheck('sklep') ?>> <span>Sklep</span></label>
          <label class="check"><input type="checkbox" name="features[komora]" value="1"<?= $fCheck('komora') ?>> <span>Komora / půda</span></label>
          <label class="check"><input type="checkbox" name="features[kolarna]" value="1"<?= $fCheck('kolarna') ?>> <span>Kolárna</span></label>
        </div>
        <div class="form-row">
          <label data-lst-needs="features[sklep]">Plocha sklepa (m²) <input type="text" name="features[sklep_plocha]" inputmode="decimal" value="<?= e($fText('sklep_plocha', $sklepArea)) ?>" maxlength="10" placeholder="Např. 2,5"></label>
          <label>Parkování <?php $fSelect('parkovani') ?></label>
          <label data-lst-needs="features[parkovani]"><span>Příplatek za parkování</span> <span data-lst-for="pronajem">(Kč / měsíc)</span><span data-lst-for="prodej">(Kč)</span>
            <input type="text" name="features[parkovani_cena]" inputmode="numeric" value="<?= e($fText('parkovani_cena', isset($feat['parkovani_cena']) ? (string) $feat['parkovani_cena'] : '')) ?>" maxlength="12" placeholder="V ceně = nevyplňujte"></label>
        </div>
      </fieldset>
      <fieldset class="lst-fgroup">
        <legend>Dům</legend>
        <div class="form-row">
          <label>Výtah <?php $fSelect('vytah') ?></label>
          <label class="check lst-check-inline"><input type="checkbox" name="features[bezbarierovy]" value="1"<?= $fCheck('bezbarierovy') ?>> <span>Bezbariérový přístup</span></label>
        </div>
      </fieldset>
      <fieldset class="lst-fgroup">
        <legend>Stav a vybavení bytu</legend>
        <div class="form-row">
          <label>Stav bytu <?php $fSelect('stav') ?></label>
          <label>Zařízení <?php $fSelect('zarizeni') ?></label>
        </div>
        <div class="form-row">
          <label>Vytápění <?php $fSelect('vytapeni') ?></label>
          <label>Připojení k internetu <?php $fSelect('internet') ?></label>
        </div>
        <p class="lst-flabel">Spotřebiče</p>
        <div class="lst-checks">
          <?php foreach (LISTING_FEATURES['spotrebice']['options'] as $k => $label): ?><label class="check"><input type="checkbox" name="features[spotrebice][]" value="<?= e($k) ?>"<?= in_array($k, $feat['spotrebice'] ?? [], true) ? ' checked' : '' ?>> <span><?= e($label) ?></span></label><?php endforeach ?>
        </div>
      </fieldset>
      <fieldset class="lst-fgroup" data-lst-for="prodej">
        <legend>Prodej</legend>
        <div class="form-row">
          <label>Vlastnictví <?php $fSelect('vlastnictvi') ?></label>
        </div>
      </fieldset>
      <fieldset class="lst-fgroup" data-lst-for="pronajem">
        <legend>Pronájem</legend>
        <div class="form-row">
          <label>Kauce (Kč) <input type="text" name="features[kauce]" inputmode="numeric" value="<?= e($fText('kauce', isset($feat['kauce']) ? (string) $feat['kauce'] : '')) ?>" maxlength="12" placeholder="Např. 37 000"></label>
          <label>Minimální doba nájmu (měsíce) <input type="text" name="features[min_doba]" inputmode="numeric" value="<?= e($fText('min_doba', isset($feat['min_doba']) ? (string) $feat['min_doba'] : '')) ?>" maxlength="3" placeholder="Např. 12"></label>
          <label>Domácí zvířata <?php $fSelect('zvirata') ?></label>
        </div>
        <div class="form-row lst-move">
          <label>K nastěhování od <input type="date" name="features[nastehovani]" value="<?= e($fText('nastehovani', $moveDate)) ?>" data-lst-move-date></label>
          <label class="check lst-check-inline"><input type="checkbox" name="features[ihned]" value="1"<?= ($feat['nastehovani'] ?? '') === 'ihned' ? ' checked' : '' ?> data-lst-move-now> <span>ihned</span></label>
        </div>
      </fieldset>
    </fieldset>

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
