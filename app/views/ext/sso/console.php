<?php
// Konzole → Nastavení služby: přihlášení přes Google, Microsoft, Apple a Facebook. Tajné údaje se nikdy nezobrazují.
$ssoKeys = ['google' => "'google' => ['client_id' => '…', 'client_secret' => '…']",
    'microsoft' => "'microsoft' => ['client_id' => '…', 'client_secret' => '…', 'tenant' => 'common']",
    'apple' => "'apple' => ['client_id' => 'Services ID', 'team_id' => '…', 'key_id' => '…', 'private_key_path' => '/cesta/mimo/web/AuthKey_….p8']",
    'facebook' => "'facebook' => ['app_id' => '…', 'app_secret' => '…']"];
?>
  <section class="card" id="sso">
    <h2>Přihlášení přes další účty (Google, Microsoft, Apple, Facebook)</h2>
    <p>Obyvatelé, výbory a pracovníci partnerů se mohou přihlásit účtem Google, Microsoft, Apple nebo Facebook. <strong>Účty se tím nezakládají</strong> – přihlásí se jen ten, kdo už účet má a poskytovatel potvrdí stejný ověřený e-mail, nebo kdo si účet propojil v části Můj účet. Kdo má zapnuté dvoufázové ověření, zadá kód i potom.</p>
    <p>Weby domů: <strong><?= e(BETA_STAGES[beta_stage('sso')]) ?></strong> – mění se v části <a href="#beta">Nové funkce (beta program)</a>.</p>
    <table class="table"><thead><tr><th>Poskytovatel</th><th>Stav</th><th>Návratová adresa (zaregistrujte ji u poskytovatele)</th></tr></thead><tbody>
      <?php foreach (SSO_PROVIDERS as $sp => $sl): $miss = sso_missing($sp); ?>
        <tr><td data-label="Poskytovatel"><?= sso_logo($sp) ?> <strong><?= e($sl) ?></strong></td>
          <td data-label="Stav"><?php if (!$miss): ?><span class="status status-done">Nastaveno</span><?php else: ?><span class="status">Nenastaveno</span><br><small class="muted">chybí v config.php: <?= e(implode(', ', $miss)) ?></small><?php endif ?></td>
          <td data-label="Návratová adresa"><code><?= e(sso_redirect_uri($sp)) ?></code></td></tr>
      <?php endforeach ?>
    </tbody></table>
    <details><summary>Co zapsat do storage/config.php</summary>
      <p class="hint">Klíče patří jen do souboru <code>storage/config.php</code> na serveru (nikdy do gitu ani sem do formuláře). Klíč Apple (.p8) uložte mimo veřejnou složku webu.</p>
      <pre class="report-trace">'sso' => [
<?php foreach ($ssoKeys as $line): ?>    <?= e($line) ?>,
<?php endforeach ?>],</pre>
    </details>
    <form method="post" action="<?= url('/nastaveni/sso') ?>" class="form"><?= csrf_field() ?>
      <label class="check"><input type="checkbox" name="sso_partner" value="1"<?= pset('sso_partner') !== '0' ? ' checked' : '' ?>> <span>Nabízet v partnerském portálu (/partner) <small>Platí, jen když není funkce v beta programu vypnutá.</small></span></label>
      <label class="check"><input type="checkbox" name="sso_console" value="1"<?= pset('sso_console') === '1' ? ' checked' : '' ?>> <span>Nabízet v konzoli provozovatele (/sprava) <small>Hlavní správce má přístup ke všemu – zapínejte jen se zapnutým dvoufázovým ověřením.</small></span></label>
      <div class="actions"><button class="btn" type="submit">Uložit</button></div>
    </form>
  </section>
