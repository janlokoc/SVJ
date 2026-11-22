document.documentElement.classList.add('js');

// Mobilní menu
const toggle = document.querySelector('.nav-toggle');
const nav = document.querySelector('.site-nav');
if (toggle && nav) {
  toggle.addEventListener('click', () => {
    const open = nav.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded', String(open));
  });
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
    .replace(/^(svj|spolecenstvi-vlastniku(-jednotek)?)-(?=.{2})/, '').slice(0, 30).replace(/-+$/, '');
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
  slug.addEventListener('input', () => { own = slug.value !== ''; slug.value = slug.value.toLowerCase(); check(); });
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
    file.addEventListener('change', () => { const c = preview.querySelector('canvas'); if (c) c.remove(); preview.hidden = !file.files.length; });
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
  let before = null;
  undo.addEventListener('click', () => { if (before !== null) { field.value = before; before = null; undo.hidden = true; status.textContent = ''; } });
  box.querySelectorAll('[data-ai-task]').forEach((btn) => btn.addEventListener('click', async () => {
    const buttons = box.querySelectorAll('button');
    buttons.forEach((b) => { b.disabled = true; });
    status.className = 'ai-status';
    status.textContent = 'Pomocník píše… může to trvat až minutu.';
    const body = new URLSearchParams({ _csrf: form.querySelector('[name=_csrf]').value, task: btn.dataset.aiTask, text: field.value, context: box.dataset.aiContext || '' });
    try {
      const r = await fetch(box.dataset.aiUrl, { method: 'POST', body, headers: { Accept: 'application/json' } });
      const d = await r.json();
      if (d.text) {
        before = field.value; field.value = d.text; undo.hidden = false;
        status.textContent = d.truncated ? 'Hotovo, ale text byl příliš dlouhý a je uříznutý. Zkontrolujte konec.' : 'Hotovo. Text si přečtěte a upravte, než ho uložíte.';
      } else { status.className = 'ai-status is-bad'; status.textContent = d.error || 'Nepodařilo se.'; }
    } catch (e) { status.className = 'ai-status is-bad'; status.textContent = 'Nepodařilo se spojit se serverem.'; }
    buttons.forEach((b) => { b.disabled = false; });
  }));
});
