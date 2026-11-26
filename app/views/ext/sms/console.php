<?php
/** @var string $provider @var string $effective @var int $price @var string $stage @var int $free */
?>
<div class="wrap page">
  <header class="page-head"><h1>SMS brána</h1>
    <p class="lead">Společenství platí SMS z kreditu služeb (<a href="<?= url('/kredit') ?>">Kredit služeb</a> – zůstatky, opravy, spotřeba). Edice Premium má <?= (int) $free ?> SMS ročně v ceně. Funkce je teď ve stavu <strong><?= e(BETA_STAGES[$stage] ?? $stage) ?></strong> (Nastavení služby → Nové funkce).</p></header>

  <div class="two-col">
    <form method="post" class="card form"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
      <h2>Brána a cena</h2>
      <label>Brána <select name="provider"><?php foreach (SMS_PROVIDERS as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $provider ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
      <?php if ($effective !== $provider): ?><p class="hint"><strong>Zkušební prostředí:</strong> zprávy se jen zapisují do storage/sms-log.jsonl, skutečná SMS neodejde.</p><?php endif ?>
      <?php foreach (['gosms', 'bulkgate'] as $p): $miss = sms_config_missing($p); ?>
        <p class="hint"><?= e(SMS_PROVIDERS[$p]) ?>: <?= $miss ? 'v storage/config.php chybí <code>' . e(implode('</code>, <code>', array_map(fn($k) => "sms.$k", $miss))) . '</code>' : '<span class="status status-done">klíče jsou v config.php</span>' ?></p>
      <?php endforeach ?>
      <label>Cena 1 SMS pro společenství (Kč bez DPH) <input type="text" name="price" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format($price / 100, 2, '.', ''), '0'), '.')) ?>" required></label>
      <button class="btn" type="submit">Uložit</button>
    </form>

    <form method="post" class="card form" id="zkouska"><?= csrf_field() ?><input type="hidden" name="action" value="test">
      <h2>Zkušební SMS</h2>
      <p>Pošle jednu zprávu přes bránu <strong><?= e(SMS_PROVIDERS[$effective] ?? $effective) ?></strong> bez strhávání kreditu.</p>
      <label>Mobil <input type="tel" name="phone" placeholder="+420 777 123 456" required></label>
      <label>Text <input type="text" name="text" maxlength="160" placeholder="Zkouška SMS brány"></label>
      <button class="btn btn-ghost" type="submit">Odeslat zkoušku</button>
    </form>
  </div>

</div>
