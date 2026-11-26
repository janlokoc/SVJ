<div class="wrap page narrow">
  <header class="page-head"><h1>Nápověda</h1>
    <p class="lead">Jen pro věci, které se nedají vyčíst z obrazovky: co si přinášíte zvenku, co nastavuje někdo jiný a postupy o několika krocích. Každý návod je ověřený na skutečném webu.</p></header>
  <ul class="help-list">
    <?php foreach (HELP as $slug => [$title, $summary, $public]): if (!help_visible($slug)) continue; $v = help_verified($slug); ?>
      <li><a href="<?= url('/admin/napoveda/' . $slug) ?>"><strong><?= e($title) ?></strong></a>
        <p><?= e($summary) ?></p>
        <small class="muted"><?= $v ? 'Postup ověřen ' . e(cz_date($v['date'], true)) : 'Postup zatím neověřen' ?><?= $public ? ' · lze poslat odkazem i lidem bez účtu' : '' ?></small></li>
    <?php endforeach ?>
  </ul>
  <p class="hint">Něco Vám nebylo jasné ani s nápovědou? Napište nám přes Hlášení – obrazovku raději upravíme, než abychom k ní psali další návod.</p>
</div>
