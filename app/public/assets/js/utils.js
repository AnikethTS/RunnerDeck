export function setLoading(btn, label) {
  if (!btn) return;
  if (btn.dataset.originalText === undefined) {
    btn.dataset.originalText = btn.textContent;
  }
  btn.disabled = true;
  btn.classList.add('btn-loading');
  btn.textContent = label;
}

export function clearLoading(btn) {
  if (!btn) return;
  btn.disabled = false;
  btn.classList.remove('btn-loading');
  if (btn.dataset.originalText !== undefined) {
    btn.textContent = btn.dataset.originalText;
    delete btn.dataset.originalText;
  }
}
