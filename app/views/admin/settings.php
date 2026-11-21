<div class="wrap page narrow">
  <header class="page-head"><h1>Nastavení webu</h1></header>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <label>Adresa webu <input type="url" name="site_url" value="<?= e(setting('site_url')) ?>" placeholder="https://www.vase-domena.cz"></label>
    <p class="hint">Používá se pro odkazy v e-mailech a pozvánkách. Po přestěhování webu na jinou adresu ji zde změňte.</p>
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
      <label class="check"><input type="checkbox" name="mail_tracking"<?= setting_on('mail_tracking') ? ' checked' : '' ?>> <span>Evidovat, kdo zprávu otevřel a kdo klikl na odkaz <small>Příjemci jsou o tom informováni v patičce každé zprávy.</small></span></label>
    </fieldset>
    <fieldset>
      <legend>Pozvánky členů domácnosti</legend>
      <label class="check"><input type="checkbox" name="invite_enabled"<?= setting_on('invite_enabled') ? ' checked' : '' ?>> Vlastníci mohou sami pozvat manžela, manželku nebo spoluvlastníka</label>
      <label class="check"><input type="checkbox" name="invite_by_tenants"<?= setting_on('invite_by_tenants', false) ? ' checked' : '' ?>> <span>Zvát mohou i nájemníci <small>Člověk pozvaný nájemníkem je vždy veden jako nájemník.</small></span></label>
      <div class="form-row">
        <label>Pozvaný vlastníkem je veden jako
          <select name="invite_kind"><?php foreach (KINDS as $k => $label): ?><option value="<?= $k ?>"<?= setting('invite_kind', 'owner') === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
        </label>
        <label>Nejvíc účtů na jednotku <input type="number" name="invite_max" min="1" max="20" value="<?= setting_int('invite_max', 6, 1, 20) ?>"></label>
        <label>Platnost pozvánky (dní) <input type="number" name="invite_days" min="1" max="90" value="<?= setting_int('invite_days', 14, 1, 90) ?>"></label>
      </div>
      <p class="hint">Vlastníci dostávají e-maily určené vlastníkům a mohou potvrzovat účast na shromáždění. U jednotlivého účtu to změníte v sekci Uživatelé.</p>
    </fieldset>
    <fieldset>
      <legend>Shromáždění</legend>
      <label>Usnášeníschopnost: je potřeba více než (% všech hlasů) <input type="number" name="quorum_percent" min="1" max="99" value="<?= setting_int('quorum_percent', 50, 1, 99) ?>"></label>
      <p class="hint">Obvykle 50 % (přítomna většina všech hlasů). Řiďte se stanovami.</p>
    </fieldset>
    <fieldset>
      <legend>Ochrana osobních údajů</legend>
      <label>Text stránky „Ochrana osobních údajů“ <textarea name="privacy_text" rows="12"><?= e(setting('privacy_text') !== '' ? setting('privacy_text') : PRIVACY_DEFAULT) ?></textarea></label>
      <p class="hint">Předvyplněný text je návrh odpovídající tomu, co web skutečně ukládá. Než ho zveřejníte obyvatelům, nechte ho projít výborem; nadpisy začínají dvěma křížky (## ).</p>
    </fieldset>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit nastavení</button></div>
  </form>
</div>
