<?php
/* Sloupcový graf spotřeby jednoho měřidla za 12 měsíců (inline SVG, bez knihoven). Vstup: $m z meter_overview(). Průměr domu
   se kreslí jako tečky „byt Vaší velikosti“ (průměr na m² × plocha jednotky). */
$vals = $m['series'];
$houseEq = [];
foreach ($m['house'] as $ym => $perM2) {
    $houseEq[$ym] = $perM2 * (float) $m['area'];
}
$max = max([...array_values($vals), ...array_values($houseEq), 0.000001]);
$w = 340; $h = 150; $top = 18; $bottom = 22; $left = 4; $n = count($vals); $slot = ($w - $left * 2) / 12; $bw = $slot * .62; $shift = 12 - min($n, 12);   // vždy 12 míst, nejnovější vpravo
$y = fn(float $v) => $top + ($h - $top - $bottom) * (1 - $v / $max);
$fmt = fn(float $v) => str_replace('.', ',', (string) round($v, $v >= 10 ? 1 : 2));
$unitLabel = meter_unit_label($m['kind']);
$desc = 'Spotřeba ' . (READING_KINDS[$m['kind']] ?? $m['kind']) . ' po měsících: ' . implode(', ', array_map(fn($ym, $v) => month_label($ym) . ' ' . $fmt($v) . ' ' . $unitLabel, array_keys($vals), $vals));
?>
<svg class="meter-chart" viewBox="0 0 <?= $w ?> <?= $h ?>" role="img" aria-label="<?= e($desc) ?>">
  <line class="mc-base" x1="0" x2="<?= $w ?>" y1="<?= $h - $bottom ?>" y2="<?= $h - $bottom ?>"/>
  <?php $i = 0; foreach ($vals as $ym => $v): $x = $left + $slot * ($i + $shift) + ($slot - $bw) / 2; $isLast = $ym === $m['last']; ?>
  <rect class="mc-bar<?= $isLast ? ' is-last' : '' ?>" x="<?= round($x, 1) ?>" y="<?= round($y($v), 1) ?>" width="<?= round($bw, 1) ?>" height="<?= round(max(0.5, $h - $bottom - $y($v)), 1) ?>" rx="2"><title><?= e(month_label($ym) . ': ' . $fmt($v) . ' ' . $unitLabel) ?></title></rect>
  <?php if (isset($houseEq[$ym])): ?><circle class="mc-house" cx="<?= round($x + $bw / 2, 1) ?>" cy="<?= round($y($houseEq[$ym]), 1) ?>" r="3.2"><title><?= e('Průměr domu pro byt Vaší velikosti: ' . $fmt($houseEq[$ym]) . ' ' . $unitLabel) ?></title></circle><?php endif ?>
  <text class="mc-month" x="<?= round($x + $bw / 2, 1) ?>" y="<?= $h - 6 ?>" text-anchor="middle"><?= e(MONTHS_SHORT[(int) substr($ym, 5, 2)]) ?></text>
  <?php if ($isLast): ?><text class="mc-value" x="<?= round(min($x + $bw / 2, $w - 20), 1) ?>" y="<?= round(max(11, $y($v) - 5), 1) ?>" text-anchor="middle"><?= e($fmt($v)) ?></text><?php endif ?>
  <?php $i++; endforeach ?>
</svg>
