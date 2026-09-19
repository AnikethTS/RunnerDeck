import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';

// This suite drives the real PHP backend (php -S) and the real app.js in a
// real browser. It does NOT talk to GitHub: `gh` isn't authenticated in CI,
// so GithubClient degrades to health.logged_in === false, same as any fresh
// machine. Runner data for the richer UI tests (filter/sort/bulk/mismatch)
// is supplied by mocking `action=status` responses at the network level —
// see mockStatus() below. Mismatch badges require `mismatch_flagged: true`
// on that payload (the streak itself is counted in PHP). First-run setup and Settings, by contrast, hit
// the real Settings::save() code path with no mocking at all.
//
// Tests run serially in this one file: the first test performs real
// first-run setup, and everything after it depends on that configured
// state persisting across page loads (it does — Settings::save() writes
// storage/settings.json for real, per RUNNERDECK_SETTINGS_FILE).
test.describe.configure({ mode: 'serial' });

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
    github: { status: 'online', busy: false, labels: ['self-hosted-runnerdeck'] },
    ...overrides,
  };
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
    },
  };
}

async function mockStatus(page, runners) {
  await page.route('**/api.php*action=status*', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify(fixtureSnapshot(runners)),
  }));
}

test('first-run setup configures the app and reaches the dashboard', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Set up RunnerDeck' })).toBeVisible();

  await page.locator('#setup-org').fill('e2e-org');
  await page.locator('#setup-form button[type="submit"]').click();

  await expect(page.locator('.stat-grid')).toBeVisible();
  await expect(page.locator('.org-tag')).toContainText('e2e-org');
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
