<?php /* Plán oprav a fond oprav (správce). $s, $fundForm, $repairs, $proj, $chart, $contrib, $units, $errors, $repairForm. */
$planned = array_sum(array_column($proj['years'], 'cost'));
$lowest = $proj['years'] ? min(array_column($proj['years'], 'end')) : 0;
$thisYear = (int) date('Y'); ?>
<div class="wrap page">
  <header class="page-head"><h1>Plán oprav a fond oprav</h1>
    <p class="lead">Plánované opravy domu a výhled fondu oprav na <?= (int) $s['years'] ?> let: vystačí současné příspěvky, nebo bude potřeba je zvýšit?</p>
    <?= function_exists('help_link') ? help_link('plan-oprav') : '' ?></header>
  <?php require __DIR__ . '/../../_errors.php'; ?>
  <?php if ($s['balance_date'] === ''): ?>
    <div class="flash flash-info">Začněte nastavením fondu níže: současný stav fondu (z účetnictví) a měsíční příspěvky. Potom přidejte plánované opravy.</div>
  <?php elseif ($proj['first_negative'] !== null): ?>
    <div class="callout modf-alert"><p><strong>Fond by v roce <?= (int) $proj['first_negative'] ?> šel do mínusu.</strong>
      <?php if ($proj['needed'] === null): ?>Na opravy naplánované do konce letošního roku nestačí současný stav fondu – zvážte posunutí opravy, mimořádný příspěvek nebo úvěr.
      <?php else: ?>Aby fond vystačil na všechny plánované opravy, je třeba měsíční příspěvek domu alespoň <strong><?= modf_money($proj['needed']) ?></strong> – tedy o <strong><?= modf_money($proj['increase']) ?></strong> měsíčně víc<?= $units ? ', průměrně o ' . modf_money($proj['increase_unit']) . ' na jednotku' : '' ?>.<?php endif ?></p>
      <p class="hint">O výši příspěvků do fondu oprav rozhoduje shromáždění (pokud stanovy nesvěřují rozhodnutí výboru).</p></div>
  <?php else: ?>
    <div class="flash">Při současných příspěvcích fond vystačí na všechny plánované opravy do roku <?= (int) end($proj['years'])['year'] ?>.</div>
  <?php endif ?>

  <div class="dash-tiles dash-tiles-4">
    <div class="dash-tile"><span class="dash-num"><?= modf_money($s['balance']) ?></span><span class="dash-label">stav fondu</span><span class="dash-sub"><?= $s['balance_date'] !== '' ? 'k ' . cz_date($s['balance_date']) : 'zatím nezadáno' ?></span></div>
    <div class="dash-tile"><span class="dash-num"><?= modf_money($proj['monthly']) ?></span><span class="dash-label">příspěvky měsíčně</span><span class="dash-sub"><?= modf_money($proj['monthly'] * 12) ?> ročně</span></div>
    <div class="dash-tile"><span class="dash-num"><?= modf_money($planned) ?></span><span class="dash-label">plánované opravy</span><span class="dash-sub">do roku <?= (int) end($proj['years'])['year'] ?><?= $s['inflation'] > 0 ? ' vč. růstu cen ' . str_replace('.', ',', (string) $s['inflation']) . ' % ročně' : '' ?></span></div>
    <div class="dash-tile<?= $lowest < 0 ? ' is-bad' : '' ?>"><span class="dash-num"><?= modf_money($lowest) ?></span><span class="dash-label">nejnižší zůstatek</span><span class="dash-sub">na konci roku ve výhledu</span></div>
  </div>

  <section class="card" aria-labelledby="modf-chart-h">
    <h2 id="modf-chart-h">Výhled fondu</h2>
    <?php require __DIR__ . '/_chart.php'; ?>
    <?php if ($proj['beyond']): ?><p class="hint">Za horizontem výhledu: <?= e(implode(', ', array_map(fn($r) => $r['title'] . ' (' . $r['year'] . ')', $proj['beyond']))) ?>.</p><?php endif ?>
  </section>

  <section class="card" id="opravy" aria-labelledby="modf-rep-h">
    <h2 id="modf-rep-h">Plánované opravy</h2>
    <?php if (!$repairs): ?><p class="empty">Zatím žádné opravy v plánu.</p><?php else: ?>
    <table class="table"><thead><tr><th>Oprava</th><th>Rok</th><th>Odhad nákladů</th><th>Priorita</th><th>Stav</th><th></th></tr></thead><tbody>
      <?php foreach ($repairs as $r): ?><tr<?= $r['status'] === 'done' ? ' class="muted"' : '' ?>>
        <td data-label="Oprava"><a href="<?= url('/admin/plan-oprav/' . $r['id']) ?>"><strong><?= e($r['title']) ?></strong></a><?= $r['note'] !== '' ? '<br><small class="muted">' . e(mb_strimwidth($r['note'], 0, 120, '…')) . '</small>' : '' ?></td>
        <td data-label="Rok"><?= (int) $r['year'] ?><?= $r['status'] !== 'done' && (int) $r['year'] < $thisYear ? ' <span class="tag tag-late">po plánu</span>' : '' ?></td>
        <td data-label="Odhad nákladů"><?= modf_money((int) $r['cost']) ?></td>
        <td data-label="Priorita"><?= e(MODF_PRIORITY[(int) $r['priority']] ?? '') ?></td>
        <td data-label="Stav"><?= e(MODF_REPAIR_STATUS[$r['status']] ?? $r['status']) ?></td>
        <td class="t-actions"><form method="post" action="<?= url('/admin/plan-oprav/' . $r['id'] . '/smazat') ?>" data-confirm="Odebrat „<?= e($r['title']) ?>“ z plánu?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat</button></form></td>
      </tr><?php endforeach ?>
    </tbody></table>
    <?php endif ?>
    <details class="modf-add"<?= $repairForm['title'] !== '' ? ' open' : '' ?>><summary>+ Přidat opravu</summary>
      <form method="post" action="<?= url('/admin/plan-oprav') ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="repair">
        <?php $r = $repairForm; require __DIR__ . '/_repair_fields.php'; ?>
        <div class="actions"><button class="btn" type="submit">Přidat do plánu</button></div></form></details>
  </section>

  <div class="two-col">
    <form method="post" action="<?= url('/admin/plan-oprav') ?>" class="card form" id="fond"><?= csrf_field() ?><input type="hidden" name="action" value="fund">
      <h2>Nastavení fondu</h2>
      <?php $f = $fundForm; ?>
      <div class="form-row">
        <label>Stav fondu (Kč) <input type="text" name="balance" inputmode="decimal" value="<?= e($f['balance_date'] !== '' || (float) $f['balance'] ? str_replace('.', ',', (string) $f['balance']) : '') ?>" required></label>
        <label>ke dni <input type="date" name="balance_date" value="<?= e($f['balance_date']) ?>" required></label>
      </div>
      <fieldset class="check-list"><legend>Příspěvky do fondu</legend>
        <?php foreach (MODF_FUND_MODES as $k => $label): ?><label class="check"><input type="radio" name="mode" value="<?= $k ?>"<?= $f['mode'] === $k ? ' checked' : '' ?>> <?= e($label) ?></label><?php endforeach ?></fieldset>
      <label>Měsíční příspěvek (Kč) – za dům, nebo na jednu jednotku <input type="text" name="monthly" inputmode="decimal" value="<?= e((float) $f['monthly'] ? str_replace('.', ',', (string) $f['monthly']) : '') ?>"></label>
      <div class="form-row">
        <label>Výhled na let <select name="years"><?php for ($i = 10; $i <= 20; $i++): ?><option<?= (int) $f['years'] === $i ? ' selected' : '' ?>><?= $i ?></option><?php endfor ?></select></label>
        <label>Růst cen oprav (% ročně) <input type="text" name="inflation" inputmode="decimal" value="<?= e(str_replace('.', ',', (string) $f['inflation'])) ?>"></label>
      </div>
      <label class="check"><input type="checkbox" name="published" value="1"<?= $f['published'] ? ' checked' : '' ?>> <span>Zveřejnit přehled obyvatelům <small>Přihlášení obyvatelé uvidí stav fondu, plánované opravy a výhled (jen pro čtení) na stránce Plán oprav.</small></span></label>
      <p class="hint">Příspěvky za měsíc, ke kterému stav fondu platí, už jsou v něm započtené; výhled počítá s příspěvky od dalšího měsíce.</p>
      <div class="actions"><button class="btn" type="submit">Uložit</button><?php if ($s['published']): ?> <a href="<?= url('/plan-oprav') ?>">Zobrazit jako obyvatel</a><?php endif ?></div>
    </form>
    <section class="card">
      <h2>Příspěvky jednotek</h2>
      <?php if (!$contrib): ?><p class="empty">Dům zatím nemá jednotky.</p><?php else: ?>
      <p class="hint"><?= $s['mode'] === 'flat' ? 'Stejná částka za každou jednotku.' : (in_array(0.0, array_map(fn($u) => share_value((string) $u['share']), $units), true) ? 'Některé jednotce chybí podíl – zatím se dělí rovným dílem. Doplňte podíly v části Jednotky.' : 'Rozpočítáno podle spoluvlastnických podílů.') ?></p>
      <details><summary>Zobrazit <?= count($contrib) ?> jednotek</summary>
        <table class="table"><thead><tr><th>Jednotka</th><th>Měsíčně</th><?php if ($proj['increase'] > 0): ?><th>Potřeba</th><?php endif ?></tr></thead><tbody>
          <?php $needs = $proj['increase'] > 0 ? modf_unit_contributions($s, $units, (float) $proj['needed']) : []; foreach ($contrib as $i => $c): ?><tr><td data-label="Jednotka"><?= e(unit_name($c['unit'])) ?></td><td data-label="Měsíčně"><?= modf_money($c['amount'], 2) ?></td>
            <?php if ($needs): ?><td data-label="Potřeba"><?= modf_money($needs[$i]['amount'], 2) ?></td><?php endif ?></tr><?php endforeach ?>
        </tbody></table></details>
      <?php endif ?>
    </section>
  </div>
</div>
