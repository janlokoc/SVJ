<?php /* Veřejná stránka služby: Bezpečnost a ochrana dat. $s (moda_security_facts()) */
$op = $s['operator']; ?>
<section class="lpx-hero lpx-hero-plain">
  <div class="wrap">
    <p class="lpx-eyebrow">Bezpečnost a ochrana dat</p>
    <h1>Data Vašeho domu jsou v bezpečí – a patří Vám.</h1>
    <p class="lpx-lead">Stručně a konkrétně: kde data leží, jak je chráníme, kdo k nim má přístup a jak si je kdykoli odnesete.</p>
  </div>
</section>
<section class="lpx-section">
  <div class="wrap">
    <div class="mgr-grid">
      <div class="card"><h3>Kde jsou data uložena</h3><p><?= e($s['hosting']) ?> Data se neprodávají, nepoužívají k reklamě ani k profilování.</p></div>
      <div class="card"><h3>Zálohy</h3><?php if ($s['backup']): ?><p>Každý den se pořizuje úplná záloha všech dat. Hned po stažení se <strong>zašifruje</strong> a uloží mimo hosting; rozšifrovat ji může jen provozovatel svým soukromým klíčem. Zálohy se drží 30 dní.</p><?php else: ?><p>Data se zálohují v rámci hostingu. Výbor si navíc může kdykoli stáhnout vlastní export všech dat (viz níže).</p><?php endif ?></div>
      <div class="card"><h3>Každé SVJ odděleně</h3><p>Každé společenství má <strong>vlastní databázi</strong>, vlastní složku se soubory a vlastní přihlašování. Přihlášení platí jen pro web jednoho domu – účtem z jednoho SVJ se do jiného nedostanete.</p></div>
      <div class="card"><h3>Přihlášení a hesla</h3><p>Šifrované spojení (HTTPS), hesla uložená jen jako otisk, ochrana proti hádání hesel, dvoufázové ověření kódem z telefonu a přihlášení jednorázovým odkazem z e-mailu. Po nečinnosti se uživatel sám odhlásí.</p></div>
      <div class="card"><h3>Kdo co udělal</h3><p>Změny v administraci se zapisují do záznamů, které nejde dodatečně upravit (každý záznam navazuje otiskem na předchozí). Výbor je vidí v administraci.</p></div>
      <div class="card"><h3>Podpora jen pro čtení</h3><p>Do webu domu vstupujeme jen při řešení podpory, a to v režimu, ve kterém nejde nic změnit ani odeslat. Každý vstup se zapíše do záznamů webu.</p></div>
    </div>
  </div>
</section>
<section class="lpx-section">
  <div class="wrap">
    <h2>Role podle GDPR</h2>
    <ul class="mgr-flow">
      <li><strong>Správcem</strong> osobních údajů je společenství vlastníků (výbor rozhoduje, co se na web vloží a kdo co vidí).</li>
      <li><strong>Zpracovatelem</strong> je provozovatel služby<?= $op['name'] !== '' ? ' – ' . e($op['name']) . ($op['ico'] !== '' ? ', IČO ' . e($op['ico']) : '') : '' ?>. Vztah upravuje <a href="<?= url('/zpracovani-udaju') ?>">smlouva o zpracování osobních údajů</a>; výbor si ji v administraci stáhne předvyplněnou údaji svého společenství (Správa webu → Data a smlouva).</li>
      <li><strong>Další zpracovatelé:</strong> poskytovatel serverů a služba pro odesílání e-mailů. Text vložený do funkcí s umělou inteligencí (jen pokud je výbor používá) zpracuje poskytovatel AI; upozornění do telefonu doručují push služby prohlížečů v zašifrované podobě.</li>
    </ul>
  </div>
</section>
<section class="lpx-section">
  <div class="wrap">
    <h2>Vaše data si kdykoli odnesete</h2>
    <ul class="mgr-flow">
      <li><strong>Export všech dat domu:</strong> správce webu v administraci (Správa webu → Data a smlouva) stáhne ZIP se všemi daty v otevřeném formátu JSON a se všemi nahranými soubory – v každé edici, v edici Zdarma jednou za 30 dní.</li>
      <li><strong>Vlastní údaje:</strong> každý uživatel si v Můj účet → Moje údaje a soukromí stáhne vše, co o něm web ukládá, a může účet zrušit.</li>
      <li><strong>Konec služby:</strong> web lze v administraci zrušit; před smazáním dostanete možnost stáhnout zálohu.</li>
    </ul>
    <p class="muted">Podrobnosti: <a href="<?= url('/ochrana-udaju') ?>">Ochrana osobních údajů</a> · <a href="<?= url('/zpracovani-udaju') ?>">Smlouva o zpracování údajů</a> · <a href="<?= url('/podminky') ?>">Obchodní podmínky</a><?= $op['email'] !== '' ? ' · dotazy: <a href="mailto:' . e($op['email']) . '">' . e($op['email']) . '</a>' : '' ?></p>
  </div>
</section>
