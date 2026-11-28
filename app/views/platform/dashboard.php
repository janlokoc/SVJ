<div class="wrap page">
  <?php $todo = launch_checklist(); if ($todo): ?><div class="flash flash-info">Před spuštěním zbývá v nastavení doplnit <?= count($todo) ?> <?= count($todo) === 1 ? 'věc' : (count($todo) < 5 ? 'věci' : 'věcí') ?> (<?= e(mb_strtolower($todo[0][0])) ?><?= count($todo) > 1 ? ' a další' : '' ?>). <a href="<?= url('/nastaveni') ?>#spusteni">Zobrazit seznam</a></div><?php endif ?>
  <header class="page-head row"><h1>Společenství</h1><span class="actions"><form method="post" action="<?= url('/demo') ?>"<?= $hasDemo ? ' data-confirm="Ukázkový web smazat a založit znovu s čerstvými daty?"' : '' ?>><?= csrf_field() ?><button class="btn btn-ghost" type="submit"><?= $hasDemo ? 'Obnovit ukázkový web' : 'Založit ukázkový web' ?></button></form>
    <a class="btn" href="<?= url('/svj/nove') ?>">+ Nové společenství</a></span></header>
  <p class="lead">Každé společenství má vlastní adresu, vlastní oddělená data a vlastní správce. Celkem: <?= count(array_filter($tenants, fn($x) => empty($x['demo']))) ?>.
    <?php if ($demoCount): ?><a href="<?= url('/') ?><?= $showDemo ? '' : '?ukazky=1' ?>"><?= $showDemo ? 'Skrýt ukázkové weby' : 'Zobrazit ukázkové weby (' . $demoCount . ')' ?></a><?php endif ?></p>
  <?php if (!$tenants): ?><p class="empty">Zatím tu není žádné společenství. Založte první.</p><?php else: ?>
  <label class="table-filter">Hledat <input type="search" data-filter="#tenants tbody" data-filter-rows="tr" placeholder="Název nebo adresa…"></label>
  <table class="table" id="tenants">
    <thead><tr><th>Společenství</th><th>Adresa webu</th><th>Vzhled</th><th>Účty</th><th>Jednotky</th><th>Poslední přihlášení</th><th>Předplatné</th><th>Stav</th></tr></thead>
    <tbody><?php foreach ($tenants as $t): $s = $t['stats']; ?>
      <tr<?= $t['active'] ? '' : ' class="is-off"' ?>>
        <td data-label="Společenství"><a href="<?= url('/svj/' . $t['slug']) ?>"><strong><?= e($t['name']) ?></strong></a><?= $t['demo'] ? ' <span class="tag tag-lock">ukázka</span>' : '' ?><?= ($t['domain_status'] ?? '') === 'requested' ? ' <span class="tag tag-pin">žádá o doménu</span>' : '' ?><?= $t['note'] ? '<br><small class="muted">' . e($t['note']) . '</small>' : '' ?></td>
        <td data-label="Adresa webu"><a href="<?= e(tenant_url($t)) ?>"><?= e(preg_replace('~^https?://~', '', rtrim(tenant_url($t), '/'))) ?></a></td>
        <td data-label="Vzhled"><?= e(explode(' – ', STYLES[$t['style'] ?? 'klasik'] ?? '')[0]) ?> · <?= e(mb_strtolower(explode(' – ', LOOKS[$t['look']] ?? '')[0])) ?></td>
        <td data-label="Účty"><?= $s['ready'] && $t['ai_used'] ? '<small class="muted">AI ' . $t['ai_used'] . '× tento měsíc' . '</small><br>' : '' ?><?= $s['ready'] ? '<a href="' . url('/svj/' . $t['slug']) . '#uzivatele" title="Uživatelé webu a poslední přihlášení">' . $s['users'] . '</a>' . ($s['admins'] ? '' : ' <span class="tag tag-pin">bez správce</span>') : '—' ?></td>
        <td data-label="Jednotky"><?= $s['ready'] ? $s['units'] : '—' ?></td>
        <td data-label="Poslední přihlášení"><?= $s['ready'] && $s['last_login'] ? cz_datetime($s['last_login']) : '—' ?></td>
        <td data-label="Předplatné"><?php $b = $t['billing_state']; echo match ($b['mode']) { 'free' => '<span class="muted">bez placení a omezení</span>', 'basic' => 'Zdarma', 'trial' => 'Standard na zkoušku do ' . cz_date($b['until']), 'paid' => '<strong>' . EDITIONS[$b['edition']] . '</strong> do ' . cz_date($b['until']),
          'grace' => '<span class="tag tag-pin">' . EDITIONS[$b['edition']] . ' po splatnosti ' . -$b['days'] . ' d</span>', default => '' }; ?><?= !empty($t['trials']) ? '<br><small class="muted">zkouší ' . $t['trials'] . ' ' . ($t['trials'] === 1 ? 'funkci' : ($t['trials'] < 5 ? 'funkce' : 'funkcí')) . '</small>' : '' ?>
          <?php if (($t['delete_at'] ?? '') !== ''): ?><br><span class="tag tag-pin">správce žádá smazání <?= cz_date($t['delete_at']) ?></span><?php endif ?>
          <?php if ($t['delete_on'] && $t['delete_on'] <= date('Y-m-d', strtotime('+30 day'))): ?><br><small class="muted">smazání <?= cz_date($t['delete_on']) ?></small><?php endif ?>
          <?php if ($t['last_notice']): ?><br><small class="muted">upozornění <?= cz_date($t['last_notice']['at']) ?>: <?= e(NOTICE_KINDS[$t['last_notice']['kind']] ?? '') ?><?= $t['last_notice']['sent'] ? '' : ' (neodesláno)' ?></small><?php endif ?></td>
        <td data-label="Stav"><span class="status status-<?= $t['active'] ? 'done' : 'new' ?>"><?= $t['active'] ? 'běží' : 'pozastaveno' ?></span></td>
      </tr>
    <?php endforeach ?></tbody>
  </table>
  <?php endif ?>
</div>
