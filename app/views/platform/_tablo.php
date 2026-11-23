<?php
/*
 * Zvonkové tablo u vchodu domu: místo jmenovek sousedů jsou na něm části webu společenství.
 * Ilustrace úvodní stránky služby. Při načtení se tlačítka zvonků jednou postupně rozsvítí (ne při omezení pohybu).
 */
$rows = ['Aktuality výboru', 'Dokumenty a zápisy', 'Hlasování', 'Hlášení závad', 'Termíny a shromáždění', 'Kontakty a havárie', 'Zprávy sousedům'];
$h = 150 + count($rows) * 46 + 72;
?>
<svg class="tablo" viewBox="0 0 360 <?= $h ?>" role="img" aria-label="Zvonkové tablo domu Zahradní 12, místo jmenovek části webu: <?= e(implode(', ', $rows)) ?>">
  <defs>
    <linearGradient id="tablo-kov" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" class="tablo-kov-a"/><stop offset=".55" class="tablo-kov-b"/><stop offset="1" class="tablo-kov-a"/>
    </linearGradient>
  </defs>
  <rect class="tablo-plate" x="14" y="14" width="332" height="<?= $h - 28 ?>" rx="16" fill="url(#tablo-kov)"/>
  <circle class="tablo-screw" cx="34" cy="34" r="4"/><circle class="tablo-screw" cx="326" cy="34" r="4"/>
  <circle class="tablo-screw" cx="34" cy="<?= $h - 34 ?>" r="4"/><circle class="tablo-screw" cx="326" cy="<?= $h - 34 ?>" r="4"/>
  <g class="tablo-sign">
    <rect x="48" y="44" width="264" height="76" rx="8"/>
    <rect class="tablo-sign-edge" x="54" y="50" width="252" height="64" rx="5"/>
    <text x="180" y="78" text-anchor="middle" class="tablo-street">Zahradní</text>
    <text x="180" y="106" text-anchor="middle" class="tablo-no">12</text>
  </g>
  <?php foreach ($rows as $i => $label): $y = 146 + $i * 46; ?>
  <g class="tablo-row r<?= $i ?>">
    <rect class="tablo-tag" x="48" y="<?= $y ?>" width="214" height="34" rx="3"/>
    <text class="tablo-name" x="60" y="<?= $y + 22 ?>"><?= e($label) ?></text>
    <circle class="tablo-ring" cx="292" cy="<?= $y + 17 ?>" r="15"/>
    <circle class="tablo-bell" cx="292" cy="<?= $y + 17 ?>" r="10"/>
  </g>
  <?php endforeach ?>
  <g class="tablo-grille">
    <?php for ($k = 0; $k < 5; $k++): ?><rect x="<?= 128 + $k * 22 ?>" y="<?= $h - 62 ?>" width="12" height="20" rx="6"/><?php endfor ?>
  </g>
</svg>
