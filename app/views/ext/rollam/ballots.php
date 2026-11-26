<?php $ballotUnits = $blank ? [['id' => 0, 'entrance' => '', 'number' => '', 'share' => '', 'owner_label' => '']] : $units; ?>
<div class="wrap page print-doc rollam-doc">
  <div class="no-print">
    <p><a class="btn btn-small btn-ghost" href="<?= url('/admin/hlasovani/' . $poll['id'] . '/vysledky') ?>">← Zpět</a> <button class="btn btn-small" type="button" data-print>Vytisknout</button>
      <a class="btn btn-small btn-ghost" href="<?= url('/admin/hlasovani/' . $poll['id'] . '/listky?prazdny=1') ?>">Prázdný lístek</a></p>
    <p class="hint">Každý lístek se vytiskne na samostatnou stránku (<?= count($ballotUnits) ?>). Obsahuje plné znění návrhu – podle § 1212 musí vlastník podepsat vlastní rukou listinu s plným zněním návrhu a uvést datum.</p>
  </div>
  <?php foreach ($ballotUnits as $un): ?>
  <section class="rollam-ballot">
    <p class="rollam-ballot-head"><strong><?= e(site('name')) ?></strong></p>
    <h1>Hlasovací lístek</h1>
    <p>Rozhodování mimo zasedání (per rollam) podle § 1211 a násl. zákona č. 89/2012 Sb., občanský zákoník</p>
    <table class="table print-table rollam-meta">
      <tr><th>Hlasování</th><td><?= e($poll['title']) ?></td></tr>
      <tr><th>Jednotka</th><td><?= $un['id'] ? e(unit_name($un)) : '' ?></td></tr>
      <tr><th>Vlastník</th><td><?= e($un['owner_label']) ?></td></tr>
      <tr><th>Spoluvlastnický podíl</th><td><?= e($un['share']) ?></td></tr>
      <tr><th>Lhůta pro doručení</th><td>vyplněný lístek doručte nejpozději <strong><?= cz_date($poll['closes_at'], true) ?></strong> výboru (do schránky SVJ nebo osobně)</td></tr>
    </table>
    <?php if (trim((string) $poll['body']) !== ''): ?><h2>Odůvodnění a podklady</h2><div class="prose"><?= format_text((string) $poll['body']) ?></div><?php endif ?>
    <h2>Návrh rozhodnutí (plné znění) a hlas</h2>
    <p class="hint">U každého bodu označte křížkem jednu možnost.</p>
    <ol class="rollam-ballot-items">
      <?php foreach ($items as $it): ?>
        <li><p><?= nl2br(e($it['question'])) ?></p>
          <p class="rollam-boxes"><?php foreach (CHOICES as $label): ?><span>☐ <?= e(mb_strtoupper($label)) ?></span> <?php endforeach ?></p></li>
      <?php endforeach ?>
    </ol>
    <p>Nevrácený nebo pozdě doručený lístek znamená, že vlastník nehlasoval. Rozhodnutí je přijato většinou hlasů všech vlastníků jednotek.</p>
    <p class="rollam-sign">Datum (den, měsíc, rok): ............................ &nbsp; Jméno: ................................................</p>
    <p class="rollam-sign">Vlastnoruční podpis vlastníka: ..............................................................</p>
    <p class="hint">Za jednotku se hlasuje jedním lístkem. Je-li ve spoluvlastnictví, podepisuje osoba oprávněná jednat za spoluvlastníky; za právnickou osobu její statutární orgán.</p>
  </section>
  <?php endforeach ?>
</div>
