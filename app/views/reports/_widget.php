<?php /* Okno pro hlášení, vložené do každé stránky; otevírá ho tlačítko Nahlásit v hlavičce */ $back = report_back($GLOBALS['PATH'] ?? '/'); $inDialog = true; ?>
<dialog class="report-dialog" id="report-dialog" aria-labelledby="report-dialog-title">
  <h2 id="report-dialog-title">Nahlásit problém nebo nápad</h2>
  <p class="muted">Hlášení dostane provozovatel webu. <?php if (empty($GLOBALS['IS_PLATFORM']) && user()): ?><a href="<?= url('/hlaseni') ?>">Moje hlášení</a><?php endif ?></p>
  <?php require __DIR__ . '/_form.php'; ?>
</dialog>
