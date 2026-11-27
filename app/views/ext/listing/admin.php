<div class="wrap page">
  <header class="page-head row"><h1>Inzeráty vlastníků</h1><span class="actions"><a class="btn btn-ghost" href="<?= url('/admin/nastaveni') ?>#penb">Nastavení a průkaz budovy</a></span></header>
  <p class="lead">Vlastníci mohou na webu domu zveřejnit inzerát na prodej nebo pronájem svého bytu. Výbor dostane upozornění na každý nový inzerát a nevhodný může skrýt – vlastník dostane e-mail s důvodem.</p>
  <?php if (!listing_enabled()): ?><p class="callout">Inzeráty vlastníků jsou v nastavení vypnuté – vlastníci nové nepodají a zveřejněné nikdo nevidí.</p><?php endif ?>
  <?php if (!$penb['valid']): ?><p class="callout">Průkaz energetické náročnosti budovy <?= $penb['class'] === '' ? 'není zadaný' : 'už neplatí' ?>. Vlastníci pak energetickou třídu uvádějí sami. <a href="<?= url('/admin/nastaveni') ?>#penb">Zadat průkaz</a></p><?php endif ?>
  <?php if (!$rows): ?><p class="empty">Zatím žádný vlastník inzerát nepodal.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Inzerát</th><th>Jednotka</th><th>Stav</th><th>Hlášení</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $l): $vis = listing_visibility($l); ?>
      <tr>
        <td data-label="Inzerát"><a href="<?= url(listing_path($l)) ?>"><strong><?= e(listing_title($l)) ?></strong></a><br><small><?= e(listing_price_label($l)) ?> · <?= e((string) $l['owner_name']) ?></small></td>
        <td data-label="Jednotka"><?= $l['number'] !== null ? e(unit_name($l)) : '—' ?></td>
        <td data-label="Stav"><?php if ((int) $l['hidden']): ?><span class="status status-new">skrytý</span> <small><?= e($l['hidden_reason']) ?></small>
          <?php else: ?><span class="status<?= $vis !== 'none' ? ' status-done' : '' ?>"><?= e(LISTING_STATUS[$l['status']] ?? $l['status']) ?></span>
          <?php if ($l['status'] === 'published'): ?><small><?= $vis === 'residents' ? 'zatím jen pro sousedy · ' : '' ?>do <?= e(cz_date($l['expires_on'])) ?></small><?php endif ?><?php endif ?></td>
        <td data-label="Hlášení"><?= (int) $l['reports'] ?: '' ?></td>
        <td class="t-actions">
          <?php if ((int) $l['hidden']): ?>
            <form method="post" action="<?= url('/admin/inzeraty/' . $l['id'] . '/odkryt') ?>"><?= csrf_field() ?><button class="btn btn-small btn-ghost" type="submit">Znovu zobrazit</button></form>
          <?php elseif ($l['status'] !== 'draft'): ?>
            <form method="post" action="<?= url('/admin/inzeraty/' . $l['id'] . '/skryt') ?>" class="lst-hide"><?= csrf_field() ?>
              <input type="text" name="reason" maxlength="500" placeholder="Důvod pro vlastníka" required aria-label="Důvod skrytí"><button class="btn btn-small btn-ghost" type="submit">Skrýt</button></form>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
  <?php if ($reports): ?>
    <h2>Nahlášené inzeráty</h2>
    <ul class="lst-reports"><?php foreach ($reports as $r): ?><li><small><?= e(cz_datetime($r['created_at'])) ?> · inzerát č. <?= (int) $r['listing_id'] ?></small><br><?= nl2br(e($r['reason'])) ?></li><?php endforeach ?></ul>
  <?php endif ?>
</div>
