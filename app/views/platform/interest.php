<?php uasort($stats, fn($a, $b) => [$b['visitors'], $b['views']] <=> [$a['visitors'], $a['views']]); $max = max(1, max(array_column($stats, 'visitors'))); ?>
<div class="wrap page">
  <header class="page-head"><h1>Zájem o funkce</h1><p class="lead">Kolik lidí si otevřelo detail připravované funkce z ceníku na úvodní stránce nebo z Předplatného. Unikátní návštěvník se počítá jednou za den; roboti se nepočítají.</p></header>
  <p class="chips"><?php foreach ([0 => 'Celkem', 7 => '7 dní', 30 => '30 dní', 90 => '90 dní'] as $d => $label): ?><a href="<?= url('/zajem') . ($d ? '?dni=' . $d : '') ?>"<?= $days === $d ? ' aria-current="true"' : '' ?>><?= $label ?></a> <?php endforeach ?></p>
  <table class="table"><thead><tr><th>Funkce</th><th>Unikátní návštěvníci</th><th>Prokliky celkem</th><th>Společenství</th><th>Chtějí zprávu</th><th>Poslední proklik</th></tr></thead><tbody>
    <?php foreach ($stats as $key => $c): ?><tr><td data-label="Funkce"><strong><?= e(PLANNED_FEATURES[$key][0]) ?></strong><progress class="bar" value="<?= $c['visitors'] ?>" max="<?= $max ?>" aria-hidden="true"></progress></td>
      <td data-label="Unikátní návštěvníci"><span class="stat-num"><?= $c['visitors'] ?></span></td><td data-label="Prokliky celkem"><?= $c['views'] ?></td>
      <td data-label="Společenství"><?= $c['tenants'] ?></td><td data-label="Chtějí zprávu"><?= $c['notify'] ?></td>
      <td data-label="Poslední proklik"><?= $c['last'] !== '' ? cz_datetime($c['last']) : '<span class="muted">–</span>' ?></td></tr><?php endforeach ?>
  </tbody></table>
  <p class="hint">„Společenství“ = kolik webů má správce, který si detail otevřel z administrace – to jsou platící nebo zkoušející zákazníci, jejich zájem má největší váhu.</p>
  <?php if ($tenantViews): ?><h2>Která společenství se dívala</h2>
  <table class="table"><thead><tr><th>Společenství</th><th>Funkce</th><th>Počet zobrazení</th><th>Naposledy</th></tr></thead><tbody>
    <?php foreach ($tenantViews as $r): ?><tr><td data-label="Společenství"><?= e($r['tenant_name'] ?? $r['tenant']) ?></td><td data-label="Funkce"><?= e(PLANNED_FEATURES[$r['feature']][0] ?? $r['feature']) ?></td>
      <td data-label="Počet zobrazení"><?= (int) $r['n'] ?></td><td data-label="Naposledy"><?= cz_datetime($r['last']) ?></td></tr><?php endforeach ?>
  </tbody></table><?php endif ?>
  <?php if ($rows): ?><h2>Chtějí zprávu, až bude hotovo</h2>
  <table class="table"><thead><tr><th>Kdy</th><th>Funkce</th><th>Kdo</th><th>Kontakt</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td data-label="Kdy"><?= cz_datetime($r['at']) ?></td><td data-label="Funkce"><?= e(PLANNED_FEATURES[$r['feature']][0] ?? $r['feature']) ?></td>
      <td data-label="Kdo"><?= $r['tenant'] !== '' ? e($r['tenant_name'] ?? $r['tenant']) : '<span class="muted">návštěvník</span>' ?></td><td data-label="Kontakt"><?= e($r['contact']) ?></td></tr><?php endforeach ?>
  </tbody></table><?php endif ?>
</div>
