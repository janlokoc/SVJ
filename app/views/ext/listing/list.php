<div class="wrap page">
  <header class="page-head row"><h1>Inzeráty</h1><?php if ($canCreate): ?><span class="actions"><a class="btn" href="<?= url('/inzeraty/moje') ?>">Moje inzeráty</a></span><?php endif ?></header>
  <p class="lead">Byty v domě, které jejich vlastníci prodávají nebo pronajímají. Třeba se ozve někdo ze známých.</p>
  <?php if (!$rows): ?>
    <p class="empty">Teď v domě žádný byt na prodej ani k pronájmu není.</p>
  <?php else: ?>
  <ul class="lst-cards">
    <?php foreach ($rows as $l): $cover = $covers[$l['id']] ?? null; $rent = $l['type'] === 'pronajem'; ?>
      <li class="card lst-card">
        <a href="<?= url(listing_path($l)) ?>" class="lst-card-link">
          <?php if ($cover): ?><img src="<?= url(listing_photo_url($cover, true)) ?>" alt="" width="480" height="360" loading="lazy"><?php endif ?>
          <span class="lst-card-body">
            <strong><?= e(listing_title($l)) ?></strong>
            <span class="lst-card-price"><?= e(listing_money((int) $l['price'])) ?><?php if ($rent): ?> <span>/ měsíc</span><?php endif ?></span>
            <span class="muted"><?= e(implode(' · ', array_filter([unit_area_label($l['area']), $l['floor'] !== '' ? $l['floor'] . '. p.' : '']))) ?></span>
            <?php if ($l['status'] === 'sold'): ?><span class="status status-done"><?php if ($rent): ?><span>Pronajato</span><?php else: ?><span>Prodáno</span><?php endif ?></span>
            <?php elseif (listing_visibility($l) === 'residents'): ?><span class="tag tag-lock">zatím jen pro sousedy</span><?php endif ?>
          </span>
        </a>
      </li>
    <?php endforeach ?>
  </ul>
  <?php endif ?>
</div>
