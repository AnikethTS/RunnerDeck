export function redirectIfUnauthenticated(status) {
  if (status === 401) window.location.href = 'login.php';
  return status === 401;
}

export async function post(action, params = {}) {
  const body = new URLSearchParams(params);
  let res;
  try {
    res = await fetch(`api.php?action=${action}`, {
      method: 'POST',
      body,
      headers: { 'X-CSRF-Token': window.__CSRF__ || '' },
    });
  } catch {
    throw new Error('The request did not finish. Check the connection and try again.');
  }
  redirectIfUnauthenticated(res.status);
  const text = await res.text();
  let data;
  try {
    data = text === '' ? {} : JSON.parse(text);
  } catch {
    throw new Error(
      res.ok
        ? 'The server returned a response the dashboard could not read.'
        : `The request failed (${res.status}).`,
    );
  }
  if (!data || typeof data !== 'object') {
    throw new Error('The server returned a response the dashboard could not read.');
  }
  return { status: res.status, data };
}
