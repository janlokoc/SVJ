<div class="wrap page">
  <header class="page-head row"><h1>Jednotky</h1><a class="btn" href="<?= url('/admin/jednotky/nova') ?>">+ Nová jednotka</a></header>
  <p class="lead">Seznam jednotek v domě, jejich vlastníci a účty, které k nim patří. Seznam vidí jen správci webu.</p>
  <?php if (!$units): ?><p class="empty">Zatím tu není žádná jednotka. Přidejte je jednotlivě, nebo najednou formulářem níže.</p><?php else: ?>
  <label class="table-filter">Hledat <input type="search" data-filter="#units-table tbody" data-filter-rows="tr" placeholder="Číslo jednotky nebo jméno…"></label>
  <table class="table" id="units-table">
    <thead><tr><th>Jednotka</th><th>Patro</th><th>Podíl</th><th>Vlastník dle evidence</th><th>Účty na webu</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($units as $un): ?>
      <tr>
        <td data-label="Jednotka"><a href="<?= url('/admin/jednotky/' . $un['id']) ?>"><strong><?= e(unit_name($un)) ?></strong></a></td>
        <td data-label="Patro"><?= e($un['floor']) ?></td>
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
      <label>Vchod <select name="entrance" id="bulk-entrance"><?php foreach (ENTRANCES as $en): if ($en === '') continue; ?><option value="<?= $en ?>">48 <?= $en ?></option><?php endforeach ?></select></label>
      <label>Čísla od <input type="number" name="from" id="bulk-from" min="1" required></label>
      <label>do <input type="number" name="to" id="bulk-to" min="1" required></label>
      <div class="actions"><button class="btn" type="submit">Přidat</button></div>
    </div>
    <p class="hint">Jednotky, které už existují, se přeskočí. Vlastníky doplníte úpravou jednotky.</p>
  </form>
</div>
