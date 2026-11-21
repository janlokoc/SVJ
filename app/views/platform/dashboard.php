<div class="wrap page">
  <header class="page-head row"><h1>Společenství</h1><a class="btn" href="<?= url('/svj/nove') ?>">+ Nové společenství</a></header>
  <p class="lead">Každé společenství má vlastní adresu, vlastní oddělená data a vlastní správce. Celkem: <?= count($tenants) ?>.</p>
  <?php if (!$tenants): ?><p class="empty">Zatím tu není žádné společenství. Založte první.</p><?php else: ?>
  <label class="table-filter">Hledat <input type="search" data-filter="#tenants tbody" data-filter-rows="tr" placeholder="Název nebo adresa…"></label>
  <table class="table" id="tenants">
    <thead><tr><th>Společenství</th><th>Adresa webu</th><th>Vzhled</th><th>Účty</th><th>Jednotky</th><th>Poslední přihlášení</th><th>Stav</th></tr></thead>
    <tbody><?php foreach ($tenants as $t): $s = $t['stats']; ?>
      <tr<?= $t['active'] ? '' : ' class="is-off"' ?>>
        <td data-label="Společenství"><a href="<?= url('/svj/' . $t['slug']) ?>"><strong><?= e($t['name']) ?></strong></a><?= $t['note'] ? '<br><small class="muted">' . e($t['note']) . '</small>' : '' ?></td>
        <td data-label="Adresa webu"><a href="<?= e(tenant_url($t)) ?>"><?= e(preg_replace('~^https?://~', '', rtrim(tenant_url($t), '/'))) ?></a></td>
        <td data-label="Vzhled"><?= e(explode(' – ', LOOKS[$t['look']] ?? '')[0]) ?></td>
        <td data-label="Účty"><?= $s['ready'] ? $s['users'] . ($s['admins'] ? '' : ' <span class="tag tag-pin">bez správce</span>') : '—' ?></td>
        <td data-label="Jednotky"><?= $s['ready'] ? $s['units'] : '—' ?></td>
        <td data-label="Poslední přihlášení"><?= $s['ready'] && $s['last_login'] ? cz_datetime($s['last_login']) : '—' ?></td>
        <td data-label="Stav"><span class="status status-<?= $t['active'] ? 'done' : 'new' ?>"><?= $t['active'] ? 'běží' : 'pozastaveno' ?></span></td>
      </tr>
    <?php endforeach ?></tbody>
  </table>
  <?php endif ?>
</div>
