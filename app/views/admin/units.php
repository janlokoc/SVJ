<div class="wrap page">
  <header class="page-head row"><h1>Jednotky</h1><span class="actions"><a class="btn btn-ghost" href="<?= url('/admin/jednotky/export') ?>">Seznam členů (CSV)</a><a class="btn btn-ghost" href="<?= url('/admin/jednotky/import') ?>">Import z katastru</a><a class="btn" href="<?= url('/admin/jednotky/nova') ?>">+ Nová jednotka</a></span></header>
  <p class="lead">Seznam jednotek v domě, jejich vlastníci a účty, které k nim patří. Seznam vidí jen správci webu.</p>
  <?php if ($todo = cadastre_todo()): ?>
  <section class="card callout" id="k-overeni">
    <h2>K ověření po importu z katastru (<?= count($todo) ?>)</h2>
    <p class="hint">Katastr tyto údaje neuvádí jednoznačně. Ověřte je v prohlášení vlastníka a případně opravte u jednotky; pak položku odškrtněte.</p>
    <ul class="todo-list">
      <?php foreach ($todo as $i => $t): ?><li><span><?= e($t) ?></span>
        <form method="post" action="<?= url('/admin/jednotky/k-overeni') ?>"><?= csrf_field() ?><input type="hidden" name="i" value="<?= $i ?>"><button class="btn btn-small btn-ghost" type="submit">Ověřeno</button></form></li><?php endforeach ?>
    </ul>
    <?php if (count($todo) > 1): ?><form method="post" action="<?= url('/admin/jednotky/k-overeni') ?>" data-confirm="Označit všechny položky jako ověřené?"><?= csrf_field() ?><input type="hidden" name="all" value="1"><button class="btn btn-small btn-ghost" type="submit">Vše ověřeno</button></form><?php endif ?>
  </section>
  <?php endif ?>
  <?php if (!$units): ?><p class="empty">Zatím tu není žádná jednotka. Přidejte je jednotlivě, nebo najednou formulářem níže.</p><?php else: ?>
  <label class="table-filter">Hledat <input type="search" data-filter="#units-table tbody" data-filter-rows="tr" placeholder="Číslo jednotky nebo jméno…"></label>
  <table class="table" id="units-table">
    <thead><tr><th>Jednotka</th><th>Patro</th><th>Plocha</th><th>Podíl</th><th>Vlastník dle evidence</th><th>Účty na webu</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($units as $un): ?>
      <tr>
        <td data-label="Jednotka"><a href="<?= url('/admin/jednotky/' . $un['id']) ?>"><strong><?= e(unit_name($un)) ?></strong></a></td>
        <td data-label="Patro"><?= e($un['floor']) ?></td>
        <td data-label="Plocha"><?= e(unit_area_label($un['area'] ?? null)) ?></td>
        <td data-label="Podíl"><?= e($un['share']) ?></td>
        <td data-label="Vlastník dle evidence"><?= e($un['owner_note']) ?></td>
        <td data-label="Účty na webu"><?php foreach ($people[$un['id']] ?? [] as $p): ?><div><a href="<?= url('/admin/uzivatele/' . $p['id']) ?>"><?= e($p['name']) ?></a> <small><?= mb_strtolower(KINDS[$p['kind']]) ?><?= $p['pending'] ? ', pozván(a)' : '' ?><?= $p['active'] ? '' : ', vypnutý' ?></small></div><?php endforeach ?></td>
        <td class="t-actions"><a href="<?= url('/admin/jednotky/' . $un['id']) ?>">Upravit</a>
          <form method="post" action="<?= url('/admin/jednotky/' . $un['id'] . '/smazat') ?>" data-confirm="Opravdu smazat jednotku <?= e(unit_name($un)) ?>?"><?= csrf_field() ?><button class="link-danger" type="submit">Smazat</button></form></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
  <form method="post" action="<?= url('/admin/jednotky/hromadne') ?>" class="card form">
    <h2>Přidat více jednotek najednou</h2>
    <?= csrf_field() ?>
    <div class="form-row">
      <label>Vchod <select name="entrance" id="bulk-entrance"><?php foreach (entrances() as $en): if ($en === '') continue; ?><option value="<?= e($en) ?>"><?= e(unit_prefix() . ' ' . $en) ?></option><?php endforeach ?></select></label>
      <label>Čísla od <input type="number" name="from" id="bulk-from" min="1" required></label>
      <label>do <input type="number" name="to" id="bulk-to" min="1" required></label>
      <div class="actions"><button class="btn" type="submit">Přidat</button></div>
    </div>
    <p class="hint">Jednotky, které už existují, se přeskočí. Vlastníky doplníte úpravou jednotky.</p>
  </form>
</div>
