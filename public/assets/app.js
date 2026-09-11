(() => {
  const POLL_MS = 12000;
  const rowsEl = document.getElementById('runner-rows');
  const bannerEl = document.getElementById('health-banner');
  const lastUpdatedEl = document.getElementById('last-updated');

  let activeStream = null;

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  function badge(text, cls) {
    return `<span class="badge badge-${cls}">${escapeHtml(text)}</span>`;
  }

  function githubBadges(gh) {
    if (!gh) return badge('unknown', 'muted');
    const statusCls = gh.status === 'online' ? 'good' : 'critical';
    const parts = [badge(gh.status, statusCls)];
    parts.push(badge(gh.busy ? 'busy' : 'idle', gh.busy ? 'warning' : 'good'));
    return parts.join(' ');
  }

  function localBadge(runner) {
    if (!runner.configured) return badge('not configured', 'muted');
    if (runner.local_running) return badge(`running (pid ${runner.pid})`, 'good');
    return badge('stopped', 'critical');
  }

  function rowHtml(runner) {
    const logPreview = (runner.log_tail || []).join('\n') || '(no log yet)';
    return `
      <tr data-runner="${runner.id}">
        <td>
          <span class="runner-name">${escapeHtml(runner.id)}</span>
          <span class="agent-name">${escapeHtml(runner.agent_name)}</span>
        </td>
        <td>${githubBadges(runner.github)}</td>
        <td>${localBadge(runner)}</td>
        <td>
          <pre class="log-preview">${escapeHtml(logPreview)}</pre>
          <button class="log-link" data-action="view-log">View live log &rarr;</button>
        </td>
        <td>
          <div class="row-actions">
            <button class="btn btn-sm btn-good" data-action="start" ${runner.local_running ? 'disabled' : ''}>Start</button>
            <button class="btn btn-sm btn-critical" data-action="stop" ${runner.local_running ? '' : 'disabled'}>Stop</button>
            <button class="btn btn-sm" data-action="restart">Restart</button>
          </div>
        </td>
      </tr>`;
  }

  function render(snapshot) {
    const h = snapshot.health;
    if (!h.logged_in || !h.org_access_ok) {
      bannerEl.hidden = false;
      bannerEl.className = 'banner';
      bannerEl.textContent = !h.logged_in
        ? `gh CLI is not authenticated: ${h.message}`
        : `gh CLI is logged in but cannot read org runners: ${h.message}`;
    } else if (h.gh_list_error) {
      bannerEl.hidden = false;
      bannerEl.className = 'banner warn';
      bannerEl.textContent = `Could not load GitHub runner status: ${h.gh_list_error}`;
    } else {
      bannerEl.hidden = true;
    }

    rowsEl.innerHTML = snapshot.runners.map(rowHtml).join('');
    lastUpdatedEl.textContent = `updated ${new Date(snapshot.generated_at * 1000).toLocaleTimeString()}`;
  }

  async function fetchStatus() {
    const res = await fetch('api.php?action=status&lines=5');
    render(await res.json());
  }

  async function post(action, params = {}) {
    const body = new URLSearchParams(params);
    const res = await fetch(`api.php?action=${action}`, {
      method: 'POST',
      body,
      headers: { 'X-CSRF-Token': window.__CSRF__ || '' },
    });
    const data = await res.json();
    return { status: res.status, data };
  }

  let confirmOpen = false;

  function confirmModal(message) {
    if (confirmOpen) {
      return Promise.resolve(false);
    }
    confirmOpen = true;
    closeLogViewer();

    return new Promise((resolve) => {
      const modal = document.getElementById('confirm-modal');
      document.getElementById('confirm-modal-message').textContent = message;
      modal.hidden = false;
      const cleanup = (result) => {
        modal.hidden = true;
        confirmOpen = false;
        okBtn.removeEventListener('click', onOk);
        cancelBtn.removeEventListener('click', onCancel);
        resolve(result);
      };
      const okBtn = document.getElementById('confirm-modal-ok');
      const cancelBtn = document.getElementById('confirm-modal-cancel');
      const onOk = () => cleanup(true);
      const onCancel = () => cleanup(false);
      okBtn.addEventListener('click', onOk);
      cancelBtn.addEventListener('click', onCancel);
    });
  }

  async function runWithBusyGuard(action, params) {
    let { status, data } = await post(action, params);
    if (status === 409) {
      const proceed = await confirmModal(data.message);
      if (!proceed) return;
      ({ status, data } = await post(action, { ...params, force: '1' }));
    }
    if (!data.ok) {
      alert(data.message || `${action} failed`);
    }
    await fetchStatus();
  }

  rowsEl.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    const tr = btn.closest('tr');
    const runner = tr.dataset.runner;
    const action = btn.dataset.action;

    if (action === 'view-log') {
      openLogViewer(runner);
      return;
    }
    if (action === 'start') {
      const { data } = await post('start', { runner });
      if (!data.ok) alert(data.message);
      await fetchStatus();
      return;
    }
    if (action === 'stop' || action === 'restart') {
      await runWithBusyGuard(action, { runner });
    }
  });

  document.getElementById('btn-refresh').addEventListener('click', fetchStatus);

  document.getElementById('btn-start-all').addEventListener('click', async () => {
    const count = document.getElementById('pool-size').value || 10;
    const { data } = await post('start_all', { count });
    if (!data.ok) alert(data.message);
    await fetchStatus();
  });

  document.getElementById('btn-stop-all').addEventListener('click', async () => {
    await runWithBusyGuard('stop_all', {});
  });

  document.getElementById('pool-size-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const count = document.getElementById('pool-size').value;
    const { data } = await post('resize', { count });
    if (!data.ok) alert(data.message);
    await fetchStatus();
  });

  function openLogViewer(runnerId) {
    const modal = document.getElementById('log-modal');
    const body = document.getElementById('log-modal-body');
    document.getElementById('log-modal-title').textContent = `${runnerId} — live log`;
    body.textContent = 'Loading…';
    modal.hidden = false;

    if (activeStream) {
      activeStream.close();
      activeStream = null;
    }

    body.textContent = '';
    const stream = new EventSource(`log_stream.php?runner=${encodeURIComponent(runnerId)}`);
    activeStream = stream;
    stream.onmessage = (ev) => {
      body.textContent += ev.data + '\n';
      body.scrollTop = body.scrollHeight;
    };
    stream.onerror = () => {};
  }

  function closeLogViewer() {
    document.getElementById('log-modal').hidden = true;
    if (activeStream) {
      activeStream.close();
      activeStream = null;
    }
  }

  document.getElementById('log-modal-close').addEventListener('click', closeLogViewer);
  document.getElementById('log-modal').addEventListener('click', (e) => {
    if (e.target.id === 'log-modal') closeLogViewer();
  });

  if (window.__SNAPSHOT__) {
    render(window.__SNAPSHOT__);
  } else {
    fetchStatus();
  }
  setInterval(fetchStatus, POLL_MS);
})();
