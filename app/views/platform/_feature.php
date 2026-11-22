<?php /** Detail připravované funkce. Vstup: $key, $back (adresa zpět), $notifyUrl, $notified (bool), $askEmail (bool) */
[$name, $short, $price] = PLANNED_FEATURES[$key]; $d = PLANNED_DETAILS[$key]; ?>
<p><a href="<?= e($back) ?>">← Zpět na přehled</a></p>
<header class="page-head"><span class="tag">připravujeme</span><h1><?= e($name) ?></h1><p class="lead"><?= e($d['lead']) ?></p></header>
<div class="feature-detail">
  <section><h2>Jaký problém to řeší</h2><p><?= e($d['problem']) ?></p></section>
  <section><h2>Co přesně to bude dělat</h2><ol><?php foreach ($d['how'] as $step): ?><li><?= e($step) ?></li><?php endforeach ?></ol></section>
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
  <?php endif ?>
  <p class="hint">Funkce zatím není hotová a termín neslibujeme. Pořadí určujeme podle toho, o které funkce je největší zájem.</p>
</div>
