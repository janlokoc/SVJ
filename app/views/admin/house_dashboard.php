<?php /* Přehled správy domu. $d = house_dashboard(), $partner = správcovská firma nebo null. */
$q = fn(int $days) => url('/admin/prehled-spravy' . ($days !== 90 ? '?obdobi=' . $days : ''));
$per = DASH_PERIODS[$d['days']]; ?>
<div class="wrap page">
  <header class="page-head"><h1>Přehled správy domu</h1>
    <p class="lead">Jak se v domě řeší hlášení, revize a komunikace<?= $partner ? ' – včetně toho, co udělala správcovská firma <strong>' . e($partner['name']) . '</strong>' : '' ?>. Čísla se počítají průběžně; klikněte na kterékoli pro detail.</p></header>
  <nav class="chips" aria-label="Období"><?php foreach (DASH_PERIODS as $k => $label): ?><a href="<?= $q($k) ?>"<?= $d['days'] === $k ? ' aria-current="page"' : '' ?>>Posledních <?= $label ?></a><?php endforeach ?></nav>
  <div class="dash-tiles">
    <a class="dash-tile<?= $d['late'] ? ' is-bad' : '' ?>" href="<?= url('/admin/zavady') ?>"><span class="dash-num"><?= $d['open'] ?></span><span class="dash-label">otevřených hlášení</span><span class="dash-sub"><?= $d['late'] ? $d['late'] . ' po termínu' : 'žádné po termínu' ?><?= $d['unassigned'] ? ' · ' . $d['unassigned'] . ' bez řešitele' : '' ?></span></a>
    <a class="dash-tile" href="<?= url('/admin/zavady?f=vse') ?>"><span class="dash-num"><?= $d['resolved'] ?></span><span class="dash-label">vyřešeno za <?= $per ?></span><span class="dash-sub">nahlášeno <?= $d['created'] ?></span></a>
    <div class="dash-tile"><span class="dash-num"><?= dash_hours($d['react_hours']) ?></span><span class="dash-label">průměrná první reakce</span><span class="dash-sub">odpověď, změna stavu nebo přiřazení</span></div>
    <div class="dash-tile"><span class="dash-num"><?= dash_days($d['avg_days']) ?></span><span class="dash-label">průměrná doba vyřešení</span><span class="dash-sub">u hlášení vyřešených za <?= $per ?></span></div>
    <a class="dash-tile<?= $d['revize_late'] ? ' is-bad' : '' ?>" href="<?= url('/admin/revize') ?>"><span class="dash-num"><?= $d['revize_late'] ?></span><span class="dash-label">revizí po termínu</span><span class="dash-sub"><?= $d['revize_soon'] ? $d['revize_soon'] . ' do 30 dnů' : 'v příštích 30 dnech nic' ?></span></a>
    <a class="dash-tile" href="<?= url('/admin/aktuality') ?>"><span class="dash-num"><?= $d['posts'] + $d['mailings'] ?></span><span class="dash-label">oznámení obyvatelům</span><span class="dash-sub"><?= $d['posts'] ?> aktualit · <?= $d['mailings'] ?> e-mailů</span></a>
  </div>
  <div class="dash-grid">
    <section class="card dash-chart-card" aria-labelledby="dash-chart-h">
      <h2 id="dash-chart-h">Nová hlášení <?= $d['days'] >= 365 ? 'po měsících' : 'po týdnech' ?></h2>
      <?php $max = max(1, max(array_column($d['series'], 'n'))); $n = count($d['series']); $w = 100 / $n; ?>
      <div class="dash-chart" role="img" aria-label="Počet nových hlášení <?= $d['days'] >= 365 ? 'po měsících' : 'po týdnech' ?> za <?= $per ?>; podrobně v tabulce pod grafem.">
        <div class="dash-gridlines" aria-hidden="true"><span data-v="<?= $max ?>"></span><span data-v="<?= round($max / 2) == $max / 2 ? $max / 2 : '' ?>"></span><span data-v="0"></span></div>
        <div class="dash-bars">
          <?php foreach ($d['series'] as $b): ?><div class="dash-col" tabindex="0" data-tip="<?= e(($d['days'] >= 365 ? $b['label'] : 'týden od ' . $b['label']) . ': ' . $b['n'] . ' ' . ($b['n'] == 1 ? 'hlášení' : 'hlášení')) ?>">
            <span class="dash-bar" data-h="<?= round($b['n'] / $max * 100, 1) ?>"></span><span class="dash-x"><?= e($b['label']) ?></span></div><?php endforeach ?>
        </div>
      </div>
      <details class="dash-table"><summary>Zobrazit jako tabulku</summary>
        <table class="table"><thead><tr><th><?= $d['days'] >= 365 ? 'Měsíc' : 'Týden od' ?></th><th>Nová hlášení</th></tr></thead><tbody>
          <?php foreach ($d['series'] as $b): ?><tr><td><?= e($b['label']) ?></td><td><?= $b['n'] ?></td></tr><?php endforeach ?></tbody></table></details>
    </section>
    <section class="card" aria-labelledby="dash-next-h">
      <h2 id="dash-next-h">Co se blíží</h2>
      <ul class="dash-next">
        <?php foreach ($d['upcoming'] as $u): ?><li<?= $u['late'] ? ' class="is-bad"' : '' ?>><span class="muted"><?= cz_date($u['date']) ?></span> <?= e($u['title']) ?><?= $u['late'] ? ' <span class="tag tag-late">po termínu</span>' : '' ?></li><?php endforeach ?>
        <?php if (!$d['upcoming']): ?><li class="muted">Žádné termíny, revize ani termíny hlášení.</li><?php endif ?>
      </ul>
    </section>
  </div>
  <section class="card" aria-labelledby="dash-tl-h">
    <div class="onb-card-head"><h2 id="dash-tl-h">Co se v domě dělo</h2><?php if ($partner): ?><label class="check dash-filter"><input type="checkbox" data-only-staff> Jen správcovská firma (<?= $d['staff_actions'] ?>)</label><?php endif ?></div>
    <?php if (!$d['timeline']): ?><p class="empty">Za <?= $per ?> správci nic nezaznamenali.</p><?php else: ?>
    <ol class="dash-timeline">
      <?php foreach ($d['timeline'] as $e): ?><li<?= $e['staff'] ? ' class="is-staff"' : '' ?>>
        <span class="dash-when"><?= cz_datetime($e['at']) ?></span>
        <span><strong><?= e($e['who'] !== '' ? $e['who'] : 'Web') ?></strong><?= $e['staff'] ? ' <span class="tag tag-pin">správce domu</span>' : '' ?> – <?= e(AUDIT_LABELS[$e['what']] ?? $e['what']) ?><?= $e['detail'] !== '' ? '<br><small class="muted">' . e(mb_strimwidth($e['detail'], 0, 140, '…')) . '</small>' : '' ?></span></li>
      <?php endforeach ?>
    </ol>
    <?php endif ?>
  </section>
</div>
