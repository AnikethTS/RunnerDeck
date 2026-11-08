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

  function setBanner(text, hidden) {
    bannerEl.hidden = hidden;
    bannerEl.className = 'banner';
    if (hidden) return;
    let shell = bannerEl.querySelector('.page-shell');
    if (!shell) {
      bannerEl.replaceChildren();
      shell = document.createElement('div');
      shell.className = 'page-shell';
      bannerEl.append(shell);
    }
    shell.textContent = text;
  }

  function render(snapshot) {
    lastSnapshot = snapshot;
    exportBtn.disabled = false;
    const h = snapshot.health;
    if (snapshot.view && typeof snapshot.view.banner_text === 'string') {
      setBanner(snapshot.view.banner_text, Boolean(snapshot.view.banner_hidden));
    } else if (!h.logged_in || !h.org_access_ok) {
      setBanner(
        !h.logged_in
          ? `gh CLI is not authenticated: ${h.message}`
          : `gh CLI is logged in but cannot read runners: ${h.message}`,
        false,
      );
    } else {
      setBanner('', true);
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
      applyRowActivity();
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

  function setActionStatus(text) {
    const el = document.getElementById('action-status');
    if (!el) return;
    el.textContent = text || '';
    el.hidden = !text;
  }

  const rowActivity = new Map();

  function setRowActivity(id, phase, text) {
    const prev = rowActivity.get(id);
    if (prev && prev.timer) clearTimeout(prev.timer);
    const timer = phase === 'working'
      ? null
      : window.setTimeout(() => {
        rowActivity.delete(id);
        applyRowActivity();
      }, 7000);
    rowActivity.set(id, { phase, text, timer });
    applyRowActivity();
  }

  function setRowsActivity(ids, phase, text) {
    ids.forEach((id) => {
      const prev = rowActivity.get(id);
      if (prev && prev.timer) clearTimeout(prev.timer);
      const timer = phase === 'working'
        ? null
        : window.setTimeout(() => {
          rowActivity.delete(id);
          applyRowActivity();
        }, 7000);
      rowActivity.set(id, { phase, text, timer });
    });
    applyRowActivity();
  }

  function clearRowActivity(id) {
    const prev = rowActivity.get(id);
    if (prev && prev.timer) clearTimeout(prev.timer);
    rowActivity.delete(id);
    applyRowActivity();
  }

  function applyRowActivity() {
    rowsEl.querySelectorAll('tr[data-runner]').forEach((tr) => {
      tr.classList.remove('row-working', 'row-done', 'row-error');
      tr.removeAttribute('aria-busy');
      tr.querySelector('.row-activity')?.remove();
      const state = rowActivity.get(tr.dataset.runner);
      if (!state) return;
      tr.classList.add(state.phase === 'working' ? 'row-working' : state.phase === 'error' ? 'row-error' : 'row-done');
      if (state.phase === 'working') tr.setAttribute('aria-busy', 'true');
      const cell = tr.children[1];
      if (!cell) return;
      const note = document.createElement('div');
      note.className = 'row-activity';
      note.setAttribute('role', 'status');
      const mark = document.createElement('span');
      mark.className = 'row-activity-mark';
      mark.setAttribute('aria-hidden', 'true');
      const text = document.createElement('span');
      text.className = 'row-activity-text';
      text.textContent = state.text;
      note.append(mark, text);
      cell.append(note);
    });
  }

  function poolIds() {
    return lastSnapshot ? lastSnapshot.runners.map((r) => r.id) : [];
  }

  function reportResult(data, ids) {
    const failed = !data || !data.ok;
    const message = (data && data.message) || (failed ? 'The request failed' : 'Done');
    if (ids && ids.length) {
      setRowsActivity(ids, failed ? 'error' : 'done', message);
      return;
    }
    if (failed) showToast(message);
    else if (data && data.message) showToast(data.message, 'good');
  }

  async function withLoading(btn, label, fn, opts) {
    const local = Boolean(opts && opts.local);
    setLoading(btn, label);
    if (btn) btn.setAttribute('aria-busy', 'true');
    if (!local) setActionStatus(label);
    try {
      await fn();
    } catch (err) {
      if (local) throw err;
      showToast(err instanceof Error ? err.message : 'The request failed');
    } finally {
      clearLoading(btn);
      if (btn) btn.removeAttribute('aria-busy');
      if (!local) setActionStatus('');
    }
  }

  async function runRow(tr, btn, label, fn) {
    const id = tr.dataset.runner;
    setRowActivity(id, 'working', label);
    try {
      await withLoading(btn, label, fn, { local: true });
    } catch (err) {
      setRowActivity(id, 'error', err instanceof Error ? err.message : 'The request failed');
    }
  }

  async function runPool(btn, label, ids, fn) {
    setRowsActivity(ids, 'working', label);
    try {
      await withLoading(btn, label, fn, { local: true });
    } catch (err) {
      setRowsActivity(ids, 'error', err instanceof Error ? err.message : 'The request failed');
    }
  }

  const VIEW_KEY = 'runnerdeck-dashboard-view';
  const SORT_KEYS = new Set(['id', 'status', 'cpu', 'uptime', 'crashes', 'disk']);

  function saveView() {
    const filter = document.getElementById('runner-filter');
    const state = document.getElementById('runner-state-filter');
    try {
      localStorage.setItem(VIEW_KEY, JSON.stringify({
        q: filter ? filter.value : '',
        state: state ? state.value : '',
        sort: sortState.key,
        dir: sortState.dir,
      }));
    } catch {
      /* storage unavailable */
    }
  }

  function restoreView() {
    let pref = null;
    try {
      pref = JSON.parse(localStorage.getItem(VIEW_KEY) || 'null');
    } catch {
      pref = null;
    }
    if (!pref || typeof pref !== 'object') return;
    const filter = document.getElementById('runner-filter');
    const state = document.getElementById('runner-state-filter');
    if (filter && typeof pref.q === 'string') filter.value = pref.q;
    if (state && typeof pref.state === 'string' && [...state.options].some((option) => option.value === pref.state)) {
      state.value = pref.state;
    }
    if (typeof pref.sort === 'string' && SORT_KEYS.has(pref.sort)) {
      sortState.key = pref.sort;
      sortState.dir = pref.dir === -1 ? -1 : 1;
    }
  }

  restoreView();

  function rerenderFromFilters() {
    saveView();
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
      saveView();
      if (lastSnapshot) render(lastSnapshot);
    });
  });

  let statusFlight = null;

  function fetchStatus(opts) {
    const join = opts && opts.join;
    if (join && statusFlight) return statusFlight;
    const prev = statusFlight;
    const run = (async () => {
      if (prev) {
        await prev.catch(() => {});
      }
      const res = await fetch('api.php?action=status&lines=5&view=1');
      if (redirectIfUnauthenticated(res.status)) return;
      render(await res.json());
    })();
    statusFlight = run;
    void run.finally(() => {
      if (statusFlight === run) statusFlight = null;
    });
    return run;
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

  let queueFlight = null;

  function fetchQueue(opts) {
    const join = opts && opts.join;
    if (join && queueFlight) return queueFlight;
    const prev = queueFlight;
    const run = (async () => {
      if (prev) {
        try { await prev; } catch { /* keep last paint */ }
      }
      try {
        const res = await fetch('api.php?action=queue');
        if (redirectIfUnauthenticated(res.status)) return;
        renderQueue(await res.json());
      } catch {
        /* keep last paint */
      }
    })();
    queueFlight = run;
    void run.finally(() => {
      if (queueFlight === run) queueFlight = null;
    });
    return run;
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
          'Stop anyway',
        );
        if (!proceed) {
          ids.forEach((id) => clearRowActivity(id));
          return false;
        }
        return runWithBusyGuard(action, { ...params, force: '1' }, ids);
      }
      return runWithBusyGuard(action, params, ids);
    } finally {
      endDrainWait(ids);
    }
  }

  async function runWithBusyGuard(action, params, ids) {
    let { status, data } = await post(action, params);
    if (status === 409) {
      const proceed = await confirmModal(data.message, 'Continue');
      if (!proceed) return false;
      ({ data } = await post(action, { ...params, force: '1' }));
    }
    reportResult(data, ids);
    await fetchStatus();
    return true;
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
      await runRow(tr, btn, 'Starting…', async () => {
        const { data } = await post('start', { runner });
        reportResult(data, [runner]);
        await fetchStatus();
      });
      return;
    }
    if (action === 'stop' || action === 'restart') {
      const name = tr.dataset.agentName || runner;
      const stopping = action === 'stop';
      const sure = await confirmModal(
        stopping
          ? `Stop ${name}? A job in progress is interrupted.`
          : `Restart ${name}? A job in progress is interrupted.`,
        stopping ? 'Stop' : 'Restart',
      );
      if (!sure) return;
      await runRow(tr, btn, stopping ? 'Stopping…' : 'Restarting…', async () => {
        const kept = await runWithBusyGuard(action, { runner }, [runner]);
        if (kept === false) clearRowActivity(runner);
      });
      return;
    }
    if (action === 'drain') {
      const name = tr.dataset.agentName || runner;
      const sure = await confirmModal(
        `Drain ${name}? It stops when GitHub reports it idle.`,
        'Drain',
      );
      if (!sure) return;
      await runRow(tr, btn, 'Draining…', () => drainThenStop('stop', { runner }, [runner]));
      return;
    }
    if (action === 'pause-auto-restart') {
      const row = lastSnapshot ? lastSnapshot.runners.find((r) => r.id === runner) : null;
      const paused = row && row.auto_restart_paused ? '0' : '1';
      await runRow(tr, btn, paused === '1' ? 'Pausing…' : 'Resuming…', async () => {
        const { data } = await post('pause_auto_restart', { runner, paused });
        reportResult(data, [runner]);
        await fetchStatus();
      });
      return;
    }
    if (action === 'rolling-restart') {
      const name = tr.dataset.agentName || runner;
      const sure = await confirmModal(
        `Drain and restart ${name}? Other slots keep running.`,
        'Roll restart',
      );
      if (!sure) return;
      await runRow(tr, btn, 'Rolling…', async () => {
        const kept = await runWithBusyGuard('rolling_restart', rollingParams({ runner }), [runner]);
        if (kept === false) clearRowActivity(runner);
      });
      return;
    }
    if (action === 'clear-work') {
      const name = tr.dataset.agentName || runner;
      const sure = await confirmModal(
        `Clear _work on ${name}? Checked-out job files on this slot are deleted.`,
        'Clear',
      );
      if (!sure) return;
      await runRow(tr, btn, 'Clearing…', async () => {
        const { data } = await post('clear_work', { runner });
        reportResult(data, [runner]);
        await fetchStatus();
      });
      return;
    }
    if (action === 'force-idle') {
      const name = tr.dataset.agentName || runner;
      const sure = await confirmModal(
        `Clear ${name} on GitHub? Local files stay. The next Start re-registers this slot.`,
        'Clear',
      );
      if (!sure) return;
      await runRow(tr, btn, 'Clearing…', async () => {
        const { data } = await post('force_idle', { runner });
        reportResult(data, [runner]);
        await fetchStatus();
      });
      return;
    }
    if (action === 'delete') {
      const name = tr.dataset.agentName || runner;
      const sure = await confirmModal(
        `Permanently delete ${runner} (${name})? This deregisters it from GitHub and deletes its local files, `
        + 'including logs. This cannot be undone.',
        'Delete',
      );
      if (!sure) return;
      await runRow(tr, btn, 'Deleting…', async () => {
        const kept = await runWithBusyGuard('delete_runner', { runner }, [runner]);
        if (kept === false) clearRowActivity(runner);
      });
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
      const sure = await confirmModal(
        `Remove ${name} from GitHub? This host has no local slot for it.`,
        'Remove',
      );
      if (!sure) return;
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
      const sure = await confirmModal(
        'Clear the operator log on this host? Rotated files are wiped too.',
        'Clear',
      );
      if (!sure) return;
      await withLoading(e.currentTarget, 'Clearing…', async () => {
        const { data } = await post('clear_applog');
        reportResult(data);
        await fetchStatus();
      });
    });
  }

  document.getElementById('btn-start-all').addEventListener('click', (e) => {
    const count = document.getElementById('pool-size').value || 10;
    const ids = poolIds();
    runPool(e.currentTarget, 'Starting…', ids, async () => {
      const { data } = await post('start_all', { count });
      reportResult(data, ids);
      await fetchStatus();
    });
  });

  document.getElementById('btn-stop-all').addEventListener('click', async (e) => {
    const sure = await confirmModal(
      'Stop every runner now? Jobs in progress are interrupted.',
      'Stop all',
    );
    if (!sure) return;
    const ids = poolIds();
    runPool(e.currentTarget, 'Stopping…', ids, async () => {
      const kept = await runWithBusyGuard('stop_all', {}, ids);
      if (kept === false) ids.forEach((id) => clearRowActivity(id));
    });
  });

  document.getElementById('btn-drain-all').addEventListener('click', async (e) => {
    const sure = await confirmModal(
      'Drain every runner? Each slot stops when GitHub reports it idle.',
      'Drain',
    );
    if (!sure) return;
    const ids = poolIds();
    runPool(e.currentTarget, 'Draining…', ids, () => drainThenStop('stop_all', {}, ids));
  });

  document.getElementById('btn-rolling-restart-all').addEventListener('click', async (e) => {
    const ids = poolIds();
    if (!ids.length) return;
    const sure = await confirmModal(
      'Drain and restart every slot, one at a time?',
      'Roll restart',
    );
    if (!sure) return;
    runPool(e.currentTarget, 'Rolling…', ids, async () => {
      const kept = await runWithBusyGuard('bulk_rolling_restart', rollingParams({ runners: ids.join(',') }), ids);
      if (kept === false) ids.forEach((id) => clearRowActivity(id));
    });
  });

  document.getElementById('btn-rolling-upgrade').addEventListener('click', async (e) => {
    const sure = await confirmModal(
      'Replace each slot’s runner package with GitHub’s current download, one at a time?',
      'Upgrade',
    );
    if (!sure) return;
    const ids = poolIds();
    runPool(e.currentTarget, 'Upgrading…', ids, async () => {
      const kept = await runWithBusyGuard('rolling_upgrade', rollingParams({}), ids);
      if (kept === false) ids.forEach((id) => clearRowActivity(id));
    });
  });

  document.getElementById('btn-maintenance').addEventListener('click', async (e) => {
    const on = !(lastSnapshot && lastSnapshot.maintenance);
    const msg = on
      ? 'Drain-stop every runner and refuse starts until you resume this host?'
      : 'Leave maintenance? Runners stay stopped until you start them.';
    const sure = await confirmModal(msg, on ? 'Drain' : 'Resume');
    if (!sure) return;
    const ids = poolIds();
    runPool(e.currentTarget, on ? 'Draining…' : 'Resuming…', ids, async () => {
      const kept = await runWithBusyGuard('maintenance', rollingParams({ enabled: on ? '1' : '0' }), ids);
      if (kept === false) ids.forEach((id) => clearRowActivity(id));
    });
  });

  const safeRebootBtn = document.getElementById('btn-safe-reboot');
  if (safeRebootBtn) {
    safeRebootBtn.addEventListener('click', async (e) => {
      const sure = await confirmModal(
        'Drain-stop every runner so this machine is safe to reboot? This does not reboot for you.',
        'Drain',
      );
      if (!sure) return;
      const ids = poolIds();
      runPool(e.currentTarget, 'Draining…', ids, () =>
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
    const ids = [...selectedRunners];
    runPool(e.currentTarget, 'Starting…', ids, async () => {
      const { data } = await post('bulk_start', { runners: ids.join(',') });
      reportResult(data, ids);
      await fetchStatus();
    });
  });

  document.getElementById('btn-bulk-stop').addEventListener('click', async (e) => {
    const count = selectedRunners.size;
    const sure = await confirmModal(
      `Stop ${count} runner${count === 1 ? '' : 's'}? Jobs in progress are interrupted.`,
      'Stop',
    );
    if (!sure) return;
    const ids = [...selectedRunners];
    runPool(e.currentTarget, 'Stopping…', ids, async () => {
      const kept = await runWithBusyGuard('bulk_stop', { runners: ids.join(',') }, ids);
      if (kept === false) ids.forEach((id) => clearRowActivity(id));
    });
  });

  document.getElementById('btn-bulk-drain').addEventListener('click', async (e) => {
    const ids = [...selectedRunners];
    const sure = await confirmModal(
      `Drain ${ids.length} runner${ids.length === 1 ? '' : 's'}? Each stops when GitHub reports it idle.`,
      'Drain',
    );
    if (!sure) return;
    runPool(
      e.currentTarget,
      'Draining…',
      ids,
      () => drainThenStop('bulk_stop', { runners: ids.join(',') }, ids),
    );
  });

  document.getElementById('btn-bulk-rolling-restart').addEventListener('click', async (e) => {
    const ids = [...selectedRunners];
    const sure = await confirmModal(
      `Drain and restart ${ids.length} slot${ids.length === 1 ? '' : 's'}, one at a time?`,
      'Roll restart',
    );
    if (!sure) return;
    runPool(e.currentTarget, 'Rolling…', ids, async () => {
      const kept = await runWithBusyGuard('bulk_rolling_restart', rollingParams({ runners: ids.join(',') }), ids);
      if (kept === false) ids.forEach((id) => clearRowActivity(id));
    });
  });

  document.getElementById('btn-bulk-delete').addEventListener('click', async (e) => {
    const count = selectedRunners.size;
    const sure = await confirmModal(
      `Permanently delete ${count} runner${count === 1 ? '' : 's'}? This deregisters `
      + 'each from GitHub and deletes its local files, including logs. This cannot be undone.',
      'Delete',
    );
    if (!sure) return;
    const ids = [...selectedRunners];
    await runPool(e.currentTarget, 'Deleting…', ids, async () => {
      const kept = await runWithBusyGuard('bulk_delete', { runners: ids.join(',') }, ids);
      if (kept === false) ids.forEach((id) => clearRowActivity(id));
      else selectedRunners.clear();
    });
  });

  document.getElementById('pool-size-form').addEventListener('submit', (e) => {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    withLoading(btn, 'Applying…', async () => {
      const { data } = await post('resize', { count: document.getElementById('pool-size').value });
      reportResult(data);
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
    statusTimer = setInterval(() => { fetchStatus({ join: true }); }, POLL_MS);
    queueTimer = setInterval(() => { fetchQueue({ join: true }); }, QUEUE_MS);
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

  initModals(fetchStatus, { note: setRowActivity, clear: clearRowActivity });

  document.getElementById('crash-history-close').addEventListener('click', () => {
    document.getElementById('crash-history-modal').hidden = true;
  });

  if (window.__SNAPSHOT__) render(window.__SNAPSHOT__);
  fetchStatus()
    .catch(() => {})
    .then(() => fetchQueue())
    .then(() => checkForUpdates())
    .catch(() => {});
  if (!document.hidden) startPolling();
}

initDashboard();
