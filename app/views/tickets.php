<div class="wrap page narrow">
  <header class="page-head"><h1>Závady a požadavky</h1>
    <p class="lead">Nahlaste výboru závadu ve společných prostorách nebo mu pošlete požadavek. V případě havárie volejte rovnou <a href="<?= url('/kontakty') ?>#havarie">havarijní servis</a>.</p></header>
  <form method="post" action="<?= url('/zavady') ?>" class="card form" enctype="multipart/form-data">
    <h2>Nová závada nebo požadavek</h2>
    <?= csrf_field() ?>
    <label>Co se stalo <input type="text" name="subject" maxlength="150" placeholder="Např. nesvítí světlo na chodbě, žádost o klíč od kolárny" required></label>
    <label>Kde <input type="text" name="location" maxlength="150" placeholder="Např. vchod C, 3. patro"></label>
    <label>Popis <textarea name="body" rows="4" maxlength="5000" required></textarea></label>
    <label>Fotografie nebo dokument <small class="muted">(nepovinné, nejvýše 4 soubory)</small> <input type="file" name="files[]" multiple accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf" data-shrink></label>
    <p class="hint">Fotka závady výboru hodně pomůže. Z telefonu ji můžete rovnou vyfotit; velké fotografie se před odesláním samy zmenší. Přílohy vidíte jen Vy a výbor.</p>
    <button class="btn" type="submit">Odeslat výboru</button>
  </form>
  <h2>Moje hlášení</h2>
  <?php if (!$tickets): ?><p class="empty">Zatím jste nic neposlali.</p><?php endif ?>
  <div class="ticket-list">
    <?php foreach ($tickets as $t): ?>
      <article class="ticket">
        <header><h3><?= e($t['subject']) ?></h3><span class="status status-<?= e($t['status']) ?>"><?= TICKET_STATUS[$t['status']] ?></span></header>
        <p class="post-meta"><?= cz_datetime($t['created_at']) ?><?= $t['location'] ? ' · ' . e($t['location']) : '' ?><?= !empty($t['supplier_name']) ? ' · řeší: <span translate="no">' . e($t['supplier_name']) . '</span>' : '' ?></p>
        <div class="prose"><?= format_text($t['body']) ?></div>
        <?php $ticketFiles = $files[(int) $t['id']] ?? []; require __DIR__ . '/_ticket_files.php'; ?>
        <?php if ($t['admin_note']): ?><div class="callout"><strong>Odpověď výboru:</strong> <?= format_text($t['admin_note']) ?></div><?php endif ?>
      </article>
    <?php endforeach ?>
  </div>
</div>
