<?php $cta = fn(string $class = 'btn btn-big') => $open ? '<a class="' . $class . '" href="' . url('/registrace') . '">Založit web zdarma ' . icon('arrow') . '</a>' : ''; ?>
<section class="wrap lp-section lp-page-head" id="cenik">
  <h1>Ceník</h1>
  <p class="lead">Web společenství je zdarma pro každého a bez časového omezení. Platíte až ve chvíli, kdy Vám přestanou stačit limity nebo chcete funkce navíc – a každou placenou funkci si předtím na <?= PLAN_TRIAL_DAYS ?> dní zdarma vyzkoušíte.</p>
  <?php $edView = 'landing'; require __DIR__ . '/_editions.php'; ?>
  <p class="hint">Po dosažení limitu web běží dál a nic se nemaže – jen nejde přidat další účet nebo odeslat další hromadný e-mail. Platí se ročně převodem na zálohovou fakturu s QR kódem; po úhradě dostanete fakturu.</p>

  <h3 class="lp-sub">Doplňky a služby</h3>
  <table class="table price-table"><tbody>
    <tr><td><strong>Vlastní doména</strong><br><small class="muted">Web na adrese jako tabor48.cz; doménu zaregistrujeme, nebo připojíme Vaši. Od edice Standard, v Premium v ceně.</small></td><td class="t-actions"><strong><?= money_czk(pset_int('price_domain', 0, 1000000)) ?> / rok</strong></td></tr>
    <?php if (pset_int('price_storage', 0, 1000000)): ?><tr><td><strong>Úložiště navíc</strong><br><small class="muted">Když dokumentům přestane stačit místo edice. V kterékoli edici, objednává se v administraci.</small></td><td class="t-actions"><strong><?= pset_int('storage_pack_mb', 1, 100000) ?> MB za <?= money_czk(pset_int('price_storage', 0, 1000000)) ?> / rok</strong></td></tr><?php endif ?>
    <?php if (pset_int('referral_reward', 0, 100000)): ?><tr><td><strong>Sleva za doporučení</strong><br><small class="muted">Doporučte web jinému společenství. Když si zaplatí edici, odečteme Vám slevu z příští roční platby<?= pset_int('referral_welcome', 0, 100000) ? ' a jim ' . money_czk(pset_int('referral_welcome', 0, 100000)) . ' z první' : '' ?>. Odkaz najdete v administraci.</small></td><td class="t-actions"><strong>− <?= money_czk(pset_int('referral_reward', 0, 100000)) ?> za každé</strong></td></tr><?php endif ?>
    <tr><td><strong>Převod obsahu ze starého webu</strong><br><small class="muted">Aktuality, dokumenty, kontakty a texty přeneseme za Vás. Objednává se v administraci, v kterékoli edici; hotovo do 5 pracovních dnů od platby.</small></td><td class="t-actions"><strong><?= money_czk(pset_int('price_migration', 0, 1000000)) ?> jednorázově</strong></td></tr>
    <?php foreach (preg_split('/\R/', pset('pricelist')) as $line): if (trim($line) === '' || str_contains($line, 'v ceně') || str_contains(mb_strtolower($line), 'převod obsahu') || str_contains(mb_strtolower($line), 'vlastní doména')) { continue; } [$item, $price] = array_map('trim', explode('|', $line . '|')); ?>
      <tr><td><?= e($item) ?></td><td class="t-actions"><strong><?= e($price) ?></strong></td></tr>
    <?php endforeach ?>
  </tbody></table>

  <h3 class="lp-sub" id="pripravujeme">Připravujeme</h3>
  <p class="muted">Na těchto funkcích pracujeme. Klikněte na tu, která Vás zajímá – dozvíte se, co přesně bude dělat a jaký problém řeší. Podle zájmu rozhodujeme, co udělat dřív.</p>
  <ul class="planned">
    <?php foreach (PLANNED_FEATURES as $key => [$name, $desc, $price]): ?>
    <li><a href="<?= url('/pripravujeme/' . $key) ?>"><span class="planned-head"><strong><?= e($name) ?></strong> <span class="tag">již brzy</span></span>
      <span class="planned-desc"><?= e($desc) ?></span>
      <span class="planned-foot"><small class="muted"><?= e($price) ?></small><span class="planned-more">Více o funkci →</span></span></a></li>
    <?php endforeach ?>
  </ul>
</section>

<section class="wrap lp-section narrow" id="otazky">
  <h2>Časté otázky k ceně</h2>
  <details class="lp-faq"><summary>Je web opravdu zdarma?</summary><p>Ano. Edice Zdarma nemá časové omezení a nechceme po Vás platební kartu. Má limity (počet účtů, správců a hromadných e-mailů) a v patičce odkaz na službu. Když limit vyčerpáte, web běží dál.</p></details>
  <details class="lp-faq"><summary>Jak funguje zkoušení placených funkcí?</summary><p>Každou funkci z vyšší edice si v administraci jedním kliknutím zapnete na <?= PLAN_TRIAL_DAYS ?> dní. Po skončení zkoušky zůstane vše, co jste zapsali, uložené a viditelné; měnit to půjde, až přejdete na edici, která funkci obsahuje.</p></details>
  <details class="lp-faq"><summary>Prodlužuje se předplatné samo?</summary><p>Ano. Před koncem období Vám připravíme zálohovou fakturu na další období a pošleme upozornění. Nic se ale nestrhává automaticky – když nechcete pokračovat, vypnete prodlužování v administraci, nebo fakturu prostě nezaplatíte. Web pak po <?= pset_int('grace_days', 0, 365) ?> dnech přejde zpět na edici Zdarma; nic se nemaže a nevzniká Vám dluh.</p></details>
  <details class="lp-faq"><summary>Jak se platí?</summary><p>Bankovním převodem. V administraci najdete zálohovou fakturu s QR kódem; po připsání platby vystavíme fakturu. Platba kartou zatím není k dispozici.</p></details>
  <details class="lp-faq"><summary>Kdo uvidí data našeho společenství?</summary><p>Každé společenství má vlastní oddělenou databázi, soubory i přihlašování. Co je veřejné a co jen pro přihlášené obyvatele, určuje Váš výbor.</p></details>
  <?php if (pset('hosting_note') !== ''): ?><details class="lp-faq"><summary>Kde jsou data uložena?</summary><p><?= e(pset('hosting_note')) ?></p></details><?php endif ?>
  <details class="lp-faq"><summary>Můžeme web přestat používat a data si odnést?</summary><p>Ano. V placené edici si správce stáhne úplnou zálohu webu včetně dokumentů; v edici Zdarma si zálohu může jednou na 30 dní zdarma zapnout. Každý uživatel si své vlastní údaje stáhne kdykoli a web lze v administraci zrušit.</p></details>
  <details class="lp-faq"><summary>Můžeme mít vlastní doménu?</summary><p>Ano, web může běžet i na Vaší doméně. Nastavení je v ceníku jako samostatná položka.</p></details>
  <div class="actions lp-final"><?= $cta() ?></div>
</section>
