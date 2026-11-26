<?php /* Graf výhledu fondu oprav: $chart = modf_fund_chart(), $proj. Výšky a polohy nastaví app.js z data-b/data-h (CSP). */ ?>
<div class="modf-chart" role="img" aria-label="Výhled zůstatku fondu oprav a plánovaných nákladů po letech <?= (int) $proj['years'][0]['year'] ?>–<?= (int) end($proj['years'])['year'] ?>; podrobně v tabulce pod grafem.">
  <span class="modf-zero" data-b="<?= $chart['zero'] ?>" aria-hidden="true"></span>
  <div class="modf-cols">
    <?php foreach ($chart['cols'] as $c): ?><div class="modf-col<?= $c['negative'] ? ' is-neg' : '' ?>" tabindex="0" data-tip="<?= e($c['year'] . ': zůstatek na konci roku ' . modf_money($c['end']) . ' · opravy ' . modf_money($c['cost'])) ?>">
      <span class="modf-bar modf-bar-cost" data-b="<?= $c['cost_b'] ?>" data-h="<?= $c['cost_h'] ?>"></span>
      <span class="modf-bar modf-bar-bal" data-b="<?= $c['bal_b'] ?>" data-h="<?= $c['bal_h'] ?>"></span>
      <span class="modf-x"><?= (int) $c['year'] ?></span></div><?php endforeach ?>
  </div>
</div>
<p class="modf-legend"><span class="modf-key modf-key-bal"></span> zůstatek fondu na konci roku <span class="modf-key modf-key-neg"></span> zůstatek v mínusu <span class="modf-key modf-key-cost"></span> plánované opravy</p>
<details class="dash-table"><summary>Zobrazit jako tabulku</summary>
  <table class="table"><thead><tr><th>Rok</th><th>Na začátku</th><th>Příspěvky</th><th>Opravy</th><th>Na konci</th></tr></thead><tbody>
    <?php foreach ($proj['years'] as $y): ?><tr<?= $y['negative'] ? ' class="is-neg"' : '' ?>><td data-label="Rok"><?= (int) $y['year'] ?></td><td data-label="Na začátku"><?= modf_money($y['start']) ?></td>
      <td data-label="Příspěvky"><?= modf_money($y['income']) ?></td><td data-label="Opravy"><?= modf_money($y['cost']) ?></td><td data-label="Na konci"><strong><?= modf_money($y['end']) ?></strong></td></tr><?php endforeach ?>
  </tbody></table></details>
