<?php $with = array_filter($rows, fn($r) => $r['as_of'] !== null); $debtors = array_filter($with, fn($r) => (float) $r['balance'] < 0); ?>
<div class="wrap page">
  <header class="page-head"><h1>Platby na jednotku</h1>
    <p class="lead">Předpis záloh a stav účtu každé jednotky, jak je vede Vaše účetní. Vlastník vidí jen své jednotky, s QR kódem pro platbu. Web sám nic neúčtuje – údaje sem nahrajete z účetnictví.</p></header>
  <?php if (!$iban): ?><div class="flash flash-info">V <a href="<?= url('/admin/nastaveni') ?>">Nastavení</a> není vyplněné platné číslo účtu společenství, proto se vlastníkům nezobrazí QR platba.</div><?php endif ?>
  <div class="stat-grid">
    <div class="stat"><span class="stat-num"><?= count($with) ?></span> jednotek s údaji</div>
    <div class="stat"><span class="stat-num<?= $debtors ? ' is-debt' : '' ?>"><?= count($debtors) ?></span> s nedoplatkem</div>
    <div class="stat"><span class="stat-num"><?= account_money(-array_sum(array_map(fn($r) => (float) $r['balance'], $debtors))) ?></span> dlužná částka celkem</div>
  </div>
  <form method="post" class="card form">
    <?= csrf_field() ?><input type="hidden" name="action" value="import">
    <h2>Nahrát z účetnictví</h2>
    <label>Řádky ve tvaru: jednotka ; měsíční záloha ; stav účtu ; variabilní symbol ; poznámka
      <textarea name="text" rows="6" required placeholder="<?= e(($rows ? unit_name($rows[0]) : '48A/1') . ' ; 4 250 ; -1 200 ; 4801') ?>&#10;… záporný stav = nedoplatek, kladný = přeplatek"></textarea></label>
    <p class="hint">Zkopírujte sloupce z Excelu nebo exportu účetního programu (oddělené středníkem nebo tabulátorem). Jednotka se pozná podle označení (<?= $rows ? e(unit_name($rows[0])) : '48A/1' ?>). Nahrané jednotky se přepíší, ostatní zůstanou.</p>
    <label>Stav platí ke dni <input type="date" name="as_of" value="<?= date('Y-m-d') ?>" required></label>
    <div class="actions"><button class="btn" type="submit">Nahrát</button></div>
  </form>
  <?php if ($with): ?>
  <div class="actions">
    <?php if ($debtors): ?><form method="post" data-confirm="Poslat e-mail s upomínkou vlastníkům <?= count($debtors) ?> jednotek s nedoplatkem?"><?= csrf_field() ?><input type="hidden" name="action" value="remind"><button class="btn" type="submit">Poslat upomínku dlužníkům (<?= count($debtors) ?>)</button></form><?php endif ?>
    <form method="post" data-confirm="Smazat celý přehled plateb? Vlastníci ho přestanou vidět."><?= csrf_field() ?><input type="hidden" name="action" value="clear"><button class="btn btn-ghost" type="submit">Smazat přehled</button></form>
  </div>
  <?php endif ?>
  <table class="table">
    <thead><tr><th>Jednotka</th><th>Vlastník dle evidence</th><th>Měsíční záloha</th><th>Stav účtu</th><th>Var. symbol</th><th>K datu</th><th>Upomínka</th></tr></thead>
    <tbody><?php foreach ($rows as $r): $bal = (float) $r['balance']; ?>
      <tr<?= $r['as_of'] === null ? ' class="is-off"' : '' ?>>
        <td data-label="Jednotka"><strong><?= e(unit_name($r)) ?></strong><?= $r['accounts'] ? '' : ' <span class="tag">bez účtu na webu</span>' ?></td>
        <td data-label="Vlastník dle evidence"><?= e($r['owner_note']) ?></td>
        <td data-label="Měsíční záloha"><?= $r['as_of'] === null ? '—' : account_money((float) $r['prescribed']) ?></td>
        <td data-label="Stav účtu"><?= $r['as_of'] === null ? '—' : '<strong class="' . ($bal < 0 ? 'is-debt' : 'is-ok') . '">' . ($bal < 0 ? 'nedoplatek ' . account_money(-$bal) : ($bal > 0 ? 'přeplatek ' . account_money($bal) : 'vyrovnáno')) . '</strong>' ?></td>
        <td data-label="Var. symbol"><?= e((string) $r['vs']) ?></td>
        <td data-label="K datu"><?= $r['as_of'] ? cz_date($r['as_of']) : '' ?></td>
        <td data-label="Upomínka"><?= $r['reminded_at'] ? cz_date($r['reminded_at']) : '' ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table>
</div>
