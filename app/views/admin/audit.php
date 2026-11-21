<div class="wrap page">
  <header class="page-head"><h1>Záznamy</h1>
    <p class="lead">Přihlášení, neúspěšné pokusy a důležité změny za poslední rok. Za posledních 24 hodin: <strong><?= $failed24 ?></strong> neúspěšných pokusů o přihlášení.</p></header>
  <nav class="chips" aria-label="Filtr">
    <a href="<?= url('/admin/zaznamy') ?>"<?= $only ? '' : ' aria-current="true"' ?>>Vše</a>
    <a href="<?= url('/admin/zaznamy?jen-problemy') ?>"<?= $only ? ' aria-current="true"' : '' ?>>Jen podezřelé a citlivé</a>
  </nav>
  <?php if (!$rows): ?><p class="empty">Žádné záznamy.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Kdy</th><th>Událost</th><th>Kdo</th><th>Podrobnost</th><th>IP adresa</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $bad = str_contains($r['action'], 'failed'); ?>
      <tr>
        <td data-label="Kdy"><?= cz_datetime($r['at']) ?></td>
        <td data-label="Událost"><?= $bad ? '<span class="status status-new">' : '' ?><?= e(AUDIT_LABELS[$r['action']] ?? $r['action']) ?><?= $bad ? '</span>' : '' ?></td>
        <td data-label="Kdo"><?= e($r['user_name'] ?? '') ?></td>
        <td data-label="Podrobnost"><?= e($r['detail']) ?></td>
        <td data-label="IP adresa"><?= e($r['ip']) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>
