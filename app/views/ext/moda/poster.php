<?php /* Plakát do vchodu (A4 k tisku): $p (moda_poster()), $hasJoin, $withJoin */ ?>
<div class="wrap page poster-page">
<div class="poster-tools no-print">
  <a class="btn btn-small btn-ghost" href="<?= url('/admin') ?>">← Administrace</a>
  <button class="btn btn-small" type="button" data-print>Vytisknout</button>
  <?php if ($hasJoin): ?>
    <a class="btn btn-small btn-ghost" href="<?= url('/admin/plakat?odkaz=' . ($withJoin ? '0' : '1')) ?>"><?= $withJoin ? 'QR kód jen s adresou webu' : 'QR kód s odkazem pro přihlášení k jednotce' ?></a>
  <?php else: ?>
    <span class="hint">Chcete, aby se obyvatelé k jednotce přihlásili sami? Zapněte <a href="<?= url('/admin/uzivatele/zadosti') ?>">odkaz pro přiřazení k jednotce</a> a QR kód na plakátu povede rovnou na něj.</span>
  <?php endif ?>
  <span class="hint">Tiskněte na A4 na výšku; v dialogu tisku vypněte záhlaví a zápatí prohlížeče.</span>
</div>
<article class="poster">
  <p class="poster-eyebrow">Web našeho domu</p>
  <h1 class="poster-title"><?= e($p['name']) ?></h1>
  <?php if ($p['address'] !== ''): ?><p class="poster-address"><?= e($p['address']) ?></p><?php endif ?>
  <div class="poster-qr"><?= $p['qr'] ?></div>
  <p class="poster-web"><?= e($p['web']) ?></p>
  <p class="poster-lead">Aktuality od výboru, dokumenty, hlášení závad, hlasování a termíny – na jednom místě, i v mobilu.</p>
  <h2 class="poster-h"><?= $p['join'] ? 'Jak se zaregistrovat' : 'Jak získat účet' ?></h2>
  <ol class="poster-steps">
    <?php foreach ($p['steps'] as $step): ?><li><?= e($step) ?></li><?php endforeach ?>
  </ol>
  <?php if ($p['contact'] !== ''): ?><p class="poster-contact">Dotazy: <?= e($p['contact']) ?></p><?php endif ?>
  <p class="poster-foot">Výbor <?= e($p['title']) ?> · vyvěšeno <?= cz_date(date('Y-m-d')) ?></p>
</article>
</div>
