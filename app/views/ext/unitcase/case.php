<?php /* Vlákno: $case, $msgs, $files, $u, $unit, $other (účty druhé strany). */
$side = unitcase_side($u); ?>
<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/byt') ?>">← Závady v bytě</a></p>
  <header class="page-head"><h1 translate="no"><?= e($case['subject']) ?></h1>
    <p class="lead"><span><?= e(UNITCASE_KINDS[$case['kind']] ?? '') ?></span> · <span>byt</span> <span translate="no"><?= e($unit) ?></span>
      <?php if ($case['kind'] === 'zavada'): ?> <span class="status status-<?= e($case['status']) ?>"><?= e(UNITCASE_STATUS[$case['status']] ?? '') ?></span><?php endif ?></p></header>

  <ol class="report-thread">
    <?php foreach ($msgs as $m): $own = (int) $m['user_id'] === (int) $u['id']; ?>
    <li class="report-msg <?= $own ? 'report-msg-user' : 'report-msg-staff' ?>">
      <p class="report-msg-head"><strong translate="no"><?= e($own ? 'Vy' : ($m['author'] ?? '')) ?></strong> <small class="muted"><?= $m['side'] === 'tenant' ? 'nájemník' : 'vlastník' ?> · <?= e(cz_datetime($m['created_at'])) ?></small></p>
      <div class="prose" translate="no"><?= format_text((string) $m['body']) ?></div>
      <?php if (!empty($files[(int) $m['id']])): ?><ul class="ticket-files"><?php foreach ($files[(int) $m['id']] as $f): ?><li><a href="<?= url('/byt/priloha/' . $f['id']) ?>" target="_blank" rel="noopener" translate="no"><?= icon('doc') ?> <span><?= e($f['original_name']) ?></span></a></li><?php endforeach ?></ul><?php endif ?>
    </li>
    <?php endforeach ?>
  </ol>
  <span id="konec"></span>

  <?php if ($case['kind'] === 'zavada'): ?>
  <form method="post" action="<?= url('/byt/' . $case['id']) ?>" class="inline-form ucase-status"><?= csrf_field() ?>
    <span>Stav:</span>
    <?php foreach (UNITCASE_STATUS as $k => $label): if ($k === $case['status']) { continue; } ?><button class="btn btn-small btn-ghost" name="status" value="<?= $k ?>" type="submit"><?= e($label) ?></button><?php endforeach ?>
  </form>
  <?php endif ?>

  <?php if ($other): ?>
  <form method="post" action="<?= url('/byt/' . $case['id']) ?>" class="card form" id="odpoved" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <label>Odpověď <textarea name="body" rows="5" maxlength="<?= UNITCASE_MAX ?>" required></textarea></label>
    <label>Fotografie nebo dokument <small class="muted">(nepovinné, nejvýše 4 soubory)</small> <input type="file" name="files[]" multiple accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf" data-shrink></label>
    <div class="actions"><button class="btn" type="submit">Odeslat odpověď</button></div>
    <p class="hint"><?= $side === 'tenant' ? 'Vlastník dostane e-mail a upozornění do telefonu.' : 'Nájemník dostane e-mail a upozornění do telefonu.' ?></p>
  </form>
  <?php else: ?>
    <p class="callout"><?= $side === 'tenant' ? 'Vlastník bytu teď nemá na webu aktivní účet, odpověď by nikdo nedostal.' : 'U bytu teď není přihlášený žádný nájemník, odpověď by nikdo nedostal.' ?></p>
  <?php endif ?>
</div>
