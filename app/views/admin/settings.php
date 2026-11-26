<div class="wrap page narrow">
  <header class="page-head"><h1>Nastavení webu</h1><p class="lead"><a href="<?= url('/admin/data-domu') ?>">Export všech dat domu a smlouva o zpracování osobních údajů</a> · <a href="<?= url('/admin/plakat') ?>">Plakát do vchodu</a></p></header>
  <?php if (plan_managed()): $stUsed = plan_used('storage'); $stLimit = plan_limit('storage'); $stPct = $stLimit ? min(100, (int) round(100 * $stUsed / $stLimit)) : 0;
    $stFiles = (int) val('SELECT COUNT(*) FROM documents WHERE filename IS NOT NULL') + (int) val('SELECT COUNT(*) FROM ticket_files'); $hasBilling = ($GLOBALS['BILLING']['mode'] ?? 'free') !== 'free'; ?>
  <section class="card storage-card<?= $stLimit && $stPct >= 100 ? ' is-full' : ($stLimit && $stPct >= 80 ? ' is-near' : '') ?>" id="uloziste">
    <h2>Úložiště dokumentů</h2>
    <p>Využito <strong><?= number_format($stUsed, 0, ',', "\u{a0}") ?> MB<?= $stLimit ? ' z ' . number_format($stLimit, 0, ',', "\u{a0}") . ' MB' : '' ?></strong><?= $stLimit ? ' (' . $stPct . ' %)' : ' – bez omezení' ?> · <?= $stFiles ?> <?= $stFiles === 1 ? 'soubor' : ($stFiles >= 2 && $stFiles <= 4 ? 'soubory' : 'souborů') ?> (dokumenty a přílohy závad).</p>
    <?php if ($stLimit): ?><progress class="bar bar-wide" value="<?= min($stUsed, $stLimit) ?>" max="<?= $stLimit ?>"></progress><?php endif ?>
    <?php if ($stLimit && $stPct >= 100): ?><p><strong>Úložiště je plné.</strong> Další soubory nepůjdou nahrát, dokud neuvolníte místo nebo úložiště nezvětšíte. Web běží dál a nic se nemaže.</p>
    <?php elseif ($stLimit && $stPct >= 80): ?><p><strong>Místo dochází.</strong> Zbývá <?= max(0, $stLimit - $stUsed) ?> MB.</p><?php endif ?>
    <?php if ($stLimit && $hasBilling): ?><p class="actions"><a class="btn<?= $stPct >= 80 ? '' : ' btn-small btn-ghost' ?>" href="<?= url('/admin/predplatne') ?>#uloziste">Přikoupit úložiště navíc</a>
      <?php if (plan_edition() !== 'premium'): ?><a class="btn btn-small btn-ghost" href="<?= url('/admin/predplatne') ?>#edice">Vyšší edice s větším úložištěm</a><?php endif ?></p><?php endif ?>
    <?php if ($stExternal = (int) val('SELECT COUNT(*) FROM documents WHERE filename IS NULL AND external_url IS NOT NULL')): ?><p class="callout"><strong>Starý web zatím nerušte.</strong> <?= $stExternal ?> dokumentů je pořád jen odkazem na soubory na starém webu (proto se do úložiště nepočítají). Stáhněte je sem na <a href="<?= url('/admin') ?>">přehledu administrace</a> (Převod souborů ze starého webu), nebo nám napište a stáhneme je za Vás.</p><?php endif ?>
    <p class="actions"><a class="btn btn-small btn-ghost" href="<?= url('/admin/uvolnit-misto') ?>">Uvolnit místo</a></p>
    <p class="hint">Místo uvolníte promazáním starých příloh závad, zpráv a rozeslaných e-mailů (tlačítko výše) nebo smazáním nepotřebných souborů v <a href="<?= url('/admin/dokumenty') ?>">Dokumentech</a>. Fotografie u závad se před nahráním samy zmenšují.</p>
  </section>
  <?php endif ?>
  <?php
  // Každá sekce má vlastní tlačítko Uložit (uloží celý formulář); po uložení se stránka vrátí k té sekci.
  $saved = (string) ($_SESSION['settings_saved'] ?? '');
  unset($_SESSION['settings_saved']);
  $saveBtn = fn(string $id) => '<div class="actions section-save"><button class="btn" type="submit" name="sekce" value="' . $id . '">Uložit</button>'
      . ($saved === $id ? ' <span class="saved-note" role="status" data-scroll-into-view>Uloženo</span>' : '') . '</div>';
  ?>
  <form method="post" enctype="multipart/form-data" class="card form">
    <?= csrf_field() ?>
    <fieldset id="vzhled">
      <legend>Název a vzhled</legend>
      <label>Název společenství (v e-mailech a v záhlaví prohlížeče) <input type="text" name="site_name" value="<?= e(site('name')) ?>" required></label>
      <div class="form-row">
        <label>Krátký název na web <input type="text" name="site_title" value="<?= e(site('title')) ?>" required></label>
        <label>Doplněk názvu (nepovinné) <input type="text" name="site_accent" value="<?= e(site('accent')) ?>"></label>
      </div>
      <label>Celý název (patička, plná moc) <input type="text" name="site_full" value="<?= e(site('full')) ?>"></label>
      <label>Řádek nad názvem na úvodní stránce <input type="text" name="site_tagline" value="<?= e(site('tagline')) ?>"></label>
      <p class="h-small">Šablona</p>
      <?php if (plan_managed() && !plan_has('sablony')): ?><p class="callout">V edici <?= e(EDITIONS[plan_edition()]) ?> má web jeden pevný vzhled: šablonu Klasická a barvy Les, bez vlastního loga. Další šablony, barvy a logo jsou součástí edice Standard – <a href="<?= url('/admin/predplatne') ?>#funkce"><?= plan_state('sablony') === 'available' ? 'vyzkoušejte je na ' . PLAN_TRIAL_DAYS . ' dní zdarma' : 'zkouška už skončila, přejděte na Standard' ?></a>.</p><?php endif ?>
      <?php $plusOk = !plan_managed() || plan_has('vzhledy-plus'); if (!$plusOk && plan_has('sablony')): ?><p class="callout">Šablony a barvy se štítkem Premium patří do edice Premium – <a href="<?= url('/admin/predplatne') ?>#funkce"><?= plan_state('vzhledy-plus') === 'available' ? 'vyzkoušejte je na ' . PLAN_TRIAL_DAYS . ' dní zdarma' : 'přejděte na Premium' ?></a>. Vybrat je můžete i teď; web je začne používat, jakmile budou dostupné.</p><?php endif ?>
      <div class="look-cards" role="radiogroup" aria-label="Šablona webu">
        <?php foreach (STYLES as $style => $label): $look = isset(LOOKS[site('look')]) ? site('look') : 'les'; $miniName = site('title'); ?>
          <label class="look-card"><input type="radio" name="site_style" value="<?= $style ?>"<?= site('style') === $style ? ' checked' : '' ?>><?php require __DIR__ . '/../platform/_mini.php'; ?><span><?= e($label) ?><?= !$plusOk && in_array($style, PREMIUM_STYLES, true) ? ' <span class="plan-tag">Premium</span>' : '' ?></span></label>
        <?php endforeach ?>
      </div>
      <p class="h-small">Barvy</p>
      <div class="look-picker" role="radiogroup" aria-label="Barvy webu">
        <?php foreach (LOOKS as $k => $label): ?>
          <label class="look look-<?= $k ?>"><input type="radio" name="site_look" value="<?= $k ?>"<?= site('look') === $k ? ' checked' : '' ?>><span class="look-swatch" aria-hidden="true"><i></i><i></i><i></i></span><span><?= e($label) ?></span><?= plan_managed() && !plan_has('sablony') && $k !== 'les' ? '<span class="plan-tag look-tag">' . (in_array($k, PREMIUM_LOOKS, true) ? 'Premium' : 'Standard') . '</span>' : (!$plusOk && in_array($k, PREMIUM_LOOKS, true) ? '<span class="plan-tag look-tag">Premium</span>' : '') ?></label>
        <?php endforeach ?>
      </div>
      <?php if (plan_managed() && !plan_has('sablony')): ?><p class="hint">Vlastní logo je součástí edice Standard.</p><?php else: ?>
      <label>Logo (PNG nebo JPG do 1 MB, nepovinné) <input type="file" name="logo" accept=".png,.jpg,.jpeg"></label>
      <?php if (setting('logo') !== ''): ?><p class="logo-current"><img src="<?= url('/logo') ?>?v=<?= e(substr(setting('logo'), 0, 8)) ?>" alt="Současné logo"> <label class="check"><input type="checkbox" name="logo_remove"> Odebrat logo</label></p><?php endif ?>
      <p class="hint">Logo nahradí znak domečků vlevo nahoře. Nejlépe vypadá čtvercový nebo široký obrázek s průhledným pozadím.</p><?php endif ?>
      <label>Jazyk webu <select name="site_lang"><?php foreach (LANGS as $k => $label): ?><option value="<?= $k ?>"<?= site('lang') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
      <p class="hint">Výchozí jazyk pro návštěvníky. Každý si může jazyk přepnout sám v patičce webu. Administrace zůstává česky.</p>
      <?= $saveBtn('vzhled') ?>
    </fieldset>
    <fieldset id="menu">
      <legend>Menu – co se na webu zobrazuje</legend>
      <?php foreach (FEATURES as $k => $f): ?><label class="check"><input type="checkbox" name="features[]" value="<?= $k ?>"<?= feature_on($k) ? ' checked' : '' ?>> <?= e($f[0]) ?></label><?php endforeach ?>
      <p class="hint">Odškrtnutá část zmizí z menu obyvatelům i z administrace a nepůjde otevřít ani přímou adresou. Její data zůstanou uložená a po opětovném zaškrtnutí se vrátí.</p>
      <?= $saveBtn('menu') ?>
    </fieldset>
    <fieldset id="jednotky">
      <legend>Značení jednotek</legend>
      <div class="form-row">
        <label>Číslo před označením jednotky <input type="text" name="site_unit_prefix" value="<?= e(site('unit_prefix')) ?>" maxlength="10"></label>
        <label>Vchody (oddělené čárkou) <input type="text" name="site_entrances" value="<?= e(site('entrances')) ?>"></label>
      </div>
      <p class="hint">Jednotka se zobrazuje jako číslo + vchod / číslo jednotky, například <?= e(unit_prefix()) ?>A/5.</p>
      <?= $saveBtn('jednotky') ?>
    </fieldset>
    <fieldset id="udaje">
      <legend>Údaje o domě a texty webu</legend>
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
      <?= $saveBtn('udaje') ?>
    </fieldset>
    <fieldset id="emaily">
      <legend>Rozesílání e-mailů</legend>
      <div class="form-row">
        <label>Adresa odesílatele <input type="email" name="mail_from" value="<?= e(setting('mail_from')) ?>" placeholder="vybor@vase-domena.cz"></label>
        <label>Jméno odesílatele <input type="text" name="mail_from_name" value="<?= e(setting('mail_from_name', site('name'))) ?>"></label>
        <label>Odpovědi na e-maily posílat na (nepovinné) <input type="email" name="mail_reply_to" value="<?= e(setting('mail_reply_to')) ?>" placeholder="vybor@…"></label>
        <p class="hint">Když obyvatel na e-mail z webu odpoví, odpověď dostane tato adresa (třeba společná schránka výboru). Nevyplněno = adresa odesílatele.</p>
      </div>
      <p class="hint">Použijte adresu na doméně webu (zřídíte ji u poskytovatele hostingu). Zprávy odeslané „z“ adresy u Seznamu, Centra nebo Gmailu většinou skončí ve spamu. Na tuto adresu také přijdou odpovědi.</p>
      <label class="check"><input type="checkbox" name="mail_tracking"<?= setting_on('mail_tracking') ? ' checked' : '' ?>> <span>Evidovat, kdo zprávu otevřel a kdo klikl na odkaz <small>Příjemci jsou o tom informováni v patičce každé zprávy.</small></span></label>
      <?= $saveBtn('emaily') ?>
    </fieldset>
    <fieldset id="pozvanky">
      <legend>Pozvánky členů domácnosti</legend>
      <label class="check"><input type="checkbox" name="invite_enabled"<?= setting_on('invite_enabled') ? ' checked' : '' ?>> Vlastníci mohou sami pozvat manžela, manželku nebo spoluvlastníka</label>
      <?php if (ai_configured()): ?><label class="check"><input type="checkbox" name="ai_enabled"<?= setting_on('ai_enabled', true) ? ' checked' : '' ?>> <span>AI pomocník pro výbor <small>Pomáhá psát oznámení, e-maily a odpovědi na závady. Je v ceně. Text, který mu správce zadá, se odesílá ke zpracování společnosti Anthropic; po vypnutí se tlačítka pomocníka nezobrazují.</small></span></label><?php endif ?>
      <label class="check"><input type="checkbox" name="invite_by_tenants"<?= setting_on('invite_by_tenants', false) ? ' checked' : '' ?>> <span>Zvát mohou i nájemníci <small>Člověk pozvaný nájemníkem je vždy veden jako nájemník.</small></span></label>
      <div class="form-row">
        <label>Pozvaný vlastníkem je veden jako
          <select name="invite_kind"><?php foreach (KINDS as $k => $label): ?><option value="<?= $k ?>"<?= setting('invite_kind', 'owner') === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
        </label>
        <label>Nejvíc účtů na jednotku <input type="number" name="invite_max" min="1" max="20" value="<?= setting_int('invite_max', 6, 1, 20) ?>"></label>
        <label>Platnost pozvánky (dní) <input type="number" name="invite_days" min="1" max="90" value="<?= setting_int('invite_days', 14, 1, 90) ?>"></label>
      </div>
      <p class="hint">Vlastníci dostávají e-maily určené vlastníkům a mohou potvrzovat účast na shromáždění. U jednotlivého účtu to změníte v sekci Uživatelé.</p>
      <?= $saveBtn('pozvanky') ?>
    </fieldset>
    <fieldset id="shromazdeni">
      <legend>Shromáždění</legend>
      <label>Usnášeníschopnost: je potřeba více než (% všech hlasů) <input type="number" name="quorum_percent" min="1" max="99" value="<?= setting_int('quorum_percent', 50, 1, 99) ?>"></label>
      <p class="hint">Obvykle 50 % (přítomna většina všech hlasů). Řiďte se stanovami.</p>
      <?= $saveBtn('shromazdeni') ?>
    </fieldset>
    <fieldset id="gdpr">
      <legend>Ochrana osobních údajů</legend>
      <label>Text stránky „Ochrana osobních údajů“ <textarea name="privacy_text" rows="12"><?= e(setting('privacy_text') !== '' ? setting('privacy_text') : PRIVACY_DEFAULT) ?></textarea></label>
      <p class="hint">Předvyplněný text je návrh odpovídající tomu, co web skutečně ukládá. Než ho zveřejníte obyvatelům, nechte ho projít výborem; nadpisy začínají dvěma křížky (## ).</p>
      <?= $saveBtn('gdpr') ?>
    </fieldset>
  </form>
</div>
