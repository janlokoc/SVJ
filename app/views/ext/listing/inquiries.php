<?php /* Zájemci o inzerát (jen vlastník jednotky): $l, $rows (listing_inquiries_for), $archive = ukázat archiv. */
$shown = array_values(array_filter($rows, fn($r) => ($r['status'] === 'archived') === $archive));
$archived = count(array_filter($rows, fn($r) => $r['status'] === 'archived'));
?>
<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/inzeraty/moje') ?>">← Moje inzeráty</a></p>
  <header class="page-head"><h1>Zájemci o inzerát</h1>
    <p class="lead"><a href="<?= url(listing_path($l)) ?>"><?= e(listing_title($l)) ?></a> · <?= e(listing_price_label($l)) ?></p></header>
  <p class="hint">Zprávy z formuláře u inzerátu. Odpovíte na webu a zájemce Vaši e-mailovou adresu neuvidí. Zprávy vidíte jen Vy (a další vlastníci jednotky), výbor ne. Po skončení inzerátu je uchováváme ještě <?= LISTING_INQUIRY_KEEP_DAYS ?> dní, pak se smažou.</p>
  <nav class="lst-inq-tabs" aria-label="Zájemci">
    <?php if ($archive): ?><a href="<?= url('/inzeraty/' . $l['id'] . '/zajemci') ?>">← Aktuální zprávy</a>
    <?php elseif ($archived): ?><a href="<?= url('/inzeraty/' . $l['id'] . '/zajemci?archiv=1') ?>">Archiv (<?= $archived ?>)</a><?php endif ?>
  </nav>
  <?php if (!$shown): ?>
    <?php if ($archive): ?><p class="empty">V archivu nic není.</p><?php else: ?><p class="empty">Zatím se nikdo neozval. Zprávy zájemců se tu objeví a dostanete na ně upozornění e-mailem.</p><?php endif ?>
  <?php else: ?>
  <ul class="lst-inq-list">
    <?php foreach ($shown as $r): $new = $r['status'] === 'new'; ?>
      <li class="card lst-inq<?= $new ? ' lst-inq-new' : '' ?>">
        <a class="lst-inq-link" href="<?= url(listing_inquiry_path($l, $r)) ?>">
          <span class="lst-inq-head"><strong translate="no"><?= e($r['name']) ?></strong>
            <?php if ($new): ?><span class="status status-new">Nová zpráva</span><?php endif ?>
            <small class="muted"><?= e(cz_datetime($r['updated_at'])) ?></small></span>
          <span class="lst-inq-contact"><?php if ($r['email'] !== ''): ?><?= icon('mail') ?> <span translate="no"><?= e($r['email']) ?></span><?php else: ?><?= icon('phone') ?> <span translate="no"><?= e($r['contact']) ?></span><?php endif ?></span>
          <span class="lst-inq-last" translate="no"><?= e(($r['last_author'] === 'owner' ? '↩ ' : '') . excerpt((string) $r['last_body'], 140)) ?></span>
        </a>
      </li>
    <?php endforeach ?>
  </ul>
  <?php endif ?>
</div>
