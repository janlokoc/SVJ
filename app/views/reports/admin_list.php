<?php $tabs = ['open' => 'K vyřízení', 'done' => 'Vyřešeno', 'rejected' => 'Nebudeme řešit', 'auto' => 'Chyby webu', 'all' => 'Vše']; $openN = ($counts['new'] ?? 0) + ($counts['in_progress'] ?? 0) + ($counts['planned'] ?? 0); ?>
<div class="wrap page">
  <header class="page-head"><h1>Hlášení</h1><p class="lead">Problémy a nápady, které poslali uživatelé tlačítkem Nahlásit, a chyby, které web zachytil sám. Nových: <?= (int) ($counts['new'] ?? 0) ?>, k vyřízení celkem: <?= $openN ?>.<?php if (reports_central()): ?> <a href="<?= url('/pristup-ai') ?>">Přístup pro AI (MCP)<?= pset('mcp_token_hash') !== '' ? ' – zapnuto' : '' ?></a><?php endif ?></p></header>
  <p class="chips"><?php foreach ($tabs as $k => $label): ?><a class="chip<?= $filter === $k ? ' is-active' : '' ?>" href="<?= url($base) ?>?stav=<?= $k ?>"<?= $filter === $k ? ' aria-current="true"' : '' ?>><?= e($label) ?></a> <?php endforeach ?></p>
  <?php if (!$rows): ?><p class="empty">V tomto výběru nejsou žádná hlášení.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Č.</th><th>Druh</th><th>Hlášení</th><th>Kdo</th><th>Stránka</th><th>Přijato</th><th>Stav</th></tr></thead>
    <tbody><?php foreach ($rows as $r): $path = (string) parse_url($r['url'], PHP_URL_PATH); ?>
      <tr>
        <td data-label="Č."><a href="<?= url($base . '/' . $r['id']) ?>"><?= (int) $r['id'] ?></a></td>
        <td data-label="Druh"><?= $r['type'] === 'auto' ? '<span class="tag tag-pin">chyba webu' . ((int) $r['hits'] > 1 ? ' ×' . (int) $r['hits'] : '') . '</span>' : e($r['type'] === 'idea' ? 'nápad' : 'problém') ?><?= $r['screenshot'] !== '' ? ' <span class="tag tag-lock">snímek</span>' : '' ?></td>
        <td data-label="Hlášení"><a href="<?= url($base . '/' . $r['id']) ?>"><?= e(mb_strimwidth(preg_replace('/\s+/u', ' ', $r['body']), 0, 110, '…')) ?></a></td>
        <td data-label="Kdo"><?= e($r['user_name'] !== '' ? $r['user_name'] : ($r['user_email'] !== '' ? $r['user_email'] : '—')) ?><?= $r['tenant'] !== '' ? '<br><small class="muted">' . e($r['tenant']) . '</small>' : '' ?></td>
        <td data-label="Stránka"><small><?= e(mb_strimwidth($path !== '' ? $path : '—', 0, 40, '…')) ?></small></td>
        <td data-label="Přijato"><?= cz_datetime($r['created_at']) ?></td>
        <td data-label="Stav"><span class="status status-<?= REPORT_STATUS_CLASS[$r['status']] ?>"><?= e(REPORT_STATUS[$r['status']]) ?></span><?= (string) $r['reply'] !== '' ? '<br><small class="muted">odpovězeno</small>' : '' ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table>
  <?php endif ?>
</div>
