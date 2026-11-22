<div class="wrap page narrow">
  <h1>Předplatné</h1>
  <?php if ($state['mode'] === 'free'): ?>
    <p class="lead">Tento web běží bez placení – provoz je domluven přímo s provozovatelem služby.</p>
  <?php else: ?>
  <div class="card bill-state bill-<?= e($state['mode']) ?>">
    <h2>Edice <?= e(EDITIONS[$edition]) ?></h2>
    <?php if ($state['mode'] === 'basic'): ?>
      <p>Web běží zdarma a bez časového omezení. Platí pro něj limity níže; funkce vyšších edic si můžete každou na <?= PLAN_TRIAL_DAYS ?> dní vyzkoušet.<?= $state['until'] !== '' ? ' Placená edice skončila ' . cz_date($state['until']) . ' – nic jsme nesmazali.' : '' ?></p>
    <?php elseif ($state['mode'] === 'trial'): ?>
      <p>Váš web má edici Standard na zkoušku do <strong><?= cz_date($state['until']) ?></strong> (ještě <?= $state['days'] ?> dní). Potom přejde na edici Zdarma – poběží dál, jen s jejími limity.</p>
    <?php elseif ($state['mode'] === 'paid'): ?>
      <p>Zaplaceno do <strong><?= cz_date($state['until']) ?></strong> (ještě <?= $state['days'] ?> dní).</p>
      <?php if (!empty($t['auto_renew'])): ?>
      <div class="renew-box"><p><strong>Předplatné se prodlužuje automaticky.</strong> <?= ($t['period'] ?? 'year') === 'month' ? 'Každou měsíční platbu z Vašeho trvalého příkazu připíšeme a edici prodloužíme o další měsíc.' : 'Před koncem období Vám připravíme zálohovou fakturu na další rok a pošleme upozornění e-mailem.' ?> Nic se nestrhává bez Vás – platíte převodem.</p>
        <form method="post" action="<?= url('/admin/predplatne/prodluzovani') ?>" data-confirm="Vypnout automatické prodlužování? Edice doběhne do <?= cz_date($state['until']) ?> a potom web přejde na edici Zdarma."><?= csrf_field() ?><input type="hidden" name="action" value="off"><button class="btn btn-small btn-ghost" type="submit">Zrušit prodlužování</button></form></div>
      <?php else: ?>
      <div class="renew-box is-off"><p><strong>Automatické prodlužování je vypnuté.</strong> Edice doběhne do <?= cz_date($state['until']) ?> a potom web přejde na edici Zdarma. Nic se nemaže.<?= ($t['period'] ?? 'year') === 'month' ? ' Nezapomeňte zrušit trvalý příkaz ve své bance.' : '' ?></p>
        <form method="post" action="<?= url('/admin/predplatne/prodluzovani') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="on"><button class="btn btn-small" type="submit">Znovu zapnout prodlužování</button></form></div>
      <?php endif ?>
    <?php else: ?>
      <p>Předplatné skončilo <strong><?= cz_date($state['until']) ?></strong>. Web zatím běží beze změny; bez úhrady přejde za <?= max(0, pset_int('grace_days', 0, 365) + $state['days']) ?> dní na edici Zdarma. Nic se nemaže.</p>
    <?php endif ?>
    <ul class="usage">
      <?php foreach ($usage as $k => $u): [$label, $unit] = PLAN_LIMITS[$k]; if ($k === 'ai' && !ai_configured()) { continue; } $pct = $u['limit'] ? min(100, (int) round(100 * $u['used'] / $u['limit'])) : 0; ?>
      <li<?= $u['limit'] && $pct >= 100 ? ' class="is-full"' : ($u['limit'] && $pct >= 80 ? ' class="is-near"' : '') ?>><span><?= e($label) ?></span>
        <strong><?= number_format($u['used'], 0, ',', "\u{a0}") ?><?= $u['limit'] ? ' z ' . number_format($u['limit'], 0, ',', "\u{a0}") : '' ?><?= $unit !== '' ? ' ' . $unit : '' ?><?= $u['limit'] ? '' : ' <small class="muted">· bez omezení</small>' ?></strong>
        <?php if ($u['limit']): ?><progress class="bar" value="<?= min($u['used'], $u['limit']) ?>" max="<?= $u['limit'] ?>"></progress><?php endif ?></li>
      <?php endforeach ?>
    </ul>
    <p class="hint">Po dosažení limitu web běží dál a nic se nemaže – jen nejde přidat další účet, odeslat další hromadný e-mail nebo nahrát další soubor.</p>
  </div>

  <?php if ($deleteOn && $deleteOn <= date('Y-m-d', strtotime('+30 day'))): ?><div class="flash flash-info">Na web se dlouho nikdo nepřihlásil. Nepoužívané weby v edici Zdarma mažeme – tento by byl smazán <strong><?= cz_date($deleteOn) ?></strong>. Vaše přihlášení lhůtu právě vynulovalo.</div><?php endif ?>

  <?php if ($payment): ?>
  <div class="card" id="platba">
    <?php $monthlyPay = (int) $payment['months'] === 1; ?>
    <h2>Platba za edici <?= e(EDITIONS[$payment['item']] ?? 'Standard') ?> <?= $monthlyPay ? '– měsíčně trvalým příkazem' : 'na 1 rok' ?></h2>
    <?php if ($payment['status'] === 'claimed'): ?><div class="flash flash-info">Platbu jste nahlásili jako odeslanou <?= cz_datetime($payment['claimed_at']) ?>. Čeká na potvrzení provozovatelem – obvykle do dvou pracovních dnů.</div><?php endif ?>
    <?php if ($iban): ?>
    <div class="pay">
      <div class="pay-qr"><?= $qr ?><small><?= $monthlyPay ? 'QR kód pro trvalý příkaz' : 'Načtěte v bankovní aplikaci' ?></small></div>
      <dl class="pay-data">
        <dt>Částka</dt><dd><strong><?= money_czk((int) $payment['amount']) ?></strong><?= $monthlyPay ? ' <small class="muted">každý měsíc</small>' : '' ?></dd>
        <dt>Číslo účtu</dt><dd><?= e(str_starts_with(strtoupper(trim(pset('account'))), 'CZ') ? $iban : pset('account')) ?></dd>
        <dt>IBAN</dt><dd><?= e(trim(chunk_split($iban, 4, ' '))) ?></dd>
        <dt>Variabilní symbol</dt><dd><code><?= e($payment['vs']) ?></code></dd>
        <?php if (pset('recipient') !== ''): ?><dt>Příjemce</dt><dd><?= e(pset('recipient')) ?></dd><?php endif ?>
      </dl>
    </div>
    <?php if ($monthlyPay): ?>
    <div class="callout"><strong>Jak nastavit trvalý příkaz</strong>
      <ol><li>Načtěte QR kód v bankovní aplikaci – vyplní částku, účet, variabilní symbol i měsíční opakování. Před potvrzením zkontrolujte, že aplikace nabízí <strong>trvalý příkaz</strong>, ne jednorázovou platbu.</li>
        <li>Pokud Vaše banka trvalý příkaz z QR kódu neumí, zadejte ho ručně: <?= money_czk((int) $payment['amount']) ?> každý měsíc na účet <?= e(pset('account')) ?>, variabilní symbol <code><?= e($payment['vs']) ?></code>.</li>
        <li>Variabilní symbol zůstává každý měsíc stejný. Každou připsanou platbu potvrdíme a edici prodloužíme o měsíc.</li></ol>
      <?php if (!empty($qrOnce)): ?><details><summary>Chci zatím zaplatit jen první měsíc jednorázově</summary><div class="pay-qr"><?= $qrOnce ?><small>Jednorázová platba <?= money_czk((int) $payment['amount']) ?></small></div></details><?php endif ?>
    </div>
    <p class="hint">Měsíční platba vyjde ročně na <?= money_czk((int) $payment['amount'] * 12) ?>; při roční platbě je to <?= money_czk(edition_price((string) ($payment['item'] ?? 'standard'))) ?>. Trvalý příkaz zrušíte kdykoli ve své bance – edice pak doběhne do konce zaplaceného měsíce a web přejde na edici Zdarma.</p>
    <?php else: ?>
    <p class="hint">Platba se páruje podle variabilního symbolu. <?= (int) $payment['amount'] < edition_price((string) ($payment['item'] ?? 'standard')) ? 'Částka je snížená o nevyčerpanou část dosavadní edice. ' : '' ?>Edici zapneme po připsání platby na účet<?= ($payment['item'] ?? '') === $edition && $state['mode'] === 'paid' ? ' a prodloužíme o rok od ' . cz_date(date('Y-m-d', strtotime($state['until'] . ' +1 day'))) : ', na rok ode dne připsání' ?>.</p>
    <?php endif ?>
    <?php else: ?>
      <p>Částka: <strong><?= money_czk((int) $payment['amount']) ?></strong>, variabilní symbol <code><?= e($payment['vs']) ?></code>.</p>
      <p>Platební údaje Vám provozovatel pošle e-mailem<?= pset('contact_email') !== '' ? ' – případně napište na <a href="mailto:' . e(pset('contact_email')) . '">' . e(pset('contact_email')) . '</a>' : '' ?>.</p>
    <?php endif ?>
    <div class="actions"><?php if ($payment['status'] === 'pending'): ?><form method="post" action="<?= url('/admin/predplatne/zaplaceno') ?>"><?= csrf_field() ?><button class="btn" type="submit">Platbu jsem odeslal(a)</button></form><?php endif ?>
      <a class="btn btn-ghost" href="<?= url('/admin/predplatne/doklad/' . $payment['id']) ?>">Zálohová faktura k tisku</a>
      <?php if ($payment['status'] === 'pending'): ?><form method="post" action="<?= url('/admin/predplatne/edice') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-ghost" type="submit">Zrušit objednávku</button></form><?php endif ?></div>
  </div>
  <?php endif ?>

  <div class="card" id="edice">
    <h2>Edice</h2>
    <?php $edView = 'admin'; require __DIR__ . '/../platform/_editions.php'; ?>
  </div>

  <div class="card" id="funkce">
    <h2>Funkce vyšších edic</h2>
    <p class="muted">Každou funkci si můžete jednou na <?= PLAN_TRIAL_DAYS ?> dní zdarma vyzkoušet. Po skončení zkoušky zůstane vše, co jste do ní zapsali, uložené a viditelné – jen už nepůjde nic přidávat ani měnit, dokud nepřejdete na edici, která funkci obsahuje.</p>
    <ul class="plan-features">
      <?php foreach (PLAN_FEATURES as $fk => [$min, $fname, $fdesc]): $st = plan_state($fk); $tr = plan_trials()[$fk] ?? null; ?>
      <li><div><strong><?= e($fname) ?></strong> <span class="tag"><?= e(EDITIONS[$min]) ?></span><p><?= e($fdesc) ?></p></div>
        <?php if ($st === 'on'): ?><span class="status status-done">v edici</span>
        <?php elseif ($st === 'trial'): ?><span class="status status-in_progress">zkouška do <?= cz_date($tr['ends']) ?></span>
        <?php elseif ($st === 'expired'): ?><span class="status status-new">zkouška skončila <?= cz_date($tr['ends']) ?></span>
        <?php else: ?><form method="post" action="<?= url('/admin/predplatne/zkusit') ?>"><?= csrf_field() ?><input type="hidden" name="feature" value="<?= e($fk) ?>"><button class="btn btn-small btn-ghost" type="submit">Vyzkoušet <?= PLAN_TRIAL_DAYS ?> dní</button></form><?php endif ?></li>
      <?php endforeach ?>
    </ul>
  </div>

  <div class="card" id="uloziste">
    <h2>Úložiště dokumentů</h2>
    <?php $stUsed = $usage['storage']['used']; $stLimit = $usage['storage']['limit']; $stExtra = storage_extra_mb($t); $packMb = pset_int('storage_pack_mb', 1, 100000); $packPrice = pset_int('price_storage', 0, 1000000); ?>
    <p>Využito <strong><?= number_format($stUsed, 0, ',', "\u{a0}") ?> MB<?= $stLimit ? ' z ' . number_format($stLimit, 0, ',', "\u{a0}") . ' MB' : '' ?></strong><?= $stLimit ? '' : ' – bez omezení' ?><?= $stExtra ? ' (edice ' . ($stLimit - $stExtra) . ' MB + ' . $stExtra . ' MB navíc do ' . cz_date($t['storage_until']) . ')' : '' ?>.</p>
    <?php if ($stLimit): ?><progress class="bar bar-wide" value="<?= min($stUsed, $stLimit) ?>" max="<?= $stLimit ?>"></progress><?php endif ?>
    <?php if ($storagePayment): $sp = $storagePayment; ?>
      <h3>Platba za <?= (int) $sp['item'] ?> MB navíc na 1 rok</h3>
      <?php if ($sp['status'] === 'claimed'): ?><div class="flash flash-info">Platbu jste nahlásili jako odeslanou <?= cz_datetime($sp['claimed_at']) ?>. Čeká na potvrzení provozovatelem.</div><?php endif ?>
      <div class="pay"><?php if ($storageQr): ?><div class="pay-qr"><?= $storageQr ?><small>Načtěte v bankovní aplikaci</small></div><?php endif ?>
        <dl class="pay-data"><dt>Částka</dt><dd><strong><?= money_czk((int) $sp['amount']) ?></strong></dd><?php if ($iban): ?><dt>Číslo účtu</dt><dd><?= e(pset('account')) ?></dd><?php endif ?><dt>Variabilní symbol</dt><dd><code><?= e($sp['vs']) ?></code></dd></dl></div>
      <div class="actions">
        <?php if ($sp['status'] === 'pending'): ?><form method="post" action="<?= url('/admin/predplatne/uloziste') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="claim"><button class="btn" type="submit">Platbu za úložiště jsem odeslal(a)</button></form><?php endif ?>
        <a class="btn btn-ghost" href="<?= url('/admin/predplatne/doklad/' . $sp['id']) ?>">Zálohová faktura k tisku</a>
        <?php if ($sp['status'] === 'pending'): ?><form method="post" action="<?= url('/admin/predplatne/uloziste') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-ghost" type="submit">Zrušit objednávku</button></form><?php endif ?>
      </div>
    <?php elseif ($stLimit && $packPrice): ?>
      <p><strong>Potřebujete víc místa?</strong> Přikupte si úložiště navíc: <?= $packMb ?> MB za <?= money_czk($packPrice) ?> ročně, v kterékoli edici.<?= $edition !== 'premium' ? ' Vyšší edice má úložiště větší (' . implode(', ', array_map(fn($e) => EDITIONS[$e] . ' ' . pset_int('lim_' . $e . '_storage', 0, 10000000) . ' MB', array_slice(['standard', 'premium'], $edition === 'free' ? 0 : 1))) . ').' : '' ?></p>
      <form method="post" action="<?= url('/admin/predplatne/uloziste') ?>" class="storage-order"><?= csrf_field() ?><input type="hidden" name="action" value="order">
        <label>Kolik místa navíc <select name="packs"><?php foreach ([1, 2, 4, 10] as $n): ?><option value="<?= $n ?>">+ <?= number_format($n * $packMb, 0, ',', "\u{a0}") ?> MB · <?= money_czk($n * $packPrice) ?> / rok</option><?php endforeach ?></select></label>
        <button class="btn" type="submit">Objednat úložiště navíc</button></form>
      <?php if ($stExtra): ?><p class="hint">Nová objednávka nahradí dosavadní balíček (<?= $stExtra ?> MB do <?= cz_date($t['storage_until']) ?>) a platí rok od připsání platby.</p><?php endif ?>
    <?php endif ?>
  </div>

  <div class="card" id="prevod">
    <h2>Převod obsahu ze starého webu</h2>
    <?php $mf = $_SESSION['migration_form'] ?? []; $price = pset_int('price_migration', 0, 1000000); ?>
    <?php if ($migration && $migration['status'] !== 'done'): $mp = $migrationPayment; ?>
      <p>Objednávka převodu z <strong><?= e($migration['url']) ?></strong> ze dne <?= cz_date($migration['created_at']) ?>: <span class="status status-<?= $migration['status'] === 'paid' ? 'in_progress' : 'new' ?>"><?= e(MIGRATION_STATUS[$migration['status']]) ?></span></p>
      <p class="muted">Převádíme: <?= e(implode(', ', array_map(fn($k) => mb_strtolower(MIGRATION_SCOPE[$k] ?? $k), array_filter(explode(',', $migration['scope']))))) ?>. Kontakt: <?= e($migration['contact_name']) ?>, <?= e($migration['contact_email']) ?>.</p>
      <?php if ($migration['status'] === 'paid'): ?><p>Platbu jsme přijali. Převod bývá hotový do 5 pracovních dnů; kdybychom něco potřebovali, ozveme se na uvedený e-mail. Starý web neměníme ani nerušíme.</p>
      <?php elseif ($mp): ?>
        <?php if ($mp['status'] === 'claimed'): ?><div class="flash flash-info">Platbu jste nahlásili jako odeslanou <?= cz_datetime($mp['claimed_at']) ?>. Čeká na potvrzení provozovatelem.</div><?php endif ?>
        <div class="pay">
          <?php if ($migrationQr): ?><div class="pay-qr"><?= $migrationQr ?><small>Načtěte v bankovní aplikaci</small></div><?php endif ?>
          <dl class="pay-data"><dt>Částka</dt><dd><strong><?= money_czk((int) $mp['amount']) ?></strong> <small class="muted">jednorázově</small></dd><?php if ($iban): ?><dt>Číslo účtu</dt><dd><?= e(pset('account')) ?></dd><?php endif ?><dt>Variabilní symbol</dt><dd><code><?= e($mp['vs']) ?></code></dd></dl>
        </div>
        <?php if (!$iban): ?><p>Platební údaje Vám provozovatel pošle e-mailem.</p><?php endif ?>
        <div class="actions">
          <?php if ($mp['status'] === 'pending'): ?><form method="post" action="<?= url('/admin/predplatne/prevod') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="claim"><button class="btn" type="submit">Platbu za převod jsem odeslal(a)</button></form><?php endif ?>
          <a class="btn btn-ghost" href="<?= url('/admin/predplatne/doklad/' . $mp['id']) ?>">Zálohová faktura k tisku</a>
          <?php if ($mp['status'] === 'pending'): ?><form method="post" action="<?= url('/admin/predplatne/prevod') ?>" data-confirm="Zrušit objednávku převodu?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-ghost" type="submit">Zrušit objednávku</button></form><?php endif ?>
        </div>
      <?php endif ?>
    <?php else: ?>
      <?php if ($migration): ?><div class="flash flash-ok">Převod z <?= e($migration['url']) ?> byl dokončen <?= cz_date($migration['done_at']) ?>.<?= $migration['done_note'] !== '' ? ' ' . e($migration['done_note']) : '' ?></div><?php endif ?>
      <p>Máte obsah na starém webu (Webnode, Wix, WordPress, vlastní stránky)? Přeneseme ho za Vás – <strong><?= money_czk($price) ?> jednorázově</strong>, v kterékoli edici.</p>
      <div class="req-box">
        <strong>Co k převodu potřebujeme</strong>
        <ol>
          <li><strong>Adresu starého webu</strong> a informaci, zda je obsah veřejně vidět. Je-li část za přihlášením, domluvíme přístup e-mailem – hesla do formuláře nepište.</li>
          <li><strong>Co převést</strong> – aktuality, dokumenty, kontakty, texty stránek, fotografie.</li>
          <li><strong>Potvrzení, že společenství má k obsahu práva</strong> a s převodem souhlasí. U dokumentů s osobními údaji (zápisy, seznamy vlastníků) nám napište, které mají být jen pro přihlášené.</li>
          <li><strong>Kontaktní osobu</strong>, které se můžeme zeptat, když si nebudeme jistí.</li>
          <li><strong>Úhradu <?= money_czk($price) ?></strong> převodem na zálohovou fakturu s QR kódem. Pracovat začneme po připsání platby.</li>
        </ol>
        <p class="hint">Převod bývá hotový do 5 pracovních dnů od připsání platby a dodání podkladů. Starý web neměníme ani nerušíme. Nepřevádíme účty uživatelů a hesla, diskuze, formuláře ani vzhled starého webu. Převedené dokumenty se počítají do limitu úložiště Vaší edice<?= plan_limit('storage') ? ' (' . plan_limit('storage') . ' MB)' : '' ?>.</p>
      </div>
      <form method="post" action="<?= url('/admin/predplatne/prevod') ?>" class="form">
        <?= csrf_field() ?><input type="hidden" name="action" value="order">
        <label>Adresa starého webu <input type="text" name="url" value="<?= e($mf['url'] ?? '') ?>" placeholder="https://svj-zahradni.webnode.cz" required maxlength="300" inputmode="url" autocomplete="off"></label>
        <fieldset class="checks"><legend>Co chcete převést</legend>
          <?php foreach (MIGRATION_SCOPE as $sk => $sl): ?><label class="check"><input type="checkbox" name="scope[]" value="<?= $sk ?>"<?= in_array($sk, $mf['scope_list'] ?? ['aktuality', 'dokumenty', 'kontakty'], true) ? ' checked' : '' ?>> <span><?= e($sl) ?></span></label><?php endforeach ?>
        </fieldset>
        <fieldset class="checks"><legend>Jak se k obsahu dostaneme</legend>
          <?php foreach (MIGRATION_ACCESS as $ak => $al): ?><label class="check"><input type="radio" name="access" value="<?= $ak ?>"<?= ($mf['access'] ?? 'public') === $ak ? ' checked' : '' ?> required> <span><?= e($al) ?></span></label><?php endforeach ?>
        </fieldset>
        <label>Poznámka (nepovinné) <textarea name="note" rows="3" maxlength="2000" placeholder="Např. zápisy ze shromáždění jen pro přihlášené, aktuality starší než 2 roky nepřevádět…"><?= e($mf['note'] ?? '') ?></textarea></label>
        <div class="form-row">
          <label>Kontaktní osoba <input type="text" name="contact_name" value="<?= e($mf['contact_name'] ?? user()['name']) ?>" required maxlength="120"></label>
          <label>E-mail <input type="email" name="contact_email" value="<?= e($mf['contact_email'] ?? user()['email']) ?>" required></label>
          <label>Telefon (nepovinné) <input type="tel" name="contact_phone" value="<?= e($mf['contact_phone'] ?? '') ?>" maxlength="40"></label>
        </div>
        <label class="check"><input type="checkbox" name="rights" required> <span>Potvrzuji, že společenství má k obsahu starého webu práva a s jeho převodem souhlasí.</span></label>
        <div class="actions"><button class="btn" type="submit">Objednat převod za <?= money_czk($price) ?></button></div>
      </form>
    <?php endif ?>
  </div>

  <div class="card" id="domena">
    <h2>Vlastní doména</h2>
    <?php if ($t['domain_status'] === 'active'): ?>
      <p>Web běží na vlastní doméně <strong><a href="https://<?= e($t['custom_domain']) ?>"><?= e($t['custom_domain']) ?></a></strong><?= $t['domain_until'] !== '' ? ', zaplaceno do ' . cz_date($t['domain_until']) : '' ?>. Adresa <?= e(tenant_address($t['slug'])) ?> funguje dál.</p>
    <?php elseif ($t['domain_status'] === 'requested'): ?>
      <p>Žádost o doménu <strong><?= e($t['domain_request']) ?></strong> je odeslaná. <?= $t['domain_until'] !== '' ? 'Platbu jsme přijali; doménu zprovozňujeme a dáme Vám vědět e-mailem.' : 'Po přijetí platby ji zprovozníme, obvykle do několika pracovních dnů.' ?></p>
      <?php if (pset('domain_dns') !== ''): ?><div class="callout"><strong>Pokud doménu už vlastníte,</strong> nastavte u svého registrátora: <div class="prose"><?= format_text(pset('domain_dns')) ?></div></div><?php endif ?>
    <?php elseif ($edition === 'free'): ?>
      <p>Web může běžet na Vaší vlastní adrese, například <strong>tabor48.cz</strong>. Vlastní doména je k dispozici od edice Standard (příplatek <?= money_czk(pset_int('price_domain', 0, 1000000)) ?> ročně), v edici Premium je v ceně.</p>
    <?php else: ?>
      <p>Web může běžet na Vaší vlastní adrese, například <strong>tabor48.cz</strong>, <?= $edition === 'premium' ? 'v edici Premium bez příplatku' : 'za ' . money_czk(pset_int('price_domain', 0, 1000000)) . ' ročně (v edici Premium je v ceně)' ?>. Doménu Vám zaregistrujeme, nebo připojíme tu, kterou už máte. Správa webu se nemění – jen adresa.</p>
      <form method="post" action="<?= url('/admin/predplatne/domena') ?>" class="form">
        <?= csrf_field() ?><input type="hidden" name="action" value="request">
        <label>Požadovaná doména <input type="text" name="domain" placeholder="tabor48.cz" required maxlength="100" autocomplete="off"></label>
        <label class="check"><input type="radio" name="own" value="0" checked> <span>Doménu ještě nemáme <small>Ověříme, zda je volná, a zaregistrujeme ji pro Vaše společenství.</small></span></label>
        <label class="check"><input type="radio" name="own" value="1"> <span>Doménu už máme <small>Po zaplacení Vám pošleme, co nastavit u Vašeho registrátora.</small></span></label>
        <div class="actions"><button class="btn" type="submit">Požádat o vlastní doménu</button></div>
      </form>
    <?php endif ?>
    <?php if ($t['domain_status'] !== ''): $certDomain = $t['custom_domain'] !== '' ? $t['custom_domain'] : $t['domain_request']; ?>
    <details class="cert-box"<?= $certInfo && $certInfo['days'] <= 21 ? ' open' : '' ?>>
      <summary>Vlastní certifikát HTTPS <?= $certInfo ? '<span class="status status-' . ($certInfo['days'] < 0 ? 'new' : ($certInfo['days'] <= 21 ? 'in_progress' : 'done')) . '">' . ($certInfo['days'] < 0 ? 'neplatný' : 'platí do ' . cz_date($certInfo['valid_to'])) . '</span>' : '<small class="muted">nepovinné</small>' ?></summary>
      <p class="hint">Certifikát pro Vaši doménu obvykle zajistíme sami a nemusíte nic nahrávat. Vlastní nahrajte jen tehdy, když ho Vaše společenství už má (například od poskytovatele domény).</p>
      <?php if ($certInfo): ?><p>Nahraný certifikát: vydal <?= e($certInfo['issuer'] !== '' ? $certInfo['issuer'] : 'neznámý vydavatel') ?>, platí do <strong><?= cz_date($certInfo['valid_to']) ?></strong><?= $certInfo['self_signed'] ? ' · podepsaný sám sebou' : '' ?>.</p><?php endif ?>
      <form method="post" action="<?= url('/admin/predplatne/certifikat') ?>" enctype="multipart/form-data" class="form">
        <?= csrf_field() ?>
        <label>Certifikát pro <?= e($certDomain) ?> (soubor .pem nebo .crt, celý řetězec) <input type="file" name="cert_file" accept=".pem,.crt,.cer,.txt" required></label>
        <label>Soukromý klíč (soubor .key nebo .pem, bez hesla) <input type="file" name="key_file" accept=".pem,.key,.txt" required></label>
        <p class="hint">Soukromý klíč se uloží jen na serveru, nikde se nezobrazuje a nejde stáhnout. Před uložením ověříme, že patří k certifikátu, že je certifikát pro tuto doménu a že platí.</p>
        <div class="actions"><button class="btn btn-small" type="submit"><?= $certInfo ? 'Nahrát nový certifikát' : 'Nahrát certifikát' ?></button></div>
      </form>
      <?php if ($certInfo): ?><form method="post" action="<?= url('/admin/predplatne/certifikat') ?>" data-confirm="Odebrat nahraný certifikát?"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><button class="link-danger" type="submit">Odebrat certifikát</button></form><?php endif ?>
    </details>
    <?php endif ?>
    <?php if ($domainPayment): $dp = $domainPayment; ?>
      <h3>Platba za doménu <?= e($dp['item']) ?> na 1 rok</h3>
      <?php if ($dp['status'] === 'claimed'): ?><div class="flash flash-info">Platbu jste nahlásili jako odeslanou <?= cz_datetime($dp['claimed_at']) ?>. Čeká na potvrzení provozovatelem.</div><?php endif ?>
      <div class="pay">
        <?php if ($domainQr): ?><div class="pay-qr"><?= $domainQr ?><small>Načtěte v bankovní aplikaci</small></div><?php endif ?>
        <dl class="pay-data"><dt>Částka</dt><dd><strong><?= money_czk((int) $dp['amount']) ?></strong></dd><?php if ($iban): ?><dt>Číslo účtu</dt><dd><?= e(pset('account')) ?></dd><?php endif ?><dt>Variabilní symbol</dt><dd><code><?= e($dp['vs']) ?></code></dd></dl>
      </div>
      <div class="actions">
        <?php if ($dp['status'] === 'pending'): ?><form method="post" action="<?= url('/admin/predplatne/domena') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="claim"><button class="btn" type="submit">Platbu za doménu jsem odeslal(a)</button></form><?php endif ?>
        <a class="btn btn-ghost" href="<?= url('/admin/predplatne/doklad/' . $dp['id']) ?>">Zálohová faktura k tisku</a>
        <?php if ($dp['status'] === 'pending' && $t['domain_status'] === 'requested'): ?><form method="post" action="<?= url('/admin/predplatne/domena') ?>" data-confirm="Zrušit žádost o vlastní doménu?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-ghost" type="submit">Zrušit žádost</button></form><?php endif ?>
      </div>
    <?php endif ?>
  </div>

  <?php if ($aiPayment): $ap = $aiPayment; ?>
  <div class="card" id="ai">
    <h2>Platba za doplněk AI Plus</h2>
    <p class="muted">Doplněk AI Plus už se nenabízí – vyšší počet použití AI je součástí edice Premium. Tuto dříve objednanou platbu můžete dokončit, nebo zrušit.</p>
    <dl class="pay-data"><dt>Částka</dt><dd><strong><?= money_czk((int) $ap['amount']) ?></strong></dd><dt>Variabilní symbol</dt><dd><code><?= e($ap['vs']) ?></code></dd></dl>
    <div class="actions">
      <?php if ($ap['status'] === 'pending'): ?><form method="post" action="<?= url('/admin/predplatne/ai') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="claim"><button class="btn" type="submit">Platbu jsem odeslal(a)</button></form>
      <form method="post" action="<?= url('/admin/predplatne/ai') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-ghost" type="submit">Zrušit objednávku</button></form><?php endif ?>
    </div>
  </div>
  <?php endif ?>

  <div class="card" id="pripravujeme">
    <h2>Připravujeme</h2>
    <p class="muted">Na těchto funkcích pracujeme. Klikněte na tu, která Vás zajímá – dozvíte se, co přesně bude dělat a jaký problém řeší. Podle zájmu rozhodujeme, co udělat dřív.</p>
    <ul class="planned">
      <?php foreach (PLANNED_FEATURES as $key => [$name, $desc, $price]): ?>
      <li><a href="<?= url('/admin/predplatne/funkce/' . $key) ?>"><span class="planned-head"><strong><?= e($name) ?></strong> <span class="tag">již brzy</span></span>
        <span class="planned-desc"><?= e($desc) ?></span>
        <span class="planned-foot"><small class="muted"><?= e($price) ?></small><span class="planned-more">Více o funkci →</span></span></a></li>
      <?php endforeach ?>
    </ul>
  </div>

  <div class="card card-soft">
    <h2>Další služby</h2>
    <ul class="price-rows">
      <?php foreach (preg_split('/\R/', pset('pricelist')) as $line): if (trim($line) === '') { continue; } [$item, $price] = array_map('trim', explode('|', $line . '|')); ?>
        <li><span><?= e($item) ?></span><strong><?= e($price) ?></strong></li>
      <?php endforeach ?>
    </ul>
    <?php if (pset('contact_email') !== ''): ?><p class="hint">Podporu a úpravy objednáte na <a href="mailto:<?= e(pset('contact_email')) ?>"><?= e(pset('contact_email')) ?></a>.</p><?php endif ?>
  </div>

  <?php if (empty($t['demo']) && empty($GLOBALS['DEMO'])): ?>
  <details class="card danger-zone" id="zrusit"<?= $t['delete_at'] !== '' ? ' open' : '' ?>>
    <summary><h2>Zrušit web a smazat všechna data</h2></summary>
    <?php if ($t['delete_at'] !== ''): ?>
      <div class="flash flash-error">Web bude nenávratně smazán <strong><?= cz_date($t['delete_at']) ?></strong> na žádost: <?= e($t['delete_by']) ?>.</div>
      <p>Do té doby web běží normálně. <a href="<?= url('/admin/zaloha') ?>">Stáhněte si zálohu dat</a>, pokud ji budete chtít mít<?= plan_has('zaloha') ? '' : ' (záloha je součástí placených edic; v edici Zdarma ji lze jednou na ' . PLAN_TRIAL_DAYS . ' dní zdarma zapnout)' ?>.</p>
      <form method="post" action="<?= url('/admin/predplatne/zrusit-web') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn" type="submit">Vzít žádost zpět – web nemazat</button></form>
    <?php else: ?>
      <p>Smaže se celý web společenství: všechny účty, jednotky, dokumenty, hlasování, zprávy i nastavení. <strong>Nejde to vrátit.</strong></p>
      <ul><li>Web se smaže za <?= TENANT_DELETE_WAIT_DAYS ?> dní od žádosti. Do té doby běží normálně a kterýkoli správce může žádost vzít zpět.</li>
        <li>Všem správcům webu pošleme e-mail, aby o žádosti věděli.</li>
        <li>Zaplacené předplatné se nevrací; vystavené faktury uchováváme po dobu danou účetními předpisy.</li>
        <li>Nejdřív si <a href="<?= url('/admin/zaloha') ?>">stáhněte zálohu dat</a><?= plan_has('zaloha') ? '' : ' – je součástí placených edic, v edici Zdarma ji lze jednou na ' . PLAN_TRIAL_DAYS . ' dní zdarma zapnout' ?>.</li></ul>
      <form method="post" action="<?= url('/admin/predplatne/zrusit-web') ?>" class="form" data-confirm="Opravdu požádat o smazání celého webu?">
        <?= csrf_field() ?>
        <label>Pro potvrzení opište adresu webu: <strong><?= e($t['slug']) ?></strong> <input type="text" name="confirm" required autocomplete="off" autocapitalize="none"></label>
        <label>Vaše heslo <input type="password" name="password" required autocomplete="current-password"></label>
        <label class="check"><input type="checkbox" name="understand" required> <span>Rozumím, že se smažou všechna data společenství a nepůjde to vrátit.</span></label>
        <div class="actions"><button class="btn btn-danger" type="submit">Požádat o smazání webu</button></div>
      </form>
    <?php endif ?>
  </details>
  <?php endif ?>

  <?php if ($history): ?>
  <h2>Přijaté platby</h2>
  <table class="table"><thead><tr><th>Zaplaceno</th><th>Částka</th><th>Období</th><th>Var. symbol</th><th>Doklad</th></tr></thead><tbody>
    <?php foreach ($history as $p): ?><tr><td data-label="Zaplaceno"><?= cz_date($p['paid_at']) ?></td><td data-label="Částka"><?= money_czk((int) $p['amount']) ?></td><td data-label="Období"><?= cz_date($p['period_from']) ?> – <?= cz_date($p['period_to']) ?></td><td data-label="Var. symbol"><?= e($p['vs']) ?><?= ($p['kind'] ?? 'service') === 'service' && isset(EDITIONS[$p['item'] ?? '']) ? '<br><small class="muted">edice ' . EDITIONS[$p['item']] . '</small>' : '' ?><?= ($p['kind'] ?? '') === 'storage' ? '<br><small class="muted">úložiště +' . (int) $p['item'] . ' MB</small>' : (($p['kind'] ?? '') === 'migration' ? '<br><small class="muted">převod obsahu</small>' : (($p['kind'] ?? '') === 'domain' ? '<br><small class="muted">doména ' . e($p['item']) . '</small>' : (($p['kind'] ?? '') === 'ai' ? '<br><small class="muted">AI Plus</small>' : ''))) ?></td><td data-label="Doklad"><a href="<?= url('/admin/predplatne/doklad/' . $p['id']) ?>">Faktura <?= e($p['invoice_no']) ?></a></td></tr><?php endforeach ?>
  </tbody></table>
  <?php endif ?>
  <?php endif ?>
</div>
