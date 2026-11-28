<?php /* Napojení na programy správy domů a účetnictví + sběr požadavků (veřejné stránky služby). */ ?>
<section class="wrap lp-section narrow" id="napojeni">
  <h2>Napojení na programy správy domů a účetnictví</h2>
  <p>Web domu převezme data z programu, ve kterém správce nebo účetní vede předpisy a platby, a ukáže je vlastníkům u jejich bytu. Opačným směrem exportuje předpisy a platby do účetnictví.</p>
  <ul class="lp-check integ-list">
    <?php foreach (INTEGRATIONS as [$name, $state, $text]): ?>
    <li><strong><?= e($name) ?></strong> <span class="tag<?= $state === 'done' ? ' tag-ok' : '' ?>"><?= $state === 'done' ? 'napojeno' : 'pracujeme na tom' ?></span><br><span class="muted"><?= e($text) ?></span></li>
    <?php endforeach ?>
  </ul>
  <form method="post" action="<?= url('/napojeni') ?>" class="card form integ-form">
    <?= csrf_field() ?>
    <h3>Chybí Vám napojení na Váš program?</h3>
    <p class="hint">Napište nám, který program používáte a co by web domu měl umět převzít. Podle počtu zájemců určujeme, na čem pracovat dřív.</p>
    <div class="form-row">
      <label>Program <select name="program" required><option value="">– vyberte –</option><?php foreach (INTEGRATION_ASK as $k => $label): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach ?></select></label>
      <label>Jiný program (název) <input type="text" name="other" maxlength="100" placeholder="jen u „Jiný program“"></label>
    </div>
    <label>Co potřebujete převzít nebo předat <small class="muted">(nepovinné)</small> <textarea name="what" rows="3" maxlength="1000" placeholder="např. předpisy a saldo vlastníků, vyúčtování, odečty, faktury domu…"></textarea></label>
    <label>E-mail pro odpověď <small class="muted">(nepovinné)</small> <input type="email" name="email" maxlength="190"></label>
    <input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="hp" aria-hidden="true">
    <div class="actions"><button class="btn" type="submit">Poslat požadavek</button></div>
  </form>
</section>
