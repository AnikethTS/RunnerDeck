function loadClass(percent) {
  if (percent == null) return '';
  if (percent > 85) return 'stat-critical';
  if (percent > 60) return 'stat-warning';
  return '';
}

function formatDiskKb(kb) {
  if (kb == null) return '—';
  if (kb >= 1024 * 1024) return `${(kb / (1024 * 1024)).toFixed(1)} GB`;
  if (kb >= 1024) return `${Math.round(kb / 1024)} MB`;
  return `${kb} KB`;
}

export function renderLoadStat(sys) {
  if (!sys) return;

  const cpuEl = document.getElementById('stat-sys-cpu');
  cpuEl.textContent = sys.cpu_percent != null ? `${sys.cpu_percent.toFixed(1)}%` : '—';
  cpuEl.className = `stat-value ${loadClass(sys.cpu_percent)}`;
  document.getElementById('stat-sys-cpu-sub').textContent = `${sys.cpu_cores} core${sys.cpu_cores === 1 ? '' : 's'}, whole machine`;

  const memEl = document.getElementById('stat-sys-mem');
  memEl.textContent = sys.mem_percent != null ? `${sys.mem_percent.toFixed(0)}%` : '—';
  memEl.className = `stat-value ${loadClass(sys.mem_percent)}`;
  const usedMb = (sys.mem_used_kb / 1024).toFixed(0);
  const totalMb = sys.mem_total_kb != null ? (sys.mem_total_kb / 1024).toFixed(0) : null;
  document.getElementById('stat-sys-mem-sub').textContent = totalMb != null
    ? `${usedMb} of ${totalMb} MB`
    : `${usedMb} MB used, total unknown`;
}

export function renderHistory(snapshot) {
  const history = snapshot.history;
  if (!history) return;
  const cpuChart = document.getElementById('chart-cpu');
  const memChart = document.getElementById('chart-mem');
  if (cpuChart) cpuChart.innerHTML = history.cpu_svg;
  if (memChart) memChart.innerHTML = history.mem_svg;
}

export function renderStats(snapshot) {
  const cards = snapshot.view && snapshot.view.stats;
  if (cards) {
    document.getElementById('stat-active').textContent = cards.active;
    document.getElementById('stat-active-sub').textContent = cards.active_sub;
    const githubEl = document.getElementById('stat-github');
    githubEl.textContent = cards.github;
    githubEl.className = cards.github_class;
    document.getElementById('stat-github-sub').textContent = cards.github_sub;
    document.getElementById('stat-cpu').textContent = cards.cpu;
    document.getElementById('stat-cpu-sub').textContent = cards.cpu_sub;
    document.getElementById('stat-mem').textContent = cards.mem;
    document.getElementById('stat-mem-sub').textContent = cards.mem_sub;
    const diskEl = document.getElementById('stat-pool-disk');
    if (diskEl) {
      diskEl.textContent = cards.pool_disk;
      document.getElementById('stat-pool-disk-sub').textContent = cards.pool_disk_sub;
    }
    const cpuEl = document.getElementById('stat-sys-cpu');
    cpuEl.textContent = cards.sys_cpu;
    cpuEl.className = cards.sys_cpu_class;
    document.getElementById('stat-sys-cpu-sub').textContent = cards.sys_cpu_sub;
    const memEl = document.getElementById('stat-sys-mem');
    memEl.textContent = cards.sys_mem;
    memEl.className = cards.sys_mem_class;
    document.getElementById('stat-sys-mem-sub').textContent = cards.sys_mem_sub;
    renderHistory(snapshot);
    return;
  }

  const s = snapshot.stats;
  document.getElementById('stat-active').textContent = String(s.running);
  document.getElementById('stat-active-sub').textContent = `of ${s.total} configured`;

  const h = snapshot.health;
  const connected = h.logged_in && h.org_access_ok;
  const githubEl = document.getElementById('stat-github');
  githubEl.textContent = connected ? 'Connected' : 'Issue';
  githubEl.className = `stat-value ${connected ? 'stat-good' : 'stat-critical'}`;
  document.getElementById('stat-github-sub').textContent = connected ? 'org/repo reachable' : h.message;

  document.getElementById('stat-cpu').textContent = s.avg_cpu_percent != null ? `${s.avg_cpu_percent.toFixed(1)}%` : '—';
  document.getElementById('stat-cpu-sub').textContent = s.running ? `across ${s.running} running` : 'no runners active';

  const mb = s.total_rss_kb != null ? (s.total_rss_kb / 1024).toFixed(0) : null;
  document.getElementById('stat-mem').textContent = mb != null ? `${mb} MB` : '—';
  document.getElementById('stat-mem-sub').textContent = s.running ? `across ${s.running} running` : 'no runners active';

  const diskEl = document.getElementById('stat-pool-disk');
  if (diskEl) {
    diskEl.textContent = formatDiskKb(s.total_disk_kb);
    document.getElementById('stat-pool-disk-sub').textContent = s.total
      ? `across ${s.total} slot${s.total === 1 ? '' : 's'}`
      : 'no slots';
  }

  renderLoadStat(snapshot.system);
  renderHistory(snapshot);
}
