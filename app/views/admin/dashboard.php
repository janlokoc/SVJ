<div class="wrap page">
  <header class="page-head"><h1>Administrace</h1><p class="lead">Správa obsahu webu, uživatelů a hlášených závad.</p></header>
  <div class="stat-grid">
    <a class="stat" href="<?= url('/admin/aktuality') ?>"><span class="stat-num"><?= $stats['posts'] ?></span><span>aktualit</span></a>
    <a class="stat" href="<?= url('/admin/dokumenty') ?>"><span class="stat-num"><?= $stats['documents'] ?></span><span>dokumentů</span></a>
    <a class="stat" href="<?= url('/admin/uzivatele') ?>"><span class="stat-num"><?= $stats['users'] ?></span><span>aktivních účtů</span></a>
    <a class="stat<?= $stats['tickets'] ? ' stat-alert' : '' ?>" href="<?= url('/admin/zavady') ?>"><span class="stat-num"><?= $stats['tickets'] ?></span><span>otevřených závad</span></a>
  </div>
  <div class="actions">
    <a class="btn" href="<?= url('/admin/aktuality/nova') ?>">+ Nová aktualita</a>
    <a class="btn btn-ghost" href="<?= url('/admin/zpravy/nova') ?>">+ Zpráva e-mailem</a>
    <a class="btn btn-ghost" href="<?= url('/admin/dokumenty/novy') ?>">+ Nahrát dokument</a>
    <a class="btn btn-ghost" href="<?= url('/admin/uzivatele/novy') ?>">+ Přidat obyvatele</a>
  </div>
  <?php if ($external): ?>
  <div class="card card-soft">
    <h2>Převod souborů ze starého webu</h2>
    <p><?= $external ?> dokumentů zatím odkazuje na soubory uložené na původním webu (Webnode). Než starý web zrušíte, stáhněte je sem – jinak odkazy přestanou fungovat.</p>
    <form method="post" action="<?= url('/admin/import') ?>"><?= csrf_field() ?><button class="btn" type="submit">Stáhnout dalších 15 souborů</button></form>
  </div>
  <?php endif ?>
  <h2>Otevřené závady</h2>
  <?php if (!$tickets): ?><p class="empty">Žádná otevřená závada.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Závada</th><th>Kdo</th><th>Kdy</th><th>Stav</th></tr></thead>
    <tbody>
    <?php foreach ($tickets as $t): ?>
      <tr><td data-label="Závada"><a href="<?= url('/admin/zavady') ?>#z<?= $t['id'] ?>"><?= e($t['subject']) ?></a></td><td data-label="Kdo"><?= e(author_label($t)) ?></td><td data-label="Kdy"><?= cz_date($t['created_at']) ?></td>
        <td data-label="Stav"><span class="status status-<?= e($t['status']) ?>"><?= TICKET_STATUS[$t['status']] ?></span></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>
