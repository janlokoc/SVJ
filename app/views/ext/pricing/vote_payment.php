<?php /* Modul G: platební údaje jednorázového hlasování. $p = platba nebo null, $qr, $iban, $active = počet platných hlasování navíc. */ ?>
<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/admin/hlasovani') ?>">← Hlasování</a></p>
  <h1><?= e($title) ?></h1>
  <?php if ($active): ?><div class="flash flash-ok">Zaplacené jednorázové hlasování platí. Nové hlasování můžete <a href="<?= url('/admin/hlasovani/nove') ?>">vypsat teď</a>.</div><?php endif ?>
  <?php if ($p): ?>
  <div class="card">
    <h2>Platba za jednorázové hlasování</h2>
    <?php if ($p['status'] === 'claimed'): ?><div class="flash flash-info">Platbu jste nahlásili jako odeslanou <?= cz_datetime($p['claimed_at']) ?>. Čeká na potvrzení provozovatelem – obvykle do dvou pracovních dnů.</div><?php endif ?>
    <div class="pay"><?php if ($qr): ?><div class="pay-qr"><?= $qr ?><small>Načtěte v bankovní aplikaci</small></div><?php endif ?>
      <dl class="pay-data"><dt>Částka</dt><dd><strong><?= pay_money($p) ?></strong><?= pay_vat_hint($p) ?></dd><?php if ($iban): ?><dt>Číslo účtu</dt><dd><?= e(pset('account')) ?></dd><?php endif ?><dt>Variabilní symbol</dt><dd><code><?= e($p['vs']) ?></code></dd></dl></div>
    <p class="hint">Po připsání platby můžete do <?= VOTE_PASS_DAYS ?> dní vypsat jedno hlasování nad limit edice Zdarma. Fakturu dostanete e-mailem a najdete ji v části Předplatné.</p>
    <div class="actions">
      <?php if ($p['status'] === 'pending'): ?><form method="post" action="<?= url('/admin/hlasovani/jednorazove') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="claim"><button class="btn" type="submit">Platbu jsem odeslal(a)</button></form><?php endif ?>
      <a class="btn btn-ghost" href="<?= url('/admin/predplatne/doklad/' . $p['id']) ?>">Zálohová faktura k tisku</a>
      <?php if ($p['status'] === 'pending'): ?><form method="post" action="<?= url('/admin/hlasovani/jednorazove') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-ghost" type="submit">Zrušit objednávku</button></form><?php endif ?>
    </div>
  </div>
  <?php elseif (!$active): ?>
  <p class="muted">Žádné jednorázové hlasování nemáte objednané. Objednat ho můžete ve formuláři <a href="<?= url('/admin/hlasovani/nove') ?>">nového hlasování</a>, když vyčerpáte limit edice Zdarma.</p>
  <?php endif ?>
</div>
