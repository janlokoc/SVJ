<?php /* Zpracovatelská smlouva k tisku, předvyplněná údaji společenství a provozovatele: $d (moda_dpa()) */
$svj = $d['svj']; $op = $d['op']; $blank = '…………………………………'; ?>
<div class="wrap page print-doc dpa-doc">
  <p class="no-print"><a class="btn btn-small btn-ghost" href="<?= url('/admin/data-domu') ?>">← Zpět</a> <button class="btn btn-small" type="button" data-print>Vytisknout / uložit jako PDF</button></p>
  <h1>Smlouva o zpracování osobních údajů</h1>
  <p>podle čl. 28 nařízení Evropského parlamentu a Rady (EU) 2016/679 (GDPR)<?= $d['service'] !== '' ? ' ke službě ' . e($d['service']) : '' ?></p>
  <div class="dpa-parties">
    <div>
      <p class="h-small">Správce</p>
      <p><strong><?= e($svj['name']) ?></strong><br>
        IČO: <?= $svj['ico'] !== '' ? e($svj['ico']) : $blank ?><br>
        Sídlo: <?= $svj['address'] !== '' ? e($svj['address']) : $blank ?><br>
        Web: <?= e($svj['web']) ?></p>
    </div>
    <div>
      <p class="h-small">Zpracovatel</p>
      <p><strong><?= $op['name'] !== '' ? e($op['name']) : $blank ?></strong><br>
        IČO: <?= $op['ico'] !== '' ? e($op['ico']) : $blank ?><?= $op['dic'] !== '' ? ', DIČ: ' . e($op['dic']) : '' ?><br>
        Sídlo: <?= $op['address'] !== '' ? e($op['address']) : $blank ?><?= $op['register'] !== '' ? '<br>' . e($op['register']) : '' ?><?= $op['email'] !== '' ? '<br>E-mail: ' . e($op['email']) : '' ?></p>
    </div>
  </div>
  <div class="prose legal"><?= format_text($d['text']) ?></div>
  <p>Smlouva je uzavřena elektronicky přijetím obchodních podmínek<?= $d['since'] !== '' ? ' při založení webu dne ' . e(cz_date($d['since'])) : '' ?> a platí po dobu poskytování služby. Tento výtisk slouží k založení do dokumentace společenství; podpisy nejsou podmínkou platnosti.</p>
  <div class="dpa-sign">
    <p>Za správce: ……………………………………<br><small>jméno, funkce, datum, podpis</small></p>
    <p>Za zpracovatele: ……………………………………<br><small>jméno, funkce, datum, podpis</small></p>
  </div>
  <p class="hint">Vytištěno z webu <?= e($svj['web']) ?> dne <?= e(cz_date(date('Y-m-d'))) ?>.</p>
</div>
