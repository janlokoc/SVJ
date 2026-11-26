<div class="wrap page">
  <header class="page-head"><h1>Lustrace exekucí</h1>
    <p class="lead">Ověření vlastníka v Centrální evidenci exekucí – třeba když dluží zálohy a výbor zvažuje vymáhání. Každý dotaz je placený, proto se spouští jen na Vaše kliknutí.</p>
    <p><a href="<?= url('/admin/rejstriky') ?>">← Katastr a rejstříky</a> <?= help_link('katastr-a-rejstriky') ?></p></header>
  <?php $betaKey = 'exekuce'; require __DIR__ . '/../../admin/_beta.php'; ?>
  <?php if (!$premium): ?>
  <div class="card"><p>Lustrace exekucí je součástí edice Premium. <a href="<?= url('/admin/predplatne') ?>#edice">Předplatné</a></p></div>
  <?php else: ?>
  <?php if (!$configured): ?><div class="callout"><p>Propojení s evidencí exekucí zatím provozovatel nenastavil.</p></div><?php endif ?>
  <p class="hint">Lustrovat jde vlastníky-firmy s IČO a fyzické osoby, u kterých jste na stránce <a href="<?= url('/admin/insolvence') ?>#osoby">Insolvence vlastníků</a> zadali datum narození. Cena <strong><?= e(money_czk($price)) ?> za dotaz</strong> bez DPH, účtuje se s předplatným. Výsledek se uloží s datem a vidí ho jen správci.</p>
  <?php if (!$subjects): ?>
  <div class="card"><p>Zatím není koho lustrovat. Doplňte u vlastníka IČO, nebo jméno a datum narození na stránce <a href="<?= url('/admin/insolvence') ?>#osoby">Insolvence vlastníků</a>.</p></div>
  <?php else: ?>
  <div class="card">
    <table class="table">
      <thead><tr><th>Jednotka</th><th>Vlastník</th><th>Poslední lustrace</th><th></th></tr></thead>
      <tbody><?php foreach ($subjects as $key => $s): $l = $latest[$key] ?? null; ?>
        <tr<?= $l && (int) $l['found'] ? ' class="is-unmatched"' : '' ?>>
          <td data-label="Jednotka"><strong><?= e($s['unit']) ?></strong></td>
          <td data-label="Vlastník"><?= e($s['label']) ?><br><small class="muted"><?= $s['kind'] === 'P' ? 'IČO ' . e($s['id']) : 'nar. ' . e(cz_date($s['id'])) ?></small></td>
          <td data-label="Výsledek"><?php if (!$l): ?><span class="muted">zatím ne</span>
            <?php elseif ($l['error'] !== ''): ?><span class="muted">nepovedlo se: <?= e($l['error']) ?></span>
            <?php elseif (!(int) $l['found']): ?><span class="status status-done">bez exekucí</span>
            <?php else: foreach ((array) json_decode((string) $l['result'], true) as $c): ?><strong><?= e($c['ex_cislo']) ?></strong><?= $c['uprava'] !== '' ? ' · ' . e($c['uprava']) : '' ?><?= $c['urad'] !== '' ? ' · ' . e($c['urad']) : '' ?><br><?php endforeach; endif ?>
            <?= $l ? '<small class="muted">' . e(cz_datetime($l['at'])) . ' · ' . e($l['user_name']) . '</small>' : '' ?></td>
          <td data-label="">
            <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="subject" value="<?= e($key) ?>">
              <label class="check"><input type="checkbox" name="confirm" value="1" required> <span>Za <?= e(money_czk($price)) ?></span></label>
              <button class="btn btn-small" type="submit"<?= $configured ? '' : ' disabled' ?>>Lustrovat</button></form></td>
        </tr>
      <?php endforeach ?></tbody>
    </table>
  </div>
  <?php endif ?>
  <p class="hint">Výpis z evidence exekucí říká, u kterých exekutorů jsou vedené exekuce. Pro vymáhání dlužných záloh se obraťte na právníka; výsledek lustrace nesdělujte dalším lidem v domě.</p>
  <?php endif ?>
</div>
