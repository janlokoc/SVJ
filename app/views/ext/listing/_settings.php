<?php /* Sekce v Nastavení webu (app/views/admin/settings.php): průkaz energetické náročnosti budovy a inzeráty vlastníků. Vlastní formulář (soubor PDF). */
$penb = listing_penb();
if (isset($_SESSION['penb_form'])) {   // po chybě ukázat, co správce vyplnil
    $penb = array_merge($penb, array_intersect_key((array) $_SESSION['penb_form'], ['class' => 1, 'until' => 1]));
    unset($_SESSION['penb_form']);
}
$penbDoc = (int) setting('penb_doc_id') ? one('SELECT * FROM documents WHERE id = ?', [(int) setting('penb_doc_id')]) : null;
$penbFile = preg_match('/^[a-f0-9]{32}\.pdf$/', setting('penb_file')) ? setting('penb_file') : '';
?>
<form method="post" action="<?= url('/admin/nastaveni/inzeraty') ?>" enctype="multipart/form-data" class="card form" id="penb">
  <?= csrf_field() ?>
  <fieldset>
    <legend>Průkaz energetické náročnosti budovy (PENB)</legend>
    <div class="form-row">
      <label>Energetická třída budovy <select name="penb_class"><option value="">nezadáno</option><?php foreach (LISTING_CLASSES as $c): ?><option value="<?= $c ?>"<?= $penb['class'] === $c ? ' selected' : '' ?>><?= $c ?></option><?php endforeach ?></select></label>
      <label>Platí do <input type="date" name="penb_valid_until" value="<?= e($penb['until']) ?>"></label>
    </div>
    <p class="hint">Třídu (A–G) a datum platnosti najdete na první straně průkazu. Vlastníkům se třída sama doplní do inzerátů. 90 dní před koncem platnosti pošleme správcům připomínku.</p>
    <?php if ($penb['class'] !== '' && $penb['until'] !== '' && !$penb['valid']): ?><p class="callout">Průkaz skončil <?= e(cz_date($penb['until'])) ?>. Vlastníci teď v inzerátech uvádějí třídu sami.</p><?php endif ?>
    <?php if ($penbDoc): ?><p>Nahraný průkaz: <a href="<?= url('/soubor/' . $penbDoc['id']) ?>"><?= e($penbDoc['original_name']) ?></a> <small class="muted">(v Dokumentech pro přihlášené obyvatele)</small></p>
    <?php elseif ($penbFile !== ''): ?><p>Nahraný průkaz: <a href="<?= url('/admin/nastaveni/penb') ?>"><?= e(setting('penb_file_name')) ?></a> <small class="muted">(vidí jen správci)</small></p><?php endif ?>
    <label>Průkaz v PDF (nepovinné) <input type="file" name="penb_pdf" accept="application/pdf,.pdf"></label>
    <label class="check"><input type="checkbox" name="penb_offer"<?= $penbFile === '' ? ' checked' : '' ?>> <span>Nabídnout průkaz vlastníkům v Dokumentech <small>Bude vidět přihlášeným obyvatelům (v kategorii Revize a technická dokumentace) – hodí se vlastníkům, kteří byt prodávají nebo pronajímají.</small></span></label>
    <?php if ($penbDoc || $penbFile !== ''): ?><label class="check"><input type="checkbox" name="penb_remove"> <span>Smazat nahraný průkaz</span></label><?php endif ?>
  </fieldset>
  <fieldset>
    <legend>Inzeráty vlastníků</legend>
    <p class="hint">Inzeráty jsou <?= listing_enabled() ? 'zapnuté' : '<strong>vypnuté</strong>' ?>. Zapínají a vypínají se výše v části <a href="#menu">Menu – co se na webu zobrazuje</a> (položka Inzeráty).</p>
    <label>Lokalita v inzerátech <input type="text" name="listing_locality" value="<?= e(setting('listing_locality')) ?>" maxlength="100" placeholder="<?= e(listing_locality_from(setting('address'))) ?>"></label>
    <p class="hint">Inzerát ukazuje jen ulici a město domu (nikdy číslo bytu ani vchod). Nevyplněno = podle adresy domu: <?= e(listing_locality_from(setting('address')) ?: '—') ?>.</p>
  </fieldset>
  <div class="actions section-save"><button class="btn" type="submit">Uložit</button></div>
</form>
