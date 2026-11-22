<div class="wrap page narrow">
  <h1>Nastavení služby</h1>
  <p class="lead">Ceny, zkušební doba, platební údaje a texty úvodní stránky na adrese <a href="<?= e(platform_url()) ?>"><?= e(platform_domain()) ?></a>.</p>
  <?php require __DIR__ . '/../_errors.php'; ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <h2>Služba a registrace</h2>
    <label>Název služby <input type="text" name="service_name" value="<?= e($v['service_name']) ?>" required></label>
    <label class="check"><input type="checkbox" name="signup_open"<?= $v['signup_open'] === '1' ? ' checked' : '' ?>> <span>Registrace je otevřená <small>Kdokoli si může z úvodní stránky sám založit web společenství. Po vypnutí zakládáte weby jen Vy.</small></span></label>
    <label class="check"><input type="checkbox" name="demo_auto"<?= $v['demo_auto'] === '1' ? ' checked' : '' ?>> <span>Ukázkový web <small>Na adrese <?= e(tenant_address('demo')) ?> běží smyšlené společenství jen pro čtení a úvodní stránka na něj odkazuje. Pokud neexistuje, založí se sám. Po vypnutí ho můžete smazat v seznamu společenství.</small></span></label>
    <div class="form-row">
      <label>Zkušební doba zdarma (dní) <input type="number" name="trial_days" value="<?= e($v['trial_days']) ?>" min="0" max="365" required></label>
      <label>Web běží po splatnosti ještě (dní) <input type="number" name="grace_days" value="<?= e($v['grace_days']) ?>" min="0" max="365" required></label>
    </div>
    <label>Nezaplacený zkušební web smazat po (dní od konce zkušební doby) <input type="number" name="delete_days" value="<?= e($v['delete_days']) ?>" min="0" max="3650" required></label>
    <p class="hint">Týká se jen webů, které po zkušební době nikdy nezaplatily. Kontaktní osobě přijde upozornění 14 dní a 3 dny před smazáním a potvrzení po něm; bez prokazatelně odeslaného upozornění se nic nemaže. 0 = nemazat. Všechna odeslaná upozornění vidíte u společenství a v Záznamech.</p>
    <h2>Cena</h2>
    <label>Platí se <select name="period"><option value="month"<?= $v['period'] === 'month' ? ' selected' : '' ?>>měsíčně</option><option value="year"<?= $v['period'] === 'year' ? ' selected' : '' ?>>ročně</option></select></label>
    <div class="form-row">
      <label>Cena za období v prvním roce (Kč) <input type="number" name="price_first" value="<?= e($v['price_first']) ?>" min="0" required></label>
      <label>Cena za období v dalších letech (Kč) <input type="number" name="price_next" value="<?= e($v['price_next']) ?>" min="0" required></label>
    </div>
    <label>Vlastní doména společenství (Kč za rok) <input type="number" name="price_domain" value="<?= e($v['price_domain']) ?>" min="0" required></label>
    <label>Pokyny pro společenství, které doménu už vlastní <textarea name="domain_dns" rows="3" placeholder="Nastavte záznam A pro tabor48.cz a www.tabor48.cz na adresu 203.0.113.10"><?= e($v['domain_dns']) ?></textarea></label>
    <p class="hint">O doménu žádá správce společenství v části Předplatné. Žádost uvidíte u společenství; po zaplacení, nasměrování domény na server a zapnutí HTTPS ji tam zprovozníte.</p>
    <label>Ceník podpory a úprav (jedna položka na řádek: název | cena) <textarea name="pricelist" rows="7"><?= e($v['pricelist']) ?></textarea></label>
    <h2>Platby</h2>
    <label>Číslo účtu pro platby <input type="text" name="account" value="<?= e($v['account']) ?>" placeholder="19-2000145399/0800 nebo IBAN"></label>
    <p class="hint">Z čísla účtu se vytváří QR platba. Dokud není vyplněné, vidí správci společenství jen informaci, že platební údaje dostanou e-mailem.</p>
    <label>Název příjemce (nepovinné) <input type="text" name="recipient" value="<?= e($v['recipient']) ?>" maxlength="35"></label>
    <h2>Kontakt a e-maily</h2>
    <div class="form-row">
      <label>Kontaktní e-mail (zobrazuje se na webu) <input type="email" name="contact_email" value="<?= e($v['contact_email']) ?>"></label>
      <label>Odesílatel e-mailů služby <input type="email" name="mail_from" value="<?= e($v['mail_from']) ?>"></label>
    </div>
    <p class="hint">Z adresy odesílatele chodí potvrzení registrace, připomenutí konce zkušební doby a potvrzení plateb. Bez ní se tyto e-maily neposílají. Nově založené weby ji dostanou jako výchozího odesílatele.</p>
    <h2>Provozovatel</h2>
    <p class="hint">Zobrazuje se v patičce úvodní stránky, u obchodních podmínek a jako dodavatel na fakturách.</p>
    <label>Název nebo jméno <input type="text" name="op_name" value="<?= e($v['op_name']) ?>" placeholder="Weby pro domy s.r.o."></label>
    <label>Sídlo <input type="text" name="op_address" value="<?= e($v['op_address']) ?>" placeholder="Ulice 1, 602 00 Brno"></label>
    <div class="form-row">
      <label>IČO <input type="text" name="op_ico" value="<?= e($v['op_ico']) ?>"></label>
      <label>DIČ (nepovinné) <input type="text" name="op_dic" value="<?= e($v['op_dic']) ?>"></label>
      <label>Telefon <input type="text" name="op_phone" value="<?= e($v['op_phone']) ?>"></label>
    </div>
    <label>Zápis v rejstříku <input type="text" name="op_register" value="<?= e($v['op_register']) ?>" placeholder="Zapsáno u Krajského soudu v Brně, oddíl C, vložka 12345"></label>
    <h2>Faktury</h2>
    <div class="form-row">
      <label>Splatnost zálohové faktury (dní) <input type="number" name="invoice_due_days" value="<?= e($v['invoice_due_days']) ?>" min="0" max="90" required></label>
      <label>Poznámka na faktuře <input type="text" name="invoice_note" value="<?= e($v['invoice_note']) ?>"></label>
    </div>
    <p class="hint">Faktura se vystaví sama, jakmile v části Platby potvrdíte přijetí platby. Doklady počítají s dodavatelem, který není plátcem DPH; jste-li plátce, nechte si podobu faktury upravit.</p>
    <h2>Obchodní podmínky</h2>
    <p class="hint">Výchozí text je návrh. Před otevřením registrace ho nechte projít právníkem. Formátování: řádek začínající ## je nadpis, **tučně**.</p>
    <label>Obchodní podmínky <textarea name="terms" rows="12"><?= e($v['terms']) ?></textarea></label>
    <label>Smlouva o zpracování osobních údajů <textarea name="dpa" rows="12"><?= e($v['dpa']) ?></textarea></label>
    <div class="actions"><button class="btn btn-big" type="submit">Uložit</button></div>
  </form>
</div>
