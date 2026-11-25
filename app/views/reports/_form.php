<?php /* Formulář hlášení: $back = kam se vrátit, $inDialog = vložen do vyskakovacího okna */ $anon = empty($GLOBALS['IS_PLATFORM']) ? !user() : true; $rid = !empty($inDialog) ? 'd' : 'p'; ?>
<form method="post" action="<?= url('/hlaseni') ?>" enctype="multipart/form-data" class="form report-form"<?= empty($GLOBALS['IS_PLATFORM']) && function_exists('user') && user() ? ' data-mine="' . e(url('/hlaseni')) . '"' : '' ?>>
  <?= csrf_field() ?>
  <input type="hidden" name="z" value="<?= e($back ?? '/') ?>">
  <input type="hidden" name="url" value="<?= e(post('url') !== '' ? post('url') : report_page_url($back ?? '/')) ?>" data-report-url>
  <input type="hidden" name="title" value="<?= e(post('title')) ?>" data-report-title>
  <input type="hidden" name="viewport" value="<?= e(post('viewport')) ?>" data-report-viewport>
  <?php if (empty($GLOBALS['IS_PLATFORM'])): $toFaults = user() && feature_on('zavady'); ?>
  <div class="report-scope"><strong>Tohle hlášení se týká jen webu</strong> – dostane ho <?= reports_central() ? 'provozovatel webové služby (technická podpora)' : 'správce tohoto webu' ?>, ne výbor ani správa domu.
    Závadu v domě (topení, výtah, světlo, úklid) sem prosím nepište: <a href="<?= url($toFaults ? '/zavady' : '/kontakty') ?>"><?= $toFaults ? 'nahlaste ji výboru v části Závady a požadavky' : 'kontakty na výbor a havarijní čísla najdete zde' ?></a>.</div>
  <?php endif ?>
  <div class="report-types" role="radiogroup" aria-label="Druh hlášení">
    <?php foreach (REPORT_TYPES as $k => $label): ?><label class="check"><input type="radio" name="type" value="<?= $k ?>"<?= post('type', 'bug') === $k ? ' checked' : '' ?>> <span><?= e($label) ?></span></label><?php endforeach ?>
  </div>
  <label>Co na webu nefunguje nebo co navrhujete? <textarea name="body" rows="5" required minlength="5" maxlength="5000"><?= e(post('body')) ?></textarea></label>
  <?php if ($anon): ?><label>Váš e-mail (pro odpověď) <input type="email" name="email" value="<?= e(post('email')) ?>" required autocomplete="email"></label><?php endif ?>
  <p class="hp" aria-hidden="true"><label>Web <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
  <div class="report-shot">
    <button class="btn btn-small btn-ghost" type="button" data-report-capture hidden>Pořídit snímek stránky</button>
    <label class="report-file">nebo přiložit obrázek <input type="file" name="screenshot" accept="image/png,image/jpeg" data-report-file></label>
    <div class="report-preview" data-report-preview hidden><span>Snímek je přiložen.</span> <button class="btn btn-small btn-ghost" type="button" data-report-clear>Odebrat snímek</button></div>
  </div>
  <p class="hint">S hlášením se odešle adresa stránky, na které právě jste, a typ prohlížeče. Na snímku nenechávejte údaje, které nechcete sdílet.</p>
  <div class="actions"><button class="btn" type="submit">Odeslat hlášení</button><?php if (!empty($inDialog)): ?><button class="btn btn-ghost" type="button" data-report-close>Zavřít</button><?php endif ?></div>
</form>
