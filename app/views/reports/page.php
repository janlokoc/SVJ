<div class="wrap page narrow">
  <h1>Podpora webu – problém nebo nápad</h1>
  <p class="lead">Něco na webu nefunguje, nebo Vás napadlo, jak ho zlepšit? Napište nám.</p>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <div class="card"><?php $inDialog = false; require __DIR__ . '/_form.php'; ?></div>
  <?php if (!empty($mine)): ?>
  <h2 id="moje">Moje hlášení</h2>
  <div class="ticket-list">
    <?php foreach ($mine as $r): ?>
    <article class="ticket" id="hlaseni-<?= (int) $r['id'] ?>">
      <header class="post-meta"><span class="status status-<?= REPORT_STATUS_CLASS[$r['status']] ?>"><?= e(REPORT_STATUS[$r['status']]) ?></span><span><?= e(REPORT_TYPES[$r['type']] ?? '') ?></span><time><?= cz_datetime($r['created_at']) ?></time></header>
      <div class="prose" translate="no"><?= format_text($r['body']) ?></div>
      <?php if ($r['screenshot'] !== ''): ?><p><a href="<?= url('/hlaseni/' . $r['id'] . '/snimek') ?>" target="_blank" rel="noopener" data-zoom><img class="report-img" src="<?= url('/hlaseni/' . $r['id'] . '/snimek') ?>" alt="Přiložený snímek" loading="lazy"></a></p><?php endif ?>
      <?php $thread = $msgs[(int) $r['id']] ?? []; $imgBase = '/hlaseni/' . $r['id'] . '/snimek/'; $forStaff = false; require __DIR__ . '/_thread.php';
        $last = $thread ? end($thread)['author'] : ''; $draft = ($_SESSION['report_draft'][0] ?? 0) === (int) $r['id'] ? (string) $_SESSION['report_draft'][1] : ''; ?>
      <details class="report-followup"<?= $last === 'staff' || $draft !== '' ? ' open' : '' ?>>
        <summary><?= $last === 'staff' ? 'Odpovědět' : 'Něco doplnit' ?></summary>
        <form method="post" action="<?= url('/hlaseni/' . $r['id'] . '/doplnit') ?>" enctype="multipart/form-data" class="form report-form">
          <?= csrf_field() ?>
          <label><?= $last === 'staff' ? 'Vaše odpověď' : 'Co chcete doplnit' ?> <textarea name="body" rows="3" required minlength="2" maxlength="5000"><?= e($draft) ?></textarea></label>
          <div class="report-shot">
            <label class="report-file">Přiložit obrázek (nepovinné) <input type="file" name="screenshot" accept="image/png,image/jpeg" data-report-file></label>
            <div class="report-preview" data-report-preview hidden><span>Snímek je přiložen.</span> <button class="btn btn-small btn-ghost" type="button" data-report-clear>Odebrat snímek</button></div>
          </div>
          <div class="actions"><button class="btn" type="submit">Odeslat</button></div>
        </form>
      </details>
    </article>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>
<?php unset($_SESSION['report_draft']); ?>
