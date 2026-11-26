<div class="wrap page">
  <p class="crumbs"><a href="<?= url('/partneri') ?>">← Partneři</a></p>
  <header class="page-head"><h1><?= e($pa['name']) ?><?= $pa['active'] ? '' : ' <span class="tag">vypnuto</span>' ?></h1>
    <p class="lead"><?= count(array_filter($houses, fn($h) => $h['partner_status'] === 'active')) ?> domů ve správě · množstevní sleva <?= partner_discount(count(array_filter($houses, fn($h) => $h['partner_status'] === 'active'))) ?> %</p></header>
  <?php if ($invite): ?><div class="card card-soft"><h2>Pozvánka odeslána</h2><p><?= e($invite['name']) ?> (<?= e($invite['email']) ?>) dostal e-mail s odkazem na nastavení hesla. Kdyby nedošel, pošlete mu tento odkaz (platí 7 dní):</p><p><code><?= e($invite['link']) ?></code></p></div><?php endif ?>
  <h2>Domy</h2>
  <?php if ($houses): ?>
  <table class="table"><thead><tr><th>Dům</th><th>Přístup</th><th>Platí</th><th>Předplatné</th></tr></thead><tbody>
    <?php foreach ($houses as $h): $bs = billing_state($h); ?><tr>
      <td data-label="Dům"><a href="<?= url('/svj/' . $h['slug']) ?>"><?= e($h['name']) ?></a></td>
      <td data-label="Přístup"><?= $h['partner_status'] === 'active' ? 'schválen ' . cz_date(substr((string) $h['partner_since'], 0, 10)) : 'čeká na výbor' ?></td>
      <td data-label="Platí"><?= $h['partner_billing'] ? 'partner' : 'společenství' ?></td>
      <td data-label="Předplatné"><?= e(EDITIONS[$bs['edition']] ?? $bs['edition']) ?><?= $bs['until'] !== '' ? ' do ' . cz_date($bs['until']) : '' ?></td></tr><?php endforeach ?>
  </tbody></table>
  <?php else: ?><p class="empty">Partner zatím nespravuje žádný dům.</p><?php endif ?>
  <h2>Faktury</h2>
  <?php if ($payments): ?>
  <table class="table"><thead><tr><th>VS</th><th>Částka</th><th>Domů</th><th>Stav</th><th></th></tr></thead><tbody>
    <?php foreach ($payments as $p): ?><tr><td data-label="VS"><code><?= e($p['vs']) ?></code></td><td data-label="Částka"><?= pay_money($p) ?></td><td data-label="Domů"><?= count(partner_lines($p)) ?></td>
      <td data-label="Stav"><?= e(PAYMENT_STATUS[$p['status']]) ?></td><td class="t-actions"><a href="<?= url('/platby/' . $p['id'] . '/doklad') ?>"><?= $p['invoice_no'] !== '' ? 'Faktura ' . e($p['invoice_no']) : 'Zálohová faktura' ?></a></td></tr><?php endforeach ?>
  </tbody></table>
  <p class="hint">Platby potvrzujete jako ostatní v části <a href="<?= url('/platby') ?>">Platby</a>.</p>
  <?php else: ?><p class="empty">Zatím žádné faktury.</p><?php endif ?>
  <div class="two-col">
    <form method="post" class="card form"><h2>Údaje firmy</h2><?= csrf_field() ?><input type="hidden" name="action" value="save">
      <?php $aresUrl = url('/ares'); $aresFirm = true; require __DIR__ . '/_ares.php'; ?>
      <label>Název <input type="text" name="name" value="<?= e($pa['name']) ?>" data-ares-field="name" required></label>
      <div class="form-row"><label>IČO <input type="text" name="ico" data-ares-field="ico" value="<?= e($pa['ico']) ?>"></label><label>DIČ <input type="text" name="dic" data-ares-field="dic" value="<?= e($pa['dic']) ?>"></label></div>
      <label>Sídlo <input type="text" name="address" data-ares-field="address" value="<?= e($pa['address']) ?>"></label>
      <div class="form-row"><label>Fakturační e-mail <input type="email" name="email" value="<?= e($pa['email']) ?>"></label><label>Telefon <input type="text" name="phone" value="<?= e($pa['phone']) ?>"></label></div>
      <label>Poznámka (vidíte jen Vy) <textarea name="note" rows="2"><?= e($pa['note']) ?></textarea></label>
      <button class="btn" type="submit">Uložit</button></form>
    <div class="card form"><h2>Pracovníci</h2>
      <ul class="plain-list"><?php foreach ($people as $u): ?><li><?= e($u['name']) ?> <small class="muted">· <?= e($u['email']) ?> · <?= $u['role'] === 'owner' ? 'správce účtu' : 'pracovník' ?><?= !$u['active'] ? ' · vypnuto' : ($u['invite_hash'] !== '' ? ' · čeká na pozvánku' : '') ?><?= $u['totp_enabled'] ? ' · 2FA' : '' ?></small></li><?php endforeach ?></ul>
      <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="invite">
        <div class="form-row"><label>Jméno <input type="text" name="name" required></label><label>E-mail <input type="email" name="email" required></label></div>
        <label>Role <select name="role"><option value="member">pracovník</option><option value="owner">správce účtu</option></select></label>
        <button class="btn btn-small" type="submit">Pozvat</button></form>
      <form method="post" data-confirm="<?= $pa['active'] ? 'Vypnout partnera? Jeho pracovníci se nepřihlásí do portálu ani do domů.' : 'Znovu zapnout partnera?' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><button class="btn btn-small btn-ghost" type="submit"><?= $pa['active'] ? 'Vypnout partnera' : 'Zapnout partnera' ?></button></form>
    </div>
  </div>
</div>
