<?php /* Můj účet: zapnutí týdenního přehledu ($u = přihlášený uživatel). Vkládá app/views/account.php. */
if (!function_exists('digest_enabled')) {
    return;
}
$dgOn = digest_enabled($u); ?>
  <section class="household" id="tydenni-prehled">
    <h2>Týdenní přehled e-mailem</h2>
    <form method="post" action="<?= url('/muj-ucet/tydenni-prehled') ?>" class="card form">
      <?= csrf_field() ?>
      <p>Jednou týdně (v pátek) shrnutí novinek: nové aktuality a dokumenty, termíny na 14 dní dopředu, hlasování a ankety, na které jste ještě neodpověděli, a stav Vašich hlášení. Když se nic nového nestalo, e-mail nepřijde.</p>
      <label class="check"><input type="checkbox" name="digest"<?= $dgOn ? ' checked' : '' ?>> <span>Posílat mi týdenní přehled <small>Vlastníkům jednotek ho posíláme automaticky, nájemníci si ho mohou zapnout.</small></span></label>
      <?php if ($u['role'] === 'admin'): ?>
        <fieldset><legend>Pro celý dům (jen správci webu)</legend>
          <label class="check"><input type="radio" name="house" value="on"<?= digest_house_on() ? ' checked' : '' ?>> <span>Rozesílat přehled obyvatelům</span></label>
          <label class="check"><input type="radio" name="house" value="off"<?= digest_house_on() ? '' : ' checked' ?>> <span>Nerozesílat nikomu</span></label>
        </fieldset>
      <?php endif ?>
      <?php if (!moda_mail_ready()): ?><p class="hint">Web zatím neumí posílat e-maily (chybí adresa odesílatele v Nastavení), proto přehled zatím neodchází.</p><?php endif ?>
      <button class="btn" type="submit">Uložit</button>
    </form>
  </section>
