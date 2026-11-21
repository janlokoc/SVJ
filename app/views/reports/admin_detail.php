<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url($base) ?>">← Hlášení</a></p>
  <h1>Hlášení č. <?= (int) $r['id'] ?></h1>
  <div class="card">
    <header class="post-meta"><span class="status status-<?= REPORT_STATUS_CLASS[$r['status']] ?>"><?= e(REPORT_STATUS[$r['status']]) ?></span>
      <span><?= $r['type'] === 'auto' ? 'Chyba zachycená webem' . ((int) $r['hits'] > 1 ? ' (' . (int) $r['hits'] . '×, naposledy ' . cz_datetime($r['updated_at']) . ')' : '') : e(REPORT_TYPES[$r['type']] ?? '') ?></span><time><?= cz_datetime($r['created_at']) ?></time></header>
    <div class="prose"><?= $r['type'] === 'auto' ? '<pre class="report-trace">' . e($r['body']) . '</pre>' : format_text($r['body']) ?></div>
    <dl class="pay-data report-meta">
      <?php if ($r['type'] !== 'auto'): ?><dt>Kdo</dt><dd><?= e(trim($r['user_name'] . ' ' . ($r['user_email'] !== '' ? '<' . $r['user_email'] . '>' : ''))) ?: '—' ?><?= $r['user_role'] !== '' ? ' · ' . e($r['user_role']) : '' ?></dd><?php endif ?>
      <?php if ($r['tenant'] !== ''): ?><dt>Web</dt><dd><?= e($r['tenant']) ?></dd><?php endif ?>
      <dt>Stránka</dt><dd><?php if ($r['url'] !== ''): ?><a href="<?= e($r['url']) ?>" rel="noopener noreferrer"><?= e($r['url']) ?></a><?php else: ?>—<?php endif ?><?= $r['page_title'] !== '' ? '<br><small class="muted">' . e($r['page_title']) . '</small>' : '' ?></dd>
      <dt>Prohlížeč</dt><dd><small><?= e($r['agent']) ?: '—' ?><?= $r['viewport'] !== '' ? ' · okno ' . e($r['viewport']) : '' ?></small></dd>
    </dl>
    <?php if ($r['screenshot'] !== ''): ?><p><a href="<?= url($base . '/' . $r['id'] . '/snimek') ?>"><img class="report-img" src="<?= url($base . '/' . $r['id'] . '/snimek') ?>" alt="Snímek stránky přiložený k hlášení"></a></p><?php endif ?>
  </div>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <h2>Vyřízení</h2>
    <label>Stav <select name="status"><?php foreach (REPORT_STATUS as $k => $label): ?><option value="<?= $k ?>"<?= $r['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
    <?php if ($r['type'] !== 'auto'): ?>
    <label>Odpověď autorovi hlášení <textarea name="reply" rows="5" maxlength="5000"><?= e((string) $r['reply']) ?></textarea></label>
    <p class="hint">Stav a odpověď uvidí autor v části Moje hlášení<?= filter_var($r['user_email'], FILTER_VALIDATE_EMAIL) ? ' a při změně mu přijdou e-mailem na ' . e($r['user_email']) : '' ?>.<?= (string) $r['replied_at'] !== '' ? ' Naposledy odpovězeno ' . cz_datetime($r['replied_at']) . ' (' . e($r['replied_by']) . ').' : '' ?></p>
    <?php endif ?>
    <label>Interní poznámka (autor ji nevidí) <textarea name="note" rows="3" maxlength="5000"><?= e((string) $r['note']) ?></textarea></label>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit</button></div>
  </form>
</div>
