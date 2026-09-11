export function renderChart(svg, values, min, max, unavailable) {
  if (unavailable) {
    svg.innerHTML = '<text x="8" y="34" font-size="10" fill="currentColor" opacity="0.5">unavailable — php-pdo_sqlite not installed</text>';
    return;
  }
  if (values.length < 2) {
    svg.innerHTML = '<text x="8" y="34" font-size="10" fill="currentColor" opacity="0.5">collecting data…</text>';
    return;
  }
  const w = 300;
  const h = 60;
  const range = (max - min) || 1;
  const step = w / (values.length - 1);
  const points = values
    .map((v, i) => `${(i * step).toFixed(1)},${(h - ((v - min) / range) * h).toFixed(1)}`)
    .join(' ');
  svg.innerHTML = `<polyline points="${points}" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" />`;
}

export async function fetchHistory() {
  const res = await fetch('api.php?action=history');
  const data = await res.json();
  if (!data.ok) return;
  const cpuChart = document.getElementById('chart-cpu');
  const memChart = document.getElementById('chart-mem');
  if (!cpuChart || !memChart) return;

  const unavailable = data.available === false;
  renderChart(cpuChart, data.samples.map((s) => s.avg_cpu), 0, 100, unavailable);
  const memValues = data.samples.map((s) => s.total_rss_kb / 1024);
  renderChart(memChart, memValues, 0, Math.max(10, ...memValues), unavailable);
}
