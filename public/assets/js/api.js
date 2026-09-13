export function redirectIfUnauthenticated(status) {
  if (status === 401) window.location.href = 'login.php';
  return status === 401;
}

export async function post(action, params = {}) {
  const body = new URLSearchParams(params);
  const res = await fetch(`api.php?action=${action}`, {
    method: 'POST',
    body,
    headers: { 'X-CSRF-Token': window.__CSRF__ || '' },
  });
  redirectIfUnauthenticated(res.status);
  const data = await res.json();
  return { status: res.status, data };
}
