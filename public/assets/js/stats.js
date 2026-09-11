function loadClass(percent) {
  if (percent == null) return '';
  if (percent > 85) return 'stat-critical';
  if (percent > 60) return 'stat-warning';
  return '';
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

export function renderStats(snapshot) {
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

  renderLoadStat(snapshot.system);
}
