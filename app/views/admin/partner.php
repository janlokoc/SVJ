<?php /* Správce domu (správcovská firma): $t = řádek webu v databázi služby, $partner = firma nebo null */ $inPartner = !empty($_SESSION['partner']); ?>
<div class="wrap page narrow">
  <h1>Správce domu</h1>
  <p class="lead">Správcovská firma může mít přístup do administrace webu a spravovat ho za výbor. Přístup schvaluje a kdykoli může ukončit jen výbor.</p>
  <?php if (!$partner): ?>
  <div class="card">
    <p>Web teď nespravuje žádná správcovská firma.</p>
    <p class="hint">Pokud dům spravuje firma, která používá tuto službu, může z partnerského portálu požádat o přístup. Žádost se Vám pak objeví tady a v přehledu administrace.</p>
  </div>
  <?php else: ?>
  <div class="card">
    <h2><?= e($partner['name']) ?></h2>
    <p><?= $partner['ico'] !== '' ? 'IČO ' . e($partner['ico']) . '<br>' : '' ?><?= nl2br(e($partner['address'])) ?><?= $partner['email'] !== '' ? '<br>' . e($partner['email']) : '' ?><?= $partner['phone'] !== '' ? ' · ' . e($partner['phone']) : '' ?></p>
    <?php if ($t['partner_status'] === 'pending'): ?>
      <div class="callout">Firma žádá o přístup do administrace webu (od <?= cz_datetime($t['partner_since']) ?>). Dokud žádost neschválíte, k webu přístup nemá.</div>
      <?php if (!$inPartner): ?>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <label class="check"><input type="checkbox" name="billing" checked> <span>Předplatné webu bude hradit správce <small>Správce ho zaplatí na své společné faktuře za všechny domy. Když volbu vypnete, předplatné si dál hradí společenství samo.</small></span></label>
        <p class="hint">Pracovníci firmy budou do administrace vstupovat přes svůj partnerský portál. V záznamech webu bude u každé změny vidět jejich jméno i název firmy.</p>
        <div class="actions"><button class="btn" type="submit" name="action" value="approve">Schválit přístup</button>
          <button class="btn btn-ghost" type="submit" name="action" value="reject" data-confirm="Odmítnout žádost firmy <?= e($partner['name']) ?>?">Odmítnout</button></div>
      </form>
      <?php endif ?>
    <?php else: ?>
      <p>Firma má přístup do administrace od <?= cz_date(substr((string) $t['partner_since'], 0, 10)) ?>.<?= $t['partner_billing'] ? ' Předplatné webu hradí na své společné faktuře.' : ' Předplatné si hradí společenství samo.' ?></p>
      <?php $pids = partner_tenant_user_ids(); $people = $pids ? all('SELECT name, last_login_at, active FROM users WHERE id IN (' . implode(',', $pids) . ') ORDER BY name') : []; ?>
      <?php if ($people): ?><h3>Pracovníci firmy, kteří web otevřeli</h3>
      <ul class="plain-list"><?php foreach ($people as $pp): ?><li><?= e($pp['name']) ?><?= $pp['last_login_at'] ? ' <small class="muted">· naposledy ' . cz_datetime($pp['last_login_at']) . '</small>' : '' ?></li><?php endforeach ?></ul><?php endif ?>
      <p class="hint">Co pracovníci firmy na webu změnili, najdete v <a href="<?= url('/admin/zaznamy') ?>">Záznamech</a>. Do limitu správců webu se nepočítají.</p>
      <?php if (!$inPartner): ?>
      <form method="post" data-confirm="Ukončit přístup firmy <?= e($partner['name']) ?>? Její účty ve webu se vypnou. Zaplacené předplatné zůstává; další období si společenství hradí samo.">
        <?= csrf_field() ?><button class="btn btn-ghost" type="submit" name="action" value="revoke">Ukončit přístup správce</button></form>
      <?php else: ?><p class="hint">O přístupu rozhoduje výbor – ve Vašem účtu správce se tato volba nezobrazuje.</p><?php endif ?>
    <?php endif ?>
  </div>
  <?php endif ?>
</div>
