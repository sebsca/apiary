export function createApiClient({
  apiUrl = './api.php',
  getCsrfToken = () => null,
  onUnauthorized = () => {}
} = {}) {
  async function handleResponse(response, options) {
    const data = await response.json().catch(() => ({}));
    if (response.status === 401) {
      onUnauthorized(!options.suppressAuthRedirect);
      throw new Error(data.error || 'Unauthorized');
    }
    if (!response.ok) {
      throw new Error(data.error || `HTTP ${response.status}`);
    }
    return data;
  }

  function buildUrl(params) {
    const url = new URL(apiUrl, window.location.href);
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
    return url;
  }

  async function get(params, options = {}) {
    const response = await fetch(buildUrl(params), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
      signal: options.signal
    });
    return handleResponse(response, options);
  }

  async function post(params, body, options = {}) {
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json'
    };
    const csrfToken = getCsrfToken();
    if (csrfToken) {
      headers['X-CSRF-Token'] = csrfToken;
    }
    const response = await fetch(buildUrl(params), {
      method: 'POST',
      headers,
      body: JSON.stringify(body ?? {}),
      credentials: 'same-origin'
    });
    return handleResponse(response, options);
  }

  return { get, post };
}
