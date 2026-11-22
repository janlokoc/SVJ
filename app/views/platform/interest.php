<?php uasort($counts, fn($a, $b) => [$b['tenants'], $b['visitors']] <=> [$a['tenants'], $a['visitors']]); ?>
<div class="wrap page">
  <header class="page-head"><h1>Zájem o funkce</h1><p class="lead">Kolikrát kdo klikl na „Mám zájem“ u připravovaných funkcí. Hlas společenství (z administrace, jeden na web) má větší váhu než kliknutí návštěvníka úvodní stránky.</p></header>
  <table class="table"><thead><tr><th>Funkce</th><th>Společenství</th><th>Návštěvníci úvodní stránky</th></tr></thead><tbody>
    <?php foreach ($counts as $key => $c): ?><tr><td data-label="Funkce"><strong><?= e(PLANNED_FEATURES[$key][0]) ?></strong><br><small class="muted"><?= e(PLANNED_FEATURES[$key][1]) ?></small></td>
      <td data-label="Společenství"><span class="stat-num"><?= $c['tenants'] ?></span></td><td data-label="Návštěvníci úvodní stránky"><?= $c['visitors'] ?></td></tr><?php endforeach ?>
  </tbody></table>
  <?php if ($rows): ?><h2>Poslední projevy zájmu</h2>
  <table class="table"><thead><tr><th>Kdy</th><th>Funkce</th><th>Kdo</th><th>Kontakt</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td data-label="Kdy"><?= cz_datetime($r['at']) ?></td><td data-label="Funkce"><?= e(PLANNED_FEATURES[$r['feature']][0] ?? $r['feature']) ?></td>
      <td data-label="Kdo"><?= $r['tenant'] !== '' ? e($r['tenant_name'] ?? $r['tenant']) : '<span class="muted">návštěvník</span>' ?></td><td data-label="Kontakt"><?= e($r['contact']) ?></td></tr><?php endforeach ?>
  </tbody></table><?php endif ?>
</div>
