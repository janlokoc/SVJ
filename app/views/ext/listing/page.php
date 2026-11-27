<?php
/* Veřejná stránka inzerátu. Nikdy neukazuje číslo jednotky, vchod ani e-mail vlastníka (jen ulici a město domu). */
$rent = $l['type'] === 'pronajem';
$sold = $l['status'] === 'sold';
$canContact = $l['status'] === 'published' && $vis !== 'none';
$pageUrl = absolute_url(listing_path($l));
$locality = listing_locality();
$penbHouse = listing_penb();
$service = plan_managed() ? pset('service_name') : '';
?>
<div class="wrap page lst-page">
  <?php if ($vis === 'none'): ?>
    <div class="flash flash-info lst-preview" role="status">
      <strong>Náhled – inzerát teď nikdo jiný nevidí.</strong>
      <?php if ((int) $l['hidden']): ?><span>Výbor ho skryl. Důvod: <?= e($l['hidden_reason']) ?></span>
      <?php elseif ($l['status'] === 'draft'): ?><span>Je to koncept – zveřejníte ho v části Moje inzeráty.</span>
      <?php elseif ($l['status'] === 'expired'): ?><span>Zveřejnění skončilo – můžete ho prodloužit v části Moje inzeráty.</span>
      <?php endif ?>
    </div>
  <?php elseif ($vis === 'residents'): ?>
    <div class="flash flash-info" role="status">Zatím ho vidí jen přihlášení obyvatelé domu, veřejně bude od <?= e(cz_datetime($l['public_from'])) ?>.</div>
  <?php endif ?>
  <?php if (user()): ?><p class="crumbs"><a href="<?= url('/inzeraty') ?>">← Inzeráty v domě</a><?php if ($canManage): ?> · <a href="<?= url('/inzeraty/' . $l['id'] . '/upravit') ?>">Upravit inzerát</a> · <a href="<?= url('/inzeraty/moje') ?>">Moje inzeráty</a><?php endif ?></p><?php endif ?>

  <header class="lst-head">
    <div>
      <?php if ($sold): ?><span class="status status-done lst-sold"><?php if ($rent): ?><span>Pronajato</span><?php else: ?><span>Prodáno</span><?php endif ?></span><?php endif ?>
      <h1><?= e(listing_title($l)) ?></h1>
      <p class="lst-place"><?= icon('pin') ?> <span><?= e($locality) ?></span>
        <?php if ($locality !== ''): ?> · <a href="https://mapy.cz/zakladni?q=<?= e(rawurlencode($locality)) ?>" rel="noopener noreferrer" target="_blank">Zobrazit na mapě</a><?php endif ?></p>
    </div>
    <p class="lst-price"><strong><?= e(listing_money((int) $l['price'])) ?></strong><?php if ($rent): ?> <span>/ měsíc</span><?php endif ?></p>
  </header>

  <?php if ($photos): ?>
  <div class="lst-gallery" role="region" aria-label="Fotky" tabindex="0">
    <?php foreach ($photos as $i => $p): ?>
      <a class="lst-photo" href="<?= url(listing_photo_url($p)) ?>"><img src="<?= url(listing_photo_url($p)) ?>" srcset="<?= url(listing_photo_url($p, true)) ?> 480w, <?= url(listing_photo_url($p)) ?> <?= (int) $p['width'] ?>w"
        sizes="(max-width: 760px) 92vw, 60vw" width="<?= (int) $p['width'] ?>" height="<?= (int) $p['height'] ?>" alt="<?= e(listing_photo_alt($p, $i, count($photos))) ?>"<?= $i ? ' loading="lazy"' : '' ?>><?php if (trim((string) ($p['caption'] ?? '')) !== ''): ?><span class="lst-caption"><?= e($p['caption']) ?></span><?php endif ?></a>
    <?php endforeach ?>
  </div>
  <p class="hint lst-swipe">Fotek: <?= count($photos) ?>. Další zobrazíte posunutím do strany.</p>
  <?php endif ?>

  <div class="lst-grid">
    <div class="lst-main">
      <dl class="lst-facts">
        <div><dt>Dispozice</dt><dd><?= e(listing_layout_label((string) $l['layout']) ?: '—') ?></dd></div>
        <?php if (unit_area_label($l['area']) !== ''): ?><div><dt>Plocha</dt><dd><?= e(unit_area_label($l['area'])) ?></dd></div><?php endif ?>
        <?php if (listing_outdoor_type($l) !== 'none'): ?><div class="lst-outdoor"><dt><?= e(LISTING_OUTDOOR[listing_outdoor_type($l)]) ?></dt><dd><?= e(unit_area_label($l['outdoor_area'] ?? null) ?: '—') ?></dd></div><?php endif ?>
        <?php if ($l['floor'] !== ''): ?><div><dt>Patro</dt><dd><?= e($l['floor']) ?></dd></div><?php endif ?>
        <div><dt>Cena</dt><dd><?= e(listing_money((int) $l['price'])) ?><?php if ($rent): ?> <span>/ měsíc</span><?php endif ?></dd></div>
        <?php if ($rent && (int) $l['fees'] > 0): ?><div><dt>Poplatky</dt><dd><?= e(listing_money((int) $l['fees'])) ?> <span>/ měsíc</span></dd></div><?php endif ?>
        <div class="lst-energy"><dt>Energetická třída</dt><dd>
          <?php if ($l['energy_class'] !== ''): ?><span class="penb penb-<?= e(strtolower($l['energy_class'])) ?>"><?= e($l['energy_class']) ?></span>
            <?php if ($l['energy_source'] === 'budova' && $penbHouse['until'] !== ''): ?><small>průkaz budovy, platí do <?= e(cz_date($penbHouse['until'])) ?></small><?php elseif ($l['energy_source'] === 'budova'): ?><small>průkaz budovy</small><?php else: ?><small>údaj vlastníka</small><?php endif ?>
          <?php else: ?><span>neuvedena</span><?php endif ?>
        </dd></div>
      </dl>
      <?php if ($featureItems = listing_feature_items($l)): ?>
      <section class="lst-features" aria-labelledby="lst-features-h">
        <h2 id="lst-features-h">Vybavení a příslušenství</h2>
        <ul class="lst-checklist">
          <?php foreach ($featureItems as $it): ?>
            <li<?= $it['no'] ? ' class="lst-no"' : '' ?>><span class="lst-mark" aria-hidden="true"><?= $it['no'] ? '–' : '✓' ?></span>
              <span class="lst-ftext"><span class="lst-fl"><?= e($it['label']) ?></span><?php if ($it['value'] !== ''): ?><span class="lst-fsep"><?= $it['sep'] === '·' ? ' · ' : ': ' ?></span><?php if ($it['note'] === 'příplatek'): ?><span>příplatek</span> <?php endif ?><span class="lst-fv"><?= e($it['value']) ?></span><?php if ($it['note'] !== '' && $it['note'] !== 'příplatek'): ?> <small class="lst-fnote"><?= e($it['note']) ?></small><?php endif ?><?php endif ?></span></li>
          <?php endforeach ?>
        </ul>
      </section>
      <?php endif ?>
      <h2>Popis</h2>
      <div class="prose" translate="no"><?= format_text((string) $l['description']) ?></div>
    </div>

    <aside class="lst-side">
      <section class="card lst-contact" id="kontakt">
        <h2>Kontakt</h2>
        <p class="lst-person"><?= icon('user') ?> <span translate="no"><?= e($l['contact_name']) ?></span></p>
        <?php if ($sold): ?>
          <?php if ($rent): ?><p>Byt je už pronajatý. Děkujeme za zájem.</p><?php else: ?><p>Byt je už prodaný. Děkujeme za zájem.</p><?php endif ?>
        <?php elseif ($canContact): ?>
          <?php require __DIR__ . '/../../_errors.php'; ?>
          <form method="post" action="<?= url('/inzerat/' . $l['id'] . '/kontakt') ?>" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="t" value="<?= e($token) ?>">
            <p class="hp" aria-hidden="true"><label>Web <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
            <label>Vaše jméno <input type="text" name="name" value="<?= e($in['name']) ?>" maxlength="120" autocomplete="name" required></label>
            <label>E-mail nebo telefon <input type="text" name="contact" value="<?= e($in['contact']) ?>" maxlength="190" autocomplete="email" required></label>
            <label>Zpráva <textarea name="message" rows="5" maxlength="3000" required placeholder="Dobrý den, mám zájem o prohlídku…"><?= e($in['message']) ?></textarea></label>
            <button class="btn" type="submit">Odeslat zprávu</button>
            <p class="hint">Zprávu předáme vlastníkovi e-mailem. Jeho adresu neuvidíte – ozve se Vám sám.</p>
          </form>
        <?php else: ?>
          <p class="muted">Inzerát není zveřejněný, zprávy teď nejde posílat.</p>
        <?php endif ?>
      </section>

      <?php if ($vis === 'public'): ?>
      <section class="card lst-share" id="sdilet">
        <h2>Sdílet inzerát</h2>
        <div class="lst-share-buttons">
          <a class="btn btn-small btn-ghost" href="https://www.facebook.com/sharer/sharer.php?u=<?= e(rawurlencode($pageUrl)) ?>" rel="noopener noreferrer" target="_blank">Facebook</a>
          <a class="btn btn-small btn-ghost" href="https://wa.me/?text=<?= e(rawurlencode(listing_title($l) . ', ' . str_replace("\u{a0}", ' ', listing_price_label($l)) . ' ' . $pageUrl)) ?>" rel="noopener noreferrer" target="_blank">WhatsApp</a>
        </div>
        <div class="lst-copy">
          <label>Odkaz na inzerát <input type="text" value="<?= e($pageUrl) ?>" readonly data-select-all></label>
          <button class="btn btn-small btn-ghost" type="button" data-copy="<?= e($pageUrl) ?>">Kopírovat odkaz</button>
        </div>
        <details class="lst-bazos lst-copy">
          <summary>Text pro Bazoš a skupiny</summary>
          <textarea rows="9" readonly data-select-all translate="no"><?= e(listing_share_text($l)) ?></textarea>
          <button class="btn btn-small btn-ghost" type="button" data-copy="<?= e(listing_share_text($l)) ?>">Kopírovat text</button>
        </details>
      </section>
      <?php endif ?>
    </aside>
  </div>

  <footer class="lst-foot">
    <p><span>Inzerát vlastníka jednotky ve společenství <?= e(site('name')) ?></span><?php if ($service !== ''): ?> · <a href="<?= e(platform_url('/')) ?>">web domu provozuje <?= e($service) ?></a><?php endif ?></p>
    <?php if ($vis !== 'none'): ?><p><a href="<?= url('/inzerat/' . $l['id'] . '/nahlasit') ?>" rel="nofollow"><?= icon('flag') ?> <span>Nahlásit inzerát</span></a></p><?php endif ?>
  </footer>
</div>
