<?php /* Nové vlákno: $units, $side, $error. */
$targets = array_filter($units, fn($x) => $x['other'] > 0); ?>
<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/byt') ?>">← Závady v bytě</a></p>
  <header class="page-head"><h1><?= $side === 'tenant' ? 'Napsat vlastníkovi' : 'Napsat nájemníkovi' ?></h1></header>
  <?php if (!$targets): ?>
    <p class="callout"><?= $side === 'tenant' ? 'Vlastník Vašeho bytu zatím nemá na webu účet, proto mu tady napsat nejde.' : 'U Vašeho bytu teď není přihlášený žádný nájemník.' ?></p>
  <?php else: ?>
  <form method="post" action="<?= url('/byt/novy') ?>" class="card form" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <?php $errors = $error !== '' ? [$error] : []; require __DIR__ . '/../../_errors.php'; ?>
    <?php if (count($targets) > 1): ?>
    <label>Byt <select name="unit_id"><?php foreach ($targets as $id => $x): ?><option value="<?= (int) $id ?>"<?= (int) post('unit_id') === (int) $id ? ' selected' : '' ?> translate="no"><?= e($x['name']) ?></option><?php endforeach ?></select></label>
    <?php else: ?><input type="hidden" name="unit_id" value="<?= (int) array_key_first($targets) ?>"><p>Byt <?= e(reset($targets)['name']) ?></p><?php endif ?>
    <fieldset class="checks"><legend>Co posíláte</legend>
      <?php foreach (UNITCASE_KINDS as $k => $label): ?><label class="check"><input type="radio" name="kind" value="<?= $k ?>"<?= (post('kind') ?: 'zavada') === $k ? ' checked' : '' ?>> <?= e($label) ?></label><?php endforeach ?>
    </fieldset>
    <label>Stručně <input type="text" name="subject" maxlength="150" required value="<?= e(post('subject')) ?>" placeholder="např. Kape kohoutek v kuchyni"></label>
    <label>Popis <textarea name="body" rows="6" maxlength="<?= UNITCASE_MAX ?>" required placeholder="Co se děje, odkdy, kdy jste doma k dispozici…"><?= e(post('body')) ?></textarea></label>
    <label>Fotografie nebo dokument <small class="muted">(nepovinné, nejvýše 4 soubory)</small> <input type="file" name="files[]" multiple accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf" data-shrink></label>
    <div class="actions"><button class="btn" type="submit">Odeslat</button></div>
    <p class="hint"><?= $side === 'tenant' ? 'Vlastník dostane e-mail a upozornění do telefonu. Výbor ani správa domu zprávu neuvidí.' : 'Nájemník dostane e-mail a upozornění do telefonu. Výbor ani správa domu zprávu neuvidí.' ?></p>
  </form>
  <?php endif ?>
</div>
