<?php /* Štítek a výzva u funkce v betě. $betaKey = klíč z BETA_FEATURES. */ if (beta_is($betaKey)): ?>
<div class="callout beta-note"><span class="tag tag-beta">Beta</span> <strong>Novou funkci máte v edici Premium dřív než ostatní.</strong> Ještě ji ladíme – napište nám, co Vám chybí nebo nefunguje, podle Vašich zkušeností ji upravíme.
  <a href="<?= url('/hlaseni?type=idea&z=' . rawurlencode(BETA_FEATURES[$betaKey][1])) ?>">Napsat provozovateli</a></div>
<?php endif ?>
