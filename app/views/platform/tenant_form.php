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
  <?php if (!$new): ?>
  <nav class="tenant-jump" aria-label="Části stránky">
    <a href="#uzivatele">Uživatelé</a><a href="#vyuziti">Využití a limity</a><?= !empty($externalDocs) ? '<a href="#soubory">Soubory ze starého webu (' . count($externalDocs) . ')</a>' : '' ?><a href="#udaje">Údaje a vzhled</a><a href="#predplatne">Předplatné</a><a href="#podpora">Podpora a hesla</a><a href="#vybor">Výbor z rejstříku</a><?= $raw['domain_status'] !== '' ? '<a href="#domena">Doména</a>' : '' ?><a href="#prevod">Převod webu</a><?= $payments ? '<a href="#platby">Platby</a>' : '' ?>
  </nav>
  <?php if (!empty($tenantUsers)): $active = array_filter($tenantUsers, fn($u) => (int) $u['active'] === 1); $seen30 = array_filter($active, fn($u) => $u['last_login_at'] && $u['last_login_at'] >= date('Y-m-d', time() - 30 * 86400)); $never = array_filter($active, fn($u) => !$u['last_login_at']); ?>
  <details class="card tenant-users" id="uzivatele" open>
    <summary><h2>Uživatelé webu</h2> <span class="muted">aktivních <?= count($active) ?> · přihlášeno za 30 dní <?= count($seen30) ?> · nikdy nepřihlášeno <?= count($never) ?></span></summary>
    <table class="table">
      <thead><tr><th>Uživatel</th><th>Role</th><th>Jednotky</th><th>Poslední přihlášení</th><th>Stav</th></tr></thead>
      <tbody><?php foreach ($tenantUsers as $u): ?><tr<?= (int) $u['active'] ? '' : ' class="is-off"' ?>>
        <td data-label="Uživatel"><strong><?= e($u['name']) ?></strong><br><small class="muted"><?= e($u['email']) ?></small></td>
        <td data-label="Role"><?= $u['role'] === 'admin' ? '<strong>správce webu</strong>' : e(mb_strtolower(KINDS[$u['kind']] ?? $u['kind'])) ?><?= ($u['position'] ?? '') !== '' ? '<br><small class="muted">' . e(position_label($u)) . '</small>' : '' ?></td>
        <td data-label="Jednotky"><?= $u['units'] !== '' ? e($u['units']) : '<span class="muted">—</span>' ?></td>
        <td data-label="Poslední přihlášení"><?= $u['last_login_at'] ? cz_datetime($u['last_login_at']) : '<span class="muted">nikdy</span>' ?></td>
        <td data-label="Stav"><?= !(int) $u['active'] ? '<span class="status status-new">vypnutý</span>' : ((int) $u['pending'] ? '<span class="status status-in_progress">čeká na přijetí pozvánky</span>' . ($u['invite_expires'] && $u['invite_expires'] < date('Y-m-d H:i:s') ? '<br><small class="muted">pozvánka vypršela</small>' : '') : '<span class="status status-done">aktivní</span>') ?><?= (int) $u['totp_enabled'] ? '<br><small class="muted">dvoufázové ověření</small>' : '' ?></td>
      </tr><?php endforeach ?></tbody>
    </table>
    <p class="hint">Jen přehled účtů pro podporu. Obsah webu (aktuality, dokumenty, zprávy) se tu nezobrazuje; nahlédnout do něj jde jen přes režim podpory níže, který se zapisuje do záznamů společenství.</p>
  </details>
  <?php endif ?>
  <?php endif ?>
  <?php if (!empty($externalDocs)): $tried = array_filter($externalDocs, fn($d) => $d['reason'] !== 'Zatím nezkoušeno.'); ?>
  <section class="card card-alert" id="soubory">
    <h2>Převod souborů není dokončený</h2>
    <p><strong><?= count($externalDocs) ?> dokumentů</strong> je na webu jen jako odkaz na soubor na starém webu. Až společenství starý web zruší (což může udělat kdykoli – myslí si, že je převod hotový), dokumenty přestanou fungovat.</p>
    <?php if (count($tried) < count($externalDocs)): ?><form method="post" action="<?= url('/svj/' . $t['slug'] . '/soubory') ?>"><?= csrf_field() ?><button class="btn" type="submit">Stáhnout soubory ze starého webu</button></form>
    <?php else: ?><p>Automaticky už to nejde. Každý soubor si otevřete odkazem „Originál“, uložte ho a nahrajte zpět u daného dokumentu; když soubor už neexistuje, dokument odeberte.
      <form method="post" action="<?= url('/svj/' . $t['slug'] . '/soubory') ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="znovu" value="1"><button class="btn btn-small btn-ghost" type="submit">Zkusit stáhnout znovu</button></form></p><?php endif ?>
    <table class="table ext-docs">
      <thead><tr><th>Dokument</th><th>Proč se nestáhl</th><th>Náhrada</th></tr></thead>
      <tbody><?php foreach ($externalDocs as $d): ?><tr>
        <td data-label="Dokument"><strong><?= e($d['title']) ?></strong><br><small class="muted"><?= e(CATEGORIES[$d['category']] ?? $d['category']) ?><?= $d['doc_date'] ? ' · ' . cz_date($d['doc_date']) : '' ?></small><br>
          <?php if (preg_match('~^https?://~i', $d['url'])): ?><a href="<?= e($d['url']) ?>" target="_blank" rel="noopener noreferrer">Originál<?= $d['original_name'] !== '' ? ': ' . e($d['original_name']) : '' ?></a><?php else: ?><small class="muted"><?= e($d['url']) ?></small><?php endif ?></td>
        <td data-label="Proč se nestáhl"><?= e($d['reason']) ?></td>
        <td data-label="Náhrada">
          <form method="post" action="<?= url('/svj/' . $t['slug'] . '/soubor-nahrat') ?>" enctype="multipart/form-data" class="ext-upload"><?= csrf_field() ?><input type="hidden" name="doc" value="<?= (int) $d['id'] ?>">
            <input type="file" name="file" accept=".<?= implode(',.', ALLOWED_EXT) ?>" required aria-label="Soubor pro <?= e($d['title']) ?>"><button class="btn btn-small" type="submit">Nahrát</button></form>
          <form method="post" action="<?= url('/svj/' . $t['slug'] . '/soubor-smazat') ?>" class="inline-form" data-confirm="Odebrat dokument „<?= e($d['title']) ?>“? Soubor už neexistuje a na webu by byl jen nefunkční odkaz."><?= csrf_field() ?><input type="hidden" name="doc" value="<?= (int) $d['id'] ?>"><button class="btn btn-small btn-ghost" type="submit">Odebrat dokument</button></form>
        </td></tr><?php endforeach ?></tbody>
    </table>
    <p class="hint">Správce společenství může soubor nahradit i sám v Dokumentech (úprava dokumentu → nahrát soubor).</p>
  </section>
  <?php endif ?>
  <?php if (!empty($usage)): ?>
  <section class="card usage-report" id="vyuziti">
    <h2>Využití a limity <span class="muted">· edice <?= e(EDITIONS[$usage['edition']]) ?></span></h2>
    <ul class="usage-verdict"><?php foreach ($usage['verdict'] as [$tone, $text]): ?><li class="is-<?= $tone ?>"><?= e($text) ?></li><?php endforeach ?></ul>
    <table class="table usage-table">
      <thead><tr><th>Limit</th><th>Využito</th><th class="usage-bar-col">Naplnění</th><th>Stav</th></tr></thead>
      <tbody><?php foreach ($usage['limits'] as [$label, $used, $limit, $unit, $pct, $state]): $u = $unit !== '' ? ' ' . $unit : ''; ?><tr class="u-<?= $state ?>">
        <td data-label="Limit"><?= e($label) ?></td>
        <td data-label="Využito"><strong><?= number_format($used, 0, ',', "\u{a0}") . e($u) ?></strong> <span class="muted">z <?= $limit ? number_format($limit, 0, ',', "\u{a0}") . e($u) : 'neomezeně' ?></span></td>
        <td data-label="Naplnění" class="usage-bar-col"><?php if ($limit): ?><progress class="bar" max="100" value="<?= min(100, $pct) ?>"><?= $pct ?> %</progress><?php else: ?><span class="muted">—</span><?php endif ?></td>
        <td data-label="Stav"><?= ['ok' => '<span class="status status-done">v pořádku</span>', 'near' => '<span class="status status-in_progress">blíží se · ' . $pct . ' %</span>', 'full' => '<span class="status status-new">vyčerpáno · ' . $pct . ' %</span>', 'free' => '<span class="muted">bez omezení</span>'][$state] ?></td>
      </tr><?php endforeach ?></tbody>
    </table>
    <dl class="usage-facts"><?php foreach ($usage['facts'] as $k => $v): ?><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd><?php endforeach ?></dl>
    <p class="hint">E-maily a AI se počítají za kalendářní měsíc, hlasování za kalendářní rok. Úložiště zahrnuje dokumenty, přílohy závad i logo; k limitu se přičítá zaplacené úložiště navíc.</p>
  </section>
  <?php endif ?>
  <form method="post" class="card form" id="udaje">
    <?= csrf_field() ?>
    <?php $aresUrl = url('/ares'); ?>
    <?php if (!$new): ?><details class="ares-later"><summary>Doplnit nebo opravit údaje z ARES</summary><?php require __DIR__ . '/_ares.php'; ?><p class="hint">Vyplní název, celý název, adresu a IČO níže – uloží se až tlačítkem pod formulářem.</p></details><?php endif ?>
    <?php if ($new): ?>
      <?php require __DIR__ . '/_ares.php'; ?>
      <label>Adresa webu <span class="slug-row"><?php if (platform_main_paths()): ?><span><?= e(platform_main_domain()) ?>/</span><?php endif ?><input type="text" name="slug" data-slug-fixed="<?= e(platform_main_domain()) ?>" aria-describedby="slug-fixed-note" id="t-slug" value="<?= e($t['slug']) ?>" pattern="[a-z0-9][a-z0-9\-]{1,38}" required placeholder="zahradni"><?php if (!platform_main_paths()): ?><span>.<?= e(platform_main_domain()) ?></span><?php endif ?></span></label>
      <p class="hint">Jen malá písmena bez diakritiky, číslice a pomlčka. Adresu později nejde změnit.</p>
    <?php endif ?>
    <label>Název společenství <input type="text" name="name" data-ares-field="site_name" value="<?= e($t['name']) ?>" required placeholder="SVJ Zahradní 12"></label>
    <div class="form-row">
      <label>Krátký název na web <input type="text" name="title" data-ares-field="short" value="<?= e($t['title']) ?>" required placeholder="Zahradní 12"></label>
      <label>Doplněk názvu (nepovinné) <input type="text" name="accent" value="<?= e($t['accent']) ?>" placeholder="a–d"></label>
    </div>
    <label>Celý název (patička, plná moc) <input type="text" name="full" data-ares-field="name" value="<?= e($t['full']) ?>" placeholder="Společenství vlastníků jednotek Zahradní 12"></label>
    <label>Řádek nad názvem <input type="text" name="tagline" value="<?= e($t['tagline']) ?>" placeholder="Společenství vlastníků jednotek · Praha"></label>
    <div class="form-row">
      <label>Adresa domu <input type="text" name="address" data-ares-field="address" value="<?= e($t['address']) ?>" placeholder="Zahradní 1200/12, Brno"></label>
      <label>IČO <input type="text" name="ico" data-ares-field="ico" value="<?= e($t['ico'] ?? '') ?>" inputmode="numeric" pattern="\d{8}" maxlength="8" placeholder="12345678"></label>
    </div>
    <div class="form-row">
      <label>Číslo před označením jednotky <input type="text" name="unit_prefix" value="<?= e($t['unit_prefix']) ?>" maxlength="10" placeholder="1200"></label>
      <label>Vchody (oddělené čárkou) <input type="text" name="entrances" value="<?= e($t['entrances']) ?>" placeholder="A,B,C"></label>
    </div>
    <p class="hint">Jednotka se pak zobrazuje například jako 372A/5.</p>
    <div class="form-row">
      <label>Šablona <select name="style"><?php foreach (STYLES as $k => $label): ?><option value="<?= $k ?>"<?= ($t['style'] ?? 'klasik') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
      <label>Barvy <select name="look"><?php foreach (LOOKS as $k => $label): ?><option value="<?= $k ?>"<?= $t['look'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
      <label>Jazyk webu <select name="lang"><?php foreach (LANGS as $k => $label): ?><option value="<?= $k ?>"<?= $t['lang'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
    </div>
    <?php if (($t['custom_domain'] ?? '') !== ''): /* vlastní doménu už nenabízíme; pole jen u webů, které ji mají */ ?>
    <label>Vlastní doména <input type="text" name="custom_domain" value="<?= e($t['custom_domain']) ?>" placeholder="www.svj-priklad.cz"></label>
    <p class="hint">Doménu musí její majitel nasměrovat na tento server; web pak poběží na ní i na adrese platformy. Nové domény se nenabízejí.</p>
    <?php else: ?><input type="hidden" name="custom_domain" value=""><?php endif ?>
    <label>Interní poznámka <input type="text" name="note" value="<?= e($t['note']) ?>"></label>
    <?php if ($new): ?><label class="check"><input type="checkbox" name="seed_tabor"> <span>Naplnit ukázkovým obsahem SVJ Tábor 48 <small>Dokumenty, kontakty a aktuality z původního webu svjtabor48.webnode.cz. Pro ostatní společenství nechte vypnuté.</small></span></label><?php endif ?>
    <?php if ($new): ?>
    <h2>Předplatné</h2>
    <label class="check"><input type="checkbox" name="billing"<?= $t['billing'] ? ' checked' : '' ?>> <span>Web je placený <small>Web se řídí edicemi: bez platby běží v edici Zdarma, po zaplacení ve Standardu nebo Premium. Vypnuté = web běží bez jakéhokoli omezení a nic neplatí (domluva s provozovatelem).</small></span></label>
    <div class="form-row">
      <label>Standard na zkoušku do (starší weby) <input type="date" name="trial_ends" value="<?= e($t['trial_ends']) ?>"></label>
      <label>Zaplaceno do <input type="date" name="paid_until" value="<?= e($t['paid_until']) ?>"></label>
      <label>Zaplacená edice <select name="plan"><?php foreach (['' => '– (Standard)', 'standard' => 'Standard', 'premium' => 'Premium'] as $pk => $pl): ?><option value="<?= $pk ?>"<?= ($t['plan'] ?? '') === $pk ? ' selected' : '' ?>><?= $pl ?></option><?php endforeach ?></select></label>
    </div>
    <?php else: ?>
    <h2>Kontakt pro platby a upozornění</h2>
    <input type="hidden" name="trial_ends" value="<?= e($t['trial_ends']) ?>"><input type="hidden" name="paid_until" value="<?= e($t['paid_until']) ?>"><input type="hidden" name="plan" value="<?= e($t['plan'] ?? '') ?>">
    <?php if ($t['billing']): ?><input type="hidden" name="billing" value="1"><?php endif ?>
    <p class="hint">Edici a platnost předplatného nastavíte níže v části Předplatné.</p>
    <?php endif ?>
    <div class="form-row">
      <label>Kontaktní osoba <input type="text" name="owner_name" value="<?= e($t['owner_name']) ?>"></label>
      <label>E-mail pro připomenutí plateb <input type="email" name="owner_email" value="<?= e($t['owner_email']) ?>"></label>
    </div>
    <label class="check"><input type="checkbox" name="active"<?= $t['active'] ? ' checked' : '' ?>> <span>Web běží <small>Pozastavený web se návštěvníkům nezobrazí; data zůstávají.</small></span></label>
    <div class="actions"><button class="btn btn-big" type="submit"><?= $new ? 'Založit web' : 'Uložit' ?></button><a class="btn btn-ghost" href="<?= url('/') ?>">Zrušit</a></div>
  </form>
  <?php if (!$new): ?>
  <div class="card" id="predplatne">
    <h2>Předplatné</h2>
    <p>Teď: <strong><?= $bstate['mode'] === 'free' ? 'bez placení a bez omezení' : 'edice ' . e(EDITIONS[$bstate['edition']]) ?></strong><?= match ($bstate['mode']) { 'paid' => ' · zaplaceno do ' . cz_date($bstate['until']) . ' (ještě ' . $bstate['days'] . ' dní)', 'trial' => ' · Standard na zkoušku do ' . cz_date($bstate['until']),
      'grace' => ' · po splatnosti od ' . cz_date($bstate['until']) . ', za ' . max(0, pset_int('grace_days', 0, 365) + $bstate['days']) . ' dní přejde na Zdarma', 'basic' => $bstate['until'] !== '' ? ' · placená edice skončila ' . cz_date($bstate['until']) : ' · nikdy neplatil', default => '' } ?></p>
    <form method="post" action="<?= url('/svj/' . $t['slug'] . '/predplatne') ?>" class="form">
      <?= csrf_field() ?>
      <div class="form-row">
        <label>Edice <select name="edition"><?php foreach (['standard' => 'Standard', 'premium' => 'Premium', 'free' => 'Zdarma (ukončit placenou edici)', 'unlimited' => 'Bez placení a bez omezení'] as $ek => $el): ?><option value="<?= $ek ?>"<?= ($bstate['mode'] === 'free' ? 'unlimited' : ($bstate['edition'] === 'free' ? 'standard' : $bstate['edition'])) === $ek ? ' selected' : '' ?>><?= $el ?></option><?php endforeach ?></select></label>
        <label>Platí do <input type="date" name="until" value="<?= e($t['paid_until'] !== '' && $t['paid_until'] >= date('Y-m-d') ? $t['paid_until'] : date('Y-m-d', strtotime('+1 year'))) ?>" min="<?= date('Y-m-d') ?>"></label>
      </div>
      <label>Důvod (uloží se do záznamů; při odeslání e-mailu ho uvidí i společenství) <input type="text" name="reason" maxlength="200" placeholder="Např. platba hotově, dárek za doporučení, oprava po reklamaci"></label>
      <label class="check"><input type="checkbox" name="notify"> <span>Poslat kontaktní osobě e-mail o nastavení předplatného</span></label>
      <div class="actions">
        <button class="btn" type="submit" name="do" value="set">Nastavit do zvoleného data</button>
        <button class="btn btn-ghost" type="submit" name="do" value="year">Prodloužit o rok</button>
        <button class="btn btn-ghost" type="submit" name="do" value="month">Prodloužit o měsíc</button>
      </div>
      <p class="hint">Nastavení nevytváří platbu ani fakturu – slouží pro dary, slevy, platby mimo systém a opravy. „Prodloužit“ počítá od konce dosavadního období stejné edice, jinak ode dneška. Běžné platby potvrzujte v části Platby.</p>
    </form>
    <?php if ((int) ($t['credit'] ?? 0) > 0 || !empty($referrals) || ($t['referred_by'] ?? '') !== ''): ?>
    <h3>Doporučení</h3>
    <p>Kredit na příští roční platbu: <strong><?= money_czk((int) ($t['credit'] ?? 0)) ?></strong><?= ($t['referred_by'] ?? '') !== '' ? ' · přišlo na doporučení webu <a href="' . url('/svj/' . $t['referred_by']) . '">' . e($t['referred_by']) . '</a>' : '' ?></p>
    <?php if (!empty($referrals)): ?><ul><?php foreach ($referrals as $r): ?><li><?= $r['referrer'] === $t['slug'] ? 'doporučilo: ' . e($r['referred_name'] ?: $r['referred']) : 'doporučeno webem ' . e($r['referrer']) ?> (<?= cz_date($r['created_at']) ?>) – <?= $r['rewarded_at'] !== '' ? 'odměna ' . money_czk((int) $r['amount']) . ' připsána ' . cz_date($r['rewarded_at']) : 'zatím bez platby' ?></li><?php endforeach ?></ul><?php endif ?>
    <?php endif ?>
    <h3>Zkoušení funkcí</h3>
    <table class="table"><thead><tr><th>Funkce</th><th>Edice</th><th>Stav</th><th></th></tr></thead><tbody>
      <?php $edRank = $bstate['mode'] === 'free' ? 2 : EDITION_RANK[$bstate['edition']]; foreach (PLAN_FEATURES as $fk => [$fmin, $fname]): $tr = $trials[$fk] ?? null; $in = $edRank >= EDITION_RANK[$fmin]; $running = $tr && $tr['ends'] >= date('Y-m-d'); ?>
      <tr><td data-label="Funkce"><?= e($fname) ?></td><td data-label="Edice"><?= e(EDITIONS[$fmin]) ?></td>
        <td data-label="Stav"><?= $in ? '<span class="status status-done">v edici</span>' : ($running ? '<span class="status status-in_progress">zkouška do ' . cz_date($tr['ends']) . '</span>' : ($tr ? '<span class="status status-new">zkouška skončila ' . cz_date($tr['ends']) . '</span>' : '<span class="muted">nezkoušel</span>')) ?></td>
        <td class="t-actions"><?php if (!$in): ?><form method="post" action="<?= url('/svj/' . $t['slug'] . '/zkouska') ?>"><?= csrf_field() ?><input type="hidden" name="feature" value="<?= e($fk) ?>">
          <button class="btn btn-small btn-ghost" type="submit" name="do" value="extend"><?= $running ? '+' . PLAN_TRIAL_DAYS . ' dní' : 'Zapnout na ' . PLAN_TRIAL_DAYS . ' dní' ?></button><?php if ($running): ?> <button class="link-danger" type="submit" name="do" value="end">Ukončit</button><?php endif ?></form><?php endif ?></td></tr>
      <?php endforeach ?>
    </tbody></table>
  </div>
  <?php if (!empty($migration)): $mp = $migrationPayment; ?>
  <div class="card" id="prevod">
    <h2>Převod obsahu ze starého webu</h2>
    <p><strong><?php if ($migration['url'] === 'export'): ?><?= e(migration_source($migration)) ?><?php else: ?><a href="<?= e($migration['url']) ?>" rel="noopener noreferrer nofollow" target="_blank"><?= e($migration['url']) ?></a><?php endif ?></strong> · <span class="status status-<?= ['ordered' => 'new', 'paid' => 'in_progress', 'done' => 'done'][$migration['status']] ?? 'new' ?>"><?= e(MIGRATION_STATUS[$migration['status']]) ?></span>
      · objednáno <?= cz_datetime($migration['created_at']) ?><?= $mp ? ' · platba: ' . e(PAYMENT_STATUS[$mp['status']] ?? $mp['status']) . ' (VS ' . e($mp['vs']) . ', ' . pay_money($mp) . ')' : '' ?></p>
    <dl class="pay-data">
      <dt>Rozsah</dt><dd><?= e(implode(', ', array_map(fn($k) => MIGRATION_SCOPE[$k] ?? $k, array_filter(explode(',', $migration['scope']))))) ?></dd>
      <dt>Přístup</dt><dd><?= e(MIGRATION_ACCESS[$migration['access']] ?? '') ?></dd>
      <dt>Kontakt</dt><dd><?= e($migration['contact_name']) ?>, <a href="mailto:<?= e($migration['contact_email']) ?>"><?= e($migration['contact_email']) ?></a><?= $migration['contact_phone'] !== '' ? ', ' . e($migration['contact_phone']) : '' ?></dd>
      <?php if ($migration['note'] !== ''): ?><dt>Poznámka</dt><dd><?= nl2br(e($migration['note'])) ?></dd><?php endif ?>
      <?php if (($migration['export_file'] ?? '') !== ''): ?><dt>Export</dt><dd><a href="<?= url('/svj/' . $t['slug'] . '/prevod-export') ?>"><?= e($migration['export_name']) ?></a> <small class="muted">(smaže se po dokončení převodu)</small></dd><?php endif ?>
      <?php if (($migration['export_url'] ?? '') !== ''): ?><dt>Odkaz na export</dt><dd><a href="<?= e($migration['export_url']) ?>" rel="noopener noreferrer nofollow" target="_blank"><?= e($migration['export_url']) ?></a></dd><?php endif ?>
    </dl>
    <?php if ($migration['status'] === 'ordered'): ?><p class="hint">Čeká na platbu – potvrdíte ji v části Platby. Do té doby na převodu nepracujte.</p>
    <?php elseif ($migration['status'] === 'paid'): ?>
      <p class="hint">Obsah převeďte přes API nebo MCP webu (klíč v jeho administraci → API a napojení, nápověda „API a MCP“), případně ručně. Potom převod označte jako hotový – kontaktní osoba dostane e-mail.</p>
      <form method="post" action="<?= url('/svj/' . $t['slug'] . '/prevod') ?>" class="form"><?= csrf_field() ?><input type="hidden" name="do" value="done">
        <label>Vzkaz pro společenství (nepovinné) <input type="text" name="done_note" maxlength="500" placeholder="Např. Převedeno 42 dokumentů a 18 aktualit; 3 soubory už na starém webu nebyly."></label>
        <div class="actions"><button class="btn" type="submit">Označit převod jako hotový</button></div></form>
    <?php else: ?><p>Dokončeno <?= cz_datetime($migration['done_at']) ?><?= $migration['done_note'] !== '' ? ' · ' . e($migration['done_note']) : '' ?></p><?php endif ?>
  </div>
  <?php else: ?>
  <div class="card" id="prevod">
    <h2>Převod obsahu ze starého webu</h2>
    <p>Společenství převod zatím neobjednalo. Objednává ho správce webu v části Předplatné → Převod obsahu ze starého webu; po zaplacení se objednávka objeví tady.</p>
    <p class="hint">Obsah převedete přes API nebo MCP webu (klíč vytvoříte za správce v jeho administraci → API a napojení) – stejně jako to dělají správci sami; postup je v nápovědě „API a MCP“.</p>
  </div>
  <?php endif ?>
  <?php if ($raw['domain_status'] !== ''): ?>
  <div class="card" id="domena">
    <h2>Vlastní doména (už se nenabízí, jen stávající)</h2>
    <p><strong><?= e($raw['domain_request']) ?></strong> · <?= $raw['domain_status'] === 'active' ? '<span class="status status-done">zprovozněna</span>' : '<span class="status status-new">žádost</span>' ?>
      <?= $raw['domain_until'] !== '' ? ' · zaplaceno do ' . cz_date($raw['domain_until']) : ' · zatím nezaplaceno' ?>
      <?= $domainPayment ? ' · poslední platba: ' . e(PAYMENT_STATUS[$domainPayment['status']]) . ' (VS ' . e($domainPayment['vs']) . ')' : '' ?></p>
    <p class="muted">DNS: <?= $domainDns === null ? 'nepodařilo se zjistit, zda je doména registrovaná.' : ($domainDns ? 'doména je registrovaná – má ji společenství, nebo ji musíte nasměrovat na tento server.' : 'doména vypadá volná – je potřeba ji zaregistrovat.') ?></p>
    <p><strong>Certifikát nahraný společenstvím:</strong> <?= $certInfo ? 'vydal ' . e($certInfo['issuer'] !== '' ? $certInfo['issuer'] : 'neznámý vydavatel') . ', platí do ' . cz_date($certInfo['valid_to']) . ($certInfo['days'] <= 21 ? ' <span class="tag tag-pin">brzy končí</span>' : '') . ($certInfo['self_signed'] ? ' · podepsaný sám sebou' : '') . ' · uložen ve <code>storage/certs/' . e($raw['custom_domain'] !== '' ? $raw['custom_domain'] : $raw['domain_request']) . '/</code>' : 'žádný – certifikát zajišťujete Vy (například automaticky).' ?></p>
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
    <?php if ($deleteOn && $deleteOn <= date('Y-m-d', strtotime('+30 day'))): ?><div class="flash flash-info">Na web se dlouho nikdo nepřihlásil. Automaticky se smaže <strong><?= cz_date($deleteOn) ?></strong>, nejdříve však 3 dny po odeslaném upozornění. Smazání zabrání přijatá nebo nahlášená platba, prodloužení zkušební doby nebo vypnutí „Web je placený“.</div>
    <?php elseif ($deleteOn): ?><p class="hint">Web v edici Zdarma: kdyby se na něj nikdo nepřihlásil <?= pset_int('delete_days', 0, 3650) ?> dní, smazal by se – nejdříve <?= cz_date($deleteOn) ?> (každé přihlášení lhůtu posune). Před smazáním odejdou dvě upozornění.</p><?php endif ?>
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
  <div class="card" id="platby"><h2>Platby tohoto společenství</h2>
    <table class="table"><thead><tr><th>Var. symbol</th><th>Částka</th><th>Období</th><th>Stav</th></tr></thead><tbody>
    <?php foreach ($payments as $p): ?><tr><td data-label="Var. symbol"><code><?= e($p['vs']) ?></code></td><td data-label="Částka"><?= pay_money($p) ?></td>
      <td data-label="Období"><?= $p['period_from'] ? cz_date($p['period_from']) . ' – ' . cz_date($p['period_to']) : '—' ?></td><td data-label="Stav"><?= e(PAYMENT_STATUS[$p['status']]) ?></td></tr><?php endforeach ?>
    </tbody></table>
    <p><a href="<?= url('/platby') ?>">Potvrdit nebo zrušit platbu →</a></p>
  </div>
  <?php endif ?>
  <div class="card" id="podpora">
    <h2>Podpora: přihlášení a hesla</h2>
    <?php if (!empty($tenantEnter)): ?><div class="flash flash-info">Vstup jako <?= e($tenantEnter['name']) ?> je připraven a zapsán do záznamů. Odkaz platí 2 minuty a jen jednou. <a class="btn btn-small" href="<?= e($tenantEnter['link']) ?>" rel="noreferrer">Otevřít web v režimu podpory</a></div><?php endif ?>
    <?php if (!empty($tenantReset)): ?><div class="flash flash-<?= $tenantReset['sent'] ? 'ok' : 'error' ?>"><?= $tenantReset['sent'] ? 'Odkaz na nové heslo jsme poslali na ' . e($tenantReset['email']) . '.' : 'E-mail na ' . e($tenantReset['email']) . ' se nepodařilo odeslat.' ?> Odkaz platí 24 hodin; můžete ho předat i jinou cestou:<br><code class="break"><?= e($tenantReset['link']) ?></code></div><?php endif ?>
    <?php $pickUser = function (string $id) use ($supportUsers, $supportPick) { ?>
      <label>Uživatel <select name="user" id="<?= $id ?>" required><option value="">– vyberte –</option>
        <?php foreach (['admin' => 'Správci webu', 'user' => 'Obyvatelé'] as $role => $group): $rows = array_filter($supportUsers, fn($u) => ($u['role'] === 'admin') === ($role === 'admin')); if (!$rows) { continue; } ?>
        <optgroup label="<?= $group ?>"><?php foreach ($rows as $u): ?><option value="<?= (int) $u['id'] ?>"<?= $supportPick === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?> – <?= e($u['email']) ?><?= $role === 'admin' ? '' : ' (' . (($u['kind'] ?? '') === 'tenant' ? 'nájemník' : 'vlastník') . ')' ?><?= $u['totp_enabled'] ? ' · 2FA' : '' ?></option><?php endforeach ?></optgroup>
        <?php endforeach ?></select></label>
    <?php }; ?>
    <?php if (!$supportUsers): ?><p class="empty">Web nemá žádný aktivní účet. Přidejte správce níže.</p><?php else: ?>
    <h3>Podívat se na web očima uživatele</h3>
    <form method="post" action="<?= url('/svj/' . $t['slug'] . '/vstoupit') ?>" class="form support-enter"><?= csrf_field() ?>
      <div class="form-row"><?php $pickUser('sup-enter-user') ?>
        <label>Důvod vstupu <input type="text" name="reason" maxlength="200" required placeholder="např. požadavek č. 123 – nevidí dokumenty"></label></div>
      <button class="btn" type="submit">Připravit vstup jen pro čtení</button>
    </form>
    <ol class="hint support-steps">
      <li>Vyberte uživatele, napište důvod a potvrďte. Vstup se hned zapíše do záznamů platformy.</li>
      <li>Objeví se tlačítko „Otevřít web v režimu podpory“ (platí 2 minuty, jen jednou). Otevře web společenství přihlášený za vybraného uživatele – bez jeho hesla i ověřovacího kódu.</li>
      <li>Web vidíte přesně jako on, ale <strong>jen pro čtení</strong>: žádný formulář se neuloží, nic se neodešle ani nesmaže a soukromé zprávy mezi uživateli se nezobrazí. Nahoře je po celou dobu červený pruh.</li>
      <li>Skončíte tlačítkem „Ukončit režim podpory“. Vstup i odchod s důvodem vidí správce společenství ve svých Záznamech.</li>
    </ol>
    <h3>Pomoc s přihlášením</h3>
    <form method="post" class="form" action="<?= url('/svj/' . $t['slug'] . '/heslo') ?>"><?= csrf_field() ?>
      <div class="form-row"><?php $pickUser('sup-login-user') ?></div>
      <div class="actions"><button class="btn btn-ghost" type="submit">Poslat odkaz na nové heslo</button>
        <button class="btn btn-ghost" type="submit" formaction="<?= url('/svj/' . $t['slug'] . '/overeni') ?>">Vypnout dvoufázové ověření</button></div>
    </form>
    <p class="hint">Zapomenuté heslo si každý obnoví sám odkazem „Zapomněli jste heslo?“ na přihlašovací stránce. Tady pomůžete, když mu e-mail nechodí nebo ztratil telefon s ověřovacím kódem. Odkaz na nové heslo platí 24 hodin; dosavadní heslo platí, dokud si uživatel nenastaví nové. Hesla uživatelů neznáte a nejdou zobrazit.</p>
    <?php endif ?>
  </div>
  <section class="card" id="vybor">
    <h2>Výbor z rejstříku</h2>
    <p>Načte současné členy výboru a kontrolní komise z veřejného rejstříku (ARES) podle IČO webu<?= ($t['ico'] ?? '') !== '' ? ' ' . e($t['ico']) : '' ?> a založí jim účty s funkcí – předsedovi třeba rovnou jako správci webu.</p>
    <a class="btn" href="<?= url('/svj/' . $t['slug'] . '/vybor') ?>">Načíst výbor z rejstříku</a>
  </section>
  <form method="post" action="<?= url('/svj/' . $t['slug'] . '/spravce') ?>" class="card form">
    <h2>Přidat správce tohoto webu</h2>
    <?= csrf_field() ?>
    <div class="form-row"><label>Jméno a příjmení <input type="text" name="name" id="ta-name" required></label><label>E-mail <input type="email" name="email" id="ta-email" required></label></div>
    <button class="btn" type="submit">Vytvořit účet a zobrazit pozvánku</button>
    <p class="hint">Vytvoření účtu správce se zapisuje do záznamů platformy.</p>
  </form>
  <?php if (!empty($t['demo'])): ?>
  <form method="post" action="<?= url('/demo') ?>" class="card form" data-confirm="Ukázkový web se smaže a založí znovu s čerstvými daty. Pokračovat?">
    <?= csrf_field() ?>
    <h2>Obnovit ukázkový web</h2>
    <p>Ukázkový web smazat nejde. Sám se obnovuje každých <?= pset_int('demo_refresh_days', 0, 365) ?> dní (nastavení služby), aby termíny, zprávy a lhůty vypadaly aktuálně. Obnovit ho můžete i hned.</p>
    <div class="actions"><button class="btn" type="submit">Obnovit teď</button></div>
  </form>
  <?php else: ?>
  <form method="post" action="<?= url('/svj/' . $t['slug'] . '/smazat') ?>" class="card form" data-confirm="Opravdu nenávratně smazat web „<?= e($t['name']) ?>“ včetně všech dat?">
    <h2>Smazat společenství</h2>
    <?= csrf_field() ?>
    <p>Smaže web, všechny účty, dokumenty i záznamy tohoto společenství. Nejde to vrátit – předtím si nechte od výboru stáhnout zálohu.</p>
    <label>Pro potvrzení opište adresu: <strong><?= e($t['slug']) ?></strong> <input type="text" name="confirm" id="t-confirm" autocomplete="off" required></label>
    <button class="btn btn-accent" type="submit">Smazat společenství</button>
  </form>
  <?php endif ?>
  <?php endif ?>
</div>
