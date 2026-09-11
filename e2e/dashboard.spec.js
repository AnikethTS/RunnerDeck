import { test, expect } from '@playwright/test';

// This suite drives the real PHP backend (php -S) and the real app.js in a
// real browser. It does NOT talk to GitHub: `gh` isn't authenticated in CI,
// so GithubClient degrades to health.logged_in === false, same as any fresh
// machine. Runner data for the richer UI tests (filter/sort/bulk/mismatch)
// is supplied by mocking `action=status` responses at the network level —
// see mockStatus() below. First-run setup and Settings, by contrast, hit
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
    health: { logged_in: false, org_access_ok: false, message: 'not logged in', gh_list_error: null },
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
  // github.status is 'online' but local_running is false — isMismatched()
  // in app.js only flags this after MISMATCH_THRESHOLD (3) consecutive
  // polls, so refresh three times before asserting.
  await mockStatus(page, [
    fixtureRunner({
      id: 'runner-base',
      agent_name: 'e2e-runner-base',
      local_running: false,
      pid: null,
      cpu_percent: null,
      rss_kb: null,
      github: { status: 'online', busy: false, labels: [] },
    }),
  ]);
  await page.goto('/');

  await page.locator('#btn-refresh').click();
  await page.locator('#btn-refresh').click();
  await page.locator('#btn-refresh').click();

  await expect(page.locator('tr[data-runner="runner-base"] .row-flag')).toBeVisible();
  await expect(page.locator('tr[data-runner="runner-base"] .row-flag')).toContainText('disagree');
});
