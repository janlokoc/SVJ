<div class="wrap page narrow">
  <h1>Nahlásit chybu webu nebo nápad</h1>
  <p class="lead">Něco na webu nefunguje, nebo Vás napadlo, jak ho zlepšit? Napište nám.</p>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <div class="card"><?php $inDialog = false; require __DIR__ . '/_form.php'; ?></div>
  <?php if (!empty($mine)): ?>
  <h2 id="moje">Moje hlášení</h2>
  <div class="ticket-list">
    <?php foreach ($mine as $r): ?>
    <article class="ticket">
      <header class="post-meta"><span class="status status-<?= REPORT_STATUS_CLASS[$r['status']] ?>"><?= e(REPORT_STATUS[$r['status']]) ?></span><span><?= e(REPORT_TYPES[$r['type']] ?? '') ?></span><time><?= cz_datetime($r['created_at']) ?></time></header>
      <div class="prose" translate="no"><?= format_text($r['body']) ?></div>
      <?php if ($r['screenshot'] !== ''): ?><p><a href="<?= url('/hlaseni/' . $r['id'] . '/snimek') ?>">Přiložený snímek</a></p><?php endif ?>
      <?php if ((string) $r['reply'] !== ''): ?><div class="callout report-reply"><strong>Odpověď</strong> <small class="muted"><?= cz_datetime($r['replied_at']) ?></small><div class="prose" translate="no"><?= format_text($r['reply']) ?></div></div><?php endif ?>
    </article>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>
