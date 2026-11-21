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
