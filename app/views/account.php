<div class="wrap page narrow">
  <header class="page-head"><h1>Můj účet</h1>
    <p class="lead"><?= e($u['email']) ?> · <?= KINDS[$u['kind']] ?><?= $units ? ' · jednotka ' . e(implode(', ', array_map('unit_name', $units))) : '' ?></p>
    <p><?php if (feature_on('zavady')): ?><a href="<?= url('/zavady') ?>">Moje závady a požadavky</a> · <?php endif ?><a href="<?= url('/hlaseni') ?>#moje">Moje hlášení</a></p></header>
  <?php require __DIR__ . '/_errors.php'; ?>
  <div class="two-col">
    <form method="post" action="<?= url('/muj-ucet') ?>" class="card form">
      <h2>Kontaktní údaje</h2>
      <?= csrf_field() ?><input type="hidden" name="action" value="profile">
      <label>Jméno a příjmení <input type="text" name="name" value="<?= e($u['name']) ?>" required></label>
      <label>Telefon <input type="tel" name="phone" value="<?= e($u['phone']) ?>" autocomplete="tel"></label>
      <p class="hint">Telefon vidí jen výbor – kvůli řešení závad a požadavků.</p>
      <label class="check"><input type="checkbox" name="email_notify"<?= $u['email_notify'] ? ' checked' : '' ?>> <span>Posílat mi důležité zprávy výboru e-mailem <small>Například pozvánky na shromáždění nebo oznámení o odstávkách.</small></span></label>
      <button class="btn" type="submit">Uložit údaje</button>
    </form>
    <form method="post" action="<?= url('/muj-ucet') ?>" class="card form">
      <h2>Změna hesla</h2>
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <label>Současné heslo <input type="password" name="current_password" autocomplete="current-password" required></label>
      <label>Nové heslo (alespoň 10 znaků) <input type="password" name="new_password" minlength="10" autocomplete="new-password" required></label>
      <label>Nové heslo znovu <input type="password" name="new_password2" minlength="10" autocomplete="new-password" required></label>
      <button class="btn" type="submit">Změnit heslo</button>
    </form>
  </div>
  <section class="household" id="overeni">
    <h2>Dvoufázové ověření</h2>
    <div class="card form">
      <?php if ($u['totp_enabled']): ?>
        <p><span class="status status-done">Zapnuto</span> Při přihlášení zadáváte kromě hesla i kód z aplikace v telefonu.</p>
        <form method="post" action="<?= url('/muj-ucet/overeni') ?>" class="form">
          <?= csrf_field() ?><input type="hidden" name="action" value="disable">
          <label>Pro vypnutí zadejte současné heslo <input type="password" name="current_password" id="totp-disable-password" autocomplete="current-password" required></label>
          <button class="btn btn-ghost" type="submit">Vypnout dvoufázové ověření</button>
        </form>
      <?php elseif ($totpSetup): ?>
        <p><strong>1.</strong> V telefonu otevřete ověřovací aplikaci (Google Authenticator, Microsoft Authenticator, Aegis apod.), zvolte přidání účtu a <strong>zadání klíče ručně</strong>. Typ klíče: podle času.</p>
        <p><strong>2.</strong> Opište tento klíč:<br><code class="totp-key"><?= e(trim(chunk_split($totpSetup, 4, ' '))) ?></code></p>
        <form method="post" action="<?= url('/muj-ucet/overeni') ?>" class="form">
          <?= csrf_field() ?><input type="hidden" name="action" value="confirm">
          <label><strong>3.</strong> Zadejte šestimístný kód, který aplikace ukazuje <input type="text" name="code" id="totp-confirm" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required></label>
          <div class="actions"><button class="btn" type="submit">Zapnout</button><button class="btn btn-ghost" type="submit" name="action" value="cancel" formnovalidate>Zrušit</button></div>
        </form>
      <?php else: ?>
        <p>Kromě hesla budete při přihlášení zadávat i krátký kód z aplikace v telefonu. Kdyby někdo Vaše heslo uhodl nebo ukradl, bez telefonu se nepřihlásí.<?= $u['role'] === 'admin' ? ' <strong>Správcům webu to důrazně doporučujeme.</strong>' : '' ?></p>
        <form method="post" action="<?= url('/muj-ucet/overeni') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="start"><button class="btn" type="submit">Nastavit dvoufázové ověření</button></form>
      <?php endif ?>
    </div>
  </section>
  <?php if ($units): ?>
  <section class="household">
    <h2>Moje domácnost</h2>
    <?php if ($inviteLink): ?>
      <div class="card card-soft credentials">
        <h3>Pozvánka pro: <?= e($inviteLink['name']) ?></h3>
        <p>E-mail s pozvánkou se nepodařilo odeslat. Předejte prosím tento odkaz sami – <strong>zobrazuje se jen teď</strong> a platí <?= $inviteDays ?> dní.</p>
        <p><code><?= e($inviteLink['link']) ?></code></p>
      </div>
    <?php endif ?>
    <?php foreach ($units as $un): ?>
      <div class="card">
        <h3>Jednotka <?= e(unit_name($un)) ?><?= $un['floor'] !== '' ? ' <small class="muted">· ' . e($un['floor']) . '. patro</small>' : '' ?></h3>
        <ul class="people">
          <?php foreach ($un['people'] as $p): ?>
            <li><span><?= e($p['name']) ?><?= (int) $p['id'] === (int) $u['id'] ? ' (vy)' : '' ?> <small><?= mb_strtolower(KINDS[$p['kind']]) ?></small></span>
              <?php if ($p['pending']): ?><span class="tag tag-pin">čeká na přijetí pozvánky</span>
                <?php if ((int) $p['invited_by'] === (int) $u['id']): ?><form method="post" action="<?= url('/muj-ucet/pozvanka/' . $p['id'] . '/zrusit') ?>" data-confirm="Zrušit pozvánku pro „<?= e($p['name']) ?>“?"><?= csrf_field() ?><button class="link-danger" type="submit">Zrušit pozvánku</button></form><?php endif ?>
              <?php endif ?></li>
          <?php endforeach ?>
        </ul>
      </div>
    <?php endforeach ?>
    <?php if ($canInvite): ?>
    <form method="post" action="<?= url('/muj-ucet/pozvat') ?>" class="card form">
      <h3>Pozvat manžela, manželku nebo dalšího člena domácnosti</h3>
      <p class="hint">Pozvaný dostane e-mail s odkazem, nastaví si heslo a jeho účet se přiřadí k Vaší jednotce. Bude mít stejný přístup jako Vy.</p>
      <?= csrf_field() ?>
      <div class="form-row">
        <label>Jméno a příjmení <input type="text" name="name" id="invite-name" required></label>
        <label>E-mail <input type="email" name="email" id="invite-email" required></label>
      </div>
      <?php if (count($units) > 1): ?>
        <fieldset><legend>K jednotkám</legend>
          <?php foreach ($units as $un): ?><label class="check"><input type="checkbox" name="units[]" value="<?= $un['id'] ?>" checked> <?= e(unit_name($un)) ?></label><?php endforeach ?>
        </fieldset>
      <?php else: ?><input type="hidden" name="units[]" value="<?= $units[0]['id'] ?>"><?php endif ?>
      <button class="btn" type="submit">Odeslat pozvánku</button>
    </form>
    <?php endif ?>
  </section>
  <?php endif ?>
</div>
