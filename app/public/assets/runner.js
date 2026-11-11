import { post } from './js/api.js';
import { setLoading, clearLoading } from './js/utils.js';
import { confirmModal, openLogViewer, openRenameModal, initModals } from './js/modals.js';
import './js/theme.js';

const page = document.getElementById('runner-page');
if (page) {
  const runnerId = page.dataset.runner || '';

  function host() {
    return document.getElementById('runner-page');
  }

  function agentName() {
    return host()?.dataset.agentName || runnerId;
  }

  function note(text) {
    const el = document.getElementById('runner-note');
    if (el) el.textContent = text;
  }

  function drainTimeoutSeconds() {
    const n = Number(window.__DRAIN_TIMEOUT__);
    return Number.isFinite(n) && n >= 1 ? n : 600;
  }

  async function runnerRow() {
    const res = await fetch(`api.php?action=status&lines=200`);
    if (res.status === 401) {
      window.location.assign('index.php');
      return null;
    }
    const data = await res.json();
    return (data.runners || []).find((row) => row.id === runnerId) || null;
  }

  let logPlain = document.getElementById('runner-log')?.textContent || '';

  function paintLog(text) {
    logPlain = text;
    const pre = document.getElementById('runner-log');
    const query = document.getElementById('runner-log-search')?.value || '';
    const status = document.getElementById('runner-log-search-status');
    if (!pre) return;
    const scroll = pre.scrollTop;
    if (!query) {
      pre.textContent = text;
      if (status) status.textContent = '';
      pre.scrollTop = scroll;
      return;
    }
    const pattern = new RegExp(query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
    const fragment = document.createDocumentFragment();
    let offset = 0;
    let matches = 0;
    for (const match of text.matchAll(pattern)) {
      matches += 1;
      fragment.append(document.createTextNode(text.slice(offset, match.index)));
      const mark = document.createElement('mark');
      mark.textContent = match[0];
      fragment.append(mark);
      offset = match.index + match[0].length;
    }
    fragment.append(document.createTextNode(text.slice(offset)));
    pre.replaceChildren(fragment);
    if (status) status.textContent = matches ? '' : 'No matches';
    pre.scrollTop = scroll;
  }

  async function refreshLog() {
    const row = await runnerRow();
    const pre = document.getElementById('runner-log');
    if (!row || !pre || document.getElementById('log-modal')?.hidden === false) return;
    const lines = Array.isArray(row.log_tail) ? row.log_tail : [];
    const text = lines.join('\n').trim() || '(no log yet)';
    if (text !== logPlain) paintLog(text);
  }

  function fillJob(host, job) {
    if (!host) return;
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
    host.append(title, document.createTextNode(' '), meta);
  }

  function fillRecent(host, jobs) {
    if (!host) return;
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
      line.append(title, document.createTextNode(' '), meta);
      host.append(line);
    });
  }

  async function refreshQueue() {
    const res = await fetch('api.php?action=queue');
    if (res.status === 401) {
      window.location.assign('index.php');
      return;
    }
    const data = await res.json();
    const name = agentName();
    const live = (Array.isArray(data.live) ? data.live : [])
      .find((job) => job && job.runner_name === name) || null;
    const recent = (Array.isArray(data.recent) ? data.recent : [])
      .filter((job) => job && job.runner_name === name)
      .slice(0, 2);
    fillJob(document.querySelector('#runner-page .live-job'), live);
    fillRecent(document.querySelector('#runner-page .last-jobs'), recent);
  }

  async function refreshPage() {
    const current = host();
    const noteText = document.getElementById('runner-note')?.textContent || '';
    const log = document.getElementById('runner-log');
    const logScroll = log ? log.scrollTop : 0;
    const query = document.getElementById('runner-log-search')?.value || '';
    const res = await fetch(`runner.php?id=${encodeURIComponent(runnerId)}`, { cache: 'no-store' });
    if (res.status === 401 || res.redirected) {
      window.location.assign('index.php');
      return;
    }
    const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
    const next = doc.getElementById('runner-page');
    if (!next || !current) {
      window.location.assign('index.php');
      return;
    }
    current.replaceWith(next);
    const noteEl = document.getElementById('runner-note');
    if (noteEl && noteText) noteEl.textContent = noteText;
    const search = document.getElementById('runner-log-search');
    if (search) search.value = query;
    const newLog = document.getElementById('runner-log');
    paintLog(newLog ? newLog.textContent : '');
    if (newLog) newLog.scrollTop = logScroll;
    await refreshQueue();
  }

  async function finish(data, gone) {
    if (!data.ok) {
      note(data.message || 'The request failed');
      return false;
    }
    note(data.message || 'Done');
    if (gone) {
      window.location.assign('index.php');
      return true;
    }
    await refreshPage();
    return true;
  }

  async function withBusy(action, params) {
    let { status, data } = await post(action, params);
    if (status === 409) {
      const proceed = await confirmModal(data.message, 'Continue');
      if (!proceed) return false;
      ({ data } = await post(action, { ...params, force: '1' }));
    }
    return finish(data, action === 'delete_runner');
  }

  async function drainThenStop(btn) {
    const deadline = Date.now() + drainTimeoutSeconds() * 1000;
    setLoading(btn, 'Draining…');
    try {
      while (Date.now() < deadline) {
        const row = await runnerRow();
        if (!row || !row.github || !row.github.busy) break;
        await new Promise((resolve) => { window.setTimeout(resolve, 2000); });
      }
      const row = await runnerRow();
      if (row && row.github && row.github.busy) {
        const proceed = await confirmModal(
          'Still busy after waiting for the job to finish. Stop anyway and interrupt it?',
          'Stop anyway',
        );
        if (!proceed) return;
        await withBusy('stop', { runner: runnerId, force: '1' });
        return;
      }
      await withBusy('stop', { runner: runnerId });
    } finally {
      clearLoading(btn);
    }
  }

  document.addEventListener('click', async (event) => {
    const root = host();
    if (!root || !root.contains(event.target)) return;
    const btn = event.target.closest('button[data-action]');
    if (!btn || btn.disabled) return;
    const action = btn.dataset.action;

    if (action === 'view-log') {
      openLogViewer(runnerId);
      return;
    }
    if (action === 'crash-history') {
      const modal = document.getElementById('crash-history-modal');
      const title = document.getElementById('crash-history-title');
      const list = document.getElementById('crash-history-list');
      title.textContent = `${agentName()} — crashes (7 days)`;
      list.replaceChildren();
      let times = [];
      try {
        times = JSON.parse(host().dataset.crashes || '[]');
      } catch {
        times = [];
      }
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
      openRenameModal(runnerId, agentName());
      return;
    }
    if (action === 'start') {
      setLoading(btn, 'Starting…');
      try {
        const { data } = await post('start', { runner: runnerId });
        await finish(data, false);
      } catch (err) {
        note(err instanceof Error ? err.message : 'The request failed');
      } finally {
        clearLoading(btn);
      }
      return;
    }
    if (action === 'stop' || action === 'restart') {
      const stopping = action === 'stop';
      const sure = await confirmModal(
        stopping
          ? `Stop ${agentName()}? A job in progress is interrupted.`
          : `Restart ${agentName()}? A job in progress is interrupted.`,
        stopping ? 'Stop' : 'Restart',
      );
      if (!sure) return;
      setLoading(btn, stopping ? 'Stopping…' : 'Restarting…');
      try {
        await withBusy(action, { runner: runnerId });
      } catch (err) {
        note(err instanceof Error ? err.message : 'The request failed');
      } finally {
        clearLoading(btn);
      }
      return;
    }
    if (action === 'drain') {
      const sure = await confirmModal(
        `Drain ${agentName()}? It stops when GitHub reports it idle.`,
        'Drain',
      );
      if (!sure) return;
      try {
        await drainThenStop(btn);
      } catch (err) {
        note(err instanceof Error ? err.message : 'The request failed');
      }
      return;
    }
    if (action === 'pause-auto-restart') {
      const paused = host().dataset.paused === '1' ? '0' : '1';
      setLoading(btn, paused === '1' ? 'Pausing…' : 'Resuming…');
      try {
        const { data } = await post('pause_auto_restart', { runner: runnerId, paused });
        await finish(data, false);
      } catch (err) {
        note(err instanceof Error ? err.message : 'The request failed');
      } finally {
        clearLoading(btn);
      }
      return;
    }
    if (action === 'rolling-restart') {
      const sure = await confirmModal(
        `Drain and restart ${agentName()}? Other slots keep running.`,
        'Roll restart',
      );
      if (!sure) return;
      setLoading(btn, 'Rolling…');
      try {
        await withBusy('rolling_restart', {
          runner: runnerId,
          timeout: String(drainTimeoutSeconds()),
        });
      } catch (err) {
        note(err instanceof Error ? err.message : 'The request failed');
      } finally {
        clearLoading(btn);
      }
      return;
    }
    if (action === 'clear-work') {
      const sure = await confirmModal(
        `Clear _work on ${agentName()}? Checked-out job files on this slot are deleted.`,
        'Clear',
      );
      if (!sure) return;
      setLoading(btn, 'Clearing…');
      try {
        const { data } = await post('clear_work', { runner: runnerId });
        await finish(data, false);
      } catch (err) {
        note(err instanceof Error ? err.message : 'The request failed');
      } finally {
        clearLoading(btn);
      }
      return;
    }
    if (action === 'force-idle') {
      const sure = await confirmModal(
        `Clear ${agentName()} on GitHub? Local files stay. The next Start re-registers this slot.`,
        'Clear',
      );
      if (!sure) return;
      setLoading(btn, 'Clearing…');
      try {
        const { data } = await post('force_idle', { runner: runnerId });
        await finish(data, false);
      } catch (err) {
        note(err instanceof Error ? err.message : 'The request failed');
      } finally {
        clearLoading(btn);
      }
      return;
    }
    if (action === 'delete') {
      const sure = await confirmModal(
        `Permanently delete ${runnerId} (${agentName()})? This deregisters it from GitHub and deletes its local files, `
        + 'including logs. This cannot be undone.',
        'Delete',
      );
      if (!sure) return;
      setLoading(btn, 'Deleting…');
      try {
        await withBusy('delete_runner', { runner: runnerId });
      } catch (err) {
        note(err instanceof Error ? err.message : 'The request failed');
      } finally {
        clearLoading(btn);
      }
    }
  });

  document.addEventListener('input', (event) => {
    if (event.target?.id !== 'runner-log-search') return;
    paintLog(logPlain);
  });

  document.getElementById('crash-history-close').addEventListener('click', () => {
    document.getElementById('crash-history-modal').hidden = true;
  });

  initModals(() => {
    window.location.assign('index.php');
  });

  window.setInterval(() => {
    if (document.hidden) return;
    refreshLog().catch(() => {});
  }, 5000);
  window.setInterval(() => {
    if (document.hidden) return;
    refreshQueue().catch(() => {});
  }, 20000);
  refreshQueue().catch(() => {});
}
