import { post, redirectIfUnauthenticated } from './js/api.js';
import { setLoading, clearLoading } from './js/utils.js';
import { showToast } from './js/toast.js';
import {
  sortState, selectedRunners, filteredRunners, sortRunners, updateSortIndicators, rowHtml, updateBulkActionsBar,
} from './js/table.js';
import { renderStats, renderLoadStat } from './js/stats.js';
import {
  wireScopeToggle, submitSettingsForm, confirmModal, openLogViewer, openRenameModal, initModals,
} from './js/modals.js';

const POLL_MS = 5000;
const SYSTEM_POLL_MS = 2000;
const THEME_KEY = 'runnerdeck-theme';

(() => {
  const btn = document.getElementById('btn-theme-toggle');
  const sunIcon = document.getElementById('theme-icon-sun');
  const moonIcon = document.getElementById('theme-icon-moon');
  if (!btn) return;

  function readCookie() {
    const match = document.cookie.match(/(?:^|; )runnerdeck-theme=(dark|light)/);
    return match ? match[1] : null;
  }

  function getStoredTheme() {
    const fromCookie = readCookie();
    if (fromCookie) return fromCookie;
    try {
      const legacy = localStorage.getItem(THEME_KEY);
      if (legacy === 'dark' || legacy === 'light') {
        storeTheme(legacy);
        localStorage.removeItem(THEME_KEY);
        return legacy;
      }
    } catch {
      // storage unavailable
    }
    return null;
  }

  function storeTheme(theme) {
    const maxAge = theme ? 31536000 : 0;
    const value = theme || '';
    document.cookie = `${THEME_KEY}=${value}; Path=/; Max-Age=${maxAge}; SameSite=Lax`;
  }

  function systemPrefersDark() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  function effectiveTheme(stored) {
    if (stored === 'dark' || stored === 'light') return stored;
    return systemPrefersDark() ? 'dark' : 'light';
  }

  function applyTheme(stored) {
    if (stored === 'dark' || stored === 'light') {
      document.documentElement.dataset.theme = stored;
    } else {
      delete document.documentElement.dataset.theme;
    }
    const dark = effectiveTheme(stored) === 'dark';
    sunIcon.toggleAttribute('hidden', dark);
    moonIcon.toggleAttribute('hidden', !dark);
  }

  let stored = getStoredTheme();
  applyTheme(stored);

  btn.addEventListener('click', () => {
    stored = effectiveTheme(stored) === 'dark' ? 'light' : 'dark';
    storeTheme(stored);
    applyTheme(stored);
  });

  window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (!getStoredTheme()) applyTheme(null);
  });
})();

function initDashboard() {
  const rowsEl = document.getElementById('runner-rows');
  const bannerEl = document.getElementById('health-banner');
  const lastUpdatedEl = document.getElementById('last-updated');
  const exportBtn = document.getElementById('btn-export');
  let lastSnapshot = null;

  function visibleRows() {
    return lastSnapshot ? sortRunners(filteredRunners(lastSnapshot.runners)) : [];
  }

  function render(snapshot) {
    lastSnapshot = snapshot;
    exportBtn.disabled = false;
    const h = snapshot.health;
    if (!h.logged_in || !h.org_access_ok) {
      bannerEl.hidden = false;
      bannerEl.className = 'banner';
      bannerEl.textContent = !h.logged_in
        ? `gh CLI is not authenticated: ${h.message}`
        : `gh CLI is logged in but cannot read runners: ${h.message}`;
    } else {
      bannerEl.hidden = true;
    }

    renderStats(snapshot);
    snapshot.runners.forEach((r) => {
      if (r.just_flagged) showToast(`${r.agent_name || r.id} crashed and needs attention`);
      if (r.should_auto_restart) post('start', { runner: r.id }).catch(() => {});
    });

    const liveIds = new Set(snapshot.runners.map((r) => r.id));
    [...selectedRunners].forEach((id) => {
      if (!liveIds.has(id)) selectedRunners.delete(id);
    });

    const visible = visibleRows();
    rowsEl.innerHTML = visible.map(rowHtml).join('');
    updateSortIndicators();
    updateBulkActionsBar(visible);
    lastUpdatedEl.textContent = `updated ${new Date(snapshot.generated_at * 1000).toLocaleTimeString()}`;
  }

  async function withLoading(btn, label, fn) {
    setLoading(btn, label);
    try {
      await fn();
    } finally {
      clearLoading(btn);
    }
  }

  document.getElementById('runner-filter').addEventListener('input', () => {
    if (lastSnapshot) render(lastSnapshot);
  });

  document.addEventListener('keydown', (event) => {
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || event.isComposing || event.repeat) return;
    const active = document.activeElement;
    if (active && (active.matches('input, textarea, select') || active.isContentEditable)) return;
    if (event.key === '/') {
      event.preventDefault();
      document.getElementById('runner-filter').focus();
    } else if (event.key === 'r') {
      event.preventDefault();
      document.getElementById('btn-refresh').click();
    }
  });

  document.querySelectorAll('.sortable[data-sort]').forEach((control) => {
    control.addEventListener('click', () => {
      const key = control.dataset.sort;
      sortState.dir = sortState.key === key ? sortState.dir * -1 : 1;
      sortState.key = key;
      if (lastSnapshot) render(lastSnapshot);
    });
  });

  async function fetchStatus() {
    const res = await fetch('api.php?action=status&lines=5');
    if (redirectIfUnauthenticated(res.status)) return;
    render(await res.json());
  }

  async function fetchSystemStats() {
    const res = await fetch('api.php?action=system');
    if (redirectIfUnauthenticated(res.status)) return;
    const data = await res.json();
    if (data.ok) renderLoadStat(data.system);
  }

  async function checkForUpdates() {
    const res = await fetch('api.php?action=check_updates');
    if (redirectIfUnauthenticated(res.status)) return;
    const data = await res.json();
    if (!data.ok || !data.update_available) return;
    const badge = document.getElementById('update-available');
    badge.textContent = `Update available: v${data.latest}`;
    badge.hidden = false;
  }

  async function runWithBusyGuard(action, params) {
    let { status, data } = await post(action, params);
    if (status === 409) {
      const proceed = await confirmModal(data.message);
      if (!proceed) return;
      ({ data } = await post(action, { ...params, force: '1' }));
    }
    if (!data.ok) {
      alert(data.message || `${action} failed`);
    }
    await fetchStatus();
  }

  rowsEl.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    const tr = btn.closest('tr');
    const runner = tr.dataset.runner;
    const action = btn.dataset.action;

    if (action === 'view-log') {
      openLogViewer(runner);
      return;
    }
    if (action === 'rename') {
      openRenameModal(runner, tr.dataset.agentName);
      return;
    }
    if (action === 'start') {
      await withLoading(btn, 'Starting…', async () => {
        const { data } = await post('start', { runner });
        if (!data.ok) alert(data.message);
        await fetchStatus();
      });
      return;
    }
    if (action === 'stop' || action === 'restart') {
      await withLoading(btn, action === 'stop' ? 'Stopping…' : 'Restarting…', () => runWithBusyGuard(action, { runner }));
      return;
    }
    if (action === 'delete') {
      const name = tr.dataset.agentName || runner;
      const sure = await confirmModal(
        `Permanently delete ${runner} (${name})? This deregisters it from GitHub and deletes its local files, `
        + 'including logs. This cannot be undone.',
      );
      if (!sure) return;
      await withLoading(btn, 'Deleting…', () => runWithBusyGuard('delete_runner', { runner }));
    }
  });

  exportBtn.addEventListener('click', () => {
    if (!lastSnapshot) return;
    const blob = new window.Blob([JSON.stringify(lastSnapshot.runners, null, 2)], { type: 'application/json' });
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `runnerdeck-runners-${new Date().toISOString().replace(/[:.]/g, '-')}.json`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => window.URL.revokeObjectURL(url), 0);
  });

  document.getElementById('btn-refresh').addEventListener('click', (e) => {
    withLoading(e.currentTarget, 'Refreshing…', fetchStatus);
  });

  document.getElementById('btn-start-all').addEventListener('click', (e) => {
    const count = document.getElementById('pool-size').value || 10;
    withLoading(e.currentTarget, 'Starting All…', async () => {
      const { data } = await post('start_all', { count });
      if (!data.ok) alert(data.message);
      await fetchStatus();
    });
  });

  document.getElementById('btn-stop-all').addEventListener('click', (e) => {
    withLoading(e.currentTarget, 'Stopping All…', () => runWithBusyGuard('stop_all', {}));
  });

  rowsEl.addEventListener('change', (e) => {
    const checkbox = e.target.closest('input.row-select');
    if (!checkbox) return;
    const id = checkbox.dataset.runner;
    if (checkbox.checked) selectedRunners.add(id);
    else selectedRunners.delete(id);
    if (lastSnapshot) updateBulkActionsBar(visibleRows());
  });

  document.getElementById('select-all-runners').addEventListener('change', (e) => {
    if (!lastSnapshot) return;
    visibleRows().forEach((r) => (e.target.checked ? selectedRunners.add(r.id) : selectedRunners.delete(r.id)));
    render(lastSnapshot);
  });

  document.getElementById('btn-bulk-clear').addEventListener('click', () => {
    selectedRunners.clear();
    if (lastSnapshot) render(lastSnapshot);
  });

  document.getElementById('btn-bulk-start').addEventListener('click', (e) => {
    withLoading(e.currentTarget, 'Starting…', async () => {
      const { data } = await post('bulk_start', { runners: [...selectedRunners].join(',') });
      if (!data.ok) alert(data.message);
      await fetchStatus();
    });
  });

  document.getElementById('btn-bulk-stop').addEventListener('click', (e) => {
    withLoading(e.currentTarget, 'Stopping…', () => runWithBusyGuard('bulk_stop', { runners: [...selectedRunners].join(',') }));
  });

  document.getElementById('btn-bulk-delete').addEventListener('click', async (e) => {
    const count = selectedRunners.size;
    const sure = await confirmModal(
      `Permanently delete ${count} runner${count === 1 ? '' : 's'}? This deregisters `
      + 'each from GitHub and deletes its local files, including logs. This cannot be undone.',
    );
    if (!sure) return;
    await withLoading(e.currentTarget, 'Deleting…', async () => {
      await runWithBusyGuard('bulk_delete', { runners: [...selectedRunners].join(',') });
      selectedRunners.clear();
    });
  });

  document.getElementById('pool-size-form').addEventListener('submit', (e) => {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    withLoading(btn, 'Applying…', async () => {
      const { data } = await post('resize', { count: document.getElementById('pool-size').value });
      if (!data.ok) alert(data.message);
      await fetchStatus();
    });
  });

  initModals(fetchStatus);
  checkForUpdates();

  if (window.__SNAPSHOT__) render(window.__SNAPSHOT__);
  else fetchStatus();
  setInterval(fetchStatus, POLL_MS);
  setInterval(fetchSystemStats, SYSTEM_POLL_MS);
}

if (window.__NEEDS_SETUP__) {
  const scopeSelect = document.getElementById('setup-scope');
  wireScopeToggle(scopeSelect, document.getElementById('setup-org-field'), document.getElementById('setup-repo-field'));
  document.getElementById('setup-form').addEventListener('submit', (e) => {
    e.preventDefault();
    submitSettingsForm(
      document.getElementById('setup-error'),
      scopeSelect,
      document.getElementById('setup-org'),
      document.getElementById('setup-repo'),
      document.getElementById('setup-label'),
      e.target.querySelector('button[type="submit"]'),
    );
  });
} else {
  initDashboard();
}
