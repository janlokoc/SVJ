// Ověření návodů v nápovědě a pořízení snímků obrazovky.
//
//   node tools/napoveda/overit.js
//
// Spustí web nad dočasnou složkou (proměnná SVJ_STORAGE – skutečná data se nepoužijí), založí ukázkové
// společenství, projde každý návod krok za krokem jako člověk v prohlížeči, ověří výsledky a uloží
// snímky do assets/help/. Do assets/help/overeno.json zapíše datum a počet kontrol; když některá
// kontrola selže, soubor nepřepíše a skončí chybou – návod pak neplatí a je potřeba ho opravit.
// Potřebuje PHP a Playwright (npm i playwright; npx playwright install chromium).
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');

const ROOT = path.resolve(__dirname, '../..');
const OUT = path.join(ROOT, 'assets/help');
const PORT = 8790 + Math.floor(Math.random() * 100);
const STORE = fs.mkdtempSync(path.join(os.tmpdir(), 'svj-napoveda-'));
const BASE = `http://localhost:${PORT}`;
const T = `http://dum.localhost:${PORT}`;
const results = {};
const failures = [];
let current = null;

const check = (ok, what) => { results[current].checks++; if (!ok) failures.push(`${current}: ${what}`); };
const step = (text) => results[current].steps.push(text);
const begin = (slug, note = '') => { current = slug; results[slug] = { date: new Date().toISOString().slice(0, 10), checks: 0, steps: [], note }; };
const env = { ...process.env, SVJ_STORAGE: STORE };
const sql = (q) => execFileSync('php', ['-r', '$f = glob(getenv("SVJ_STORAGE") . "/tenants/dum/*.sqlite")[0]; $p = new PDO("sqlite:$f"); echo $p->query(getenv("Q"))->fetchColumn();'], { env: { ...env, Q: q } }).toString().trim();
const run = (q) => execFileSync('php', ['-r', '$f = glob(getenv("SVJ_STORAGE") . "/tenants/dum/*.sqlite")[0]; $p = new PDO("sqlite:$f"); $p->exec(getenv("Q"));'], { env: { ...env, Q: q } });
const post = (url, body, type) => new Promise((resolve, reject) => {
  const u = new URL(url);
  const req = http.request({ host: '127.0.0.1', port: PORT, path: u.pathname, method: 'POST', headers: { Host: u.host, 'Content-Type': type, 'Content-Length': Buffer.byteLength(body) } }, (res) => {
    let d = ''; res.on('data', (c) => { d += c; }); res.on('end', () => resolve({ status: res.statusCode, body: d }));
  });
  req.on('error', reject); req.end(body);
});
// Snímek prvku; vysoké prvky (tabulky, celé stránky) se oříznou na horní část – víc v návodu není potřeba.
const shot = async (locator, file, maxHeight = 900, dir = OUT) => {
  // Plovoucí záhlaví webu by na snímku překrylo horní okraj – pro snímek se ukotví na místě.
  await locator.page().evaluate(() => document.querySelectorAll('body *').forEach((el) => { const p = getComputedStyle(el).position; if ((p === 'sticky' || p === 'fixed') && !el.closest('.presence-bar')) el.style.position = 'static'; }));
  await locator.scrollIntoViewIfNeeded();
  const box = await locator.boundingBox();
  const page = locator.page();
  if (box.height <= maxHeight) { await locator.screenshot({ path: path.join(dir, file), type: 'jpeg', quality: 72 }); return; }
  const y = await page.evaluate((top) => { window.scrollTo(0, top + window.scrollY - 10); return window.scrollY; }, box.y);
  const b2 = await locator.boundingBox();
  await page.screenshot({ path: path.join(dir, file), type: 'jpeg', quality: 72, clip: { x: b2.x, y: b2.y, width: b2.width, height: maxHeight } });
};

(async () => {
  fs.writeFileSync(path.join(STORE, 'config.php'), "<?php\nreturn ['platform' => ['domain' => 'localhost']];\n");
  const server = spawn('php', ['-d', 'sendmail_path=true', '-S', `127.0.0.1:${PORT}`, 'index.php'], { cwd: ROOT, env, stdio: 'ignore' });
  const b = await chromium.launch();
  try {
    for (let i = 0; i < 50; i++) { try { await new Promise((ok, ko) => http.get(`${BASE}/`, (r) => { r.resume(); ok(); }).on('error', ko)); break; } catch (e) { await new Promise((r) => setTimeout(r, 100)); } }
    // Platforma a ukázkové společenství
    const s = await b.newPage(); await s.goto(BASE + '/sprava');
    const code = fs.readFileSync(path.join(STORE, fs.readdirSync(STORE).find((f) => f.startsWith('instalacni-kod-platformy-'))), 'utf8').trim();
    await s.fill('#p-code', code); await s.fill('[name=name]', 'Provozovatel'); await s.fill('[name=email]', 'provoz@example.cz'); await s.fill('[name=password]', 'Provozovatel-overeni-1'); await s.click('.form button');
    const who = JSON.parse(execFileSync('php', [path.join(__dirname, 'priprava.php')], { env, cwd: ROOT }).toString());
    const login = async (page, email) => { await page.goto(T + '/prihlaseni'); await page.fill('[name=email]', email); await page.fill('[name=password]', who.password); await page.click('main form button[type=submit]'); };
    const admin = await (await b.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2, locale: 'cs-CZ' })).newPage(); admin.on('dialog', (d) => d.accept()); admin.setDefaultTimeout(10000);
    await login(admin, who.admin);
    const owner = await (await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, locale: 'cs-CZ' })).newPage(); owner.on('dialog', (d) => d.accept()); owner.setDefaultTimeout(10000);
    await login(owner, who.owner);
    const text = async (page, sel = 'main') => (await page.textContent(sel)).replace(/\s+/g, ' ');

    // ---------- Shromáždění ----------
    begin('shromazdeni');
    const date = new Date(Date.now() + 10 * 86400000).toISOString().slice(0, 10);
    await admin.goto(T + '/admin/terminy/novy');
    await admin.fill('[name=title]', 'Řádné shromáždění vlastníků'); await admin.fill('[name=event_date]', date); await admin.fill('[name=event_time]', '18:00'); await admin.fill('[name=place]', 'Kočárkárna');
    await admin.check('[name=rsvp]');
    await admin.fill('[name=program]', '- Zahájení shromáždění\n- Volba zapisovatele\n- Schválení účetní závěrky za rok 2026\nPodklady jsou v Dokumentech.\n- Zvýšení příspěvku do fondu oprav na 50 Kč/m²\n- Diskuze\n- Ukončení shromáždění');
    await admin.$eval('[name=program]', (t) => { t.rows = 8; t.scrollTop = 0; t.blur(); });
    await shot(admin.locator('main form'), 'shrom-1-termin.jpg', 1150);
    await admin.click('main form button[type=submit]');
    const evId = sql("SELECT id FROM events WHERE title = 'Řádné shromáždění vlastníků'");
    check(!!evId, 'termín se uložil'); step('Termín s programem a přihlašováním se uložil.');
    await admin.goto(T + '/admin/terminy'); await admin.click(`a[href$="/admin/zpravy/nova?termin=${evId}"]`);
    check((await admin.inputValue('[name=subject]')).includes('Řádné shromáždění vlastníků') && (await admin.inputValue('[name=body]')).includes('/terminy/' + evId), 'Pozvat e-mailem předvyplní pozvánku s odkazem'); step('Odkaz „Pozvat e-mailem“ předvyplnil pozvánku s odkazem na přihlášení.');

    await owner.goto(T + '/terminy/' + evId);
    await owner.fill('.rsvp-card [name=proxy_name]', 'Marie Nováková'); await owner.click('.rsvp-card button[value=proxy]');
    const pdf = path.join(STORE, 'plna-moc.pdf'); fs.writeFileSync(pdf, '%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');
    await owner.setInputFiles('.proxy-upload input[type=file]', pdf); await owner.click('.proxy-upload button[type=submit]');
    check((await text(owner)).includes('Nahráno: plna-moc.pdf'), 'vlastník nahrál plnou moc');
    check(!!(await owner.$('a:has-text("Vytisknout plnou moc")')), 'odkaz na předvyplněnou plnou moc'); step('Vlastník zvolil zastoupení a nahrál podepsanou plnou moc.');
    await shot(owner.locator('.rsvp-card').first(), 'shrom-2-prihlaska.jpg');
    const ownerUnit = await owner.getAttribute('.rsvp-card input[name=unit_id]', 'value');

    await admin.goto(T + `/admin/terminy/${evId}/ucast`);
    check((await text(admin)).includes('plná moc nahrána'), 'správce vidí nahranou plnou moc'); step('Správce v přihláškách vidí zastoupení i nahranou plnou moc.');
    await admin.click('a:has-text("Prezence na místě")');
    const rows = await admin.$$('.presence-row');
    const want = Math.ceil(rows.length * 0.6);
    for (let i = 0; i < want; i++) {
      const id = await rows[i].getAttribute('id');
      if (id === 'j' + ownerUnit) continue;
      await admin.click(`#${id} input[name=mode][value=self] + span`);
      await admin.waitForFunction((x) => document.querySelector('#' + x + ' .presence-state').textContent === 'uloženo', id);
    }
    await admin.click(`#j${ownerUnit} input[name=mode][value=proxy] + span`);
    await admin.waitForFunction((x) => document.querySelector('#j' + x + ' .presence-state').textContent === 'uloženo', ownerUnit);
    const lastId = await rows[rows.length - 1].getAttribute('id');
    await admin.click(`#${lastId} input[name=mode][value=proxy] + span`); await admin.fill(`#${lastId} [name=proxy_name]`, 'Josef Svoboda'); await admin.check(`#${lastId} [name=proxy_ok]`);
    await admin.waitForFunction((x) => document.querySelector('#' + x + ' .presence-state').textContent === 'uloženo', lastId);
    await admin.reload();
    const verdict = await admin.textContent('[data-p=verdict]');
    check(verdict.includes('je usnášeníschopné'), 'prezence: usnášeníschopnost'); check((await admin.textContent('[data-p=missing-wrap]')) !== null && await admin.$eval('[data-p=missing-wrap]', (x) => x.hidden), 'plné moci předloženy');
    step(`Prezence: přítomno ${(await admin.textContent('[data-p=percent]')).trim()} hlasů, shromáždění je usnášeníschopné.`);
    await admin.evaluate(() => window.scrollTo(0, 0)); await shot(admin.locator('.presence-bar'), 'shrom-3-prezence-soucet.jpg');
    await shot(admin.locator('.presence-table'), 'shrom-4-prezence-tabulka.jpg', 560);

    const checked = await admin.$$eval('.motion-program input[type=checkbox]:checked', (x) => x.map((y) => y.parentElement.textContent.trim()));
    check(checked.length === 3 && !checked.some((c) => /Zahájení|Diskuze|Ukončení/.test(c)), 'z programu předvybrány jen body k rozhodnutí'); step('Z programu web předvybral 3 body k hlasování; zahájení, diskuzi a ukončení ne.');
    await shot(admin.locator('.motion-program'), 'shrom-5-z-programu.jpg');
    await admin.click('.motion-program button'); await admin.waitForURL('**#navrhy');
    const card = admin.locator('.motion').nth(2);
    await card.locator('details.motion-votes').evaluate((d) => { d.open = true; });
    const voters = card.locator('.vote-list li:not([hidden])');
    await voters.nth(0).locator('input[value=no] + span').click(); await voters.nth(1).locator('input[value=no] + span').click(); await voters.nth(2).locator('input[value=abstain] + span').click();
    await admin.waitForTimeout(600);
    const mv = await card.locator('[data-m=verdict]').textContent();
    check(/Návrh (prošel|neprošel)/.test(mv), 'výsledek návrhu'); step('U návrhu „Zvýšení příspěvku do fondu oprav“: 2 proti, 1 se zdržel – ' + mv.trim());
    await shot(card, 'shrom-6-hlasovani.jpg');
    fs.mkdirSync(path.join(ROOT, 'assets/landing'), { recursive: true });
    await shot(card, 'hlasovani.jpg', 470, path.join(ROOT, 'assets/landing'));
    await card.locator('button[value=motion-close]').click(); await admin.waitForURL('**#m*');
    check((await admin.locator('.motion').nth(2).textContent()).includes('uzavřeno'), 'návrh uzavřen');
    while (await admin.$('.motion button[value=motion-close]')) { await admin.click('.motion button[value=motion-close]'); await admin.waitForLoadState(); }
    await admin.goto(T + `/admin/terminy/${evId}/zapis`);
    const z = await text(admin);
    check(z.includes('Hlasování na shromáždění') && z.includes('Zvýšení příspěvku do fondu oprav'), 'výsledky v zápisu: ' + z.slice(0, 600)); step('Výsledky hlasování a účast se propsaly do zápisu.');
    await admin.evaluate(() => { const h = [...document.querySelectorAll('.minutes h2')].find((x) => x.textContent.includes('Hlasování na shromáždění')); const wrap = document.createElement('div'); wrap.id = 'zapis-hlasovani'; h.before(wrap); let n = h; const parts = []; while (n && !(n !== h && n.tagName === 'H2')) { parts.push(n); n = n.nextElementSibling; } parts.forEach((x) => wrap.appendChild(x)); });
    await shot(admin.locator('#zapis-hlasovani'), 'shrom-7-zapis.jpg');

    // ---------- Dálkové odečty: příjem dat ----------
    begin('odecty-api');
    await admin.goto(T + '/admin/odecty/dalkove');
    await admin.click('#prijem button:has-text("Zapnout")');
    const api = await admin.inputValue('#prijem input');
    check(/\/api\/odecty\/[a-f0-9]{40}$/.test(api), 'adresa pro příjem'); step('Správce zapnul automatický příjem a získal adresu.');
    await admin.$eval('#prijem input', (x) => { x.value = x.value.replace(/[a-f0-9]{40}$/, '••••••••••••••••'); });
    await shot(admin.locator('#prijem'), 'api-1-adresa.jpg');
    const unit = sql(`SELECT entrance || '/' || number FROM units WHERE id = ${ownerUnit}`);
    const day = new Date().toISOString().slice(0, 10);
    const jsonReq = JSON.stringify([{ unit, meter_no: 'TV-104233', kind: 'tv', amount: 41.27, date: day }, { unit: 'Z/99', kind: 'tv', amount: 3, date: day }], null, 2);
    const r1 = await post(api, jsonReq, 'application/json');
    check(r1.status === 200 && JSON.parse(r1.body).saved === 1 && JSON.parse(r1.body).errors.length === 1, 'JSON: 1 uloženo, 1 chyba');
    const csvReq = `jednotka;číslo měřidla;druh;stav;datum odečtu\n${unit};TV-104233;teplá voda;42,05;${day}`;
    const r2 = await post(api, csvReq, 'text/csv');
    check(r2.status === 200 && JSON.parse(r2.body).ok === true, 'CSV přijato');
    const r4 = await post(api, JSON.stringify([{ meter_no: 'TV-104233', kind: 'tv', amount: 43.5, date: new Date(Date.now() - 86400000).toISOString().slice(0, 10) }]), 'application/json');
    check(r4.status === 200 && JSON.parse(r4.body).saved === 1, 'jednotku lze vynechat, když web zná číslo měřidla');
    const r3 = await post(api.replace(/[a-f0-9]{40}$/, '0'.repeat(40)), '[]', 'application/json');
    check(r3.status === 403, 'špatný klíč odmítnut');
    step('Poslán JSON (1 řádek uložen, 1 s chybou), CSV (stav přepsán), JSON jen s číslem měřidla (uložen) a požadavek se špatným klíčem (odmítnut 403).');
    fs.writeFileSync(path.join(OUT, 'api-ukazka.json'), JSON.stringify({ unit, jsonRequest: jsonReq, jsonResponse: JSON.stringify(JSON.parse(r1.body), null, 2), csvRequest: csvReq, csvResponse: JSON.stringify(JSON.parse(r2.body), null, 2), badStatus: r3.status, badResponse: r3.body }, null, 2) + '\n');
    await owner.goto(T + '/odecty');
    const ot = await text(owner); check(ot.includes('Spotřeba po měsících') && ot.includes('dálkový odečet'), 'obyvatel vidí dálkový odečet'); step('Obyvatel vidí na stránce Odečty přijaté stavy se zdrojem „dálkový odečet“.');

    // ---------- Snímky pro úvodní stránku služby (assets/landing) ----------
    const LP = path.join(ROOT, 'assets/landing'); fs.mkdirSync(LP, { recursive: true });
    const lpShot = async (page, file) => { await page.evaluate(() => window.scrollTo(0, 0)); await page.screenshot({ path: path.join(LP, file), type: 'jpeg', quality: 74 }); };
    const desk = async (email) => { const p = await (await b.newContext({ viewport: { width: 1280, height: 800 }, locale: 'cs-CZ', colorScheme: 'light' })).newPage(); await login(p, email); return p; };
    const res = await desk(who.owner); await res.goto(T + '/'); await lpShot(res, 'web-obyvatel.jpg');
    const adm = await desk(who.admin); await adm.goto(T + '/admin'); await lpShot(adm, 'administrace.jpg');
    const phone = await (await b.newContext({ viewport: { width: 390, height: 780 }, deviceScaleFactor: 2, locale: 'cs-CZ', colorScheme: 'light' })).newPage(); await login(phone, who.owner); await phone.goto(T + '/'); await lpShot(phone, 'telefon.jpg');
    check(fs.existsSync(path.join(LP, 'telefon.jpg')), 'snímky pro úvodní stránku');

    // ---------- Import z katastru ----------
    begin('import-z-katastru', 'Kopírování ze stránky Nahlížení do KN nešlo ověřit automaticky – web katastru strojové procházení blokuje. Ověřeno je vložení textu ve stejné podobě, v jaké ho stránka katastru zkopíruje.');
    // Nové společenství zatím jednotky nemá – stejně jako při prvním importu.
    run('DELETE FROM user_units; DELETE FROM units;');
    await admin.goto(T + '/admin/jednotky');
    await admin.click('a:has-text("Import z katastru")');
    await shot(admin.locator('main form'), 'katastr-1-vlozit.jpg');
    await admin.fill('#cadastre-text', fs.readFileSync(path.join(__dirname, 'katastr-ukazka.txt'), 'utf8'));
    await admin.click('button[value=preview]');
    const pv = await text(admin);
    check(pv.includes('Nalezeno jednotek: 6') && pv.includes('100,0 %') && pv.includes('sedí'), 'náhled: 6 jednotek, podíly 100 %'); step('Náhled našel 6 jednotek a součet podílů 100 %.');
    await shot(admin.locator('main'), 'katastr-2-nahled.jpg', 1000);
    await admin.click('button[value=save]');
    const done = await text(admin, '.flash');
    check(done.includes('nových jednotek 6'), 'uloženo 6 nových jednotek: ' + done); step('Po uložení vzniklo 6 jednotek s vlastníky a podíly.');
    check(sql('SELECT COUNT(*) FROM units') === '6', 'jednotky v databázi: ' + sql("SELECT group_concat(entrance || '/' || number) FROM units"));
    await shot(admin.locator('main'), 'katastr-3-hotovo.jpg', 700);
  } catch (e) {
    failures.push('přerušeno: ' + e.message.split('\n')[0]);
  } finally {
    await b.close(); server.kill(); fs.rmSync(STORE, { recursive: true, force: true });
  }
  for (const [slug, r] of Object.entries(results)) console.log(`${slug}: ${r.checks} kontrol\n  - ${r.steps.join('\n  - ')}`);
  if (failures.length) { console.log('\nSELHALO – návody neplatí:\n' + failures.join('\n')); process.exit(1); }
  fs.writeFileSync(path.join(OUT, 'overeno.json'), JSON.stringify(results, null, 2) + '\n');
  console.log('\nVše ověřeno, snímky a overeno.json uloženy v assets/help/.');
})();
