<?php /* Hlasování nejde spustit, dokud nemají všechny jednotky spoluvlastnický podíl. */ $g = shares_gap(); ?>
<div class="card card-soft">
  <h2>Nejdřív doplňte spoluvlastnické podíly</h2>
  <p>Hlasy vlastníků se počítají podle spoluvlastnických podílů – podle nich se určuje usnášeníschopnost i přijetí usnesení.
    <?= $g['units'] === 0 ? 'Web zatím nemá založené žádné jednotky.' : 'Podíl chybí u ' . $g['missing'] . ' z ' . $g['units'] . ' jednotek.' ?></p>
  <p>Nejrychleji je doplníte importem z katastru nemovitostí – jednotky, vlastníky i podíly naráz. Jednotlivě je můžete doplnit i ručně (např. 652/100000).</p>
  <div class="actions"><a class="btn" href="<?= url('/admin/jednotky') ?>">Jednotky</a> <?= help_link('import-z-katastru', 'Jak na import z katastru') ?></div>
</div>
