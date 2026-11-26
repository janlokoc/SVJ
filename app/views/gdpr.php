<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/muj-ucet') ?>">← Můj účet</a></p>
  <header class="page-head"><h1>Moje údaje a soukromí</h1>
    <p class="lead">Co o Vás tento web ukládá a jak s tím můžete naložit. Správcem údajů je společenství, kterému web patří; podrobnosti jsou na stránce <a href="<?= url('/ochrana-udaju') ?>">Ochrana osobních údajů</a>.</p></header>

  <section class="card">
    <h2>Co o Vás ukládáme</h2>
    <dl class="pay-data">
      <?php foreach ($account as $k => $v): if ($v === null || $v === '') { continue; } ?><dt><?= e(mb_strtoupper(mb_substr($k, 0, 1)) . mb_substr($k, 1)) ?></dt><dd><?= is_bool($v) ? ($v ? 'ano' : 'ne') : e((string) $v) ?></dd><?php endforeach ?>
    </dl>
    <p class="muted">A dále, co jste na webu sami zapsali:
      <?= implode(', ', array_map(fn($k, $n) => '<span>' . e($k . ' (' . $n . ')') . '</span>', array_keys($counts), $counts)) ?>.</p>
    <div class="actions"><a class="btn" href="<?= url('/muj-ucet/moje-udaje.json') ?>" download>Stáhnout všechny moje údaje</a></div>
    <p class="hint">Soubor ve formátu JSON obsahuje vše výše včetně textů zpráv a příspěvků. Otevřete ho v libovolném textovém editoru nebo ho předáte jinému správci (právo na přístup a na přenositelnost údajů).</p>
  </section>

  <section class="card">
    <h2>Vaše práva</h2>
    <ul class="rights">
      <li><strong>Oprava.</strong> Jméno, telefon a heslo změníte ve <a href="<?= url('/muj-ucet') ?>">svém účtu</a>. E-mail a přiřazení k jednotce opraví výbor.</li>
      <li><strong>Přístup a přenositelnost.</strong> Všechny údaje si stáhnete tlačítkem výše.</li>
      <li><strong>Námitka a omezení.</strong> E-maily od výboru<?= feature_on('zpravy') ? ' i zprávy od sousedů' : '' ?> vypnete ve svém účtu. S dalšími požadavky se obraťte na výbor – kontakt je na stránce <a href="<?= url('/kontakty') ?>">Kontakty</a>.</li>
      <li><strong>Výmaz.</strong> Účet si můžete zrušit níže.</li>
      <li><strong>Stížnost.</strong> Můžete ji podat u Úřadu pro ochranu osobních údajů (www.uoou.cz).</li>
    </ul>
  </section>

  <section class="card danger-zone" id="smazat">
    <h2>Zrušit můj účet</h2>
    <p>Smaže se Váš účet, kontaktní údaje, zprávy se sousedy, rezervace a přiřazení k jednotce. <strong>Nejde to vrátit.</strong></p>
    <p class="muted">Co zůstane: příspěvky v diskuzi a nahlášené závady bez Vašeho jména, hlasy a potvrzení účasti Vaší jednotky (jsou součástí zápisů ze shromáždění, které společenství musí uchovávat), údaje o vlastníkovi jednotky z katastru nemovitostí a provozní záznamy o přihlášení (kvůli bezpečnosti).</p>
    <?php if ($lastAdmin): ?>
      <p class="note">Jste jediný správce webu, proto svůj účet zrušit nemůžete – web by zůstal bez správy. Nejdřív předejte správu někomu dalšímu<?= plan_managed() && ($GLOBALS['BILLING']['mode'] ?? 'free') !== 'free' ? ', nebo zrušte celý web v části <a href="' . url('/admin/predplatne') . '#zrusit">Předplatné</a>' : '' ?>.</p>
    <?php else: ?>
    <form method="post" action="<?= url('/muj-ucet/smazat-ucet') ?>" class="form" data-confirm="Opravdu nenávratně zrušit Váš účet?">
      <?= csrf_field() ?>
      <label>Vaše heslo <input type="password" name="password" required autocomplete="current-password"></label>
      <label class="check"><input type="checkbox" name="understand" required> <span>Rozumím, že účet nepůjde obnovit.</span></label>
      <div class="actions"><button class="btn btn-danger" type="submit">Zrušit můj účet a smazat údaje</button></div>
    </form>
    <?php endif ?>
  </section>
</div>
