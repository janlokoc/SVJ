<?php /* Vlákno jednoho zájemce (jen vlastník jednotky): $l, $inq, $msgs, $error, $draft. */
$phone = listing_inquiry_phone($inq);
$tel = preg_replace('/[^0-9+]/', '', $phone);
?>
<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/inzeraty/' . $l['id'] . '/zajemci') ?>">← Zájemci o inzerát</a></p>
  <header class="page-head"><h1 translate="no"><?= e($inq['name']) ?></h1>
    <p class="lead"><span>Zájemce o inzerát</span> <a href="<?= url(listing_path($l)) ?>"><?= e(listing_title($l)) ?></a></p></header>
  <p class="lst-inq-contact"><?php if ($phone === ''): ?><?= icon('mail') ?> <span translate="no"><?= e($inq['email']) ?></span><?php else: ?><?= icon('phone') ?> <a href="tel:<?= e($tel) ?>" translate="no"><?= e($phone) ?></a><?php endif ?></p>

  <ol class="report-thread">
    <?php foreach ($msgs as $m): $own = $m['author'] === 'owner'; ?>
    <li class="report-msg <?= $own ? 'report-msg-staff' : 'report-msg-user' ?>">
      <p class="report-msg-head"><?php if ($own): ?><strong>Vaše odpověď</strong><?php else: ?><strong>Zájemce</strong><?php endif ?>
        <small class="muted"><?= e(cz_datetime($m['created_at'])) ?></small><?php if ($m['via'] === 'email'): ?> <small class="muted">e-mailem</small><?php endif ?></p>
      <div class="prose" translate="no"><?= format_text((string) $m['body']) ?></div>
    </li>
    <?php endforeach ?>
  </ol>

  <?php if ($phone !== ''): ?>
    <div class="callout lst-inq-phone"><p>Zájemce nechal jen telefon, odpovědět e-mailem nejde. Zavolejte mu nebo pošlete SMS na číslo <strong translate="no"><?= e($phone) ?></strong>.</p>
      <p class="actions"><a class="btn btn-small" href="tel:<?= e($tel) ?>"><?= icon('phone') ?> <span>Zavolat</span></a> <a class="btn btn-small btn-ghost" href="sms:<?= e($tel) ?>">Poslat SMS</a></p></div>
  <?php else: ?>
    <form method="post" action="<?= url(listing_inquiry_path($l, $inq)) ?>" class="card form lst-inq-reply" id="odpoved">
      <?= csrf_field() ?>
      <?php $errors = $error ? [$error] : []; require __DIR__ . '/../../_errors.php'; ?>
      <label>Odpověď zájemci <textarea name="body" rows="6" maxlength="<?= LISTING_INQUIRY_MAX ?>" required placeholder="Dobrý den, děkuji za zájem…"><?= e($draft) ?></textarea></label>
      <?php $aiShow = ai_resident_available(); $aiUrl = url('/ai-text'); $aiTarget = 'textarea[name=body]'; $aiTasks = ['polish', 'shorten']; $aiContext = 'Odpověď vlastníka bytu zájemci o inzerát: ' . listing_title($l); $aiFields = []; require __DIR__ . '/../../_ai_mini.php'; ?>
      <div class="actions"><button class="btn" type="submit">Odeslat odpověď</button></div>
      <p class="hint">Odpověď pošleme zájemci e-mailem z adresy webu domu – Vaši e-mailovou adresu neuvidí. Když odpoví, jeho zprávu uvidíte tady a dostanete upozornění.</p>
    </form>
  <?php endif ?>

  <form method="post" action="<?= url(listing_inquiry_path($l, $inq) . ($inq['status'] === 'archived' ? '/obnovit' : '/archivovat')) ?>" class="lst-inq-archive">
    <?= csrf_field() ?>
    <?php if ($inq['status'] === 'archived'): ?><button class="btn btn-small btn-ghost" type="submit">Vrátit z archivu</button><?php else: ?><button class="btn btn-small btn-ghost" type="submit">Archivovat</button><?php endif ?>
  </form>
</div>
