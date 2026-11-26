<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/admin/hlasovani/' . $poll['id'] . '/vysledky') ?>">← <?= e($poll['title']) ?></a></p>
  <h1>Zapsat listinný hlas</h1>
  <p class="lead">Přepište hlas z vráceného podepsaného lístku. Lhůta pro doručení: <?= cz_date($poll['closes_at']) ?>.
    <?= function_exists('help_link') ? help_link('hlasovani-per-rollam') : '' ?></p>
  <?php require __DIR__ . '/../../_errors.php'; ?>
  <form method="post" enctype="multipart/form-data" class="card form" action="<?= url('/admin/hlasovani/' . $poll['id'] . '/listina') ?>">
    <?= csrf_field() ?>
    <label>Jednotka
      <select name="unit_id" required><option value="">—</option><?php foreach ($units as $un): ?><option value="<?= $un['id'] ?>"<?= (int) $in['unit_id'] === (int) $un['id'] ? ' selected' : '' ?>><?= e(unit_name($un)) ?> · <?= e($un['owner_note']) ?><?= isset($voted[(int) $un['id']]) ? ' (už hlasovala)' : '' ?></option><?php endforeach ?></select></label>
    <ol class="poll-items">
    <?php foreach ($items as $it): ?>
      <li><fieldset><legend><?= e($it['question']) ?></legend>
        <?php foreach (CHOICES as $k => $label): ?><label class="check"><input type="radio" name="choice[<?= $it['id'] ?>]" value="<?= $k ?>"<?= ($in['choice'][$it['id']] ?? '') === $k ? ' checked' : '' ?>> <?= $label ?></label><?php endforeach ?>
        <label class="check"><input type="radio" name="choice[<?= $it['id'] ?>]" value=""<?= ($in['choice'][$it['id']] ?? '') === '' ? ' checked' : '' ?>> nevyplněno</label>
      </fieldset></li>
    <?php endforeach ?>
    </ol>
    <div class="form-row">
      <label>Datum u podpisu <input type="date" name="signed_on" value="<?= e($in['signed_on']) ?>" required></label>
      <label>Lístek doručen dne <input type="date" name="received_on" value="<?= e($in['received_on']) ?>" required></label>
    </div>
    <label>Sken podepsaného lístku (PDF, JPG, PNG – nepovinné) <input type="file" name="scan" accept=".pdf,.jpg,.jpeg,.png"></label>
    <label>Poznámka <input type="text" name="note" maxlength="255" value="<?= e($in['note']) ?>" placeholder="Např. doručeno do schránky SVJ"></label>
    <label class="check"><input type="checkbox" name="replace"<?= $in['replace'] ? ' checked' : '' ?>> <span>Nahradit dosavadní hlas jednotky <small>Jen když lístek opravdu nahrazuje dřívější hlas (např. vlastník hlasoval přes web a pak poslal lístek). Předchozí zápis zůstane v protokolu.</small></span></label>
    <p class="hint">Bez data a vlastnoručního podpisu na lístku s plným zněním návrhu hlas neplatí (§ 1212). Originál lístku uschovejte.</p>
    <div class="actions"><button class="btn btn-big" type="submit">Zapsat hlas</button>
      <a class="btn btn-ghost" href="<?= url('/admin/hlasovani/' . $poll['id'] . '/listky') ?>">Hlasovací lístky k tisku</a></div>
  </form>

  <?php if ($paper): ?>
  <h2>Zapsané listinné hlasy</h2>
  <table class="table">
    <thead><tr><th>Zapsáno</th><th>Jednotka</th><th>Podepsáno / doručeno</th><th>Zapsal(a)</th><th>Sken</th></tr></thead>
    <tbody><?php $unitById = array_column($units, null, 'id'); foreach (array_reverse($paper) as $r): ?>
      <tr><td data-label="Zapsáno"><?= cz_datetime($r['at']) ?></td><td data-label="Jednotka"><?= isset($unitById[$r['unit_id']]) ? e(unit_name($unitById[$r['unit_id']])) : '—' ?></td>
        <td data-label="Podepsáno / doručeno"><?= cz_date($r['signed_on']) ?> / <?= cz_date($r['received_on']) ?></td><td data-label="Zapsal(a)"><?= e($r['entered_name']) ?></td>
        <td data-label="Sken"><?= $r['scan_file'] !== '' ? '<a href="' . url('/admin/hlasovani/listina/' . $r['id'] . '/sken') . '">' . e($r['scan_name']) . '</a>' : '—' ?></td></tr>
    <?php endforeach ?></tbody>
  </table>
  <?php endif ?>
</div>
