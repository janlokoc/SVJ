<?php /** Tři edice vedle sebe. $edView = 'admin' (objednávka) nebo 'landing' (registrace). V administraci je $edition aktuální edice. */
$edNow = $edView === 'admin' ? ($edition ?? 'free') : '';
$lim = fn(string $ed, string $k) => pset_int("lim_{$ed}_{$k}", 0, 10000000);
$edNotes = ['free' => 'Web domu pro každé společenství. Bez časového omezení.', 'standard' => 'Pro výbor, který přes web opravdu řídí dům.', 'premium' => 'Bez limitů, s AI asistentem pro obyvatele, AI zápisem, platbami na jednotku a rezervacemi.'];
$edExtra = [
    'free' => ['Veřejný web: aktuality, dokumenty, kontakty, termíny', 'Přihlášení obyvatel, hlášení závad, vývěska', 'Jeden pevný vzhled (šablona i barvy)', 'V patičce odkaz na službu'],
    'standard' => ['Vše z edice Zdarma', 'Více hlasování ročně, ankety, zápis ze shromáždění', 'Dálkové odečty, revize, klíče a čipy', 'Upozornění do telefonu', 'Úkoly a usnesení, dodavatelé', '3 šablony a 3 barvy, vlastní logo, bez odkazu v patičce', 'Záloha všech dat ke stažení', 'AI pomocník pro texty výboru: oznámení, pozvánky, program, návrhy usnesení'],
    'premium' => ['Vše z edice Standard', 'AI asistent pro obyvatele i výbor: odpoví běžnou řečí podle stanov, domovního řádu, dokumentů a oznámení domu, vždy s odkazem na zdroj', 'AI zápis ze shromáždění: průběh jednání z poznámek nebo nahraného přepisu, po bodech programu', number_format($lim('premium', 'ai'), 0, ',', "\u{a0}") . ' použití AI pomocníka měsíčně místo ' . number_format($lim('standard', 'ai'), 0, ',', "\u{a0}") . ' (oznámení, pozvánky, program, usnesení)', 'Platby na jednotku s upomínkami, rezervace prostor', 'Jazykové verze webu', 'Všech 10 šablon a 10 barev místo 3 a 3', 'Přednostní podpora', 'Přístup k novým funkcím ještě před spuštěním (beta program)'],
];
$edCompact = !empty($edCompact);
if ($edCompact) {
    // Krátký výčet toho, co edice přidává oproti nižší; počty (účty, e-maily…) jsou v limitech výše, tady se neopakují.
    $edExtra = [
        'free' => ['Veřejný web a část pro obyvatele', 'Hlášení závad, vývěska, termíny do kalendáře', 'Jeden pevný vzhled'],
        'standard' => ['Hlasování, ankety a zápis ze shromáždění', 'Odečty, revize, klíče, úkoly a dodavatelé', 'Upozornění do telefonu', '3 šablony, 3 barvy a logo, bez odkazu v patičce, záloha dat'],
        'premium' => ['AI asistent pro obyvatele a AI zápis ze shromáždění', 'Platby na jednotku s upomínkami', 'Rezervace, jazykové verze, všech 10 šablon a 10 barev', 'Přednostní podpora a beta program'],
    ];
} ?>
<div class="editions<?= $edCompact ? ' editions-compact' : '' ?>">
  <?php foreach (EDITIONS as $ek => $en): $price = edition_price($ek); ?>
  <section class="edition<?= $ek === 'standard' ? ' is-main' : '' ?><?= $edNow === $ek ? ' is-current' : '' ?>">
    <h3><?= e($en) ?><?= $edNow === $ek ? ' <span class="tag">Vaše edice</span>' : '' ?></h3>
    <p class="edition-price"><?= $price ? '<strong>' . money_czk($price) . '</strong> <small>/ rok' . ' <b class="vat-tag">bez DPH</b>' . '</small>' : '<strong>0 Kč</strong> <small>napořád</small>' ?></p>
    <?php if ($edCompact): ?><div class="edition-more" id="edice-<?= $ek ?>"><div><ul class="edition-limits">
      <?php foreach (PLAN_LIMITS as $lk => [$ln, $unit]): if ($lk === 'ai' && !ai_configured()) { continue; } $v = $lim($ek, $lk); ?>
      <li><span><?= e($ln) ?></span><strong><?= $v ? number_format($v, 0, ',', "\u{a0}") . ($unit !== '' ? ' ' . $unit : '') : 'bez omezení' ?></strong></li>
      <?php endforeach ?>
    </ul></div></div>
    <button class="edition-toggle" type="button" aria-expanded="false" aria-controls="edice-<?= $ek ?>" data-collapse-toggle><span data-more>Zobrazit limity</span><span data-less hidden>Skrýt limity</span> <?= icon('chevron') ?></button><?php endif ?>
    <?php if (!$edCompact): ?><p class="muted"><?= e($edNotes[$ek]) ?></p>
    <ul class="edition-limits">
      <?php foreach (PLAN_LIMITS as $lk => [$ln, $unit]): if ($lk === 'ai' && !ai_configured()) { continue; } $v = $lim($ek, $lk); ?>
      <li><span><?= e($ln) ?></span><strong><?= $v ? number_format($v, 0, ',', "\u{a0}") . ($unit !== '' ? ' ' . $unit : '') : 'bez omezení' ?></strong></li>
      <?php endforeach ?>
    </ul><?php endif ?>
    <ul class="lp-check"><?php foreach ($edExtra[$ek] as $line): ?><li><?= e($line) ?></li><?php endforeach ?></ul>
    <?php if ($edView === 'landing'): ?>
      <a class="btn<?= $ek === 'standard' ? '' : ' btn-ghost' ?>" href="<?= url('/registrace') ?>"><?= $ek === 'free' ? 'Založit web zdarma' : 'Založit a vyzkoušet ' . PLAN_TRIAL_DAYS . ' dní' ?></a>
    <?php elseif ($ek !== 'free' && !empty($t['billing']) && billing_order_problem($t, $ek)): ?>
      <p class="hint">Přechod možný po <?= cz_date($t['paid_until']) ?>, kdy skončí zaplacená edice <?= e(EDITIONS[$edNow]) ?>.</p>
    <?php elseif ($ek !== 'free' && !empty($t['billing'])): $edCredit = billing_credit($t, $ek); ?>
      <form method="post" action="<?= url('/admin/predplatne/edice') ?>" class="edition-order"><?= csrf_field() ?><input type="hidden" name="edition" value="<?= $ek ?>">
        <button class="btn<?= $ek === 'standard' ? '' : ' btn-ghost' ?>" type="submit"><?= $edNow === $ek ? 'Prodloužit o rok' : 'Objednat ' . e($en) ?></button></form>
      <?php if ($edCredit): ?><p class="hint">Zaplatíte <?= money_czk(max(0, $price - $edCredit)) ?> – odečteme <?= money_czk($edCredit) ?> za nevyčerpanou část edice <?= e(EDITIONS[$edNow]) ?>.</p><?php endif ?>
    <?php endif ?>
  </section>
  <?php endforeach ?>
</div>
