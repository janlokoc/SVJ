<?php $res = $results; $unitsById = array_column($res['units'], null, 'id'); $itemNo = array_flip(array_keys($items)); ?>
<div class="wrap page print-doc rollam-doc">
  <p class="no-print"><a class="btn btn-small btn-ghost" href="<?= url('/admin/hlasovani/' . $poll['id'] . '/vysledky') ?>">← Zpět</a> <button class="btn btn-small" type="button" data-print>Vytisknout / uložit jako PDF</button></p>
  <h1>Protokol o hlasování</h1>
  <p><strong><?= e(site('name')) ?></strong> · rozhodování <?= $poll['event_id'] ? 'k shromáždění' : 'mimo zasedání (per rollam, § 1211–1214 občanského zákoníku)' ?></p>
  <table class="table print-table rollam-meta">
    <tr><th>Hlasování</th><td><?= e($poll['title']) ?></td></tr>
    <tr><th>Lhůta pro vyjádření</th><td><?= cz_date($poll['opens_at']) ?> – <?= cz_date($poll['closes_at']) ?> (<?= e(POLL_STATES[$state]) ?>)</td></tr>
    <tr><th>Ověření hlasu přes web</th><td><?= e(ROLLAM_VERIFY[$method]) ?><?= $poll['online'] ? '' : ' (hlasování přes web vypnuto)' ?></td></tr>
    <tr><th>Váha hlasu</th><td><?= $res['byShare'] ? 'spoluvlastnický podíl jednotky' : '1 hlas za jednotku (podíly nejsou vyplněné u všech jednotek)' ?></td></tr>
    <tr><th>Potřebná většina</th><td>více než <?= $need ?> % hlasů všech vlastníků (§ 1214)</td></tr>
    <tr><th>Vyhotoveno</th><td><?= cz_datetime($generated) ?> · <?= e(user()['name'] ?? '') ?></td></tr>
  </table>
  <?php foreach ($warnings as $w): ?><p class="hint"><strong>Upozornění:</strong> <?= e($w) ?></p><?php endforeach ?>

  <h2>Návrh rozhodnutí</h2>
  <?php if (trim((string) $poll['body']) !== ''): ?><div class="prose"><?= format_text((string) $poll['body']) ?></div><?php endif ?>
  <ol class="poll-items"><?php foreach ($res['items'] as $it): ?><li><?= e($it['question']) ?></li><?php endforeach ?></ol>

  <h2>Výsledek</h2>
  <p>Hlasovalo jednotek: <strong><?= $res['voted'] ?></strong> z <?= count($res['units']) ?> (<?= percent_cz($res['turnout']) ?> všech hlasů).</p>
  <table class="table print-table">
    <thead><tr><th>Bod</th><th>Pro</th><th>Proti</th><th>Zdržel(a) se</th><th>Výsledek</th></tr></thead>
    <tbody><?php foreach ($res['items'] as $n => $it): ?>
      <tr><td><?= $n + 1 ?>. <?= e(mb_strimwidth($it['question'], 0, 120, '…')) ?></td>
        <td><?= percent_cz($it['percent']['yes']) ?> (<?= $it['count']['yes'] ?>)</td><td><?= percent_cz($it['percent']['no']) ?> (<?= $it['count']['no'] ?>)</td>
        <td><?= percent_cz($it['percent']['abstain']) ?> (<?= $it['count']['abstain'] ?>)</td>
        <td><strong><?= $it['adopted'] ? 'PŘIJATO' : 'NEPŘIJATO' ?></strong></td></tr>
    <?php endforeach ?></tbody>
  </table>
  <?php if ($state !== 'closed'): ?><p class="hint"><strong>Hlasování ještě neskončilo – výsledek je průběžný.</strong></p><?php endif ?>

  <h2>Odevzdané hlasy</h2>
  <p class="hint">Každý zápis hlasu (i oprava nebo nahrazení) má vlastní řádek. Platí poslední zápis za jednotku. Body: P = pro, N = proti, Z = zdržel(a) se.</p>
  <table class="table print-table rollam-records">
    <thead><tr><th>#</th><th>Čas zápisu</th><th>Jednotka / podíl</th><th>Hlasující</th><th>Hlas</th><th>Způsob a ověření</th><th>Adresa / zařízení</th><th>Otisk</th></tr></thead>
    <tbody><?php foreach ($records as $n => $r): ?>
      <tr><td><?= $n + 1 ?></td><td><?= e($r['at']) ?></td>
        <td><?= $r['unit'] ? e(unit_name($r['unit'])) . ' · ' . e($r['unit']['share']) : 'jednotka #' . (int) $r['unit_id'] ?></td>
        <td><?= e($r['voter_name']) ?></td>
        <td><?php $parts = []; foreach ($r['choice_list'] as $itemId => $c) { $parts[] = (($itemNo[$itemId] ?? -1) + 1) . ':' . ['yes' => 'P', 'no' => 'N', 'abstain' => 'Z'][$c]; } echo e($parts ? implode(' ', $parts) : 'bez hlasu (smazáno)'); ?></td>
        <td><?= e(ROLLAM_SOURCES[$r['method']] ?? $r['method']) ?><?= $r['verified'] !== '' ? '<br><small>' . e($r['verified']) . '</small>' : '' ?>
          <?php if ($r['method'] === 'listina'): ?><br><small>podepsáno <?= cz_date($r['signed_on']) ?>, doručeno <?= cz_date($r['received_on']) ?><?= $r['scan_file'] !== '' ? ', sken: <a href="' . url('/admin/hlasovani/listina/' . $r['id'] . '/sken') . '">' . e($r['scan_name']) . '</a>' : ', bez skenu' ?></small><?php endif ?>
          <?= $r['entered_name'] !== '' ? '<br><small>zapsal(a) ' . e($r['entered_name']) . '</small>' : '' ?><?= $r['note'] !== '' ? '<br><small>' . e($r['note']) . '</small>' : '' ?></td>
        <td><small><?= e($r['ip']) ?><br><?= e(mb_strimwidth($r['ua'], 0, 60, '…')) ?></small></td>
        <td><code><?= e(substr($r['hash'], 0, 12)) ?></code></td></tr>
    <?php endforeach ?>
    <?php if (!$records): ?><tr><td colspan="8">Zatím žádný zapsaný hlas.</td></tr><?php endif ?></tbody>
  </table>

  <h2>Kontrola neporušenosti</h2>
  <?php if ($check['ok']): ?>
    <p><strong>V pořádku.</strong> Všech <?= $check['count'] ?> záznamů na sebe navazuje (každý nese otisk SHA-256 předchozího) a odevzdané hlasy odpovídají poslednímu záznamu u každé jednotky.</p>
  <?php else: ?>
    <?php if ($check['bad']): ?><p><strong>Řetěz otisků je porušen</strong> od záznamu č. <?= (int) $check['bad'] ?> – záznam byl po zápisu změněn mimo web.</p><?php endif ?>
    <?php if ($check['mismatch']): ?><p><strong>Hlasy těchto jednotek neodpovídají protokolu:</strong> <?= e(implode(', ', array_map(fn($id) => isset($unitsById[$id]) ? unit_name($unitsById[$id]) : '#' . $id, $check['mismatch']))) ?>.
      Mohly být zapsány před zavedením protokolu, nebo změněny mimo web.</p><?php endif ?>
  <?php endif ?>
  <p><small>Otisk posledního záznamu: <code><?= e($check['head'] ?: '—') ?></code><br>Otisk odevzdaných hlasů: <code><?= e($fingerprint) ?></code></small></p>

  <p class="rollam-sign">Za statutární orgán: ........................................ &nbsp; Datum: ....................</p>
  <p class="hint">Výsledek hlasování a u přijatého rozhodnutí jeho plné znění oznamuje statutární orgán vlastníkům písemně bez zbytečného odkladu (§ 1213).</p>
</div>
