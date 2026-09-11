(() => {
  const POLL_MS = 12000;

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  function setLoading(btn, label) {
    if (!btn) return;
    if (btn.dataset.originalText === undefined) {
      btn.dataset.originalText = btn.textContent;
    }
    btn.disabled = true;
    btn.classList.add('btn-loading');
    btn.textContent = label;
  }

  function clearLoading(btn) {
    if (!btn) return;
    btn.disabled = false;
    btn.classList.remove('btn-loading');
    if (btn.dataset.originalText !== undefined) {
      btn.textContent = btn.dataset.originalText;
      delete btn.dataset.originalText;
    }
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

  function wireScopeToggle(scopeSelect, orgField, repoField) {
    const update = () => {
      const isRepo = scopeSelect.value === 'repo';
      orgField.hidden = isRepo;
      repoField.hidden = !isRepo;
    };
    scopeSelect.addEventListener('change', update);
    update();
  }

  async function submitSettingsForm(errorEl, scopeSelect, orgInput, repoInput, labelInput, submitBtn) {
    errorEl.hidden = true;
    setLoading(submitBtn, 'Saving…');
    try {
      const { data } = await post('save_settings', {
        scope: scopeSelect.value,
        org: orgInput.value.trim(),
        repo: repoInput.value.trim(),
        label: labelInput.value.trim(),
      });
      if (!data.ok) {
        errorEl.textContent = data.message || 'failed to save settings';
        errorEl.hidden = false;
        return;
      }
      location.reload();
    } finally {
      clearLoading(submitBtn);
    }
  }

  if (window.__NEEDS_SETUP__) {
    const scopeSelect = document.getElementById('setup-scope');
    wireScopeToggle(scopeSelect, document.getElementById('setup-org-field'), document.getElementById('setup-repo-field'));

    document.getElementById('setup-form').addEventListener('submit', (e) => {
      e.preventDefault();
      submitSettingsForm(
        document.getElementById('setup-error'),
        scopeSelect,
        document.getElementById('setup-org'),
        document.getElementById('setup-repo'),
        document.getElementById('setup-label'),
        e.target.querySelector('button[type="submit"]'),
      );
    });
    return;
  }

  const rowsEl = document.getElementById('runner-rows');
  const bannerEl = document.getElementById('health-banner');
  const lastUpdatedEl = document.getElementById('last-updated');

  let activeStream = null;
  let lastSnapshot = null;
  const sortState = { key: null, dir: 1 };

  function miniBar(percent) {
    const pct = Math.max(0, Math.min(100, percent));
    const cls = percent > 80 ? 'mini-bar-critical' : percent > 50 ? 'mini-bar-warn' : '';
    return `<span class="mini-bar"><span class="mini-bar-fill ${cls}" style="width:${pct}%"></span></span>`;
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

  function resourceUsage(runner) {
    if (!runner.local_running || runner.cpu_percent == null || runner.rss_kb == null) return '';
    const mb = (runner.rss_kb / 1024).toFixed(0);
    const bar = miniBar(runner.cpu_percent);
    return `<span class="resource-usage">${bar}${runner.cpu_percent.toFixed(1)}% CPU &middot; ${mb} MB</span>`;
  }

  function sortValue(runner, key) {
    if (key === 'id') return runner.id;
    if (key === 'status') return runner.github ? (runner.github.busy ? 2 : runner.github.status === 'online' ? 1 : 0) : -1;
    if (key === 'cpu') return runner.cpu_percent ?? -1;
    return '';
  }

  function sortRunners(runners) {
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

  function updateSortIndicators() {
    document.querySelectorAll('th.sortable .sort-caret').forEach((el) => {
      el.textContent = '';
    });
    if (sortState.key) {
      const caret = document.querySelector(`th.sortable[data-sort="${sortState.key}"] .sort-caret`);
      if (caret) caret.textContent = sortState.dir === 1 ? ' ▲' : ' ▼';
    }
  }

  function renderStats(snapshot) {
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
  }

  function rowHtml(runner) {
    const logPreview = (runner.log_tail || []).join('\n') || '(no log yet)';
    return `
      <tr data-runner="${runner.id}" data-agent-name="${escapeHtml(runner.agent_name)}">
        <td>
          <span class="runner-name">${escapeHtml(runner.id)}</span>
          <span class="agent-name">${escapeHtml(runner.agent_name)}</span>
        </td>
        <td>${githubBadges(runner.github)}</td>
        <td>${localBadge(runner)}${resourceUsage(runner)}</td>
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
          </div>
        </td>
      </tr>`;
  }

  function render(snapshot) {
    lastSnapshot = snapshot;
    const h = snapshot.health;
    if (!h.logged_in || !h.org_access_ok) {
      bannerEl.hidden = false;
      bannerEl.className = 'banner';
      bannerEl.textContent = !h.logged_in
        ? `gh CLI is not authenticated: ${h.message}`
        : `gh CLI is logged in but cannot read runners: ${h.message}`;
    } else if (h.gh_list_error) {
      bannerEl.hidden = false;
      bannerEl.className = 'banner warn';
      bannerEl.textContent = `Could not load GitHub runner status: ${h.gh_list_error}`;
    } else {
      bannerEl.hidden = true;
    }

    renderStats(snapshot);
    rowsEl.innerHTML = sortRunners(snapshot.runners).map(rowHtml).join('');
    updateSortIndicators();
    lastUpdatedEl.textContent = `updated ${new Date(snapshot.generated_at * 1000).toLocaleTimeString()}`;
  }

  document.querySelectorAll('th.sortable').forEach((th) => {
    th.addEventListener('click', () => {
      const key = th.dataset.sort;
      sortState.dir = sortState.key === key ? sortState.dir * -1 : 1;
      sortState.key = key;
      if (lastSnapshot) render(lastSnapshot);
    });
  });

  async function fetchStatus() {
    const res = await fetch('api.php?action=status&lines=5');
    render(await res.json());
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
    if (action === 'rename') {
      openRenameModal(runner, tr.dataset.agentName);
      return;
    }
    if (action === 'start') {
      setLoading(btn, 'Starting…');
      try {
        const { data } = await post('start', { runner });
        if (!data.ok) alert(data.message);
        await fetchStatus();
      } finally {
        clearLoading(btn);
      }
      return;
    }
    if (action === 'stop' || action === 'restart') {
      setLoading(btn, action === 'stop' ? 'Stopping…' : 'Restarting…');
      try {
        await runWithBusyGuard(action, { runner });
      } finally {
        clearLoading(btn);
      }
    }
  });

  document.getElementById('btn-refresh').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    setLoading(btn, 'Refreshing…');
    try {
      await fetchStatus();
    } finally {
      clearLoading(btn);
    }
  });

  document.getElementById('btn-start-all').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const count = document.getElementById('pool-size').value || 10;
    setLoading(btn, 'Starting All…');
    try {
      const { data } = await post('start_all', { count });
      if (!data.ok) alert(data.message);
      await fetchStatus();
    } finally {
      clearLoading(btn);
    }
  });

  document.getElementById('btn-stop-all').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    setLoading(btn, 'Stopping All…');
    try {
      await runWithBusyGuard('stop_all', {});
    } finally {
      clearLoading(btn);
    }
  });

  document.getElementById('pool-size-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    const count = document.getElementById('pool-size').value;
    setLoading(btn, 'Applying…');
    try {
      const { data } = await post('resize', { count });
      if (!data.ok) alert(data.message);
      await fetchStatus();
    } finally {
      clearLoading(btn);
    }
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

  const settingsModal = document.getElementById('settings-modal');
  const settingsScope = document.getElementById('settings-scope');
  wireScopeToggle(settingsScope, document.getElementById('settings-org-field'), document.getElementById('settings-repo-field'));

  document.getElementById('btn-settings').addEventListener('click', () => {
    const cur = window.__CURRENT_SETTINGS__ || {};
    settingsScope.value = cur.scope || 'org';
    document.getElementById('settings-org').value = cur.org || '';
    document.getElementById('settings-repo').value = cur.repo || '';
    document.getElementById('settings-label').value = cur.label || '';
    settingsScope.dispatchEvent(new Event('change'));
    document.getElementById('settings-error').hidden = true;
    settingsModal.hidden = false;
  });

  document.getElementById('settings-cancel').addEventListener('click', () => {
    settingsModal.hidden = true;
  });
  document.getElementById('settings-modal-close').addEventListener('click', () => {
    settingsModal.hidden = true;
  });

  document.getElementById('settings-form').addEventListener('submit', (e) => {
    e.preventDefault();
    submitSettingsForm(
      document.getElementById('settings-error'),
      settingsScope,
      document.getElementById('settings-org'),
      document.getElementById('settings-repo'),
      document.getElementById('settings-label'),
      e.target.querySelector('button[type="submit"]'),
    );
  });

  const addRunnerModal = document.getElementById('add-runner-modal');

  function closeAddRunnerModal() {
    addRunnerModal.hidden = true;
  }

  document.getElementById('btn-add-runner').addEventListener('click', () => {
    document.getElementById('add-runner-name').value = '';
    document.getElementById('add-runner-error').hidden = true;
    addRunnerModal.hidden = false;
  });
  document.getElementById('add-runner-modal-close').addEventListener('click', closeAddRunnerModal);
  document.getElementById('add-runner-cancel').addEventListener('click', closeAddRunnerModal);
  document.getElementById('add-runner-modal').addEventListener('click', (e) => {
    if (e.target.id === 'add-runner-modal') closeAddRunnerModal();
  });

  document.getElementById('add-runner-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = document.getElementById('add-runner-name').value.trim();
    const errorEl = document.getElementById('add-runner-error');
    const submitBtn = e.target.querySelector('button[type="submit"]');
    errorEl.hidden = true;
    setLoading(submitBtn, 'Adding…');
    try {
      const { data } = await post('add_runner', { name });
      if (!data.ok) {
        errorEl.textContent = data.message || 'failed to add runner';
        errorEl.hidden = false;
        return;
      }
      closeAddRunnerModal();
      await fetchStatus();
    } finally {
      clearLoading(submitBtn);
    }
  });

  const renameModal = document.getElementById('rename-modal');

  function openRenameModal(runnerId, currentName) {
    document.getElementById('rename-runner-id').value = runnerId;
    document.getElementById('rename-name').value = currentName || '';
    document.getElementById('rename-error').hidden = true;
    renameModal.hidden = false;
  }

  function closeRenameModal() {
    renameModal.hidden = true;
  }

  document.getElementById('rename-modal-close').addEventListener('click', closeRenameModal);
  document.getElementById('rename-cancel').addEventListener('click', closeRenameModal);
  document.getElementById('rename-modal').addEventListener('click', (e) => {
    if (e.target.id === 'rename-modal') closeRenameModal();
  });

  document.getElementById('rename-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const runner = document.getElementById('rename-runner-id').value;
    const name = document.getElementById('rename-name').value.trim();
    const errorEl = document.getElementById('rename-error');
    const submitBtn = e.target.querySelector('button[type="submit"]');
    errorEl.hidden = true;
    setLoading(submitBtn, 'Renaming…');
    try {
      let { status, data } = await post('rename', { runner, name });
      if (status === 409) {
        renameModal.hidden = true;
        const proceed = await confirmModal(data.message);
        if (!proceed) {
          renameModal.hidden = false;
          return;
        }
        ({ status, data } = await post('rename', { runner, name, force: '1' }));
      }
      if (!data.ok) {
        errorEl.textContent = data.message || 'rename failed';
        errorEl.hidden = false;
        renameModal.hidden = false;
        return;
      }
      closeRenameModal();
      await fetchStatus();
    } finally {
      clearLoading(submitBtn);
    }
  });

  if (window.__SNAPSHOT__) {
    render(window.__SNAPSHOT__);
  } else {
    fetchStatus();
  }
  setInterval(fetchStatus, POLL_MS);
})();
