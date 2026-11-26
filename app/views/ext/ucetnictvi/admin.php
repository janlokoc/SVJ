<?php $o = $house['opts']; ?>
<div class="wrap page">
  <header class="page-head"><h1>Účetnictví – export a import</h1>
    <p class="lead">Předání údajů mezi webem a účetním programem: vlastníci a předpisy záloh do účetnictví, stavy účtů a jednotky z účetnictví zpět na web.</p></header>
  <p><?= function_exists('help_link') ? help_link('ucetnictvi') : '' ?></p>
  <?php if ($house['ico'] === ''): ?><div class="flash flash-info">V <a href="<?= url('/admin/nastaveni') ?>">Nastavení</a> chybí IČ společenství. POHODA i Money podle něj poznají, do které účetní jednotky soubor patří.</div><?php endif ?>

  <section class="card" id="export">
    <h2>Export do účetnictví</h2>
    <form method="get" action="<?= url('/admin/ucetnictvi/export') ?>" class="form">
      <div class="two-col">
        <div>
          <fieldset class="checks"><legend>Program</legend>
            <?php foreach (ACCT_FORMATS as $k => $label): ?><label class="check"><input type="radio" name="format" value="<?= e($k) ?>"<?= $params['format'] === $k ? ' checked' : '' ?>> <span><?= e($label) ?></span></label><?php endforeach ?>
          </fieldset>
          <fieldset class="checks"><legend>Co exportovat</legend>
            <?php foreach (ACCT_PARTS as $k => $label): $off = !$platby && $k !== 'addressbook'; ?><label class="check"><input type="checkbox" name="casti[]" value="<?= e($k) ?>"<?= in_array($k, $params['parts'], true) && !$off ? ' checked' : '' ?><?= $off ? ' disabled' : '' ?>> <span><?= e($label) ?><?= $off ? ' <small class="muted">(jen s funkcí Platby na jednotku)</small>' : '' ?></span></label><?php endforeach ?>
          </fieldset>
        </div>
        <div>
          <label>Předpisy od měsíce <input type="month" name="od" value="<?= e($params['from']) ?>"></label>
          <label>Počet měsíců <input type="number" name="mesicu" min="1" max="12" value="<?= (int) $params['months'] ?>"></label>
          <label>Platby z banky od <input type="date" name="banka_od" value="<?= e($params['bank_from']) ?>"></label>
          <label>do <input type="date" name="banka_do" value="<?= e($params['bank_to']) ?>"></label>
        </div>
      </div>
      <p class="hint">Předpis vznikne za každou jednotku s měsíční zálohou (z <a href="<?= url('/admin/platby') ?>">Plateb na jednotku</a>) a měsíc, se splatností <?= (int) $o['acct_due_day'] ?>. dne. V POHODĚ a Money jde o <strong>ostatní pohledávky</strong> (SVJ zálohy nefakturuje, ale potřebuje u každého vlastníka saldo a párování úhrad podle VS). CSV obsahuje navíc stavy účtů jednotek.</p>
      <p class="hint">Soubory odpovídají zveřejněným schématům POHODY (XML verze 2) a Money S3; <strong>import do skutečné POHODY, Money ani ABRA Flexi jsme zatím neověřili</strong>. Před prvním použitím je zkuste naimportovat do zkušební účetní jednotky.</p>
      <div class="actions"><button class="btn" type="submit">Stáhnout export</button></div>
    </form>
    <p class="muted"><small>Na webu je <?= (int) $stats['units'] ?> jednotek, <?= (int) $stats['accounts'] ?> s předpisem a stavem účtu, <?= (int) $stats['bank'] ?> plateb z banky.</small></p>
  </section>

  <section class="card" id="import">
    <h2>Import z účetnictví nebo programu správy domů</h2>
    <form method="post" enctype="multipart/form-data" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
      <fieldset class="checks"><legend>Co soubor obsahuje</legend>
        <?php foreach (ACCT_TARGETS as $k => [$label]): $off = !$platby && $k === 'accounts'; ?><label class="check"><input type="radio" name="target" value="<?= e($k) ?>"<?= $k === ($platby ? 'accounts' : 'units') ? ' checked' : '' ?><?= $off ? ' disabled' : '' ?>> <span><?= e($label) ?><?= $off ? ' <small class="muted">(jen s funkcí Platby na jednotku)</small>' : '' ?></span></label><?php endforeach ?>
      </fieldset>
      <label>Soubor (CSV nebo XLSX, do 5 MB) <input type="file" name="file" accept=".csv,.txt,.xlsx" required></label>
      <label>Program, ze kterého soubor je <select name="preset"><option value="">jiný / nevím – poznat podle záhlaví</option><?php foreach (ACCT_PRESETS as $k => [$label]): ?><option value="<?= e($k) ?>"><?= e($label) ?> (odhad – ověřit na vzorovém exportu)</option><?php endforeach ?></select></label>
      <p class="hint">Oddělovač (středník, čárka, tabulátor) a kódování (UTF-8 nebo Windows-1250) poznáme sami. Sloupce odhadneme podle záhlaví (jednotka, vlastník, podíl, záloha/předpis, saldo/stav účtu, VS…) a před uložením je uvidíte v náhledu – nic se neuloží bez Vašeho potvrzení. Předvolby programů jsou jen odhad názvů sloupců.</p>
      <div class="actions"><button class="btn" type="submit">Nahrát a zkontrolovat</button></div>
    </form>
  </section>

  <section class="card" id="nastaveni">
    <h2>Nastavení exportu</h2>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
      <div class="two-col">
        <label>Zkratka bankovního účtu v účetnictví <input name="acct_bank_code" maxlength="20" value="<?= e($o['acct_bank_code']) ?>" placeholder="např. KB nebo FIO">
          <small class="hint">Pod jakou zkratkou má účet společenství POHODA, Money nebo ABRA Flexi. Prázdné = účet doplní program podle svého nastavení.</small></label>
        <label>Splatnost předpisu (den v měsíci) <input type="number" name="acct_due_day" min="1" max="28" value="<?= (int) $o['acct_due_day'] ?>"></label>
        <label>ABRA Flexi – typ dokladu ostatní pohledávky <input name="acct_flexi_receivable" maxlength="20" value="<?= e($o['acct_flexi_receivable']) ?>"></label>
        <label>ABRA Flexi – typ bankovního dokladu <input name="acct_flexi_bank_type" maxlength="20" value="<?= e($o['acct_flexi_bank_type']) ?>"></label>
      </div>
      <p class="hint">Kódy typů dokladů ABRA Flexi najdete v jejím číselníku Typy dokladů; výchozí hodnoty jsou jen odhad.</p>
      <div class="actions"><button class="btn btn-ghost" type="submit">Uložit nastavení</button></div>
    </form>
  </section>
</div>
