<div class="wrap page narrow print-doc">
  <p class="no-print"><a class="btn btn-small btn-ghost" href="<?= url('/terminy/' . $ev['id']) ?>">← Zpět</a> <button class="btn btn-small" type="button" data-print>Vytisknout</button></p>
  <h1>Plná moc</h1>
  <p>Já, níže podepsaný(á) <strong><?= e($owner) ?></strong>, vlastník jednotky <strong><?= e(unit_name($unit)) ?></strong> v domě Tábor 48 a–d, Brno<?= $unit['share'] ? ' (spoluvlastnický podíl na společných částech ' . e($unit['share']) . ')' : '' ?>,</p>
  <p class="center"><strong>zmocňuji</strong></p>
  <p>pana / paní <strong><?= $proxy !== '' ? e($proxy) : '.......................................................' ?></strong>, datum narození ......................, bytem ..............................................................................,</p>
  <p>aby mě zastupoval(a) na shromáždění Společenství vlastníků jednotek Tábor 48, které se koná dne <strong><?= cz_date($ev['event_date'], true) ?></strong><?= $ev['event_time'] ? ' v ' . e($ev['event_time']) : '' ?><?= $ev['place'] ? ', ' . e($ev['place']) : '' ?>, a aby za mě hlasoval(a) o všech bodech programu.</p>
  <p>V ...................... dne ......................</p>
  <div class="sign-row"><div>...............................................<br>podpis zmocnitele (vlastníka)</div><div>...............................................<br>zmocnění přijímám – podpis zmocněnce</div></div>
  <p class="hint no-print">Vzor pro běžné použití. U jednotky ve spoluvlastnictví nebo ve společném jmění manželů podepisují všichni spoluvlastníci, případně jejich společný zástupce. Požadavky na plnou moc mohou upravovat stanovy.</p>
</div>
