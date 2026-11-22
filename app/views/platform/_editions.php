<?php /** Tři edice vedle sebe. $edView = 'admin' (objednávka) nebo 'landing' (registrace). V administraci je $edition aktuální edice. */
$edNow = $edView === 'admin' ? ($edition ?? 'free') : '';
$lim = fn(string $ed, string $k) => pset_int("lim_{$ed}_{$k}", 0, 10000000);
$edNotes = ['free' => 'Web domu pro každé společenství. Bez časového omezení.', 'standard' => 'Pro výbor, který přes web opravdu řídí dům.', 'premium' => 'Bez limitů, s vlastní doménou a AI zápisem.'];
$edExtra = [
    'free' => ['Veřejný web: aktuality, dokumenty, kontakty, termíny', 'Přihlášení obyvatel, hlášení závad, vývěska', 'Jedna šablona vzhledu, 5 barev', 'V patičce odkaz na službu'],
    'standard' => ['Vše z edice Zdarma', 'Více hlasování ročně, ankety, zápis ze shromáždění', 'Odečty, revize, klíče a čipy', 'Úkoly a usnesení, dodavatelé', 'Všech 5 šablon, bez odkazu v patičce', 'Záloha všech dat ke stažení', 'Vlastní doména za příplatek ' . money_czk(pset_int('price_domain', 0, 1000000)) . ' ročně'],
    'premium' => ['Vše z edice Standard', 'Platby na jednotku s upomínkami, rezervace prostor', 'Jazykové verze webu', 'Vlastní doména a vlastní certifikát v ceně', 'AI zápis ze shromáždění z poznámek i přepisu', 'Přednostní podpora'],
];
$edCompact = !empty($edCompact);
if ($edCompact) {
    $n = fn(string $ed, string $k, string $one, string $many) => ($v = $lim($ed, $k)) ? 'až ' . number_format($v, 0, ',', "\u{a0}") . ' ' . ($v === 1 ? $one : $many) : 'bez omezení';
    $edExtra = [
        'free' => ['Veřejný web a část pro obyvatele', 'Účty obyvatel: ' . $n('free', 'users', 'účet', 'účtů'), 'Jedna šablona, 5 barev'],
        'standard' => ['Hlasování, ankety a zápis ze shromáždění', 'Účty obyvatel: ' . $n('standard', 'users', 'účet', 'účtů'), 'Všech 5 šablon'],
        'premium' => ['Platby na jednotku, rezervace, jazyky', 'Účty obyvatel: ' . $n('premium', 'users', 'účet', 'účtů'), 'Vlastní doména v ceně'],
    ];
} ?>
<div class="editions<?= $edCompact ? ' editions-compact' : '' ?>"<?= $edView === 'landing' ? ' data-reveal-group="up"' : '' ?>>
  <?php foreach (EDITIONS as $ek => $en): $price = edition_price($ek); $priceMonth = edition_price($ek, 'month'); ?>
  <section class="edition<?= $ek === 'standard' ? ' is-main' : '' ?><?= $edNow === $ek ? ' is-current' : '' ?>">
    <h3><?= e($en) ?><?= $edNow === $ek ? ' <span class="tag">Vaše edice</span>' : '' ?></h3>
    <p class="edition-price"><?= $price ? '<strong>' . money_czk($price) . '</strong> <small>/ rok</small>' : '<strong>0 Kč</strong> <small>napořád</small>' ?></p>
    <?php if ($price): ?><p class="edition-monthly">nebo <?= money_czk($priceMonth) ?> měsíčně</p><?php endif ?>
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
      <a class="btn<?= $ek === 'standard' ? '' : ' btn-ghost' ?>" href="<?= url('/registrace') ?>"><?= $ek === 'free' ? 'Založit web zdarma' : ($edCompact ? 'Začít zdarma' : 'Začít zdarma, přejít později') ?></a>
    <?php elseif ($ek !== 'free' && !empty($t['billing']) && billing_order_problem($t, $ek)): ?>
      <p class="hint">Přechod možný po <?= cz_date($t['paid_until']) ?>, kdy skončí zaplacená edice <?= e(EDITIONS[$edNow]) ?>.</p>
    <?php elseif ($ek !== 'free' && !empty($t['billing'])): $edCredit = billing_credit($t, $ek); ?>
      <form method="post" action="<?= url('/admin/predplatne/edice') ?>" class="edition-order"><?= csrf_field() ?><input type="hidden" name="edition" value="<?= $ek ?>">
        <button class="btn<?= $ek === 'standard' ? '' : ' btn-ghost' ?>" type="submit" name="period" value="year"><?= $edNow === $ek ? 'Prodloužit o rok' : 'Objednat ' . e($en) ?></button>
        <button class="btn btn-small btn-ghost" type="submit" name="period" value="month">Platit měsíčně · <?= money_czk($priceMonth) ?></button></form>
      <?php if ($edCredit): ?><p class="hint">Zaplatíte <?= money_czk(max(0, $price - $edCredit)) ?> – odečteme <?= money_czk($edCredit) ?> za nevyčerpanou část edice <?= e(EDITIONS[$edNow]) ?>.</p><?php endif ?>
    <?php endif ?>
  </section>
  <?php endforeach ?>
</div>
