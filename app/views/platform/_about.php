<?php /* „Kdo za tím stojí“: kdo službu provozuje a doporučení výborů (konzole → Obchod). Bez obsahu se nezobrazí. */
if (function_exists('about_has_content') && about_has_content()):
    $aName = pset('about_name'); $aRole = pset('about_role'); $aStory = trim(pset('about_story')); $aRefs = testimonials_public(); $aPhoto = about_photo_file() !== ''; ?>
<section class="lpx-section" id="kdo-za-tim-stoji">
  <div class="wrap">
    <h2>Kdo za tím stojí</h2>
    <?php if ($aStory !== ''): ?>
    <div class="about-founder">
      <?php if ($aPhoto): ?><img class="about-photo" src="<?= url('/kdo-za-tim-stoji/foto') ?>?v=<?= e(pset('about_photo_v')) ?>" alt="<?= e($aName !== '' ? $aName : 'Provozovatel služby') ?>" width="160" height="160" loading="lazy"><?php endif ?>
      <div><?php foreach (preg_split('/\R{2,}/', $aStory) as $para): ?><p><?= nl2br(e(trim($para))) ?></p><?php endforeach ?>
        <?php if ($aName !== ''): ?><p class="about-sign"><strong><?= e($aName) ?></strong><?= $aRole !== '' ? '<br><span class="muted">' . e($aRole) . '</span>' : '' ?></p><?php endif ?></div>
    </div>
    <?php endif ?>
    <?php if ($aRefs): ?>
    <div class="about-refs">
      <?php foreach ($aRefs as $r): ?><blockquote class="about-ref"><p>„<?= e($r['text']) ?>“</p><footer><strong><?= e($r['who']) ?></strong><?= $r['house'] !== '' ? ', ' . e($r['house']) : '' ?></footer></blockquote><?php endforeach ?>
    </div>
    <?php endif ?>
  </div>
</section>
<?php endif ?>
