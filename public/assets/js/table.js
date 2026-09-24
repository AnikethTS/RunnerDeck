import { escapeHtml, badge, miniBar, formatUptime } from './utils.js';

export const sortState = { key: null, dir: 1 };
export const selectedRunners = new Set();

export function filteredRunners(runners) {
  const q = (document.getElementById('runner-filter').value || '').trim().toLowerCase();
  if (!q) return runners;
  return runners.filter((r) => r.id.toLowerCase().includes(q) || r.agent_name.toLowerCase().includes(q));
}

function sortValue(runner, key) {
  if (key === 'id') return runner.id;
  if (key === 'status') return runner.github ? (runner.github.busy ? 2 : runner.github.status === 'online' ? 1 : 0) : -1;
  if (key === 'cpu') return runner.cpu_percent ?? -1;
  if (key === 'uptime') return runner.uptime_seconds ?? -1;
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

function githubBadges(gh) {
  if (!gh) return badge('unknown', 'muted');
  const statusCls = gh.status === 'online' ? 'good' : 'critical';
  return `${badge(gh.status, statusCls)} ${badge(gh.busy ? 'busy' : 'idle', gh.busy ? 'warning' : 'good')}`;
}

function githubLabels(gh) {
  if (!gh || !gh.labels || !gh.labels.length) return '';
  const chips = gh.labels.map((l) => `<span class="label-chip">${escapeHtml(l)}</span>`).join('');
  return `<div class="label-chips">${chips}</div>`;
}

function localBadge(runner) {
  if (!runner.configured) return badge('not configured', 'muted');
  if (runner.local_running) return badge(`running (pid ${runner.pid})`, 'good');
  return badge('stopped', 'critical');
}

function resourceUsage(runner) {
  if (!runner.local_running || runner.cpu_percent == null || runner.rss_kb == null) return '';
  const mb = (runner.rss_kb / 1024).toFixed(0);
  const uptime = formatUptime(runner.uptime_seconds);
  const uptimeText = uptime ? ` &middot; up ${uptime}` : '';
  return `<span class="resource-usage">${miniBar(runner.cpu_percent)}${runner.cpu_percent.toFixed(1)}% CPU &middot; ${mb} MB${uptimeText}</span>`;
}

function flag(runner, key, text, cls) {
  return runner[key] ? `<div class="row-flag">${badge(text, cls)}</div>` : '';
}

function crashHistoryBadge(runner) {
  const n = runner.crash_count_7d;
  if (!n) return '';
  const text = n === 1 ? '1 crash (7d)' : `${n} crashes (7d)`;
  return `<div class="row-flag">${badge(text, 'warning')}</div>`;
}

export function rowHtml(runner) {
  const logPreview = (runner.log_tail || []).join('\n') || '(no log yet)';
  const checked = selectedRunners.has(runner.id) ? 'checked' : '';
  return `
    <tr data-runner="${runner.id}" data-agent-name="${escapeHtml(runner.agent_name)}">
      <td class="select-col">
        <input type="checkbox" class="row-select" data-runner="${runner.id}" ${checked} />
      </td>
      <td>
        <span class="runner-name">${escapeHtml(runner.id)}</span>
        <span class="agent-name">${escapeHtml(runner.agent_name)}</span>
      </td>
      <td>${githubBadges(runner.github)}${githubLabels(runner.github)}${flag(runner, 'mismatch_flagged', 'local/GitHub status disagree', 'warning')}</td>
      <td>${localBadge(runner)}${resourceUsage(runner)}${flag(runner, 'crash_flagged', 'crashed unexpectedly', 'critical')}${crashHistoryBadge(runner)}</td>
      <td>
        <pre class="log-preview">${escapeHtml(logPreview)}</pre>
        <button class="log-link" data-action="view-log">View live log &rarr;</button>
      </td>
      <td>
        <div class="row-actions">
          <button class="btn btn-sm btn-good" data-action="start" ${runner.local_running ? 'disabled' : ''}>Start</button>
          <button class="btn btn-sm btn-critical" data-action="stop" ${runner.local_running ? '' : 'disabled'}>Stop</button>
          <button class="btn btn-sm" data-action="restart">Restart</button>
          <button class="btn btn-sm" data-action="rename">Rename</button>
          <button class="btn btn-sm btn-critical" data-action="delete">Delete</button>
        </div>
      </td>
    </tr>`;
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
