<div class="wrap page narrow">
  <h1>Nastavení služby</h1>
  <p class="lead">Ceny a limity edic, platební údaje a texty úvodní stránky na adrese <a href="<?= e(platform_url()) ?>"><?= e(platform_domain()) ?></a>.</p>
  <?php $todo = launch_checklist(); ?>
  <section class="card launch-list<?= $todo ? '' : ' is-done' ?>" id="spusteni">
    <h2><?= $todo ? 'Před spuštěním zbývá doplnit (' . count($todo) . ')' : 'Vše potřebné je vyplněno' ?></h2>
    <?php if ($todo): ?>
    <ol><?php foreach ($todo as [$what, $why, $anchor]): ?><li><a href="#<?= $anchor ?>"><strong><?= e($what) ?></strong></a> – <?= e($why) ?></li><?php endforeach ?></ol>
    <p class="hint">Formulář níže vyplňujte odshora dolů; uložit ho můžete kdykoli a pokračovat později. Služba běží i bez těchto údajů, ale zákazníkům by chyběly.</p>
    <?php else: ?><p class="muted">Provozovatel, platby, e-maily i právní texty jsou připravené.</p><?php endif ?>
  </section>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <h2>Služba a registrace</h2>
    <label>Název služby <input type="text" name="service_name" value="<?= e($v['service_name']) ?>" required></label>
    <label class="check"><input type="checkbox" name="signup_open"<?= $v['signup_open'] === '1' ? ' checked' : '' ?>> <span>Registrace je otevřená <small>Kdokoli si může z úvodní stránky sám založit web společenství. Po vypnutí zakládáte weby jen Vy.</small></span></label>
    <label class="check"><input type="checkbox" name="demo_auto"<?= $v['demo_auto'] === '1' ? ' checked' : '' ?>> <span>Ukázkový web <small>Na adrese <?= e(tenant_address('demo')) ?> běží smyšlené společenství jen pro čtení a úvodní stránka na něj odkazuje. Pokud neexistuje, založí se sám. Po vypnutí ho můžete smazat v seznamu společenství.</small></span></label>
    <label>Ukázkový web obnovit s čerstvými daty každých (dní; 0 = neobnovovat) <input type="number" name="demo_refresh_days" value="<?= e($v['demo_refresh_days']) ?>" min="0" max="365" required></label>
    <p class="hint">Při obnově se ukázka smaže a založí znovu; všechny termíny, ankety, zprávy a lhůty se počítají ode dne obnovy, takže vždy vypadá jako živý web.</p>
    <label>Placená edice běží po splatnosti ještě (dní) <input type="number" name="grace_days" value="<?= e($v['grace_days']) ?>" min="0" max="365" required></label>
    <p class="hint">Potom web přejde na edici Zdarma. Nic se nemaže ani nepozastavuje.</p>
    <label>Nepoužívaný web v edici Zdarma smazat po (dní od posledního přihlášení) <input type="number" name="delete_days" value="<?= e($v['delete_days']) ?>" min="0" max="3650" required></label>
    <p class="hint">Kontaktní osobě přijde upozornění 14 dní a 3 dny před smazáním a potvrzení po něm; bez prokazatelně odeslaného upozornění se nic nemaže a přihlášení lhůtu vynuluje. 0 = nemazat. Všechna upozornění najdete v části Záznamy.</p>
    <h2>Edice a ceny</h2>
    <input type="hidden" name="period" value="year">
    <div class="form-row">
      <label>Standard (Kč za rok) <input type="number" name="price_standard" value="<?= e($v['price_standard']) ?>" min="0" required></label>
      <label>Premium (Kč za rok) <input type="number" name="price_premium" value="<?= e($v['price_premium']) ?>" min="0" required></label>
    </div>
    <table class="table limits-table"><thead><tr><th>Limit (0 = bez omezení)</th><?php foreach (EDITIONS as $ek => $en): ?><th><?= $en ?></th><?php endforeach ?></tr></thead><tbody>
      <?php foreach (PLAN_LIMITS as $lk => [$ln, $unit]): ?><tr><td data-label="Limit"><?= e($ln) ?><?= $unit !== '' ? ' (' . $unit . ')' : '' ?></td>
        <?php foreach (EDITIONS as $ek => $en): ?><td data-label="<?= $en ?>"><input type="number" name="lim_<?= $ek ?>_<?= $lk ?>" value="<?= e($v['lim_' . $ek . '_' . $lk]) ?>" min="0" required aria-label="<?= e($ln . ' – ' . $en) ?>"></td><?php endforeach ?></tr><?php endforeach ?>
    </tbody></table>
    <p class="hint">Funkce edic (které části webu jsou ve Standardu a v Premium) jsou dané v kódu v <code>app/plan_lib.php</code>. Každou placenou funkci si web v edici Zdarma může jednou na <?= PLAN_TRIAL_DAYS ?> dní vyzkoušet.</p>
    <div class="form-row">
      <label>Odměna za doporučení (Kč; 0 = vypnuto) <input type="number" name="referral_reward" value="<?= e($v['referral_reward']) ?>" min="0" required></label>
      <label>Sleva pro doporučené společenství (Kč) <input type="number" name="referral_welcome" value="<?= e($v['referral_welcome']) ?>" min="0" required></label>
    </div>
    <p class="hint">Doporučitel dostane odměnu jako slevu na svou příští roční platbu, až doporučené společenství poprvé zaplatí roční edici. Doporučený dostane slevu na první roční platbu.</p>
    <div class="form-row">
      <label>Balíček úložiště navíc (MB) <input type="number" name="storage_pack_mb" value="<?= e($v['storage_pack_mb']) ?>" min="1" required></label>
      <label>Cena balíčku (Kč za rok; 0 = nenabízet) <input type="number" name="price_storage" value="<?= e($v['price_storage']) ?>" min="0" required></label>
    </div>
    <div class="form-row">
      <label>Balíček AI kreditů (počet dotazů) <input type="number" name="ai_pack" value="<?= e($v['ai_pack']) ?>" min="1" required></label>
      <label>Cena balíčku AI kreditů (Kč; 0 = nenabízet) <input type="number" name="price_ai_pack" value="<?= e($v['price_ai_pack']) ?>" min="0" required></label>
    </div>
    <p class="hint">AI kredity se čerpají, až web vyčerpá měsíční limit AI své edice, a nepropadají. Cena by měla pokrýt náklady na AI službu (orientačně desítky haléřů až korunu za dotaz asistenta).</p>
    <label>Jednorázový převod obsahu ze starého webu (Kč) <input type="number" name="price_migration" value="<?= e($v['price_migration']) ?>" min="0" required></label>
    <label>Vlastní doména společenství (Kč za rok) <input type="number" name="price_domain" value="<?= e($v['price_domain']) ?>" min="0" required></label>
    <label>Pokyny pro společenství, které doménu už vlastní <textarea name="domain_dns" rows="3" placeholder="Nastavte záznam A pro tabor48.cz a www.tabor48.cz na adresu 203.0.113.10"><?= e($v['domain_dns']) ?></textarea></label>
    <p class="hint">O doménu žádá správce společenství v části Předplatné. Žádost uvidíte u společenství; po zaplacení, nasměrování domény na server a zapnutí HTTPS ji tam zprovozníte.</p>
    <?php require __DIR__ . '/../ext/pricing/settings.php'; /* modul G: ceny podle velikosti, jednorázové hlasování, srovnání, aplikace */ ?>
    <h2 id="ai">AI pomocník a AI asistent</h2>
    <?php $aiLib = function_exists('ai_sdk_ready') && ai_sdk_ready(); $aiKey = function_exists('ai_settings') && ai_settings()['key'] !== ''; ?>
    <ul class="ai-status">
      <li class="<?= $aiLib ? 'is-ok' : 'is-debt' ?>"><?= $aiLib ? '✓' : '✗' ?> Knihovna Anthropic na serveru<?= $aiLib ? '' : ' – chybí: ve složce webu spusťte <code>composer install</code>' ?></li>
      <li class="<?= $aiKey ? 'is-ok' : 'is-debt' ?>"><?= $aiKey ? '✓' : '✗' ?> Klíč API v <code>storage/config.php</code><?= $aiKey ? '' : ' – chybí (postup níže)' ?></li>
    </ul>
    <details<?= $aiLib && $aiKey ? '' : ' open' ?>><summary>Jak AI zapnout</summary>
      <ol>
        <li>Na <a href="https://platform.claude.com" target="_blank" rel="noopener noreferrer">platform.claude.com</a> si založte účet pro vývojáře (Claude Console), v části Billing nabijte kredit a v části API keys vytvořte klíč. <strong>Předplatné aplikace Claude (Pro, Max, Team) k tomu použít nejde</strong> – API je samostatná služba placená podle spotřeby.</li>
        <li>Ve složce webu spusťte <code>composer install</code> (stáhne oficiální knihovnu; na Macu nejdřív <code>brew install composer</code>).</li>
        <li>Do <code>storage/config.php</code> přidejte řádek <code>'ai' =&gt; ['api_key' =&gt; 'sk-ant-…'],</code> – klíč nikam jinam nevkládejte a neposílejte.</li>
        <li>Klikněte níže na <strong>Vyzkoušet spojení</strong>. Pak AI pomocník funguje na všech webech (správce ho může vypnout) a AI asistent na webech v edici Premium.</li>
      </ol></details>
    <p class="hint">Spotřebu platíte Vy z kreditu v Claude Console; společenství mají měsíční limit podle edice (tabulka výše) a mohou si dokoupit AI kredity. Anthropic účtuje <strong>tokeny</strong> (množství textu), web je u každého volání zapisuje.</p>
    <div class="form-row">
      <label>Cena vstupních tokenů (Kč za 1 milion) <input type="text" inputmode="decimal" name="ai_price_in" value="<?= e(str_replace('.', ',', $v['ai_price_in'])) ?>" pattern="\d{1,6}([,.]\d{1,4})?" required></label>
      <label>Cena výstupních tokenů (Kč za 1 milion) <input type="text" inputmode="decimal" name="ai_price_out" value="<?= e(str_replace('.', ',', $v['ai_price_out'])) ?>" pattern="\d{1,6}([,.]\d{1,4})?" required></label>
    </div>
    <p class="hint">Opište z ceníku v Claude Console pro model, který používáte, a přepočtěte z USD na Kč (např. 0,10 USD × kurz). Desetinná místa piště s čárkou. Vstup = otázka a podklady domu, výstup = odpověď. Levnější čtení z mezipaměti (cache) se počítá jako běžný vstup, odhad je tedy spíš vyšší.</p>
    <?php $ov = function_exists('ai_cost_overview') ? ai_cost_overview() : [0, 0, 0, 0.0, []]; [$ovUses, $ovIn, $ovOut, $ovCzk, $ovRows] = $ov; $creditUnit = pset_int('price_ai_pack', 0, 1000000) / max(1, pset_int('ai_pack', 1, 100000)); $priced = ai_token_price('ai_price_in') + ai_token_price('ai_price_out') > 0; ?>
    <div class="ai-costs" id="ai-naklady">
      <p class="h-small">Náklady na AI tento měsíc</p>
      <?php if (!$ovUses && !$ovIn): ?><p class="muted">Zatím žádné použití AI.</p><?php else: ?>
        <p>Všechny weby: <strong><?= $ovUses ?></strong> použití · <?= number_format($ovIn, 0, ',', "\u{a0}") ?> vstupních a <?= number_format($ovOut, 0, ',', "\u{a0}") ?> výstupních tokenů<?= $priced ? ' · <strong>≈ ' . ai_czk($ovCzk) . '</strong>' . ($ovUses ? ' (průměr ' . str_replace('.', ',', (string) round($ovCzk / $ovUses, 2)) . ' Kč za použití)' : '') : '' ?>.</p>
        <?php if ($priced && $ovUses && $creditUnit > 0): $avg = $ovCzk / $ovUses; ?>
          <p class="<?= $avg > $creditUnit ? 'is-debt' : 'is-ok' ?>"><strong><?= $avg > $creditUnit ? 'Pozor: jedno použití AI Vás stojí víc, než za kolik prodáváte kredity' : 'Kredity pokrývají náklady' ?></strong> – průměr <?= str_replace('.', ',', (string) round($avg, 2)) ?> Kč proti <?= str_replace('.', ',', (string) round($creditUnit, 2)) ?> Kč za použití v kreditech.</p>
        <?php elseif (!$priced): ?><p class="hint">Zadejte ceny tokenů výše a uvidíte náklady v korunách.</p><?php endif ?>
        <table class="table"><thead><tr><th>Společenství</th><th>Použití</th><th>Tokeny (vstup / výstup)</th><th>Náklad</th></tr></thead><tbody>
          <?php foreach (array_slice($ovRows, 0, 20, true) as [$name, $uses, $in, $out, $czk]): ?><tr><td data-label="Společenství"><?= e($name) ?></td><td data-label="Použití"><?= $uses ?></td><td data-label="Tokeny"><?= number_format($in, 0, ',', "\u{a0}") ?> / <?= number_format($out, 0, ',', "\u{a0}") ?></td><td data-label="Náklad"><?= $priced ? '≈ ' . ai_czk($czk) : '–' ?></td></tr><?php endforeach ?>
        </tbody></table>
      <?php endif ?>
    </div>
    <label>Model (nevyplněno = podle config.php) <input type="text" name="ai_model" value="<?= e($v['ai_model']) ?>" placeholder="claude-opus-5-5" pattern="[a-z0-9.\-]*"></label>
    <p class="hint">Menší model (například claude-haiku-5-5) je řádově levnější a na krátké texty obvykle stačí; nejsilnější píše nejlépe. Počet použití podle edice nastavíte v tabulce limitů výše (0 = bez omezení).</p>
    <?php $aiTest = $_SESSION['ai_test'] ?? null; unset($_SESSION['ai_test']); ?>
    <div id="ai-test" class="ai-test">
      <?php if ($aiTest): ?><p class="flash <?= $aiTest['ok'] ? 'flash-ok' : 'flash-error' ?>" role="status" data-scroll-into-view><?= e($aiTest['text']) ?></p><?php endif ?>
      <p class="actions"><button class="btn btn-ghost" type="submit" form="ai-test-form"<?= $aiLib && $aiKey ? '' : ' disabled aria-describedby="ai-test-why"' ?>>Vyzkoušet spojení s Claude</button></p>
      <?php if (!$aiLib || !$aiKey): ?><p class="hint" id="ai-test-why">Zkoušku spojení jde spustit, až budou oba body výše splněné (✓): <?= !$aiLib ? 'chybí knihovna' : '' ?><?= !$aiLib && !$aiKey ? ' a ' : '' ?><?= !$aiKey ? 'chybí klíč v storage/config.php' : '' ?>.</p><?php endif ?>
    </div>
    <label>Ceník podpory a úprav (jedna položka na řádek: název | cena) <textarea name="pricelist" rows="7"><?= e($v['pricelist']) ?></textarea></label>
    <h2 id="platby">Platby</h2>
    <label>Číslo účtu pro platby <input type="text" name="account" value="<?= e($v['account']) ?>" placeholder="19-2000145399/0800 nebo IBAN"></label>
    <p class="hint">Z čísla účtu se vytváří QR platba. Dokud není vyplněné, vidí správci společenství jen informaci, že platební údaje dostanou e-mailem.</p>
    <label>Název příjemce (nepovinné) <input type="text" name="recipient" value="<?= e($v['recipient']) ?>" maxlength="35"></label>
    <h2 id="vyhledavace">Vyhledávače</h2>
    <label>Ověření v Google Search Console <small class="muted">(nepovinné)</small> <input type="text" name="google_verification" value="<?= e($v['google_verification']) ?>" placeholder="kód z meta značky google-site-verification" maxlength="100"></label>
    <p class="hint">V Search Console zvolte ověření „Značka HTML“ a sem vložte jen kód z atributu content. Mapa stránek pro vyhledávače je na <code><?= e(platform_url('/sitemap.xml')) ?></code>.</p>
    <h2 id="kontakt">Kontakt a e-maily</h2>
    <div class="form-row">
      <label>Kontaktní e-mail (zobrazuje se na webu) <input type="email" name="contact_email" value="<?= e($v['contact_email']) ?>"></label>
      <label>Odesílatel e-mailů služby <input type="email" name="mail_from" value="<?= e($v['mail_from']) ?>"></label>
    </div>
    <p class="hint">Z adresy odesílatele chodí potvrzení registrace, připomenutí konce zkušební doby a potvrzení plateb. Bez ní se tyto e-maily neposílají. Nově založené weby ji dostanou jako výchozího odesílatele.</p>
    <?php $mq = mailq_status(); ?>
    <p class="hint" id="fronta-emailu"><strong>Odesílání e-mailů (všechny weby):</strong> za poslední hodinu <?= $mq['sent'] ?> z limitu <?= $mq['limits']['hour'] ?> (hromadné zprávy nejvýš <?= $mq['limits']['bulk'] ?> za hodinu).
      <?= $mq['pending'] ? 'Ve frontě čeká <strong>' . $mq['pending'] . '</strong>' . ($mq['bulk'] ? ' (hromadných ' . $mq['bulk'] . ')' : '') . ', nejstarší od ' . e(cz_datetime($mq['oldest'])) . '. Odcházejí postupně při návštěvách webů.' : 'Fronta je prázdná.' ?>
      <?= $mq['failed'] ? ' Za posledních 24 hodin se nepodařilo odeslat ' . $mq['failed'] . ' zpráv.' : '' ?> Limity: <code>'mail' =&gt; ['per_hour' =&gt; 800, 'bulk_per_hour' =&gt; 200]</code> v storage/config.php.</p>
    <h2 id="provozovatel">Provozovatel</h2>
    <p class="hint">Zobrazuje se v patičce úvodní stránky, u obchodních podmínek a jako dodavatel na fakturách.</p>
    <label>Název nebo jméno <input type="text" name="op_name" value="<?= e($v['op_name']) ?>" placeholder="Weby pro domy s.r.o."></label>
    <label>Sídlo <input type="text" name="op_address" value="<?= e($v['op_address']) ?>" placeholder="Ulice 1, 602 00 Brno"></label>
    <div class="form-row">
      <label>IČO <input type="text" name="op_ico" value="<?= e($v['op_ico']) ?>"></label>
      <label>DIČ (u plátce DPH povinné) <input type="text" name="op_dic" value="<?= e($v['op_dic']) ?>"></label>
      <label>Telefon <input type="text" name="op_phone" value="<?= e($v['op_phone']) ?>"></label>
    </div>
    <label>Zápis v rejstříku <input type="text" name="op_register" value="<?= e($v['op_register']) ?>" placeholder="Zapsáno u Krajského soudu v Brně, oddíl C, vložka 12345"></label>
    <label>Kde jsou data uložena (zobrazí se v častých otázkách) <input type="text" name="hosting_note" value="<?= e($v['hosting_note']) ?>" placeholder="Servery v České republice, denní zálohy na druhé místo v EU."></label>
    <h2 id="faktury">Faktury a DPH</h2>
    <label class="check"><input type="checkbox" name="vat_payer"<?= $v['vat_payer'] === '1' ? ' checked' : '' ?>> <span>Provozovatel je plátce DPH <small>Faktury pak budou daňové doklady s DIČ, základem daně a DPH. <strong>Ceny v tomto nastavení jsou bez DPH</strong> – daň se připočte k částce, kterou zákazník platí, a ceník to uvádí.</small></span></label>
    <div class="form-row">
      <label>Sazba DPH (%) <input type="number" name="vat_rate" value="<?= e($v['vat_rate']) ?>" min="0" max="100" required></label>
      <label>Splatnost zálohové faktury (dní) <input type="number" name="invoice_due_days" value="<?= e($v['invoice_due_days']) ?>" min="0" max="90" required></label>
    </div>
    <label>Poznámka na faktuře <input type="text" name="invoice_note" value="<?= e($v['invoice_note']) ?>"></label>
    <p class="hint">Faktura se vystaví sama, jakmile v části Platby potvrdíte přijetí platby; datem plnění je den přijetí platby. Zálohová faktura před platbou není daňovým dokladem. <?php $vs = vat_split(edition_price('standard'), (int) $v['vat_rate']) ?><?= $v['vat_payer'] === '1' ? 'Při ceně ' . money_czk(edition_price('standard')) . ' zákazník zaplatí ' . money_czk2($vs['total']) . ' (z toho DPH ' . money_czk2($vs['vat']) . ').' : 'Neplátce nechává poznámku „Dodavatel není plátcem DPH.“' ?> Podobu dokladů si nechte potvrdit účetní.</p>
    <h2 id="partneri">Partneři – množstevní sleva</h2>
    <label>Sleva podle počtu domů ve správě partnera <textarea name="partner_discounts" rows="4"><?= e($v['partner_discounts']) ?></textarea></label>
    <p class="hint">Na každý řádek „od kolika domů : sleva v %“, např. <code>5:10</code> znamená 10 % od 5 domů. Platí pro společnou fakturu partnera ze součtu cen všech domů na ní.</p>
    <h2 id="podminky">Obchodní podmínky</h2>
    <p class="hint">Výchozí text je návrh. Před otevřením registrace ho nechte projít právníkem. Formátování: řádek začínající ## je nadpis, **tučně**.</p>
    <label class="check"><input type="checkbox" name="legal_checked"<?= $v['legal_checked'] === '1' ? ' checked' : '' ?>> <span>Obchodní podmínky a smlouvu o zpracování údajů jsme zkontrolovali <small>Texty níže jsou vzor; před spuštěním je nechte projít právníkem a upravte podle své situace.</small></span></label>
    <label>Obchodní podmínky <textarea name="terms" rows="12"><?= e($v['terms']) ?></textarea></label>
    <label>Smlouva o zpracování osobních údajů <textarea name="dpa" rows="12"><?= e($v['dpa']) ?></textarea></label>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit</button></div>
  </form>
<form id="ai-test-form" method="post" action="<?= url('/nastaveni/ai-test') ?>" hidden><?= csrf_field() ?></form>
  <section class="card" id="isir">
    <h2>Insolvenční rejstřík</h2>
    <p>Hlídání insolvencí vlastníků-firem (podle IČO) přes veřejnou webovou službu insolvenčního rejstříku. Je zdarma. Zapněte ho, až zkouška níže z tohoto serveru projde – ukáže, co rejstřík skutečně vrátil.</p>
    <p>Stav: <strong><?= e(BETA_STAGES[beta_stage('insolvence')]) ?></strong> – mění se v části <a href="#beta">Nové funkce (beta program)</a> níže.</p>
    <?php $it = $_SESSION['isir_test'] ?? null; unset($_SESSION['isir_test']); if ($it): ?>
    <div class="callout">
      <strong><?= $it['ok'] ? 'Spojení funguje' : 'Zkouška se nepovedla' ?></strong> (IČO <?= e($it['ico']) ?>)<?= $it['error'] !== '' ? ': ' . e($it['error']) : '' ?><br>
      Nalezená řízení: <?= count($it['found']) ?><?php foreach ($it['found'] as $f): ?><br>· <?= e($f['sz']) ?> <?= e($f['name']) ?> <?= e($f['state']) ?><?php endforeach ?>
      <?php if ($it['raw'] !== ''): ?><details><summary>Odpověď rejstříku</summary><pre class="report-trace"><?= e($it['raw']) ?></pre></details><?php endif ?>
    </div>
    <?php endif ?>
    <form method="post" action="<?= url('/nastaveni/isir') ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="test">
      <div class="form-row"><label>IČO ke zkoušce <input type="text" name="ico" inputmode="numeric" maxlength="8" required placeholder="např. IČO firmy, která je v insolvenci"></label>
        <label>Řízení <select name="scope"><option value="active">jen probíhající</option><option value="all">i ukončená</option></select></label></div>
      <p class="hint">Pro průkaznou zkoušku zadejte IČO firmy, o které víte, že v rejstříku je (najdete ji na isir.justice.cz) – a pro srovnání i IČO bez řízení.</p>
      <button class="btn btn-ghost" type="submit">Vyzkoušet spojení</button></form>
  </section>
  <section class="card" id="beta">
    <h2>Nové funkce (beta program)</h2>
    <p>Novou funkci můžete nejdřív pustit jen edici Premium (slib „přístup k novým funkcím ještě před spuštěním“ v ceníku). Premium domy ji uvidí se štítkem <span class="tag tag-beta">Beta</span>, výzvou k ohlasu a v kartě „Vyzkoušejte“. Až je vyladěná, přepněte ji na <em>spuštěno</em> – pak ji dostanou edice, pro které je určená.</p>
    <form method="post" action="<?= url('/nastaveni/beta') ?>" class="form"><?= csrf_field() ?>
      <table class="table"><thead><tr><th>Funkce</th><th>Stav</th></tr></thead><tbody>
        <?php foreach (BETA_FEATURES as $bk => [$bn]): ?><tr><td data-label="Funkce"><?= e($bn) ?></td>
          <td data-label="Stav"><select name="stage[<?= e($bk) ?>]" aria-label="Stav: <?= e($bn) ?>"><?php foreach (BETA_STAGES as $sk => $sl): ?><option value="<?= $sk ?>"<?= beta_stage($bk) === $sk ? ' selected' : '' ?>><?= e($sl) ?></option><?php endforeach ?></select></td></tr><?php endforeach ?>
      </tbody></table>
      <div class="actions"><button class="btn" type="submit">Uložit</button></div></form>
  </section>
  <section class="card" id="prenos">
    <h2>Přenos nastavení na jiný web služby</h2>
    <p>Např. ze skutečného webu na zkušební: stáhněte nastavení zde a na druhém webu ho načtěte. Přenesou se jen údaje z této stránky (provozovatel, účet, ceny, limity, texty a podmínky) – žádná společenství, uživatelé ani platby. Klíč pro AI ani přístup pro AI k hlášením se nepřenášejí.</p>
    <div class="actions"><a class="btn" href="<?= url('/nastaveni/export') ?>">Stáhnout nastavení</a></div>
    <form method="post" action="<?= url('/nastaveni/import') ?>" enctype="multipart/form-data" class="form" data-confirm="Přepsat nastavení služby hodnotami ze souboru?">
      <?= csrf_field() ?>
      <label>Načíst nastavení ze souboru (.json) <input type="file" name="file" accept=".json,application/json" required></label>
      <div class="actions"><button class="btn btn-ghost" type="submit">Načíst a přepsat nastavení</button></div>
    </form>
  </section>
</div>
