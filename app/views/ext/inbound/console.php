<?php
/** Konzole → Nastavení služby: stav schránky pro odpovědi e-mailem (app/ext/inbound_lib.php). @var array $s */
?>
  <section class="card" id="odpovedi-emailem">
    <h2>Odpovědi e-mailem</h2>
    <p>Odpověď na e-mail s odpovědí na hlášení nebo na zprávu výboru k závadě se sama připojí ke správnému vláknu. Web odpovědi vyzvedává ze schránky
      <?= $s['address'] !== '' ? '<strong>' . e($s['address']) . '</strong>' : '' ?> při každém spuštění cronu (<code>/cron/&lt;klíč&gt;</code>) a tlačítkem níže.</p>
    <?php if ($s['on']): ?>
      <p><span class="status status-done">Zapnuto</span> Server <?= e($s['host']) ?>. Poslední kontrola: <?= $s['last_check'] !== '' ? e(cz_datetime($s['last_check'])) : 'zatím žádná' ?>.</p>
    <?php else: ?>
      <p><span class="status">Vypnuto</span> <?= e($s['off_reason']) ?> E-maily zatím uvádějí, že odpověď na e-mail se k vláknu nepřipojí.</p>
    <?php endif ?>
    <?php if ($s['last_error'] !== ''): ?><p class="hint"><strong>Poslední chyba</strong> (<?= e(cz_datetime($s['last_error_at'])) ?>): <?= e($s['last_error']) ?><?= $s['last_ok'] > $s['last_error_at'] ? ' – od té doby kontrola proběhla bez chyby.' : '' ?></p><?php endif ?>
    <p>Za posledních 7 dní: zpracováno <strong><?= (int) $s['processed'] ?></strong>, připojeno <strong><?= (int) $s['counts']['attached'] ?></strong><?= $s['counts']['queued'] ? ', čeká na web domu ' . (int) $s['counts']['queued'] : '' ?>,
      odmítnuto <strong><?= (int) $s['counts']['rejected'] + (int) $s['counts']['error'] ?></strong>, automatických zpráv <?= (int) $s['counts']['ignored'] ?>.</p>
    <?php if ($s['recent']): ?>
    <details><summary>Poslední odmítnuté zprávy (<?= count($s['recent']) ?>)</summary>
      <ul><?php foreach ($s['recent'] as $r): ?><li><?= e(cz_datetime($r['at'])) ?> · <?= e($r['sender'] !== '' ? $r['sender'] : 'neznámý odesílatel') ?><?= $r['kind'] !== '' ? ' · ' . e($r['kind'] . $r['thread_id'] . ($r['tenant'] !== '' ? ' (' . $r['tenant'] . ')' : '')) : '' ?> – <?= e($r['reason']) ?></li><?php endforeach ?></ul>
      <p class="hint">Odmítnuté zprávy zůstávají ve schránce ve složce <?= e(INBOUND_FAILED_FOLDER) ?> (<?= (int) max($s['keep_days'], 1) ?> dní). Odesílateli se nic automaticky neposílá.</p>
    </details>
    <?php endif ?>
    <p class="hint">Uchovává se jen text odpovědi ve vlákně (u závad i přílohy, u hlášení snímek). Zpracované e-maily se ve schránce přesouvají do složky <?= e(INBOUND_DONE_FOLDER) ?>
      a mažou <?= $s['keep_days'] === 0 ? 'hned po zpracování' : 'po ' . (int) $s['keep_days'] . ' dnech' ?> (<code>keep_days</code>); záznam o zpracování (odesílatel a výsledek, bez textu) 30 dní.</p>
    <?php if ($s['on']): ?>
    <form method="post" action="<?= url('/nastaveni/schranka') ?>" class="form"><?= csrf_field() ?><button class="btn btn-ghost" type="submit">Zkontrolovat schránku teď</button></form>
    <?php endif ?>
  </section>
