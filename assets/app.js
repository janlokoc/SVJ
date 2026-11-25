document.documentElement.classList.add('js');

// Mobilní menu
const toggle = document.querySelector('.nav-toggle');
const nav = document.querySelector('.site-nav');
if (toggle && nav) {
  // Otevřené menu v telefonu se posouvá samo a stránka pod ním stojí (jinak by tah prstem nejdřív posouval pozadí).
  const narrow = window.matchMedia('(max-width: 1100px)');
  const lock = () => {
    const on = nav.classList.contains('is-open') && narrow.matches;
    document.documentElement.classList.toggle('nav-lock', on);
    const hdr = nav.closest('.site-header') || nav.parentElement;
    nav.style.maxHeight = on ? Math.max(200, window.innerHeight - (nav.getBoundingClientRect().top - hdr.getBoundingClientRect().top)) + 'px' : '';
  };
  window.addEventListener('resize', lock);
  narrow.addEventListener('change', lock);
  toggle.addEventListener('click', () => {
    const open = nav.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded', String(open));
    lock();
  });
  // Rozbalená podnabídka (Pro obyvatele) se v menu hned ukáže celá.
  nav.querySelectorAll('.nav-more details').forEach((d) => d.addEventListener('toggle', () => {
    if (!d.open || !narrow.matches) return;
    // posunout jen samotné menu, ne stránku: rozbalená část dole, nadpis podnabídky ale nesmí zmizet nahoře
    requestAnimationFrame(() => { const n = nav.getBoundingClientRect(), r = d.getBoundingClientRect(); const by = Math.min(r.bottom - n.bottom, r.top - n.top); if (by > 0) nav.scrollBy({ top: by, behavior: 'smooth' }); });
  }));
}

// Potvrzení před nevratnými akcemi (mazání, reset hesla)
document.addEventListener('submit', (e) => {
  let msg = e.target.dataset.confirm;
  // Hromadný e-mail: před odesláním zopakuj, komu zpráva půjde
  if (e.submitter && e.submitter.hasAttribute('data-confirm-audience')) {
    const sel = e.target.querySelector('[name=audience]');
    msg = 'Opravdu odeslat zprávu? Příjemci: ' + sel.options[sel.selectedIndex].text + '. Odeslání nelze vzít zpět.';
  }
  if (msg && !window.confirm(msg)) e.preventDefault();
});

// Filtrování dlouhého seznamu dokumentů při výběru příloh
document.querySelectorAll('input[data-filter]').forEach((input) => {
  const box = document.querySelector(input.dataset.filter);
  if (!box) return;
  const norm = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  input.addEventListener('input', () => {
    const q = norm(input.value.trim());
    box.querySelectorAll(input.dataset.filterRows || 'label').forEach((l) => { l.hidden = q !== '' && !norm(l.textContent).includes(q); });
  });
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
});

// Ukazatel přihlášené účasti (šířky se nastavují skriptem kvůli bezpečnostním pravidlům stránky)
document.querySelectorAll('.meter-fill[data-width]').forEach((el) => { el.style.width = el.dataset.width + '%'; });
document.querySelectorAll('.meter-mark[data-left]').forEach((el) => { el.style.left = el.dataset.left + '%'; });

// Kontrola v administraci: vnitřní soubory webu nesmí být dostupné přímo z internetu
const probe = document.getElementById('storage-probe');
if (probe) {
  Promise.all(JSON.parse(probe.dataset.probe).map((u) => fetch(u, { credentials: 'omit', cache: 'no-store' }).then((r) => r.status === 200, () => false)))
    .then((hits) => { if (hits.some(Boolean)) probe.hidden = false; });
}

// Tisk plné moci a prezenční listiny
document.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));
// Rozbalovací nabídka „Pro obyvatele“ se zavře kliknutím mimo ni
document.addEventListener('click', (e) => { document.querySelectorAll('.nav-more details[open]').forEach((d) => { if (!d.contains(e.target)) d.open = false; }); });

// Registrace: adresa webu se navrhne z názvu společenství a průběžně se ověřuje, zda je volná
document.querySelectorAll('input[data-slug-check]').forEach((slug) => {
  const name = document.querySelector(slug.dataset.slugFrom);
  const status = document.querySelector(slug.dataset.slugStatus);
  const hint = status ? status.textContent : '';
  let own = slug.value !== '';
  let timer;
  const slugify = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
    .replace(/^(svj|spolecenstvi-vlastniku(-jednotek)?)-(?=.{2})/, '').replace(/^(.{30}).+$/, (m, head) => (m[30] === '-' ? head : head.replace(/-[^-]*$/, '')).replace(/-[a-z]$/, '')).replace(/-+$/, '');
  const check = () => {
    clearTimeout(timer);
    if (!status) return;
    status.className = 'hint';
    if (slug.value.length < 2) { status.textContent = hint; return; }
    timer = setTimeout(() => {
      const asked = slug.value;
      fetch(slug.dataset.slugCheck + '?adresa=' + encodeURIComponent(asked), { headers: { Accept: 'application/json' } }).then((r) => r.json()).then((d) => {
        if (asked !== slug.value) return;
        status.textContent = d.message;
        status.className = 'hint ' + (d.ok ? 'is-ok' : 'is-bad');
      }).catch(() => {});
    }, 300);
  };
  const fixed = (slug.dataset.slugFixed || '').toLowerCase();
  const clean = (v) => { v = v.toLowerCase().replace(/^\s+/, '').replace(/^https?:\/\//, ''); if (fixed) v = v.split(fixed).map((part, i) => (i ? part.replace(/^:\d+/, '') : part)).join(''); return v.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[\s_.\/]+/g, '-').replace(/[^a-z0-9-]/g, '').replace(/-{2,}/g, '-').replace(/^-+/, '').slice(0, 39); };
  slug.addEventListener('input', () => { const c = clean(slug.value); if (c !== slug.value) slug.value = c; own = slug.value !== ''; check(); });
  if (name) name.addEventListener('input', () => { if (!own) { slug.value = slugify(name.value); check(); } });
  if (slug.value) check();
});

// Hlášení problému nebo nápadu: okno přímo na stránce, s adresou stránky a volitelným snímkem obrazovky
(() => {
  const dlg = document.getElementById('report-dialog');
  document.querySelectorAll('.report-form').forEach((form) => {
    const set = (sel, v) => { const el = form.querySelector(sel); if (el && (!el.value || dlg && dlg.contains(form))) el.value = v; };
    const fill = () => { if (!dlg || !dlg.contains(form)) return; set('[data-report-url]', location.href); set('[data-report-title]', document.title); set('[data-report-viewport]', innerWidth + '×' + innerHeight); };
    const file = form.querySelector('[data-report-file]');
    const preview = form.querySelector('[data-report-preview]');
    const capture = form.querySelector('[data-report-capture]');
    const clear = () => { file.value = ''; preview.hidden = true; const c = preview.querySelector('canvas'); if (c) c.remove(); };
    form.querySelector('[data-report-clear]').addEventListener('click', clear);
    // Přiložený obrázek (typicky snímek obrazovky z telefonu, 2–5 MB) se před odesláním zmenší na JPEG do 1600 px –
    // odeslání přes mobilní data pak trvá sekundy, ne desítky sekund.
    let shrinking = false;
    file.addEventListener('change', async () => {
      const c = preview.querySelector('canvas'); if (c) c.remove(); preview.hidden = !file.files.length;
      const f = file.files[0];
      if (shrinking || !f || !/^image\/(png|jpeg)$/.test(f.type) || typeof DataTransfer !== 'function') return;
      try {
        // createImageBitmap nepodléhá pravidlům CSP pro obrázky (blob: adresy jsou zakázané); jinak data: adresa.
        const img = window.createImageBitmap ? await createImageBitmap(f)
          : await new Promise((ok, ko) => { const rd = new FileReader(); rd.onload = () => { const i = new Image(); i.onload = () => ok(i); i.onerror = ko; i.src = rd.result; }; rd.onerror = ko; rd.readAsDataURL(f); });
        const w = img.naturalWidth || img.width, h = img.naturalHeight || img.height;
        const scale = Math.min(1, 1600 / Math.max(w, h));
        if (scale === 1 && f.size < 400 * 1024) return;
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(w * scale); canvas.height = Math.round(h * scale);
        const ctx = canvas.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height); ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((r) => canvas.toBlob(r, 'image/jpeg', 0.82));
        if (!blob || blob.size >= f.size) return;
        const dt = new DataTransfer(); dt.items.add(new File([blob], (f.name.replace(/\.\w+$/, '') || 'snimek') + '.jpg', { type: 'image/jpeg' }));
        shrinking = true; file.files = dt.files; shrinking = false;
      } catch (e) { /* původní soubor zůstane */ }
    });
    // Okno hlášení odesílá na pozadí: stránka s rozdělanou prací zůstane, jak byla. Bez skriptu klasické odeslání.
    const btn = form.querySelector('button[type=submit]');
    const status = document.createElement('div'); status.className = 'report-status'; status.setAttribute('role', 'status'); status.hidden = true;
    form.prepend(status);
    const say = (html, bad) => { status.hidden = false; status.className = 'report-status flash ' + (bad ? 'flash-error' : 'flash-ok'); status.innerHTML = html; };
    const esc = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    form.addEventListener('submit', async (e) => {
      if (!dlg || !dlg.contains(form) || !window.fetch || !window.FormData) {
        if (btn) setTimeout(() => { btn.disabled = true; btn.textContent = 'Odesílám…'; }, 0);
        return;
      }
      e.preventDefault(); fill();
      const label = btn ? btn.textContent : ''; if (btn) { btn.disabled = true; btn.textContent = 'Odesílám…'; }
      status.hidden = true;
      try {
        const fd = new FormData(form); fd.append('ajax', '1');
        const r = await fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const d = await r.json();
        if (d.ok) {
          form.reset(); clear();
          say(esc(d.message) + (form.dataset.mine ? ' <a href="' + esc(form.dataset.mine) + '">Moje hlášení</a>' : ''));
          if (btn) { btn.textContent = 'Odeslat další'; }
        } else {
          say((d.errors || ['Hlášení se nepodařilo odeslat.']).map(esc).join('<br>'), true);
          if (btn) btn.textContent = label;
        }
      } catch (err) {
        say('Hlášení se nepodařilo odeslat – zkontrolujte připojení a zkuste to znovu. Text zůstal ve formuláři.', true);
        if (btn) btn.textContent = label;
      }
      if (btn) btn.disabled = false;
      status.scrollIntoView({ block: 'nearest' });
    });
    const close = form.querySelector('[data-report-close]');
    if (close) close.addEventListener('click', () => dlg.close());
    form.addEventListener('submit', fill);
    // Snímek pořídí prohlížeč sám (zeptá se, co smí zachytit); v prohlížečích, které to neumějí, zůstane jen přiložení obrázku.
    if (capture && dlg && dlg.contains(form) && navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia && typeof DataTransfer === 'function') {
      capture.hidden = false;
      capture.addEventListener('click', async () => {
        dlg.close();
        let stream;
        try {
          stream = await navigator.mediaDevices.getDisplayMedia({ video: { displaySurface: 'browser' }, audio: false, preferCurrentTab: true });
          const video = document.createElement('video');
          video.srcObject = stream; video.muted = true;
          await video.play();
          await new Promise((r) => setTimeout(r, 400));
          const scale = Math.min(1, 1600 / video.videoWidth);
          const canvas = document.createElement('canvas');
          canvas.width = Math.round(video.videoWidth * scale); canvas.height = Math.round(video.videoHeight * scale);
          canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
          const blob = await new Promise((r) => canvas.toBlob(r, 'image/jpeg', 0.85));
          const dt = new DataTransfer();
          dt.items.add(new File([blob], 'snimek.jpg', { type: 'image/jpeg' }));
          file.files = dt.files;
          const old = preview.querySelector('canvas'); if (old) old.remove();
          preview.appendChild(canvas); preview.hidden = false;
        } catch (e) { /* uživatel snímek odmítl – hlášení jde odeslat i bez něj */ }
        if (stream) stream.getTracks().forEach((t) => t.stop());
        dlg.showModal();
      });
    }
    form._fill = fill;
  });
  if (!dlg || typeof dlg.showModal !== 'function') return;
  document.querySelectorAll('[data-report-open]').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault();
    const form = dlg.querySelector('.report-form');
    form._fill();
    dlg.showModal();
    form.querySelector('textarea').focus();
  }));
})();

// Menu administrace na úzké obrazovce
(() => {
  const btn = document.querySelector('.admin-toggle');
  if (!btn) return;
  btn.addEventListener('click', () => { btn.setAttribute('aria-expanded', String(btn.parentElement.classList.toggle('is-open'))); });
})();

// Levé menu administrace: výška vždy jen do spodního okraje okna, aby šlo posunout samo až na konec,
// i když nad ním je lišta (ukázka, podpora) nebo je obsah vpravo krátký.
(() => {
  const menu = document.querySelector('.admin-menu');
  if (!menu) return;
  let queued = false;
  const fit = () => {
    queued = false;
    if (getComputedStyle(menu).position !== 'sticky') { menu.style.maxHeight = ''; return; }
    const top = Math.max(0, menu.getBoundingClientRect().top);
    menu.style.maxHeight = Math.max(160, window.innerHeight - top) + 'px';
  };
  const queue = () => { if (!queued) { queued = true; requestAnimationFrame(fit); } };
  ['scroll', 'resize', 'orientationchange'].forEach((ev) => window.addEventListener(ev, queue, { passive: true }));
  fit();
  // Aktivní položku hned po načtení ukázat, i když je v menu hodně dole.
  const current = menu.querySelector('[aria-current]');
  if (current && current.offsetTop > menu.clientHeight - 40) menu.scrollTop = current.offsetTop - menu.clientHeight / 2;
})();

// Výběr vzhledu: při změně barev se náhledy šablon přebarví hned
document.querySelectorAll('.look-picker input[type=radio]').forEach((radio) => radio.addEventListener('change', () => {
  radio.form.querySelectorAll('.look-cards .mini').forEach((m) => { m.dataset.look = radio.value; });
}));

// AI pomocník: pošle text z pole na server a výsledek do pole vloží; původní text jde vrátit
document.querySelectorAll('.ai-box').forEach((box) => {
  const form = box.closest('form');
  const field = form && form.querySelector(box.dataset.aiTarget);
  const status = box.querySelector('.ai-status');
  const undo = box.querySelector('[data-ai-undo]');
  if (!field) return;
  // Výsledek se vkládá buď zpět do pole, nebo do jiného místa na stránce (zápis ze shromáždění).
  const out = box.dataset.aiOutput ? document.querySelector(box.dataset.aiOutput) : null;
  const read = () => (out ? out.innerText : field.value);
  const write = (v) => { if (out) out.innerText = v; else { field.value = v; field.dispatchEvent(new Event('input', { bubbles: true })); } };
  let before = null;
  undo.addEventListener('click', () => { if (before !== null) { write(before); before = null; undo.hidden = true; status.textContent = ''; } });
  box.querySelectorAll('[data-ai-task]').forEach((btn) => btn.addEventListener('click', async () => {
    const buttons = box.querySelectorAll('button');
    buttons.forEach((b) => { b.disabled = true; });
    status.className = 'ai-status';
    status.textContent = 'Pomocník píše… může to trvat až minutu.';
    // Kontext z dalších polí formuláře (např. název, datum a místo termínu), jak je správce právě vyplnil.
    let context = box.dataset.aiContext || '';
    if (box.dataset.aiFields) {
      try {
        Object.entries(JSON.parse(box.dataset.aiFields)).forEach(([name, label]) => { const el = form.querySelector('[name="' + name + '"]'); if (el && el.value.trim()) context += (context ? '\n' : '') + label + ': ' + el.value.trim(); });
      } catch (e) { /* bez kontextu */ }
    }
    const body = new URLSearchParams({ _csrf: form.querySelector('[name=_csrf]').value, task: btn.dataset.aiTask, text: field.value, context });
    try {
      const r = await fetch(box.dataset.aiUrl, { method: 'POST', body, headers: { Accept: 'application/json' } });
      let d;
      try { d = await r.json(); } catch (e) {
        // Server nevrátil JSON – typicky vypršel čas na hostingu (504) nebo chyba serveru (500).
        d = { error: r.status === 504 || r.status === 502 ? 'Pomocník nestihl odpovědět v časovém limitu serveru. Zkuste to prosím znovu, případně s kratším textem.'
          : r.status === 403 || r.status === 419 ? 'Přihlášení vypršelo. Obnovte stránku (text si předtím zkopírujte) a zkuste to znovu.'
          : 'Server vrátil chybu (' + r.status + '). Zkuste to prosím znovu za chvíli.' };
      }
      if (d.text) {
        before = read(); write(d.text); undo.hidden = false;
        status.textContent = d.truncated ? 'Hotovo, ale text byl příliš dlouhý a je uříznutý. Zkontrolujte konec.' : 'Hotovo. Text si přečtěte a upravte, než ho uložíte.';
      } else { status.className = 'ai-status is-bad'; status.textContent = d.error || 'Nepodařilo se.'; }
    } catch (e) { status.className = 'ai-status is-bad'; status.textContent = 'Nepodařilo se spojit se serverem.'; }
    buttons.forEach((b) => { b.disabled = false; });
  }));
});

// Přepis ze schůze: soubor se pošle na server, ten z něj vytáhne text a vloží ho do pole s poznámkami
document.querySelectorAll('[data-transcript-url]').forEach((input) => input.addEventListener('change', async () => {
  const form = input.closest('form');
  const field = form.querySelector(input.dataset.transcriptTarget);
  const status = form.querySelector('[data-transcript-status]');
  if (!input.files[0]) return;
  if (field.value.trim() && !confirm('Pole s poznámkami už obsahuje text. Nahradit ho přepisem ze souboru?')) { input.value = ''; return; }
  const body = new FormData();
  body.append('_csrf', form.querySelector('[name=_csrf]').value);
  body.append('file', input.files[0]);
  status.className = 'hint';
  status.textContent = 'Načítáme přepis…';
  try {
    const r = await fetch(input.dataset.transcriptUrl, { method: 'POST', body, headers: { Accept: 'application/json' } });
    const d = await r.json();
    if (d.text) {
      field.value = d.text;
      status.textContent = d.note || ('Přepis je načtený (' + d.text.length.toLocaleString('cs-CZ') + ' znaků). Teď klikněte na „Napsat zápis z poznámek“.');
    } else { status.className = 'hint is-bad'; status.textContent = d.error || 'Soubor se nepodařilo načíst.'; }
  } catch (e) { status.className = 'hint is-bad'; status.textContent = 'Soubor se nepodařilo načíst – možná je příliš velký.'; }
  input.value = '';
}));

// Web otevřený z plochy jako aplikace nemá tlačítko Zpět: dokumenty se proto otevírají na stránce s náhledem uvnitř webu.
const standalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
if (standalone) {
  document.querySelectorAll('a[href*="/soubor/"]').forEach((a) => {
    if (a.hasAttribute('download') || a.href.includes('nahled=') || a.href.includes('stahnout=')) return;
    a.href += (a.href.includes('?') ? '&' : '?') + 'nahled=1';
    a.removeAttribute('target');
  });
  // Odkazy mimo web by v aplikaci z plochy také neměly cestu zpět – otevřou se v prohlížeči.
  document.querySelectorAll('a[href^="http"]').forEach((a) => { if (a.host !== window.location.host) { a.target = '_blank'; a.rel = 'noopener'; } });
}
document.querySelectorAll('[data-back]').forEach((a) => a.addEventListener('click', (e) => {
  if (window.history.length > 1 && document.referrer.startsWith(window.location.origin)) { e.preventDefault(); window.history.back(); }
}));

// Zprávy: stránka rozhovoru se sama ptá na nové zprávy a po načtení sjede na konec
const chat = document.querySelector('#chat[data-chat-url]');
if (chat) {
  const bottom = document.querySelector('#konec');
  if (bottom && !window.location.hash) bottom.scrollIntoView({ block: 'end' });
  const poll = async () => {
    if (document.hidden) return;
    try {
      const r = await fetch(chat.dataset.chatUrl + '?po=' + chat.dataset.chatLast, { headers: { Accept: 'application/json' } });
      if (!r.ok) return;
      const d = await r.json();
      (d.messages || []).forEach((m) => {
        if (chat.querySelector('[data-id="' + m.id + '"]')) return;
        const empty = chat.querySelector('.chat-empty'); if (empty) empty.remove();
        const li = document.createElement('li');
        li.className = 'chat-msg' + (m.mine ? ' is-mine' : ''); li.dataset.id = m.id;
        const bubble = document.createElement('div'); bubble.className = 'chat-bubble'; bubble.innerHTML = m.html;   // text je na serveru převedený na bezpečné HTML
        const small = document.createElement('small'); small.textContent = m.time;
        li.append(bubble, small); chat.append(li);
        chat.dataset.chatLast = m.id;
        li.scrollIntoView({ block: 'end', behavior: 'smooth' });
      });
    } catch (e) { /* další pokus za chvíli */ }
  };
  window.setInterval(poll, 8000);
  const area = document.querySelector('.chat-form textarea');
  if (area) area.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) area.form.requestSubmit(); });
}
// Hledání v seznamu lidí
document.querySelectorAll('[data-filter-list]').forEach((input) => input.addEventListener('input', () => {
  const needle = input.value.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  document.querySelectorAll(input.dataset.filterList + ' li').forEach((li) => {
    li.hidden = needle !== '' && !li.textContent.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').includes(needle);
  });
}));

// Fotografie z telefonu bývají větší, než server dovolí nahrát: před odesláním se zmenší na rozumnou velikost.
document.querySelectorAll('input[type=file][data-shrink]').forEach((input) => input.addEventListener('change', async () => {
  if (typeof DataTransfer !== 'function' || !input.files.length) return;
  const dt = new DataTransfer();
  for (const file of input.files) {
    if (!/^image\/(jpeg|png)$/.test(file.type) || file.size < 900000) { dt.items.add(file); continue; }
    try {
      const img = await createImageBitmap(file);
      const scale = Math.min(1, 1800 / Math.max(img.width, img.height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(img.width * scale); canvas.height = Math.round(img.height * scale);
      canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
      const blob = await new Promise((r) => canvas.toBlob(r, 'image/jpeg', 0.82));
      dt.items.add(blob && blob.size < file.size ? new File([blob], file.name.replace(/\.(png|jpe?g)$/i, '') + '.jpg', { type: 'image/jpeg' }) : file);
    } catch (e) { dt.items.add(file); }
  }
  input.files = dt.files;
}));

// Úvodní stránka: prvky při posouvání „přilétnou“. Schovají se jen ty, které jsou při načtení pod okrajem okna,
// takže bez JavaScriptu i při omezeném pohybu je vše normálně vidět.
(() => {
  const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const items = [];
  document.querySelectorAll('[data-reveal-group]').forEach((group) => {
    [...group.children].forEach((child, i) => { child.dataset.reveal = group.dataset.revealGroup || 'up'; child.dataset.revealDelay = Math.min(i, 6); items.push(child); });
  });
  document.querySelectorAll('[data-reveal]').forEach((el) => { if (!items.includes(el)) items.push(el); });
  if (!items.length || still || !('IntersectionObserver' in window)) return;
  const io = new IntersectionObserver((entries) => entries.forEach((en) => { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } }), { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });
  items.forEach((el) => { if (el.getBoundingClientRect().top > window.innerHeight * 0.92) { el.classList.add('will-reveal'); io.observe(el); } });
})();
// Rozbalení podrobností (limity edice) s plynulým otevřením
document.querySelectorAll('[data-collapse-toggle]').forEach((btn) => btn.addEventListener('click', () => {
  const open = btn.getAttribute('aria-expanded') !== 'true';
  // Karty vedle sebe (edice) se rozbalují společně, aby šly limity porovnat a karty zůstaly stejně vysoké.
  const group = btn.closest('.editions');
  (group ? group.querySelectorAll('[data-collapse-toggle]') : [btn]).forEach((b) => {
    b.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.getElementById(b.getAttribute('aria-controls')).classList.toggle('is-open', open);
    b.querySelector('[data-more]').hidden = open; b.querySelector('[data-less]').hidden = !open;
  });
}));

// Zkopírování odkazu do schránky a označení celého textu při kliknutí do pole
document.querySelectorAll('[data-copy]').forEach((btn) => btn.addEventListener('click', async () => {
  const old = btn.textContent;
  try { await navigator.clipboard.writeText(btn.dataset.copy); btn.textContent = 'Zkopírováno'; }
  catch (e) { const f = btn.closest('.card, form, div').querySelector('[data-select-all]'); if (f) { f.focus(); f.select(); } btn.textContent = 'Označeno – zkopírujte'; }
  window.setTimeout(() => { btn.textContent = old; }, 2500);
}));
document.querySelectorAll('[data-select-all]').forEach((f) => f.addEventListener('focus', () => f.select()));


// Prezence na shromáždění: přepnutí přítomnosti se hned uloží a přepočítá podíly
document.querySelectorAll('.presence-form').forEach((form) => {
  const row = form.closest('tr');
  const proxy = form.querySelector('.presence-proxy');
  const state = form.querySelector('.presence-state');
  const save = async () => {
    const mode = (form.querySelector('input[name=mode]:checked') || {}).value || '';
    row.dataset.mode = mode;
    row.classList.toggle('is-present', mode !== '');
    proxy.hidden = mode !== 'proxy';
    const data = new FormData(form); data.append('ajax', '1');
    state.textContent = 'ukládám…';
    try {
      const res = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' });
      const s = await res.json();
      const box = document.querySelector('[data-presence-summary]');
      ['percent', 'verdict', 'self', 'proxy', 'absent', 'missingDocs'].forEach((k) => { const el = box.querySelector('[data-p=' + k + ']'); if (el) el.textContent = s[k]; });
      box.querySelector('[data-p=missing-wrap]').hidden = !s.missingDocs;
      box.querySelector('.meter-fill').style.width = s.width + '%';
      box.classList.toggle('is-ok', s.quorate);
      document.querySelectorAll('.vote-list li[data-unit="' + form.querySelector('[name=unit_id]').value + '"]').forEach((li) => { li.hidden = mode === ''; });
      document.querySelectorAll('[data-none-present]').forEach((el) => { el.hidden = (s.self + s.proxy) > 0; });
      Object.entries(s.motions || {}).forEach(([id, m]) => updateMotion(id, m));
      state.textContent = 'uloženo';
    } catch (e) {
      state.textContent = 'nepodařilo se uložit – zkuste to znovu';
    }
  };
  form.querySelectorAll('input[name=mode]').forEach((r) => r.addEventListener('change', save));
  proxy.querySelectorAll('input').forEach((i) => i.addEventListener('change', save));
  form.addEventListener('submit', (e) => { e.preventDefault(); save(); });
});

function updateMotion(id, m) {
  const card = document.querySelector('[data-motion="' + id + '"]');
  if (!card) return;
  ['yes', 'no', 'abstain', 'cyes', 'cno', 'cabstain', 'verdict'].forEach((k) => { const el = card.querySelector('[data-m=' + k + ']'); if (el) el.textContent = m[k]; });
  ['yes', 'no', 'abstain'].forEach((k) => { const el = card.querySelector('.mb-' + k); if (el) el.style.width = m['w' + k] + '%'; });
  card.classList.toggle('is-passed', m.passed);
}
document.querySelectorAll('.vote-form').forEach((form) => {
  const send = async () => {
    const data = new FormData(form); data.append('ajax', '1');
    try {
      const res = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' });
      updateMotion(form.querySelector('[name=motion_id]').value, await res.json());
    } catch (e) { form.classList.add('is-error'); }
  };
  form.querySelectorAll('input[name=choice]').forEach((r) => r.addEventListener('change', send));
  form.addEventListener('submit', (e) => { e.preventDefault(); send(); });
});

// Upozornění do telefonu: zapnutí a vypnutí na tomto zařízení
document.querySelectorAll('[data-push]').forEach(async (box) => {
  const state = box.querySelector('.push-state'), on = box.querySelector('[data-push-on]'), off = box.querySelector('[data-push-off]');
  const ios = /iPhone|iPad|iPod/.test(navigator.userAgent), standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  const say = (t) => { state.textContent = t; };
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
    say(ios && !standalone ? 'Nejdřív si web přidejte na plochu (návod níže), pak ho otevřete z plochy.' : 'Tento prohlížeč upozornění neumí.');
    box.querySelector('.push-ios').hidden = !ios; return;
  }
  const key = Uint8Array.from(atob(box.dataset.key.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
  const send = (url, fields) => { const f = new FormData(); f.append('_csrf', box.dataset.csrf); f.append('ajax', '1'); Object.entries(fields).forEach(([k, v]) => f.append(k, v)); return fetch(url, { method: 'POST', body: f, credentials: 'same-origin' }); };
  let reg;
  try { reg = await navigator.serviceWorker.register(box.dataset.sw, { scope: box.dataset.scope }); } catch (e) { say('Upozornění se nepodařilo připravit: ' + e.message); return; }
  const refresh = async () => {
    const sub = await reg.pushManager.getSubscription();
    on.hidden = !!sub; off.hidden = !sub;
    say(sub ? 'Na tomto zařízení jsou upozornění zapnutá.' : (Notification.permission === 'denied' ? 'Upozornění jsou v prohlížeči zakázaná – povolte je v nastavení prohlížeče pro tento web.' : 'Na tomto zařízení jsou upozornění vypnutá.'));
  };
  on.addEventListener('click', async () => {
    on.disabled = true;
    try {
      if ((await Notification.requestPermission()) !== 'granted') { say('Upozornění jste nepovolili.'); on.disabled = false; return; }
      const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
      const res = await send(box.dataset.sub, { subscription: JSON.stringify(sub) });
      const data = await res.json();
      if (!data.ok) { await sub.unsubscribe(); say(data.error || 'Nepodařilo se uložit.'); } else { location.reload(); return; }
    } catch (e) { say('Upozornění se nepodařilo zapnout: ' + e.message); }
    on.disabled = false;
  });
  off.addEventListener('click', async () => {
    const sub = await reg.pushManager.getSubscription();
    if (sub) { await send(box.dataset.unsub, { endpoint: sub.endpoint }); await sub.unsubscribe(); }
    location.reload();
  });
  refresh();
});

// AI asistent: ukázková otázka se vloží do pole
document.querySelectorAll('[data-fill]').forEach((b) => b.addEventListener('click', () => { const t = document.querySelector(b.dataset.fill); if (t) { t.value = b.textContent.trim(); t.focus(); } }));
// Odeslání otázky: tlačítko ukáže, že asistent přemýšlí
document.querySelectorAll('.chat-form').forEach((f) => f.addEventListener('submit', (e) => { if (e.submitter && e.submitter.value === 'nova') return; const b = f.querySelector('button[type=submit]'); b.disabled = true; b.textContent = 'Asistent přemýšlí…'; }));

// Plovoucí AI asistent
document.querySelectorAll('[data-asst]').forEach((box) => {
  const panel = box.querySelector('.asst-panel'), fab = box.querySelector('.asst-fab'), log = box.querySelector('.asst-log'), form = box.querySelector('[data-asst-form]'), ta = form.querySelector('textarea'), ex = box.querySelector('.asst-examples'), body = box.querySelector('.asst-body') || log;
  // Bez rozhovoru zůstane nahoře uvítání (příklady jsou pod ním); s rozhovorem se skočí na poslední zprávu.
  const toEnd = () => { body.scrollTop = ex && !ex.hidden ? 0 : body.scrollHeight; };
  const store = (v) => { try { sessionStorage.setItem('asst-open', v ? '1' : ''); } catch (e) {} };
  // V telefonu panel zabere celou obrazovku – stránka pod ním se nesmí posouvat.
  const mobile = window.matchMedia('(max-width: 560px)');
  const lock = () => document.documentElement.classList.toggle('asst-lock', !panel.hidden && mobile.matches);
  mobile.addEventListener('change', lock);
  const open = (v, focus) => { panel.hidden = !v; fab.setAttribute('aria-expanded', v ? 'true' : 'false'); box.classList.toggle('is-open', v); store(v); lock(); if (v) { toEnd(); if (focus) ta.focus(); } };
  box.querySelectorAll('[data-asst-toggle]').forEach((b) => b.addEventListener('click', () => open(panel.hidden, !mobile.matches)));
  // tah prstem mimo seznam zpráv (záhlaví, formulář) neposouvá stránku za oknem
  panel.addEventListener('touchmove', (e) => { if (!e.target.closest('.asst-body, textarea')) e.preventDefault(); }, { passive: false });
  try { if (sessionStorage.getItem('asst-open') === '1') open(true, false); } catch (e) {}
  const add = (cls, html) => { const li = document.createElement('li'); li.className = cls; li.innerHTML = html; log.appendChild(li); toEnd(); return li; };
  const esc = (t) => t.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const ask = async (q) => {
    q = q.trim(); if (!q) return;
    ex.hidden = true; add('chat-q', esc(q).replace(/\n/g, '<br>')); const wait = add('chat-a asst-wait', '<span class="asst-dots"><i></i><i></i><i></i></span> Asistent přemýšlí…');
    ta.value = ''; form.querySelector('button').disabled = true;
    const f = new FormData(); f.append('_csrf', box.dataset.csrf); f.append('ajax', '1'); f.append('q', q);
    try { const r = await (await fetch(box.dataset.url, { method: 'POST', body: f, credentials: 'same-origin' })).json(); wait.classList.remove('asst-wait'); wait.innerHTML = r.ok ? r.html : '<p class="asst-err">' + esc(r.error || 'Asistent teď neodpovídá.') + '</p>'; }
    catch (e) { wait.classList.remove('asst-wait'); wait.innerHTML = '<p class="asst-err">Asistent teď neodpovídá. Zkuste to prosím znovu.</p>'; }
    form.querySelector('button').disabled = false; toEnd(); ta.focus();
  };
  form.addEventListener('submit', (e) => { e.preventDefault(); ask(ta.value); });
  ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(ta.value); } });
  box.querySelectorAll('[data-asst-ask]').forEach((b) => b.addEventListener('click', () => ask(b.textContent)));
  box.querySelector('[data-asst-new]').addEventListener('click', async () => { const f = new FormData(); f.append('_csrf', box.dataset.csrf); f.append('ajax', '1'); f.append('do', 'nova');
    await fetch(box.dataset.url, { method: 'POST', body: f, credentials: 'same-origin' }); log.querySelectorAll('li:not(.asst-hello)').forEach((li) => li.remove()); ex.hidden = false; ta.focus(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !panel.hidden) open(false); });
});

// Vyhledání společenství v ARES při zakládání webu: IČO načte rovnou, část názvu nabídne seznam SVJ.
document.querySelectorAll('[data-ares]').forEach((box) => {
  const form = box.closest('form'), q = box.querySelector('[data-ares-q]'), go = box.querySelector('[data-ares-go]');
  const status = box.querySelector('[data-ares-status]'), list = box.querySelector('[data-ares-results]');
  const say = (text, bad) => { status.textContent = text; status.classList.toggle('is-error', !!bad); };
  const fill = (s, warning) => {
    form.querySelectorAll('[data-ares-field]').forEach((f) => {
      const v = s[f.dataset.aresField]; if (v === undefined || v === '') return;
      f.value = v; f.dispatchEvent(new Event('input', { bubbles: true })); f.dispatchEvent(new Event('change', { bubbles: true }));
    });
    list.hidden = true; list.innerHTML = '';
    say('Doplněno z ARES: ' + s.name + ', IČO ' + s.ico + (s.address ? ', sídlo ' + s.address : '') + '. Zkontrolujte prosím název a adresu.' + (warning ? ' ' + warning : ''), !!warning);
  };
  const run = async () => {
    const text = q.value.trim(); if (!text) { q.focus(); return; }
    go.disabled = true; say('Hledám v ARES…');
    try {
      const r = await fetch(box.dataset.ares + '?q=' + encodeURIComponent(text), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const d = await r.json();
      if (!d.ok) { list.hidden = true; say(d.error || 'Nepodařilo se načíst údaje z ARES.', true); return; }
      if (d.subject) { fill(d.subject, d.warning); return; }
      list.innerHTML = ''; list.hidden = false;
      d.results.forEach((s) => {
        const li = document.createElement('li'), b = document.createElement('button');
        b.type = 'button'; b.className = 'ares-pick';
        const n = document.createElement('strong'); n.textContent = s.name;
        const m = document.createElement('small'); m.textContent = 'IČO ' + s.ico + (s.address ? ' · ' + s.address : '');
        b.append(n, m); b.addEventListener('click', () => fill(s)); li.append(b); list.append(li);
      });
      say(d.total > d.results.length ? `Nalezeno ${d.total} SVJ, ukazujeme prvních ${d.results.length}. Vyberte své, nebo hledání upřesněte (třeba i číslem domu či obcí).` : 'Vyberte své společenství:');
    } catch (e) { say('ARES se teď nepodařilo zastihnout. Údaje můžete vyplnit ručně.', true); }
    finally { go.disabled = false; }
  };
  go.addEventListener('click', run);
  q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); run(); } });
});

// Tlačítko, které vyplní pole (např. jméno ze seznamu členů výboru)
document.querySelectorAll('[data-fill-target]').forEach((b) => b.addEventListener('click', () => {
  const f = document.querySelector(b.dataset.fillTarget); if (!f) return;
  f.value = b.dataset.fillValue; f.dispatchEvent(new Event('input', { bubbles: true })); f.focus();
}));

// Výsledek akce uprostřed dlouhé stránky (např. zkouška spojení s AI): po načtení ho dorovnat do okna,
// protože obsah nad ním se může ještě dopočítat a skok na kotvu by ho odsunul pod okraj.
(() => {
  const el = document.querySelector('[data-scroll-into-view]');
  if (!el) return;
  const show = () => el.scrollIntoView({ block: 'center', behavior: 'instant' });
  if (document.readyState === 'complete') show(); else window.addEventListener('load', show, { once: true });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(show);
})();

// Zvětšení obrázku (např. snímek u hlášení) v okně nad stránkou. Odkaz na samotný obrázek by ve webu
// přidaném na plochu telefonu otevřel obrázek bez tlačítka Zpět a nešlo by se vrátit.
document.addEventListener('click', (e) => {
  const a = e.target.closest('a[data-zoom]');
  if (!a || e.ctrlKey || e.metaKey || e.shiftKey) return;
  e.preventDefault();
  const dlg = document.createElement('dialog');
  dlg.className = 'zoom';
  dlg.setAttribute('aria-label', 'Zvětšený obrázek');
  const close = document.createElement('button');
  close.type = 'button'; close.className = 'zoom-x'; close.setAttribute('aria-label', 'Zavřít'); close.textContent = '×';
  const img = document.createElement('img');
  img.src = a.href; img.alt = (a.querySelector('img') || {}).alt || 'Obrázek';
  dlg.append(close, img);
  document.body.append(dlg);
  const done = () => { dlg.close(); dlg.remove(); };
  close.addEventListener('click', done);
  dlg.addEventListener('click', (ev) => { if (ev.target === dlg || ev.target === img) done(); });
  dlg.addEventListener('cancel', (ev) => { ev.preventDefault(); done(); });
  dlg.showModal();
  close.focus();
});

// Živý náhled textu pod polem (stejná pravidla jako format_text() na serveru: odstavce, „- “ odrážky, ## nadpis, **tučně**, odkazy).
(() => {
  const esc = (t) => t.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const inline = (s) => esc(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/https?:\/\/[^\s<]+[^\s<.,;:!?)]/g, (u) => '<a href="' + u + '" rel="noopener noreferrer">' + u + '</a>');
  const format = (text, notes) => {
    let html = ''; let par = []; let list = [];
    const flush = () => {
      if (par.length) html += '<p>' + par.map(inline).join('<br>') + '</p>';
      if (list.length) html += '<ul>' + list.map((l) => '<li>' + [].concat(l).map(inline).join('<br><span class="li-note">') + '</span>'.repeat([].concat(l).length - 1) + '</li>').join('') + '</ul>';
      par = []; list = [];
    };
    text.replace(/\r/g, '').trim().split('\n').forEach((raw) => {
      const line = raw.trim();
      if (line === '') flush();
      else if (line.startsWith('## ')) { flush(); html += '<h3>' + inline(line.slice(3)) + '</h3>'; }
      else if (line.startsWith('- ')) { if (par.length) flush(); list.push(line.slice(2)); }
      else if (notes && list.length) list[list.length - 1] = [].concat(list[list.length - 1], line);
      else { if (list.length) flush(); par.push(line); }
    });
    flush();
    return html;
  };
  document.querySelectorAll('[data-preview-for]').forEach((box) => {
    const form = box.closest('form');
    const field = form && form.querySelector('[name="' + box.dataset.previewFor + '"]');
    const out = box.querySelector('.prose');
    if (!field || !out) return;
    const update = () => { const v = field.value.trim(); box.hidden = v === ''; out.innerHTML = v ? format(v, box.hasAttribute('data-preview-notes')) : ''; };
    field.addEventListener('input', update);
    update();
  });
})();
