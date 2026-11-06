import { readdirSync } from 'node:fs';
import { test, expect } from '@playwright/test';
import {
  tag, ourRunners, runnerByName, deleteOurRunners, dispatchProbe, probeRunConclusion,
} from './github.js';

test.describe.configure({ mode: 'serial' });

const BASE = 'runner-base';
const baseName = tag;
const renamedBase = `${tag}-renamed`;

const SLOW = process.platform === 'win32' ? 3 : 1;

let page;

const row = (id) => page.locator(`tr[data-runner="${id}"]`);
const localCell = (id) => row(id).locator('td').nth(3);
const agentName = (id) => row(id).locator('.agent-name');
const toast = () => page.locator('#toast-container .toast');

async function confirmIfAsked(ms = 3000) {
  const ok = page.locator('#confirm-modal-ok');
  for (let i = 0; i < 2; i += 1) {
    try {
      await ok.waitFor({ state: 'visible', timeout: i === 0 ? ms : 1500 });
      await ok.click();
    } catch {
      return;
    }
  }
}

async function refresh() {
  await page.locator('#btn-refresh').click();
}

async function expectRunning(id, timeout = 120_000) {
  await expect(localCell(id)).toContainText('running', { timeout: timeout * SLOW });
}

async function expectStopped(id, timeout = 120_000) {
  await expect(localCell(id)).toContainText('stopped', { timeout: timeout * SLOW });
}

async function expectGithubStatus(name, status, timeout = 120_000) {
  await expect.poll(async () => (await runnerByName(name))?.status ?? 'absent', { timeout, intervals: [6000] })
    .toBe(status);
}

async function pidOf(id) {
  const text = (await localCell(id).innerText()).match(/pid (\d+)/);
  return text ? text[1] : null;
}

test.beforeAll(async ({ browser }) => {
  expect(process.env.GH_TOKEN, 'GH_TOKEN').toBeTruthy();
  expect(process.env.E2E_REPO, 'E2E_REPO').toBeTruthy();
  expect(tag, 'E2E_TAG').toBeTruthy();
  page = await browser.newPage();
});

test.afterEach(async ({}, testInfo) => {
  if (testInfo.status === testInfo.expectedStatus) return;
  const toasts = await toast().allInnerTexts().catch(() => []);
  const addError = await page.locator('#add-runner-error').innerText().catch(() => '');
  const raw = await page.evaluate(() => fetch('api.php?action=status&lines=20').then((r) => r.text()))
    .catch((e) => `fetch failed: ${e.message}`);
  let status = null;
  try { status = JSON.parse(raw); } catch { console.log(`status was not JSON: ${String(raw).slice(0, 1500)}`); }
  console.log(`FAILED: ${testInfo.title}`);
  console.log(`toasts: ${JSON.stringify(toasts)} add-runner-error: ${JSON.stringify(addError)}`);
  console.log(`app log: ${JSON.stringify(status && status.errors, null, 1)}`);
  const poolDir = process.env.E2E_POOL_DIR || '';
  const listing = (() => { try { return readdirSync(poolDir); } catch (e) { return `unreadable: ${e.message}`; } })();
  console.log(`pool dir ${poolDir}: ${JSON.stringify(listing)}`);
  console.log(`status snapshot runners: ${JSON.stringify(status && status.runners && status.runners.map((r) => r.id))}`);
  for (const r of (status && status.runners) || []) {
    console.log(`${r.id}: running=${r.local_running} configured=${r.configured} log=${JSON.stringify(r.log_tail)}`);
  }
});

test.afterAll(async () => {
  await deleteOurRunners();
  await page.close();
});

test('every header tab opens quickly', async () => {
  const tabs = [
    ['History', /history\.php/],
    ['Crashes', /crashes\.php/],
    ['Outbox', /outbox\.php/],
    ['Bundles', /bundles\.php/],
    ['Settings', /settings\.php/],
    ['Dashboard', /(index\.php|\/)$/],
  ];
  const windows = process.platform === 'win32';
  const limit = windows ? 10_000 : 4_000;
  const queueSettled = windows
    ? page.waitForResponse((r) => r.url().includes('action=queue'), { timeout: 60_000 })
    : null;
  await page.goto('/');
  await expect(page.locator('.stat-grid')).toBeVisible();
  if (queueSettled) await queueSettled;
  const openTab = async (label, url) => {
    const started = Date.now();
    await page.locator('nav.page-nav').getByRole('link', { name: label, exact: true }).click();
    await page.waitForURL(url);
    await expect(page.locator('nav.page-nav a[aria-current="page"]')).toHaveText(label);
    return Date.now() - started;
  };
  for (const [label, url] of tabs) {
    let elapsed = await openTab(label, url);
    if (elapsed >= limit) {
      await page.goto('/');
      elapsed = Math.min(elapsed, await openTab(label, url));
    }
    expect(elapsed, `${label} tab took ${elapsed}ms`).toBeLessThan(limit);
  }
});

test('dashboard reaches GitHub and starts empty', async () => {
  await page.goto('/');
  await refresh();
  await expect(page.locator('.stat-grid')).toContainText('Connected');
  await expect(page.locator('tr[data-runner]')).toHaveCount(0);
});

test('Add Runner registers a real runner and it comes online', async () => {
  await page.locator('#btn-add-runner').click();
  await expect(page.locator('#add-runner-modal')).toBeVisible();
  const added = page.waitForResponse((r) => r.url().includes('action=add_runner'), { timeout: 300_000 });
  await page.locator('#add-runner-form button[type="submit"]').click();
  const body = await (await added).json();
  console.log(`add_runner response: ${JSON.stringify(body)}`);
  expect(body.ok, `add_runner said: ${JSON.stringify(body)}`).toBe(true);
  await expect(page.locator('#add-runner-modal')).toBeHidden({ timeout: 300_000 });
  await expect(row(BASE)).toBeVisible({ timeout: 60_000 * SLOW * 2 });
  await expectRunning(BASE, 180_000);
  await expectGithubStatus(baseName, 'online', 180_000);
  await expect(agentName(BASE)).toHaveText(baseName);
});

test('Stop takes the runner offline', async () => {
  await row(BASE).locator('[data-action="stop"]').click();
  await confirmIfAsked();
  await expectStopped(BASE);
  await expectGithubStatus(baseName, 'offline');
});

test('Start brings it back online', async () => {
  await row(BASE).locator('[data-action="start"]').click();
  await expectRunning(BASE);
  await expectGithubStatus(baseName, 'online');
});

test('Restart replaces the process', async () => {
  const before = await pidOf(BASE);
  expect(before).not.toBeNull();
  await row(BASE).locator('[data-action="restart"]').click();
  await confirmIfAsked();
  await expect.poll(() => pidOf(BASE), { timeout: 120_000, intervals: [2000] }).not.toBe(before);
  await expectRunning(BASE);
  await expectGithubStatus(baseName, 'online');
});

test('Pause AR and Resume AR toggle', async () => {
  const btn = row(BASE).locator('[data-action="pause-auto-restart"]');
  await expect(btn).toHaveText('Pause AR');
  await btn.click();
  await expect(btn).toHaveText('Resume AR');
  await btn.click();
  await expect(btn).toHaveText('Pause AR');
});

test('View live log shows runner output', async () => {
  await row(BASE).locator('[data-action="view-log"]').click();
  await expect(page.locator('#log-modal')).toBeVisible();
  await expect(page.locator('#log-modal-body')).not.toHaveText('', { timeout: 30_000 });
  await page.locator('#log-modal-close').click();
  await expect(page.locator('#log-modal')).toBeHidden();
});

test('Rename re-registers under the new name', async () => {
  await row(BASE).locator('[data-action="rename"]').click();
  await expect(page.locator('#rename-modal')).toBeVisible();
  await page.locator('#rename-name').fill(renamedBase);
  await page.locator('#rename-form button[type="submit"]').click();
  await confirmIfAsked();
  await expect(page.locator('#rename-modal')).toBeHidden({ timeout: 180_000 });
  await expect(agentName(BASE)).toHaveText(renamedBase, { timeout: 60_000 });
  await expectRunning(BASE, 180_000);
  await expectGithubStatus(renamedBase, 'online', 180_000);
  await expectGithubStatus(baseName, 'absent');
});

test('a real workflow job runs on the runner', async () => {
  await dispatchProbe();
  expect(await probeRunConclusion()).toBe('success');
});

test('Clear work empties _work on a stopped runner', async () => {
  await expect(localCell(BASE)).toContainText(/running|stopped/, { timeout: 60_000 });
  if ((await localCell(BASE).innerText()).includes('running')) {
    await row(BASE).locator('[data-action="stop"]').click();
    await confirmIfAsked();
  }
  await expectStopped(BASE);
  const clear = row(BASE).locator('[data-action="clear-work"]');
  await expect(clear).toBeEnabled({ timeout: 60_000 });
  await clear.click();
  await confirmIfAsked();
  await expect(clear).toBeDisabled({ timeout: 60_000 });
  await row(BASE).locator('[data-action="start"]').click();
  await expectRunning(BASE);
});

test('Export JSON downloads the current snapshot', async () => {
  const download = page.waitForEvent('download');
  await page.locator('#btn-export').click();
  const file = await download;
  const stream = await file.createReadStream();
  const chunks = [];
  for await (const chunk of stream) chunks.push(chunk);
  const runners = JSON.parse(Buffer.concat(chunks).toString('utf8'));
  expect(runners.map((r) => r.id)).toContain(BASE);
});

test('Pool size Apply creates two more slots', async () => {
  await page.locator('#pool-size').fill('3');
  await page.locator('#pool-size-form button[type="submit"]').click();
  await expect(row('runner-1')).toBeVisible({ timeout: 300_000 });
  await expect(row('runner-2')).toBeVisible({ timeout: 300_000 });
  await expectRunning('runner-1', 240_000);
  await expectRunning('runner-2', 240_000);
  await expectGithubStatus(`${tag}-1`, 'online', 180_000);
  await expectGithubStatus(`${tag}-2`, 'online', 180_000);
});

test('bulk Stop and Start act on the selected rows', async () => {
  await row('runner-1').locator('input.row-select').check();
  await row('runner-2').locator('input.row-select').check();
  await expect(page.locator('#bulk-actions-bar')).toBeVisible();
  await expect(page.locator('#bulk-actions-count')).toContainText('2');

  await page.locator('#btn-bulk-stop').click();
  await confirmIfAsked();
  await expectStopped('runner-1');
  await expectStopped('runner-2');
  await expectRunning(BASE);

  await page.locator('#btn-bulk-start').click();
  await confirmIfAsked(1500);
  await expectRunning('runner-1');
  await expectRunning('runner-2');

  await row('runner-1').locator('input.row-select').uncheck();
  await row('runner-2').locator('input.row-select').uncheck();
  await expect(page.locator('#bulk-actions-bar')).toBeHidden();
});

test('Stop All and Start All act on the whole pool', async () => {
  await page.locator('#btn-stop-all').click();
  await confirmIfAsked();
  for (const id of [BASE, 'runner-1', 'runner-2']) await expectStopped(id);

  await page.locator('#btn-start-all').click();
  for (const id of [BASE, 'runner-1', 'runner-2']) await expectRunning(id, 180_000);
});

test('Rolling Restart cycles every slot and leaves them running', async () => {
  const before = await Promise.all([BASE, 'runner-1', 'runner-2'].map(pidOf));
  await page.locator('#btn-rolling-restart-all').click();
  await confirmIfAsked();
  await expect.poll(async () => (await Promise.all([BASE, 'runner-1', 'runner-2'].map(pidOf))).join(','), {
    timeout: 300_000,
    intervals: [3000],
  }).not.toBe(before.join(','));
  for (const id of [BASE, 'runner-1', 'runner-2']) await expectRunning(id, 180_000);
});

test('Maintenance refuses starts until resumed', async () => {
  await page.locator('#btn-maintenance').click();
  await confirmIfAsked();
  for (const id of [BASE, 'runner-1', 'runner-2']) await expectStopped(id, 180_000);
  await expect(page.locator('#health-banner')).toBeVisible();
  await expect(page.locator('#health-banner')).toContainText('maintenance');

  await row('runner-1').locator('[data-action="start"]').click();
  await expect(row('runner-1').locator('.row-activity')).toBeVisible();
  await expectStopped('runner-1');

  await page.locator('#btn-maintenance').click();
  await confirmIfAsked();
  await expect.poll(async () => {
    const banner = page.locator('#health-banner');
    if (!(await banner.isVisible())) return 'hidden';
    return (await banner.innerText()).includes('maintenance') ? 'maintenance' : 'other';
  }, { timeout: 60_000, intervals: [2000] }).not.toBe('maintenance');
});

test('Drain All stops idle runners', async () => {
  await page.locator('#btn-start-all').click();
  for (const id of [BASE, 'runner-1', 'runner-2']) await expectRunning(id, 180_000);
  await page.locator('#btn-drain-all').click();
  await confirmIfAsked();
  for (const id of [BASE, 'runner-1', 'runner-2']) await expectStopped(id, 180_000);
});

test('Settings save persists and exports', async () => {
  await page.goto('/settings.php');
  await page.locator('#tab-pool').click();
  const idle = page.locator('#settings-idle-stop');
  await idle.fill('45');
  await page.locator('#settings-save').click();
  await page.goto('/settings.php');
  await page.locator('#tab-pool').click();
  await expect(page.locator('#settings-idle-stop')).toHaveValue('45');

  await page.locator('#settings-idle-stop').fill('');
  await page.locator('#settings-save').click();
  await page.goto('/settings.php');
  await page.locator('#tab-pool').click();
  await expect(page.locator('#settings-idle-stop')).toHaveValue('');

  const res = await page.request.get('/download_settings.php');
  expect(res.ok()).toBe(true);
  const exported = await res.json();
  expect(JSON.stringify(exported)).not.toMatch(/totp/i);
});

test('bulk Delete removes slots locally and on GitHub', async () => {
  await page.goto('/');
  await expect(row('runner-1')).toBeVisible();
  await row('runner-1').locator('input.row-select').check();
  await row('runner-2').locator('input.row-select').check();
  await page.locator('#btn-bulk-delete').click();
  await confirmIfAsked();
  await expect(row('runner-1')).toHaveCount(0, { timeout: 120_000 });
  await expect(row('runner-2')).toHaveCount(0, { timeout: 120_000 });
  await expectGithubStatus(`${tag}-1`, 'absent');
  await expectGithubStatus(`${tag}-2`, 'absent');
});

test('Delete removes the last slot and nothing is left behind', async () => {
  await row(BASE).locator('[data-action="delete"]').click();
  await confirmIfAsked();
  await expect(row(BASE)).toHaveCount(0, { timeout: 120_000 });
  await expectGithubStatus(renamedBase, 'absent');
  expect(await ourRunners()).toHaveLength(0);
});
