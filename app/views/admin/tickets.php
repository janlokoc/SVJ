<div class="wrap page narrow">
  <header class="page-head"><h1>Závady a požadavky</h1>
    <p class="lead">Hlášení přiřaďte řešiteli a dejte mu termín. Obyvateli můžete napsat zprávu – přijde mu e-mailem a odpovědět může na webu. Nová hlášení a odpovědi chodí správcům e-mailem (přiřazená jen řešiteli).</p></header>
  <nav class="chips" aria-label="Filtr hlášení">
    <?php foreach (['otevrene' => 'Otevřená', 'moje' => 'Přiřazená mně', 'po-terminu' => 'Po termínu', 'vse' => 'Všechna'] as $k => $label): ?>
      <a href="<?= url('/admin/zavady' . ($k !== 'otevrene' ? '?f=' . $k : '')) ?>"<?= $filter === $k ? ' aria-current="page" class="is-active"' : '' ?>><?= $label ?> <small>(<?= $counts[$k] ?>)</small></a>
    <?php endforeach ?>
  </nav>
  <?php if (!$tickets): ?><p class="empty"><?= $counts['vse'] ? 'V tomto výběru nic není.' : 'Zatím nikdo nenahlásil žádnou závadu ani požadavek.' ?></p><?php endif ?>
  <div class="ticket-list">
  <?php foreach ($tickets as $t): $late = ticket_overdue($t); $history = $log[(int) $t['id']] ?? []; ?>
    <article class="ticket<?= $late ? ' is-late' : '' ?>" id="z<?= $t['id'] ?>">
      <header><h3><?= e($t['subject']) ?></h3><span class="status status-<?= e($t['status']) ?>"><?= TICKET_STATUS[$t['status']] ?></span></header>
      <p class="post-meta"><?= cz_datetime($t['created_at']) ?><?= $t['location'] ? ' · ' . e($t['location']) : '' ?> · <?= e(author_label($t)) ?>
        <?php if (!empty($t['author_phone'])): ?> · <a href="<?= e(tel_href($t['author_phone'])) ?>"><?= e($t['author_phone']) ?></a><?php endif ?>
        <?php if (!empty($t['author_email'])): ?> · <a href="mailto:<?= e($t['author_email']) ?>"><?= e($t['author_email']) ?></a><?php endif ?></p>
      <?php if ($t['assignee_name'] || $t['due_date'] !== ''): ?>
      <p class="ticket-owner"><?= $t['assignee_name'] ? 'Řeší: <strong>' . e($t['assignee_name']) . '</strong>' : 'Bez řešitele' ?><?= $t['due_date'] !== '' ? ' · termín ' . cz_date($t['due_date']) . ($late ? ' <span class="tag tag-late">po termínu</span>' : '') : '' ?></p>
      <?php endif ?>
      <div class="prose"><?= format_text($t['body']) ?></div>
      <?php $ticketFiles = $files[(int) $t['id']] ?? []; require __DIR__ . '/../_ticket_files.php'; ?>
      <?php $forStaff = true; require __DIR__ . '/../_ticket_thread.php'; ?>
      <form method="post" action="<?= url('/admin/zavady/' . $t['id']) ?>" class="form ticket-form">
        <?= csrf_field() ?><input type="hidden" name="f" value="<?= e($filter) ?>">
        <details class="ticket-edit"><summary>Upravit text hlášení</summary>
          <div class="form">
            <label>Co se stalo <input type="text" name="subject" value="<?= e($t['subject']) ?>" maxlength="150"></label>
            <label>Kde <input type="text" name="location" value="<?= e($t['location']) ?>" maxlength="150"></label>
            <label>Popis <textarea name="body" rows="3" maxlength="5000"><?= e($t['body']) ?></textarea></label>
          </div>
        </details>
        <label>Zpráva obyvateli <small class="muted">(nepovinné – přijde mu e-mailem)</small> <textarea name="message" rows="2" maxlength="5000"></textarea></label>
        <?php $aiTarget = 'textarea[name=message]'; $aiTasks = ['reply', 'polish']; $aiContext = 'Hlášení obyvatele: ' . $t['subject'] . ($t['location'] ? ' (' . $t['location'] . ')' : '') . "\n" . $t['body']; require __DIR__ . '/_ai.php'; ?>
        <div class="form-row">
          <label>Řeší <select name="assignee_id"><option value="">—</option><?php foreach ($admins as $a): ?><option value="<?= $a['id'] ?>"<?= (int) $t['assignee_id'] === (int) $a['id'] ? ' selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach ?></select></label>
          <label>Termín <input type="date" name="due_date" value="<?= e($t['due_date']) ?>"></label>
        </div>
        <div class="form-row">
          <?php if ($suppliers): ?><label>Dodavatel <select name="supplier_id"><option value="">—</option><?php foreach ($suppliers as $sp): ?><option value="<?= $sp['id'] ?>"<?= (int) $t['supplier_id'] === (int) $sp['id'] ? ' selected' : '' ?>><?= e($sp['name'] . ($sp['field'] !== '' ? ' (' . $sp['field'] . ')' : '')) ?></option><?php endforeach ?></select></label><?php endif ?>
          <label>Stav <select name="status"><?php foreach (TICKET_STATUS as $k => $label): ?><option value="<?= $k ?>"<?= $t['status'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
          <div class="actions"><button class="btn" type="submit">Uložit</button></div>
        </div>
      </form>
      <?php if ($history): ?>
      <details class="ticket-history"><summary>Historie (<?= count($history) ?>)</summary>
        <ol class="plain-list"><?php foreach ($history as $r): ?>
          <li><small class="muted"><?= cz_datetime($r['at']) ?></small> · <?= e($r['user_name'] !== '' ? $r['user_name'] : 'Web') ?> – <?= e(ticket_log_label($r)) ?><?= $r['text'] !== '' && !in_array($r['kind'], ['msg_admin', 'msg_author'], true) ? ': ' . e($r['text']) : '' ?></li>
        <?php endforeach ?></ol></details>
      <?php endif ?>
      <form method="post" action="<?= url('/admin/zavady/' . $t['id'] . '/smazat') ?>" data-confirm="Opravdu smazat toto hlášení?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat hlášení</button></form>
    </article>
  <?php endforeach ?>
  </div>
</div>
