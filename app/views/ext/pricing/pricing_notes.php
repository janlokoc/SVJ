<?php /* Modul G: doplňky ceníku pod edicemi (app/views/platform/pricing.php). */
$pnStd = price_size_note('standard');
$pnPrem = price_size_note('premium');
$pnFree = free_max_units();
$pnVote = vote_price(); ?>
  <ul class="lp-check pricing-notes">
    <?php if ($pnStd !== '' || $pnPrem !== ''): ?><li><strong>Cena podle velikosti domu:</strong> <?= $pnStd !== '' ? 'Standard ' . e($pnStd) : '' ?><?= $pnStd !== '' && $pnPrem !== '' ? '; ' : '' ?><?= $pnPrem !== '' ? 'Premium ' . e($pnPrem) : '' ?> (bez DPH). Například dům s 80 jednotkami zaplatí za Standard <?= money_czk(edition_price('standard', 80)) ?> a za Premium <?= money_czk(edition_price('premium', 80)) ?> ročně. Počítají se jednotky zapsané na webu v den vystavení faktury.</li><?php endif ?>
    <?php if ($pnFree > 0): ?><li><strong>Edice Zdarma je pro domy do <?= $pnFree ?> jednotek.</strong> Větší dům dostane <?= PLAN_TRIAL_DAYS ?> dní edice Standard na zkoušku; potom si edici zaplatí, nebo web běží dál v omezeném režimu s limity edice Zdarma. Nic se nemaže.</li><?php endif ?>
    <?php if ($pnVote > 0): ?><li><strong>Jednorázové hlasování</strong> bez předplatného: <?= money_czk($pnVote) ?> bez DPH <?= price_vat_html($pnVote) ?> za jedno hlasování nad limit edice Zdarma, vypsat ho můžete do <?= VOTE_PASS_DAYS ?> dní od zaplacení. Objednává se v administraci u nového hlasování.</li><?php endif ?>
    <?php if (compare_enabled()): ?><li><a href="<?= url('/srovnani') ?>">Srovnání cen a funkcí s jinými službami pro SVJ</a></li><?php endif ?>
  </ul>
