<div class="wrap page narrow print-doc">
  <p class="no-print"><a class="btn btn-small btn-ghost" href="<?= url('/admin') ?>">← Přehled</a> <button class="btn btn-small" type="button" data-print>Vytisknout cedulku</button> <a class="btn btn-small btn-ghost" href="<?= url('/vyveska') ?>">Otevřít vývěsku</a></p>
  <h1>Vývěska domu</h1>
  <p class="lead no-print">Veřejná stránka bez přihlášení s oznámeními, termíny a havarijními čísly. Vytiskněte tuto cedulku ke vchodu nebo stránku pusťte na obrazovce ve společných prostorách – obnovuje se sama. Ukazuje jen veřejné aktuality.</p>
  <div class="board-card"><?= $qr ?><div><p class="eyebrow"><?= e(site('name')) ?></p><h2>Co je v domě nového?</h2><p>Načtěte kód telefonem.<br><strong><?= e(preg_replace('~^https?://~', '', $address)) ?></strong></p></div></div>
</div>
