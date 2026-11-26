<div class="wrap page">
  <header class="page-head"><h1>Účetnictví</h1>
    <p class="lead">Export vlastníků, předpisů záloh a plateb z banky všech domů najednou a import stavů účtů nebo jednotek z jednoho souboru za více domů.</p></header>
  <?php if (!$houses): ?><p class="empty">Zatím nespravujete žádný dům.</p><?php else: ?>
  <section class="card" id="export">
    <h2>Export pro účetní program</h2>
    <form method="get" action="<?= url('/ucetnictvi/export') ?>" class="form">
      <div class="two-col">
        <div>
          <fieldset class="checks"><legend>Program</legend>
            <?php foreach (ACCT_FORMATS as $k => $label): ?><label class="check"><input type="radio" name="format" value="<?= e($k) ?>"<?= $params['format'] === $k ? ' checked' : '' ?>> <span><?= e($label) ?></span></label><?php endforeach ?>
          </fieldset>
          <fieldset class="checks"><legend>Co exportovat</legend>
            <?php foreach (ACCT_PARTS as $k => $label): ?><label class="check"><input type="checkbox" name="casti[]" value="<?= e($k) ?>" checked> <span><?= e($label) ?></span></label><?php endforeach ?>
          </fieldset>
          <label>Předpisy od měsíce <input type="month" name="od" value="<?= e($params['from']) ?>"></label>
          <label>Počet měsíců <input type="number" name="mesicu" min="1" max="12" value="<?= (int) $params['months'] ?>"></label>
          <label>Platby z banky od <input type="date" name="banka_od" value="<?= e($params['bank_from']) ?>"></label>
          <label>do <input type="date" name="banka_do" value="<?= e($params['bank_to']) ?>"></label>
        </div>
        <fieldset class="checks"><legend>Domy (<?= count($houses) ?>)</legend>
          <?php foreach ($houses as $h): ?><label class="check"><input type="checkbox" name="domy[]" value="<?= e($h['slug']) ?>" checked> <span><?= e($h['name']) ?></span></label><?php endforeach ?>
        </fieldset>
      </div>
      <p class="hint">Stáhne se ZIP se složkou za každý dům. Nastavení exportu (zkratka bankovního účtu, splatnost, kódy ABRA Flexi) má každý dům ve své administraci v části Účetnictví. Soubory odpovídají zveřejněným schématům POHODY a Money S3; <strong>import do skutečných programů jsme zatím neověřili</strong>.</p>
      <div class="actions"><button class="btn" type="submit">Stáhnout ZIP</button></div>
    </form>
  </section>
  <section class="card" id="import">
    <h2>Import za více domů</h2>
    <form method="post" enctype="multipart/form-data" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
      <fieldset class="checks"><legend>Co soubor obsahuje</legend>
        <?php foreach (ACCT_TARGETS as $k => [$label]): ?><label class="check"><input type="radio" name="target" value="<?= e($k) ?>"<?= $k === 'accounts' ? ' checked' : '' ?>> <span><?= e($label) ?></span></label><?php endforeach ?>
      </fieldset>
      <label>Soubor (CSV nebo XLSX, do 5 MB) <input type="file" name="file" accept=".csv,.txt,.xlsx" required></label>
      <label>Program, ze kterého soubor je <select name="preset"><option value="">jiný / nevím – poznat podle záhlaví</option><?php foreach (ACCT_PRESETS as $k => [$label]): ?><option value="<?= e($k) ?>"><?= e($label) ?> (odhad – ověřit na vzorovém exportu)</option><?php endforeach ?></select></label>
      <p class="hint">Soubor musí mít sloupec <strong>Dům</strong> s názvem domu, jeho IČO nebo adresou webu (<?= e(implode(', ', array_slice(array_column($houses, 'slug'), 0, 3))) ?>…). Před uložením uvidíte náhled za každý dům; řádky nepoznaných domů a řádky s chybou se vynechají.</p>
      <div class="actions"><button class="btn" type="submit">Nahrát a zkontrolovat</button></div>
    </form>
  </section>
  <?php endif ?>
</div>
