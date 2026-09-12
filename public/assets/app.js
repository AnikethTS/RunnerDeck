import { post } from './js/api.js';
import { setLoading, clearLoading } from './js/utils.js';
import { updateMismatchStreaks, trackCrashesAndMaybeRestart, markExplicitlyStopped } from './js/reliability.js';
import {
  sortState, selectedRunners, filteredRunners, sortRunners, updateSortIndicators, rowHtml, updateBulkActionsBar,
} from './js/table.js';
import { fetchHistory } from './js/history-chart.js';
import { renderStats, renderLoadStat } from './js/stats.js';
import {
  wireScopeToggle, submitSettingsForm, confirmModal, openLogViewer, openRenameModal, initModals,
} from './js/modals.js';

const POLL_MS = 5000;
const SYSTEM_POLL_MS = 2000;

(() => {
  const STORAGE_KEY = 'runnerdeck-theme';
  const btn = document.getElementById('btn-theme-toggle');
  const sunIcon = document.getElementById('theme-icon-sun');
  const moonIcon = document.getElementById('theme-icon-moon');
  if (!btn) return;

  function getStoredTheme() {
    try {
      return localStorage.getItem(STORAGE_KEY);
    } catch {
      return null;
    }
  }

  function storeTheme(theme) {
    try {
      if (theme) {
        localStorage.setItem(STORAGE_KEY, theme);
      } else {
        localStorage.removeItem(STORAGE_KEY);
      }
    } catch {
      // storage unavailable; toggle still works for this session
    }
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
    const effective = effectiveTheme(stored);
    if (effective === 'dark') {
  sunIcon.setAttribute('hidden', '');
  moonIcon.removeAttribute('hidden');
} else {
  sunIcon.removeAttribute('hidden');
  moonIcon.setAttribute('hidden', '');
}
  }

  let stored = getStoredTheme();
  applyTheme(stored);

  btn.addEventListener('click', () => {
    const next = effectiveTheme(stored) === 'dark' ? 'light' : 'dark';
    stored = next;
    storeTheme(next);
    applyTheme(next);
  });

  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (!getStoredTheme()) applyTheme(null);
    });
  }
})();

function initDashboard() {
  const rowsEl = document.getElementById('runner-rows');
  const bannerEl = document.getElementById('health-banner');
  const lastUpdatedEl = document.getElementById('last-updated');

  let lastSnapshot = null;

  function render(snapshot) {
    lastSnapshot = snapshot;
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
    updateMismatchStreaks(snapshot.runners);
    trackCrashesAndMaybeRestart(snapshot.runners);

    const liveIds = new Set(snapshot.runners.map((r) => r.id));
    [...selectedRunners].forEach((id) => {
      if (!liveIds.has(id)) selectedRunners.delete(id);
    });

    const visible = sortRunners(filteredRunners(snapshot.runners));
    rowsEl.innerHTML = visible.map(rowHtml).join('');
    updateSortIndicators();
    updateBulkActionsBar(visible);
    lastUpdatedEl.textContent = `updated ${new Date(snapshot.generated_at * 1000).toLocaleTimeString()}`;
  }

  document.getElementById('runner-filter').addEventListener('input', () => {
    if (lastSnapshot) render(lastSnapshot);
  });

  (() => {
    const toggle = document.getElementById('auto-restart-toggle');
    try {
      toggle.checked = localStorage.getItem('runnerdeck-auto-restart') === '1';
    } catch {
      toggle.checked = false;
    }
    toggle.addEventListener('change', () => {
      try {
        localStorage.setItem('runnerdeck-auto-restart', toggle.checked ? '1' : '0');
      } catch {
        // storage unavailable; toggle still works for this session
      }
    });
  })();

  document.querySelectorAll('th.sortable').forEach((th) => {
    th.addEventListener('click', () => {
      const key = th.dataset.sort;
      sortState.dir = sortState.key === key ? sortState.dir * -1 : 1;
      sortState.key = key;
      if (lastSnapshot) render(lastSnapshot);
    });
  });

  async function fetchStatus() {
    const res = await fetch('api.php?action=status&lines=5');
    render(await res.json());
    fetchHistory();
  }

  async function fetchSystemStats() {
    const res = await fetch('api.php?action=system');
    const data = await res.json();
    if (data.ok) renderLoadStat(data.system);
  }

  async function checkForUpdates() {
    const res = await fetch('api.php?action=check_updates');
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
      setLoading(btn, 'Starting…');
      try {
        const { data } = await post('start', { runner });
        if (!data.ok) alert(data.message);
        await fetchStatus();
      } finally {
        clearLoading(btn);
      }
      return;
    }
    if (action === 'stop' || action === 'restart') {
      markExplicitlyStopped(runner);
      setLoading(btn, action === 'stop' ? 'Stopping…' : 'Restarting…');
      try {
        await runWithBusyGuard(action, { runner });
      } finally {
        clearLoading(btn);
      }
      return;
    }
    if (action === 'delete') {
      const name = tr.dataset.agentName || runner;
      const sure = await confirmModal(
        `Permanently delete ${runner} (${name})? This deregisters it from GitHub and deletes its local files, `
        + 'including logs. This cannot be undone.',
      );
      if (!sure) return;
      markExplicitlyStopped(runner);
      setLoading(btn, 'Deleting…');
      try {
        await runWithBusyGuard('delete_runner', { runner });
      } finally {
        clearLoading(btn);
      }
    }
  });

  document.getElementById('btn-refresh').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    setLoading(btn, 'Refreshing…');
    try {
      await fetchStatus();
    } finally {
      clearLoading(btn);
    }
  });

  document.getElementById('btn-start-all').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const count = document.getElementById('pool-size').value || 10;
    setLoading(btn, 'Starting All…');
    try {
      const { data } = await post('start_all', { count });
      if (!data.ok) alert(data.message);
      await fetchStatus();
    } finally {
      clearLoading(btn);
    }
  });

  document.getElementById('btn-stop-all').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    if (lastSnapshot) lastSnapshot.runners.forEach((r) => markExplicitlyStopped(r.id));
    setLoading(btn, 'Stopping All…');
    try {
      await runWithBusyGuard('stop_all', {});
    } finally {
      clearLoading(btn);
    }
  });

  rowsEl.addEventListener('change', (e) => {
    const checkbox = e.target.closest('input.row-select');
    if (!checkbox) return;
    const id = checkbox.dataset.runner;
    if (checkbox.checked) {
      selectedRunners.add(id);
    } else {
      selectedRunners.delete(id);
    }
    if (lastSnapshot) updateBulkActionsBar(sortRunners(filteredRunners(lastSnapshot.runners)));
  });

  document.getElementById('select-all-runners').addEventListener('change', (e) => {
    if (!lastSnapshot) return;
    const visible = sortRunners(filteredRunners(lastSnapshot.runners));
    if (e.target.checked) {
      visible.forEach((r) => selectedRunners.add(r.id));
    } else {
      visible.forEach((r) => selectedRunners.delete(r.id));
    }
    render(lastSnapshot);
  });

  document.getElementById('btn-bulk-clear').addEventListener('click', () => {
    selectedRunners.clear();
    if (lastSnapshot) render(lastSnapshot);
  });

  document.getElementById('btn-bulk-start').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const runners = [...selectedRunners].join(',');
    setLoading(btn, 'Starting…');
    try {
      const { data } = await post('bulk_start', { runners });
      if (!data.ok) alert(data.message);
      await fetchStatus();
    } finally {
      clearLoading(btn);
    }
  });

  document.getElementById('btn-bulk-stop').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    selectedRunners.forEach((id) => markExplicitlyStopped(id));
    setLoading(btn, 'Stopping…');
    try {
      await runWithBusyGuard('bulk_stop', { runners: [...selectedRunners].join(',') });
    } finally {
      clearLoading(btn);
    }
  });

  document.getElementById('btn-bulk-delete').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const count = selectedRunners.size;
    const sure = await confirmModal(
      `Permanently delete ${count} runner${count === 1 ? '' : 's'}? This deregisters `
      + 'each from GitHub and deletes its local files, including logs. This cannot be undone.',
    );
    if (!sure) return;
    selectedRunners.forEach((id) => markExplicitlyStopped(id));
    const runners = [...selectedRunners].join(',');
    setLoading(btn, 'Deleting…');
    try {
      await runWithBusyGuard('bulk_delete', { runners });
      selectedRunners.clear();
    } finally {
      clearLoading(btn);
    }
  });

  document.getElementById('pool-size-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    const count = document.getElementById('pool-size').value;
    setLoading(btn, 'Applying…');
    try {
      const { data } = await post('resize', { count });
      if (!data.ok) alert(data.message);
      await fetchStatus();
    } finally {
      clearLoading(btn);
    }
  });

  initModals(fetchStatus);
  checkForUpdates();

  if (window.__SNAPSHOT__) {
    render(window.__SNAPSHOT__);
    fetchHistory();
  } else {
    fetchStatus();
  }
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
