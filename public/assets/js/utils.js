export function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}

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

export function badge(text, cls) {
  return `<span class="badge badge-${cls}">${escapeHtml(text)}</span>`;
}

export function miniBar(percent) {
  const pct = Math.max(0, Math.min(100, percent));
  const cls = percent > 80 ? 'mini-bar-critical' : percent > 50 ? 'mini-bar-warn' : '';
  return `<span class="mini-bar"><span class="mini-bar-fill ${cls}" style="width:${pct}%"></span></span>`;
}

export function formatUptime(seconds) {
  if (seconds == null) return null;
  if (seconds < 60) return `${seconds}s`;
  const mins = Math.floor(seconds / 60);
  if (mins < 60) return `${mins}m`;
  const hours = Math.floor(mins / 60);
  const remMins = mins % 60;
  if (hours < 24) return `${hours}h ${remMins}m`;
  const days = Math.floor(hours / 24);
  const remHours = hours % 24;
  return `${days}d ${remHours}h`;
}
