<?php
$cta = fn(string $class = 'btn btn-big') => $open ? '<a class="' . $class . '" href="' . url('/registrace') . '">Založit web zdarma ' . icon('arrow') . '</a>' : '';
$templates = ['klasik' => 'les', 'modern' => 'more', 'vecna' => 'grafit', 'noviny' => 'cihla', 'vlidna' => 'svestka'];
$benefits = [
    ['Web domu i část pro sousedy', 'Aktuality, dokumenty a kontakty pro veřejnost; závady, hlasování a termíny pro přihlášené obyvatele.'],
    ['Shromáždění a hlasování bez papírů', 'Vlastníci potvrdí účast a hlasují na webu. Výsledky i zápis se sestaví samy.'],
    ['Hotovo za pět minut', 'Zvolíte adresu a vzhled, vytvoříte si účet a můžete zvát sousedy. Nic se neinstaluje.'],
];
?>
<section class="hero lp-hero lp-hero-simple">
  <div class="wrap hero-grid">
    <div class="hero-text">
      <h1>Web Vašeho SVJ <span class="h1-sub">zdarma a za pět minut</span></h1>
      <p class="lead">Vlastní web domu s částí pro obyvatele a administrací pro výbor.</p>
      <div class="actions"><?= $cta() ?><?php if ($demo): ?><a class="btn btn-big btn-ghost" href="<?= e(tenant_url($demo)) ?>">Prohlédnout ukázku</a><?php endif ?></div>
      <?php if ($open): ?><p class="lp-note">Bez platební karty a bez časového omezení.</p>
      <?php else: ?><p class="lp-note">Nová společenství teď zakládáme po domluvě<?= pset('contact_email') !== '' ? ' – napište na ' . e(pset('contact_email')) : '' ?>.</p><?php endif ?>
    </div>
    <div class="hero-side">
      <?php $look = 'les'; $miniName = 'Zahradní 12'; require __DIR__ . '/_mini.php'; ?>
    </div>
  </div>
</section>

<section class="wrap lp-section" id="funkce">
  <ul class="lp-benefits">
    <?php foreach ($benefits as [$h, $p]): ?><li><h2><?= e($h) ?></h2><p><?= e($p) ?></p></li><?php endforeach ?>
  </ul>
  <p class="lp-more"><a href="<?= url('/funkce') ?>">Všechny funkce <?= icon('arrow') ?></a></p>
</section>

<section class="wrap lp-section" id="vzhledy">
  <h2>Pět šablon, každá v pěti barvách</h2>
  <div class="lp-looks look-cards lp-looks-plain">
    <?php foreach ($templates as $style => $look): $miniName = null; ?><figure><?php require __DIR__ . '/_mini.php'; ?><figcaption><strong><?= e(explode(' – ', STYLES[$style])[0]) ?></strong></figcaption></figure><?php endforeach ?>
  </div>
</section>

<section class="wrap lp-section" id="cenik">
  <h2>Kolik to stojí</h2>
  <?php $edView = 'landing'; $edCompact = true; require __DIR__ . '/_editions.php'; ?>
  <p class="lp-more"><a href="<?= url('/cenik') ?>">Podrobný ceník, limity a doplňky <?= icon('arrow') ?></a></p>
</section>

<section class="wrap lp-section narrow" id="otazky">
  <h2>Časté otázky</h2>
  <details class="lp-faq"><summary>Je web opravdu zdarma?</summary><p>Ano. Edice Zdarma nemá časové omezení a nechceme po Vás platební kartu. Má limity (počet účtů, správců a hromadných e-mailů) a v patičce odkaz na službu. Když limit vyčerpáte, web běží dál.</p></details>
  <details class="lp-faq"><summary>Kdo uvidí data našeho společenství?</summary><p>Každé společenství má vlastní oddělenou databázi, soubory i přihlašování. Co je veřejné a co jen pro přihlášené obyvatele, určuje Váš výbor.</p></details>
  <details class="lp-faq"><summary>Můžeme web přestat používat a data si odnést?</summary><p>Ano. Správce si kdykoli stáhne úplnou zálohu webu včetně dokumentů.</p></details>
  <p class="lp-more"><a href="<?= url('/cenik') ?>#otazky">Další otázky k ceně a placení <?= icon('arrow') ?></a></p>
  <div class="actions lp-final"><?= $cta() ?></div>
</section>

<?php $op = operator(); if ($op['name'] !== ''): ?>
<section class="wrap lp-section narrow" id="provozovatel">
  <h2 class="lp-small-head">Kdo službu provozuje</h2>
  <div class="lp-operator">
    <p><strong><?= e($op['name']) ?></strong><br><?= e($op['address']) ?><?= $op['ico'] !== '' ? '<br>IČO ' . e($op['ico']) : '' ?><?= $op['register'] !== '' ? '<br><small class="muted">' . e($op['register']) . '</small>' : '' ?></p>
    <p><?= $op['email'] !== '' ? '<a href="mailto:' . e($op['email']) . '">' . e($op['email']) . '</a><br>' : '' ?><?= $op['phone'] !== '' ? e($op['phone']) . '<br>' : '' ?>
      <a href="<?= url('/podminky') ?>">Obchodní podmínky</a> · <a href="<?= url('/zpracovani-udaju') ?>">Zpracování osobních údajů</a></p>
  </div>
</section>
<?php endif ?>

