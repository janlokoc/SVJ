<?php $qs = fn(array $over) => url('/prehled?' . http_build_query(array_filter(array_merge(['obdobi' => $days !== 90 ? $days : '', 'razeni' => $sort !== 'pozornost' ? $sort : ''], $over)))); $per = DASH_PERIODS[$days]; ?>
<div class="wrap page">
  <header class="page-head"><h1>Přehled správy</h1>
    <p class="lead">Jak se daří ve všech domech, které spravujete: hlášení, rychlost reakce, revize a komunikace s obyvateli. Dům, který potřebuje pozornost, je nahoře. Totéž vidí výbor každého domu ve své administraci (Přehled správy).</p></header>
  <div class="filter-row">
    <nav class="chips" aria-label="Období"><?php foreach (DASH_PERIODS as $k => $label): ?><a href="<?= $qs(['obdobi' => $k !== 90 ? $k : '']) ?>"<?= $days === $k ? ' aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach ?></nav>
  </div>
  <div class="dash-tiles dash-tiles-4">
    <a class="dash-tile<?= $tot['late'] ? ' is-bad' : '' ?>" href="<?= url('/hlaseni') ?>"><span class="dash-num"><?= $tot['open'] ?></span><span class="dash-label">otevřených hlášení</span><span class="dash-sub"><?= $tot['late'] ?> po termínu · <?= $tot['unassigned'] ?> bez řešitele</span></a>
    <div class="dash-tile"><span class="dash-num"><?= $tot['resolved'] ?></span><span class="dash-label">vyřešeno za <?= $per ?></span><span class="dash-sub">nahlášeno <?= $tot['created'] ?></span></div>
    <div class="dash-tile"><span class="dash-num"><?= dash_hours($tot['react']) ?></span><span class="dash-label">průměrná první reakce</span><span class="dash-sub">průměr domů</span></div>
    <a class="dash-tile<?= $tot['revize_late'] ? ' is-bad' : '' ?>" href="<?= url('/kalendar') ?>"><span class="dash-num"><?= $tot['revize_late'] ?></span><span class="dash-label">revizí po termínu</span><span class="dash-sub">ve všech domech</span></a>
  </div>
  <?php if (!$rows): ?><p class="empty">Zatím nespravujete žádný dům.</p><?php else: ?>
  <p class="muted">Seřadit: <?php foreach ($sorts as $k => $label): ?><a href="<?= $qs(['razeni' => $k !== 'pozornost' ? $k : '']) ?>"<?= $sort === $k ? ' aria-current="true" class="is-active-sort"' : '' ?>><?= $label ?></a><?= $k !== array_key_last($sorts) ? ' · ' : '' ?><?php endforeach ?></p>
  <div class="table-scroll"><table class="table dash-houses"><thead><tr><th>Dům</th><th>Otevřená</th><th>Po termínu</th><th>Bez řešitele</th><th>Vyřešeno</th><th>První reakce</th><th>Doba vyřešení</th><th>Revize po termínu</th><th>Oznámení</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): $attn = $score($r) > 0 && ($r['late'] || $r['revize_late']); ?><tr<?= $attn ? ' class="is-unmatched"' : '' ?>>
      <td data-label="Dům"><strong><?= e($r['h']['name']) ?></strong></td>
      <td data-label="Otevřená"><?= $r['open'] ?></td>
      <td data-label="Po termínu"><?= $r['late'] ? '<span class="tag tag-late">' . $r['late'] . '</span>' : '0' ?></td>
      <td data-label="Bez řešitele"><?= $r['unassigned'] ?></td>
      <td data-label="Vyřešeno"><?= $r['resolved'] ?> <small class="muted">z <?= $r['created'] ?> nových</small></td>
      <td data-label="První reakce"><?= dash_hours($r['react_hours']) ?></td>
      <td data-label="Doba vyřešení"><?= dash_days($r['avg_days']) ?></td>
      <td data-label="Revize po termínu"><?= $r['revize_late'] ? '<span class="tag tag-late">' . $r['revize_late'] . '</span>' : '0' ?><?= $r['revize_soon'] ? ' <small class="muted">+' . $r['revize_soon'] . ' do 30 dnů</small>' : '' ?></td>
      <td data-label="Oznámení"><?= $r['posts'] + $r['mailings'] ?></td>
      <td class="t-actions"><a class="btn btn-small btn-ghost" href="<?= url('/domy/' . $r['h']['slug'] . '/vstoupit?k=prehled') ?>">Detail domu</a></td></tr>
    <?php endforeach ?>
  </tbody></table></div>
  <?php endif ?>
</div>
