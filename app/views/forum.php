<div class="wrap page narrow">
  <header class="page-head"><h1>Diskuze</h1><p class="lead"><?= e(setting('forum_note')) ?></p></header>
  <?php if (!$threads): ?><p class="empty">Zatím tu není žádné téma. Založte první.</p><?php endif ?>
  <ul class="thread-list">
    <?php foreach ($threads as $t): ?>
      <li><a href="<?= url('/diskuze/' . $t['id']) ?>">
        <span class="thread-title"><?= e($t['title']) ?></span>
        <span class="thread-meta"><?= e(author_label($t)) ?> · <?= cz_datetime($t['last_activity_at']) ?></span>
        <span class="thread-count"><?= icon('chat') ?> <?= (int) $t['replies'] ?></span>
      </a></li>
    <?php endforeach ?>
  </ul>
  <form method="post" action="<?= url('/diskuze') ?>" class="card form">
    <h2>Nové téma</h2>
    <?= csrf_field() ?>
    <label>Název tématu <input type="text" name="title" maxlength="150" required></label>
    <label>Text příspěvku <textarea name="body" rows="5" maxlength="5000" required></textarea></label>
    <?php $aiShow = user() && ai_resident_available(); $aiUrl = url('/ai-text'); $aiTarget = 'textarea[name=body]'; $aiTasks = ['polish', 'shorten']; $aiContext = ''; require __DIR__ . '/_ai_mini.php'; ?>
    <button class="btn" type="submit">Založit téma</button>
  </form>
</div>
