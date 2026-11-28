<div class="wrap page narrow">
  <header class="page-head"><h1>Platby a zálohy</h1><p class="lead">Předpis záloh a stav plateb u Vašich jednotek podle účetnictví společenství.</p></header>
  <?php if (!$rows): ?><p class="empty"><?= $hasUnits ? 'Výbor zatím přehled plateb k Vaší jednotce nezveřejnil.' : 'K Vašemu účtu není přiřazena žádná jednotka.' ?></p><?php endif ?>
  <?php foreach ($rows as $r): $bal = (float) $r['balance']; ?>
  <section class="card pay-unit">
    <h2 translate="no"><?= e(unit_name($r)) ?></h2>
    <dl class="pay-data">
      <dt>Měsíční záloha</dt><dd><?= account_money((float) $r['prescribed']) ?></dd>
      <?php if ($r['bank']): ?><dt>Platby přijaté po <?= cz_date($r['as_of']) ?></dt><dd><?= account_money($r['bank']) ?></dd><?php endif ?>
      <dt><?= $r['bank'] ? 'Stav včetně těchto plateb' : 'Stav k ' . cz_date($r['as_of']) ?></dt><dd><strong class="<?= $bal < 0 ? 'is-debt' : 'is-ok' ?>"><?= $bal < 0 ? 'nedoplatek ' . account_money(-$bal) : ($bal > 0 ? 'přeplatek ' . account_money($bal) : 'vyrovnáno') ?></strong></dd>
      <?php if (setting('account') !== ''): ?><dt>Účet společenství</dt><dd translate="no"><?= e(setting('account')) ?></dd><?php endif ?>
      <?php if ((string) $r['vs'] !== ''): ?><dt>Variabilní symbol</dt><dd><code><?= e($r['vs']) ?></code></dd><?php endif ?>
    </dl>
    <?php if ($r['qr_debt'] || $r['qr_month']): ?><div class="pay">
      <?php if ($r['qr_debt']): ?><div class="pay-qr"><?= $r['qr_debt'] ?><small>Doplatit nedoplatek</small></div><?php endif ?>
      <?php if ($r['qr_month']): ?><div class="pay-qr"><?= $r['qr_month'] ?><small>Zaplatit měsíční zálohu</small></div><?php endif ?>
    </div><?php endif ?>
    <?php if ((string) $r['account_note'] !== ''): ?><p class="hint" translate="no"><?= e($r['account_note']) ?></p><?php endif ?>
    <?php if (function_exists('ledger_account') && ($acc = ledger_account((int) $r['id'])) && ($acc['receivables'] || $acc['payments'])): require __DIR__ . '/ext/ledger/_unit.php'; endif ?>
  </section>
  <?php endforeach ?>
  <?php if ($rows): ?><p class="hint">Údaje nahrává výbor z účetnictví k uvedenému dni; platby, které na účet společenství přijdou později, se připočítávají podle výpisu z banky.</p><?php endif ?>
</div>
