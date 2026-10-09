export const sortState = { key: null, dir: 1 };
export const selectedRunners = new Set();

export function matchesState(runner, state) {
  if (!state) return true;
  if (state === 'idle') return Boolean(runner.github) && !runner.github.busy;
  if (state === 'busy') return Boolean(runner.github && runner.github.busy);
  if (state === 'crashed') return Boolean(runner.crash_flagged);
  if (state === 'mismatch') return Boolean(runner.mismatch_flagged);
  return true;
}

export function filteredRunners(runners) {
  const q = (document.getElementById('runner-filter').value || '').trim().toLowerCase();
  const stateEl = document.getElementById('runner-state-filter');
  const state = stateEl ? stateEl.value : '';
  return runners.filter((r) => {
    if (!matchesState(r, state)) return false;
    if (!q) return true;
    const version = (r.agent_version || '').toLowerCase();
    const labels = r.github && Array.isArray(r.github.labels)
      ? r.github.labels.join(' ').toLowerCase()
      : '';
    return r.id.toLowerCase().includes(q)
      || r.agent_name.toLowerCase().includes(q)
      || version.includes(q)
      || labels.includes(q);
  });
}

function sortValue(runner, key) {
  if (key === 'id') return runner.id;
  if (key === 'status') return runner.github ? (runner.github.busy ? 2 : runner.github.status === 'online' ? 1 : 0) : -1;
  if (key === 'cpu') return runner.cpu_percent ?? -1;
  if (key === 'uptime') return runner.uptime_seconds ?? -1;
  if (key === 'crashes') return runner.crash_count_7d ?? 0;
  if (key === 'disk') return runner.disk_kb ?? -1;
  return '';
}

export function sortRunners(runners) {
  if (!sortState.key) return runners;
  const { key, dir } = sortState;
  return [...runners].sort((a, b) => {
    const av = sortValue(a, key);
    const bv = sortValue(b, key);
    if (av < bv) return -1 * dir;
    if (av > bv) return 1 * dir;
    return 0;
  });
}

export function updateSortIndicators() {
  document.querySelectorAll('.sortable .sort-caret').forEach((el) => {
    el.textContent = '';
  });
  document.querySelectorAll('.runner-table th[aria-sort]').forEach((th) => {
    th.removeAttribute('aria-sort');
  });
  if (sortState.key) {
    const control = document.querySelector(`.sortable[data-sort="${sortState.key}"]`);
    const caret = control?.querySelector('.sort-caret');
    if (caret) caret.textContent = sortState.dir === 1 ? ' ▲' : ' ▼';
    control?.closest('th').setAttribute('aria-sort', sortState.dir === 1 ? 'ascending' : 'descending');
  }
}

export function paintRows(rowsEl, snapshot, visibleRunners) {
  const html = snapshot.view && typeof snapshot.view.rows_html === 'string'
    ? snapshot.view.rows_html
    : '';
  const wrap = document.createElement('tbody');
  wrap.innerHTML = html || rowsEl.innerHTML;
  const byId = new Map();
  wrap.querySelectorAll('tr[data-runner]').forEach((tr) => {
    byId.set(tr.dataset.runner, tr);
  });
  const nodes = visibleRunners.map((r) => {
    const tr = byId.get(r.id);
    if (!tr) return null;
    const box = tr.querySelector('.row-select');
    if (box) box.checked = selectedRunners.has(r.id);
    return tr;
  }).filter(Boolean);
  if (!nodes.length) {
    const msg = (snapshot.runners && snapshot.runners.length)
      ? 'No runners match this filter.'
      : 'No runners in the pool yet.';
    rowsEl.innerHTML = `<tr class="empty-row"><td colspan="6" class="muted">${msg}</td></tr>`;
    return;
  }
  rowsEl.replaceChildren(...nodes);
}

export function updateBulkActionsBar(visibleRunners) {
  const bar = document.getElementById('bulk-actions-bar');
  const count = selectedRunners.size;
  bar.hidden = count === 0;
  if (count > 0) {
    document.getElementById('bulk-actions-count').textContent = `${count} selected`;
  }

  const selectAll = document.getElementById('select-all-runners');
  const visibleIds = visibleRunners.map((r) => r.id);
  selectAll.checked = visibleIds.length > 0 && visibleIds.every((id) => selectedRunners.has(id));
  selectAll.indeterminate = !selectAll.checked && visibleIds.some((id) => selectedRunners.has(id));
}
