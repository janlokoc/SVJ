<div class="wrap page">
  <header class="page-head"><h1>Kontakty</h1>
    <p class="lead">Správu domu zajišťuje výbor SVJ. IČ <?= e(setting('ico')) ?>, číslo účtu <?= e(setting('account')) ?>.</p></header>
  <?php foreach (CONTACT_GROUPS as $k => $label): if (empty($groups[$k])) continue; ?>
    <section class="contact-group<?= $k === 'havarie' ? ' is-alert' : '' ?>" id="<?= $k ?>">
      <h2><?= $k === 'havarie' ? icon('alert') . ' ' : '' ?><?= $label ?></h2>
      <div class="contact-grid">
        <?php foreach ($groups[$k] as $c): ?>
          <div class="contact">
            <?php if ($c['role']): ?><p class="eyebrow"><?= e($c['role']) ?></p><?php endif ?>
            <h3><?= e($c['name']) ?></h3>
            <?php if ($c['note']): ?><p class="contact-note"><?= e($c['note']) ?></p><?php endif ?>
            <?php if ($c['phone']): ?><a class="contact-line" href="<?= e(tel_href($c['phone'])) ?>"><?= icon('phone') ?> <?= e($c['phone']) ?></a><?php endif ?>
            <?php if ($c['email']): ?><a class="contact-line" href="mailto:<?= e($c['email']) ?>"><?= icon('mail') ?> <?= e($c['email']) ?></a><?php endif ?>
          </div>
        <?php endforeach ?>
      </div>
      <?php if ($k === 'havarie' && setting('emergency_note')): ?><div class="prose callout"><?= format_text(setting('emergency_note')) ?></div><?php endif ?>
    </section>
  <?php endforeach ?>
</div>
