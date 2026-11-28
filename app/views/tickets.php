<div class="wrap page narrow">
  <header class="page-head"><h1>Závady a požadavky</h1>
    <p class="lead">Nahlaste výboru závadu ve společných prostorách nebo mu pošlete požadavek. V případě havárie volejte rovnou <a href="<?= url('/kontakty') ?>#havarie">havarijní servis</a>.</p></header>
  <?php if (function_exists('unitcase_enabled') && unitcase_enabled() && unitcase_side() === 'tenant' && unitcase_my_units()): ?><p class="hint">Závada uvnitř Vašeho bytu (spotřebič, vybavení, okna)? Tu řeší vlastník bytu – <a href="<?= url('/byt/novy') ?>">napište mu</a>.</p><?php endif ?>
  <form method="post" action="<?= url('/zavady') ?>" class="card form" enctype="multipart/form-data">
    <h2>Nová závada nebo požadavek</h2>
    <?= csrf_field() ?>
    <label>Co se stalo <input type="text" name="subject" maxlength="150" placeholder="Např. nesvítí světlo na chodbě, žádost o klíč od kolárny" required></label>
    <label>Kde <input type="text" name="location" maxlength="150" placeholder="Např. vchod C, 3. patro"></label>
    <label>Popis <textarea name="body" rows="4" maxlength="5000" required></textarea></label>
    <?php $aiShow = user() && ai_resident_available(); $aiUrl = url('/ai-text'); $aiTarget = 'textarea[name=body]'; $aiTasks = ['polish', 'shorten']; $aiContext = ''; require __DIR__ . '/_ai_mini.php'; ?>
    <label>Fotografie nebo dokument <small class="muted">(nepovinné, nejvýše 4 soubory)</small> <input type="file" name="files[]" multiple accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf" data-shrink></label>
    <p class="hint">Fotka závady výboru hodně pomůže. Z telefonu ji můžete rovnou vyfotit; velké fotografie se před odesláním samy zmenší. Přílohy vidíte jen Vy a výbor.</p>
    <button class="btn" type="submit">Odeslat výboru</button>
  </form>
  <h2>Moje hlášení</h2>
  <?php if (!$tickets): ?><p class="empty">Zatím jste nic neposlali.</p><?php endif ?>
  <div class="ticket-list">
    <?php foreach ($tickets as $t): ?>
      <article class="ticket" id="z<?= $t['id'] ?>">
        <header><h3><?= e($t['subject']) ?></h3><span class="status status-<?= e($t['status']) ?>"><?= TICKET_STATUS[$t['status']] ?></span></header>
        <p class="post-meta"><?= cz_datetime($t['created_at']) ?><?= $t['location'] ? ' · ' . e($t['location']) : '' ?><?= !empty($t['supplier_name']) ? ' · řeší: <span translate="no">' . e($t['supplier_name']) . '</span>' : '' ?></p>
        <div class="prose"><?= format_text($t['body']) ?></div>
        <?php $ticketFiles = $files[(int) $t['id']] ?? []; require __DIR__ . '/_ticket_files.php'; ?>
        <?php $history = $log[(int) $t['id']] ?? []; $forStaff = false; require __DIR__ . '/_ticket_thread.php'; ?>
        <?php if ($t['due_date'] !== '' && $t['status'] !== 'done'): ?><p class="hint">Předpokládané vyřešení do <?= cz_date($t['due_date']) ?>.</p><?php endif ?>
        <details class="ticket-reply"<?= $history && end($history)['kind'] === 'msg_admin' ? ' open' : '' ?>><summary><?= $t['status'] === 'done' ? 'Není vyřešeno? Napište výboru' : 'Doplnit nebo odpovědět' ?></summary>
          <form method="post" action="<?= url('/zavady/' . $t['id'] . '/odpoved') ?>" class="form"><?= csrf_field() ?>
            <label>Zpráva <textarea name="message" rows="3" maxlength="5000" required></textarea></label>
            <?php $aiShow = user() && ai_resident_available(); $aiUrl = url('/ai-text'); $aiTarget = 'textarea[name=message]'; $aiTasks = ['polish', 'shorten']; $aiContext = ''; require __DIR__ . '/_ai_mini.php'; ?>
            <button class="btn btn-small" type="submit">Odeslat</button></form></details>
      </article>
    <?php endforeach ?>
  </div>
</div>
