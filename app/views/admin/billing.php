<?php $per = period_label(); ?>
<div class="wrap page narrow">
  <h1>Předplatné</h1>
  <?php if ($state['mode'] === 'free'): ?>
    <p class="lead">Tento web běží bez placení – provoz je domluven přímo s provozovatelem služby.</p>
  <?php else: ?>
  <div class="card bill-state bill-<?= e($state['mode']) ?>">
    <?php if ($state['mode'] === 'trial'): ?>
      <h2>Zkušební doba zdarma</h2><p>Běží do <strong><?= cz_date($state['until']) ?></strong> (ještě <?= $state['days'] ?> dní). Potom je potřeba provoz uhradit, jinak se web pozastaví.</p>
    <?php elseif ($state['mode'] === 'paid'): ?>
      <h2>Zaplaceno</h2><p>Provoz webu je uhrazen do <strong><?= cz_date($state['until']) ?></strong>.</p>
    <?php elseif ($state['mode'] === 'grace'): ?>
      <h2>Po splatnosti</h2><p><?= $t['paid_until'] === '' ? 'Zkušební doba skončila' : 'Předplatné skončilo' ?> <strong><?= cz_date($state['until']) ?></strong>. Web zatím běží; bez úhrady bude za <?= max(0, pset_int('grace_days', 0, 365) + $state['days']) ?> dní pozastaven.</p>
    <?php else: ?>
      <h2>Web je pozastaven</h2><p><?= $t['paid_until'] === '' ? 'Zkušební doba skončila' : 'Předplatné skončilo' ?> <strong><?= cz_date($state['until']) ?></strong> a platba nedorazila. Obyvatelé web nevidí, data zůstávají uložena. Po úhradě web znovu zprovozníme. <a href="<?= url('/admin/zaloha') ?>">Zálohu dat</a> si můžete stáhnout i teď.</p>
    <?php endif ?>
  </div>

  <?php if ($deleteOn && $state['days'] < 0): ?><div class="flash flash-error">Pokud provoz neuhradíte, bude web včetně všech dat <strong>smazán <?= cz_date($deleteOn) ?></strong>. Předem Vám pošleme upozornění e-mailem. <a href="<?= url('/admin/zaloha') ?>">Stáhnout zálohu dat</a></div><?php endif ?>
  <div class="card">
    <h2>Platba za další <?= $per ?></h2>
    <?php if ($payment['status'] === 'claimed'): ?><div class="flash flash-info">Platbu jste nahlásili jako odeslanou <?= cz_datetime($payment['claimed_at']) ?>. Čeká na potvrzení provozovatelem – obvykle do dvou pracovních dnů.</div><?php endif ?>
    <?php if ($iban): ?>
    <div class="pay">
      <div class="pay-qr"><?= $qr ?><small>Načtěte v bankovní aplikaci</small></div>
      <dl class="pay-data">
        <dt>Částka</dt><dd><strong><?= money_czk((int) $payment['amount']) ?></strong></dd>
        <dt>Číslo účtu</dt><dd><?= e(str_starts_with(strtoupper(trim(pset('account'))), 'CZ') ? $iban : pset('account')) ?></dd>
        <dt>IBAN</dt><dd><?= e(trim(chunk_split($iban, 4, ' '))) ?></dd>
        <dt>Variabilní symbol</dt><dd><code><?= e($payment['vs']) ?></code></dd>
        <?php if (pset('recipient') !== ''): ?><dt>Příjemce</dt><dd><?= e(pset('recipient')) ?></dd><?php endif ?>
      </dl>
    </div>
    <p class="hint">Platba se páruje podle variabilního symbolu. Po připsání na účet prodloužíme předplatné o <?= (int) $payment['months'] === 12 ? 'rok' : 'měsíc' ?><?= $state['days'] >= 0 ? ' od ' . cz_date(date('Y-m-d', strtotime($state['until'] . ' +1 day'))) : '' ?>.</p>
    <div class="actions"><?php if ($payment['status'] === 'pending'): ?><form method="post" action="<?= url('/admin/predplatne/zaplaceno') ?>"><?= csrf_field() ?><button class="btn" type="submit">Platbu jsem odeslal(a)</button></form><?php endif ?>
      <a class="btn btn-ghost" href="<?= url('/admin/predplatne/doklad/' . $payment['id']) ?>">Zálohová faktura k tisku</a></div>
    <?php else: ?>
      <p>Částka: <strong><?= money_czk((int) $payment['amount']) ?></strong>, variabilní symbol <code><?= e($payment['vs']) ?></code>.</p>
      <p>Platební údaje Vám provozovatel pošle e-mailem<?= pset('contact_email') !== '' ? ' – případně napište na <a href="mailto:' . e(pset('contact_email')) . '">' . e(pset('contact_email')) . '</a>' : '' ?>.</p>
    <?php endif ?>
  </div>

  <div class="card" id="domena">
    <h2>Vlastní doména</h2>
    <?php if ($t['domain_status'] === 'active'): ?>
      <p>Web běží na vlastní doméně <strong><a href="https://<?= e($t['custom_domain']) ?>"><?= e($t['custom_domain']) ?></a></strong><?= $t['domain_until'] !== '' ? ', zaplaceno do ' . cz_date($t['domain_until']) : '' ?>. Adresa <?= e(tenant_address($t['slug'])) ?> funguje dál.</p>
    <?php elseif ($t['domain_status'] === 'requested'): ?>
      <p>Žádost o doménu <strong><?= e($t['domain_request']) ?></strong> je odeslaná. <?= $t['domain_until'] !== '' ? 'Platbu jsme přijali; doménu zprovozňujeme a dáme Vám vědět e-mailem.' : 'Po přijetí platby ji zprovozníme, obvykle do několika pracovních dnů.' ?></p>
      <?php if (pset('domain_dns') !== ''): ?><div class="callout"><strong>Pokud doménu už vlastníte,</strong> nastavte u svého registrátora: <div class="prose"><?= format_text(pset('domain_dns')) ?></div></div><?php endif ?>
    <?php else: ?>
      <p>Web může běžet na Vaší vlastní adrese, například <strong>tabor48.cz</strong>, za <?= money_czk(pset_int('price_domain', 0, 1000000)) ?> ročně. Doménu Vám zaregistrujeme, nebo připojíme tu, kterou už máte. Správa webu se nemění – jen adresa.</p>
      <form method="post" action="<?= url('/admin/predplatne/domena') ?>" class="form">
        <?= csrf_field() ?><input type="hidden" name="action" value="request">
        <label>Požadovaná doména <input type="text" name="domain" placeholder="tabor48.cz" required maxlength="100" autocomplete="off"></label>
        <label class="check"><input type="radio" name="own" value="0" checked> <span>Doménu ještě nemáme <small>Ověříme, zda je volná, a zaregistrujeme ji pro Vaše společenství.</small></span></label>
        <label class="check"><input type="radio" name="own" value="1"> <span>Doménu už máme <small>Po zaplacení Vám pošleme, co nastavit u Vašeho registrátora.</small></span></label>
        <div class="actions"><button class="btn" type="submit">Požádat o vlastní doménu</button></div>
      </form>
    <?php endif ?>
    <?php if ($t['domain_status'] !== ''): $certDomain = $t['custom_domain'] !== '' ? $t['custom_domain'] : $t['domain_request']; ?>
    <details class="cert-box"<?= $certInfo && $certInfo['days'] <= 21 ? ' open' : '' ?>>
      <summary>Vlastní certifikát HTTPS <?= $certInfo ? '<span class="status status-' . ($certInfo['days'] < 0 ? 'new' : ($certInfo['days'] <= 21 ? 'in_progress' : 'done')) . '">' . ($certInfo['days'] < 0 ? 'neplatný' : 'platí do ' . cz_date($certInfo['valid_to'])) . '</span>' : '<small class="muted">nepovinné</small>' ?></summary>
      <p class="hint">Certifikát pro Vaši doménu obvykle zajistíme sami a nemusíte nic nahrávat. Vlastní nahrajte jen tehdy, když ho Vaše společenství už má (například od poskytovatele domény).</p>
      <?php if ($certInfo): ?><p>Nahraný certifikát: vydal <?= e($certInfo['issuer'] !== '' ? $certInfo['issuer'] : 'neznámý vydavatel') ?>, platí do <strong><?= cz_date($certInfo['valid_to']) ?></strong><?= $certInfo['self_signed'] ? ' · podepsaný sám sebou' : '' ?>.</p><?php endif ?>
      <form method="post" action="<?= url('/admin/predplatne/certifikat') ?>" enctype="multipart/form-data" class="form">
        <?= csrf_field() ?>
        <label>Certifikát pro <?= e($certDomain) ?> (soubor .pem nebo .crt, celý řetězec) <input type="file" name="cert_file" accept=".pem,.crt,.cer,.txt" required></label>
        <label>Soukromý klíč (soubor .key nebo .pem, bez hesla) <input type="file" name="key_file" accept=".pem,.key,.txt" required></label>
        <p class="hint">Soukromý klíč se uloží jen na serveru, nikde se nezobrazuje a nejde stáhnout. Před uložením ověříme, že patří k certifikátu, že je certifikát pro tuto doménu a že platí.</p>
        <div class="actions"><button class="btn btn-small" type="submit"><?= $certInfo ? 'Nahrát nový certifikát' : 'Nahrát certifikát' ?></button></div>
      </form>
      <?php if ($certInfo): ?><form method="post" action="<?= url('/admin/predplatne/certifikat') ?>" data-confirm="Odebrat nahraný certifikát?"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><button class="link-danger" type="submit">Odebrat certifikát</button></form><?php endif ?>
    </details>
    <?php endif ?>
    <?php if ($domainPayment): $dp = $domainPayment; ?>
      <h3>Platba za doménu <?= e($dp['item']) ?> na 1 rok</h3>
      <?php if ($dp['status'] === 'claimed'): ?><div class="flash flash-info">Platbu jste nahlásili jako odeslanou <?= cz_datetime($dp['claimed_at']) ?>. Čeká na potvrzení provozovatelem.</div><?php endif ?>
      <div class="pay">
        <?php if ($domainQr): ?><div class="pay-qr"><?= $domainQr ?><small>Načtěte v bankovní aplikaci</small></div><?php endif ?>
        <dl class="pay-data"><dt>Částka</dt><dd><strong><?= money_czk((int) $dp['amount']) ?></strong></dd><?php if ($iban): ?><dt>Číslo účtu</dt><dd><?= e(pset('account')) ?></dd><?php endif ?><dt>Variabilní symbol</dt><dd><code><?= e($dp['vs']) ?></code></dd></dl>
      </div>
      <div class="actions">
        <?php if ($dp['status'] === 'pending'): ?><form method="post" action="<?= url('/admin/predplatne/domena') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="claim"><button class="btn" type="submit">Platbu za doménu jsem odeslal(a)</button></form><?php endif ?>
        <a class="btn btn-ghost" href="<?= url('/admin/predplatne/doklad/' . $dp['id']) ?>">Zálohová faktura k tisku</a>
        <?php if ($dp['status'] === 'pending' && $t['domain_status'] === 'requested'): ?><form method="post" action="<?= url('/admin/predplatne/domena') ?>" data-confirm="Zrušit žádost o vlastní doménu?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-ghost" type="submit">Zrušit žádost</button></form><?php endif ?>
      </div>
    <?php endif ?>
  </div>

  <?php if (ai_configured()): $aiCfg = ai_settings(); ?>
  <div class="card" id="ai">
    <h2>AI pomocník</h2>
    <p>Tento měsíc využito <strong><?= ai_used() ?> z <?= (int) $aiCfg['limit'] ?></strong> použití<?= $aiCfg['plus'] ? ' (doplněk AI Plus do ' . cz_date($t['ai_until']) . ')' : ' – základní limit je v ceně webu' ?>.</p>
    <?php if ($aiPayment): $ap = $aiPayment; ?>
      <h3>Platba za AI Plus na 1 rok</h3>
      <?php if ($ap['status'] === 'claimed'): ?><div class="flash flash-info">Platbu jste nahlásili jako odeslanou <?= cz_datetime($ap['claimed_at']) ?>. Čeká na potvrzení provozovatelem.</div><?php endif ?>
      <div class="pay"><?php if ($aiQr): ?><div class="pay-qr"><?= $aiQr ?><small>Načtěte v bankovní aplikaci</small></div><?php endif ?>
        <dl class="pay-data"><dt>Částka</dt><dd><strong><?= money_czk((int) $ap['amount']) ?></strong></dd><?php if ($iban): ?><dt>Číslo účtu</dt><dd><?= e(pset('account')) ?></dd><?php endif ?><dt>Variabilní symbol</dt><dd><code><?= e($ap['vs']) ?></code></dd></dl></div>
      <div class="actions">
        <?php if ($ap['status'] === 'pending'): ?><form method="post" action="<?= url('/admin/predplatne/ai') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="claim"><button class="btn" type="submit">Platbu za AI Plus jsem odeslal(a)</button></form><?php endif ?>
        <a class="btn btn-ghost" href="<?= url('/admin/predplatne/doklad/' . $ap['id']) ?>">Zálohová faktura k tisku</a>
        <?php if ($ap['status'] === 'pending'): ?><form method="post" action="<?= url('/admin/predplatne/ai') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-ghost" type="submit">Zrušit objednávku</button></form><?php endif ?>
      </div>
    <?php elseif (!$aiCfg['plus'] || $t['ai_until'] <= date('Y-m-d', time() + 30 * 86400)): ?>
      <p><strong>AI Plus:</strong> <?= pset_int('ai_plus_limit', 0, 100000) ?> použití měsíčně za <?= money_czk(pset_int('price_ai', 0, 1000000)) ?> ročně. Hodí se, když pomocníka používá víc členů výboru nebo píšete hodně oznámení.</p>
      <form method="post" action="<?= url('/admin/predplatne/ai') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="request"><button class="btn" type="submit"><?= $aiCfg['plus'] ? 'Prodloužit AI Plus o rok' : 'Objednat AI Plus' ?></button></form>
    <?php endif ?>
  </div>
  <?php endif ?>

  <div class="card" id="pripravujeme">
    <h2>Připravujeme</h2>
    <p class="muted">Na těchto funkcích pracujeme. Klikněte na tu, která Vás zajímá – dozvíte se, co přesně bude dělat a jaký problém řeší. Podle zájmu rozhodujeme, co udělat dřív.</p>
    <ul class="planned">
      <?php foreach (PLANNED_FEATURES as $key => [$name, $desc, $price]): ?>
      <li><a href="<?= url('/admin/predplatne/funkce/' . $key) ?>"><span class="planned-head"><strong><?= e($name) ?></strong> <span class="tag">již brzy</span></span>
        <span class="planned-desc"><?= e($desc) ?></span>
        <span class="planned-foot"><small class="muted"><?= e($price) ?></small><span class="planned-more">Více o funkci →</span></span></a></li>
      <?php endforeach ?>
    </ul>
  </div>

  <div class="card card-soft">
    <h2>Ceník</h2>
    <ul class="price-rows">
      <li><span>První rok</span><strong><?= money_czk(pset_int('price_first', 0, 1000000)) ?> <small>/ <?= $per ?></small></strong></li>
      <li><span>Další roky</span><strong><?= money_czk(pset_int('price_next', 0, 1000000)) ?> <small>/ <?= $per ?></small></strong></li>
      <?php foreach (preg_split('/\R/', pset('pricelist')) as $line): if (trim($line) === '') { continue; } [$item, $price] = array_map('trim', explode('|', $line . '|')); ?>
        <li><span><?= e($item) ?></span><strong><?= e($price) ?></strong></li>
      <?php endforeach ?>
    </ul>
    <?php if (pset('contact_email') !== ''): ?><p class="hint">Podporu a úpravy objednáte na <a href="mailto:<?= e(pset('contact_email')) ?>"><?= e(pset('contact_email')) ?></a>.</p><?php endif ?>
  </div>

  <?php if ($history): ?>
  <h2>Přijaté platby</h2>
  <table class="table"><thead><tr><th>Zaplaceno</th><th>Částka</th><th>Období</th><th>Var. symbol</th><th>Doklad</th></tr></thead><tbody>
    <?php foreach ($history as $p): ?><tr><td data-label="Zaplaceno"><?= cz_date($p['paid_at']) ?></td><td data-label="Částka"><?= money_czk((int) $p['amount']) ?></td><td data-label="Období"><?= cz_date($p['period_from']) ?> – <?= cz_date($p['period_to']) ?></td><td data-label="Var. symbol"><?= e($p['vs']) ?><?= ($p['kind'] ?? '') === 'domain' ? '<br><small class="muted">doména ' . e($p['item']) . '</small>' : (($p['kind'] ?? '') === 'ai' ? '<br><small class="muted">AI Plus</small>' : '') ?></td><td data-label="Doklad"><a href="<?= url('/admin/predplatne/doklad/' . $p['id']) ?>">Faktura <?= e($p['invoice_no']) ?></a></td></tr><?php endforeach ?>
  </tbody></table>
  <?php endif ?>
  <?php endif ?>
</div>
