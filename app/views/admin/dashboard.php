<div class="wrap page">
  <header class="page-head"><h1>Administrace</h1><p class="lead">Správa obsahu webu, uživatelů a hlášených závad a požadavků.</p></header>
  <div class="flash flash-error" id="storage-probe" data-probe="<?= e(json_encode([url('/storage/.gitkeep'), url('/app/schema.sql'), url('/tools/import-webnode.php')])) ?>" hidden>Pozor: server zpřístupňuje vnitřní soubory webu (složky app, storage nebo tools jsou čitelné zvenku). Soubor .htaccess na tomto hostingu nefunguje – obraťte se na poskytovatele hostingu, než web začnete používat.</div>
  <?php if (plan_managed() && ($pt = plan_tenant()) && (int) $pt['partner_id'] && $pt['partner_status'] === 'pending' && ($pp = partner_row((int) $pt['partner_id']))): ?><div class="flash flash-info">Správcovská firma <strong><?= e($pp['name']) ?></strong> žádá o přístup k administraci webu. <a href="<?= url('/admin/spravce-domu') ?>">Schválit nebo odmítnout</a></div><?php endif ?>
  <?php if (!empty($_SESSION['partner'])): ?><?php elseif (user()['totp_enabled'] == 0): ?><div class="flash flash-info">Váš účet správce chrání jen heslo. <a href="<?= url('/muj-ucet#overeni') ?>">Zapněte si dvoufázové ověření</a> – trvá to dvě minuty.</div>
  <?php elseif ($adminsWithout2fa): ?><div class="flash flash-info">Správců bez dvoufázového ověření: <?= $adminsWithout2fa ?>. Požádejte je, ať si ho zapnou ve svém účtu.</div><?php endif ?>
  <?php if ($failed24 >= 20): ?><div class="flash flash-error">Za posledních 24 hodin bylo <?= $failed24 ?> neúspěšných pokusů o přihlášení. Podívejte se do <a href="<?= url('/admin/zaznamy?jen-problemy') ?>">Záznamů</a>.</div><?php endif ?>
  <?php if ($maintenanceDue): ?><div class="flash flash-<?= maintenance_state($maintenanceDue[0]['next_date']) === 'overdue' ? 'error' : 'info' ?>">Blíží se termín revize: <?= e(implode('; ', array_map(fn($m) => $m['title'] . ' (' . cz_date($m['next_date']) . (maintenance_state($m['next_date']) === 'overdue' ? ', po termínu' : '') . ')', $maintenanceDue))) ?>. <a href="<?= url('/admin/revize') ?>">Otevřít revize</a></div><?php endif ?>
  <?php if (plan_managed() && ($bs = $GLOBALS['BILLING'] ?? null) && $bs['mode'] !== 'free'): $activeTrials = array_filter(plan_trials(), fn($tr) => $tr['ends'] >= date('Y-m-d') && plan_state($tr['feature']) === 'trial'); ?>
  <p class="plan-line">Edice <strong><?= e(EDITIONS[$bs['edition']]) ?></strong><?= match ($bs['mode']) { 'paid' => ' · zaplaceno do ' . cz_date($bs['until']), 'trial' => ' · na zkoušku do ' . cz_date($bs['until']), 'grace' => ' · předplatné skončilo ' . cz_date($bs['until']), default => ' · bez časového omezení' } ?><?php foreach ($activeTrials as $tr): ?> · zkouška „<?= e(PLAN_FEATURES[$tr['feature']][1]) ?>“ do <?= cz_date($tr['ends']) ?><?php endforeach ?>
    · <a href="<?= url('/admin/predplatne') ?>">Předplatné a limity</a></p>
  <?php endif ?>
  <?php if ($jp = join_pending_count()): ?><div class="flash flash-info">Žádosti o přiřazení k jednotce čekají na schválení: <?= $jp ?>. <a href="<?= url('/admin/uzivatele/zadosti') ?>">Ověřit a schválit</a></div><?php endif ?>
  <?php $onb = onboarding_summary(); if ($onb['waiting'] && !$onb['hidden']): ?>
  <div class="card onb-card">
    <div class="onb-card-head"><h2>Průvodce nastavením</h2><span class="onb-count"><?= $onb['done'] ?> z <?= $onb['total'] ?> hotovo</span></div>
    <progress class="bar" max="<?= $onb['total'] ?>" value="<?= $onb['done'] ?>"><?= $onb['done'] ?> z <?= $onb['total'] ?></progress>
    <ul class="onb-next">
      <?php foreach (array_slice($onb['waiting'], 0, 3) as $s): ?><li><span class="light light-<?= $s['important'] ? 'must' : 'wait' ?>" aria-hidden="true"></span> <a href="<?= url($s['href']) ?>"><?= e($s['title']) ?></a> <small class="muted"><?= $s['important'] ? 'čeká – důležité' : 'čeká' ?></small></li><?php endforeach ?>
    </ul>
    <p><a class="btn btn-small" href="<?= url('/admin/pruvodce') ?>">Zobrazit všechny kroky</a><?= count($onb['waiting']) > 3 ? ' <small class="muted">a dalších ' . (count($onb['waiting']) - 3) . '</small>' : '' ?></p>
  </div>
  <?php endif ?>
  <?php if (!$onb['waiting'] || $onb['hidden']): $tips = array_slice(feature_tips(), 0, 3); if ($tips): ?>
  <section class="card tips-card" aria-labelledby="tips-h">
    <div class="onb-card-head"><h2 id="tips-h">Vyzkoušejte</h2>
      <form method="post" action="<?= url('/admin/tipy') ?>"><?= csrf_field() ?><input type="hidden" name="key" value="*"><button class="link" type="submit">Skrýt vše</button></form></div>
    <ul class="tips">
      <?php foreach ($tips as $tip): ?><li>
        <div><strong><?= e($tip['title']) ?></strong><?= !empty($tip['beta']) ? ' <span class="tag tag-beta">Beta</span>' : ($tip['new'] ? ' <span class="tag tag-pin">Novinka</span>' : '') ?><p class="muted"><?= e($tip['why']) ?></p></div>
        <div class="tips-actions"><a class="btn btn-small" href="<?= url($tip['href']) ?>">Vyzkoušet</a>
          <form method="post" action="<?= url('/admin/tipy') ?>"><?= csrf_field() ?><input type="hidden" name="key" value="<?= e($tip['key']) ?>"><button class="link" type="submit">Nezajímá nás</button></form></div>
      </li><?php endforeach ?>
    </ul>
  </section>
  <?php endif; endif ?>
  <div class="stat-grid">
    <a class="stat" href="<?= url('/admin/aktuality') ?>"><span class="stat-num"><?= $stats['posts'] ?></span><span>aktualit</span></a>
    <a class="stat" href="<?= url('/admin/dokumenty') ?>"><span class="stat-num"><?= $stats['documents'] ?></span><span>dokumentů</span></a>
    <a class="stat" href="<?= url('/admin/uzivatele') ?>"><span class="stat-num"><?= $stats['users'] ?></span><span>aktivních účtů</span></a>
    <a class="stat<?= $stats['tickets'] ? ' stat-alert' : '' ?>" href="<?= url('/admin/zavady') ?>"><span class="stat-num"><?= $stats['tickets'] ?></span><span>otevřených závad a požadavků</span></a>
  </div>
  <div class="actions">
    <a class="btn" href="<?= url('/admin/aktuality/nova') ?>">+ Nová aktualita</a>
    <a class="btn btn-ghost" href="<?= url('/admin/zpravy/nova') ?>">+ Zpráva e-mailem</a>
    <a class="btn btn-ghost" href="<?= url('/admin/dokumenty/novy') ?>">+ Nahrát dokument</a>
    <a class="btn btn-ghost" href="<?= url('/admin/uzivatele/novy') ?>">+ Přidat obyvatele</a>
    <a class="btn btn-ghost" href="<?= url('/admin/zaloha') ?>">Stáhnout zálohu</a>
  </div>
  <?php if ($external): ?>
  <div class="card card-soft">
    <h2>Převod souborů ze starého webu</h2>
    <p><?= $external ?> dokumentů zatím odkazuje na soubory uložené na původním webu (Webnode). Než starý web zrušíte, stáhněte je sem – jinak odkazy přestanou fungovat.</p>
    <form method="post" action="<?= url('/admin/import') ?>"><?= csrf_field() ?><button class="btn" type="submit">Stáhnout dalších 15 souborů</button></form>
  </div>
  <?php endif ?>
  <h2>Otevřené závady a požadavky</h2>
  <?php if (!$tickets): ?><p class="empty">Žádná otevřená závada ani požadavek.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Závada / požadavek</th><th>Kdo</th><th>Kdy</th><th>Stav</th></tr></thead>
    <tbody>
    <?php foreach ($tickets as $t): ?>
      <tr><td data-label="Závada / požadavek"><a href="<?= url('/admin/zavady') ?>#z<?= $t['id'] ?>"><?= e($t['subject']) ?></a></td><td data-label="Kdo"><?= e(author_label($t)) ?></td><td data-label="Kdy"><?= cz_date($t['created_at']) ?></td>
        <td data-label="Stav"><span class="status status-<?= e($t['status']) ?>"><?= TICKET_STATUS[$t['status']] ?></span></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
  <?php if (plan_managed()): ?>
  <section class="dash-planned">
    <h2>Na čem pracujeme</h2>
    <p class="muted">Funkce, které pro weby společenství připravujeme. Klikněte na tu, která by se Vám hodila – podle zájmu rozhodujeme, co udělat dřív.</p>
    <ul class="chips planned-chips"><?php foreach (PLANNED_FEATURES as $pk => $pf): ?><li><a href="<?= url('/admin/predplatne/funkce/' . $pk) ?>" title="<?= e($pf[2]) ?>"><?= e($pf[3]) ?> <span class="plan-tag">již brzy</span></a></li><?php endforeach ?></ul>
  </section>
  <?php endif ?>
</div>
