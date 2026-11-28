<?php /* Nabídka „zakládající domy“ (konzole → Obchod). Zobrazí se jen zapnutá a s volnými místy. */
if (function_exists('founding_open') && founding_open()):
    $fLeft = founding_left(); $fStd = edition_price('standard'); $fMail = pset('contact_email'); ?>
<div class="ref-promo founding-promo" id="zakladajici">
  <div><h3>Zakládající domy: zbývá <?= $fLeft ?> <?= $fLeft === 1 ? 'místo' : ($fLeft >= 2 && $fLeft <= 4 ? 'místa' : 'míst') ?> z <?= founding_slots() ?></h3>
    <p>Prvním domům převedeme starý web zdarma, osobně pomůžeme se zprovozněním a cenu edice Standard <strong><?= money_czk($fStd) ?> ročně</strong> <small class="vat-tag">bez DPH</small> jim necháme natrvalo, i když ceník později zdražíme. Na oplátku Vás poprosíme o krátké doporučení – zveřejníme ho jen s Vaším souhlasem.</p></div>
  <?php if ($fMail !== ''): ?><a class="btn" href="mailto:<?= e($fMail) ?>?subject=<?= rawurlencode('Zakládající dům') ?>">Chci být zakládající dům</a><?php endif ?>
</div>
<?php endif ?>
