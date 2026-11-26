<?php
/** @var array $links @var array $institutions @var ?string $instError */
?>
<div class="wrap page">
  <header class="page-head"><h1>Propojení s bankou</h1>
    <p class="lead">Má-li společenství účet u jiné banky než Fio (Česká spořitelna, KB, ČSOB, Raiffeisenbank, Moneta, UniCredit, Air Bank, mBank…), web si může příchozí platby stahovat sám – přes licencovaného poskytovatele bankovních dat. Přístup je <strong>jen pro čtení</strong>: web s ním nic nezaplatí.</p>
    <p><a href="<?= url('/admin/platby') ?>#banka">← Platby na jednotku</a> <?= function_exists('help_link') ? help_link('propojeni-banky') : '' ?></p></header>
  <?php $betaKey = 'bank-psd2'; require __DIR__ . '/../../admin/_beta.php'; ?>

  <?php foreach ($links as $l): $st = $l['status']; ?>
  <section class="card">
    <h2><?= e($l['institution_name']) ?></h2>
    <p><?php if ($st === 'LN'): ?><span class="status status-done">Propojeno</span>
      <?php if ($l['days_left'] !== null && $l['days_left'] <= PSD2_WARN_DAYS[0]): ?> <strong>Souhlas vyprší <?= e(cz_date($l['valid_until'])) ?></strong> – obnovte ho připojením banky znovu (pak staré propojení zrušte).
      <?php else: ?> Souhlas platí do <?= e(cz_date($l['valid_until'])) ?>. Platby se stahují samy jednou denně.<?php endif ?>
    <?php else: ?><span class="status"><?= e(PSD2_REQ_STATES[$st] ?? $st) ?></span><?php endif ?></p>
    <?php if ($l['accounts']): ?><ul><?php foreach ($l['accounts'] as $a): ?><li><?= e($a['iban'] !== '' ? psd2_iban_to_cz($a['iban']) : 'účet') ?><?= $a['owner'] !== '' ? ' · ' . e($a['owner']) : '' ?>
      <?= $a['last_fetch'] !== '' ? '<small class="muted"> · naposledy ' . e(substr($a['last_fetch'], 0, 16)) . '</small>' : '' ?></li><?php endforeach ?></ul><?php endif ?>
    <?php if ($l['last_result'] !== ''): ?><p class="hint"><?= e($l['last_result']) ?></p><?php endif ?>
    <div class="actions">
      <?php if ($st === 'LN'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="refresh"><input type="hidden" name="link" value="<?= (int) $l['id'] ?>"><button class="btn btn-ghost" type="submit">Stáhnout teď</button></form><?php endif ?>
      <form method="post" data-confirm="Zrušit propojení s bankou <?= e($l['institution_name']) ?>? Už stažené platby zůstanou."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="link" value="<?= (int) $l['id'] ?>"><button class="btn btn-ghost" type="submit">Zrušit propojení</button></form>
    </div>
    <?php if ($st === 'LN'): ?><p class="hint">Banky dovolují stáhnout pohyby nejvýš <?= PSD2_DAILY_LIMIT ?>× denně na účet (včetně automatického stažení).</p><?php endif ?>
  </section>
  <?php endforeach ?>

  <section class="card">
    <h2><?= $links ? 'Připojit další banku' : 'Připojit banku' ?></h2>
    <?php if ($instError): ?><p><?= e($instError) ?></p>
    <?php else: ?>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="connect">
      <label>Banka <select name="institution" required><option value="">– vyberte banku –</option><?php foreach ($institutions as $i): ?><option value="<?= e($i['id']) ?>"><?= e($i['name']) ?></option><?php endforeach ?></select></label>
      <p class="hint">Po kliknutí Vás přesměrujeme do internetového bankovnictví. Přihlaste se tam jako osoba s přístupem k účtu společenství, vyberte účet a potvrďte souhlas. Souhlas platí <?= PSD2_CONSENT_DAYS ?> dní, potom ho stejně snadno obnovíte – 14 dní předem Vám připomeneme e-mailem.</p>
      <button class="btn" type="submit">Pokračovat do banky</button></form>
    <?php endif ?>
  </section>
</div>
