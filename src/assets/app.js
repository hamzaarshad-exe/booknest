'use strict';
document.querySelectorAll('form[data-confirm]').forEach(form => {
  form.addEventListener('submit', event => { if (!window.confirm(form.dataset.confirm)) event.preventDefault(); });
});
const showToast = message => {
  document.querySelector('.toast')?.remove();
  const toast = document.createElement('div');
  toast.className = 'toast'; toast.role = 'status'; toast.textContent = message;
  document.body.append(toast); window.setTimeout(() => toast.remove(), 4000);
};
document.querySelectorAll('[data-copy]').forEach(button => {
  button.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(button.dataset.copy); showToast('Booking link copied.'); }
    catch { window.prompt('Copy this booking link:', button.dataset.copy); }
  });
});
document.querySelectorAll('[data-print]').forEach(button => button.addEventListener('click', () => window.print()));
const menu = document.querySelector('[data-menu]');
menu?.addEventListener('click', () => {
  const open = document.querySelector('#sidebar').classList.toggle('is-open');
  menu.setAttribute('aria-expanded', String(open));
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape') { document.querySelector('#sidebar')?.classList.remove('is-open'); menu?.setAttribute('aria-expanded', 'false'); }
});
document.addEventListener('click', event => {
  const sidebar = document.querySelector('#sidebar');
  if (sidebar?.classList.contains('is-open') && !sidebar.contains(event.target) && !menu?.contains(event.target)) {
    sidebar.classList.remove('is-open'); menu?.setAttribute('aria-expanded', 'false');
  }
});
