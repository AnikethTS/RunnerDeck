import { test, expect } from '@playwright/test';
import { copyFile, readFile } from 'node:fs/promises';

// This suite drives the real PHP backend (php -S) and the real app.js in a
// real browser. It does NOT talk to GitHub: `gh` isn't authenticated in CI,
// so GithubClient degrades to health.logged_in === false, same as any fresh
// machine. Runner data for the richer UI tests (filter/sort/bulk/mismatch)
// is supplied by mocking `action=status` responses at the network level;
// `action=queue` is mocked empty so tests never walk live GitHub jobs
// (`view.rows_html` is the PHP table markup the UI swaps in) —
// see mockStatus() below. Mismatch badges require `mismatch_flagged: true`
// on that payload (the streak itself is counted in PHP). First-run setup and Settings, by contrast, hit
// the real Settings::save() code path with no mocking at all.
//
// Tests run serially in this one file: the first test performs real
// first-run setup, and everything after it depends on that configured
// state persisting across page loads (it does — Settings::save() writes
// storage/settings.json for real, per RUNNERDECK_SETTINGS_FILE).
test.describe.configure({ mode: 'serial' });

test.beforeEach(async ({ page }) => {
  await page.route('**/api.php*action=queue*', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      ok: true,
      label: 'self-hosted-runnerdeck',
      count: 0,
      truncated: false,
      hint: '',
      hint_action: '',
      jobs: [],
      live: [],
      message: '',
    }),
  }));
});

function fixtureRunner(overrides = {}) {
  return {
    id: 'runner-base',
    configured: true,
    agent_name: 'e2e-runner-base',
    local_running: true,
    pid: 4242,
    log_tail: ['Listening for Jobs'],
    cpu_percent: 12.5,
    rss_kb: 204800,
    uptime_seconds: 305,
    github: { status: 'online', busy: false, labels: ['self-hosted-runnerdeck'], os: 'Linux' },
    ...overrides,
  };
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}

function fixtureRowHtml(r) {
  const id = escapeHtml(r.id);
  const name = escapeHtml(r.agent_name || '');
  const version = r.agent_version
    ? `<span class="agent-version">v${escapeHtml(r.agent_version)}</span>`
    : '';
  let github = '<span class="badge badge-muted">unknown</span>';
  if (r.github) {
    const st = r.github.status === 'online' ? 'good' : 'critical';
    github = `<span class="badge badge-${st}">${escapeHtml(r.github.status)}</span> `
      + `<span class="badge badge-${r.github.busy ? 'warning' : 'good'}">${r.github.busy ? 'busy' : 'idle'}</span>`;
    if (r.github.os) {
      github += ` <span class="os-chip">${escapeHtml(r.github.os)}</span>`;
    }
    if (r.github.labels && r.github.labels.length) {
      github += `<div class="label-chips">${r.github.labels.map((l) => `<span class="label-chip">${escapeHtml(l)}</span>`).join('')}</div>`;
    }
  }
  github += '<div class="live-job" hidden></div><div class="last-jobs" hidden></div>';
  if (r.mismatch_flagged) {
    github += '<div class="row-flag"><span class="badge badge-warning">local/GitHub status disagree</span></div>';
  }
  if (r.stuck_busy_flagged) {
    github += '<div class="row-flag"><span class="badge badge-critical">GitHub busy, process gone</span></div>';
  }
  let local = !r.configured
    ? '<span class="badge badge-muted">not configured</span>'
    : r.local_running
      ? `<span class="badge badge-good">running (pid ${r.pid})</span>`
      : '<span class="badge badge-critical">stopped</span>';
  if (r.disk_kb != null) {
    const text = r.disk_kb >= 1024 ? `${(r.disk_kb / 1024).toFixed(1)} MB disk` : `${r.disk_kb} KB disk`;
    local += `<span class="resource-usage disk-usage">${text}</span>`;
  }
  if (r.crash_flagged) {
    local += '<div class="row-flag"><span class="badge badge-critical">crashed unexpectedly</span></div>';
  }
  if (r.last_job_failed) {
    const job = r.last_job_name ? `last job failed: ${r.last_job_name}` : 'last job failed';
    local += `<div class="row-flag"><span class="badge badge-critical">${job}</span></div>`;
  }
  if (r.auto_restart_paused) {
    local += '<div class="row-flag"><span class="badge badge-warning">auto-restart paused</span></div>';
  }
  if (r.crash_count_7d) {
    const text = r.crash_count_7d === 1 ? '1 crash (7d)' : `${r.crash_count_7d} crashes (7d)`;
    local += `<div class="row-flag"><button type="button" class="crash-history-btn" data-action="crash-history">${text}</button></div>`;
  }
  const startDis = r.local_running ? ' disabled' : '';
  const stopDis = r.local_running ? '' : ' disabled';
  const drainDis = r.local_running ? '' : ' disabled';
  const clearDis = r.can_clear_work ? '' : ' disabled';
  const log = escapeHtml((r.log_tail || []).join('\n') || '(no log yet)');
  return `<tr data-runner="${id}" data-agent-name="${name}">
    <td class="select-col"><input type="checkbox" class="row-select" data-runner="${id}" /></td>
    <td><span class="runner-name">${id}</span><span class="agent-name">${name}</span>${version}</td>
    <td>${github}</td>
    <td>${local}</td>
    <td><pre class="log-preview">${log}</pre><button class="log-link" data-action="view-log">View live log</button></td>
    <td><div class="row-actions">
      <button class="btn btn-sm btn-good" data-action="start"${startDis}>Start</button>
      <button class="btn btn-sm btn-critical" data-action="stop"${stopDis}>Stop</button>
      <button class="btn btn-sm" data-action="drain"${drainDis}>Drain</button>
      <button class="btn btn-sm" data-action="restart">Restart</button>
      <button class="btn btn-sm" data-action="rolling-restart">Roll restart</button>
      <button class="btn btn-sm" data-action="pause-auto-restart">${r.auto_restart_paused ? 'Resume AR' : 'Pause AR'}</button>
      <button class="btn btn-sm" data-action="rename">Rename</button>
      <button class="btn btn-sm" data-action="clear-work"${clearDis}>Clear work</button>
      <button class="btn btn-sm btn-critical" data-action="force-idle"${r.stuck_busy_flagged ? '' : ' hidden'}>Force idle</button>
      <button class="btn btn-sm btn-critical" data-action="delete">Delete</button>
    </div></td>
  </tr>`;
}

function fixtureSnapshot(runners) {
  const running = runners.filter((r) => r.local_running);
  return {
    generated_at: Math.floor(Date.now() / 1000),
    health: { logged_in: false, org_access_ok: false, message: 'not logged in' },
    runners,
    stats: {
      total: runners.length,
      running: running.length,
      avg_cpu_percent: running.length ? 12.5 : null,
      total_rss_kb: running.length ? 204800 : null,
      total_disk_kb: runners.some((r) => r.disk_kb != null)
        ? runners.reduce((sum, r) => sum + (r.disk_kb || 0), 0)
        : null,
    },
    view: { rows_html: runners.map(fixtureRowHtml).join('') },
  };
}

async function mockStatus(page, runners, extra = {}) {
  await page.route('**/api.php*action=status*', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ ...fixtureSnapshot(runners), ...extra }),
  }));
}

async function mockReadmeStatus(page, runners) {
  const snap = fixtureSnapshot(runners);
  snap.health = { logged_in: true, org_access_ok: true, message: '' };
  snap.system = {
    cpu_percent: 12.5,
    cpu_cores: 8,
    mem_percent: 20,
    mem_used_kb: 204800,
    mem_total_kb: 1024000,
  };
  snap.view.updated = 'updated 12:00:00 PM';
  snap.view.banner_text = '';
  snap.view.banner_hidden = true;
  await page.route('**/api.php*action=status*', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify(snap),
  }));
}

test('first-run setup configures the app and reaches the dashboard', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Set up RunnerDeck' })).toBeVisible();

  await page.locator('#setup-org').fill('e2e-org');
  await page.locator('#setup-form button[type="submit"]').click();

  await expect(page.locator('.stat-grid')).toBeVisible();
  await expect(page.locator('.org-tag')).toContainText('e2e-org');
  await expect(page.locator('header.topbar img.logo')).toBeVisible();
  await expect(page.locator('#chart-cpu-now')).toBeVisible();
  await expect(page.locator('#chart-cpu')).toBeVisible();
  await expect(page.locator('#hosting-warnings')).toHaveCount(0);
});

test('refresh renders runner rows from the API', async ({ page }) => {
  await mockStatus(page, [
    fixtureRunner({ id: 'runner-base', agent_name: 'e2e-runner-base' }),
    fixtureRunner({ id: 'runner-1', agent_name: 'e2e-runner-1', local_running: false, pid: null, cpu_percent: null, rss_kb: null, github: { status: 'offline', busy: false, labels: [] } }),
  ]);
  await page.goto('/');
  await page.locator('#btn-refresh').click();

  await expect(page.locator('tr[data-runner="runner-base"]')).toBeVisible();
  await expect(page.locator('tr[data-runner="runner-1"]')).toBeVisible();
  await expect(page.locator('#stat-active')).toHaveText('1');
});

test('the filter box narrows the table to matching runners', async ({ page }) => {
  await mockStatus(page, [
    fixtureRunner({ id: 'runner-base', agent_name: 'ci-worker' }),
    fixtureRunner({ id: 'runner-1', agent_name: 'deploy-box' }),
  ]);
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await expect(page.locator('tr[data-runner]')).toHaveCount(2);

  await page.locator('#runner-filter').fill('deploy');

  await expect(page.locator('tr[data-runner]')).toHaveCount(1);
  await expect(page.locator('tr[data-runner="runner-1"]')).toBeVisible();
});

test('the filter box matches GitHub labels', async ({ page }) => {
  await mockStatus(page, [
    fixtureRunner({ id: 'runner-base', agent_name: 'ci-worker', github: { status: 'online', busy: false, labels: ['self-hosted-runnerdeck'] } }),
    fixtureRunner({ id: 'runner-1', agent_name: 'deploy-box', github: { status: 'online', busy: false, labels: ['gpu'] } }),
  ]);
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await page.locator('#runner-filter').fill('gpu');
  await expect(page.locator('tr[data-runner]')).toHaveCount(1);
  await expect(page.locator('tr[data-runner="runner-1"]')).toBeVisible();
});

test('the state filter narrows idle, busy, crashed, and mismatch rows', async ({ page }) => {
  await mockStatus(page, [
    fixtureRunner({
      id: 'idle-one',
      agent_name: 'idle-one',
      github: { status: 'online', busy: false, labels: [] },
    }),
    fixtureRunner({
      id: 'busy-one',
      agent_name: 'busy-one',
      github: { status: 'online', busy: true, labels: [] },
    }),
    fixtureRunner({
      id: 'crash-one',
      agent_name: 'crash-one',
      local_running: false,
      pid: null,
      crash_flagged: true,
      github: { status: 'offline', busy: false, labels: [] },
    }),
    fixtureRunner({
      id: 'mismatch-one',
      agent_name: 'mismatch-one',
      mismatch_flagged: true,
      github: { status: 'offline', busy: false, labels: [] },
    }),
  ]);
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await expect(page.locator('tr[data-runner]')).toHaveCount(4);

  await page.locator('#runner-state-filter').selectOption('busy');
  await expect(page.locator('tr[data-runner]')).toHaveCount(1);
  await expect(page.locator('tr[data-runner="busy-one"]')).toBeVisible();

  await page.locator('#runner-state-filter').selectOption('idle');
  await expect(page.locator('tr[data-runner]')).toHaveCount(3);
  await expect(page.locator('tr[data-runner="busy-one"]')).toHaveCount(0);

  await page.locator('#runner-state-filter').selectOption('crashed');
  await expect(page.locator('tr[data-runner]')).toHaveCount(1);
  await expect(page.locator('tr[data-runner="crash-one"]')).toBeVisible();

  await page.locator('#runner-state-filter').selectOption('mismatch');
  await expect(page.locator('tr[data-runner]')).toHaveCount(1);
  await expect(page.locator('tr[data-runner="mismatch-one"]')).toBeVisible();

  await page.locator('#runner-state-filter').selectOption('');
  await page.locator('#runner-filter').fill('crash');
  await page.locator('#runner-state-filter').selectOption('crashed');
  await expect(page.locator('tr[data-runner]')).toHaveCount(1);
  await expect(page.locator('tr[data-runner="crash-one"]')).toBeVisible();
});

test('clicking a sortable column header reorders the table', async ({ page }) => {
  await mockStatus(page, [
    fixtureRunner({ id: 'runner-2', agent_name: 'zzz-last' }),
    fixtureRunner({ id: 'runner-1', agent_name: 'aaa-first' }),
  ]);
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await expect(page.locator('tr[data-runner]')).toHaveCount(2);

  await page.locator('th.sortable[data-sort="id"]').click();

  const ids = await page.locator('tr[data-runner]').evaluateAll(
    (rows) => rows.map((r) => r.getAttribute('data-runner')),
  );
  expect(ids).toEqual(['runner-1', 'runner-2']);
});

test('selecting runners shows the bulk-actions bar with an accurate count', async ({ page }) => {
  await mockStatus(page, [
    fixtureRunner({ id: 'runner-base', agent_name: 'e2e-runner-base' }),
    fixtureRunner({ id: 'runner-1', agent_name: 'e2e-runner-1' }),
  ]);
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await expect(page.locator('#bulk-actions-bar')).toBeHidden();

  await page.locator('tr[data-runner="runner-base"] input.row-select').check();
  await page.locator('tr[data-runner="runner-1"] input.row-select').check();
  await expect(page.locator('#bulk-actions-bar')).toBeVisible();
  await expect(page.locator('#bulk-actions-count')).toHaveText('2 selected');

  await page.locator('tr[data-runner="runner-1"] input.row-select').uncheck();
  await expect(page.locator('#bulk-actions-count')).toHaveText('1 selected');
});

test('a persistent local/GitHub status disagreement gets flagged', async ({ page }) => {
  // Streak counting lives in CrashState on the server. The UI just renders
  // mismatch_flagged from the status payload (this suite mocks that JSON).
  await mockStatus(page, [
    fixtureRunner({
      id: 'runner-base',
      agent_name: 'e2e-runner-base',
      local_running: false,
      pid: null,
      cpu_percent: null,
      rss_kb: null,
      github: { status: 'online', busy: false, labels: [] },
      mismatch_flagged: true,
    }),
  ]);
  await page.goto('/');
  await page.locator('#btn-refresh').click();

  await expect(page.locator('tr[data-runner="runner-base"] .row-flag')).toBeVisible();
  await expect(page.locator('tr[data-runner="runner-base"] .row-flag')).toContainText('disagree');
});

// https://github.com/AnikethTS/RunnerDeck/issues/41
test('JSON export includes filtered-out runners and uses the latest snapshot', async ({ page }) => {
  const runners = [
    fixtureRunner({ id: 'runner-base', agent_name: 'ci-worker' }),
    fixtureRunner({ id: 'runner-1', agent_name: 'deploy-box', github: { status: 'offline', busy: false, labels: ['custom-label'] } }),
  ];
  await mockStatus(page, runners);
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await page.locator('#runner-filter').fill('deploy');
  await expect(page.locator('tr[data-runner]')).toHaveCount(1);

  async function exportRunners() {
    const pending = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Export JSON' }).click();
    const download = await pending;
    expect(download.suggestedFilename()).toMatch(/^runnerdeck-runners-\d{4}-\d{2}-\d{2}T.*\.json$/);
    expect(await download.failure()).toBeNull();
    return JSON.parse(await readFile(await download.path(), 'utf8'));
  }

  expect(await exportRunners()).toEqual(runners);
  runners[0].cpu_percent = 55;
  runners.push(fixtureRunner({ id: 'runner-2', agent_name: 'new-worker' }));
  await page.locator('#btn-refresh').click();
  expect(await exportRunners()).toEqual(runners);
  await expect(page.locator('a[download^="runnerdeck-runners-"]')).toHaveCount(0);
});

test('JSON export supports an empty runner pool', async ({ page }) => {
  await mockStatus(page, []);
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  const pending = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Export JSON' }).click();
  const download = await pending;
  expect(await download.failure()).toBeNull();
  expect(JSON.parse(await readFile(await download.path(), 'utf8'))).toEqual([]);
});

// https://github.com/AnikethTS/RunnerDeck/issues/26
test('dashboard shortcuts focus the filter and reuse Refresh', async ({ page }) => {
  await mockStatus(page, []);
  await page.goto('/');
  await page.locator('#btn-refresh').focus();
  await page.keyboard.press('/');
  await expect(page.locator('#runner-filter')).toBeFocused();
  await expect(page.locator('#runner-filter')).toHaveValue('');
  await page.keyboard.type('/r');
  await expect(page.locator('#runner-filter')).toHaveValue('/r');
  await page.evaluate(() => {
    window.refreshClicks = 0;
    document.getElementById('btn-refresh').addEventListener('click', () => window.refreshClicks++);
  });
  await page.locator('#btn-refresh').focus();
  await page.keyboard.press('r');
  await expect.poll(() => page.evaluate(() => window.refreshClicks)).toBe(1);
});

test('shortcuts leave editable controls, modifiers and composition alone', async ({ page }) => {
  await mockStatus(page, []);
  await page.goto('/');
  const results = await page.evaluate(() => {
    let clicks = 0;
    document.getElementById('btn-refresh').addEventListener('click', () => clicks++);
    const results = [];
    for (const tag of ['input', 'textarea', 'select', 'div']) {
      const element = document.createElement(tag);
      if (tag === 'div') element.contentEditable = 'true';
      document.body.appendChild(element);
      element.focus();
      for (const key of ['/', 'r']) {
        const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
        element.dispatchEvent(event);
        results.push(!event.defaultPrevented && document.activeElement === element && clicks === 0);
      }
      element.remove();
    }
    const button = document.getElementById('btn-refresh');
    button.focus();
    for (const flag of ['ctrlKey', 'metaKey', 'altKey', 'isComposing', 'repeat']) {
      for (const key of ['/', 'r']) {
        const event = new KeyboardEvent('keydown', { key, [flag]: true, bubbles: true, cancelable: true });
        button.dispatchEvent(event);
        results.push(!event.defaultPrevented && document.activeElement === button && clicks === 0);
      }
    }
    return results;
  });
  expect(results).toHaveLength(18);
  expect(results.every(Boolean)).toBe(true);
});

// https://github.com/AnikethTS/RunnerDeck/issues/40
async function mockLogStream(page) {
  await page.addInitScript(() => {
    window.logStreams = [];
    window.EventSource = class {
      constructor(url) {
        this.url = url;
        this.closed = false;
        window.logStreams.push(this);
      }
      close() {
        this.closed = true;
        sessionStorage.setItem('log-stream-closed', '1');
      }
    };
  });
}

async function emitLogLine(page, line) {
  await page.evaluate((data) => window.logStreams.at(-1).onmessage({ data }), line);
}

test('log search highlights existing and streamed text, and resets on reopening', async ({ page }) => {
  await mockStatus(page, [fixtureRunner()]);
  await mockLogStream(page);
  await page.goto('/');
  const open = page.locator('[data-action="view-log"]');
  await open.click();
  const search = page.getByRole('searchbox', { name: 'Search log' });
  const body = page.locator('#log-modal-body');
  await expect(search).toBeVisible();
  await emitLogLine(page, 'ERROR: first error');
  await emitLogLine(page, 'ready');
  await search.fill('error');
  await expect(body.locator('mark')).toHaveText(['ERROR', 'error']);
  await emitLogLine(page, 'another Error');
  await expect(body.locator('mark')).toHaveText(['ERROR', 'error', 'Error']);
  await search.fill('ready');
  await expect(body.locator('mark')).toHaveText(['ready']);
  await search.fill('absent');
  await expect(body.locator('mark')).toHaveCount(0);
  await search.fill('');
  await expect(body).toHaveText('ERROR: first error\nready\nanother Error\n', { useInnerText: false });
  await expect(body.locator('mark')).toHaveCount(0);
  await search.fill('error');
  await page.locator('#log-modal-close').click();
  expect(await page.evaluate(() => window.logStreams[0].closed)).toBe(true);
  await open.click();
  await expect(search).toHaveValue('');
  await expect(body).toBeEmpty();
  await emitLogLine(page, 'new log');
  await expect(body).toHaveText('new log\n', { useInnerText: false });
});

// https://github.com/AnikethTS/RunnerDeck/issues/56
test('log stream stops retrying and redirects when the session expires', async ({ page }) => {
  await mockStatus(page, [fixtureRunner()]);
  await mockLogStream(page);
  await page.goto('/');
  await page.locator('[data-action="view-log"]').click();

  await page.unroute('**/api.php*action=status*');
  await page.route('**/api.php*action=status*', (route) => route.fulfill({
    status: 401,
    contentType: 'application/json',
    body: JSON.stringify({ ok: false, message: 'unauthorized' }),
  }));
  await page.route('**/login.php', (route) => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><title>Login</title>',
  }));

  await page.evaluate(() => window.logStreams.at(-1).onerror());
  await page.waitForURL('**/login.php');
  expect(await page.evaluate(() => sessionStorage.getItem('log-stream-closed'))).toBe('1');
});

test('log search treats markup and regex characters as literal text', async ({ page }) => {
  await mockStatus(page, [fixtureRunner()]);
  await mockLogStream(page);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');
  await page.locator('[data-action="view-log"]').click();
  const search = page.getByRole('searchbox', { name: 'Search log' });
  const body = page.locator('#log-modal-body');
  const line = '<img src=x onerror="window.logHtmlExecuted=true"> & [.*+?^${}()|\\] İ ERROR';
  await emitLogLine(page, line);
  for (const query of ['<img', '[.*+?^${}()|\\]', 'error']) {
    await search.fill(query);
    await expect(body.locator('mark')).toHaveText(query === 'error' ? ['error', 'ERROR'] : [query]);
    expect(await body.textContent()).toBe(line + '\n');
    await expect(body.locator('img, script')).toHaveCount(0);
    expect(await page.evaluate(() => window.logHtmlExecuted)).toBeUndefined();
  }
  const bounds = await search.boundingBox();
  expect(bounds.x).toBeGreaterThanOrEqual(0);
  expect(bounds.x + bounds.width).toBeLessThanOrEqual(390);
  await expect(page.locator('#log-modal-close')).toBeInViewport();
});

// https://github.com/AnikethTS/RunnerDeck/issues/45
test('Local process sorts independently by CPU and uptime, including absent values', async ({ page }) => {
  await mockStatus(page, [
    fixtureRunner({ id: 'long', uptime_seconds: 100, cpu_percent: 20 }),
    fixtureRunner({ id: 'zero', uptime_seconds: 0, cpu_percent: 90 }),
    fixtureRunner({ id: 'missing', uptime_seconds: undefined, cpu_percent: 30 }),
    fixtureRunner({ id: 'stopped', uptime_seconds: null, cpu_percent: null, local_running: false }),
    fixtureRunner({ id: 'short', uptime_seconds: 9, cpu_percent: 10 }),
  ]);
  await page.goto('/');
  const uptime = page.getByRole('button', { name: 'Sort by uptime', exact: true });
  const cpu = page.getByRole('button', { name: 'Sort by CPU', exact: true });
  await page.locator('#btn-refresh').click();
  await expect(page.locator('tr[data-runner]')).toHaveCount(5);
  const rows = () => page.locator('tr[data-runner]').evaluateAll((els) => els.map((el) => el.dataset.runner));
  await uptime.click();
  expect(await rows()).toEqual(['missing', 'stopped', 'zero', 'short', 'long']);
  await expect(uptime.locator('.sort-caret')).toHaveText('▲');
  await expect(cpu.locator('.sort-caret')).toBeEmpty();
  await expect(uptime.locator('xpath=ancestor::th')).toHaveAttribute('aria-sort', 'ascending');
  await uptime.press('Enter');
  expect(await rows()).toEqual(['long', 'short', 'zero', 'missing', 'stopped']);
  await expect(uptime.locator('.sort-caret')).toHaveText('▼');
  await cpu.click();
  expect(await rows()).toEqual(['stopped', 'short', 'long', 'missing', 'zero']);
  await expect(cpu.locator('.sort-caret')).toHaveText('▲');
  await expect(uptime.locator('.sort-caret')).toBeEmpty();
  await cpu.press('Space');
  expect(await rows()).toEqual(['zero', 'missing', 'long', 'short', 'stopped']);
  await expect(cpu.locator('.sort-caret')).toHaveText('▼');
  await page.locator('th[data-sort="id"]').click();
  expect(await rows()).toEqual(['long', 'missing', 'short', 'stopped', 'zero']);
  await expect(cpu.locator('.sort-caret')).toBeEmpty();
  await expect(page.locator('th[aria-sort]')).toHaveCount(1);
  await expect(page.locator('thead th')).toHaveCount(6);
});

test('settings page toggles scope fields and persists a change', async ({ page }) => {
  await page.goto('/settings.php');
  await expect(page.getByRole('tab', { name: 'GitHub' })).toBeVisible();
  await expect(page.locator('#settings-org-field')).toBeVisible();
  await expect(page.locator('#settings-github-app-id')).toBeVisible();
  await expect(page.locator('#settings-repo-field')).toBeHidden();

  await page.getByRole('tab', { name: 'Pool' }).click();
  await expect(page.locator('input[name="auto_clear_work"]')).toBeVisible();
  await expect(page.locator('#settings-slot-cpu')).toBeVisible();

  await page.getByRole('tab', { name: 'Alerts' }).click();
  await expect(page.locator('input[name="syslog"]')).toBeVisible();

  await page.getByRole('tab', { name: 'Access' }).click();
  await expect(page.locator('#settings-session-idle')).toBeVisible();

  await page.getByRole('tab', { name: 'Host' }).click();
  await expect(page.locator('#this-host')).toBeVisible();
  await expect(page.locator('#this-host')).toContainText('php -S');
  await expect(page.locator('a[href="download_bundle.php"]')).toBeVisible();
  await expect(page.locator('input[name="include_secrets"]')).toBeVisible();

  await page.getByRole('tab', { name: 'GitHub' }).click();
  await page.locator('#settings-scope').selectOption('repo');
  await expect(page.locator('#settings-org-field')).toBeHidden();
  await expect(page.locator('#settings-repo-field')).toBeVisible();

  await page.locator('#settings-repo').fill('e2e-org/e2e-repo');
  await page.locator('#settings-save').click();

  await expect(page).toHaveURL(/index\.php(?:#.*)?$/);
  await expect(page.locator('.org-tag')).toContainText('e2e-org/e2e-repo');
});

test('metrics.php serves prometheus text without github', async ({ request }) => {
  const res = await request.get('/metrics.php');
  expect(res.ok()).toBeTruthy();
  const body = await res.text();
  expect(body).toContain('runnerdeck_runners');
  expect(body).toContain('runnerdeck_webhook_outbox');
});

test('dashboard shows watch heartbeat banner from status view', async ({ page }) => {
  const runner = fixtureRunner();
  await mockStatus(page, [runner], {
    view: {
      rows_html: fixtureRowHtml(runner),
      banner_hidden: false,
      banner_text: 'watch-auto-restart.php has not ticked — auto-restart is idle.',
    },
  });
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await expect(page.locator('#health-banner')).toBeVisible();
  await expect(page.locator('#health-banner')).toContainText('watch-auto-restart.php');
});

test('settings page shows a validation error and keeps entered values', async ({ page }) => {
  await page.goto('/settings.php');
  await page.locator('#settings-scope').selectOption('org');
  await page.locator('#settings-org').fill('e2e-org');
  await page.getByRole('tab', { name: 'Alerts' }).click();
  // A scheme browsers accept as a valid URL (so native type="url" validation
  // doesn't block the submit) but that our own scheme allowlist rejects —
  // this exercises the server-side check, not the browser's.
  await page.locator('#settings-crash-webhook-url').fill('ftp://example.com');
  await page.locator('#settings-save').click();

  await expect(page).toHaveURL(/settings\.php(?:#.*)?$/);
  await expect(page.locator('.setup-error')).toContainText('crash webhook url must start with');
  await expect(page.locator('#settings-org')).toHaveValue('e2e-org');
});

test('settings page test-webhook button reports delivery failure', async ({ page }) => {
  await page.goto('/settings.php');
  await page.getByRole('tab', { name: 'Alerts' }).click();
  await page.locator('#settings-crash-webhook-url').fill('https://127.0.0.1:1/unreachable');
  await page.locator('#settings-test-webhook').click();

  await expect(page.locator('#settings-test-webhook-result')).toBeVisible();
  await expect(page.locator('#settings-test-webhook-result')).toContainText('Delivery failed');
  await expect(page.locator('#settings-test-webhook-result')).toHaveClass(/setup-error/);
});

test('settings page has a theme toggle that persists to the dashboard', async ({ page }) => {
  await page.goto('/settings.php');
  await expect(page.locator('#btn-theme-toggle')).toBeVisible();

  await page.locator('#btn-theme-toggle').click();
  const theme = await page.locator('html').getAttribute('data-theme');
  expect(theme).toMatch(/^(dark|light)$/);

  await page.goto('/');
  await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
  await expect(page.locator('#btn-theme-toggle')).toBeVisible();
});

test('dashboard shows recent errors from the status snapshot', async ({ page }) => {
  await mockStatus(page, [fixtureRunner()], {
    errors: [{
      time: '2026-09-22T18:00:00+00:00',
      action: 'process.start',
      message: 'failed to start <runner-1>',
      runner: 'runner-1',
      stderr: 'nohup: failed',
    }],
  });
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await page.locator('#app-log').evaluate((el) => { el.open = true; });

  await expect(page.locator('#app-log summary')).toContainText('Recent log');
  await expect(page.locator('#app-log-download')).toHaveAttribute('href', 'download_applog.php');
  await expect(page.locator('#app-log-count')).toContainText('(1)');
  await expect(page.locator('#app-log-body')).toContainText('failed to start <runner-1>');
  await expect(page.locator('#app-log-body')).toContainText('process.start');
  await expect(page.locator('#app-log-body')).toContainText('nohup: failed');
  await expect(page.locator('#app-log-level')).toBeVisible();
  await expect(page.locator('#btn-clear-applog')).toBeVisible();
});

test('recent log level filter hides info lines', async ({ page }) => {
  await mockStatus(page, [fixtureRunner()], {
    errors: [
      {
        time: '2026-09-22T18:00:00+00:00',
        action: 'process.start',
        message: 'failed to start',
        level: 'error',
      },
      {
        time: '2026-09-22T18:01:00+00:00',
        action: 'process.stop',
        message: 'stopped ok',
        level: 'info',
      },
    ],
  });
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await page.locator('#app-log').evaluate((el) => { el.open = true; });
  await expect(page.locator('#app-log-body')).toContainText('failed to start');
  await expect(page.locator('#app-log-body')).toContainText('stopped ok');
  await page.locator('#app-log-level').selectOption('error');
  await expect(page.locator('#app-log-body .app-log-item[data-level="error"]')).toBeVisible();
  await expect(page.locator('#app-log-body .app-log-item[data-level="info"]')).toBeHidden();
});

test('github OS chip is on the row', async ({ page }) => {
  await mockStatus(page, [fixtureRunner()]);
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await expect(page.locator('tr[data-runner="runner-base"] .os-chip')).toHaveText('Linux');
});

test('a runner with recent crash history shows a count badge, a healthy one shows none', async ({ page }) => {
  await mockStatus(page, [
    fixtureRunner({ id: 'flaky', crash_count_7d: 3 }),
    fixtureRunner({ id: 'stable', crash_count_7d: 0 }),
  ]);
  await page.goto('/');
  await page.locator('#btn-refresh').click();

  const flakyRow = page.locator('tr[data-runner="flaky"]');
  const stableRow = page.locator('tr[data-runner="stable"]');
  await expect(flakyRow.getByText('3 crashes (7d)')).toBeVisible();
  await expect(stableRow.getByText(/crashes? \(7d\)/)).toHaveCount(0);
});

test('clicking the crash-history badge lists timestamps', async ({ page }) => {
  await mockStatus(page, [
    fixtureRunner({
      id: 'flaky',
      crash_count_7d: 2,
      crash_at_7d: [1700000000, 1699900000],
    }),
  ]);
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await page.locator('tr[data-runner="flaky"] button[data-action="crash-history"]').click();

  await expect(page.locator('#crash-history-modal')).toBeVisible();
  await expect(page.locator('#crash-history-list li')).toHaveCount(2);
});

test('crash count sort puts the flakiest runner first', async ({ page }) => {
  await mockStatus(page, [
    fixtureRunner({ id: 'stable', crash_count_7d: 0 }),
    fixtureRunner({ id: 'flaky', crash_count_7d: 4 }),
  ]);
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await page.locator('button.sortable[data-sort="crashes"]').click();
  await page.locator('button.sortable[data-sort="crashes"]').click();

  const ids = await page.locator('tr[data-runner]').evaluateAll(
    (rows) => rows.map((r) => r.getAttribute('data-runner')),
  );
  expect(ids[0]).toBe('flaky');
});

test('disk usage and drain/clear-work controls render', async ({ page }) => {
  await mockStatus(page, [
    fixtureRunner({
      id: 'fat',
      local_running: false,
      pid: null,
      disk_kb: 2048,
      can_clear_work: true,
    }),
  ]);
  await page.goto('/');
  await page.locator('#btn-refresh').click();

  await expect(page.getByText('2.0 MB disk')).toBeVisible();
  await expect(page.locator('#stat-pool-disk')).toHaveText('2 MB');
  await expect(page.locator('#stat-pool-disk-sub')).toHaveText('across 1 slot');
  await expect(page.locator('tr[data-runner="fat"] button[data-action="drain"]')).toBeDisabled();
  await expect(page.locator('tr[data-runner="fat"] button[data-action="clear-work"]')).toBeEnabled();
  await expect(page.locator('#btn-drain-all')).toBeVisible();
  await expect(page.locator('#btn-rolling-restart-all')).toBeVisible();
  await expect(page.locator('#btn-rolling-upgrade')).toBeVisible();
  await expect(page.locator('#btn-maintenance')).toBeVisible();
  await expect(page.locator('#btn-safe-reboot')).toBeVisible();
  await expect(page.locator('tr[data-runner="fat"] button[data-action="rolling-restart"]')).toBeVisible();
});

test('rolling restart posts the selected slots', async ({ page }) => {
  const runners = [
    fixtureRunner({ id: 'runner-1', agent_name: 'e2e-1' }),
    fixtureRunner({ id: 'runner-2', agent_name: 'e2e-2' }),
  ];
  await mockStatus(page, runners);
  const posts = [];
  await page.route('**/api.php*action=*rolling_restart*', async (route) => {
    posts.push({
      url: route.request().url(),
      body: route.request().postData() || '',
    });
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, message: 'restarted' }),
    });
  });
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await page.locator('tr[data-runner="runner-1"] button[data-action="rolling-restart"]').click();
  await expect.poll(() => posts.length).toBe(1);
  expect(posts[0].url).toContain('action=rolling_restart');
  expect(posts[0].body).toContain('runner=runner-1');
  expect(posts[0].body).toContain('timeout=');

  await page.locator('tr[data-runner="runner-1"] input.row-select').check();
  await page.locator('tr[data-runner="runner-2"] input.row-select').check();
  await page.locator('#btn-bulk-rolling-restart').click();
  await expect.poll(() => posts.length).toBe(2);
  expect(posts[1].url).toContain('action=bulk_rolling_restart');
  expect(posts[1].body).toMatch(/runners=runner-1%2Crunner-2|runners=runner-2%2Crunner-1/);
});

test('pause auto-restart posts the slot flag', async ({ page }) => {
  await mockStatus(page, [fixtureRunner({ id: 'runner-1', agent_name: 'e2e-1' })]);
  const posts = [];
  await page.route('**/api.php*action=pause_auto_restart*', async (route) => {
    posts.push(route.request().postData() || '');
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, paused: true, message: 'paused' }),
    });
  });
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await page.locator('tr[data-runner="runner-1"] button[data-action="pause-auto-restart"]').click();
  await expect.poll(() => posts.length).toBe(1);
  expect(posts[0]).toContain('runner=runner-1');
  expect(posts[0]).toContain('paused=1');
});

test('drain wait shows remaining seconds from the status poll', async ({ page }) => {
  await mockStatus(page, [
    fixtureRunner({
      id: 'runner-1',
      github: { status: 'online', busy: true, labels: [], os: 'Linux' },
    }),
  ]);
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await page.evaluate(() => {
    window.__DRAIN_TIMEOUT__ = 4;
  });
  await page.locator('tr[data-runner="runner-1"] button[data-action="drain"]').click();
  await expect(page.locator('tr[data-runner="runner-1"] button[data-action="drain"]'))
    .toHaveText(/Busy · \d+s/);
  await expect(page.locator('#confirm-modal')).toBeVisible({ timeout: 8000 });
  await page.locator('#confirm-modal-cancel').click();
  await expect(page.locator('tr[data-runner="runner-1"] button[data-action="drain"]')).toHaveText('Drain');
});

test('runner rows show the local agent version', async ({ page }) => {
  await mockStatus(page, [
    fixtureRunner({ id: 'runner-base', agent_name: 'e2e-runner-base', agent_version: '2.321.0' }),
    fixtureRunner({ id: 'runner-old', agent_name: 'stale-box', agent_version: '2.300.0' }),
  ]);
  await page.goto('/');
  await page.locator('#btn-refresh').click();

  await expect(page.locator('tr[data-runner="runner-base"] .agent-version')).toHaveText('v2.321.0');
  await expect(page.locator('tr[data-runner="runner-old"] .agent-version')).toHaveText('v2.300.0');

  await page.locator('#runner-filter').fill('2.300');
  await expect(page.locator('tr[data-runner]')).toHaveCount(1);
  await expect(page.locator('tr[data-runner="runner-old"]')).toBeVisible();
});

test('queued jobs panel lists waiting work and a start hint', async ({ page }) => {
  await mockStatus(page, [
    fixtureRunner({ id: 'runner-1', agent_name: 'e2e-runner-base', local_running: false, pid: null }),
  ]);
  await page.unroute('**/api.php*action=queue*');
  await page.route('**/api.php*action=queue*', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      ok: true,
      label: 'self-hosted-runnerdeck',
      count: 1,
      truncated: false,
      hint: '1 stopped slot(s) can pick these up.',
      hint_action: 'start',
      jobs: [{
        id: 9,
        name: 'lint',
        workflow: 'CI',
        repo: 'acme/app',
        html_url: 'https://example.test/job/9',
        status: 'queued',
        waited: '45s',
      }],
      live: [{
        id: 10,
        name: 'build',
        workflow: 'CI',
        repo: 'acme/app',
        html_url: 'https://example.test/job/10',
        runner_name: 'e2e-runner-base',
      }],
      recent: [{
        id: 8,
        name: 'unit',
        workflow: 'CI',
        repo: 'acme/app',
        html_url: 'https://example.test/job/8',
        runner_name: 'e2e-runner-base',
        conclusion: 'success',
        duration: '1m',
      }],
      message: '',
    }),
  }));
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await expect(page.locator('#queue-panel')).toBeVisible();
  await expect(page.locator('#queue-count')).toHaveText('1');
  await expect(page.locator('#queue-jobs')).toContainText('lint');
  await expect(page.locator('#queue-jobs a')).toHaveAttribute('href', 'https://example.test/job/9');
  await expect(page.locator('#queue-hint')).toContainText('stopped slot');
  await expect(page.locator('#queue-hint-btn')).toHaveText('Start idle slots');
  await expect(page.locator('tr[data-runner="runner-1"] .live-job')).toContainText('build');
  await expect(page.locator('tr[data-runner="runner-1"] .last-jobs')).toContainText('unit');
  await expect(page.locator('tr[data-runner="runner-1"] .last-jobs')).toContainText('success');
});

async function assertNoHorizontalOverflow(page) {
  const extra = await page.evaluate(
    () => Math.ceil(document.documentElement.scrollWidth - window.innerWidth),
  );
  expect(extra).toBeLessThanOrEqual(1);
}

test('dashboard layout stays aligned and does not overflow at 1280px', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto('/');
  await expect(page.locator('.topbar-inner')).toBeVisible();
  await expect(page.locator('.topbar-brand')).toBeVisible();
  await expect(page.locator('.page-nav-link[aria-current="page"]')).toHaveText('Dashboard');
  const statCols = await page.locator('.stat-grid').evaluate(
    (el) => getComputedStyle(el).gridTemplateColumns.split(' ').length,
  );
  expect(statCols).toBe(3);
  await assertNoHorizontalOverflow(page);
});

test('dashboard and settings are usable at 390px', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');
  await expect(page.locator('.stat-grid')).toBeVisible();
  const statCols = await page.locator('.stat-grid').evaluate(
    (el) => getComputedStyle(el).gridTemplateColumns.split(' ').length,
  );
  expect(statCols).toBe(1);
  await expect(page.locator('#btn-add-runner')).toBeVisible();
  await assertNoHorizontalOverflow(page);

  await page.goto('/settings.php');
  await expect(page.getByRole('tab', { name: 'GitHub' })).toBeVisible();
  const tabDir = await page.locator('.settings-tabs').evaluate(
    (el) => getComputedStyle(el).flexDirection,
  );
  expect(tabDir).toBe('row');
  await page.getByRole('tab', { name: 'Pool' }).click();
  await expect(page.locator('#settings-slot-cpu')).toBeVisible();
  await assertNoHorizontalOverflow(page);
});

test('history charts stack on a phone-sized viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/history.php');
  const cols = await page.locator('.history-grid').evaluate(
    (el) => getComputedStyle(el).gridTemplateColumns.split(' ').length,
  );
  expect(cols).toBe(1);
  await assertNoHorizontalOverflow(page);
});

test('header nav stays on one row and reaches History at 390px', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');
  await expect(page.locator('.topbar-brand')).toBeVisible();
  const wrap = await page.locator('.page-nav').evaluate(
    (el) => getComputedStyle(el).flexWrap,
  );
  expect(wrap).toBe('nowrap');
  await page.locator('.page-nav a[href="history.php"]').click();
  await expect(page).toHaveURL(/history\.php/);
  await expect(page.locator('.page-nav-link[aria-current="page"]')).toHaveText('History');
  await assertNoHorizontalOverflow(page);
});

test('ops pages do not overflow at 390px', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/outbox.php');
  await expect(page.locator('.ops-main')).toBeVisible();
  await assertNoHorizontalOverflow(page);
  await page.goto('/crashes.php');
  await expect(page.locator('.ops-main')).toBeVisible();
  await assertNoHorizontalOverflow(page);
});

async function enableDarkMode(page) {
  const baseURL = test.info().project.use.baseURL;
  await page.context().addCookies([{
    name: 'runnerdeck-theme',
    value: 'dark',
    url: `${baseURL}/`,
  }]);
  await page.emulateMedia({ colorScheme: 'dark' });
}

async function expectReadmeShot(page, name, opts = {}) {
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await page.addStyleTag({
    content: 'html,body,*{scrollbar-width:none!important} *::-webkit-scrollbar{display:none!important}',
  });
  await page.mouse.move(0, 0);
  await page.evaluate(() => {
    const el = document.activeElement;
    if (el && typeof el.blur === 'function') el.blur();
  });
  await expect(page).toHaveScreenshot(`${name}.png`, opts);
  const src = `.github/screenshots/${name}.png`;
  const dest = `docs/screenshots/${name}.png`;
  if (test.info().config.updateSnapshots !== 'none') {
    await copyFile(src, dest);
    return;
  }
  const committed = await readFile(src);
  const published = await readFile(dest);
  expect(
    committed.equals(published),
    `${name}.png must be identical in .github/screenshots and docs/screenshots`,
  ).toBe(true);
}

test('README dashboard screenshot stays current', async ({ page }) => {
  await enableDarkMode(page);
  await mockReadmeStatus(page, [
    fixtureRunner({ id: 'runner-base', agent_name: 'e2e-runner-base' }),
    fixtureRunner({
      id: 'runner-1',
      agent_name: 'e2e-runner-1',
      local_running: false,
      pid: null,
      cpu_percent: null,
      rss_kb: null,
      github: { status: 'offline', busy: false, labels: [] },
    }),
  ]);
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await expect(page.locator('tr[data-runner="runner-base"]')).toBeVisible();
  await expect(page.locator('#health-banner')).toBeHidden();
  await expectReadmeShot(page, 'dashboard', { fullPage: true });
});

test('README settings screenshot stays current', async ({ page }) => {
  await enableDarkMode(page);
  await page.goto('/settings.php');
  await expect(page.getByRole('tab', { name: 'GitHub' })).toBeVisible();
  await expectReadmeShot(page, 'settings', { fullPage: true });
});

test('README add-runner screenshot stays current', async ({ page }) => {
  await enableDarkMode(page);
  await mockReadmeStatus(page, [fixtureRunner()]);
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await page.locator('#btn-add-runner').click();
  await expect(page.locator('#add-runner-modal')).toBeVisible();
  await expectReadmeShot(page, 'add-runner');
});

test('README live-log screenshot stays current', async ({ page }) => {
  await enableDarkMode(page);
  await mockReadmeStatus(page, [fixtureRunner()]);
  await mockLogStream(page);
  await page.goto('/');
  await page.locator('#btn-refresh').click();
  await page.locator('tr[data-runner="runner-base"] .log-link').click();
  await expect(page.locator('#log-modal')).toBeVisible();
  await emitLogLine(page, 'Listening for Jobs');
  await emitLogLine(page, '2026-10-27 Job succeeded');
  await expect(page.locator('#log-modal-body')).toContainText('Listening for Jobs');
  await expectReadmeShot(page, 'live-log');
});
