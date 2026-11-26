<?php
/** @var string $text @var bool $plain @var ?array $preview @var array $recipients @var array $bad @var bool $managed @var ?int $balance (kolik SMS jde poslat) @var int $creditH @var int $freeLeft @var int $freeYear @var int $price (haléře) @var array $log @var bool $staging */
$priceCzk = $price / 100;
?>
<div class="wrap page">
  <header class="page-head"><h1>SMS zprávy</h1>
    <p class="lead">Naléhavé oznámení (havárie, odstávka vody, uzavřený vchod) pošlete vlastníkům i jako SMS. Běžná sdělení posílejte e-mailem – SMS se platí za každou zprávu.</p>
    <p><?= function_exists('help_link') ? help_link('sms-zpravy') : '' ?></p></header>
  <?php $betaKey = 'sms'; require __DIR__ . '/../../admin/_beta.php'; ?>
  <?php if ($staging): ?><p class="callout">Zkušební režim: zprávy se jen zaznamenají, žádná SMS skutečně neodejde.</p><?php endif ?>

  <div class="two-col">
    <section class="card">
      <h2>Nová zpráva vlastníkům</h2>
      <p>Příjemci: <strong><?= count($recipients) ?></strong> vlastníků s mobilem na webu.<?= $bad ? ' <span class="muted">Neplatné nebo pevné číslo má ' . count($bad) . ' – opravte ho v části Uživatelé.</span>' : '' ?></p>
      <form method="post" class="form"><?= csrf_field() ?>
        <label>Text zprávy <textarea name="text" rows="5" maxlength="2000" required><?= e($preview['text'] ?? $text) ?></textarea></label>
        <?php $aiTarget = 'textarea[name=text]'; $aiTasks = ['sms', 'polish']; $aiContext = ''; $aiFields = []; $aiOutput = null; require __DIR__ . '/../../admin/_ai.php'; ?>
        <label class="check"><input type="checkbox" name="plain" value="1"<?= $plain ? ' checked' : '' ?>> Odstranit diakritiku (do jedné SMS se vejde 160 znaků místo 70)</label>
        <p class="hint">Na konec zprávy připište, kdo ji posílá (např. „Výbor SVJ Zahradní 12“). Odpověď na SMS nepřijde na web.</p>
        <button class="btn btn-ghost" type="submit" name="action" value="preview">Náhled a cena</button>
      </form>
      <?php if ($preview): $i = $preview['info']; ?>
      <div class="callout" id="nahled">
        <h3>Náhled</h3>
        <p class="sms-preview"><?= nl2br(e($preview['text'])) ?></p>
        <p><small class="muted"><?= (int) $i['units'] ?> znaků · <?= $i['encoding'] === 'GSM-7' ? 'bez diakritiky' : 's diakritikou nebo zvláštními znaky' ?> · <?= (int) $i['segments'] ?> <?= $i['segments'] === 1 ? 'SMS' : 'SMS (zpráva se rozdělí)' ?> na příjemce</small></p>
        <p>Celkem <strong><?= (int) $preview['total'] ?> SMS</strong> pro <?= (int) $preview['recipients'] ?> vlastníků<?= $managed ? ', tj. ' . e(sms_money($preview['czk'])) . ' bez DPH' : '' ?>.
          <?= $managed && $balance !== null && $balance < $preview['total'] ? '<br><strong>Kredit služeb nestačí (vystačí na ' . (int) $balance . ' SMS).</strong> ' . credit_topup_link() : '' ?></p>
        <?php if ($preview['recipients'] > 0 && (!$managed || $balance >= $preview['total'])): ?>
        <form method="post" data-confirm="Odeslat SMS <?= (int) $preview['recipients'] ?> vlastníkům?"><?= csrf_field() ?>
          <input type="hidden" name="text" value="<?= e($preview['text']) ?>"><?= $plain ? '<input type="hidden" name="plain" value="1">' : '' ?>
          <button class="btn" type="submit" name="action" value="send">Odeslat <?= (int) $preview['recipients'] ?> vlastníkům</button></form>
        <?php endif ?>
      </div>
      <?php endif ?>
    </section>

    <section class="card" id="kredit">
      <h2>Placení</h2>
      <?php if ($managed): ?>
      <p class="sms-balance"><strong><?= (int) $balance ?></strong> SMS</p>
      <p>Tolik SMS teď můžete poslat: <?= $freeYear ? (int) $freeLeft . ' z ' . (int) $freeYear . ' SMS v ceně edice Premium na letošní rok a ' : '' ?>kredit služeb <?= e(credit_money($creditH)) ?>.</p>
      <p class="hint">Jedna SMS stojí <?= e(credit_money($price)) ?> bez DPH a platí se z kreditu služeb<?= $freeYear ? ' (nejdřív se čerpají SMS v ceně edice)' : '' ?>. Dlouhá zpráva se rozdělí na více SMS (s diakritikou po 70 znacích, bez ní po 160) a každá část se počítá zvlášť. Neodeslaná zpráva se do kreditu vrací.</p>
      <p><?= credit_topup_link() ?> <a href="<?= url('/admin/kredit') ?>">Výpis pohybů kreditu</a></p>
      <?php else: ?>
      <p>Web neběží na předplatném služby – SMS se posílají přes bránu nastavenou v config.php bez kreditu.</p>
      <?php endif ?>
    </section>
  </div>

  <?php if ($log): ?>
  <section class="card">
    <h2>Odeslané zprávy</h2>
    <table class="table"><thead><tr><th>Kdy</th><th>Číslo</th><th>SMS</th><th>Stav</th><th>Text</th></tr></thead><tbody>
      <?php foreach ($log as $s): ?><tr<?= $s['status'] === 'sent' ? '' : ' class="is-unmatched"' ?>>
        <td data-label="Kdy"><?= e(cz_date(substr($s['at'], 0, 10))) ?> <small class="muted"><?= e(substr($s['at'], 11, 5)) ?></small></td>
        <td data-label="Číslo"><?= e(substr($s['phone'], 0, -3)) ?>•••</td><td data-label="SMS"><?= (int) $s['segments'] ?></td>
        <td data-label="Stav"><?= e(['sent' => 'odesláno', 'failed' => 'neodesláno', 'refused' => 'bez kreditu'][$s['status']] ?? $s['status']) ?><?= $s['error'] !== '' ? '<br><small class="muted">' . e($s['error']) . '</small>' : '' ?></td>
        <td data-label="Text"><small><?= e(mb_strimwidth($s['text'], 0, 80, '…')) ?></small></td></tr><?php endforeach ?>
    </tbody></table>
  </section>
  <?php endif ?>
</div>
