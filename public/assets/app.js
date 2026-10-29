import { post, redirectIfUnauthenticated } from './js/api.js';
import { setLoading, clearLoading } from './js/utils.js';
import { showToast } from './js/toast.js';
import {
  sortState, selectedRunners, filteredRunners, sortRunners, updateSortIndicators, paintRows, updateBulkActionsBar,
} from './js/table.js';
import { renderStats } from './js/stats.js';
import {
  confirmModal, openLogViewer, openRenameModal, initModals,
} from './js/modals.js';
import './js/theme.js';

const POLL_MS = 5000;
const QUEUE_MS = 20000;

function initDashboard() {
  const rowsEl = document.getElementById('runner-rows');
  const bannerEl = document.getElementById('health-banner');
  const lastUpdatedEl = document.getElementById('last-updated');
  const exportBtn = document.getElementById('btn-export');
  let lastSnapshot = null;
  let lastRowsKey = '';
  let lastLiveJobs = [];
  let lastRecentJobs = [];

  function visibleRows() {
    return lastSnapshot ? sortRunners(filteredRunners(lastSnapshot.runners)) : [];
  }

  function render(snapshot) {
    lastSnapshot = snapshot;
    exportBtn.disabled = false;
    const h = snapshot.health;
    if (snapshot.view && typeof snapshot.view.banner_text === 'string') {
      bannerEl.hidden = Boolean(snapshot.view.banner_hidden);
      bannerEl.className = 'banner';
      bannerEl.textContent = snapshot.view.banner_text;
    } else if (!h.logged_in || !h.org_access_ok) {
      bannerEl.hidden = false;
      bannerEl.className = 'banner';
      bannerEl.textContent = !h.logged_in
        ? `gh CLI is not authenticated: ${h.message}`
        : `gh CLI is logged in but cannot read runners: ${h.message}`;
    } else {
      bannerEl.hidden = true;
    }

    renderStats(snapshot);
    renderErrors(snapshot);
    snapshot.runners.forEach((r) => {
      if (r.just_flagged) showToast(`${r.agent_name || r.id} crashed and needs attention`);
      if (r.should_auto_restart && !r.auto_restart_paused) post('start', { runner: r.id }).catch(() => {});
    });

    const liveIds = new Set(snapshot.runners.map((r) => r.id));
    [...selectedRunners].forEach((id) => {
      if (!liveIds.has(id)) selectedRunners.delete(id);
    });

    const visible = visibleRows();
    const rowsKey = JSON.stringify({
      runners: snapshot.view && snapshot.view.rows_html ? snapshot.view.rows_html : snapshot.runners,
      filter: document.getElementById('runner-filter').value,
      state: document.getElementById('runner-state-filter').value,
      sort: sortState,
      selected: [...selectedRunners],
    });
    if (rowsKey !== lastRowsKey) {
      lastRowsKey = rowsKey;
      paintRows(rowsEl, snapshot, visible);
      updateSortIndicators();
    }
    applyDrainWaitUi();
    applyLiveJobs();
    applyLastJobs();
    const orphanPanel = document.getElementById('orphan-panel');
    if (orphanPanel && snapshot.view && typeof snapshot.view.orphans_html === 'string') {
      orphanPanel.innerHTML = snapshot.view.orphans_html;
      orphanPanel.hidden = snapshot.view.orphans_html === '';
    }
    updateBulkActionsBar(visible);
    const maintBtn = document.getElementById('btn-maintenance');
    if (maintBtn) {
      maintBtn.textContent = snapshot.maintenance ? 'Resume host' : 'Maintenance';
      maintBtn.dataset.help = snapshot.maintenance
        ? 'Allow this host to start runners again.'
        : 'Refuse new starts until you resume. Running slots keep going.';
    }
    lastUpdatedEl.textContent = snapshot.view && snapshot.view.updated
      ? snapshot.view.updated
      : `updated ${new Date(snapshot.generated_at * 1000).toLocaleTimeString()}`;
  }

  async function withLoading(btn, label, fn) {
    setLoading(btn, label);
    try {
      await fn();
    } finally {
      clearLoading(btn);
    }
  }

  function rerenderFromFilters() {
    if (lastSnapshot) render(lastSnapshot);
  }

  document.getElementById('runner-filter').addEventListener('input', rerenderFromFilters);
  document.getElementById('runner-state-filter').addEventListener('change', rerenderFromFilters);

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
    const res = await fetch('api.php?action=status&lines=5&view=1');
    if (redirectIfUnauthenticated(res.status)) return;
    render(await res.json());
  }

  function renderQueue(data) {
    const panel = document.getElementById('queue-panel');
    const countEl = document.getElementById('queue-count');
    const subEl = document.getElementById('queue-subtitle');
    const hintEl = document.getElementById('queue-hint');
    const hintBtn = document.getElementById('queue-hint-btn');
    const listEl = document.getElementById('queue-jobs');
    const errorEl = document.getElementById('queue-error');
    if (!panel || !countEl || !subEl || !hintEl || !hintBtn || !listEl || !errorEl) return;

    const jobs = Array.isArray(data.jobs) ? data.jobs : [];
    const count = Number(data.count) || jobs.length;
    const failed = data.ok === false;
    const show = failed || count > 0;
    panel.hidden = !show;
    if (!show) return;

    const label = data.label || 'self-hosted-runnerdeck';
    countEl.textContent = failed ? '—' : String(count);
    subEl.textContent = data.truncated
      ? `Waiting on ${label} (capped list)`
      : `Waiting on ${label}`;

    errorEl.hidden = !failed;
    errorEl.textContent = failed ? (data.message || 'Could not read queued jobs') : '';

    hintEl.hidden = !data.hint;
    hintEl.textContent = data.hint || '';
    if (data.hint_action === 'start') {
      hintBtn.hidden = false;
      hintBtn.textContent = 'Start idle slots';
      hintBtn.dataset.queueHint = 'start';
      hintBtn.classList.add('help-hover');
      hintBtn.dataset.help = 'Start stopped slots that can pick up these queued jobs.';
    } else if (data.hint_action === 'add') {
      hintBtn.hidden = false;
      hintBtn.textContent = 'Add runner';
      hintBtn.dataset.queueHint = 'add';
      hintBtn.classList.add('help-hover');
      hintBtn.dataset.help = 'Add a slot if this host can take another runner.';
    } else {
      hintBtn.hidden = true;
      delete hintBtn.dataset.queueHint;
    }

    lastLiveJobs = Array.isArray(data.live) ? data.live : [];
    lastRecentJobs = Array.isArray(data.recent) ? data.recent : [];
    applyLiveJobs();
    applyLastJobs();

    listEl.replaceChildren();
    jobs.forEach((job) => {
      const li = document.createElement('li');
      const title = document.createElement(job.html_url ? 'a' : 'span');
      title.textContent = job.name || 'job';
      if (job.html_url) {
        title.href = job.html_url;
        title.target = '_blank';
        title.rel = 'noopener noreferrer';
      }
      const meta = document.createElement('span');
      meta.className = 'muted';
      meta.textContent = [job.repo, job.workflow, job.waited].filter(Boolean).join(' · ');
      li.append(title, ' ', meta);
      listEl.append(li);
    });
  }

  async function fetchQueue() {
    try {
      const res = await fetch('api.php?action=queue');
      if (redirectIfUnauthenticated(res.status)) return;
      renderQueue(await res.json());
    } catch {
      /* keep last paint */
    }
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

  function applyLiveJobs() {
    const byName = {};
    lastLiveJobs.forEach((job) => {
      if (job && job.runner_name) byName[job.runner_name] = job;
    });
    rowsEl.querySelectorAll('tr[data-runner]').forEach((tr) => {
      let host = tr.querySelector('.live-job');
      if (!host) {
        const td = tr.children[2];
        if (!td) return;
        host = document.createElement('div');
        host.className = 'live-job';
        td.append(host);
      }
      const job = byName[tr.getAttribute('data-agent-name') || ''];
      host.replaceChildren();
      if (!job) {
        host.hidden = true;
        return;
      }
      host.hidden = false;
      const title = document.createElement(job.html_url ? 'a' : 'span');
      title.textContent = job.name || 'job';
      if (job.html_url) {
        title.href = job.html_url;
        title.target = '_blank';
        title.rel = 'noopener noreferrer';
      }
      const meta = document.createElement('span');
      meta.className = 'muted';
      meta.textContent = [job.workflow, job.duration, job.repo].filter(Boolean).join(' · ');
      host.append(title, ' ', meta);
    });
  }

  function applyLastJobs() {
    const byName = {};
    lastRecentJobs.forEach((job) => {
      if (!job || !job.runner_name) return;
      if (!byName[job.runner_name]) byName[job.runner_name] = [];
      if (byName[job.runner_name].length < 2) byName[job.runner_name].push(job);
    });
    rowsEl.querySelectorAll('tr[data-runner]').forEach((tr) => {
      let host = tr.querySelector('.last-jobs');
      if (!host) {
        const td = tr.children[2];
        if (!td) return;
        host = document.createElement('div');
        host.className = 'last-jobs';
        td.append(host);
      }
      const jobs = byName[tr.getAttribute('data-agent-name') || ''] || [];
      host.replaceChildren();
      if (!jobs.length) {
        host.hidden = true;
        return;
      }
      host.hidden = false;
      jobs.forEach((job) => {
        const line = document.createElement('div');
        const title = document.createElement(job.html_url ? 'a' : 'span');
        title.textContent = job.name || 'job';
        if (job.html_url) {
          title.href = job.html_url;
          title.target = '_blank';
          title.rel = 'noopener noreferrer';
        }
        const meta = document.createElement('span');
        meta.className = 'muted';
        meta.textContent = [job.conclusion, job.duration, job.repo].filter(Boolean).join(' · ');
        line.append(title, ' ', meta);
        host.append(line);
      });
    });
  }

  function drainTimeoutSeconds() {
    const n = Number(window.__DRAIN_TIMEOUT__);
    return Number.isFinite(n) && n >= 1 ? n : 600;
  }

  function drainTimeoutMs() {
    return drainTimeoutSeconds() * 1000;
  }

  function rollingParams(params) {
    return { ...params, timeout: String(drainTimeoutSeconds()) };
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

  const drainWait = new Map();
  let drainTick = null;

  function beginDrainWait(ids) {
    const deadline = Date.now() + drainTimeoutMs();
    ids.forEach((id) => drainWait.set(id, deadline));
    ensureDrainTick();
    applyDrainWaitUi();
  }

  function endDrainWait(ids) {
    ids.forEach((id) => drainWait.delete(id));
    ensureDrainTick();
    if (lastSnapshot) {
      lastRowsKey = '';
      render(lastSnapshot);
    }
  }

  function ensureDrainTick() {
    if (drainWait.size && !drainTick) {
      drainTick = window.setInterval(applyDrainWaitUi, 1000);
    }
    if (!drainWait.size && drainTick) {
      window.clearInterval(drainTick);
      drainTick = null;
    }
  }

  function applyDrainWaitUi() {
    drainWait.forEach((deadline, id) => {
      const tr = [...rowsEl.querySelectorAll('tr[data-runner]')].find((row) => row.dataset.runner === id);
      const btn = tr && tr.querySelector('[data-action="drain"]');
      if (!btn) return;
      const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
      const row = lastSnapshot ? lastSnapshot.runners.find((r) => r.id === id) : null;
      const busy = !row || !row.github || !!row.github.busy;
      btn.disabled = true;
      btn.textContent = busy ? `Busy · ${remaining}s` : `Idle · ${remaining}s`;
    });
  }

  function sleep(ms) {
    return new Promise((resolve) => {
      window.setTimeout(resolve, ms);
    });
  }

  async function drainThenStop(action, params, ids) {
    beginDrainWait(ids);
    try {
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
    } finally {
      endDrainWait(ids);
    }
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
      await drainThenStop('stop', { runner }, [runner]);
      return;
    }
    if (action === 'pause-auto-restart') {
      const row = lastSnapshot ? lastSnapshot.runners.find((r) => r.id === runner) : null;
      const paused = row && row.auto_restart_paused ? '0' : '1';
      await withLoading(btn, paused === '1' ? 'Pausing…' : 'Resuming…', async () => {
        const { data } = await post('pause_auto_restart', { runner, paused });
        if (!data.ok) showToast(data.message);
        await fetchStatus();
      });
      return;
    }
    if (action === 'rolling-restart') {
      await withLoading(btn, 'Rolling…', () =>
        runWithBusyGuard('rolling_restart', rollingParams({ runner })),
      );
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
    if (action === 'force-idle') {
      const name = tr.dataset.agentName || runner;
      const sure = await confirmModal(
        `Clear ${name} on GitHub? Local files stay. The next Start re-registers this slot.`,
      );
      if (!sure) return;
      await withLoading(btn, 'Clearing…', async () => {
        const { data } = await post('force_idle', { runner });
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
    withLoading(e.currentTarget, 'Refreshing…', async () => {
      await fetchStatus();
      await fetchQueue();
    });
  });

  document.getElementById('queue-hint-btn').addEventListener('click', () => {
    const action = document.getElementById('queue-hint-btn').dataset.queueHint;
    if (action === 'start') document.getElementById('btn-start-all').click();
    if (action === 'add') document.getElementById('btn-add-runner').click();
  });

  const orphanPanelEl = document.getElementById('orphan-panel');
  if (orphanPanelEl) {
    orphanPanelEl.addEventListener('click', async (e) => {
      const btn = e.target.closest('button[data-action="remove-orphan"]');
      if (!btn) return;
      const name = btn.getAttribute('data-name') || '';
      if (!name) return;
      await withLoading(btn, 'Removing…', () => runWithBusyGuard('remove_orphan', { name }));
    });
  }

  const appLogLevel = document.getElementById('app-log-level');
  if (appLogLevel) {
    appLogLevel.addEventListener('change', applyAppLogFilter);
  }
  const clearApplogBtn = document.getElementById('btn-clear-applog');
  if (clearApplogBtn) {
    clearApplogBtn.addEventListener('click', async (e) => {
      const sure = await confirmModal('Clear the operator log on this host? Rotated files are wiped too.');
      if (!sure) return;
      await withLoading(e.currentTarget, 'Clearing…', async () => {
        const { data } = await post('clear_applog');
        if (!data.ok) showToast(data.message);
        await fetchStatus();
      });
    });
  }

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

  document.getElementById('btn-rolling-restart-all').addEventListener('click', (e) => {
    const ids = lastSnapshot ? lastSnapshot.runners.map((r) => r.id) : [];
    if (!ids.length) return;
    withLoading(e.currentTarget, 'Rolling…', () =>
      runWithBusyGuard('bulk_rolling_restart', rollingParams({ runners: ids.join(',') })),
    );
  });

  document.getElementById('btn-rolling-upgrade').addEventListener('click', (e) => {
    withLoading(e.currentTarget, 'Upgrading…', () =>
      runWithBusyGuard('rolling_upgrade', rollingParams({})),
    );
  });

  document.getElementById('btn-maintenance').addEventListener('click', async (e) => {
    const on = !(lastSnapshot && lastSnapshot.maintenance);
    const msg = on
      ? 'Drain-stop every runner and refuse starts until you resume this host?'
      : 'Leave maintenance? Runners stay stopped until you start them.';
    const sure = await confirmModal(msg);
    if (!sure) return;
    withLoading(e.currentTarget, on ? 'Draining…' : 'Resuming…', async () => {
      await runWithBusyGuard('maintenance', rollingParams({ enabled: on ? '1' : '0' }));
    });
  });

  const safeRebootBtn = document.getElementById('btn-safe-reboot');
  if (safeRebootBtn) {
    safeRebootBtn.addEventListener('click', async (e) => {
      const sure = await confirmModal(
        'Drain-stop every runner so this machine is safe to reboot? This does not reboot for you.',
      );
      if (!sure) return;
      const ids = lastSnapshot ? lastSnapshot.runners.map((r) => r.id) : [];
      withLoading(e.currentTarget, 'Draining…', () =>
        drainThenStop('safe_reboot', rollingParams({}), ids),
      );
    });
  }

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

  document.getElementById('btn-bulk-rolling-restart').addEventListener('click', (e) => {
    const ids = [...selectedRunners];
    withLoading(e.currentTarget, 'Rolling…', () =>
      runWithBusyGuard('bulk_rolling_restart', rollingParams({ runners: ids.join(',') })),
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

  function renderErrors(snapshot) {
    const countEl = document.getElementById('app-log-count');
    const body = document.getElementById('app-log-body');
    if (!countEl || !body) return;
    const view = snapshot.view;
    if (view && typeof view.errors_html === 'string') {
      countEl.textContent = view.errors_count || '';
      body.innerHTML = view.errors_html;
      applyAppLogFilter();
      return;
    }
    const list = Array.isArray(snapshot.errors) ? snapshot.errors : [];
    countEl.textContent = list.length ? ` (${list.length})` : '';
    body.replaceChildren();
    if (!list.length) {
      const empty = document.createElement('p');
      empty.className = 'muted';
      empty.textContent = 'Nothing logged yet.';
      body.append(empty);
      return;
    }
    const ol = document.createElement('ol');
    ol.className = 'app-log-list';
    [...list].reverse().forEach((entry) => {
      const li = document.createElement('li');
      const level = entry.level === 'info' ? 'info' : 'error';
      li.className = `app-log-item app-log-${level}`;
      li.dataset.level = level;
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
    applyAppLogFilter();
  }

  function applyAppLogFilter() {
    const select = document.getElementById('app-log-level');
    const level = select ? select.value : 'all';
    document.querySelectorAll('#app-log-body .app-log-item').forEach((li) => {
      const itemLevel = li.dataset.level
        || (li.classList.contains('app-log-info') ? 'info' : 'error');
      li.hidden = level !== 'all' && itemLevel !== level;
    });
  }

  function formatErrorTime(iso) {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso || '';
    return d.toLocaleString();
  }

  let statusTimer = null;
  let queueTimer = null;

  function stopPolling() {
    if (statusTimer !== null) {
      clearInterval(statusTimer);
      statusTimer = null;
    }
    if (queueTimer !== null) {
      clearInterval(queueTimer);
      queueTimer = null;
    }
  }

  function startPolling() {
    stopPolling();
    statusTimer = setInterval(fetchStatus, POLL_MS);
    queueTimer = setInterval(fetchQueue, QUEUE_MS);
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      stopPolling();
      return;
    }
    fetchStatus();
    fetchQueue();
    startPolling();
  });

  const appLogDownload = document.getElementById('app-log-download');
  if (appLogDownload) {
    appLogDownload.addEventListener('click', (e) => e.stopPropagation());
  }

  initModals(fetchStatus);
  checkForUpdates();

  document.getElementById('crash-history-close').addEventListener('click', () => {
    document.getElementById('crash-history-modal').hidden = true;
  });

  if (window.__SNAPSHOT__) render(window.__SNAPSHOT__);
  else fetchStatus();
  fetchQueue();
  if (!document.hidden) startPolling();
}

initDashboard();
