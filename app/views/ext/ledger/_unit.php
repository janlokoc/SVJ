<?php /* Konto jednotky z účetnictví (Platby a zálohy): $acc = ledger_account(). */
$m = fn($v) => ledger_money((float) $v);
$p = $acc['prescription']; ?>
<div class="ledger">
  <?php if ($p && $p['items']): ?>
  <h3>Předpis záloh <small class="muted"><?= e(cz_date($p['doc_date'])) ?></small></h3>
  <table class="ledger-items"><tbody>
    <?php foreach ($p['items'] as [$label, $price]): ?><tr><td translate="no"><?= e($label) ?></td><td class="num"><?= $m($price) ?></td></tr><?php endforeach ?>
    <tr class="sum"><td>Celkem</td><td class="num"><?= $m($p['amount']) ?></td></tr>
  </tbody></table>
  <?php endif ?>
  <h3>Pohledávky a úhrady</h3>
  <?php if (!$acc['receivables']): ?><p class="empty">Za posledních 12 měsíců žádné.</p><?php else: ?>
  <table class="table"><thead><tr><th>Doklad</th><th>Splatnost</th><th>Částka</th><th>Uhrazeno</th><th>Zbývá</th></tr></thead><tbody>
    <?php foreach ($acc['receivables'] as $r): $late = $r['open'] > 0 && $r['due_date'] !== '' && $r['due_date'] < date('Y-m-d'); ?>
    <tr><td data-label="Doklad" translate="no"><?= e($r['text'] !== '' ? $r['text'] : $r['number']) ?></td><td data-label="Splatnost"><?= e(cz_date($r['due_date'] ?: $r['doc_date'])) ?></td>
      <td data-label="Částka" class="num"><?= $m($r['amount']) ?></td><td data-label="Uhrazeno" class="num"><?= $m($r['paid']) ?></td>
      <td data-label="Zbývá" class="num<?= $late ? ' is-debt' : '' ?>"><?= $r['open'] > 0 ? $m($r['open']) . ($late ? ' <small>po splatnosti</small>' : '') : '–' ?></td></tr>
    <?php endforeach ?>
  </tbody></table>
  <p><?php if ($acc['open'] > 0): ?>Zbývá uhradit <strong><?= $m($acc['open']) ?></strong><?php if ($acc['overdue'] > 0): ?>, z toho po splatnosti <strong class="is-debt"><?= $m($acc['overdue']) ?></strong><?php endif ?>.<?php else: ?>Vše je uhrazeno.<?php endif ?></p>
  <?php endif ?>
  <?php if ($acc['payments']): ?>
  <h3>Přijaté platby</h3>
  <table class="table"><thead><tr><th>Datum</th><th>Částka</th><th>Poznámka</th></tr></thead><tbody>
    <?php foreach ($acc['payments'] as $r): ?><tr><td data-label="Datum"><?= e(cz_date($r['doc_date'])) ?></td><td data-label="Částka" class="num"><?= $m($r['amount']) ?></td><td data-label="Poznámka" translate="no"><?= e($r['text']) ?></td></tr><?php endforeach ?>
  </tbody></table>
  <?php endif ?>
  <p class="hint">Podle účetnictví společenství k <?= e(cz_datetime($acc['as_of'])) ?>. Platby se v účetnictví objeví se zpožděním; reklamace řeší výbor nebo správce.</p>
</div>
