<?php
$me = user();
$item = function (array $p) use ($me) { ?>
  <article class="reply<?= ($p['author_role'] ?? '') === 'admin' ? ' is-admin' : '' ?>">
    <header><strong><?= e(author_label($p)) ?></strong><?php if (($p['author_role'] ?? '') === 'admin'): ?> <span class="tag">výbor</span><?php endif ?>
      <time><?= cz_datetime($p['created_at']) ?></time>
      <?php if ($me['role'] === 'admin'): ?><a class="reply-edit" href="<?= url('/admin/diskuze/' . $p['id']) ?>">Upravit</a><?php endif ?>
      <?php if ($me['role'] === 'admin' || (int) $p['author_id'] === (int) $me['id']): ?>
        <form method="post" action="<?= url('/diskuze/' . $p['id'] . '/smazat') ?>" data-confirm="Opravdu smazat tento příspěvek?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat</button></form>
      <?php endif ?>
    </header>
    <div class="prose"><?= format_text($p['body']) ?></div>
    <?php if (!empty($p['edited_note'])): ?><p class="hint edited-note"><?= e($p['edited_note']) ?></p><?php endif ?>
  </article>
<?php } ?>
<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/diskuze') ?>">← Diskuze</a></p>
  <h1><?= e($thread['title']) ?></h1>
  <div class="replies">
    <?php $item($thread); foreach ($replies as $r) { $item($r); } ?>
  </div>
  <form method="post" action="<?= url('/diskuze/' . $thread['id']) ?>" class="card form">
    <h2>Odpovědět</h2>
    <?= csrf_field() ?>
    <label>Vaše odpověď <textarea name="body" rows="4" maxlength="5000" required></textarea></label>
    <?php $aiShow = user() && ai_resident_available(); $aiUrl = url('/ai-text'); $aiTarget = 'textarea[name=body]'; $aiTasks = ['polish', 'shorten']; $aiContext = ''; require __DIR__ . '/_ai_mini.php'; ?>
    <button class="btn" type="submit">Odeslat odpověď</button>
  </form>
</div>
