<div class="wrap page narrow">
  <header class="page-head"><h1>Záloha dat</h1>
    <p class="lead">Stáhne jeden soubor ZIP se všemi daty webu a nahranými dokumenty (<?= $files ?> souborů, <?= e(file_size($size) ?: '0 kB') ?>).</p></header>
  <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <label>Pro stažení zadejte své heslo <input type="password" name="current_password" id="backup-password" autocomplete="current-password" required></label>
    <button class="btn btn-big" type="submit">Stáhnout zálohu</button>
    <p class="hint">Záloha obsahuje osobní údaje obyvatel, proto ji uchovávejte bezpečně (ne v e-mailu ani na sdíleném disku). Doporučujeme stahovat ji pravidelně, například jednou měsíčně a před každou větší změnou.</p>
    <p class="hint">Obnova ze zálohy se dělá na serveru příkazem <code>php tools/restore-backup.php zaloha.zip</code>; stejně lze web přestěhovat na jiný hosting nebo jinou databázi.</p>
  </form>
</div>
