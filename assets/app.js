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
