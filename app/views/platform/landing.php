<?php
$first = money_czk(pset_int('price_first', 0, 1000000)); $next = money_czk(pset_int('price_next', 0, 1000000)); $per = period_label(); $trial = pset_int('trial_days', 0, 365);
$cta = fn(string $class = 'btn btn-big') => $open ? '<a class="' . $class . '" href="' . url('/registrace') . '">Vyzkoušet ' . ($trial ? $trial . ' dní zdarma ' : '') . icon('arrow') . '</a>' : '';
$features = [
    ['Aktuality a dokumenty', 'Oznámení výboru, zápisy, stanovy a vyúčtování na jednom místě. Veřejné, jen pro přihlášené, nebo jen pro konkrétní jednotku.'],
    ['E-maily vlastníkům', 'Pozvánku na shromáždění rozešlete jedním kliknutím a vidíte, kdo ji otevřel.'],
    ['Shromáždění', 'Vlastníci potvrdí účast na webu, Vy průběžně vidíte usnášeníschopnost. Plná moc a prezenční listina k tisku.'],
    ['Hlasování', 'Hlasování per rollam i zápis hlasů ze shromáždění, počítané podle podílů.'],
    ['Závady a požadavky', 'Obyvatelé nahlásí závadu, výbor ji vyřídí a každý vidí, v jakém je stavu.'],
    ['Jednotky a vlastníci', 'Seznam jednotek s podíly, import vlastníků z katastru, pozvánky pro členy domácnosti.'],
    ['Odečty, rezervace, klíče', 'Samoodečty měřidel, rezervace společných prostor, evidence klíčů a čipů, termíny revizí.'],
    ['Bezpečnost a zálohy', 'Dvoufázové přihlášení, nesmazatelné záznamy o práci správců a záloha celého webu jedním tlačítkem.'],
    ...(ai_configured() ? [['AI pomocník v ceně', 'Z pár poznámek napíše oznámení nebo e-mail vlastníkům, uhladí text a navrhne odpověď na nahlášenou závadu. Bez příplatku.']] : []),
    ['Pět jazyků', 'Čeština, slovenština, angličtina, ukrajinština a vietnamština – každý obyvatel si přepne sám.'],
];
?>
<section class="hero lp-hero">
  <div class="wrap hero-grid">
    <div class="hero-text">
      <p class="eyebrow">Pro výbory a správce SVJ</p>
      <h1>Web Vašeho SVJ <span class="h1-sub">za pět minut</span></h1>
      <p class="lead">Aktuality, dokumenty, e-maily vlastníkům, shromáždění, hlasování i hlášení závad. Zvolíte adresu a vzhled, vytvoříte si účet a web běží.</p>
      <div class="actions"><?= $cta() ?><?php if ($demo): ?><a class="btn btn-big btn-ghost" href="<?= e(tenant_url($demo)) ?>">Prohlédnout ukázkový web</a><?php else: ?><a class="btn btn-big btn-ghost" href="#cenik">Ceník</a><?php endif ?></div>
      <?php if ($open): ?><p class="lp-note"><?= $trial ? 'Prvních ' . $trial . ' dní zdarma, bez platební karty. ' : '' ?>Potom <?= $first ?> za <?= $per ?>.</p>
      <?php else: ?><p class="lp-note">Nová společenství teď zakládáme po domluvě<?= pset('contact_email') !== '' ? ' – napište na ' . e(pset('contact_email')) : '' ?>.</p><?php endif ?>
    </div>
    <div class="hero-side lp-stack">
      <?php $look = 'les'; $miniName = 'Zahradní 12'; require __DIR__ . '/_mini.php'; $look = 'more'; $miniName = 'Na Vyhlídce 8'; require __DIR__ . '/_mini.php'; ?>
    </div>
  </div>
</section>

<section class="wrap lp-section" id="funkce">
  <h2>Co web umí</h2>
  <p class="lead">Všechno, co výbor běžně řeší, bez instalace a bez programátora.</p>
  <ul class="lp-features">
    <?php foreach ($features as [$h, $p]): ?><li><h3><?= e($h) ?></h3><p><?= e($p) ?></p></li><?php endforeach ?>
  </ul>
</section>

<section class="lp-band"><div class="wrap lp-section">
  <h2>Jak začít</h2>
  <ol class="lp-steps">
    <li><strong>Zvolte název a adresu</strong><span>Například <code><?= e(tenant_address('zahradni')) ?></code>.</span></li>
    <li><strong>Vyberte vzhled</strong><span>Pět barevných návrhů; kdykoli ho změníte a nahrajete vlastní logo.</span></li>
    <li><strong>Vytvořte si účet správce</strong><span>Hned potom jste v administraci a můžete zvát vlastníky.</span></li>
  </ol>
  <div class="actions"><?= $cta() ?><?php if ($demo): ?><a class="btn btn-big btn-ghost" href="<?= e(tenant_url($demo)) ?>">Nejdřív si prohlédnout ukázku</a><?php endif ?></div>
</div></section>

<section class="wrap lp-section" id="vzhledy">
  <h2>Pět vzhledů</h2>
  <p class="lead">Všechny se přizpůsobí telefonu i tmavému režimu.</p>
  <div class="lp-looks">
    <?php foreach (LOOKS as $look => $label): $miniName = null; ?><figure><?php require __DIR__ . '/_mini.php'; ?><figcaption><?= e($label) ?></figcaption></figure><?php endforeach ?>
  </div>
</section>

<section class="lp-band" id="cenik"><div class="wrap lp-section">
  <h2>Ceník</h2>
  <div class="lp-pricing">
    <div class="card price-card">
      <p class="eyebrow">Provoz webu</p>
      <ul class="price-rows">
        <?php if ($trial): ?><li><span>Prvních <?= $trial ?> dní</span><strong>zdarma</strong></li><?php endif ?>
        <li><span>První rok</span><strong><?= $first ?> <small>/ <?= $per ?></small></strong></li>
        <li><span>Další roky</span><strong><?= $next ?> <small>/ <?= $per ?></small></strong></li>
      </ul>
      <p class="hint">Jedna cena za celé společenství, bez omezení počtu jednotek a účtů. Platí se převodem na zálohovou fakturu s QR kódem; po úhradě dostanete fakturu.</p>
      <div class="actions"><?= $cta('btn') ?></div>
    </div>
    <div>
      <h3>Podpora a úpravy</h3>
      <table class="table price-table"><tbody>
        <?php if (ai_configured()): ?><tr><td>AI pomocník pro výbor</td><td class="t-actions"><strong>v ceně</strong></td></tr><?php endif ?>
        <?php foreach (preg_split('/\R/', pset('pricelist')) as $line): if (trim($line) === '') { continue; } [$item, $price] = array_map('trim', explode('|', $line . '|')); ?>
          <tr><td><?= e($item) ?></td><td class="t-actions"><strong><?= e($price) ?></strong></td></tr>
        <?php endforeach ?>
      </tbody></table>
    </div>
  </div>
</div></section>

<?php $op = operator(); if ($op['name'] !== ''): ?>
<section class="wrap lp-section" id="provozovatel">
  <h2>Kdo službu provozuje</h2>
  <div class="lp-operator">
    <p><strong><?= e($op['name']) ?></strong><br><?= e($op['address']) ?><?= $op['ico'] !== '' ? '<br>IČO ' . e($op['ico']) : '' ?><?= $op['register'] !== '' ? '<br><small class="muted">' . e($op['register']) . '</small>' : '' ?></p>
    <p><?= $op['email'] !== '' ? '<a href="mailto:' . e($op['email']) . '">' . e($op['email']) . '</a><br>' : '' ?><?= $op['phone'] !== '' ? e($op['phone']) . '<br>' : '' ?>
      <a href="<?= url('/podminky') ?>">Obchodní podmínky</a> · <a href="<?= url('/zpracovani-udaju') ?>">Zpracování osobních údajů</a></p>
  </div>
</section>
<?php endif ?>

<section class="wrap lp-section narrow" id="otazky">
  <h2>Časté otázky</h2>
  <details class="lp-faq"><summary>Co se stane po zkušební době?</summary><p>V administraci najdete platební údaje s QR kódem a před koncem zkušební doby Vám přijde připomenutí e-mailem. Když se rozhodnete nepokračovat, nic neplatíte – web se po <?= pset_int('grace_days', 0, 365) ?> dnech pozastaví.</p></details>
  <details class="lp-faq"><summary>Jak se platí?</summary><p>Bankovním převodem. V administraci najdete zálohovou fakturu s QR kódem; po připsání platby vystavíme fakturu. Platba kartou zatím není k dispozici.</p></details>
  <details class="lp-faq"><summary>Kdo uvidí data našeho společenství?</summary><p>Každé společenství má vlastní oddělenou databázi, soubory i přihlašování. Co je veřejné a co jen pro přihlášené obyvatele, určuje Váš výbor.</p></details>
  <?php if (pset('hosting_note') !== ''): ?><details class="lp-faq"><summary>Kde jsou data uložena?</summary><p><?= e(pset('hosting_note')) ?></p></details><?php endif ?>
  <details class="lp-faq"><summary>Můžeme web přestat používat a data si odnést?</summary><p>Ano. Správce si kdykoli stáhne úplnou zálohu webu včetně dokumentů – i po skončení předplatného.</p></details>
  <details class="lp-faq"><summary>Můžeme mít vlastní doménu?</summary><p>Ano, web může běžet i na Vaší doméně. Nastavení je v ceníku jako samostatná položka.</p></details>
  <div class="actions lp-final"><?= $cta() ?></div>
</section>
