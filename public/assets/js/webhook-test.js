(() => {
  const btn = document.getElementById('settings-test-webhook');
  const urlInput = document.getElementById('settings-crash-webhook-url');
  const result = document.getElementById('settings-test-webhook-result');
  const csrfInput = document.querySelector('input[name="csrf_token"]');
  if (!btn || !urlInput || !result || !csrfInput) return;

  btn.addEventListener('click', async () => {
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Testing…';
    result.hidden = true;

    try {
      const body = new URLSearchParams({
        csrf_token: csrfInput.value,
        crash_webhook_url: urlInput.value.trim(),
      });
      const res = await fetch('api.php?action=test_webhook', { method: 'POST', body });
      if (res.status === 401) {
        window.location.href = 'login.php';
        return;
      }
      const data = await res.json();
      result.textContent = data.message || (data.ok ? 'Test notification sent.' : 'Delivery failed.');
      result.classList.toggle('setup-error', !data.ok);
      result.hidden = false;
    } catch {
      result.textContent = 'Could not reach the server.';
      result.classList.add('setup-error');
      result.hidden = false;
    } finally {
      btn.disabled = false;
      btn.textContent = originalText;
    }
  });
})();
