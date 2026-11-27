<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/muj-ucet') ?>">← Můj účet</a></p>
  <header class="page-head"><h1>Moje inzeráty</h1>
    <p class="lead">Prodáváte nebo pronajímáte byt? Inzerát vyplníte za pár minut a pak ho sdílíte na Facebooku, WhatsAppu nebo Bazoši.</p></header>
  <?php if (!$rows): ?>
    <p class="empty">Inzerát může podat jen vlastník jednotky. Váš účet zatím k žádné jednotce jako vlastník přiřazený není – obraťte se prosím na výbor.</p>
  <?php endif ?>
  <?php foreach ($rows as $row): $un = $row['unit']; $active = null; ?>
    <section class="card lst-mine">
      <h2>Jednotka <?= e(unit_name($un)) ?></h2>
      <?php foreach ($row['listings'] as $l): $vis = listing_visibility($l); $active = $active ?? (in_array($l['status'], ['draft', 'published'], true) ? $l : null); ?>
        <div class="lst-mine-row">
          <div>
            <a href="<?= url(listing_path($l)) ?>"><strong><?= e(listing_title($l)) ?></strong></a> · <span><?= e(listing_price_label($l)) ?></span>
            <div class="lst-mine-state">
              <?php if ((int) $l['hidden']): ?><span class="status status-new">Skryto výborem</span> <small><?= e($l['hidden_reason']) ?></small>
              <?php elseif ($l['status'] === 'draft'): ?><span class="status status-in_progress">Koncept</span>
              <?php elseif ($l['status'] === 'published' && $vis === 'residents'): ?><span class="status status-done">Zveřejněno pro sousedy</span> <small>veřejně od <?= e(cz_datetime($l['public_from'])) ?></small>
              <?php elseif ($l['status'] === 'published'): ?><span class="status status-done">Zveřejněno</span> <small>do <?= e(cz_date($l['expires_on'])) ?></small>
              <?php elseif ($l['status'] === 'sold'): ?><span class="status">Prodáno / pronajato</span>
              <?php else: ?><span class="status">Vypršelo</span> <small><?= e(cz_date($l['expires_on'])) ?></small><?php endif ?>
            </div>
          </div>
          <div class="actions lst-mine-actions">
            <?php $ic = listing_inquiry_counts((int) $l['id']); if ($l['status'] !== 'draft' || $ic['total']): ?><a class="btn btn-small<?= $ic['new'] ? '' : ' btn-ghost' ?>" href="<?= url('/inzeraty/' . $l['id'] . '/zajemci') ?>"><?= e(listing_inquiry_label($ic['new'], $ic['total'])) ?></a><?php endif ?>
            <?php if (in_array($l['status'], ['draft', 'published', 'expired'], true)): ?><a class="btn btn-small btn-ghost" href="<?= url('/inzeraty/' . $l['id'] . '/upravit') ?>">Upravit</a><?php endif ?>
            <?php if ($l['status'] === 'draft'): ?><form method="post" action="<?= url('/inzeraty/' . $l['id'] . '/zverejnit') ?>"><?= csrf_field() ?><button class="btn btn-small" type="submit">Zveřejnit</button></form><?php endif ?>
            <?php if ($l['status'] === 'published' || ($l['status'] === 'expired' && !listing_active_for_unit((int) $un['id'], (int) $l['id']))): ?><form method="post" action="<?= url('/inzeraty/' . $l['id'] . '/prodlouzit') ?>"><?= csrf_field() ?><button class="btn btn-small btn-ghost" type="submit">Prodloužit o <?= LISTING_DAYS ?> dní</button></form><?php endif ?>
            <?php if ($l['status'] === 'published'): ?><form method="post" action="<?= url('/inzeraty/' . $l['id'] . '/prodano') ?>" data-confirm="Označit byt jako prodaný / pronajatý? Inzerát pak už nepůjde prodloužit."><?= csrf_field() ?><button class="btn btn-small btn-ghost" type="submit">Prodáno / pronajato</button></form><?php endif ?>
            <form method="post" action="<?= url('/inzeraty/' . $l['id'] . '/smazat') ?>" data-confirm="Smazat inzerát i s fotkami? Nejde to vrátit."><?= csrf_field() ?><button class="link-danger" type="submit">Smazat</button></form>
          </div>
        </div>
      <?php endforeach ?>
      <?php if (!$active): ?><p><a class="btn" href="<?= url('/inzeraty/novy/' . $un['id']) ?>">+ Nový inzerát</a></p><?php endif ?>
    </section>
  <?php endforeach ?>
</div>
