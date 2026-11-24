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
const https = require('https');

const ROOT = path.resolve(__dirname, '../..');
const OUT = path.join(ROOT, 'assets/help');
const PORT = 8790 + Math.floor(Math.random() * 100);
const STORE = fs.mkdtempSync(path.join(os.tmpdir(), 'svj-napoveda-'));
// Snímky mají ukazovat skutečné adresy služby: prohlížeč chodí na https://spolecnyweb.cz a https://dum.spolecnyweb.cz,
// které se přes pravidlo pro DNS a malý HTTPS prostředník (samopodepsaný certifikát) dostanou na místní PHP server.
const DOMAIN = 'spolecnyweb.cz';
const TLS_PORT = PORT + 100;
const BASE = `https://${DOMAIN}`;
const T = `https://dum.${DOMAIN}`;
const LAUNCH = { args: [`--host-resolver-rules=MAP ${DOMAIN} 127.0.0.1:${TLS_PORT}, MAP *.${DOMAIN} 127.0.0.1:${TLS_PORT}`, '--ignore-certificate-errors', '--proxy-server=direct://'] };
const results = {};
const failures = [];
let current = null;

const check = (ok, what) => { results[current].checks++; if (!ok) failures.push(`${current}: ${what}`); };
const step = (text) => results[current].steps.push(text);
const begin = (slug, note = '') => { current = slug; results[slug] = { date: new Date().toISOString().slice(0, 10), checks: 0, steps: [], note }; };
const env = { ...process.env, SVJ_STORAGE: STORE, SVJ_PUSH_TEST: '1' };
const crypto = require('crypto');
const sql = (q) => execFileSync('php', ['-r', '$f = glob(getenv("SVJ_STORAGE") . "/tenants/dum/*.sqlite")[0]; $p = new PDO("sqlite:$f"); echo $p->query(getenv("Q"))->fetchColumn();'], { env: { ...env, Q: q } }).toString().trim();
const run = (q) => execFileSync('php', ['-r', '$f = glob(getenv("SVJ_STORAGE") . "/tenants/dum/*.sqlite")[0]; $p = new PDO("sqlite:$f"); $p->exec(getenv("Q"));'], { env: { ...env, Q: q } });
const post = (url, body, type) => new Promise((resolve, reject) => {
  const u = new URL(url);
  const req = http.request({ host: '127.0.0.1', port: PORT, path: u.pathname, method: 'POST', headers: { Host: u.host, 'X-Forwarded-Proto': 'https', 'Content-Type': type, 'Content-Length': Buffer.byteLength(body) } }, (res) => {
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
  fs.writeFileSync(path.join(STORE, 'config.php'), `<?php\nreturn ['platform' => ['domain' => '${DOMAIN}']];\n`);
  // E-maily se neposílají ven – zapíšou se do souboru, ať jde ověřit i jejich obsah.
  const MAIL = path.join(STORE, 'mail.log'); fs.writeFileSync(path.join(STORE, 'mail.sh'), `#!/bin/sh\ncat >> '${MAIL}'\n`, { mode: 0o755 });
  const server = spawn('php', ['-d', `sendmail_path=${path.join(STORE, 'mail.sh')}`, '-S', `127.0.0.1:${PORT}`, 'index.php'], { cwd: ROOT, env, stdio: 'ignore' });
  execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', `/CN=${DOMAIN}`, '-addext', `subjectAltName=DNS:${DOMAIN},DNS:*.${DOMAIN}`,
    '-keyout', path.join(STORE, 'tls.key'), '-out', path.join(STORE, 'tls.crt')], { stdio: 'ignore' });
  const tls = https.createServer({ key: fs.readFileSync(path.join(STORE, 'tls.key')), cert: fs.readFileSync(path.join(STORE, 'tls.crt')) }, (req, res) => {
    const up = http.request({ host: '127.0.0.1', port: PORT, path: req.url, method: req.method, headers: { ...req.headers, 'x-forwarded-proto': 'https' } }, (r) => { res.writeHead(r.statusCode, r.headers); r.pipe(res); });
    up.on('error', () => { res.writeHead(502); res.end(); }); req.pipe(up);
  }).listen(TLS_PORT, '127.0.0.1');
  const b = await chromium.launch(LAUNCH);
  try {
    for (let i = 0; i < 50; i++) { try { await new Promise((ok, ko) => http.get(`http://127.0.0.1:${PORT}/`, (r) => { r.resume(); ok(); }).on('error', ko)); break; } catch (e) { await new Promise((r) => setTimeout(r, 100)); } }
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
    // Data za celé měsíce: stavy ke konci posledních tří měsíců (teplá voda a indikátory topení) → měsíční spotřeba.
    const endOf = (back) => { const d = new Date(); const x = new Date(d.getFullYear(), d.getMonth() - back + 1, 0); return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`; };
    const series = [[3, 38.10, 1210], [2, 40.35, 1302], [1, 42.80, 1388]].flatMap(([m, tv, itn]) => [{ unit, meter_no: 'TV-104233', kind: 'tv', amount: tv, date: endOf(m) }, { unit, meter_no: 'ITN-5521', kind: 'itn', amount: itn, date: endOf(m) }]);
    const r5 = await post(api, JSON.stringify(series), 'application/json');
    check(r5.status === 200 && JSON.parse(r5.body).errors.length === 0, 'stavy za tři měsíce přijaty');
    await owner.goto(T + '/odecty');
    const mt = await text(owner, '.meter-monthly');
    check(mt.includes('2,45') && mt.includes('86 dílků'), 'měsíční spotřeba spočítaná ze stavů'); step('Ze stavů na konci měsíců web spočítal měsíční spotřebu (teplá voda 2,45 m³, indikátory 86 dílků).');
    await admin.goto(T + '/admin/odecty/dalkove');
    await admin.click('#upozorneni button[type=submit]');
    const fl = await text(admin, 'main'); check(/Upozornění/.test(fl), 'měsíční upozornění odesláno: ' + fl.slice(0, 120));
    const ml = fs.existsSync(MAIL) ? fs.readFileSync(MAIL, 'utf8') : ''; check(ml.includes('petr.novak@example.cz') || ml.includes('marie.novakova@example.cz'), 'e-mail s upozorněním vlastníkovi');
    // Automatické upozornění: zapnout, „zapomenout“ ruční odeslání a nechat proběhnout denní úlohu webu.
    await admin.check('#nastaveni [name=auto]'); await admin.click('#nastaveni button[type=submit]');
    const ym = await admin.inputValue('#upozorneni [name=mesic]');
    run(`DELETE FROM settings WHERE key IN ('meter_notice_${ym}', 'last_daily')`);
    const before = fs.readFileSync(MAIL, 'utf8').length;
    await owner.goto(T + '/');
    const auto = fs.readFileSync(MAIL, 'utf8').slice(before);
    check(new Date().getDate() < 5 || /To: .*(petr\.novak|marie\.novakova)@example\.cz/.test(auto), 'automatické měsíční upozornění odešlo samo');
    check(new Date().getDate() < 5 || sql(`SELECT value FROM settings WHERE key = 'meter_notice_${ym}'`) !== '', 'automatické upozornění zaznamenané: ' + sql("SELECT group_concat(key || '=' || value) FROM settings WHERE key LIKE 'meter%' OR key = 'last_daily'"));
    step('Se zapnutým automatickým upozorněním odešel e-mail obyvatelům sám při denní úloze webu (od 5. dne v měsíci, jednou za měsíc).');
    {
      const LPd = path.join(ROOT, 'assets/landing'); fs.mkdirSync(LPd, { recursive: true });
      const ph = await (await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, locale: 'cs-CZ', colorScheme: 'light' })).newPage(); await login(ph, who.owner); await ph.goto(T + '/odecty');
      await ph.evaluate(() => { document.querySelectorAll('body *').forEach((el) => { const p = getComputedStyle(el).position; if (p === 'sticky' || p === 'fixed') el.style.position = 'static'; }); const m = document.querySelector('.meter-monthly'); window.scrollTo(0, m.getBoundingClientRect().top + window.scrollY - 70); });
      await ph.screenshot({ path: path.join(LPd, 'odecty-telefon.jpg'), type: 'jpeg', quality: 74 });
      const ad = await (await b.newContext({ viewport: { width: 1100, height: 900 }, deviceScaleFactor: 1.5, locale: 'cs-CZ', colorScheme: 'light' })).newPage(); await login(ad, who.admin); await ad.goto(T + '/admin/odecty/dalkove');
      await ad.$eval('#prijem input', (x) => { x.value = x.value.replace(/[a-f0-9]{40}$/, '••••••••••••••••'); });
      await ad.evaluate(() => document.querySelectorAll('body *').forEach((el) => { const p = getComputedStyle(el).position; if (p === 'sticky' || p === 'fixed') el.style.position = 'static'; }));
      await shot(ad.locator('main'), 'odecty-sprava.jpg', 700, LPd);
    }

    // ---------- Snímky pro úvodní stránku služby (assets/landing) ----------
    const LP = path.join(ROOT, 'assets/landing'); fs.mkdirSync(LP, { recursive: true });
    const lpShot = async (page, file) => { await page.evaluate(() => window.scrollTo(0, 0)); await page.screenshot({ path: path.join(LP, file), type: 'jpeg', quality: 74 }); };
    const desk = async (email) => { const p = await (await b.newContext({ viewport: { width: 1280, height: 800 }, locale: 'cs-CZ', colorScheme: 'light' })).newPage(); await login(p, email); return p; };
    const res = await desk(who.owner); await res.goto(T + '/'); await lpShot(res, 'web-obyvatel.jpg');
    const adm = await desk(who.admin); await adm.goto(T + '/admin'); await lpShot(adm, 'administrace.jpg');
    const phone = await (await b.newContext({ viewport: { width: 390, height: 780 }, deviceScaleFactor: 2, locale: 'cs-CZ', colorScheme: 'light' })).newPage(); await login(phone, who.owner); await phone.goto(T + '/'); await lpShot(phone, 'telefon.jpg');
    // Tablet: výbor na shromáždění odklikává prezenci na iPadu.
    // iPad na ležato (1180 × 820): prezence se součtem podílů.
    const tabL = await (await b.newContext({ viewport: { width: 1180, height: 820 }, deviceScaleFactor: 1.5, locale: 'cs-CZ', colorScheme: 'light' })).newPage(); await login(tabL, who.admin);
    await tabL.goto(T + `/admin/terminy/${evId}/prezence-na-miste`); await tabL.evaluate(() => { const h = document.querySelector('.page-head h1'); window.scrollTo(0, h.getBoundingClientRect().top + window.scrollY - 90); }); await tabL.screenshot({ path: path.join(LP, 'tablet.jpg'), type: 'jpeg', quality: 72 });
    const tab = await (await b.newContext({ viewport: { width: 820, height: 1180 }, deviceScaleFactor: 1.5, locale: 'cs-CZ', colorScheme: 'light' })).newPage(); await login(tab, who.admin);
    await tab.goto(T + `/admin/terminy/${evId}/prezence-na-miste`);
    // Krok 1 na úvodní stránce: součet podílů a první jednotky v čitelné velikosti (výřez z tabletu).
    await tab.evaluate(() => document.querySelectorAll('body *').forEach((el) => { const p = getComputedStyle(el).position; if (p === 'sticky' || p === 'fixed') el.style.position = 'static'; }));
    const bar = await tab.locator('.presence-bar').boundingBox(); await tab.evaluate((y) => window.scrollTo(0, y + window.scrollY - 16), bar.y);
    const bar2 = await tab.locator('.presence-bar').boundingBox();
    await tab.screenshot({ path: path.join(LP, 'prezence-soucet.jpg'), type: 'jpeg', quality: 74, clip: { x: 0, y: Math.max(0, bar2.y - 16), width: 820, height: 560 } });
    // Všechny tři kroky shromáždění ve stejném výřezu 820 × 560 (bez ořezu obsahu).
    const clipAt = async (page, el, file, w = 820) => { await page.evaluate(() => document.querySelectorAll('body *').forEach((x) => { const p = getComputedStyle(x).position; if (p === 'sticky' || p === 'fixed') x.style.position = 'static'; }));
      const bx = await page.locator(el).first().boundingBox(); await page.evaluate((y) => window.scrollTo(0, y + window.scrollY - 16), bx.y); const b2 = await page.locator(el).first().boundingBox();
      await page.screenshot({ path: path.join(LP, file), type: 'jpeg', quality: 74, clip: { x: 0, y: Math.max(0, b2.y - 16), width: w, height: Math.round(w * 560 / 820) } }); };
    // Krok 2: hlasování o návrhu – karta s výsledkem (pro / proti / zdrželo se) celá.
    await tab.setViewportSize({ width: 560, height: 1000 });
    await tab.evaluate(() => { const m = [...document.querySelectorAll('.motion')].find((x) => x.textContent.includes('fondu oprav')); m.id = 'lp-motion'; m.querySelector('.actions')?.remove();
      for (let el = m; el && el.tagName !== 'MAIN'; el = el.parentElement) { let n = el.nextElementSibling; while (n) { const next = n.nextElementSibling; n.remove(); n = next; } } });
    await clipAt(tab, '#lp-motion', 'hlasovani.jpg', 560);
    await tab.setViewportSize({ width: 820, height: 1180 });
    // Krok 3: zápis – nadpis, účast a výsledky hlasování (vynechá se hlavička, program a průběh jednání).
    await tab.goto(T + `/admin/terminy/${evId}/zapis`);
    await tab.evaluate(() => { const root = document.querySelector('.minutes'); root.querySelectorAll('.no-print, .pay-data, .minutes-ai, .fill-block, .callout').forEach((x) => x.remove());
      const keep = (h) => /Účast|Hlasování na shromáždění/.test(h.textContent); let drop = false;
      [...root.children].forEach((el) => { if (el.tagName === 'H2') drop = !keep(el); if (drop) el.remove(); });
      root.querySelector('p')?.remove();
      // první (jednomyslné) hlasování vynechat, ať je vidět návrh s hlasy proti; kapitoly přečíslovat
      const first = [...root.querySelectorAll('p')].find((x) => /^1\) /.test(x.textContent.trim())); first?.remove();
      [...root.querySelectorAll('h2')].forEach((h, i) => { h.textContent = h.textContent.replace(/^\d+\./, (i + 1) + '.'); });
      let k = 0; [...root.querySelectorAll('p strong')].forEach((st) => { if (/^\d+\) /.test(st.textContent)) st.textContent = st.textContent.replace(/^\d+\)/, (++k) + ')'); }); });
    await clipAt(tab, '.minutes h1', 'zapis.jpg');
    check(fs.existsSync(path.join(LP, 'telefon.jpg')), 'snímky pro úvodní stránku');

    // ---------- Import z katastru ----------
    // ---------- Upozornění do telefonu ----------
    begin('upozorneni-do-telefonu', 'Přidání webu na plochu na skutečném iPhonu a telefonu s Androidem jsme ověřit nemohli – ověřovací běh nemá skutečný telefon. Ověřeno je zapnutí na webu, šifrování a doručení zprávy přes zkušební push službu a zobrazení upozornění v prohlížeči.');
    // Zkušební push služba: přijme zprávu, ověří podpis VAPID a rozšifruje ji tak jako skutečný prohlížeč.
    const ua = crypto.createECDH('prime256v1'); ua.generateKeys(); const authSecret = crypto.randomBytes(16);
    const got = [];
    const pushSrv = http.createServer((req, res) => { const chunks = []; req.on('data', (c) => chunks.push(c)); req.on('end', () => {
      try {
        const body = Buffer.concat(chunks); const m = /vapid t=([^,]+), k=(.+)/.exec(req.headers.authorization || '');
        const [h, p, sig] = m[1].split('.'); const pub = Buffer.from(m[2], 'base64url');
        const vk = crypto.createPublicKey({ key: { kty: 'EC', crv: 'P-256', x: pub.subarray(1, 33).toString('base64url'), y: pub.subarray(33).toString('base64url') }, format: 'jwk' });
        const okSig = crypto.verify('sha256', Buffer.from(h + '.' + p), { key: vk, dsaEncoding: 'ieee-p1363' }, Buffer.from(sig, 'base64url'));
        const salt = body.subarray(0, 16), idlen = body[20], asPub = body.subarray(21, 21 + idlen), ct = body.subarray(21 + idlen);
        const secret = ua.computeSecret(asPub);
        const ikm = Buffer.from(crypto.hkdfSync('sha256', secret, authSecret, Buffer.concat([Buffer.from('WebPush: info\0'), ua.getPublicKey(), asPub]), 32));
        const cek = Buffer.from(crypto.hkdfSync('sha256', ikm, salt, Buffer.from('Content-Encoding: aes128gcm\0'), 16));
        const nonce = Buffer.from(crypto.hkdfSync('sha256', ikm, salt, Buffer.from('Content-Encoding: nonce\0'), 12));
        const d = crypto.createDecipheriv('aes-128-gcm', cek, nonce); d.setAuthTag(ct.subarray(ct.length - 16));
        const plain = Buffer.concat([d.update(ct.subarray(0, ct.length - 16)), d.final()]);
        got.push({ okSig, enc: req.headers['content-encoding'], msg: JSON.parse(plain.subarray(0, plain.length - 1).toString('utf8')) });
        res.writeHead(201); res.end();
      } catch (e) { got.push({ error: e.message }); res.writeHead(400); res.end(); }
    }); });
    await new Promise((r) => pushSrv.listen(0, '127.0.0.1', r));
    const endpoint = `http://127.0.0.1:${pushSrv.address().port}/push/${crypto.randomBytes(6).toString('hex')}`;
    const b2 = await chromium.launch({ channel: 'chromium', ...LAUNCH }).catch(() => b);   // starý režim bez okna oznámení nepovoluje
    const mobCtx = await b2.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, locale: 'cs-CZ', colorScheme: 'light' }); await mobCtx.grantPermissions(['notifications'], { origin: new URL(T).origin });
    const mob = await mobCtx.newPage(); mob.setDefaultTimeout(10000);
    await login(mob, who.owner); await mob.goto(T + '/muj-ucet#upozorneni');
    await mob.waitForFunction(() => document.querySelector('.push-state').textContent !== '');
    check(await mob.isVisible('[data-push-on]'), 'tlačítko Zapnout upozornění'); step('V „Můj účet“ je část Upozornění do telefonu s tlačítkem „Zapnout upozornění na tomto zařízení“.');
    await shot(mob.locator('#upozorneni'), 'push-1-zapnout.jpg');
    // Headless prohlížeč nemá spojení na push službu Googlu – přihlášení zařízení proto pošleme sami, s klíči zkušební služby.
    const subRes = await mob.evaluate(async ([url, ep, p256dh, auth]) => { const box = document.querySelector('[data-push]'); const f = new FormData(); f.append('_csrf', box.dataset.csrf); f.append('subscription', JSON.stringify({ endpoint: ep, keys: { p256dh, auth } })); const r = await fetch(url, { method: 'POST', body: f }); return r.json(); },
      [T + '/push/prihlasit', endpoint, ua.getPublicKey().toString('base64url'), authSecret.toString('base64url')]);
    check(subRes.ok === true, 'zařízení přihlášeno');
    const bad = await mob.evaluate(async (url) => { const box = document.querySelector('[data-push]'); const f = new FormData(); f.append('_csrf', box.dataset.csrf); f.append('subscription', JSON.stringify({ endpoint: 'https://example.com/x', keys: { p256dh: 'x', auth: 'y' } })); return (await fetch(url, { method: 'POST', body: f })).status; }, T + '/push/prihlasit');
    check(bad === 422, 'cizí adresa push služby odmítnuta'); step('Web přijme jen adresy skutečných push služeb (Google, Mozilla, Apple, Microsoft); jinou adresu odmítne.');
    await mob.reload();
    await mob.click('button:has-text("Poslat zkušební upozornění")');
    check((await text(mob, '.flash')).includes('odešlo na 1 zařízení'), 'zkušební upozornění odešlo');
    const g1 = got.shift() || {};
    check(g1.okSig === true && g1.enc === 'aes128gcm' && /funguje/.test(g1.msg?.body || ''), 'zpráva podepsaná, zašifrovaná a rozšifrovaná: ' + JSON.stringify(g1));
    step('Zkušební upozornění: push služba ověřila podpis webu a rozšifrovala zprávu „' + (g1.msg?.body || '') + '“.');
    await mob.evaluate(() => { const h = [...document.querySelectorAll('#upozorneni .h-small')].pop(); const w = document.createElement('div'); w.id = 'push-zarizeni'; w.style.padding = '12px'; h.before(w); let n = h; const parts = []; while (n) { parts.push(n); n = n.nextElementSibling; } parts.forEach((x) => w.appendChild(x)); });
    await shot(mob.locator('#push-zarizeni'), 'push-2-zapnuto.jpg');
    // Nová aktualita od výboru → upozornění obyvateli
    await admin.goto(T + '/admin/aktuality/nova'); await admin.fill('[name=title]', 'Odstávka vody ve čtvrtek'); await admin.fill('[name=body]', 'Ve čtvrtek od 8 do 12 hodin nepoteče voda.'); await admin.click('main form button[type=submit]');
    const g2 = got.shift() || {};
    check(/Nová aktualita: Odstávka vody/.test(g2.msg?.body || '') && /\/aktuality\/\d+$/.test(g2.msg?.url || ''), 'upozornění na novou aktualitu: ' + JSON.stringify(g2));
    step('Po zveřejnění aktuality přišlo obyvateli upozornění „' + (g2.msg?.body || '') + '“ s odkazem na aktualitu.');
    // Kdo si upozornění do telefonu v účtu vypne, tomu nechodí – zkušební ano.
    run(`UPDATE users SET push_notify = 0 WHERE email = '${who.owner}'`);
    await admin.goto(T + '/admin/aktuality/nova'); await admin.fill('[name=title]', 'Úklid sklepů'); await admin.fill('[name=body]', 'V sobotu.'); await admin.click('main form button[type=submit]');
    check(got.length === 0, 'vypnutá upozornění se neposílají'); got.length = 0;
    run(`UPDATE users SET push_notify = 1 WHERE email = '${who.owner}'`);
    step('Když si obyvatel v účtu upozornění do telefonu vypne, nová aktualita mu nepřijde.');
    // Přijetí pozvánky v telefonu → první obrazovka je uvítání s upozorněními a volbou, jak dávat vědět.
    const token = crypto.randomBytes(32).toString('hex'); const tokHash = crypto.createHash('sha256').update(token).digest('hex');
    run(`INSERT INTO users (name, email, role, kind, password_hash, invite_hash, invite_expires) VALUES ('Lucie Malá', 'lucie.mala@example.cz', 'resident', 'owner', '!', '${tokHash}', datetime('now', '+7 day'))`);
    const nuCtx = await b2.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, locale: 'cs-CZ', colorScheme: 'light' }); await nuCtx.grantPermissions(['notifications'], { origin: new URL(T).origin });
    const nu = await nuCtx.newPage(); nu.setDefaultTimeout(10000);
    await nu.goto(T + '/pozvanka/' + token); await nu.fill('[name=password]', 'Navod-pozvanka-2027'); await nu.fill('[name=password2]', 'Navod-pozvanka-2027'); await nu.click('main form button[type=submit]');
    await nu.waitForURL('**/vitejte'); await nu.waitForFunction(() => document.querySelector('.push-state') && document.querySelector('.push-state').textContent !== '');
    check(await nu.isVisible('[data-push-on]') && (await text(nu)).includes('Jak Vám máme dávat vědět'), 'uvítání po pozvánce s upozorněními');
    step('Po nastavení hesla z pozvánky se jako první otevře uvítání: zapnutí upozornění do telefonu a volba „e-mailem / do telefonu“.');
    await shot(nu.locator('main'), 'push-0-uvitani.jpg', 1300);
    await nu.uncheck('[name=email_notify]'); await nu.click('button:has-text("Hotovo, pokračovat na web")');
    check(sql("SELECT email_notify || push_notify FROM users WHERE email = 'lucie.mala@example.cz'") === '01', 'volba způsobu upozorňování uložena'); step('Volba „jen do telefonu“ se uložila a web pokračoval na úvodní stránku.');
    await nuCtx.close();
    // Zobrazení upozornění službou v prohlížeči (service worker): doručíme push přímo přes nástroje prohlížeče.
    const pcCtx = await b2.newContext({ viewport: { width: 1280, height: 800 }, locale: 'cs-CZ' }); await pcCtx.grantPermissions(['notifications'], { origin: new URL(T).origin }); const pc = await pcCtx.newPage(); await login(pc, who.owner); await pc.goto(T + '/muj-ucet#upozorneni');
    await pc.waitForFunction(() => navigator.serviceWorker.controller !== null || document.querySelector('.push-state').textContent !== '');
    const cdp = await pc.context().newCDPSession(pc); await cdp.send('ServiceWorker.enable');
    const regId = await new Promise((resolve) => { cdp.on('ServiceWorker.workerRegistrationUpdated', (e) => { const r = e.registrations.find((x) => x.scopeURL.includes(`dum.${DOMAIN}`)); if (r) resolve(r.registrationId); }); setTimeout(() => resolve(null), 4000); });
    if (regId) {
      await cdp.send('ServiceWorker.deliverPushMessage', { origin: new URL(T).origin, registrationId: regId, data: JSON.stringify({ title: 'Zahradní 12', body: 'Zkouška zobrazení', url: T + '/' }) });
      const shown = await pc.evaluate(async () => { for (let i = 0; i < 20; i++) { const n = await (await navigator.serviceWorker.ready).getNotifications(); if (n.length) return n[0].title + ' | ' + n[0].body; await new Promise((r) => setTimeout(r, 150)); } return ''; });
      check(shown === 'Zahradní 12 | Zkouška zobrazení', 'service worker zobrazil upozornění: ' + shown); if (shown) step('Service worker webu přijatou zprávu zobrazil jako upozornění („' + shown + '“).');
    } else { check(false, 'service worker se nezaregistroval'); }
    pushSrv.close(); if (b2 !== b) await b2.close();

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
    await b.close(); server.kill(); tls.close(); fs.rmSync(STORE, { recursive: true, force: true });
  }
  for (const [slug, r] of Object.entries(results)) console.log(`${slug}: ${r.checks} kontrol\n  - ${r.steps.join('\n  - ')}`);
  if (failures.length) { console.log('\nSELHALO – návody neplatí:\n' + failures.join('\n')); process.exit(1); }
  fs.writeFileSync(path.join(OUT, 'overeno.json'), JSON.stringify(results, null, 2) + '\n');
  console.log('\nVše ověřeno, snímky a overeno.json uloženy v assets/help/.');
})();
