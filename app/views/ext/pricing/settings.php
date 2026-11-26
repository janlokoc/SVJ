<?php /* Modul G: pole v Nastavení služby (uvnitř formuláře app/views/platform/settings.php, hodnoty v $v). */ ?>
    <h3 id="cena-velikost">Cena podle velikosti domu</h3>
    <div class="form-row">
      <label>Paušál platí do (jednotek) <input type="number" name="price_flat_units" value="<?= e($v['price_flat_units']) ?>" min="0" max="100000" required></label>
      <label>Přirážka Standard (Kč za jednotku a rok) <input type="number" name="price_unit_standard" value="<?= e($v['price_unit_standard']) ?>" min="0" max="100000" required></label>
      <label>Přirážka Premium (Kč za jednotku a rok) <input type="number" name="price_unit_premium" value="<?= e($v['price_unit_premium']) ?>" min="0" max="100000" required></label>
    </div>
    <p class="hint">Dům s více jednotkami zaplatí cenu edice a k ní přirážku za každou jednotku nad paušál (0 = bez přirážky). Počítá se z jednotek zapsaných na webu domu v den vystavení výzvy k platbě. Už zaplacená období se nemění. Například dům s 80 jednotkami: Standard <?= money_czk(edition_price('standard', 80)) ?>, Premium <?= money_czk(edition_price('premium', 80)) ?> za rok (podle uložených hodnot).</p>
    <label>Edice Zdarma jen pro domy do (jednotek; 0 = pro všechny) <input type="number" name="free_max_units" value="<?= e($v['free_max_units']) ?>" min="0" max="100000" required></label>
    <p class="hint">Větší dům, který ještě nic neplatil, dostane jednou <?= PLAN_TRIAL_DAYS ?> dní edice Standard na zkoušku. Potom si edici zaplatí, nebo web běží dál v omezeném režimu s limity edice Zdarma. Ceník to uvádí.</p>
    <label>Jednorázové hlasování (Kč; 0 = nenabízet) <input type="number" name="price_vote" value="<?= e($v['price_vote']) ?>" min="0" max="100000" required></label>
    <p class="hint">Web v edici Zdarma, který vyčerpal limit hlasování, si u formuláře nového hlasování objedná jedno hlasování navíc. Platí <?= VOTE_PASS_DAYS ?> dní od potvrzení platby v části Platby.</p>
    <h3 id="srovnani">Srovnání s konkurencí</h3>
    <label class="check"><input type="checkbox" name="compare_on" value="1"<?= $v['compare_on'] === '1' ? ' checked' : '' ?>> <span>Zveřejnit stránku <a href="<?= e(platform_url('/srovnani')) ?>">/srovnani</a> <small>Údaje o konkurenci jsou v <code>app/ext/compare_lib.php</code> s datem ověření a zdrojem u každé služby. Před zveřejněním je zkontrolujte (srovnávací reklama podle § 2980 občanského zákoníku: jen ověřitelné a srovnatelné údaje). Vypnutá stránka neexistuje.</small></span></label>
    <h3 id="aplikace">Aplikace pro Android</h3>
    <label>Název balíčku aplikace <input type="text" name="twa_package" value="<?= e($v['twa_package']) ?>" placeholder="cz.webprosvj.app" maxlength="150"></label>
    <label>Otisk podpisového certifikátu SHA-256 (víc otisků na samostatné řádky) <textarea name="twa_sha256" rows="2" placeholder="AB:CD:…"><?= e($v['twa_sha256']) ?></textarea></label>
    <p class="hint">Po vyplnění vrací adresa <code>/.well-known/assetlinks.json</code> na hlavní doméně i na všech webech domů potvrzení, že aplikace z Google Play smí web otevírat bez lišty prohlížeče. Otisk najdete v Google Play Console → Integrita aplikace → Podepisování aplikací. Prázdné = adresa neexistuje.</p>
