<?php
/** @var string $provider @var string $effective @var int $price @var int $pack @var array $tenants @var array $balances @var array $ledger @var string $stage */
?>
<div class="wrap page">
  <header class="page-head"><h1>SMS brána a kredity</h1>
    <p class="lead">Společenství posílají SMS vlastníkům z kreditu, který jim zde připíšete po zaplacení balíčku. Funkce je teď ve stavu <strong><?= e(BETA_STAGES[$stage] ?? $stage) ?></strong> (Nastavení služby → Nové funkce).</p></header>

  <div class="two-col">
    <form method="post" class="card form"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
      <h2>Brána a cena</h2>
      <label>Brána <select name="provider"><?php foreach (SMS_PROVIDERS as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $provider ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
      <?php if ($effective !== $provider): ?><p class="hint"><strong>Zkušební prostředí:</strong> zprávy se jen zapisují do storage/sms-log.jsonl, skutečná SMS neodejde.</p><?php endif ?>
      <?php foreach (['gosms', 'bulkgate'] as $p): $miss = sms_config_missing($p); ?>
        <p class="hint"><?= e(SMS_PROVIDERS[$p]) ?>: <?= $miss ? 'v storage/config.php chybí <code>' . e(implode('</code>, <code>', array_map(fn($k) => "sms.$k", $miss))) . '</code>' : '<span class="status status-done">klíče jsou v config.php</span>' ?></p>
      <?php endforeach ?>
      <label>Cena 1 SMS pro společenství (Kč bez DPH) <input type="text" name="price" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format($price / 100, 2, '.', ''), '0'), '.')) ?>" required></label>
      <label>Balíček (počet SMS) <input type="number" name="pack" min="1" max="100000" value="<?= (int) $pack ?>" required></label>
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

  <section class="card" id="kredity">
    <h2>Kredity společenství</h2>
    <form method="post" class="form inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="credit">
      <label>Společenství <select name="tenant" required><?php foreach ($tenants as $t): ?><option value="<?= e($t['slug']) ?>"><?= e($t['name']) ?> (<?= (int) ($balances[$t['slug']]['n'] ?? 0) ?> SMS)</option><?php endforeach ?></select></label>
      <label>Počet SMS <input type="number" name="count" required placeholder="např. 200, nebo -10 pro opravu"></label>
      <label>Poznámka <input type="text" name="note" maxlength="200" placeholder="např. faktura 20260012"></label>
      <button class="btn" type="submit">Připsat</button></form>
    <p class="hint">Společenství objednává balíček v administraci (SMS zprávy → Objednat kredit); objednávka přijde e-mailem na kontaktní adresu služby. Výzvu k platbě a fakturu zatím vystavte ručně.</p>
    <?php if ($ledger): ?>
    <table class="table"><thead><tr><th>Kdy</th><th>Společenství</th><th>Pohyb</th><th>Poznámka</th></tr></thead><tbody>
      <?php foreach ($ledger as $l): ?><tr><td data-label="Kdy"><?= e(substr($l['at'], 0, 16)) ?></td><td data-label="Společenství"><?= e($l['tenant']) ?></td>
        <td data-label="Pohyb"><?= (int) $l['delta'] > 0 ? '+' : '' ?><?= (int) $l['delta'] ?> <small class="muted"><?= e($l['kind']) ?></small></td><td data-label="Poznámka"><small><?= e($l['note']) ?><?= $l['by_whom'] !== '' ? ' · ' . e($l['by_whom']) : '' ?></small></td></tr><?php endforeach ?>
    </tbody></table>
    <?php endif ?>
  </section>
</div>
