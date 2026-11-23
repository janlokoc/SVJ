<?php /** Detail připravované funkce. Vstup: $key, $back (adresa zpět), $notifyUrl, $notified (bool), $askEmail (bool) */
[$name, $short, $price] = PLANNED_FEATURES[$key]; $d = PLANNED_DETAILS[$key]; ?>
<p><a href="<?= e($back) ?>">← Zpět na přehled</a></p>
<header class="page-head"><span class="tag">připravujeme</span><h1><?= e($name) ?></h1><p class="lead"><?= e($d['lead']) ?></p></header>
<div class="feature-detail">
  <section><h2>Jaký problém to řeší</h2><p><?= e($d['problem']) ?></p></section>
  <section><h2>Co přesně to bude dělat</h2><ol><?php foreach ($d['how'] as $step): ?><li><?= e($step) ?></li><?php endforeach ?></ol></section>
  <?php if (!empty($d['areas'])): ?>
  <section class="ai-areas"><h2>Kde všude může AI na webu pomoci</h2>
    <p>Umělá inteligence se hodí všude, kde se na webu píše, třídí, hledá nebo shrnuje. Rozhodnutí vždy zůstává na člověku.</p>
    <?php foreach ($d['areas'] as $group => $rows): ?>
    <h3><?= e($group) ?></h3>
    <dl class="area-list">
      <?php foreach ($rows as [$where, $what, $why, $state]): ?>
      <div><dt><?= e($where) ?><?= $state === 'hotovo' ? ' <span class="tag tag-ok">už funguje</span>' : '' ?></dt>
        <dd><?= e($what) ?> <span class="area-why"><?= e($why) ?></span></dd></div>
      <?php endforeach ?>
    </dl>
    <?php endforeach ?>
  </section>
  <?php endif ?>
  <section><h2>Edice a cena</h2><p><strong><?= e($price) ?></strong></p><p><?= e($d['note']) ?></p><p class="hint">Edice i cena jsou předběžné a mohou se do spuštění změnit.</p></section>
</div>
<div class="card card-soft feature-notify">
  <?php if (!empty($GLOBALS['DEMO'])): ?><p><strong>Tuto funkci připravujeme.</strong> Ve vlastním webu si tady necháte poslat zprávu, až bude hotová.</p>
  <?php elseif ($notified): ?><p><strong>Dáme Vám vědět, až bude funkce hotová.</strong></p>
  <?php else: ?>
  <form method="post" action="<?= e($notifyUrl) ?>" class="feature-notify-form"><?= csrf_field() ?><input type="hidden" name="feature" value="<?= e($key) ?>">
    <?php if ($askEmail): ?><span class="hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></span>
    <label>Chcete zprávu, až bude funkce hotová? <input type="email" name="email" required placeholder="vas@e-mail.cz"></label>
    <?php else: ?><span>Chcete zprávu, až bude funkce hotová?</span><?php endif ?>
    <button class="btn btn-small" type="submit">Dejte mi vědět</button></form>
    <?php if ($askEmail): ?><p class="hint">E-mail použijeme jen k této jedné zprávě. Podrobnosti: <a href="<?= e(platform_url('/ochrana-udaju')) ?>">ochrana osobních údajů</a>.</p><?php endif ?>
  <?php endif ?>
  <p class="hint">Funkce zatím není hotová a termín neslibujeme. Pořadí určujeme podle toho, o které funkce je největší zájem.</p>
</div>
