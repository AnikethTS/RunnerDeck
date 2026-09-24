import { post, redirectIfUnauthenticated } from './js/api.js';
import { setLoading, clearLoading } from './js/utils.js';
import { showToast } from './js/toast.js';
import {
  sortState, selectedRunners, filteredRunners, sortRunners, updateSortIndicators, rowHtml, updateBulkActionsBar,
} from './js/table.js';
import { renderStats } from './js/stats.js';
import {
  confirmModal, openLogViewer, openRenameModal, initModals,
} from './js/modals.js';
import './js/theme.js';

const POLL_MS = 5000;

function initDashboard() {
  const rowsEl = document.getElementById('runner-rows');
  const bannerEl = document.getElementById('health-banner');
  const lastUpdatedEl = document.getElementById('last-updated');
  const exportBtn = document.getElementById('btn-export');
  let lastSnapshot = null;
  let lastRowsKey = '';

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
    renderErrors(snapshot.errors);
    snapshot.runners.forEach((r) => {
      if (r.just_flagged) showToast(`${r.agent_name || r.id} crashed and needs attention`);
      if (r.should_auto_restart) post('start', { runner: r.id }).catch(() => {});
    });

    const liveIds = new Set(snapshot.runners.map((r) => r.id));
    [...selectedRunners].forEach((id) => {
      if (!liveIds.has(id)) selectedRunners.delete(id);
    });

    const visible = visibleRows();
    const rowsKey = JSON.stringify({
      runners: snapshot.runners,
      filter: document.getElementById('runner-filter').value,
      sort: sortState,
      selected: [...selectedRunners],
    });
    if (rowsKey !== lastRowsKey) {
      lastRowsKey = rowsKey;
      rowsEl.innerHTML = visible.map(rowHtml).join('');
      updateSortIndicators();
    }
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

  async function checkForUpdates() {
    const res = await fetch('api.php?action=check_updates');
    if (redirectIfUnauthenticated(res.status)) return;
    const data = await res.json();
    if (!data.ok || !data.update_available) return;
    const badge = document.getElementById('update-available');
    badge.textContent = `Update available: v${data.latest}`;
    badge.hidden = false;
  }

  function drainTimeoutMs() {
    const n = Number(window.__DRAIN_TIMEOUT__);
    return (Number.isFinite(n) && n >= 1 ? n : 600) * 1000;
  }

  function idsStillBusy(ids) {
    if (!lastSnapshot) return true;
    const want = new Set(ids);
    return lastSnapshot.runners.some((r) => {
      if (!want.has(r.id)) return false;
      if (!r.github) return true;
      return !!r.github.busy;
    });
  }

  function sleep(ms) {
    return new Promise((resolve) => {
      window.setTimeout(resolve, ms);
    });
  }

  async function drainThenStop(action, params, ids) {
    const deadline = Date.now() + drainTimeoutMs();
    while (idsStillBusy(ids) && Date.now() < deadline) {
      await fetchStatus();
      if (!idsStillBusy(ids)) break;
      await sleep(2000);
    }
    if (idsStillBusy(ids)) {
      const proceed = await confirmModal(
        'Still busy after waiting for the job to finish. Stop anyway and interrupt it?',
      );
      if (!proceed) return;
      await runWithBusyGuard(action, { ...params, force: '1' });
      return;
    }
    await runWithBusyGuard(action, params);
  }

  async function runWithBusyGuard(action, params) {
    let { status, data } = await post(action, params);
    if (status === 409) {
      const proceed = await confirmModal(data.message);
      if (!proceed) return;
      ({ data } = await post(action, { ...params, force: '1' }));
    }
    if (!data.ok) {
      showToast(data.message || `${action} failed`);
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
    if (action === 'crash-history') {
      const row = lastSnapshot ? lastSnapshot.runners.find((r) => r.id === runner) : null;
      const modal = document.getElementById('crash-history-modal');
      const title = document.getElementById('crash-history-title');
      const list = document.getElementById('crash-history-list');
      const name = tr.dataset.agentName || runner;
      title.textContent = `${name} — crashes (7 days)`;
      list.replaceChildren();
      const times = row && Array.isArray(row.crash_at_7d) ? row.crash_at_7d : [];
      if (!times.length) {
        const li = document.createElement('li');
        li.className = 'muted';
        li.textContent = 'No timestamps stored (history needs pdo_sqlite).';
        list.append(li);
      } else {
        times.forEach((ts) => {
          const li = document.createElement('li');
          const d = new Date(ts * 1000);
          li.textContent = Number.isNaN(d.getTime()) ? String(ts) : d.toLocaleString();
          list.append(li);
        });
      }
      modal.hidden = false;
      return;
    }
    if (action === 'rename') {
      openRenameModal(runner, tr.dataset.agentName);
      return;
    }
    if (action === 'start') {
      await withLoading(btn, 'Starting…', async () => {
        const { data } = await post('start', { runner });
        if (!data.ok) showToast(data.message);
        await fetchStatus();
      });
      return;
    }
    if (action === 'stop' || action === 'restart') {
      await withLoading(btn, action === 'stop' ? 'Stopping…' : 'Restarting…', () => runWithBusyGuard(action, { runner }));
      return;
    }
    if (action === 'drain') {
      await withLoading(btn, 'Draining…', () => drainThenStop('stop', { runner }, [runner]));
      return;
    }
    if (action === 'clear-work') {
      await withLoading(btn, 'Clearing…', async () => {
        const { data } = await post('clear_work', { runner });
        if (!data.ok) showToast(data.message);
        await fetchStatus();
      });
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
      if (!data.ok) showToast(data.message);
      await fetchStatus();
    });
  });

  document.getElementById('btn-stop-all').addEventListener('click', (e) => {
    withLoading(e.currentTarget, 'Stopping All…', () => runWithBusyGuard('stop_all', {}));
  });

  document.getElementById('btn-drain-all').addEventListener('click', (e) => {
    const ids = lastSnapshot ? lastSnapshot.runners.map((r) => r.id) : [];
    withLoading(e.currentTarget, 'Draining…', () => drainThenStop('stop_all', {}, ids));
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
      if (!data.ok) showToast(data.message);
      await fetchStatus();
    });
  });

  document.getElementById('btn-bulk-stop').addEventListener('click', (e) => {
    withLoading(e.currentTarget, 'Stopping…', () => runWithBusyGuard('bulk_stop', { runners: [...selectedRunners].join(',') }));
  });

  document.getElementById('btn-bulk-drain').addEventListener('click', (e) => {
    const ids = [...selectedRunners];
    withLoading(
      e.currentTarget,
      'Draining…',
      () => drainThenStop('bulk_stop', { runners: ids.join(',') }, ids),
    );
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
      if (!data.ok) showToast(data.message);
      await fetchStatus();
    });
  });

  function renderErrors(entries) {
    const countEl = document.getElementById('app-log-count');
    const body = document.getElementById('app-log-body');
    if (!countEl || !body) return;
    const list = Array.isArray(entries) ? entries : [];
    countEl.textContent = list.length ? ` (${list.length})` : '';
    body.replaceChildren();
    if (!list.length) {
      const empty = document.createElement('p');
      empty.className = 'muted';
      empty.textContent = 'No errors logged yet.';
      body.append(empty);
      return;
    }
    const ol = document.createElement('ol');
    ol.className = 'app-log-list';
    [...list].reverse().forEach((entry) => {
      const li = document.createElement('li');
      li.className = 'app-log-item';
      const meta = document.createElement('div');
      meta.className = 'app-log-meta';
      const when = formatErrorTime(entry.time);
      meta.textContent = entry.runner
        ? `${when} · ${entry.action} · ${entry.runner}`
        : `${when} · ${entry.action}`;
      const msg = document.createElement('div');
      msg.textContent = entry.message || '';
      li.append(meta, msg);
      if (entry.stderr) {
        const pre = document.createElement('pre');
        pre.className = 'app-log-stderr';
        pre.textContent = entry.stderr;
        li.append(pre);
      }
      ol.append(li);
    });
    body.append(ol);
  }

  function formatErrorTime(iso) {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso || '';
    return d.toLocaleString();
  }

  let statusTimer = null;

  function stopPolling() {
    if (statusTimer !== null) {
      clearInterval(statusTimer);
      statusTimer = null;
    }
  }

  function startPolling() {
    stopPolling();
    statusTimer = setInterval(fetchStatus, POLL_MS);
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      stopPolling();
      return;
    }
    fetchStatus();
    startPolling();
  });

  initModals(fetchStatus);
  checkForUpdates();

  document.getElementById('crash-history-close').addEventListener('click', () => {
    document.getElementById('crash-history-modal').hidden = true;
  });

  if (window.__SNAPSHOT__) render(window.__SNAPSHOT__);
  else fetchStatus();
  if (!document.hidden) startPolling();
}

initDashboard();
