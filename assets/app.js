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
  const msg = e.target.dataset.confirm;
  if (msg && !window.confirm(msg)) e.preventDefault();
});

// Filtrování dlouhého seznamu dokumentů při výběru příloh
document.querySelectorAll('input[data-filter]').forEach((input) => {
  const box = document.querySelector(input.dataset.filter);
  if (!box) return;
  const norm = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  input.addEventListener('input', () => {
    const q = norm(input.value.trim());
    box.querySelectorAll('label').forEach((l) => { l.hidden = q !== '' && !norm(l.textContent).includes(q); });
  });
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
});
