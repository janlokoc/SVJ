<?php /* Plán oprav a fond oprav pro obyvatele (jen pro čtení). $s, $repairs, $proj, $chart, $mine. */ ?>
<div class="wrap page">
  <header class="page-head"><h1>Plán oprav a fond oprav</h1>
    <p class="lead">Na jaké opravy domu výbor šetří a jak se bude vyvíjet fond oprav. Přehled připravil výbor; o výši příspěvků rozhoduje shromáždění.</p></header>
  <div class="dash-tiles dash-tiles-4">
    <div class="dash-tile"><span class="dash-num"><?= modf_money($s['balance']) ?></span><span class="dash-label">stav fondu</span><span class="dash-sub"><?= $s['balance_date'] !== '' ? 'k ' . cz_date($s['balance_date']) : '' ?></span></div>
    <div class="dash-tile"><span class="dash-num"><?= modf_money($proj['monthly']) ?></span><span class="dash-label">příspěvky domu měsíčně</span><span class="dash-sub"><?= $s['mode'] === 'flat' ? 'stejně za každou jednotku' : 'podle spoluvlastnických podílů' ?></span></div>
    <?php foreach ($mine as $m): ?><div class="dash-tile"><span class="dash-num"><?= modf_money($m['amount'], 2) ?></span><span class="dash-label">měsíčně za jednotku <?= e(unit_name($m['unit'])) ?></span><span class="dash-sub">příspěvek do fondu oprav</span></div><?php endforeach ?>
  </div>
  <?php if ($proj['first_negative'] !== null): ?><p class="callout">Při současných příspěvcích by fond v roce <?= (int) $proj['first_negative'] ?> nestačil na plánované opravy.</p><?php endif ?>
  <section class="card" aria-labelledby="modf-chart-h"><h2 id="modf-chart-h">Výhled fondu</h2><?php require __DIR__ . '/_chart.php'; ?></section>
  <section class="card" aria-labelledby="modf-rep-h"><h2 id="modf-rep-h">Plánované opravy</h2>
    <?php if (!$repairs): ?><p class="empty">Žádné opravy v plánu.</p><?php else: ?>
    <table class="table"><thead><tr><th>Oprava</th><th>Rok</th><th>Odhad nákladů</th><th>Stav</th></tr></thead><tbody>
      <?php foreach ($repairs as $r): ?><tr><td data-label="Oprava"><strong><?= e($r['title']) ?></strong><?= $r['note'] !== '' ? '<br><small class="muted">' . e($r['note']) . '</small>' : '' ?></td><td data-label="Rok"><?= (int) $r['year'] ?></td>
        <td data-label="Odhad nákladů"><?= modf_money((int) $r['cost']) ?></td><td data-label="Stav"><?= e(MODF_REPAIR_STATUS[$r['status']] ?? '') ?></td></tr><?php endforeach ?>
    </tbody></table><?php endif ?>
  </section>
</div>
