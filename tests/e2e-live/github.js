const token = process.env.GH_TOKEN || '';
const repo = process.env.E2E_REPO || '';
export const tag = process.env.E2E_TAG || '';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function api(path, init = {}) {
  let lastError;
  for (let attempt = 0; attempt < 5; attempt += 1) {
    try {
      const res = await fetch(`https://api.github.com${path}`, {
        ...init,
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/vnd.github+json',
          'X-GitHub-Api-Version': '2022-11-28',
          ...(init.headers || {}),
        },
      });
      if (res.status >= 500) {
        lastError = new Error(`GitHub answered ${res.status} for ${path}`);
      } else {
        const text = await res.text();
        return { status: res.status, body: text ? JSON.parse(text) : null };
      }
    } catch (error) {
      lastError = error;
    }
    await sleep(2000 * (attempt + 1));
  }
  throw lastError;
}

export async function ourRunners() {
  const { body } = await api(`/repos/${repo}/actions/runners?per_page=100`);
  return ((body && body.runners) || []).filter((r) => r.name.startsWith(tag));
}

export async function runnerByName(name) {
  return (await ourRunners()).find((r) => r.name === name) || null;
}

export async function deleteOurRunners() {
  for (const r of await ourRunners()) {
    await api(`/repos/${repo}/actions/runners/${r.id}`, { method: 'DELETE' });
  }
}

export async function dispatchProbe() {
  for (let i = 0; i < 12; i += 1) {
    const { status } = await api(`/repos/${repo}/actions/workflows/runnerdeck-e2e.yml/dispatches`, {
      method: 'POST',
      body: JSON.stringify({ ref: 'main', inputs: { tag } }),
    });
    if (status === 204) return;
    await new Promise((resolve) => setTimeout(resolve, 5000));
  }
  throw new Error('could not dispatch the probe workflow');
}

async function findProbeRun() {
  const { body } = await api(`/repos/${repo}/actions/runs?event=workflow_dispatch&per_page=100`);
  return ((body && body.workflow_runs) || []).find((r) => r.display_title === `e2e ${tag}`) || null;
}

// GitHub sometimes accepts a dispatch and never starts the run (it stays queued
// with no jobs), so dispatch again if no run shows up within three minutes.
export async function runProbe() {
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    await dispatchProbe();
    const giveUp = Date.now() + 3 * 60_000;
    while (Date.now() < giveUp) {
      if (await findProbeRun()) return probeRunConclusion();
      await new Promise((resolve) => setTimeout(resolve, 8000));
    }
  }
  return 'timeout';
}

export async function probeRunConclusion(timeoutMs = 8 * 60_000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const run = await findProbeRun();
    if (run && run.status === 'completed') return run.conclusion;
    await new Promise((resolve) => setTimeout(resolve, 8000));
  }
  return 'timeout';
}
