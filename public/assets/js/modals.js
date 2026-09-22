import { post, redirectIfUnauthenticated } from './api.js';
import { setLoading, clearLoading } from './utils.js';

let confirmOpen = false;
let activeStream = null;
let currentLogRunnerId = null;

export function closeLogViewer() {
  document.getElementById('log-modal').hidden = true;
  if (activeStream) {
    activeStream.close();
    activeStream = null;
  }
}

function updateLogDownloadHref() {
  if (!currentLogRunnerId) return;
  const params = new URLSearchParams({ runner: currentLogRunnerId });
  const fromValue = document.getElementById('log-range-from').value;
  const toValue = document.getElementById('log-range-to').value;
  if (fromValue) params.set('from', String(Math.floor(new Date(fromValue).getTime() / 1000)));
  if (toValue) params.set('to', String(Math.floor(new Date(toValue).getTime() / 1000)));
  document.getElementById('log-modal-download').href = `download_log.php?${params}`;
}

function appendLogText(text) {
  const body = document.getElementById('log-modal-body');
  const query = document.getElementById('log-search').value;
  if (!query) {
    body.append(document.createTextNode(text));
    return;
  }
  // Escape regex syntax so the query is always a literal substring.
  const pattern = new RegExp(query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
  const fragment = document.createDocumentFragment();
  let offset = 0;
  for (const match of text.matchAll(pattern)) {
    fragment.append(document.createTextNode(text.slice(offset, match.index)));
    const mark = document.createElement('mark');
    mark.textContent = match[0];
    fragment.append(mark);
    offset = match.index + match[0].length;
  }
  fragment.append(document.createTextNode(text.slice(offset)));
  body.append(fragment);
}

export function openLogViewer(runnerId) {
  const modal = document.getElementById('log-modal');
  const body = document.getElementById('log-modal-body');
  currentLogRunnerId = runnerId;
  document.getElementById('log-search').value = '';
  document.getElementById('log-modal-title').textContent = `${runnerId} — live log`;
  document.getElementById('log-range-from').value = '';
  document.getElementById('log-range-to').value = '';
  updateLogDownloadHref();
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
    appendLogText(ev.data + '\n');
    body.scrollTop = body.scrollHeight;
  };
  stream.onerror = async () => {
    try {
      const res = await fetch('api.php?action=status');
      if (res.status === 401) {
        stream.close();
        if (activeStream === stream) activeStream = null;
        redirectIfUnauthenticated(res.status);
      }
    } catch {
      // Keep EventSource's normal reconnect behavior for transient failures.
    }
  };
}

export function confirmModal(message) {
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

export function openRenameModal(runnerId, currentName) {
  document.getElementById('rename-runner-id').value = runnerId;
  document.getElementById('rename-name').value = currentName || '';
  document.getElementById('rename-error').hidden = true;
  document.getElementById('rename-modal').hidden = false;
}

function closeRenameModal() {
  document.getElementById('rename-modal').hidden = true;
}

export function initModals(fetchStatus) {
  document.getElementById('log-search').addEventListener('input', () => {
    const body = document.getElementById('log-modal-body');
    const text = body.textContent;
    body.replaceChildren();
    appendLogText(text);
  });
  document.getElementById('log-modal-close').addEventListener('click', closeLogViewer);
  document.getElementById('log-modal').addEventListener('click', (e) => {
    if (e.target.id === 'log-modal') closeLogViewer();
  });
  document.getElementById('log-range-from').addEventListener('input', updateLogDownloadHref);
  document.getElementById('log-range-to').addEventListener('input', updateLogDownloadHref);

  const logoutBtn = document.getElementById('btn-logout');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
      await post('logout');
      window.location.href = 'login.php';
    });
  }

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
        ({ data } = await post('rename', { runner, name, force: '1' }));
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
}
