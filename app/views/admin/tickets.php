<div class="wrap page narrow">
  <header class="page-head"><h1>Hlášené závady</h1></header>
  <?php if (!$tickets): ?><p class="empty">Zatím nebyla nahlášena žádná závada.</p><?php endif ?>
  <div class="ticket-list">
  <?php foreach ($tickets as $t): ?>
    <article class="ticket" id="z<?= $t['id'] ?>">
      <header><h3><?= e($t['subject']) ?></h3><span class="status status-<?= e($t['status']) ?>"><?= TICKET_STATUS[$t['status']] ?></span></header>
      <p class="post-meta"><?= cz_datetime($t['created_at']) ?><?= $t['location'] ? ' · ' . e($t['location']) : '' ?> · <?= e(author_label($t)) ?><?= !empty($t['author_unit']) ? ', byt ' . e($t['author_unit']) : '' ?>
        <?php if (!empty($t['author_phone'])): ?> · <a href="<?= e(tel_href($t['author_phone'])) ?>"><?= e($t['author_phone']) ?></a><?php endif ?>
        <?php if (!empty($t['author_email'])): ?> · <a href="mailto:<?= e($t['author_email']) ?>"><?= e($t['author_email']) ?></a><?php endif ?></p>
      <div class="prose"><?= format_text($t['body']) ?></div>
      <form method="post" action="<?= url('/admin/zavady/' . $t['id']) ?>" class="form ticket-form">
        <?= csrf_field() ?>
        <label>Odpověď pro obyvatele <textarea name="admin_note" rows="2"><?= e($t['admin_note']) ?></textarea></label>
        <div class="form-row">
          <label>Stav <select name="status"><?php foreach (TICKET_STATUS as $k => $label): ?><option value="<?= $k ?>"<?= $t['status'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
          <div class="actions"><button class="btn" type="submit">Uložit</button></div>
        </div>
      </form>
      <form method="post" action="<?= url('/admin/zavady/' . $t['id'] . '/smazat') ?>" data-confirm="Opravdu smazat toto hlášení?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat hlášení</button></form>
    </article>
  <?php endforeach ?>
  </div>
</div>
