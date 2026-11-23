// The ONLY place the front end talks to the server. Every screen calls api(...).
// For a Capacitor phone app later, set window.CHORE_API_BASE to the full API address before loading app.js.

const BASE = window.CHORE_API_BASE || 'api';
const PARENT_KEY = 'ct.parentToken';
const KID_KEY = 'ct.kidToken';
const KID_LINK_KEY = 'ct.kidLink';

export class ApiError extends Error {
  constructor(message, status, data) {
    super(message);
    this.status = status;
    this.data = data || {};
  }
}

function read(key) {
  try { return localStorage.getItem(key); } catch { return null; }
}
function write(key, value) {
  try {
    if (value === null) localStorage.removeItem(key); else localStorage.setItem(key, value);
  } catch { /* private mode etc.: carry on without saving */ }
}

export const session = {
  parentToken: () => read(PARENT_KEY),
  setParentToken: (t) => write(PARENT_KEY, t),
  kidToken: () => read(KID_KEY),
  setKidToken: (t) => write(KID_KEY, t),
  kidLink: () => read(KID_LINK_KEY),
  setKidLink: (t) => write(KID_LINK_KEY, t),
};

/**
 * api('POST', '/login', {email, password})
 * options.as: 'parent' (default when signed in), 'kid', or 'none' to send no token.
 */
export async function api(method, path, body, options = {}) {
  const as = options.as || 'none';
  const headers = { Accept: 'application/json' };
  const token = as === 'parent' ? session.parentToken() : as === 'kid' ? session.kidToken() : null;
  if (token) headers.Authorization = `Bearer ${token}`;
  const init = { method, headers };
  if (body !== undefined && method !== 'GET') {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }
  let res;
  try {
    res = await fetch(BASE + path, init);
  } catch {
    throw new ApiError('Could not reach the server. Check your connection and try again.', 0);
  }
  let data = {};
  try { data = await res.json(); } catch { /* empty body */ }
  if (!res.ok) {
    if (res.status === 401 && token) {
      // Signed out (token expired or revoked): forget it and let the router send them to sign in.
      if (as === 'parent') session.setParentToken(null);
      if (as === 'kid') session.setKidToken(null);
      window.dispatchEvent(new CustomEvent('ct:signed-out', { detail: as }));
    }
    throw new ApiError(data.error || `Something went wrong (${res.status}).`, res.status, data);
  }
  if (path !== '/clock') window.dispatchEvent(new Event('ct:api-activity'));
  return data;
}
