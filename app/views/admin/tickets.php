<div class="wrap page narrow">
  <header class="page-head"><h1>Závady a požadavky</h1></header>
  <?php if (!$tickets): ?><p class="empty">Zatím nikdo nenahlásil žádnou závadu ani požadavek.</p><?php endif ?>
  <div class="ticket-list">
  <?php foreach ($tickets as $t): ?>
    <article class="ticket" id="z<?= $t['id'] ?>">
      <header><h3><?= e($t['subject']) ?></h3><span class="status status-<?= e($t['status']) ?>"><?= TICKET_STATUS[$t['status']] ?></span></header>
      <p class="post-meta"><?= cz_datetime($t['created_at']) ?><?= $t['location'] ? ' · ' . e($t['location']) : '' ?> · <?= e(author_label($t)) ?>
        <?php if (!empty($t['author_phone'])): ?> · <a href="<?= e(tel_href($t['author_phone'])) ?>"><?= e($t['author_phone']) ?></a><?php endif ?>
        <?php if (!empty($t['author_email'])): ?> · <a href="mailto:<?= e($t['author_email']) ?>"><?= e($t['author_email']) ?></a><?php endif ?></p>
      <div class="prose"><?= format_text($t['body']) ?></div>
      <form method="post" action="<?= url('/admin/zavady/' . $t['id']) ?>" class="form ticket-form">
        <?= csrf_field() ?>
        <details class="ticket-edit"><summary>Upravit text hlášení</summary>
          <div class="form">
            <label>Co se stalo <input type="text" name="subject" value="<?= e($t['subject']) ?>" maxlength="150"></label>
            <label>Kde <input type="text" name="location" value="<?= e($t['location']) ?>" maxlength="150"></label>
            <label>Popis <textarea name="body" rows="3" maxlength="5000"><?= e($t['body']) ?></textarea></label>
          </div>
        </details>
        <label>Odpověď pro obyvatele <textarea name="admin_note" rows="2"><?= e($t['admin_note']) ?></textarea></label>
        <?php $aiTarget = 'textarea[name=admin_note]'; $aiTasks = ['reply', 'polish']; $aiContext = 'Hlášení obyvatele: ' . $t['subject'] . ($t['location'] ? ' (' . $t['location'] . ')' : '') . "\n" . $t['body']; require __DIR__ . '/_ai.php'; ?>
        <div class="form-row">
          <?php if ($suppliers): ?><label>Řeší dodavatel <select name="supplier_id"><option value="">—</option><?php foreach ($suppliers as $sp): ?><option value="<?= $sp['id'] ?>"<?= (int) $t['supplier_id'] === (int) $sp['id'] ? ' selected' : '' ?>><?= e($sp['name'] . ($sp['field'] !== '' ? ' (' . $sp['field'] . ')' : '')) ?></option><?php endforeach ?></select></label><?php endif ?>
          <label>Stav <select name="status"><?php foreach (TICKET_STATUS as $k => $label): ?><option value="<?= $k ?>"<?= $t['status'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
          <div class="actions"><button class="btn" type="submit">Uložit</button></div>
        </div>
      </form>
      <form method="post" action="<?= url('/admin/zavady/' . $t['id'] . '/smazat') ?>" data-confirm="Opravdu smazat toto hlášení?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat hlášení</button></form>
    </article>
  <?php endforeach ?>
  </div>
</div>
