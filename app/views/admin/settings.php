<div class="wrap page narrow">
  <header class="page-head"><h1>Nastavení webu</h1></header>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <label>Úvodní text na hlavní stránce <textarea name="intro" rows="4"><?= e(setting('intro')) ?></textarea></label>
    <label>Adresa domu <input type="text" name="address" value="<?= e(setting('address')) ?>"></label>
    <div class="form-row">
      <label>IČ <input type="text" name="ico" value="<?= e(setting('ico')) ?>"></label>
      <label>Číslo účtu <input type="text" name="account" value="<?= e(setting('account')) ?>"></label>
    </div>
    <label>Pravidla havarijního servisu (stránka Kontakty) <textarea name="emergency_note" rows="5"><?= e(setting('emergency_note')) ?></textarea></label>
    <label>Úvodní text diskuze <textarea name="forum_note" rows="3"><?= e(setting('forum_note')) ?></textarea></label>
    <label>E-mail správce webu (zobrazí se v patičce, nepovinné) <input type="email" name="webmaster_email" value="<?= e(setting('webmaster_email')) ?>"></label>
    <fieldset>
      <legend>Rozesílání e-mailů</legend>
      <div class="form-row">
        <label>Adresa odesílatele <input type="email" name="mail_from" value="<?= e(setting('mail_from')) ?>" placeholder="vybor@vase-domena.cz"></label>
        <label>Jméno odesílatele <input type="text" name="mail_from_name" value="<?= e(setting('mail_from_name', 'SVJ Tábor 48')) ?>"></label>
      </div>
      <p class="hint">Použijte adresu na doméně webu (zřídíte ji u poskytovatele hostingu). Zprávy odeslané „z“ adresy u Seznamu, Centra nebo Gmailu většinou skončí ve spamu. Na tuto adresu také přijdou odpovědi.</p>
    </fieldset>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit nastavení</button></div>
  </form>
</div>
