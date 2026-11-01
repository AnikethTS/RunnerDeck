import { confirmModal } from './modals.js';

document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!form || form.tagName !== 'FORM') return;
  const message = form.getAttribute('data-confirm');
  if (!message || form.dataset.confirmed === '1') return;
  event.preventDefault();
  const okLabel = form.getAttribute('data-confirm-ok') || 'Continue';
  const submitter = event.submitter instanceof HTMLElement ? event.submitter : undefined;
  confirmModal(message, okLabel).then((ok) => {
    if (!ok) return;
    form.dataset.confirmed = '1';
    form.requestSubmit(submitter);
  });
});
