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
// Jiné adresy než web služby prohlížeč nenajde (MAP * ~NOTFOUND) – ověřovací běh nikdy nevolá cizí weby.
const LAUNCH = { args: [`--host-resolver-rules=MAP ${DOMAIN} 127.0.0.1:${TLS_PORT}, MAP *.${DOMAIN} 127.0.0.1:${TLS_PORT}, MAP * ~NOTFOUND, EXCLUDE localhost, EXCLUDE 127.0.0.1`, '--ignore-certificate-errors', '--proxy-server=direct://'] };
const results = {};
const failures = [];
let current = null;

const check = (ok, what) => { results[current].checks++; if (!ok) failures.push(`${current}: ${what}`); };
const step = (text) => results[current].steps.push(text);
const begin = (slug, note = '') => { current = slug; results[slug] = { date: new Date().toISOString().slice(0, 10), checks: 0, steps: [], note }; };
const env = { ...process.env, SVJ_STORAGE: STORE, SVJ_PUSH_TEST: '1' };
const crypto = require('crypto');
const sql = (q) => { try { return execFileSync('php', ['-r', '$f = glob(getenv("SVJ_STORAGE") . "/tenants/dum/*.sqlite")[0]; $p = new PDO("sqlite:$f"); echo $p->query(getenv("Q"))->fetchColumn();'], { env: { ...env, Q: q } }).toString().trim(); } catch (e) { throw new Error("SQL: " + q + " " + e.stderr); } };
const run = (q) => execFileSync('php', ['-r', '$f = glob(getenv("SVJ_STORAGE") . "/tenants/dum/*.sqlite")[0]; $p = new PDO("sqlite:$f"); $p->exec(getenv("Q"));'], { env: { ...env, Q: q } });
// Databáze služby (platformy) a její nastavení – např. zapnutí funkcí beta programu jen pro tento běh.
const psql = (q) => execFileSync('php', ['-r', '$f = glob(getenv("SVJ_STORAGE") . "/platform-*.sqlite")[0]; $p = new PDO("sqlite:$f"); echo $p->query(getenv("Q"))->fetchColumn();'], { env: { ...env, Q: q } }).toString().trim();
const prun = (q) => execFileSync('php', ['-r', '$f = glob(getenv("SVJ_STORAGE") . "/platform-*.sqlite")[0]; $p = new PDO("sqlite:$f"); $p->exec(getenv("Q"));'], { env: { ...env, Q: q } });
const pset = (k, v) => execFileSync('php', ['-r', 'require "app/lib.php"; pset_save(getenv("K"), getenv("V"));'], { env: { ...env, K: k, V: v }, cwd: ROOT });
// Nastavení serveru (storage/config.php): doména a přístupy ke zkušebním protějškům cizích služeb.
const cfg = {};
const writeConfig = () => fs.writeFileSync(path.join(STORE, 'config.php'), `<?php\nreturn json_decode(<<<'JSON'\n${JSON.stringify({ platform: { domain: DOMAIN }, ...cfg })}\nJSON, true);\n`);
const setCfg = (k, v) => { cfg[k] = v; writeConfig(); };
// Zachycené e-maily (mail.log): adresát, předmět a text po dekódování (quoted-printable, MIME hlavička).
const MAILFILE = path.join(STORE, 'mail.log');
const mailLen = () => (fs.existsSync(MAILFILE) ? fs.readFileSync(MAILFILE, 'utf8').length : 0);
const qpDecode = (s) => Buffer.from(s.replace(/=\r?\n/g, '').replace(/=([0-9A-F]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16))), 'latin1').toString('utf8');
const mailsSince = (since = 0, to = '') => (fs.existsSync(MAILFILE) ? fs.readFileSync(MAILFILE, 'utf8').slice(since) : '').split(/^(?=To: )/m).filter((m) => m.startsWith('To: ' + to)).map((m) => {
  const subject = ((/^Subject: (.*(?:\r?\n[ \t].*)*)/m.exec(m) || [])[1] || '').replace(/\r?\n[ \t]+/g, '').replace(/=\?UTF-8\?B\?([^?]*)\?=/gi, (_, b) => Buffer.from(b, 'base64').toString('utf8'));
  return { to: (/^To: (.*)$/m.exec(m) || [])[1].trim(), subject, body: qpDecode(m) };
});
const post = (url, body, type, extra = {}) => new Promise((resolve, reject) => {
  const u = new URL(url);
  const req = http.request({ host: '127.0.0.1', port: PORT, path: u.pathname, method: 'POST', headers: { Host: u.host, 'X-Forwarded-Proto': 'https', 'Content-Type': type, 'Content-Length': Buffer.byteLength(body), ...extra } }, (res) => {
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
  writeConfig();
  // E-maily se neposílají ven – zapíšou se do souboru, ať jde ověřit i jejich obsah.
  const MAIL = path.join(STORE, 'mail.log'); fs.writeFileSync(path.join(STORE, 'mail.sh'), `#!/bin/sh\ncat >> '${MAIL}'\n`, { mode: 0o755 });
  const server = spawn('php', ['-d', 'opcache.enable=0', '-d', `sendmail_path=${path.join(STORE, 'mail.sh')}`, '-S', `127.0.0.1:${PORT}`, 'index.php'], { cwd: ROOT, env, stdio: 'ignore' });
  execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', `/CN=${DOMAIN}`, '-addext', `subjectAltName=DNS:${DOMAIN},DNS:*.${DOMAIN}`,
    '-keyout', path.join(STORE, 'tls.key'), '-out', path.join(STORE, 'tls.crt')], { stdio: 'ignore' });
  const tls = https.createServer({ key: fs.readFileSync(path.join(STORE, 'tls.key')), cert: fs.readFileSync(path.join(STORE, 'tls.crt')) }, (req, res) => {
    const up = http.request({ host: '127.0.0.1', port: PORT, path: req.url, method: req.method, headers: { ...req.headers, 'x-forwarded-proto': 'https' } }, (r) => { res.writeHead(r.statusCode, r.headers); r.pipe(res); });
    up.on('error', () => { res.writeHead(502); res.end(); }); req.pipe(up);
  }).listen(TLS_PORT, '127.0.0.1');
  const b = await chromium.launch(LAUNCH);
  let fake = null;
  try {
    for (let i = 0; i < 50; i++) { try { await new Promise((ok, ko) => http.get(`http://127.0.0.1:${PORT}/`, (r) => { r.resume(); ok(); }).on('error', ko)); break; } catch (e) { await new Promise((r) => setTimeout(r, 100)); } }
    // Platforma a ukázkové společenství
    const s = await b.newPage(); await s.goto(BASE + '/sprava');
    const code = fs.readFileSync(path.join(STORE, fs.readdirSync(STORE).find((f) => f.startsWith('instalacni-kod-platformy-'))), 'utf8').trim();
    await s.fill('#p-code', code); await s.fill('[name=name]', 'Provozovatel'); await s.fill('[name=email]', 'provoz@example.cz'); await s.fill('[name=password]', 'Konzole-overeni-2027'); await Promise.all([s.waitForNavigation(), s.click('.form button')]);
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
    await admin.click(`#${lastId} input[name=mode][value=proxy] + span`); await admin.fill(`#${lastId} [name=proxy_name]`, 'Josef Svoboda'); await admin.check(`#${lastId} [name=proxy_ok]`); await admin.waitForLoadState('networkidle');
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
    const key = await admin.inputValue('#prijem input[aria-label="Klíč pro příjem dat"]');
    const api = T + '/api/odecty';
    check(/^[a-f0-9]{40}$/.test(key) && (await text(admin, '#prijem')).includes('/api/odecty'), 'adresa a zvlášť klíč pro příjem'); step('Správce zapnul automatický příjem a získal adresu a zvlášť klíč (klíč doporučujeme předat firmě jinou cestou než adresu).');
    await admin.$eval('#prijem input[aria-label="Klíč pro příjem dat"]', (x) => { x.value = '••••••••••••••••'; });
    await shot(admin.locator('#prijem'), 'api-1-adresa.jpg', 700);
    const auth = { Authorization: 'Bearer ' + key };
    const unit = sql(`SELECT entrance || '/' || number FROM units WHERE id = ${ownerUnit}`);
    const day = new Date().toISOString().slice(0, 10);
    const jsonReq = JSON.stringify([{ unit, meter_no: 'TV-104233', kind: 'tv', amount: 44.27, date: day }, { unit: 'Z/99', kind: 'tv', amount: 3, date: day }], null, 2);
    const r1 = await post(api, jsonReq, 'application/json', auth);
    check(r1.status === 200 && JSON.parse(r1.body).saved === 1 && JSON.parse(r1.body).errors.length === 1, 'JSON s klíčem v hlavičce: 1 uloženo, 1 chyba ' + r1.body);
    const csvReq = `jednotka;číslo měřidla;druh;stav;datum odečtu\n${unit};TV-104233;teplá voda;45,05;${day}`;
    const r2 = await post(api, csvReq, 'text/csv', { 'X-Api-Key': key });
    check(r2.status === 200 && JSON.parse(r2.body).updated === 1, 'CSV s hlavičkou X-Api-Key přepsalo stav ' + r2.body);
    const r4 = await post(api, JSON.stringify([{ meter_no: 'TV-104233', kind: 'tv', amount: 44.5, date: new Date(Date.now() - 86400000).toISOString().slice(0, 10) }]), 'application/json', auth);
    check(r4.status === 200 && JSON.parse(r4.body).saved === 1, 'jednotku lze vynechat, když web zná číslo měřidla');
    const rOld = await post(api + '/' + key, '[]', 'application/json');
    check(rOld.status === 200, 'starší adresa s klíčem v adrese funguje');
    const r3 = await post(api, '[]', 'application/json', { Authorization: 'Bearer ' + '0'.repeat(40) });
    check(r3.status === 403, 'špatný klíč odmítnut');
    const rNo = await post(api, '[]', 'application/json');
    check(rNo.status === 403, 'bez klíče odmítnuto');
    step('Poslán JSON s klíčem v hlavičce Authorization (1 řádek uložen, 1 s chybou), CSV s hlavičkou X-Api-Key (stav přepsán), JSON jen s číslem měřidla (uložen); starší adresa s klíčem v adrese funguje; špatný klíč i chybějící klíč odmítnuty (403).');
    // Podezřelý řádek: pokles stavu → neuloží se, čeká na schválení.
    const heldSince = mailLen();
    const rHeld = await post(api, JSON.stringify([{ unit, meter_no: 'TV-104233', kind: 'tv', amount: 30, date: day }]), 'application/json', auth);
    check(rHeld.status === 200 && JSON.parse(rHeld.body).held === 1 && JSON.parse(rHeld.body).updated === 0, 'pokles stavu zadržen ke schválení ' + rHeld.body);
    const heldMails = mailsSince(heldSince); check(heldMails.some((m) => m.subject.startsWith('Odečty ke schválení')), 'správcům přišel e-mail o odečtu ke schválení: ' + JSON.stringify(heldMails.map((m) => m.subject)));
    await admin.goto(T + '/admin/odecty/dalkove');
    const pend = await text(admin, '#ke-schvaleni'); check(pend.includes('nižší než předchozí') && pend.includes('30'), 'správce vidí zadržený odečet s důvodem');
    await shot(admin.locator('#ke-schvaleni'), 'api-2-schvaleni.jpg', 500);
    await admin.click('#ke-schvaleni button[value=reject]');
    check(sql(`SELECT amount FROM readings WHERE meter_no = 'TV-104233' AND read_date = '${day}'`) === '45.05' && sql("SELECT COUNT(*) FROM reading_pending") === '0', 'zamítnutý odečet se neuložil, platí původní stav');
    step('Řádek se stavem nižším než předchozí odečet se neuložil: odpověď ho vrátila jako „held“, správcům přišel e-mail a v administraci je v části „Odečty ke schválení“ s důvodem; po „Zamítnout“ zůstal původní stav.');
    // Povolené IP adresy: z jiné adresy odmítnuto, i se správným klíčem.
    await admin.fill('#prijem [name=ips]', '203.0.113.10'); await admin.click('#prijem form:has([name=ips]) button');
    const rIp = await post(api, '[]', 'application/json', auth);
    check(rIp.status === 403 && rIp.body.includes('IP'), 'z nepovolené IP odmítnuto i se správným klíčem');
    await admin.fill('#prijem [name=ips]', ''); await admin.click('#prijem form:has([name=ips]) button');
    check((await post(api, '[]', 'application/json', auth)).status === 200, 'po vymazání seznamu zase přijato');
    const pr = await text(admin, '#prijem'); check(/Poslední příjem dat/.test(pr) && /Neúspěšné pokusy za 7 dní: [1-9]/.test(pr), 'správce vidí poslední příjem a neúspěšné pokusy');
    step('Se seznamem povolených IP adres web odmítl data z jiné adresy (403) i se správným klíčem; v administraci je vidět poslední příjem dat (kdy, odkud, kolik řádků) a počet neúspěšných pokusů.');
    fs.writeFileSync(path.join(OUT, 'api-ukazka.json'), JSON.stringify({ unit, jsonRequest: jsonReq, jsonResponse: JSON.stringify(JSON.parse(r1.body), null, 2), csvRequest: csvReq, csvResponse: JSON.stringify(JSON.parse(r2.body), null, 2), heldResponse: JSON.stringify(JSON.parse(rHeld.body), null, 2), badStatus: r3.status, badResponse: r3.body }, null, 2) + '\n');
    await owner.goto(T + '/odecty');
    const ot = await text(owner); check(ot.includes('Spotřeba po měsících') && ot.includes('dálkový odečet'), 'obyvatel vidí dálkový odečet'); step('Obyvatel vidí na stránce Odečty přijaté stavy se zdrojem „dálkový odečet“.');
    // Data za celé měsíce: stavy ke konci posledních tří měsíců (teplá voda a indikátory topení) → měsíční spotřeba.
    const endOf = (back) => { const d = new Date(); const x = new Date(d.getFullYear(), d.getMonth() - back + 1, 0); return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`; };
    const series = [[7, 28.90, 1150], [6, 31.20, 1185], [5, 33.40, 1195], [4, 35.80, 1200], [3, 38.10, 1210], [2, 40.35, 1302], [1, 42.80, 1388]].flatMap(([m, tv, itn]) => [{ unit, meter_no: 'TV-104233', kind: 'tv', amount: tv, date: endOf(m) }, { unit, meter_no: 'ITN-5521', kind: 'itn', amount: itn, date: endOf(m) }]);
    const r5 = await post(api, JSON.stringify(series), 'application/json', auth);
    check(r5.status === 200 && JSON.parse(r5.body).errors.length === 0, 'stavy za sedm měsíců přijaty');
    await owner.goto(T + '/odecty');
    const mt = await text(owner, '.meter-monthly');
    check(mt.includes('2,45') && mt.includes('86 dílků'), 'měsíční spotřeba spočítaná ze stavů'); step('Ze stavů na konci měsíců web spočítal měsíční spotřebu (teplá voda 2,45 m³, indikátory 86 dílků).');
    check(await owner.locator('.meter-monthly svg.meter-chart').count() >= 1 && /Za posledních 6 měsíců celkem/.test(mt), 'graf spotřeby a součet za období'); step('Obyvatel vidí u každého měřidla graf spotřeby po měsících a součet za období.');
    // Chybějící data: měřidlo, které předminulý měsíc data poslalo, za minulý měsíc mlčí.
    const rMiss = await post(api, JSON.stringify([{ unit, meter_no: 'TV-200917', kind: 'tv', amount: 12.4, date: endOf(2) }]), 'application/json', auth);
    check(rMiss.status === 200 && JSON.parse(rMiss.body).saved === 1, 'druhé měřidlo jen za předminulý měsíc');
    await admin.goto(T + '/admin/odecty/dalkove');
    const miss = await text(admin, '#chybi'); check(miss.includes('chybí data') && miss.includes('(počet: 1)') && miss.includes('200917'), 'správce vidí chybějící měřidlo: ' + miss.slice(0, 160));
    await admin.fill('#chybi [name=firm_email]', 'odecty@example.org'); await admin.click('#chybi form:has([name=firm_email]) button');
    await admin.click('#chybi button[value=ask-firm]');
    const mf = fs.readFileSync(MAIL, 'utf8'); check(/To: .*odecty@example\.org/.test(mf), 'odečtové firmě odešla žádost o doplnění');
    check((await text(admin, '#chybi')).includes('Odečtovou firmu jste požádali'), 'zaznamenáno, kdy byla firma požádána');
    await shot(admin.locator('#chybi'), 'api-3-chybi.jpg', 700);
    await owner.goto(T + '/odecty');
    check(new Date().getDate() < 3 || (await text(owner, '.meter-monthly')).includes('zatím nedorazila'), 'obyvatel vidí, že data za minulý měsíc chybí a výbor to řeší');
    step('Když měřidlo, které předminulý měsíc posílalo data, za minulý měsíc mlčí: správce ho vidí v části „Když data nedorazí“, tlačítkem odeslal odečtové firmě e-mail se seznamem chybějících měřidel a obyvatel na stránce Odečty vidí, že data zatím nedorazila a výbor je řeší.');
    await admin.goto(T + '/admin/odecty/dalkove');
    await admin.click('#upozorneni form:has([value=notice]) button');
    const fl = await text(admin, 'main'); check(/Upozornění/.test(fl), 'měsíční upozornění odesláno: ' + fl.slice(0, 120));
    const ml = fs.existsSync(MAIL) ? fs.readFileSync(MAIL, 'utf8') : ''; check(ml.includes('petr.novak@example.cz') || ml.includes('marie.novakova@example.cz'), 'e-mail s upozorněním vlastníkovi');
    // Automatické upozornění: zapnout, „zapomenout“ ruční odeslání a nechat proběhnout denní úlohu webu.
    await admin.check('#upozorneni [name=auto]'); await admin.click('#upozorneni form:has([name=auto]) button[type=submit]');
    check((await text(admin, '#upozorneni .meter-plan')).includes('Zapnuto'), 'automatika zapnutá přímo v rámečku upozornění, stav s termínem'); step('Automatiku správce zapnul přímo v rámečku „Měsíční informace obyvatelům“; rámeček ukazuje, kdy a za jaký měsíc odejde další upozornění.');
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

    // ---------- Závady v bytě: nájemník ↔ vlastník (bez návodu – funkce je z obrazovky zřejmá; kontrola chování) ----------
    begin('zavady-v-byte', 'Funkce nemá návod v nápovědě (obrazovka je samovysvětlující); běh ověřuje chování a soukromí.');
    {
      const tenantEmail = sql("SELECT u.email FROM users u JOIN user_units uu ON uu.user_id = u.id WHERE u.kind = 'tenant' AND u.active = 1 ORDER BY u.id LIMIT 1");
      const tUnit = sql(`SELECT uu.unit_id FROM user_units uu JOIN users u ON u.id = uu.user_id WHERE u.email = '${tenantEmail}'`);
      const ownerId = sql(`SELECT id FROM users WHERE email = '${who.owner}'`);
      run(`INSERT INTO user_units (user_id, unit_id) VALUES (${ownerId}, ${tUnit})`);
      const ten = await (await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, locale: 'cs-CZ' })).newPage(); ten.setDefaultTimeout(10000);
      await login(ten, tenantEmail);
      await ten.goto(T + '/zavady');
      check((await text(ten)).includes('Tu řeší vlastník bytu'), 'na Závadách a požadavcích nájemník vidí odkaz na vlastníka');
      await ten.goto(T + '/byt');
      check((await text(ten)).includes('výbor ani správa domu zprávy nevidí'), 'nájemník vidí vysvětlení');
      await ten.click('a:has-text("Napsat vlastníkovi")');
      await ten.fill('[name=subject]', 'Kape kohoutek v kuchyni'); await ten.fill('[name=body]', 'Od včerejška kape kohoutek v kuchyni. Doma jsem odpoledne po 16. hodině.');
      const png = path.join(STORE, 'kohoutek.png'); fs.writeFileSync(png, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'));
      await ten.setInputFiles('input[type=file]', png);
      const since = mailLen();
      await ten.click('main form button[type=submit]');
      const caseId = sql("SELECT id FROM unit_cases ORDER BY id DESC LIMIT 1");
      check(!!caseId && ten.url().endsWith('/byt/' + caseId) && (await text(ten)).includes('Kape kohoutek v kuchyni') && (await text(ten)).includes('kohoutek.png'), 'závada odeslaná i s fotkou');
      check(mailsSince(since).some((m) => m.to === who.owner && m.subject.startsWith('Nová závada v bytě')), 'vlastník dostal e-mail');
      step('Nájemník na stránce Závady v bytě napsal vlastníkovi závadu s fotkou; vlastník dostal e-mail.');
      await owner.goto(T + '/byt');
      check((await text(owner)).includes('Kape kohoutek v kuchyni') && !!(await owner.$('.ucase-row.is-unread')), 'vlastník vidí novou nepřečtenou závadu');
      await owner.click('.ucase-row');
      await owner.click('.ucase-status button[value=in_progress]');
      await owner.fill('#odpoved [name=body]', 'Děkuji, ve čtvrtek v 17 hodin přijde instalatér.'); await owner.click('#odpoved button[type=submit]');
      check(sql(`SELECT status FROM unit_cases WHERE id = ${caseId}`) === 'in_progress' && sql(`SELECT COUNT(*) FROM unit_case_messages WHERE case_id = ${caseId}`) === '3', 'vlastník změnil stav a odpověděl');
      await ten.goto(T + '/byt/' + caseId);
      check((await text(ten)).includes('ve čtvrtek v 17 hodin') && (await text(ten)).includes('Řeší se'), 'nájemník vidí odpověď a stav');
      step('Vlastník závadu otevřel, nastavil „Řeší se“ a odpověděl; nájemník odpověď i stav vidí.');
      await admin.goto(T + '/byt/' + caseId);
      check(!(await text(admin)).includes('Kape kohoutek'), 'výbor obsah nevidí');
      const fileId = sql(`SELECT id FROM unit_case_files WHERE case_id = ${caseId}`);
      const adminFile = await admin.goto(T + '/byt/priloha/' + fileId);
      check(adminFile.status() === 404, 'výbor nestáhne ani přílohu');
      step('Správce webu (výbor) vlákno ani přílohu neotevře.');
      const shots = path.join(ROOT, 'tools/napoveda/.zavady-byt'); fs.mkdirSync(shots, { recursive: true });
      await ten.screenshot({ path: path.join(shots, 'najemnik.png'), fullPage: true });
      await owner.goto(T + '/byt'); await owner.screenshot({ path: path.join(shots, 'vlastnik.png'), fullPage: true });
      run(`DELETE FROM user_units WHERE user_id = ${ownerId} AND unit_id = ${tUnit}`);   // vrátit ukázkový dům, další návody počítají s jedním bytem vlastníka
    }

    // ---------- Snímky pro úvodní stránku služby (assets/landing) ----------
    const LP = path.join(ROOT, 'assets/landing'); fs.mkdirSync(LP, { recursive: true });
    const lpShot = async (page, file) => { await page.evaluate(() => window.scrollTo(0, 0)); await page.screenshot({ path: path.join(LP, file), type: 'jpeg', quality: 74 }); };
    const desk = async (email) => { const p = await (await b.newContext({ viewport: { width: 1280, height: 800 }, locale: 'cs-CZ', colorScheme: 'light' })).newPage(); await login(p, email); return p; };
    const res = await desk(who.owner); await res.goto(T + '/'); await lpShot(res, 'web-obyvatel.jpg');
    const adm = await desk(who.admin); await adm.goto(T + '/admin'); await lpShot(adm, 'administrace.jpg');
    await adm.goto(T + '/admin/data-domu'); check((await text(adm, 'main')).includes('Export všech dat domu'), 'Data a smlouva pro stránku Bezpečnost'); await lpShot(adm, 'data-domu.jpg');
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

    begin('parovani-plateb', 'Postup v internetovém bankovnictví (stažení výpisu, vytvoření tokenu Fio) nešlo ověřit – je na webu banky. Ověřeno je načtení výpisu ve formátu GPC, spárování podle VS a ruční přiřazení.');
    {
      const u1 = sql("SELECT entrance || '/' || number FROM units ORDER BY id LIMIT 1"), u2 = sql("SELECT entrance || '/' || number FROM units ORDER BY id LIMIT 1 OFFSET 2");
      const pre = sql("SELECT value FROM settings WHERE key = 'site_unit_prefix'") || '';
      await admin.goto(T + '/admin/platby');
      await admin.fill('textarea[name=text]', pre + u1 + ' ; 4 250 ; -4 250 ; 4801\n' + pre + u2 + ' ; 3 000 ; -3 000 ; 4803');
      await admin.fill('[name=as_of]', '2026-11-01');
      await admin.click('form:has([value=import]) button');
      check(sql("SELECT COUNT(*) FROM unit_accounts WHERE vs IN ('4801','4803')") === '2', 'stav z účetnictví nahrán'); step('Stav dvou jednotek z účetnictví s variabilními symboly 4801 a 4803 nahrán k 1. 11.');
      await admin.setInputFiles('[name=statement]', path.join(__dirname, 'vypis-ukazka.gpc'));
      await admin.click('form:has([value=bank_upload]) button');
      const fl = await text(admin, '.flash');
      check(fl.includes('spárováno s jednotkou 1') && fl.includes('nespárováno 1'), 'výpis GPC: ' + fl); step('Výpis GPC: platba s VS 4801 se spárovala, platba s neznámým VS zůstala k ručnímu přiřazení.');
      const st = async (u) => (await admin.locator('table.table').last().locator('tr', { hasText: u }).first().locator('td').nth(3).textContent()).replace(/\s+/g, ' ');
      check((await st(u1)).includes('vyrovnáno'), 'jednotka s VS 4801 vyrovnána: ' + await st(u1)); step('Jednotka s VS 4801 je po započtení platby vyrovnaná.');
      await shot(admin.locator('#banka'), 'banka-1-nacteno.jpg', 1000);
      await admin.setInputFiles('[name=statement]', path.join(__dirname, 'vypis-ukazka.gpc'));
      await admin.click('form:has([value=bank_upload]) button');
      check((await text(admin, '.flash')).includes('nových plateb 0'), 'opakované nahrání nic nezdvojí'); step('Opakované nahrání stejného výpisu nic nepřidalo.');
      const um = admin.locator('.bank-tx tr.is-unmatched');
      await um.locator('select').selectOption({ label: pre + u2 });
      await um.locator('button').click();
      check((await st(u2)).includes('vyrovnáno'), 'ruční přiřazení: ' + await st(u2)); step('Ručně přiřazená platba vyrovnala druhou jednotku.');
      await shot(admin.locator('#banka'), 'banka-2-prirazeno.jpg', 1000);
    }

    // ---------- Funkce beta programu a zkušební protějšky cizích služeb ----------
    // Funkce v betě se zapnou jen pro tento běh (dočasná složka – ostrý web se nemění) a na konci se zase vypnou.
    const BETAS = ['sms', 'bank-psd2', 'shromazdeni-online', 'katastr', 'exekuce', 'insolvence', 'sso', 'bankid'];
    BETAS.forEach((k) => pset('beta_' + k, 'on'));
    pset('contact_email', 'provoz@example.cz');
    // Cizí služby (SMS brána, poskytovatel bankovních dat, katastr, rejstříky) se nevolají: místo nich odpovídá tento místní server
    // ve tvaru podle jejich zveřejněné dokumentace (stejné vzory používají unit testy). Skutečné služby tím ověřené nejsou.
    const FAKE_PORT = PORT + 200;
    const FAKE = `http://127.0.0.1:${FAKE_PORT}`;
    const fakeLog = [];
    const smsSent = [];
    const SMS_REJECT = '+420606555666';
    const gc = { n: 0, req: {}, tx: [] };
    const fixture = (f) => fs.readFileSync(path.join(ROOT, 'tests/Unit/fixtures', f), 'utf8');
    const wsdpReport = (stav, content) => '<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
      + '<ns2:GenerujSestavuResponse xmlns:ns2="urn:cz:gov:cuzk:iskn:types:wsdp:sestavy:3.1" xmlns:ns3="urn:cz:gov:cuzk:iskn:types:common:3.1">'
      + '<ns2:vysledek><ns3:zprava kod="0" uroven="INFORMACE">Požadavek byl úspěšně zpracován.</ns3:zprava></ns2:vysledek><ns2:reportList><ns2:report><ns2:id>77001</ns2:id>'
      + '<ns2:nazev>Výpis z katastru nemovitostí</ns2:nazev><ns2:cena>100</ns2:cena><ns2:stav>' + stav + '</ns2:stav><ns2:format>xml</ns2:format>'
      + (content ? '<ns2:souborSestavy>' + Buffer.from(content).toString('base64') + '</ns2:souborSestavy>' : '') + '</ns2:report></ns2:reportList></ns2:GenerujSestavuResponse></soap:Body></soap:Envelope>';
    fake = http.createServer((req, res) => { let body = ''; req.on('data', (c) => { body += c; }); req.on('end', () => {
      const u = new URL(req.url, FAKE); const p = u.pathname; fakeLog.push({ method: req.method, path: p, query: u.search, body });
      const json = (st, o) => { res.writeHead(st, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); };
      const xml = (s) => { res.writeHead(200, { 'Content-Type': 'text/xml; charset=utf-8' }); res.end(s); };
      // GoSMS.cz (https://doc.gosms.eu/): token a odeslání zprávy; jedno číslo brána „odmítne“.
      if (p === '/gosms/oauth/v2/token') return json(200, { access_token: 'SMS-TOKEN', expires_in: 3600 });
      if (p === '/gosms/api/v1/messages') { const m = JSON.parse(body); smsSent.push(m); return json(201, { recipients: { invalid: m.recipients === SMS_REJECT ? [SMS_REJECT] : [] }, link: 'api/v1/messages/' + smsSent.length }); }
      // GoCardless Bank Account Data (API v2)
      if (p === '/gc/token/new/') return json(200, { access: 'GC-ACCESS', access_expires: 86400, refresh: 'GC-REFRESH', refresh_expires: 2592000 });
      if (p === '/gc/institutions/') return json(200, JSON.parse(fixture('modc/institutions_cz.json')));
      if (p === '/gc/agreements/enduser/') return json(201, { id: 'agr-' + (gc.n + 1), ...JSON.parse(body) });
      if (p === '/gc/requisitions/' && req.method === 'POST') { const r = JSON.parse(body); const id = 'req-' + (++gc.n); gc.req[id] = r; gc.last = id; return json(201, { id, status: 'CR', link: 'https://ob.gocardless.com/psd2/start/' + id + '/' + r.institution_id }); }
      if (/^\/gc\/requisitions\/req-\d+\/$/.test(p)) { const id = p.split('/')[3]; if (req.method === 'DELETE') return json(200, { summary: 'Requisition deleted' }); return json(200, { id, status: 'LN', accounts: ['acc-1'], reference: gc.req[id]?.reference }); }
      if (p === '/gc/accounts/acc-1/') return json(200, JSON.parse(fixture('modc/account.json')));
      if (p === '/gc/accounts/acc-1/transactions/') return json(200, { transactions: { booked: gc.tx, pending: [] } });
      // Centrální evidence exekucí (API v4)
      if (p === '/cee/auth') return json(200, { token_value: 'CEE-TOKEN', valid_for: '127.0.0.1', valid_till: new Date(Date.now() + 3600000).toISOString().slice(0, 19).replace('T', ' '), data_source: 'regular' });
      if (p === '/cee/search') return json(200, { data: [{ ex_cislo: '999EX120/23', datum_zapisu: '2023-10-18', posledni_uprava: 'Pravomocná exekuce', date_vydano: '2023-10-18', subjects: [{ typ_osoby: 'F', jmeno: 'Tomáš Dvořák', datum_narozeni: '1980-05-12' }] }], info: { cena: { zaplaceno: '15 Kč' } }, status: 200 });
      // Insolvenční rejstřík (IsirWsCuzkService)
      if (p === '/isir') return xml('<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:getIsirWsCuzkDataResponse xmlns:ns2="http://isirws.cca.cz/types/">'
        + (body.includes('Dvořák') ? '<data><nazevOsoby>Dvořák</nazevOsoby><jmeno>Tomáš</jmeno><datumNarozeni>1980-05-12</datumNarozeni><cisloSenatu>27</cisloSenatu><druhVec>INS</druhVec><bcVec>1234</bcVec><rocnik>2026</rocnik><nazevOrganizace>Krajský soud v Brně</nazevOrganizace><druhStavKonkursu>MORATORIUM</druhStavKonkursu><urlDetailRizeni>https://isir.justice.cz/detail</urlDetailRizeni></data><stav><kodChyby>OK</kodChyby></stav>' : '<stav><kodChyby>WS2</kodChyby><textChyby>Nebyl nalezen žádný záznam</textChyby></stav>')
        + '</ns2:getIsirWsCuzkDataResponse></soap:Body></soap:Envelope>');
      // Dálkový přístup do katastru (WSDP 3.1, služba sestavy): vzorový výpis LV z unit testů.
      if (p === '/wsdp/sestavy') return xml(body.includes('GenerujLVZjednoduseneRequest') ? wsdpReport('zpracovává se', '') : wsdpReport('zpracován', fixture('ModE/lv_budova.xml')));
      json(404, {});
    }); }).listen(FAKE_PORT, '127.0.0.1');
    // Cizí weby v prohlížeči (videohovor, banka, přihlášení u poskytovatelů) se neotevírají – místo nich je prázdná zkušební stránka.
    const offline = async (ctx) => ctx.route(/^https:\/\/(meet\.jit\.si|ob\.gocardless\.com|accounts\.google\.com|login\.microsoftonline\.com|appleid\.apple\.com|www\.facebook\.com)\//, (r) => {
      const url = r.request().url();
      if (url.startsWith('https://ob.gocardless.com/')) { const id = url.split('/')[5]; const q = gc.req[id]; return r.fulfill({ status: 200, contentType: 'text/html', body: `<!doctype html><title>Zkušební banka</title><p>Zkušební banka (ověřovací běh): <a id="souhlas" href="${q.redirect}?ref=${q.reference}">Souhlasím se sdílením pohybů</a></p>` }); }
      return r.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Zkušební stránka</title><p>Cizí služba – v ověřovacím běhu se neotevírá.</p>' });
    });
    await offline(admin.context()); await offline(owner.context());
    const as = async (email, mobile = true) => { const ctx = await b.newContext({ viewport: mobile ? { width: 390, height: 844 } : { width: 1280, height: 900 }, deviceScaleFactor: 2, locale: 'cs-CZ' }); await offline(ctx); const p = await ctx.newPage(); p.on('dialog', (d) => d.accept()); p.setDefaultTimeout(10000); await login(p, email); return p; };
    const tomas = await as('tomas.dvorak@example.cz');
    const navGroup = async (page, href) => (await page.locator(`.admin-group:has(a[href$="${href}"]) .admin-label`).first().textContent().catch(() => '')).trim();
    const flash = async (page) => (await page.locator('.flash').allTextContents()).join(' ').replace(/\s+/g, ' ');
    const today = new Date().toISOString().slice(0, 10);
    const plusDays = (n) => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);
    const czDate = (d) => { const [y, m, dd] = d.split('-').map(Number); return `${dd}. ${m}. ${y}`; };
    const unitId = (u) => sql(`SELECT id FROM units WHERE entrance || '/' || number = '${u}'`);
    const daily = async () => { run("DELETE FROM settings WHERE key = 'last_daily'"); await owner.goto(T + '/'); };
    const pdfFile = (name) => { const f = path.join(STORE, 'soubory', name); fs.mkdirSync(path.dirname(f), { recursive: true }); fs.writeFileSync(f, '%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n'); return f; };
    const download = async (page, click) => { const [dl] = await Promise.all([page.waitForEvent('download'), click()]); const f = path.join(STORE, 'stazeno-' + dl.suggestedFilename()); await dl.saveAs(f); return f; };
    const zipFiles = (f) => JSON.parse(execFileSync('php', ['-r', '$z = new ZipArchive(); $z->open($argv[1]); $o = []; for ($i = 0; $i < $z->numFiles; $i++) { $s = (string) $z->getFromIndex($i); $o[$z->getNameIndex($i)] = mb_check_encoding($s, "UTF-8") ? $s : (string) iconv("CP1250", "UTF-8//IGNORE", $s); } echo json_encode($o, JSON_INVALID_UTF8_SUBSTITUTE);', f]).toString());

    // Edice domu pro jednu kontrolu (předplatné v databázi služby); 'premium' = výchozí stav běhu (web bez placení).
    const edition = (ed, paid = ed !== 'premium') => prun(!paid ? "UPDATE tenants SET billing = 0, plan = '', paid_until = '', trial_ends = '' WHERE slug = 'dum'"
      : `UPDATE tenants SET billing = 1, plan = '${ed === 'free' ? '' : ed}', paid_until = '${ed === 'free' ? '' : plusDays(200)}', trial_ends = '' WHERE slug = 'dum'`);
    // Kredit služeb: provozovatel připíše opravu (Kč, záporná = odečíst) v konzoli.
    const creditFix = async (czk, note) => { await s.goto(BASE + '/sprava/kredit'); await s.selectOption('form:has([value=fix]) [name=tenant]', 'dum'); await s.fill('form:has([value=fix]) [name=amount]', String(czk)); await s.fill('form:has([value=fix]) [name=note]', note); await s.click('form:has([value=fix]) button[type=submit]'); };
    const creditH = () => Number(psql("SELECT COALESCE(SUM(delta_h), 0) FROM credit_ledger WHERE tenant = 'dum'"));

    // ---------- SMS zprávy ----------
    begin('sms-zpravy', 'Doručení SMS do telefonu jsme ověřit nemohli – ověřovací běh nesmí posílat skutečné SMS. Zprávy místo smluvní SMS brány přijala zkušební brána se stejným rozhraním (GoSMS.cz). Ověřeno je vše na straně webu: SMS v ceně edice Premium, kredit služeb a jeho dobití, náhled a cena, výběr příjemců, odeslání bráně a vrácení peněz za zprávu, kterou brána odmítla.');
    {
      s.on('dialog', (d) => d.accept());
      setCfg('sms', { gosms_client_id: 'zkouska', gosms_client_secret: 'zkouska', gosms_channel: 1, gosms_url: FAKE + '/gosms' });
      await s.goto(BASE + '/sprava/sms');
      await s.selectOption('form:has([value=settings]) [name=provider]', 'gosms'); await s.click('form:has([value=settings]) button[type=submit]');
      run("UPDATE users SET phone = '541 123 456' WHERE email = 'eva.svobodova@example.cz'");
      edition('premium', true);
      await admin.goto(T + '/admin/sms');
      check(await navGroup(admin, '/admin/sms') === 'Komunikace', 'SMS zprávy v menu administrace');
      let kr = await text(admin, '#kredit');
      check(kr.includes('100 z 100 SMS v ceně edice Premium') && /Jedna SMS stojí 1,50 Kč bez DPH a platí se z kreditu služeb/.test(kr), 'Premium: 100 SMS v ceně, cena 1,50 Kč: ' + kr);
      const rcpt = await text(admin, 'main .card');
      check(/Příjemci: 4 vlastníků/.test(rcpt) && rcpt.includes('Neplatné nebo pevné číslo má 1'), 'příjemci: 4 mobily, 1 pevná linka vynechána: ' + rcpt.slice(0, 160));
      step('SMS zprávy (Komunikace): edice Premium má 100 SMS ročně v ceně, další SMS stojí 1,50 Kč bez DPH z kreditu služeb. Příjemci: 4 vlastníci s mobilem; pevnou linku web vynechal a napsal, kolik jich je.');
      // Edice Standard: jen kredit služeb.
      edition('standard'); await creditFix(5, 'ověření návodu');
      await admin.goto(T + '/admin/sms');
      const smsText = 'Havárie vody ve sklepě: dnes od 14 do 18 hodin nepoteče voda. Děkujeme za pochopení. Výbor SVJ Zahradní 12';
      await admin.fill('[name=text]', smsText); await admin.uncheck('[name=plain]'); await admin.click('button[value=preview]');
      let pv = await text(admin, '#nahled');
      check(pv.includes('s diakritikou') && /2 SMS \(zpráva se rozdělí\)/.test(pv) && pv.includes('Celkem 8 SMS') && pv.includes('12 Kč bez DPH') && pv.includes('Kredit služeb nestačí (vystačí na 3 SMS)') && !(await admin.$('#nahled button[value=send]')), 's diakritikou 2 SMS, kredit nestačí: ' + pv);
      step('S diakritikou má zpráva (' + smsText.length + ' znaků) 2 SMS na příjemce, celkem 8 SMS za 12 Kč – kredit 5 Kč nestačí (na 3 SMS), web odeslání nenabídne a nabídne dobití.');
      await admin.click('#nahled a:has-text("Dobít kredit")');
      const kp = await text(admin);
      check(admin.url().includes('/admin/kredit') && kp.includes('Kredit platí 24 měsíců od dobití') && /SMS[^:]*: 1,50 Kč/.test(kp), 'stránka Kredit služeb: platnost 24 měsíců a ceny: ' + kp.slice(0, 500));
      await admin.check('#dobit [name=amount][value="500"]'); await admin.click('#dobit button:has-text("Objednat dobití")');
      check((await flash(admin)).includes('Dobití je objednané') && (await text(admin, '#platba')).includes('Variabilní symbol') && !!(await admin.$('#platba a:has-text("Zálohová faktura k tisku")')), 'zálohová faktura k dobití');
      await admin.click('#platba button:has-text("Platbu jsem odeslal(a)")');
      const vs = psql("SELECT vs FROM payments WHERE tenant = 'dum' AND kind = 'credit' ORDER BY id DESC LIMIT 1");
      await s.goto(BASE + '/sprava/platby'); await s.locator('tr', { hasText: vs }).locator('button:has-text("Platba dorazila")').click();
      check(creditH() === 50500, 'po potvrzení platby připsáno 500 Kč: ' + creditH());
      step('Tlačítko „Dobít kredit“ vede na Kredit služeb (kredit platí 24 měsíců): objednáno dobití 500 Kč, zálohová faktura s variabilním symbolem; po potvrzení platby provozovatelem se kredit připsal.');
      await admin.goto(T + '/admin/sms'); await admin.fill('[name=text]', smsText); await admin.check('[name=plain]'); await admin.click('button[value=preview]');
      pv = await text(admin, '#nahled');
      check(pv.includes('Havarie vody ve sklepe') && pv.includes('bez diakritiky') && /\b1 SMS na příjemce/.test(pv) && pv.includes('Celkem 4 SMS pro 4 vlastníků, tj. 6 Kč bez DPH'), 'bez diakritiky 1 SMS: ' + pv);
      step('Bez diakritiky se zpráva vejde do 1 SMS; náhled ukázal text, počet SMS, příjemce a celkovou cenu 6 Kč bez DPH.');
      await shot(admin.locator('#nahled'), 'sms-1-nahled.jpg');
      await admin.click('#nahled button[value=send]');
      const sf = await flash(admin);
      check(sf.includes('SMS odeslána 3 vlastníkům') && sf.includes('neodesláno 1'), 'odesláno 3, brána 1 odmítla: ' + sf);
      check(smsSent.length === 4 && smsSent.every((m) => m.message.startsWith('Havarie vody')), 'brána dostala 4 zprávy bez diakritiky');
      check(creditH() === 50500 - 600 + 150, 'z kreditu 4 × 1,50 Kč, odmítnutá zpráva vrácena: ' + creditH());
      check((await admin.locator('table:below(h2:has-text("Odeslané zprávy")) tbody tr').count()) >= 4, 'přehled odeslaných zpráv');
      step('Odesláno 3 vlastníkům; z kreditu se strhlo 4 × 1,50 Kč a za zprávu, kterou brána odmítla, se 1,50 Kč vrátilo. Přehled odeslaných zpráv je dole na stránce.');
      // Premium: nejdřív SMS v ceně edice.
      edition('premium', true);
      const c0 = creditH(); await admin.goto(T + '/admin/sms'); await admin.fill('[name=text]', 'Zkouska: voda uz tece. Vybor'); await admin.click('button[value=preview]'); await admin.click('#nahled button[value=send]');
      kr = await text(admin, '#kredit');
      check(creditH() === c0 && /\b9[67] z 100 SMS v ceně edice Premium/.test(kr), 'Premium: čerpají se SMS v ceně edice, kredit beze změny: ' + kr);
      step('V edici Premium se nejdřív čerpají SMS v ceně edice – kredit služeb se nezměnil.');
    }

    // ---------- Hlasování mimo zasedání (per rollam) ----------
    begin('hlasovani-per-rollam', 'Právní výklad (zákon, odborný článek, vzor stanov) ověřovací běh neověřuje – je to text, ne funkce webu. Ověření hlasu přes Bank iD jsme ověřit nemohli: potřebuje smlouvu s Bankovní identitou a přihlášení do skutečné banky; ověřeno je jen, že web Bank iD bez nastavení nenabídne. SMS s kódem přijala místo telefonu zkušební SMS brána.');
    {
      const myVote = async (page, item1, item2) => {
        await page.locator('.poll-form fieldset').nth(0).locator(`input[value=${item1}]`).check(); await page.locator('.poll-form fieldset').nth(1).locator(`input[value=${item2}]`).check();
        await Promise.all([page.waitForNavigation(), page.click('.poll-form button[type=submit]')]);
      };
      await admin.goto(T + '/admin/hlasovani/nove');
      const opts = await admin.$$eval('[name=verify] option', (o) => o.map((x) => x.value));
      check(opts.join() === 'none,email,sms' && (await admin.inputValue('[name=verify]')) === 'email', 'volby ověření hlasu (bez nastaveného Bank iD): ' + opts);
      step('Pole Ověření hlasu nabízí žádné / kód z e-mailu (výchozí) / kód z SMS; Bank iD bez nastavení u provozovatele web nenabídne.');
      await admin.fill('[name=title]', 'Výměna vchodových dveří'); await admin.fill('[name=body]', 'Dveře ve vchodu A i B dosluhují. Nabídky tří firem jsou v Dokumentech.');
      await admin.fill('[name=items]', 'Shromáždění schvaluje výměnu vchodových dveří za nejvýše 180 000 Kč z fondu oprav.\nShromáždění pověřuje výbor uzavřením smlouvy s vybraným dodavatelem.');
      check(await admin.isChecked('[name=online]') && (await admin.inputValue('[name=closes_at]')) === plusDays(15), 'výchozí lhůta 15 dní a hlasování přes web');
      await admin.fill('[name=threshold]', '40'); await admin.click('main form button[type=submit]');
      const pollId = sql("SELECT id FROM polls WHERE title = 'Výměna vchodových dveří'");
      check(!!pollId && sql(`SELECT verify FROM rollam_polls WHERE poll_id = ${pollId}`) === 'email', 'hlasování uloženo s ověřením kódem z e-mailu');
      await admin.goto(T + `/admin/hlasovani/${pollId}`); await admin.fill('[name=closes_at]', plusDays(8)); await admin.click('main form button[type=submit]');
      await admin.goto(T + `/admin/hlasovani/${pollId}`);
      const warn = await text(admin, 'main form');
      check(/Lhůta pro vyjádření je 9 dní\. Zákon \(§ 1211\)/.test(warn) && warn.includes('nastavená hranice 40 % je nižší'), 'upozornění na krátkou lhůtu a nízkou hranici: ' + warn.slice(0, 300));
      await admin.fill('[name=closes_at]', plusDays(15)); await admin.click('main form button[type=submit]');
      step('Při lhůtě 9 dní web upozornil na 15 dní podle § 1211, při hranici 40 % na většinu všech hlasů podle § 1214; lhůta pak vrácena na 15 dní.');

      // Vlastník: hlas se uloží až po kódu z e-mailu.
      await owner.goto(T + '/hlasovani/' + pollId);
      check((await text(owner)).includes('Po odeslání Vám přijde e-mailem kód'), 'vlastník vidí, že přijde kód');
      const m0 = mailLen();
      await myVote(owner, 'yes', 'yes');
      check(/\/hlasovani\/potvrdit\/\d+$/.test(owner.url()), 'po odeslání stránka potvrzení kódem');
      const pend = owner.url().split('/').pop();
      const codeMail = mailsSince(m0, who.owner).find((m) => m.subject.startsWith('Kód pro potvrzení hlasu'));
      const code = (/^\s{4}(\d{6})\s*$/m.exec(codeMail?.body || '') || [])[1];
      check(!!code && codeMail.body.includes('Výměna vchodových dveří'), 'e-mail s kódem a volbou');
      check(sql(`SELECT COUNT(*) FROM poll_votes v JOIN poll_items i ON i.id = v.item_id WHERE i.poll_id = ${pollId}`) === '0', 'bez kódu se hlas neuložil');
      check(sql(`SELECT (strftime('%s', expires_at) - strftime('%s', sent_at)) / 60 FROM rollam_pending WHERE id = ${pend}`) === '10', 'kód platí 10 minut');
      await shot(owner.locator('main'), 'rollam-1-potvrzeni.jpg', 1200);
      await owner.fill('[name=code]', code === '000000' ? '111111' : '000000'); await owner.click('main form.form button[type=submit]');
      check((await text(owner)).includes('Kód nesouhlasí. Zbývá pokusů: 4.'), 'špatný kód: zbývají 4 pokusy (celkem 5)');
      await owner.fill('[name=code]', code); await owner.click('main form.form button[type=submit]');
      check((await flash(owner)).includes('je ověřen a zaznamenán') && (await text(owner)).includes('Odhlasováno'), 'hlas po kódu uložen: ' + await flash(owner));
      step('Vlastník odeslal hlas, přišel mu e-mail s kódem; bez kódu se hlas neuložil, kód platí 10 minut, špatný kód ubral pokus (zbývají 4 z 5), správný hlas uložil.');

      // Kód z SMS (druhý vlastník) a „žádné“ ověření (třetí vlastník).
      await admin.goto(T + `/admin/hlasovani/${pollId}`); await admin.selectOption('[name=verify]', 'sms'); await admin.click('main form button[type=submit]');
      await tomas.goto(T + '/hlasovani/' + pollId);
      const smsBefore = smsSent.length;
      await myVote(tomas, 'yes', 'no');
      const smsCode = (/: (\d{6})\. Plati 10 minut/.exec(smsSent[smsBefore]?.message || '') || [])[1];
      check(!!smsCode && smsSent[smsBefore].recipients === '+420604333444', 'SMS s kódem na mobil vlastníka: ' + JSON.stringify(smsSent[smsBefore] || {}));
      await tomas.fill('[name=code]', smsCode); await tomas.click('main form.form button[type=submit]');
      check((await flash(tomas)).includes('je ověřen a zaznamenán'), 'hlas potvrzený kódem z SMS');
      step('S ověřením „kód z SMS“ přišel druhému vlastníkovi kód v SMS a po jeho zadání se hlas uložil.');
      await admin.goto(T + `/admin/hlasovani/${pollId}`); await admin.selectOption('[name=verify]', 'none'); await admin.click('main form button[type=submit]');
      const martin = await as('martin.kucera@example.cz'); await martin.goto(T + '/hlasovani/' + pollId);
      await myVote(martin, 'no', 'no');
      check((await flash(martin)).includes('je zaznamenán') && martin.url().endsWith('/hlasovani/' + pollId), 'bez ověření se hlas uložil hned');
      step('S volbou „žádné“ se hlas uložil hned po odeslání, bez kódu.');
      await martin.context().close();
      await admin.goto(T + `/admin/hlasovani/${pollId}`); await admin.selectOption('[name=verify]', 'email'); await admin.click('main form button[type=submit]');

      // Papírové lístky a listinný hlas.
      await admin.goto(T + `/admin/hlasovani/${pollId}/vysledky`); await admin.click('a:has-text("Hlasovací lístky k tisku")');
      const ballots = admin.locator('.rollam-ballot');
      const b1 = (await ballots.first().textContent()).replace(/\s+/g, ' ');
      check(await ballots.count() === 12 && b1.includes('výměnu vchodových dveří za nejvýše 180 000 Kč') && b1.includes('812/10000') && /doručte nejpozději \d+\. \S+ \d{4}/.test(b1) && b1.includes('Vlastnoruční podpis') && b1.includes('Datum (den, měsíc, rok)'),
        'lístky: 12 jednotek, plné znění, podíl, lhůta, místo pro datum a podpis: ' + (await ballots.count()) + ' ' + b1);
      step('Hlasovací lístky: 12 lístků (za každou jednotku) s plným zněním návrhu, podílem, lhůtou a místem pro datum a vlastnoruční podpis.');
      await admin.goto(T + `/admin/hlasovani/${pollId}/listina`);
      const paper = async (unit, received, replace = false) => {
        await admin.selectOption('[name=unit_id]', unitId(unit)); await admin.locator('.poll-items fieldset').nth(0).locator('input[value=yes]').check(); await admin.locator('.poll-items fieldset').nth(1).locator('input[value=abstain]').check();
        await admin.fill('[name=signed_on]', today); await admin.fill('[name=received_on]', received); if (replace) await admin.check('[name=replace]');
        await admin.setInputFiles('[name=scan]', pdfFile('listek-' + unit.replace('/', '') + '.pdf')); await admin.click('main form button[type=submit]');
      };
      await paper('A/4', today);
      check((await flash(admin)).includes('Listinný hlas je zapsán') && (await text(admin)).includes('listek-A4.pdf'), 'listinný hlas se skenem zapsán');
      await paper('A/2', today);
      check((await text(admin)).includes('Za jednotku 12A/2 už hlas je'), 'druhý hlas za jednotku bez „Nahradit“ odmítnut: ' + (await text(admin)).slice(0, 500));
      step('Listinný hlas jednotky A/4 se skenem lístku je zapsaný; hlas za jednotku, která už hlasovala, web bez volby „Nahradit dosavadní hlas“ nepřijme.');

      // Oprava hlasu výborem v přehledu po jednotkách.
      await admin.goto(T + `/admin/hlasovani/${pollId}/vysledky`);
      const rowA2 = admin.locator('#j' + unitId('A/2'));
      await rowA2.locator('select').nth(1).selectOption('abstain'); await rowA2.locator('button[type=submit]').click();
      for (let i = 0; i < 50 && sql(`SELECT COUNT(*) FROM rollam_records WHERE poll_id = ${pollId}`) !== '5'; i++) await admin.waitForTimeout(100);
      check(sql(`SELECT COUNT(*) FROM rollam_records WHERE poll_id = ${pollId}`) === '5' && sql(`SELECT method FROM rollam_records WHERE poll_id = ${pollId} ORDER BY id DESC LIMIT 1`) === 'spravce', 'oprava výborem je nový záznam, původní zůstal');
      step('Výbor opravil hlas jednotky A/2 v přehledu hlasů po jednotkách; v protokolu přibyl nový záznam a původní zůstal.');

      // Protokol a kontrola neporušenosti.
      await admin.goto(T + `/admin/hlasovani/${pollId}/protokol`);
      const pr = await text(admin);
      check(pr.includes('více než 50 % hlasů všech vlastníků') && /(NE)?PŘIJATO/.test(pr) && pr.includes('V pořádku. Všech 5 záznamů') && pr.includes('127.0.0.1') && pr.includes('Mozilla/5.0') && pr.includes('listek-A4.pdf') && pr.includes('Vytisknout / uložit jako PDF'),
        'protokol: většina nad 50 %, výsledek bodů, záznamy s adresou a zařízením, kontrola v pořádku: ' + pr.slice(400, 3000));
      step('Protokol: návrh, výsledek každého bodu, potřebná většina „více než 50 % všech hlasů“ i při nastavených 40 %, 5 záznamů s časem, ověřením, adresou a zařízením, sken lístku a kontrola „V pořádku“.');
      await admin.evaluate(() => { const h = [...document.querySelectorAll('.rollam-doc h2')].find((x) => x.textContent.includes('Výsledek')); const w = document.createElement('div'); w.id = 'protokol-vysledek'; h.before(w); let n = h; const parts = []; while (n && !(n !== h && n.tagName === 'H2' && n.textContent.includes('Kontrola'))) { parts.push(n); n = n.nextElementSibling; } parts.forEach((x) => w.appendChild(x)); });
      await shot(admin.locator('#protokol-vysledek'), 'rollam-2-protokol.jpg', 1100);
      const firstRec = sql(`SELECT id FROM rollam_records WHERE poll_id = ${pollId} ORDER BY id LIMIT 1`); const origChoices = sql(`SELECT choices FROM rollam_records WHERE id = ${firstRec}`);
      run(`UPDATE rollam_records SET choices = replace(choices, 'yes', 'no') WHERE id = ${firstRec}`);
      await admin.reload();
      check((await text(admin)).includes('Řetěz otisků je porušen od záznamu č. 1'), 'změna záznamu přímo v databázi odhalena');
      run(`UPDATE rollam_records SET choices = '${origChoices}' WHERE id = ${firstRec}`);
      const voteA4 = sql(`SELECT v.rowid FROM poll_votes v JOIN poll_items i ON i.id = v.item_id WHERE i.poll_id = ${pollId} AND v.unit_id = ${unitId('A/4')} ORDER BY i.sort LIMIT 1`);
      run(`UPDATE poll_votes SET choice = 'no' WHERE rowid = ${voteA4}`);
      await admin.reload();
      check((await text(admin)).includes('Hlasy těchto jednotek neodpovídají protokolu: 12A/4'), 'změna hlasu v databázi odhalena');
      run(`UPDATE poll_votes SET choice = 'yes' WHERE rowid = ${voteA4}`);
      step('Když se záznam nebo hlas změnil přímo v databázi, protokol to ukázal (porušený řetěz otisků, hlas neodpovídá protokolu).');
      const itemId = sql(`SELECT id FROM poll_items WHERE poll_id = ${pollId} ORDER BY sort LIMIT 1`);
      run(`INSERT INTO poll_votes (item_id, unit_id, user_id, choice) VALUES (${itemId}, ${unitId('B/6')}, 0, 'yes')`);
      await admin.goto(T + `/admin/hlasovani/${pollId}/vysledky`);
      check((await text(admin)).includes('hlasy bez záznamu: 1'), 'hlas bez záznamu v protokolu');
      run(`DELETE FROM poll_votes WHERE item_id = ${itemId} AND unit_id = ${unitId('B/6')}`);
      step('Hlas bez záznamu v protokolu (jako z doby před jeho zavedením) se u výsledků ukázal jako „hlasy bez záznamu“.');
      // Lístek doručený po lhůtě: hlasování skončilo včera.
      run(`UPDATE polls SET opens_at = '${plusDays(-20)}', closes_at = '${plusDays(-1)}' WHERE id = ${pollId}`);
      await admin.goto(T + `/admin/hlasovani/${pollId}/listina`); await paper('B/2', today);
      check((await text(admin)).includes('Lístek došel po skončení lhůty'), 'lístek po lhůtě odmítnut');
      step('Lístek doručený po skončení lhůty web nepřijal.');
      await owner.goto(T + '/hlasovani/' + pollId);
      check(!(await owner.$('.poll-form button[type=submit]')), 'po lhůtě už vlastník nehlasuje');
    }

    // ---------- Shromáždění online a hybridně ----------
    begin('shromazdeni-online', 'Videohovor samotný (Jitsi Meet, Microsoft Teams, Zoom, Google Meet) jsme neověřovali – jde o cizí služby; přihlášení předsedajícího na meet.jit.si a limit 25 připojení měsíčně jsou podle stránek Jitsi (oznámení 8/2023, podmínky 2020), neověřeno v provozu; ověřovací běh místo nich otevřel prázdnou zkušební stránku. Ověřeno je vše na webu: zapnutí pro dům, forma u termínu, připojení vlastníků s kódem z e-mailu, prezence, hlasování online, odchod a zápis.');
    {
      await admin.goto(T + '/admin/shromazdeni-online');
      check((await text(admin)).includes('Vzorové ustanovení stanov') && (await text(admin)).includes('technických prostředků'), 'vzorové ustanovení stanov na stránce');
      await admin.check('[name=hybrid_on]'); await admin.check('[name=hybrid_email_code]');
      await shot(admin.locator('main'), 'shrom-online-1-nastaveni.jpg', 900);
      await admin.click('main form button[type=submit]');
      check((await flash(admin)).includes('U termínu shromáždění teď můžete zvolit hybridní nebo online formu'), 'shromáždění online povoleno');
      step('Administrace → Shromáždění online: povoleno pro dům i ověření kódem z e-mailu; stránka nabízí vzorové ustanovení stanov.');
      edition('standard'); await admin.goto(T + '/admin/terminy/novy');
      const std = await text(admin, 'fieldset.hybrid-fields');
      check(!std.includes('Kč') && std.includes('Vytvořit místnost Jitsi') && std.includes('přihlásit účtem Google, GitHub nebo Facebook'), 'edice Standard: online forma bez ceny, poznámka o přihlášení k Jitsi: ' + std.slice(0, 300));
      edition('premium', true); await admin.goto(T + '/admin/terminy/novy');
      check(!(await text(admin, 'fieldset.hybrid-fields')).includes('Kč'), 'v edici Premium v ceně');
      step('U termínu ve Standardu i Premium je online forma v ceně (žádná cena); u tlačítka Vytvořit místnost Jitsi web upozorní, že předsedající se k Jitsi přihlásí účtem Google, GitHub nebo Facebook.');
      await admin.goto(T + '/admin/terminy/novy');
      await admin.fill('[name=title]', 'Mimořádné shromáždění vlastníků'); await admin.fill('[name=event_date]', today); await admin.fill('[name=event_time]', '18:00'); await admin.fill('[name=place]', 'Kočárkárna a online');
      await admin.fill('[name=program]', '- Zahájení shromáždění\n- Výměna vchodových dveří za nejvýše 180 000 Kč\n- Ukončení shromáždění');
      check(await admin.isHidden('[data-hybrid-remote]'), 'u prezenční formy pole videohovoru skrytá');
      await admin.check('input[name=hybrid_form][value=hybridni]');
      await admin.click('[data-jitsi-room]');
      const room = await admin.inputValue('[name=hybrid_video_url]');
      check(/^https:\/\/meet\.jit\.si\/Shromazdeni-[a-z2-9]{20}$/.test(room), 'tlačítko vytvořilo místnost Jitsi s náhodným názvem: ' + room);
      await admin.click('main form button[type=submit]');
      check((await text(admin)).includes('jde zapnout jen tehdy, když stanovy umožňují'), 'bez potvrzení stanov termín nejde uložit');
      await admin.check('[name=hybrid_statutes]');
      await shot(admin.locator('fieldset.hybrid-fields'), 'shrom-online-2-termin.jpg');
      await admin.click('main form button[type=submit]');
      const ev = sql("SELECT id FROM events WHERE title = 'Mimořádné shromáždění vlastníků'");
      check(!!ev && sql(`SELECT form || ' ' || statutes_ok FROM hybrid_events WHERE event_id = ${ev}`) === 'hybridni 1', 'termín uložen jako hybridní');
      step('U termínu: forma Hybridní, „Vytvořit místnost Jitsi“ doplnilo adresu meet.jit.si s náhodným názvem; bez potvrzení stanov termín uložit nešel, s ním ano.');

      // Prezence na místě: 7 jednotek osobně, návrhy z programu.
      await admin.goto(T + `/admin/terminy/${ev}/prezence-na-miste`);
      for (const u of ['A/1', 'A/4', 'A/5', 'A/6', 'B/1', 'B/2', 'B/4']) { const id = unitId(u); await admin.click(`#j${id} input[name=mode][value=self] + span`); await admin.waitForFunction((x) => document.querySelector('#j' + x + ' .presence-state').textContent === 'uloženo', id); }
      await admin.click('.motion-program button'); await admin.waitForURL('**#navrhy');
      const joinOnline = async (page, email) => {
        await page.goto(T + '/terminy/' + ev); await page.click('#online a:has-text("Připojit se online")');
        const m1 = mailLen(); await page.click('form:has(input[name=do][value=join]) button[type=submit]');
        check((await flash(page)).includes('Poslali jsme Vám kód na e-mail'), 'kód pro připojení poslán');
        const c = (/^\s{4}(\d{6})\s*$/m.exec(mailsSince(m1, email).find((x) => x.subject.startsWith('Kód pro připojení ke shromáždění'))?.body || '') || [])[1];
        await page.fill('[name=code]', c || '');
        const [pop] = await Promise.all([page.context().waitForEvent('page'), page.click('form:has(input[name=do][value=code]) button[type=submit]')]);
        await pop.waitForLoadState().catch(() => {}); const popUrl = pop.url(); await pop.close();
        return popUrl;
      };
      const popUrl = await joinOnline(owner, who.owner);
      check(popUrl === room, 'videohovor se otevřel v nové kartě: ' + popUrl);
      check((await text(owner, '.hybrid-status')).includes('Jste přítomen(a) online') && (await text(owner, '.hybrid-status')).includes('12A/2'), 'vlastník přítomen online za svou jednotku');
      await shot(owner.locator('.hybrid-status'), 'shrom-online-3-pripojeni.jpg');
      step('Vlastník v den konání klikl na „Připojit se online“, zadal kód z e-mailu; web ho zapsal do prezence za jednotku 12A/2 a videohovor otevřel v nové kartě.');
      // Prezence správce se obnoví sama.
      const a2 = unitId('A/2');
      await admin.waitForFunction((x) => document.querySelector('[data-p=online]')?.textContent === '1' && !document.querySelector('#j' + x + ' [data-online-badge]').hidden, a2, { timeout: 12000 });
      check(true, 'prezence se obnovila sama');
      await joinOnline(tomas, 'tomas.dvorak@example.cz');
      await admin.reload();
      const log = await text(admin, '.hybrid-panel');
      check(log.includes('Petr Novák') && log.includes('přihlášení + kód z e-mailu') && log.includes('127.0.0.1') && (await text(admin, '[data-p=online]')) === '2', 'seznam připojení: kdo, kdy, ověření, IP');
      check((await text(admin, '[data-p=verdict]')).includes('je usnášeníschopné'), 'usnášeníschopnost včetně online');
      step('V Prezenci na místě se online přítomnost a součet podílů obnovily samy (bez načtení stránky); seznam připojení ukazuje kdo, kdy, ověření a IP adresu.');
      await admin.evaluate(() => window.scrollTo(0, 0)); await shot(admin.locator('.presence-bar'), 'shrom-online-4-prezence.jpg');

      // Hlasování online.
      const motion = admin.locator('.motion', { hasText: 'Výměna vchodových dveří' });
      const mid = (await motion.getAttribute('id')).slice(1);
      await admin.click(`.hybrid-live-list form:has(input[value="${mid}"]) button[value=live-on]`);
      check((await flash(admin)).includes('Návrh je puštěný k hlasování online'), 'návrh puštěn k hlasování online');
      await owner.waitForSelector('.hybrid-motion', { timeout: 12000 });
      await owner.click('.hybrid-motion button[value=no]');
      check((await flash(owner)).includes('Hlas je zaznamenaný: proti'), 'vlastník hlasoval online');
      await admin.waitForFunction((x) => (document.querySelector('[data-motion="' + x + '"] [data-m=online]')?.textContent || '').includes('Online pro 0, proti 1, zdrželo se 1'), mid, { timeout: 12000 });
      check(true, 'průběžný výsledek: online proti 1, nehlasující online se zdržel');
      step('Návrh puštěný k hlasování online se vlastníkovi objevil sám; hlasoval „proti“. U návrhu se průběžně ukázalo „Online pro 0, proti 1, zdrželo se 1“ – druhý online přítomný nehlasoval, počítá se jako zdržel se.');
      await admin.locator(`#m${mid} details.motion-votes`).evaluate((d) => { d.open = false; });
      await shot(admin.locator(`#m${mid}`), 'shrom-online-5-hlasovani.jpg');
      // Odchod a návrat.
      await tomas.goto(T + '/terminy/' + ev + '/online'); await tomas.click('button[value=leave]');
      check((await flash(tomas)).includes('Odešel(a) jste ze shromáždění'), 'odchod');
      await admin.waitForFunction(() => document.querySelector('[data-p=online]')?.textContent === '1', null, { timeout: 12000 });
      check(true, 'po odchodu se podíly nepočítají');
      await joinOnline(tomas, 'tomas.dvorak@example.cz');
      check((await text(tomas, '.hybrid-status')).includes('Jste přítomen(a) online'), 'návrat stejným tlačítkem');
      step('Druhý vlastník klikl „Odcházím“ – přestal se počítat do přítomných; tlačítkem „Připojit se online“ se vrátil.');
      await admin.reload();
      await admin.locator(`#m${mid} button[value=motion-close]`).click(); await admin.waitForLoadState();
      await owner.goto(T + '/terminy/' + ev + '/online');
      check(!(await owner.$('.hybrid-motion')) && (await text(owner)).includes('Právě se o ničem nehlasuje'), 'po uzavření už online hlasovat nejde');
      step('Po „Uzavřít hlasování“ návrh z obrazovky vlastníka zmizel – online už hlasovat nejde.');
      await admin.goto(T + `/admin/terminy/${ev}/zapis`);
      const z = await text(admin);
      check(z.includes('Shromáždění se konalo jako hybridní') && z.includes('Jitsi Meet') && z.includes('jednorázovým kódem zaslaným e-mailem') && z.includes('Online se účastnili') && /12A\/3[^–]*–\s*\d\d:\d\d–\d\d:\d\d, \d\d:\d\d–konec/.test(z) && z.includes('Online pro 0, proti 1, zdrželo se 1'),
        'zápis: forma, služba, online účastníci s časy, hlasy online a na místě: ' + z.slice(0, 900));
      step('Zápis uvádí formu (hybridní), službu (Jitsi Meet), ověření kódem z e-mailu, online účastníky s časy připojení a odchodu a u návrhu hlasy online a na místě.');
      await admin.evaluate(() => { const h = document.querySelector('.hybrid-minutes-h'); const w = document.createElement('div'); w.id = 'zapis-online'; h.before(w); let n = h; const parts = []; while (n && !(n !== h && n.tagName === 'H2')) { parts.push(n); n = n.nextElementSibling; } parts.forEach((x) => w.appendChild(x));
        const p = [...document.querySelectorAll('.minutes p')].find((x) => x.textContent.includes('Online pro 0')); if (p) w.appendChild(p.cloneNode(true)); });
      await shot(admin.locator('#zapis-online'), 'shrom-online-6-zapis.jpg');
      await admin.goto(T + `/admin/terminy/${ev}/prezence`);
      const pl = await text(admin);
      check(pl.includes('online 2') && pl.includes('Účast online') && pl.includes('Hlasy odevzdané online') && pl.includes('Proti'), 'prezenční listina: online jednotky, seznam připojení a hlasů online: ' + pl.slice(0, 400));
      step('Prezenční listina má u online jednotek „online“ a v příloze seznam připojení a hlasů odevzdaných online.');
    }

    // ---------- Vyúčtování služeb ----------
    begin('vyuctovani-sluzeb', 'Jak soubory vyúčtování pojmenovává odečtová nebo správcovská firma, jsme ověřit nemohli – běh použil názvy podle návodu (A-1.pdf, vyuctovani_A_2.pdf …) a jeden soubor s názvem bez jednotky.');
    {
      const Y = new Date().getFullYear() - 1;
      await admin.goto(T + '/admin/vyuctovani');
      check((await text(admin)).includes('doručení do 4 měsíců od konce období, námitky do 30 dnů od doručení a jejich vyřízení do 30 dnů'), 'lhůty podle zákona č. 67/2013 Sb.');
      check((await admin.inputValue('[name=title]')) === `Vyúčtování služeb za rok ${Y}` && (await admin.inputValue('[name=period_to]')) === `${Y}-12-31`, 'předvyplněné loňské období');
      await admin.click('button:has-text("Založit vyúčtování")');
      const sid = admin.url().split('/').pop();
      check((await text(admin, '.page-head')).includes(`Zákonná lhůta pro doručení: 30. 4. ${Y + 1}`), `lhůta pro doručení do 30. 4. ${Y + 1}`);
      step(`Vyúčtování za rok ${Y} je založené; web ukazuje zákonnou lhůtu pro doručení 30. 4. ${Y + 1} (4 měsíce od konce období).`);
      await admin.setInputFiles('[name="files[]"]', ['A-1.pdf', 'vyuctovani_A_2.pdf', 'A-3.pdf', 'A-5.pdf', 'B-1.pdf', 'sken-0042.pdf'].map(pdfFile));
      await admin.click('button:has-text("Nahrát")');
      check((await flash(admin)).includes('Nahráno a přiřazeno k jednotkám: 5. U 1 souborů se jednotka z názvu nepoznala'), 'nahráno 5 + 1 bez jednotky: ' + await flash(admin));
      check((await text(admin)).includes('Soubory bez jednotky') && (await text(admin, '#j' + sql(`SELECT id FROM modf_settlement_items WHERE original_name = 'A-5.pdf'`))).includes('bez účtu vlastníka'), 'soubor bez jednotky a jednotka bez účtu vlastníka');
      await shot(admin.locator('section:has(#modf-un-h)'), 'vyuctovani-1-nahrano.jpg', 1100);
      await admin.selectOption('section:has(#modf-un-h) select[name=unit_id]', unitId('A/4')); await admin.click('section:has(#modf-un-h) button:has-text("Přiřadit")');
      check((await flash(admin)).includes('Soubor je přiřazený k jednotce') && !(await admin.$('#modf-un-h')), 'ručně přiřazeno');
      step('Nahráno 6 souborů: 5 se podle názvu přiřadilo k jednotkám, „sken-0042.pdf“ byl v Souborech bez jednotky a po výběru jednotky a „Přiřadit“ se přiřadil; jednotka 12A/5 je označená „bez účtu vlastníka“.');
      const m0 = mailLen();
      await admin.fill('[name=delivery_date]', today); await admin.click('button:has-text("Zveřejnit")');
      check((await flash(admin)).includes('Vyúčtování je zveřejněné a doručené 6 jednotkám'), 'zveřejněno: ' + await flash(admin));
      const pub = mailsSince(m0);
      check(pub.some((m) => m.to === who.owner) && !pub.some((m) => m.to === 'lukas.cerny@example.cz'), 'e-mail vlastníkům, nájemníkovi ne');
      check(!(await admin.$('button:has-text("Smazat vyúčtování")')) && (await text(admin, '.page-head')).includes(`námitky lze podat do ${czDate(plusDays(30))}`), 'po zveřejnění nejde smazat, námitky do 30 dnů');
      step(`Po „Zveřejnit“ dostali vlastníci e-mail (nájemník ne); námitky lze podat do ${czDate(plusDays(30))} a vyúčtování už nejde smazat.`);
      const lukas = await as('lukas.cerny@example.cz'); await lukas.goto(T + '/vyuctovani');
      check((await text(lukas)).includes('Vyúčtování služeb dostávají vlastníci jednotek'), 'nájemník vyúčtování nevidí'); await lukas.context().close();
      // Vlastník: Můj účet → Moje vyúčtování, převzetí a námitka.
      await owner.goto(T + '/muj-ucet'); await owner.click('a:has-text("Moje vyúčtování")');
      const card = owner.locator('.modf-my').first();
      check((await card.textContent()).includes('Otevřít vyúčtování (PDF)'), 'vlastník vidí vyúčtování své jednotky');
      const pdfHead = await owner.evaluate(async (u) => { const r = await fetch(u); return r.status + ' ' + (await r.text()).slice(0, 5); }, await card.locator('a:has-text("Otevřít vyúčtování")').getAttribute('href'));
      check(pdfHead === '200 %PDF-', 'PDF jde otevřít: ' + pdfHead);
      await card.locator('button:has-text("Potvrzuji převzetí")').click();
      check((await flash(owner)).includes('převzetí vyúčtování je potvrzené'), 'převzetí potvrzeno');
      await owner.locator('.modf-objection-form summary').click(); await owner.fill('.modf-objection-form [name=text]', 'Odečet vodoměru v kuchyni neodpovídá – stav k 31. 12. byl 41,2 m³.');
      await shot(owner.locator('.modf-my').first(), 'vyuctovani-2-vlastnik.jpg', 1400);
      const m1 = mailLen();
      await owner.click('.modf-objection-form button[type=submit]');
      check((await flash(owner)).includes('Námitka je podaná'), 'námitka podána');
      check(mailsSince(m1, who.admin).some((m) => m.subject.includes('Námitka k vyúčtování')), 'správcům přišel e-mail o námitce');
      step('Vlastník přes Můj účet → Moje vyúčtování otevřel PDF, potvrdil převzetí a podal námitku; správcům přišel e-mail.');
      await admin.goto(T + '/admin/vyuctovani/' + sid);
      const row = await text(admin, '#j' + sql(`SELECT id FROM modf_settlement_items WHERE original_name = 'vyuctovani_A_2.pdf'`));
      check(row.includes('Petr Novák') && row.includes(czDate(plusDays(30))) && row.includes('vyřídit do ' + czDate(plusDays(30))), 'stav po jednotkách: potvrzeno, námitky do, vyřídit do: ' + row);
      const m2 = mailLen();
      await admin.fill('.modf-objection [name=answer]', 'Námitce vyhovujeme, opravené vyúčtování nahrajeme do 14 dnů.'); await admin.click('button:has-text("Odeslat odpověď a uzavřít")');
      check((await flash(admin)).includes('Námitka je vyřízená') && mailsSince(m2, who.owner).some((m) => m.body.includes('Námitce vyhovujeme')), 'odpověď vlastníkovi e-mailem');
      step('Stav po jednotkách ukázal potvrzení převzetí, lhůtu pro námitky a lhůtu k vyřízení; „Odeslat odpověď a uzavřít“ poslalo vlastníkovi odpověď e-mailem.');
      // Připomínky: námitka 25 dní stará a vyúčtování, kterému za 10 dní končí lhůta pro doručení.
      await tomas.goto(T + '/vyuctovani'); await tomas.locator('.modf-objection-form summary').click(); await tomas.fill('.modf-objection-form [name=text]', 'Nesouhlasí počet osob v bytě.'); await tomas.click('.modf-objection-form button[type=submit]');
      run(`UPDATE modf_objections SET created_at = '${plusDays(-25)} 10:00:00' WHERE answered_at = ''`);
      const end = new Date(Date.now() + 10 * 86400000); end.setMonth(end.getMonth() - 4); end.setDate(0 + end.getDate());
      await admin.goto(T + '/admin/vyuctovani'); await admin.fill('[name=title]', 'Vyúčtování tepla – zkouška lhůty'); await admin.fill('[name=period_from]', `${Y}-01-01`); await admin.fill('[name=period_to]', end.toISOString().slice(0, 10)); await admin.click('button:has-text("Založit vyúčtování")');
      const m3 = mailLen(); await daily();
      const rem = mailsSince(m3, who.admin).map((m) => m.subject);
      check(rem.some((x) => x.includes('Nevyřízená námitka k vyúčtování')) && rem.some((x) => x.includes('Lhůta pro doručení vyúčtování')), 'připomínky správcům: ' + rem.join(' | '));
      step('Denní úloha webu připomněla správcům námitku, které za 5 dní končí lhůta k vyřízení, a vyúčtování, které je třeba doručit do 10 dnů.');
    }

    // ---------- Plán oprav a fond oprav ----------
    begin('plan-oprav', 'Stav fondu oprav z účetnictví si správce přináší zvenku – běh použil smyšlenou částku.');
    {
      const Y = new Date().getFullYear();
      await admin.goto(T + '/admin/plan-oprav');
      check((await flash(admin)).includes('Začněte nastavením fondu'), 'výzva k nastavení fondu');
      const fund = async (monthly, mode = 'shares', publish = false) => {
        await admin.fill('#fond [name=balance]', '650000'); await admin.fill('#fond [name=balance_date]', `${Y}-10-31`); await admin.check(`#fond [name=mode][value=${mode}]`);
        await admin.fill('#fond [name=monthly]', String(monthly)); await admin.selectOption('#fond [name=years]', '15'); await admin.fill('#fond [name=inflation]', '3');
        if (publish) await admin.check('#fond [name=published]'); await admin.click('#fond button[type=submit]');
      };
      await fund(15000);
      check((await flash(admin)).includes('Nastavení fondu je uložené'), 'fond uložen');
      const repair = async (title, year, cost, status, priority = '2') => {
        await admin.locator('details.modf-add summary').click(); await admin.fill('.modf-add [name=title]', title); await admin.fill('.modf-add [name=year]', String(year)); await admin.fill('.modf-add [name=cost]', String(cost));
        await admin.selectOption('.modf-add [name=priority]', priority); await admin.selectOption('.modf-add [name=status]', status); await admin.click('.modf-add button[type=submit]');
      };
      await repair('Výměna střešní krytiny', Y + 3, 1800000, 'planned', '1');
      await repair('Oprava stoupaček', Y + 8, 900000, 'approved');
      await repair('Výměna zvonků', Y - 1, 50000, 'done', '3');
      await repair('Nátěr zábradlí', Y - 1, 40000, 'planned', '3');
      check((await admin.locator('#opravy tbody tr').count()) === 4 && (await text(admin, '#opravy')).includes('po plánu'), '4 opravy, nehotová z loňska po plánu');
      const firstCost = await admin.locator('.modf-chart + .modf-legend + details tbody tr').first().locator('td').nth(3).textContent();
      check(firstCost.replace(/\D/g, '') === '40000', 'první rok výhledu: jen nehotová loňská oprava 40 000 Kč, hotová se nepočítá: ' + firstCost);
      step(`Nastaven fond 650 000 Kč k 31. 10. ${Y}, příspěvek 15 000 Kč měsíčně za dům, výhled 15 let, růst cen 3 %; přidány 4 opravy. Hotová oprava se do výhledu nepočítá, nehotová z loňska je v prvním roce.`);
      const al = await text(admin, '.modf-alert');
      const needed = Number((/alespoň ([\d\s ]+)Kč/.exec(al) || [])[1]?.replace(/\D/g, '') || 0);
      check(/Fond by v roce \d{4} šel do mínusu/.test(al) && needed > 15000 && al.includes('průměrně o') && (await admin.locator('.modf-col.is-neg').count()) > 0, 'fond do mínusu, potřebný příspěvek, roky v mínusu v grafu: ' + al);
      await admin.evaluate(() => { const a = document.querySelector('.modf-alert'); const c = document.querySelector('section:has(#modf-chart-h)'); const w = document.createElement('div'); w.id = 'fond-vyhled'; a.before(w); w.append(a, c); });
      await shot(admin.locator('#fond-vyhled'), 'plan-oprav-1-vyhled.jpg', 1000);
      await admin.reload();
      await admin.locator('details:has(summary:has-text("jednotek"))').evaluate((d) => { d.open = true; });
      const contrib = await text(admin, 'section.card:has(h2:has-text("Příspěvky jednotek"))');
      check(contrib.includes('Rozpočítáno podle spoluvlastnických podílů') && contrib.includes('Potřeba') && (await admin.locator('section.card:has(h2:has-text("Příspěvky jednotek")) tbody tr').count()) === 12, 'příspěvky 12 jednotek podle podílů i potřebná výše');
      step(`Web zvýraznil roky v mínusu a nad grafem uvedl nejnižší potřebný příspěvek ${needed.toLocaleString('cs-CZ')} Kč měsíčně (i průměrně na jednotku); Příspěvky jednotek ukazují rozpočet podle podílů.`);
      await fund(needed);
      check((await flash(admin)).includes('Nastavení fondu je uložené') && (await text(admin)).includes('Při současných příspěvcích fond vystačí') && (await admin.locator('.modf-col.is-neg').count()) === 0, 's navrženým příspěvkem fond nikdy neklesne pod nulu');
      step('S navrženým příspěvkem web hlásí, že fond vystačí – žádný rok v mínusu.');
      await fund(1500, 'flat');
      check((await text(admin, 'section.card:has(h2:has-text("Příspěvky jednotek"))')).includes('Stejná částka za každou jednotku'), 'stejná částka za jednotku');
      await fund(needed, 'shares', true);
      await owner.goto(T + '/muj-ucet'); await owner.click('a:has-text("Plán oprav a fond oprav")');
      const pub = await text(owner);
      check(pub.includes('měsíčně za jednotku 12A/2') && pub.includes('Výměna střešní krytiny') && !pub.includes('Výměna zvonků') && !(await owner.$('main form')), 'obyvatel vidí přehled jen pro čtení');
      step('Po „Zveřejnit přehled obyvatelům“ vidí vlastník přes Můj účet stránku Plán oprav: stav fondu, plánované opravy, výhled a svůj měsíční příspěvek – bez možnosti cokoli měnit.');
    }

    // ---------- Propojení s účetnictvím ----------
    begin('ucetnictvi', 'Import souborů do skutečné POHODY, Money S3 a ABRA Flexi jsme neověřili – tyto programy nemáme; ověřeno je, že web soubory vytvoří ve tvaru podle zveřejněných schémat výrobců. Předvolby programů správy domů (DOMSYS, IDES, MECOM, INTEGRI) jsou jen odhad názvů sloupců – soubory z těchto programů jsme neměli.');
    {
      check(await navGroup(admin, '/admin/ucetnictvi') === 'Dům a lidé', 'Účetnictví v menu administrace');
      run("DELETE FROM settings WHERE key = 'ico'");
      await admin.goto(T + '/admin/ucetnictvi');
      check((await flash(admin)).includes('chybí IČ společenství'), 'upozornění na chybějící IČ');
      run("INSERT INTO settings (key, value) VALUES ('ico', '04512345') ON CONFLICT(key) DO UPDATE SET value = excluded.value");
      await admin.reload();
      check(!(await flash(admin)).includes('chybí IČ'), 'po doplnění IČ upozornění zmizí');
      await admin.fill('[name=acct_bank_code]', 'KB'); await admin.fill('[name=acct_due_day]', '15'); await admin.click('form:has([value=settings]) button[type=submit]');
      check((await flash(admin)).includes('Nastavení exportu je uložené'), 'nastavení exportu uloženo');
      step('Bez IČ v Nastavení web upozorní; v Nastavení exportu uložena zkratka účtu KB a splatnost 15. dne.');
      const exportAs = async (format) => { await admin.goto(T + '/admin/ucetnictvi'); await admin.check(`[name=format][value=${format}]`); return download(admin, () => admin.click('button:has-text("Stáhnout export")')); };
      const pohoda = fs.readFileSync(await exportAs('pohoda')).toString('latin1');
      check(pohoda.includes('encoding="Windows-1250"') && pohoda.includes('dat:dataPack') && pohoda.includes('04512345') && pohoda.includes('<adb:addressbook') && pohoda.includes('<inv:invoiceType>receivable</inv:invoiceType>') && pohoda.includes('<bnk:bank') && pohoda.includes('KB') && /-15</.test(pohoda),
        'POHODA: adresář, ostatní pohledávky, banka, IČ, zkratka účtu, splatnost: ' + pohoda.slice(0, 300));
      const money = fs.readFileSync(await exportAs('money'), 'utf8');
      check(money.includes('<MoneyData') && money.includes('<SeznamFirem>') && money.includes('<SeznamPohledavek>') && money.includes('<SeznamBankDokl>'), 'Money S3: firmy, pohledávky, bankovní doklady');
      const flexi = fs.readFileSync(await exportAs('flexi'), 'utf8');
      check(flexi.includes('<winstrom') && flexi.includes('<adresar') && flexi.includes('<pohledavka') && flexi.includes('<banka'), 'ABRA Flexi: winstrom s adresářem, pohledávkami a bankou');
      const csvZip = zipFiles(await exportAs('csv'));
      check(Object.keys(csvZip).length === 4 && Object.keys(csvZip).some((n) => n.endsWith('-salda.csv')), 'CSV: ZIP se 4 soubory včetně stavů účtů: ' + Object.keys(csvZip));
      step('Export do POHODY (XML Windows-1250: adresář, ostatní pohledávky, banka), Money S3 (MoneyData), ABRA Flexi (winstrom) i CSV (4 soubory včetně stavů účtů) se stáhl a obsahuje předpisy s variabilním symbolem, IČ, zkratku účtu a splatnost.');
      // Import stavů účtů z CSV ve Windows-1250 se středníkem.
      const w1250 = (str) => Buffer.from([...str].map((ch) => ({ 'á': 0xE1, 'č': 0xE8, 'ř': 0xF8, 'í': 0xED, 'é': 0xE9, 'ý': 0xFD, 'ž': 0x9E, 'š': 0x9A, 'ů': 0xF9, 'ě': 0xEC, 'Č': 0xC8, 'Ř': 0xD8, 'Š': 0x8A, 'Ž': 0x8E, 'ú': 0xFA })[ch] ?? ch.charCodeAt(0)));
      const pre = sql("SELECT value FROM settings WHERE key = 'site_unit_prefix'") || '';
      const csvF = path.join(STORE, 'soubory', 'saldo.csv');
      fs.writeFileSync(csvF, w1250(`Číslo jednotky;Variabilní symbol;Předpis záloh;Zůstatek Kč\n${pre}A/1;4801;4 250;-1 200,50 Kč\n;4803;3 000;0\nZ/9;9999;1000;0\n`));
      await admin.goto(T + '/admin/ucetnictvi'); await admin.check('[name=target][value=accounts]'); await admin.setInputFiles('#import [name=file]', csvF); await admin.click('button:has-text("Nahrát a zkontrolovat")');
      const head = await text(admin, '.page-head');
      check(head.includes('Windows-1250') && head.includes('oddělovač „;“') && head.includes('3 řádků'), 'kódování a oddělovač poznány: ' + head);
      const sel = async (f) => (await admin.locator(`[name="map[${f}]"] option:checked`).textContent()).trim();
      check((await sel('unit')).startsWith('Číslo jednotky') && (await sel('vs')).startsWith('Variabilní symbol') && (await sel('prescribed')).startsWith('Předpis záloh') && (await sel('balance')).startsWith('Zůstatek'), 'sloupce odhadnuty podle záhlaví');
      await shot(admin.locator('main form').first(), 'ucetnictvi-1-prirazeni.jpg', 1000);
      await admin.selectOption('[name="map[balance]"]', ''); await admin.click('button[value=check]');
      check((await text(admin, '.acct-preview')).includes('—') && (await sel('balance')) === '— nepoužít —', 'změna přiřazení a Zkontrolovat znovu');
      await admin.selectOption('[name="map[balance]"]', { label: (await admin.locator('[name="map[balance]"] option', { hasText: 'Zůstatek' }).textContent()).trim() }); await admin.click('button[value=check]');
      const pvw = await text(admin);
      check(pvw.includes('Uloží se 2') && pvw.includes('s chybou 1') && (await admin.locator('.acct-preview tr.is-unmatched').count()) === 1, 'náhled: 2 se uloží, neznámá jednotka s chybou: ' + pvw.slice(0, 300));
      await admin.click('button[value=save]');
      check((await flash(admin)).includes('Import hotov: upraveno 2') && (await flash(admin)).includes('Řádky s chybou (1) se vynechaly') && sql(`SELECT balance FROM unit_accounts WHERE unit_id = ${unitId('A/1')}`) === '-1200.5' && Number(sql(`SELECT prescribed FROM unit_accounts WHERE unit_id = ${unitId('A/3')}`)) === 3000, 'uloženo, záporný stav = nedoplatek, jednotka bez označení podle VS: ' + await flash(admin));
      step('Import CSV (Windows-1250, středník): web poznal kódování, oddělovač i sloupce (jednotka, VS, předpis, zůstatek); po změně přiřazení a „Zkontrolovat znovu“ náhled ukázal 2 řádky k uložení a 1 s neznámou jednotkou, která se vynechala. Částka „-1 200,50 Kč“ se uložila jako nedoplatek; řádek bez označení jednotky web přiřadil podle variabilního symbolu.');
      await admin.goto(T + '/admin/ucetnictvi'); await admin.check('[name=target][value=accounts]'); await admin.setInputFiles('#import [name=file]', csvF); await admin.click('button:has-text("Nahrát a zkontrolovat")');
      check((await flash(admin)).includes('Použili jsme přiřazení sloupců z minulého importu'), 'přiřazení si web pamatuje'); await admin.click('button[value=cancel]');
      step('Při dalším souboru se stejnými sloupci web použil přiřazení z minulého importu.');
      // Jednotky z XLSX.
      const xlsx = path.join(STORE, 'soubory', 'jednotky.xlsx');
      execFileSync('php', ['-r', `$rows = [['Jednotka', 'Vlastník', 'Podíl'], ['A/1', 'Výborová Jana', '812/10000'], ['B/7', 'Nový Jan', '1,5 %']];
        $x = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $r => $row) { $x .= '<row r="' . ($r + 1) . '">'; foreach ($row as $c => $v) { $x .= '<c r="' . chr(65 + $c) . ($r + 1) . '" t="inlineStr"><is><t>' . htmlspecialchars($v) . '</t></is></c>'; } $x .= '</row>'; }
        $z = new ZipArchive(); $z->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Jednotky" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $z->addFromString('xl/worksheets/sheet1.xml', $x . '</sheetData></worksheet>'); $z->close();`, xlsx]);
      const usersBefore = sql('SELECT COUNT(*) FROM users');
      await admin.goto(T + '/admin/ucetnictvi'); await admin.check('[name=target][value=units]'); await admin.setInputFiles('#import [name=file]', xlsx); await admin.click('button:has-text("Nahrát a zkontrolovat")');
      check((await text(admin)).includes('(z toho nových jednotek 1)'), 'XLSX: nová jednotka v náhledu');
      await admin.click('button[value=save]');
      check((await flash(admin)).includes('nově založeno 1') && sql('SELECT COUNT(*) FROM users') === usersBefore, 'jednotka založena, účty obyvatel ne');
      run("DELETE FROM units WHERE entrance = 'B' AND number = '7'");
      step('Import jednotek z XLSX: jednotka B/7 se založila nově, podíl „1,5 %“ přijat; účty obyvatel import nezaložil.');
      // Partnerský portál: všechny domy najednou.
      const pp = await (await b.newContext({ viewport: { width: 1280, height: 900 }, locale: 'cs-CZ' })).newPage(); pp.setDefaultTimeout(10000);
      await pp.goto(BASE + '/partner/prihlaseni'); await pp.fill('[name=email]', who.partner); await pp.fill('[name=password]', who.password); await pp.click('main form button[type=submit]');
      await pp.goto(BASE + '/partner/ucetnictvi');
      const pzip = zipFiles(await download(pp, () => pp.click('button:has-text("Stáhnout ZIP")')));
      check(Object.keys(pzip).some((n) => n.startsWith('dum') || n.includes('/')), 'partner: ZIP se složkou za každý dům: ' + Object.keys(pzip).join(', '));
      const multi = path.join(STORE, 'soubory', 'domy.csv');
      fs.writeFileSync(multi, `Dům;Jednotka;Předpis;Saldo\nSVJ Zahradní 12;A/3;3100;-300\nCizí dům;A/1;1;1\n`);
      await pp.setInputFiles('#import [name=file]', multi); await pp.click('button:has-text("Nahrát a zkontrolovat")');
      const mp = await text(pp);
      check(mp.includes('SVJ Zahradní 12') && mp.includes('Tyto domy jsme nepoznali') && mp.includes('Cizí dům'), 'partner: náhled za dům, nepoznaný dům vynechán: ' + mp.slice(0, 300));
      await pp.click('button[value=save]');
      check((await flash(pp)).includes('Import hotov') && sql(`SELECT balance FROM unit_accounts WHERE unit_id = ${unitId('A/3')}`) === '-300', 'partner: import za více domů uložen: ' + await flash(pp) + ' ' + sql(`SELECT balance FROM unit_accounts WHERE unit_id = ${unitId('A/3')}`));
      step('Správcovská firma v partnerském portálu stáhla export všech domů v ZIP a nahrála jeden soubor se sloupcem Dům; řádek nepoznaného domu se vynechal.');
      await pp.context().close();
    }

    // ---------- Data domu a smlouva o zpracování ----------
    begin('data-a-smlouva', '');
    {
      check(await navGroup(admin, '/admin/data-domu') === 'Správa webu', 'Data a smlouva v menu Správa webu');
      await admin.goto(T + '/admin/nastaveni');
      check(!!(await admin.$('.page-head a[href$="/admin/data-domu"]')), 'odkaz nahoře v Nastavení');
      await admin.click('.page-head a[href$="/admin/data-domu"]');
      await shot(admin.locator('main'), 'data-1-stranka.jpg', 1000);
      await admin.fill('#export-password', 'spatne-heslo'); await admin.click('#export button[type=submit]');
      check((await text(admin)).includes('Heslo není správně'), 'bez hesla export nejde');
      const zf = await download(admin, async () => { await admin.fill('#export-password', who.password); await admin.click('#export button[type=submit]'); });
      const z = zipFiles(zf);
      const data = JSON.parse(z['data.json'] || '{}');
      check(!!z['data.json'] && Object.keys(z).some((n) => n.startsWith('uploads/')) && JSON.stringify(data).includes('petr.novak@example.cz'), 'ZIP: data.json a složka uploads');
      await admin.goto(T + '/admin/zaznamy');
      check((await text(admin)).includes('Export všech dat domu'), 'stažení zapsáno v Záznamech');
      step('Data a smlouva (Správa webu, odkaz i nahoře v Nastavení): se špatným heslem export nejde, se správným se stáhl ZIP s data.json a složkou uploads; stažení je v Záznamech.');
      await admin.goto(T + '/admin/data-domu'); await admin.click('a:has-text("Zobrazit smlouvu k tisku")');
      const d = await text(admin);
      check(d.includes('Společenství vlastníků jednotek Zahradní 12') && d.includes('IČO: 04512345') && d.includes('Zahradní 12, Brno') && d.includes('Vytisknout / uložit jako PDF') && d.includes('přijetím obchodních podmínek'), 'smlouva předvyplněná IČO a adresou: ' + d.slice(0, 400));
      await shot(admin.locator('main'), 'data-2-smlouva.jpg', 900);
      step('Smlouva o zpracování je předvyplněná názvem, IČO a adresou společenství z Nastavení a má tlačítko Vytisknout / uložit jako PDF.');
      await admin.goto(T + '/admin/data-domu');
      const sec = await admin.getAttribute('a:has-text("Bezpečnost a ochrana dat")', 'href');
      await admin.goto(sec); check((await text(admin)).includes('Bezpečnost'), 'stránka Bezpečnost a ochrana dat');
      edition('free'); await admin.goto(T + '/admin/data-domu');
      const fr = await text(admin, '#export');
      check(fr.includes('jednou za 30 dní') && fr.includes('Další export bude možný'), 'edice Zdarma: export jednou za 30 dní');
      edition('premium');
      step('V edici Zdarma web po stažení napíše, kdy bude možný další export (jednou za 30 dní).');
      await owner.goto(T + '/muj-ucet'); await owner.click('a:has-text("Moje údaje a soukromí")');
      check((await text(owner)).includes('Moje údaje a soukromí'), 'vlastní údaje v Můj účet');
    }

    // ---------- Propojení s účty u dalších bank (PSD2) ----------
    begin('propojeni-banky', 'Přihlášení do internetového bankovnictví a souhlas na webu banky jsme ověřit nemohli – jde o cizí weby a skutečný účet. Místo poskytovatele bankovních dat (GoCardless) odpovídala zkušební služba podle jeho zveřejněné dokumentace a místo banky zkušební stránka, která rovnou vrátila zpět na web. Ověřeno je vše na straně webu.');
    {
      await admin.goto(T + '/admin/platby');
      await admin.click('#banka .psd2-panel a:has-text("Propojit účet u ČS, KB, ČSOB a dalších bank")');
      check((await text(admin)).includes('Propojení s bankami zatím provozovatel služby nenastavil'), 'bez nastavení u provozovatele: hláška místo seznamu bank');
      setCfg('bank', { gocardless_id: 'zkouska', gocardless_key: 'zkouska', gocardless_url: FAKE + '/gc' });
      gc.tx = [
        { transactionId: 'CS-1', bookingDate: today, transactionAmount: { amount: '3000.00', currency: 'CZK' }, debtorName: 'Tomáš Dvořák', endToEndId: '/VS4803/SS/KS0308', remittanceInformationUnstructured: 'Zaloha' },
        { transactionId: 'CS-2', bookingDate: today, transactionAmount: { amount: '1200.00', currency: 'CZK' }, debtorName: 'Eva Svobodová', remittanceInformationUnstructured: 'zaloha byt 4' },
      ];
      await admin.reload();
      check((await admin.$$eval('[name=institution] option', (o) => o.map((x) => x.textContent))).includes('Česká spořitelna'), 'seznam bank');
      await admin.selectOption('[name=institution]', 'CESKA_SPORITELNA_GIBACZPX'); await Promise.all([admin.waitForURL('https://ob.gocardless.com/**'), admin.click('button:has-text("Pokračovat do banky")')]); await Promise.all([admin.waitForURL(/\/admin\/platby(#banka)?$/), admin.click('#souhlas')]);
      const fl = await flash(admin);
      check(admin.url().includes('/admin/platby') && fl.includes('Účet u banky Česká spořitelna je propojený (1 účet). Nových plateb 2, spárováno 1.'), 'propojeno a hned stažené platby: ' + fl+ ' ' + admin.url());
      const agr = JSON.parse(fakeLog.find((x) => x.path === '/gc/agreements/enduser/').body);
      check(agr.max_historical_days === 90 && agr.access_valid_for_days === 90 && agr.access_scope.join() === 'details,transactions', 'souhlas jen pro čtení na 90 dní');
      const btx = await text(admin, '#banka');
      check(btx.includes('Poslední příchozí platby') && btx.includes('Tomáš Dvořák') && (await admin.locator('.bank-tx tr.is-unmatched', { hasText: 'Eva Svobodová' }).count()) === 1, 'spárovaná platba a nespárovaná k ručnímu přiřazení');
      step('Platby na jednotku → „Propojit účet u ČS, KB, ČSOB a dalších bank“: bez nastavení u provozovatele web jen oznámí, že propojení není nastavené. S nastavením: výběr banky, „Pokračovat do banky“, návrat z banky – web načetl účet a hned stáhl 2 platby; platba s VS 4803 se spárovala, platba bez VS zůstala v Posledních příchozích platbách k ručnímu přiřazení. Souhlas je jen pro čtení (údaje o účtu a pohyby) na 90 dní.');
      await admin.goto(T + '/admin/platby/banky');
      const card = admin.locator('section.card').first();
      check((await card.textContent()).includes('Propojeno') && (await card.textContent()).includes('123456789/0300') && (await card.textContent()).includes(czDate(plusDays(90))), 'propojení: účet a platnost souhlasu');
      await shot(card, 'banka-psd2-1-propojeno.jpg');
      for (let i = 0; i < 3; i++) { await card.locator('button:has-text("Stáhnout teď")').click(); }
      check((await flash(admin)).includes('nových plateb 0'), 'Stáhnout teď: nic dvakrát');
      await card.locator('button:has-text("Stáhnout teď")').click();
      check((await flash(admin)).includes('už dnes byl stažen 4×'), 'páté stažení za den web nepustí: ' + await flash(admin));
      check(fakeLog.filter((x) => x.path === '/gc/accounts/acc-1/transactions/').length === 4, '4 stažení za den');
      step('„Stáhnout teď“ stáhlo platby znovu bez zdvojení; po 4 staženích za den (včetně prvního) web další stažení nepustí.');
      const m0 = mailLen();
      run(`UPDATE psd2_links SET valid_until = '${plusDays(14)}'`); await daily();
      run(`UPDATE psd2_links SET valid_until = '${plusDays(3)}'`); await daily();
      check(mailsSince(m0, who.admin).filter((m) => m.subject.startsWith('Obnovte propojení s bankou')).length === 2, 'e-mail 14 a 3 dny před koncem souhlasu');
      await admin.reload();
      check((await text(admin, 'section.card')).includes('Souhlas vyprší'), 'upozornění na konec souhlasu');
      step('14 a 3 dny před koncem souhlasu přišel správcům e-mail „Obnovte propojení s bankou“.');
      const txCount = sql('SELECT COUNT(*) FROM bank_tx');
      await admin.selectOption('[name=institution]', 'CESKA_SPORITELNA_GIBACZPX'); await Promise.all([admin.waitForURL('https://ob.gocardless.com/**'), admin.click('button:has-text("Pokračovat do banky")')]); await Promise.all([admin.waitForURL(/\/admin\/platby(#banka)?$/), admin.click('#souhlas')]);
      check((await flash(admin)).includes('Nových plateb 0') && sql('SELECT COUNT(*) FROM bank_tx') === txCount, 'nové propojení platby nezdvojí');
      await admin.goto(T + '/admin/platby/banky');
      await admin.locator('section.card').nth(1).locator('button:has-text("Zrušit propojení")').click();
      check((await flash(admin)).includes('je zrušené. Už stažené platby zůstávají') && sql('SELECT COUNT(*) FROM bank_tx') === txCount && fakeLog.some((x) => x.method === 'DELETE'), 'zrušení propojení, platby zůstaly');
      step('Obnovení: banka připojená znovu – platby se nezapočítaly dvakrát; staré propojení „Zrušit propojení“ ukončilo přístup a stažené platby zůstaly.');
      await admin.goto(T + '/admin/platby');
      check(!!(await admin.$('form:has([value=bank_upload])')), 'nahrávání výpisu funguje dál');
    }

    // ---------- Katastr, insolvence a exekuce ----------
    begin('katastr-a-rejstriky', 'Stránky Nahlížení do katastru nemovitostí jsme nemohli ověřit automaticky – web katastru strojové procházení blokuje. Dálkový přístup ČÚZK (WSDP), insolvenční rejstřík ani Centrální evidenci exekucí jsme se skutečnými účty nezkoušeli: místo nich odpovídaly zkušební služby podle zveřejněné dokumentace (vzorový výpis listu vlastnictví, vzorové odpovědi). Ověřeno je vše na straně webu.');
    {
      check(await navGroup(admin, '/admin/rejstriky') === 'Dům a lidé', 'Katastr a rejstříky v menu Dům a lidé');
      edition('standard'); await admin.goto(T + '/admin/rejstriky/katastr');
      check((await text(admin)).includes('Sledování katastru je součástí edice Premium'), 'jen edice Premium'); edition('premium', true);
      await admin.reload();
      check((await text(admin)).includes('Propojení s katastrem (dálkový přístup ČÚZK) zatím provozovatel nenastavil'), 'bez nastavení u provozovatele');
      setCfg('katastr', { user: 'WSZKOUSKA', password: 'zkouska', endpoint: FAKE + '/wsdp' }); setCfg('exekuce', { api_key: 'KEY', api_secret: 'SECRET', url: FAKE + '/cee' }); setCfg('isir', { url: FAKE + '/isir' });
      await admin.reload();
      check((await text(admin, '.page-head')).includes('každých 30 dní'), 'kontrola každých 30 dní');
      await admin.fill('[name=ku]', '610372'); await admin.fill('[name=lv]', '4321'); await admin.click('form:has([value=target]) button');
      await admin.fill('[name=lv]', '4321, 4322'); await admin.click('form:has([value=target]) button');
      check((await text(admin, '.card:has(h2:has-text("Sledování"))')).includes('U Vašeho domu (2 LV) 2 480 Kč ročně'), 'dva LV: 1 490 + 990 Kč');
      await admin.fill('[name=lv]', '4321'); await admin.click('form:has([value=target]) button');
      const priceTxt = await text(admin, '.card:has(h2:has-text("Sledování"))');
      check(priceTxt.includes('Cena: 1 490 Kč za dům a rok včetně 1 listu vlastnictví, každý další LV 990 Kč ročně (bez DPH)') && priceTxt.includes('Platí se s ročním předplatným') && priceTxt.includes('poměrnou část'), 'cena za dům a rok u tlačítka: ' + priceTxt);
      await admin.check('[name=confirm]'); await admin.click('button:has-text("Zapnout sledování")');
      check((await flash(admin)).includes('Sledování je zapnuté') && (await flash(admin)).includes('Za zbytek zaplaceného období') && !!psql("SELECT id FROM payments WHERE tenant = 'dum' AND kind = 'addon' AND status = 'pending'"), 'sledování zapnuto, výzva k platbě za zbytek období: ' + await flash(admin));
      step('Změny v katastru: kód k. ú. 610372 a LV uloženy; u tlačítka je cena 1 490 Kč za dům a rok bez DPH včetně 1 LV (se dvěma LV 2 480 Kč). Po potvrzení ceny „Zapnout sledování“ vznikla výzva k platbě za zbytek zaplaceného období (dál se platí s ročním předplatným). Web kontroluje každých 30 dní. Bez nastavení u provozovatele web jen upozorní; funkce je jen v edici Premium.');
      // Insolvence osob (před kontrolou katastru – převzetí nového vlastníka pak údaje smaže).
      await admin.goto(T + '/admin/insolvence#osoby');
      check(!(await admin.$('#osoby input[name*=rodne], #osoby input[name*=rc]')), 'rodné číslo se nezadává');
      await admin.selectOption('#osoby [name=unit_id]', unitId('A/3')); await admin.fill('#osoby [name=surname]', 'Dvořák'); await admin.fill('#osoby [name=first_name]', 'Tomáš'); await admin.fill('#osoby [name=birth_date]', '1980-05-12');
      await admin.click('#osoby button:has-text("Hlídat")');
      check((await flash(admin)).includes('Osoba se bude hlídat'), 'osoba přidána');
      const m0 = mailLen();
      await admin.click('#osoby button:has-text("Zkontrolovat osoby teď")');
      check((await flash(admin)).includes('Nalezená řízení: 1') && (await text(admin, '#osoby')).includes('27 INS 1234/2026'), 'řízení nalezeno: ' + await flash(admin));
      check(mailsSince(m0, who.admin).some((m) => m.subject.startsWith('Insolvence vlastníka jednotky') && m.body.includes('dvou měsíců')), 'e-mail správcům o insolvenci');
      step('Insolvence vlastníků → Fyzické osoby: jednotka, příjmení, jméno a datum narození (rodné číslo se nezadává), „Hlídat“; kontrola našla řízení a správcům přišel e-mail s upozorněním na lhůtu dvou měsíců.');
      // Exekuce.
      run(`UPDATE units SET owner_note = 'Stavby Novák s.r.o.', owner_contact = 'IČO 27082440' WHERE id = ${unitId('B/6')}`);
      await admin.goto(T + '/admin/rejstriky/exekuce');
      const ex = await text(admin);
      check(ex.includes('Tomáš Dvořák') && ex.includes('IČO 27082440') && /99\s*Kč za dotaz/.test(ex) && ex.includes('platí se z kreditu služeb jen za dotaz, který se povede'), 'lustrovat jde osobu s datem narození i firmu s IČO, cena za dotaz');
      const ceeBefore = fakeLog.filter((x) => x.path.startsWith('/cee')).length;
      check(ceeBefore === 0, 'lustrace se nespouští sama');
      const keep = creditH(); await creditFix(-(keep - 5000) / 100, 'ověření: málo kreditu'); await admin.reload();
      check((await text(admin, 'tr:has-text("Tomáš Dvořák")')).includes('Na lustraci (99 Kč) chybí kredit') && !(await admin.$('tr:has-text("Tomáš Dvořák") button:has-text("Lustrovat")')), 'bez kreditu lustraci nejde spustit');
      await creditFix((keep - 5000) / 100, 'ověření: kredit zpět'); await admin.reload();
      const exRow = admin.locator('tr', { hasText: 'Tomáš Dvořák' });
      await exRow.locator('[name=confirm]').check(); await exRow.locator('button:has-text("Lustrovat")').click();
      check((await flash(admin)).includes('Nalezené exekuce: 1') && (await text(admin)).includes('999EX120/23'), 'lustrace: 1 exekuce');
      const search = fakeLog.find((x) => x.path === '/cee/search');
      check(search && decodeURIComponent(search.query).includes('name=Tomáš Dvořák') && search.query.includes('birth=1980-05-12'), 'dotaz podle jména a data narození');
      check(!sql("SELECT result FROM rej_exekuce ORDER BY id DESC LIMIT 1").includes('1980'), 'ukládá se jen číslo a stav kauzy');
      check(creditH() === keep - 9900, 'lustrace stála 99 Kč z kreditu služeb: ' + (keep - creditH()));
      step('Lustrace exekucí: na seznamu je osoba s datem narození i firma s IČO; cena 99 Kč bez DPH za dotaz se platí z kreditu služeb – když kredit nestačí, web lustraci vůbec nenabídne. Po zaškrtnutí ceny a „Lustrovat“ web uložil nalezenou exekuci 999EX120/23 s datem a z kreditu strhl 99 Kč. Sám se dotaz nespustil.');
      // Katastr: kontrola a převzetí.
      await admin.goto(T + '/admin/rejstriky/katastr');
      const m1 = mailLen();
      await admin.click('button[value=run]');
      check(/Nových změn: \d+/.test(await flash(admin)), 'kontrola katastru: ' + await flash(admin));
      check(mailsSince(m1, who.admin).some((m) => m.subject.startsWith('Změny v katastru')), 'e-mail o změnách');
      const ch = await text(admin, 'section.card:has(h2:has-text("Zjištěné změny"))');
      check(ch.includes('Nový vlastník') && ch.includes('Malý Ondřej') && ch.includes('Probíhající řízení (plomba)'), 'změny: nový vlastník a plomba: ' + ch.slice(0, 500));
      await shot(admin.locator('section.card:has(h2:has-text("Zjištěné změny"))'), 'rejstriky-1-katastr.jpg', 1000);
      step('„Zkontrolovat teď“: web stáhl výpis LV a napoprvé ho porovnal s evidencí jednotek – ukázal nového vlastníka jednotky 12A/3 a probíhající řízení (plombu); správcům přišel e-mail.');
      const personId = sql("SELECT id FROM rej_persons WHERE surname = 'Dvořák'");
      await admin.locator('tr', { hasText: 'Malý Ondřej' }).locator('button[value=apply]').click();
      check((await flash(admin)).includes('Údaje bývalého vlastníka pro hlídání insolvence se smazaly') && sql(`SELECT owner_note FROM units WHERE id = ${unitId('A/3')}`) === 'Malý Ondřej'
        && sql(`SELECT COUNT(*) FROM rej_persons WHERE id = ${personId}`) === '0' && sql(`SELECT COUNT(*) FROM rej_exekuce WHERE person_id = ${personId}`) === '0', 'převzetí: vlastník přepsán, údaje bývalého vlastníka smazány');
      await admin.locator('tr', { hasText: 'Probíhající řízení (plomba)' }).first().locator('button[value=dismiss]').click();
      check((await flash(admin)).includes('Vzato na vědomí'), 'plomba vzata na vědomí');
      step('„Převzít do evidence“ přepsalo vlastníka jednotky 12A/3 a smazalo údaje bývalého vlastníka (hlídání insolvence i výsledek lustrace); plombu jsme „vzali na vědomí“.');
      const ownerSees = await owner.goto(T + '/admin/insolvence');
      check(!(await text(owner)).includes('Fyzické osoby'), 'obyvatel údaje nevidí');
      await admin.goto(T + '/admin/insolvence#osoby');
      await admin.selectOption('#osoby [name=unit_id]', unitId('B/2')); await admin.fill('#osoby [name=surname]', 'Veselá'); await admin.fill('#osoby [name=first_name]', 'Alena'); await admin.fill('#osoby [name=birth_date]', '1975-03-01');
      await admin.click('#osoby button:has-text("Hlídat")'); await admin.click('#osoby button:has-text("Zkontrolovat osoby teď")');
      await admin.locator('#osoby tr', { hasText: 'Veselá' }).locator('button:has-text("Nehlídat a smazat")').click();
      check((await flash(admin)).includes('údaje jsou smazané') && sql("SELECT COUNT(*) FROM rej_persons WHERE surname = 'Veselá'") === '0', 'Nehlídat a smazat');
      step('Údaje vidí jen správci (obyvatel stránku neotevře); „Nehlídat a smazat“ osobu i její výsledky smazalo.');
    }

    // ---------- Přihlášení přes Google, Microsoft, Apple a Facebook ----------
    begin('prihlaseni-sso', 'Přihlášení na stránkách Googlu, Microsoftu, Applu a Facebooku jsme ověřit nemohli – jsou to cizí weby a ověřovací běh u nich nemá účty; místo nich se otevřela prázdná zkušební stránka. Ověřena je strana webu: tlačítka, přesměrování k poskytovateli se zabezpečením (state, PKCE), zrušené přihlášení, odmítnutí odkazu z cizího prohlížeče, zrušení propojení a Záznamy. Výměnu kódu za identitu, vyhledání účtu podle ověřeného e-mailu a dokončení propojení pokrývají unit testy se vzorovými odpověďmi poskytovatelů.');
    {
      execFileSync('openssl', ['ecparam', '-name', 'prime256v1', '-genkey', '-noout', '-out', path.join(STORE, 'apple.p8')], { stdio: 'ignore' });
      setCfg('sso', { google: { client_id: 'zkouska.apps.googleusercontent.com', client_secret: 'x' }, microsoft: { client_id: '00000000-0000-0000-0000-000000000001', client_secret: 'x' },
        apple: { client_id: 'cz.spolecnyweb.zkouska', team_id: 'TEAM', key_id: 'KEY', private_key_path: path.join(STORE, 'apple.p8') }, facebook: { app_id: '123', app_secret: 'x' } });
      const anon = async () => { const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, locale: 'cs-CZ' }); await offline(ctx); const p = await ctx.newPage(); p.setDefaultTimeout(10000); return p; };
      const lp = await anon(); await lp.goto(T + '/prihlaseni');
      const btns = await lp.$$eval('.sso-btn', (x) => x.map((y) => y.textContent.trim()));
      check(btns.join('|') === 'Přihlásit přes Google|Přihlásit přes Microsoft|Přihlásit přes Apple|Přihlásit přes Facebook', 'tlačítka pod formulářem: ' + btns);
      await shot(lp.locator('main form').first().locator('xpath=..'), 'sso-1-prihlaseni.jpg', 1600);
      // Přesměrování k poskytovateli: adresu zachytíme z požadavku prohlížeče (cizí web se neotevře – prohlížeč ho nenajde).
      const leave = async (page, re, click) => { const [r] = await Promise.all([page.waitForRequest(re), click()]); await page.waitForLoadState().catch(() => {}); return new URL(r.url()); };
      const au = await leave(lp, /^https:\/\/accounts\.google\.com\//, () => lp.click('.sso-google'));
      check(au.host === 'accounts.google.com' && au.searchParams.get('code_challenge_method') === 'S256' && !!au.searchParams.get('state') && au.searchParams.get('redirect_uri') === BASE + '/sso/callback/google', 'přesměrování na Google se state a PKCE: ' + au.href.slice(0, 120));
      step('Na stránce Přihlášení jsou pod formulářem tlačítka Google, Microsoft, Apple a Facebook; tlačítko vede na stránku poskytovatele s ochranou state a PKCE (S256).');
      await lp.goto(`${au.searchParams.get('redirect_uri')}?state=${encodeURIComponent(au.searchParams.get('state'))}&error=access_denied`);
      check((await flash(lp)).includes('Přihlášení přes Google jste zrušili') && lp.url().startsWith(T + '/prihlaseni'), 'zrušené přihlášení: zpráva na webu domu');
      // Cizí prohlížeč: odkaz pro dokončení přihlášení nikoho nepřihlásí.
      const au2 = await leave(lp, /^https:\/\/login\.microsoftonline\.com\//, () => lp.click('.sso-microsoft'));
      const other = await anon();
      await other.goto(`${au2.searchParams.get('redirect_uri')}?state=${encodeURIComponent(au2.searchParams.get('state'))}&code=x&error=access_denied`);
      check((await flash(other)).includes('platí 2 minuty a jen v prohlížeči, ve kterém jste přihlášení začali'), 'odkaz z cizího prohlížeče nikoho nepřihlásí: ' + await flash(other));
      step('Zrušené přihlášení u poskytovatele: web napsal proč („Přihlášení přes Google jste zrušili“). Dokončení otevřené v jiném prohlížeči nikoho nepřihlásilo – web vysvětlil, že platí 2 minuty a jen v prohlížeči, kde přihlášení začalo.');
      await lp.context().close(); await other.context().close();
      // Můj účet: propojení.
      await owner.goto(T + '/muj-ucet#sso');
      const box = await text(owner, '#sso');
      check(box.includes('Přihlášení přes další účty') && (await owner.locator('#sso button:has-text("Propojit účet")').count()) === 4, 'Můj účet: 4× Propojit účet');
      const mu = await leave(owner, /^https:\/\/login\.microsoftonline\.com\//, () => owner.locator('#sso li', { hasText: 'Microsoft' }).locator('button:has-text("Propojit účet")').click());
      check(mu.host === 'login.microsoftonline.com' && mu.pathname.startsWith('/common/') && !!mu.searchParams.get('code_challenge'), 'Propojit účet vede k přihlášení u Microsoftu');
      // Dokončené propojení (vzniká až po přihlášení u poskytovatele – to běh nemá) zapíšeme přímo, ať jde ověřit zobrazení a zrušení.
      run(`INSERT INTO user_sso (user_id, provider, subject, email, linked_at) VALUES ((SELECT id FROM users WHERE email = '${who.owner}'), 'apple', '001234.zkouska', 'x7k2p@privaterelay.appleid.com', datetime('now'))`);
      await owner.goto(T + '/muj-ucet#sso');
      const apple = owner.locator('#sso li', { hasText: 'Apple' });
      check((await apple.textContent()).includes('Propojeno') && (await apple.textContent()).includes('privaterelay.appleid.com'), 'propojený účet: Propojeno a e-mail od poskytovatele');
      await shot(owner.locator('#sso'), 'sso-2-ucet.jpg', 1400);
      await apple.locator('button:has-text("Zrušit propojení")').click();
      check((await flash(owner)).includes('Propojení s účtem Apple je zrušené') && sql(`SELECT COUNT(*) FROM user_sso`) === '0', 'zrušení propojení');
      step('Můj účet → Přihlášení přes další účty: u každého poskytovatele „Propojit účet“ (vede na jeho přihlášení); propojený účet má „Propojeno“ s e-mailem od poskytovatele a „Zrušit propojení“ ho zrušilo.');
      await admin.goto(T + '/admin/zaznamy');
      const zz = await text(admin);
      check(zz.includes('Neúspěšné přihlášení přes další účet') && zz.includes('Zrušení propojení s dalším účtem'), 'Záznamy: přihlášení a propojení přes poskytovatele');
      step('V Záznamech správce vidí neúspěšné přihlášení přes Google i zrušení propojení (kdo, kdy, poskytovatel).');
    }

    // @@DALSI@@
    BETAS.forEach((k) => pset('beta_' + k, 'off'));

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
    // ---------- Snímek pro stránku Pro správce domů (assets/landing) – až na konci: založí ukázkový web a ukázkovou správcovskou firmu ----------
    await s.goto(BASE + '/sprava'); s.once('dialog', (d) => d.accept());
    await Promise.all([s.waitForNavigation(), s.click('form[action$="/demo"] button')]);
    const prt = await (await b.newContext({ viewport: { width: 1280, height: 800 }, locale: 'cs-CZ', colorScheme: 'light' })).newPage();
    await prt.goto(BASE + '/partner/ukazka');
    await prt.evaluate(() => document.querySelector('.demo-bar')?.remove());
    await prt.screenshot({ path: path.join(ROOT, 'assets/landing/partner-prehled.jpg'), type: 'jpeg', quality: 74 });
  } catch (e) {
    failures.push('přerušeno: ' + e.message.split('\n')[0]);
  } finally {
    await b.close(); server.kill(); tls.close(); if (fake) fake.close(); fs.rmSync(STORE, { recursive: true, force: true });
  }
  for (const [slug, r] of Object.entries(results)) console.log(`${slug}: ${r.checks} kontrol\n  - ${r.steps.join('\n  - ')}`);
  if (failures.length) { console.log('\nSELHALO – návody neplatí:\n' + failures.join('\n')); process.exit(1); }
  fs.writeFileSync(path.join(OUT, 'overeno.json'), JSON.stringify(results, null, 2) + '\n');
  console.log('\nVše ověřeno, snímky a overeno.json uloženy v assets/help/.');
})();
