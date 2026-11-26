<?php /* Modul G: srovnání s konkurencí (/srovnani). Údaje v app/ext/compare_lib.php. */
$rows = compare_price_rows();
$feat = compare_features();
$vatLabel = ['incl' => 's DPH', 'excl' => 'bez DPH', 'final' => 'neplátce DPH – cena konečná', 'unknown' => 'DPH na webu neuvedeno'];
$kc = fn(?int $n) => $n === null ? '–' : money_czk($n);
?>
<section class="wrap lp-section lp-page-head">
  <h1>Srovnání s jinými službami pro SVJ</h1>
  <p class="lead">Kolik stojí web nebo portál pro dům s <?= COMPARE_UNITS ?> jednotkami a co kdo podle svého webu nabízí. U každé služby uvádíme zdroj a kdy jsme údaje ověřili.</p>
  <p class="hint">Údaje jsme převzali z veřejných webů dodavatelů (naposledy ověřeno <?= e(compare_month_label(compare_oldest_check())) ?>). Služby jsme nezkoušeli v ostrém provozu – srovnáváme to, co dodavatelé uvádějí. Ceny se mohou změnit; platí vždy ceník dodavatele. Najdete-li nepřesnost, napište nám<?= pset('contact_email') !== '' ? ' na <a href="mailto:' . e(pset('contact_email')) . '">' . e(pset('contact_email')) . '</a>' : '' ?> a opravíme ji.</p>
</section>

<section class="wrap lp-section" id="ceny">
  <h2>Cena za rok pro dům s <?= COMPARE_UNITS ?> jednotkami</h2>
  <p class="muted">Seřazeno podle ceny, kterou SVJ skutečně zaplatí (s DPH, u neplátců DPH konečná cena). Většina SVJ si DPH neodečítá, proto je pro srovnání rozhodující cena s DPH. Přepočet ceny s DPH na cenu bez DPH: sazba 21 %.</p>
  <div class="table-wrap"><table class="table compare-table">
    <thead><tr><th>Služba</th><th>Jak se počítá</th><th class="t-num">Bez DPH</th><th class="t-num">K zaplacení</th><th>Zdroj, ověřeno</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr<?= $r['ours'] ? ' class="is-ours"' : '' ?>>
        <td data-label="Služba"><strong><?= e($r['name']) ?></strong><?= !empty($r['vendor']) ? '<br><small class="muted">' . e($r['vendor']) . '</small>' : '' ?><?= $r['note'] !== '' ? '<br><small class="muted">' . e($r['note']) . '</small>' : '' ?></td>
        <td data-label="Jak se počítá"><?= e($r['calc']) ?></td>
        <td data-label="Bez DPH" class="t-num"><?= $kc($r['net']) ?></td>
        <td data-label="K zaplacení" class="t-num"><strong><?= $r['gross'] !== null ? $kc($r['gross']) : money_czk($r['price']) ?></strong><br><small class="muted"><?= e($vatLabel[$r['vat']]) ?></small></td>
        <td data-label="Zdroj"><?php if ($r['ours']): ?><a href="<?= url('/cenik') ?>">náš ceník</a><?php else: ?><a href="<?= e($r['source']) ?>" rel="nofollow noopener" target="_blank"><?= e(preg_replace('~^https?://(www\.)?~', '', rtrim($r['source'], '/'))) ?></a><?php endif ?><br><small class="muted">ověřeno <?= e(compare_month_label($r['verified'])) ?></small></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
  <p class="hint">Výpočet pro <?= COMPARE_UNITS ?> jednotek je náš, z cen zveřejněných dodavatelem (cena za jednotku × <?= COMPARE_UNITS ?>, měsíční cena × 12). U služeb „jen hlasování“ jde o užší nabídku než web domu. U služeb s neuvedeným vztahem k DPH může být skutečná cena až o 21 % vyšší.</p>
</section>

<section class="wrap lp-section" id="funkce">
  <h2>Co kdo nabízí</h2>
  <p class="muted"><strong>ano</strong> = dodavatel funkci na svém webu uvádí, <strong>částečně</strong> = v omezené podobě nebo za příplatek (upřesnění pod značkou), <strong>neuvedeno</strong> = na webu dodavatele jsme ji nenašli (neznamená, že neexistuje), <strong>neověřeno</strong> = údaj jsme nemohli ověřit u dodavatele.</p>
  <div class="table-wrap"><table class="table compare-features">
    <thead><tr><th>Funkce</th><th class="is-ours"><?= e(pset('service_name')) ?></th><?php foreach (COMPARE_VENDORS as [$vn]): ?><th><?= e($vn) ?></th><?php endforeach ?></tr></thead>
    <tbody>
    <?php foreach ($feat as $name => [$ours, $theirs]): ?>
      <tr><th scope="row"><?= e($name) ?></th>
        <?php foreach ([$ours, ...$theirs] as $i => $cell): [$code, $note] = compare_cell($cell); $vn = $i === 0 ? pset('service_name') : array_values(COMPARE_VENDORS)[$i - 1][0]; ?>
        <td data-label="<?= e($vn) ?>" class="cmp-<?= $code === '?' ? 'q' : strtolower($code) ?><?= $i === 0 ? ' is-ours' : '' ?>"><?= e(COMPARE_STATES[$code]) ?><?= $note !== '' ? '<br><small>' . e($note) . '</small>' : '' ?></td>
        <?php endforeach ?></tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
  <p class="hint">Zdroje: <?php $links = []; foreach (COMPARE_VENDORS as [$vn, $src, $ver]) { $links[] = '<a href="' . e($src) . '" rel="nofollow noopener" target="_blank">' . e($vn) . '</a> (ověřeno ' . e(compare_month_label($ver)) . ')'; } echo implode(', ', $links); ?>.</p>
  <p class="hint">Co sami zatím neumíme, uvádíme stejně otevřeně: ověření hlasu SMS kódem nebo bankovní identitou, SMS zprávy a export do účetních programů.</p>
</section>

<section class="wrap lp-section narrow"><div class="actions lp-final"><?php if (pset('signup_open') === '1'): ?><a class="btn btn-big" href="<?= url('/registrace') ?>">Založit web zdarma</a><?php endif ?><a class="btn btn-big btn-ghost" href="<?= url('/cenik') ?>">Ceník</a></div></section>
