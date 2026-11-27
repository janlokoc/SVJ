<div class="wrap page narrow">
  <header class="page-head"><h1>Prodloužit inzerát</h1></header>
  <div class="card">
    <p><strong><?= e(listing_title($l)) ?></strong> · <span><?= e(listing_price_label($l)) ?></span></p>
    <?php if ($l['status'] === 'expired'): ?><p>Zveřejnění inzerátu skončilo <?= e(cz_date($l['expires_on'])) ?>.</p><?php else: ?><p>Inzerát je zveřejněný do <?= e(cz_date($l['expires_on'])) ?>.</p><?php endif ?>
    <form method="post"><?= csrf_field() ?><button class="btn btn-big" type="submit">Prodloužit o <?= LISTING_DAYS ?> dní</button></form>
    <p class="hint">Je byt už prodaný nebo pronajatý? Přihlaste se a označte to v části Moje inzeráty.</p>
  </div>
</div>
