<div class="wrap page narrow">
  <p class="crumbs"><a href="<?= url('/') ?>">← Společenství</a></p>
  <h1><?= e($title) ?></h1>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <?php if (!$new): ?>
    <p class="lead">Web: <a href="<?= e(tenant_url($t)) ?>"><?= e(rtrim(tenant_url($t), '/')) ?></a><?php if ($stats['ready']): ?> · účtů <?= $stats['users'] ?> · jednotek <?= $stats['units'] ?> · dokumentů <?= $stats['documents'] ?><?php endif ?></p>
    <?php if ($invite): ?><div class="card card-soft credentials"><h2>Pozvánka pro správce: <?= e($invite['name']) ?></h2><p>Předejte mu tento odkaz – <strong>zobrazuje se jen teď</strong>, platí 14 dní. Nastaví si přes něj heslo.</p><p><code><?= e($invite['link']) ?></code></p></div><?php endif ?>
    <?php if ($stats['ready'] && !$stats['admins']): ?>
      <div class="flash flash-info">Web zatím nemá žádného správce.<?php if ($setupCode): ?> Výbor si ho může vytvořit sám na adrese webu instalačním kódem <code><?= e($setupCode) ?></code>, nebo mu účet založte níže.<?php endif ?></div>
    <?php endif ?>
  <?php endif ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <?php if ($new): ?>
      <label>Adresa webu <span class="slug-row"><?php if (platform_paths()): ?><span><?= e(platform_domain()) ?>/</span><?php endif ?><input type="text" name="slug" data-slug-fixed="<?= e(platform_domain()) ?>" aria-describedby="slug-fixed-note" id="t-slug" value="<?= e($t['slug']) ?>" pattern="[a-z0-9][a-z0-9\-]{1,38}" required placeholder="zahradecka"><?php if (!platform_paths()): ?><span>.<?= e(platform_domain()) ?></span><?php endif ?></span></label>
      <p class="hint">Jen malá písmena bez diakritiky, číslice a pomlčka. Adresu později nejde změnit.</p>
    <?php endif ?>
    <label>Název společenství <input type="text" name="name" value="<?= e($t['name']) ?>" required placeholder="SVJ Zahrádecká 372"></label>
    <div class="form-row">
      <label>Krátký název na web <input type="text" name="title" value="<?= e($t['title']) ?>" required placeholder="Zahrádecká 372"></label>
      <label>Doplněk názvu (nepovinné) <input type="text" name="accent" value="<?= e($t['accent']) ?>" placeholder="a–d"></label>
    </div>
    <label>Celý název (patička, plná moc) <input type="text" name="full" value="<?= e($t['full']) ?>" placeholder="Společenství vlastníků jednotek Zahrádecká 372"></label>
    <label>Řádek nad názvem <input type="text" name="tagline" value="<?= e($t['tagline']) ?>" placeholder="Společenství vlastníků jednotek · Praha"></label>
    <label>Adresa domu <input type="text" name="address" value="<?= e($t['address']) ?>" placeholder="Zahrádecká 372/4, Praha 4"></label>
    <div class="form-row">
      <label>Číslo před označením jednotky <input type="text" name="unit_prefix" value="<?= e($t['unit_prefix']) ?>" maxlength="10" placeholder="372"></label>
      <label>Vchody (oddělené čárkou) <input type="text" name="entrances" value="<?= e($t['entrances']) ?>" placeholder="A,B,C"></label>
    </div>
    <p class="hint">Jednotka se pak zobrazuje například jako 372A/5.</p>
    <div class="form-row">
      <label>Šablona <select name="style"><?php foreach (STYLES as $k => $label): ?><option value="<?= $k ?>"<?= ($t['style'] ?? 'klasik') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
      <label>Barvy <select name="look"><?php foreach (LOOKS as $k => $label): ?><option value="<?= $k ?>"<?= $t['look'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
      <label>Jazyk webu <select name="lang"><?php foreach (LANGS as $k => $label): ?><option value="<?= $k ?>"<?= $t['lang'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
    </div>
    <label>Vlastní doména (nepovinné) <input type="text" name="custom_domain" value="<?= e($t['custom_domain']) ?>" placeholder="www.svj-priklad.cz"></label>
    <p class="hint">Doménu musí její majitel nasměrovat na tento server; web pak poběží na ní i na adrese platformy.</p>
    <label>Interní poznámka <input type="text" name="note" value="<?= e($t['note']) ?>"></label>
    <?php if ($new): ?><label class="check"><input type="checkbox" name="seed_tabor"> <span>Naplnit ukázkovým obsahem SVJ Tábor 48 <small>Dokumenty, kontakty a aktuality z původního webu svjtabor48.webnode.cz. Pro ostatní společenství nechte vypnuté.</small></span></label><?php endif ?>
    <h2>Předplatné</h2>
    <label class="check"><input type="checkbox" name="billing"<?= $t['billing'] ? ' checked' : '' ?>> <span>Web je placený <small>Správce společenství uvidí stránku Předplatné s výzvou k platbě. Po skončení zkušební doby nebo předplatného a lhůtě po splatnosti se web sám pozastaví. Vypnuté = web běží bez placení.</small></span></label>
    <div class="form-row">
      <label>Zkušební doba do <input type="date" name="trial_ends" value="<?= e($t['trial_ends']) ?>"></label>
      <label>Zaplaceno do <input type="date" name="paid_until" value="<?= e($t['paid_until']) ?>"></label>
    </div>
    <div class="form-row">
      <label>Kontaktní osoba <input type="text" name="owner_name" value="<?= e($t['owner_name']) ?>"></label>
      <label>E-mail pro připomenutí plateb <input type="email" name="owner_email" value="<?= e($t['owner_email']) ?>"></label>
    </div>
    <label class="check"><input type="checkbox" name="active"<?= $t['active'] ? ' checked' : '' ?>> <span>Web běží <small>Pozastavený web se návštěvníkům nezobrazí; data zůstávají.</small></span></label>
    <div class="actions"><button class="btn btn-big" type="submit"><?= $new ? 'Založit web' : 'Uložit' ?></button><a class="btn btn-ghost" href="<?= url('/') ?>">Zrušit</a></div>
  </form>
  <?php if (!$new): ?>
  <?php if ($raw['domain_status'] !== ''): ?>
  <div class="card" id="domena">
    <h2>Vlastní doména (placený doplněk)</h2>
    <p><strong><?= e($raw['domain_request']) ?></strong> · <?= $raw['domain_status'] === 'active' ? '<span class="status status-done">zprovozněna</span>' : '<span class="status status-new">žádost</span>' ?>
      <?= $raw['domain_until'] !== '' ? ' · zaplaceno do ' . cz_date($raw['domain_until']) : ' · zatím nezaplaceno' ?>
      <?= $domainPayment ? ' · poslední platba: ' . e(PAYMENT_STATUS[$domainPayment['status']]) . ' (VS ' . e($domainPayment['vs']) . ')' : '' ?></p>
    <p class="muted">DNS: <?= $domainDns === null ? 'nepodařilo se zjistit, zda je doména registrovaná.' : ($domainDns ? 'doména je registrovaná – má ji společenství, nebo ji musíte nasměrovat na tento server.' : 'doména vypadá volná – je potřeba ji zaregistrovat.') ?></p>
    <?php if ($raw['domain_status'] === 'requested'): ?>
      <ol class="hint"><li>Počkejte na platbu a potvrďte ji v části Platby.</li><li>Doménu zaregistrujte (nebo nechte společenství nasměrovat tu jejich) na tento server.</li><li>Na serveru pro ni zapněte HTTPS.</li><li>Teprve potom ji zde zprovozněte.</li></ol>
      <div class="actions"><form method="post" action="<?= url('/svj/' . $t['slug'] . '/domena') ?>" data-confirm="Doména už směruje na tento server a má certifikát HTTPS?"><?= csrf_field() ?><input type="hidden" name="do" value="activate"><button class="btn" type="submit">Zprovoznit doménu</button></form>
    <?php else: ?><div class="actions"><?php endif ?>
      <form method="post" action="<?= url('/svj/' . $t['slug'] . '/domena') ?>" data-confirm="Odpojit vlastní doménu od tohoto webu?"><?= csrf_field() ?><input type="hidden" name="do" value="remove"><button class="btn btn-ghost" type="submit"><?= $raw['domain_status'] === 'active' ? 'Odpojit doménu' : 'Zamítnout žádost' ?></button></form></div>
  </div>
  <?php endif ?>
  <?php if ($deleteOn || $notices): ?>
  <div class="card">
    <h2>Upozornění odeslaná společenství</h2>
    <?php if ($deleteOn): ?><div class="flash flash-info">Web po zkušební době nezaplatil. Automaticky se smaže <strong><?= cz_date($deleteOn) ?></strong>, nejdříve však 3 dny po odeslaném upozornění. Smazání zabrání přijatá nebo nahlášená platba, prodloužení zkušební doby nebo vypnutí „Web je placený“.</div><?php endif ?>
    <?php if (!$notices): ?><p class="muted">Zatím nebylo odesláno žádné upozornění.</p><?php else: ?>
    <table class="table"><thead><tr><th>Kdy</th><th>Upozornění</th><th>Komu</th><th>Stav</th></tr></thead><tbody>
      <?php foreach ($notices as $n): ?><tr><td data-label="Kdy"><?= cz_datetime($n['at']) ?></td><td data-label="Upozornění"><?= e(NOTICE_KINDS[$n['kind']] ?? $n['kind']) ?></td><td data-label="Komu"><?= e($n['email'] !== '' ? $n['email'] : '—') ?></td>
        <td data-label="Stav"><?= $n['sent'] ? '<span class="status status-done">odesláno</span>' : '<span class="status status-new">neodesláno</span>' ?></td></tr><?php endforeach ?>
    </tbody></table>
    <p class="hint">„Neodesláno“ znamená, že chybí e-mail kontaktní osoby, není nastaven odesílatel e-mailů služby, nebo server zprávu nepřijal.</p>
    <?php endif ?>
  </div>
  <?php endif ?>
  <?php if ($payments): ?>
  <div class="card"><h2>Platby tohoto společenství</h2>
    <table class="table"><thead><tr><th>Var. symbol</th><th>Částka</th><th>Období</th><th>Stav</th></tr></thead><tbody>
    <?php foreach ($payments as $p): ?><tr><td data-label="Var. symbol"><code><?= e($p['vs']) ?></code></td><td data-label="Částka"><?= money_czk((int) $p['amount']) ?></td>
      <td data-label="Období"><?= $p['period_from'] ? cz_date($p['period_from']) . ' – ' . cz_date($p['period_to']) : '—' ?></td><td data-label="Stav"><?= e(PAYMENT_STATUS[$p['status']]) ?></td></tr><?php endforeach ?>
    </tbody></table>
    <p><a href="<?= url('/platby') ?>">Potvrdit nebo zrušit platbu →</a></p>
  </div>
  <?php endif ?>
  <form method="post" action="<?= url('/svj/' . $t['slug'] . '/spravce') ?>" class="card form">
    <h2>Přidat správce tohoto webu</h2>
    <?= csrf_field() ?>
    <div class="form-row"><label>Jméno a příjmení <input type="text" name="name" id="ta-name" required></label><label>E-mail <input type="email" name="email" id="ta-email" required></label></div>
    <button class="btn" type="submit">Vytvořit účet a zobrazit pozvánku</button>
    <p class="hint">Do obsahu webu společenství hlavní správce nevidí; přístup má jen jeho výbor. Vytvoření účtu správce se zapisuje do záznamů platformy.</p>
  </form>
  <form method="post" action="<?= url('/svj/' . $t['slug'] . '/import') ?>" enctype="multipart/form-data" class="card form">
    <h2>Import obsahu ze starého webu</h2>
    <?= csrf_field() ?>
    <p>Nahraje aktuality, dokumenty, kontakty a název ze souboru připraveného převodem původního webu (formát JSON). Účty, jednotky ani hlasování se nemění.</p>
    <label>Soubor s obsahem <input type="file" name="file" accept=".json,application/json" required></label>
    <label class="check"><input type="checkbox" name="replace" checked> <span>Nahradit dosavadní aktuality, dokumenty a kontakty <small>Hodí se u nově založeného webu, který obsahuje jen uvítací text. Bez zaškrtnutí se obsah přidá ke stávajícímu.</small></span></label>
    <button class="btn" type="submit" data-confirm-import>Importovat obsah</button>
  </form>
  <form method="post" action="<?= url('/svj/' . $t['slug'] . '/smazat') ?>" class="card form" data-confirm="Opravdu nenávratně smazat web „<?= e($t['name']) ?>“ včetně všech dat?">
    <h2>Smazat společenství</h2>
    <?= csrf_field() ?>
    <p>Smaže web, všechny účty, dokumenty i záznamy tohoto společenství. Nejde to vrátit – předtím si nechte od výboru stáhnout zálohu.</p>
    <label>Pro potvrzení opište adresu: <strong><?= e($t['slug']) ?></strong> <input type="text" name="confirm" id="t-confirm" autocomplete="off" required></label>
    <button class="btn btn-accent" type="submit">Smazat společenství</button>
  </form>
  <?php endif ?>
</div>
