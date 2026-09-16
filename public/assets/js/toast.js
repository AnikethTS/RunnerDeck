const TOAST_MS = 6000;

export function showToast(message, type = 'critical') {
  const container = document.getElementById('toast-container');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.textContent = message;
  container.appendChild(toast);

  setTimeout(() => toast.remove(), TOAST_MS);
}
