<?php /* Karta importu z POHODY na stránce Platby na jednotku. */
ledger_ready();
$lp = $_SESSION['ledger_preview']['summary'] ?? null;
$lpFile = $_SESSION['ledger_preview']['file'] ?? '';
$last = one('SELECT * FROM ledger_imports ORDER BY id DESC LIMIT 1'); ?>
<section class="card" id="pohoda">
  <h2>Konto bytu z účetnictví POHODA</h2>
  <p>Nahrajte XML export z POHODY: agendu <strong>Ostatní pohledávky</strong> (předpisy záloh na jednotky, s položkami a stavem úhrady) a příjmy z agendy <strong>Banka</strong>. Vlastník pak v Platby a zálohy uvidí předpis po položkách, pohledávky se splatností a co zbývá uhradit, a přijaté platby. Doklady se k jednotkám přiřadí podle <strong>variabilního symbolu</strong> z tabulky níže.</p>
  <p><?= help_link('pohoda-konto', 'Jak udělat export v POHODĚ') ?></p>
  <?php if ($lp): ?>
    <div class="flash flash-<?= $lp['unmatched'] ? 'info' : 'ok' ?>">Soubor <?= e($lpFile) ?>: pohledávek <strong><?= (int) $lp['receivables'] ?></strong>, plateb <strong><?= (int) $lp['payments'] ?></strong><?= $lp['skipped'] ? ', jiných dokladů (přeskočí se) ' . (int) $lp['skipped'] : '' ?>. Přiřadí se k <strong><?= (int) $lp['units'] ?></strong> jednotkám.
      <?php if ($lp['unmatched']): ?><br>Neznámé variabilní symboly (doklady se uloží bez jednotky): <?= e(implode(', ', array_slice($lp['unmatched'], 0, 20))) ?><?= count($lp['unmatched']) > 20 ? ' …' : '' ?>. <?= $lp['vsKnown'] ? 'Doplňte VS u jednotek v tabulce níže.' : 'U jednotek zatím není vyplněný žádný variabilní symbol – nahrajte nejdřív předpisy s VS (Nahrát z účetnictví).' ?><?php endif ?></div>
    <form method="post" action="<?= url('/admin/platby/pohoda') ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="do" value="save"><button class="btn" type="submit">Uložit doklady</button></form>
  <?php else: ?>
    <form method="post" action="<?= url('/admin/platby/pohoda') ?>" enctype="multipart/form-data" class="form"><?= csrf_field() ?>
      <label>XML soubor z POHODY <input type="file" name="file" accept=".xml,text/xml,application/xml" required></label>
      <button class="btn" type="submit">Zkontrolovat soubor</button></form>
  <?php endif ?>
  <?php if ($last): ?><p class="hint">Poslední import <?= e(cz_datetime($last['at'])) ?>: pohledávek <?= (int) $last['receivables'] ?>, plateb <?= (int) $last['payments'] ?><?= $last['unmatched'] ? ', bez jednotky ' . (int) $last['unmatched'] : '' ?>. Opakovaný import stejné doklady přepíše (např. když mezitím přišla úhrada).</p><?php endif ?>
</section>
