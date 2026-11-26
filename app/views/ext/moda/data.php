<?php /* Data domu: export všech dat a zpracovatelská smlouva. $allowed, $next, $error, $dpa, $backup, $last, $files, $size */ ?>
<div class="wrap page narrow">
  <header class="page-head"><h1>Data a smlouva</h1>
    <p class="lead">Data webu patří Vašemu společenství. Kdykoli si je můžete stáhnout v otevřeném formátu a převést jinam.<?= function_exists('help_link') ? ' ' . help_link('data-a-smlouva') : '' ?></p></header>
  <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif ?>

  <section class="card form" id="export">
    <h2>Export všech dat domu</h2>
    <p>Jeden soubor ZIP: <strong>data.json</strong> se všemi daty webu (účty, jednotky a podíly, aktuality, dokumenty, termíny, hlasování, hlášení, záznamy činnosti…) a složka <strong>uploads</strong> s nahranými soubory (<?= $files ?> dokumentů, <?= e(file_size($size) ?: '0 kB') ?>).</p>
    <?php if (!$backup): ?><p class="hint">Ve Vaší edici jde export stáhnout jednou za <?= EXPORT_FREE_DAYS ?> dní; neomezeně (a včetně obnovy) to umí <a href="<?= url('/admin/zaloha') ?>">Záloha dat</a> v edici Standard.</p><?php endif ?>
    <?php if ($last !== ''): ?><p class="hint">Naposledy staženo <?= e(cz_datetime($last)) ?>.</p><?php endif ?>
    <?php if ($allowed): ?>
      <form method="post" action="<?= url('/admin/data-domu/export') ?>" class="form">
        <?= csrf_field() ?>
        <label>Pro stažení zadejte své heslo <input type="password" name="current_password" id="export-password" autocomplete="current-password" required></label>
        <button class="btn" type="submit">Stáhnout export (ZIP)</button>
      </form>
    <?php else: ?>
      <p><strong>Další export bude možný <?= e(cz_date($next)) ?>.</strong></p>
    <?php endif ?>
    <p class="hint">Export obsahuje osobní údaje obyvatel – uchovávejte ho bezpečně. Stažení se zapíše do záznamů. Své vlastní údaje si každý uživatel stáhne v Můj účet → Moje údaje a soukromí.</p>
  </section>

  <section class="card" id="smlouva">
    <h2>Smlouva o zpracování osobních údajů</h2>
    <?php if ($dpa): ?>
      <p>Společenství je správcem osobních údajů, provozovatel služby jejich zpracovatelem (čl. 28 GDPR). Smlouva je uzavřena přijetím obchodních podmínek; zde si ji stáhnete předvyplněnou údaji Vašeho společenství – k podpisu a založení do dokumentace GDPR.</p>
      <p class="actions"><a class="btn btn-small" href="<?= url('/admin/data-domu/smlouva') ?>">Zobrazit smlouvu k tisku</a>
        <a class="btn btn-small btn-ghost" href="<?= e(platform_url('/bezpecnost')) ?>">Bezpečnost a ochrana dat</a></p>
      <?php if (setting('ico') === ''): ?><p class="hint">Ve smlouvě chybí IČO společenství – doplňte ho v <a href="<?= url('/admin/nastaveni') ?>">Nastavení</a>.</p><?php endif ?>
    <?php else: ?>
      <p>Web provozujete na vlastním serveru, takže žádný zpracovatel osobních údajů v této podobě není: správcem i provozovatelem je Vaše společenství (případně firma, která Vám server spravuje – s tou si smlouvu o zpracování uzavřete sami).</p>
    <?php endif ?>
  </section>
</div>
