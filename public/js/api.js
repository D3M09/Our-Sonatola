/**
 * api.js — security glue (hand-written, reviewable).
 * Single fetch gateway for the SPA. All UI fetches go through here.
 *
 * Rules enforced:
 * - API_BASE from window.__API_BASE__ || '/api/v1' (same-origin default).
 * - Web auth = HttpOnly session cookie + credentials:'include'.
 *   Bearer tokens are NEVER stored here (no localStorage/sessionStorage/URL).
 *   A module-scoped Bearer (set via setBearer) is attached as
 *   `Authorization: Bearer` when present; it vanishes on page reload.
 * - X-CSRF-Token attached on POST/PUT/DELETE except register/login/reset.
 * - 401 -> dispatch 'auth:unauthorized' (no body logging).
 * - 403 -> returned as data (caller hides/disables; never masked as 404).
 * - 429 -> returned with retry hint.
 * - Never console.logs response bodies containing user data.
 */

import { dbg } from './debug.js?v=4C1';

const API_BASE = (typeof window !== 'undefined' && window.__API_BASE__) || '/api/v1';

let csrfToken = '';
let bearerToken = '';

export function setCsrf(token) {
  csrfToken = typeof token === 'string' ? token : '';
}

export function getCsrf() {
  if (csrfToken) {
    return csrfToken;
  }
  // Reload fallback: per-tab stored CSRF (Option D). Bearer itself is
  // never read from storage — only the CSRF, useless without the
  // HttpOnly session cookie.
  try {
    return window.sessionStorage.getItem('csrf') || '';
  } catch (storageError) {
    return '';
  }
}

/** Module scope only — never persisted. Lost on reload by design. */
export function setBearer(token) {
  bearerToken = typeof token === 'string' ? token : '';
}

export function getBearer() {
  return bearerToken;
}

const NO_CSRF_PATHS = [
  '/register',
  '/login',
  '/password/request',
  '/password/reset',
];

function needsCsrf(method, path) {
  if (method !== 'POST' && method !== 'PUT' && method !== 'PATCH' && method !== 'DELETE') {
    return false;
  }
  for (const p of NO_CSRF_PATHS) {
    if (path === p || path.startsWith(p + '?')) {
      return false;
    }
  }
  return true;
}

function envelopeError(status, payload) {
  const err = payload && payload.error ? payload.error : null;
  return {
    ok: false,
    status,
    code: err && err.code ? String(err.code) : 'http_' + status,
  };
}

async function request(method, path, body) {
  const headers = { Accept: 'application/json' };
  const init = { method, headers, credentials: 'include' };
  if (bearerToken) {
    headers.Authorization = 'Bearer ' + bearerToken;
  }
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }
  const csrf = getCsrf();
  if (needsCsrf(method, path) && csrf) {
    headers['X-CSRF-Token'] = csrf;
  }
  let res;
  try {
    res = await fetch(API_BASE + path, init);
  } catch (networkError) {
    dbg('api', method + ' ' + path + ' -> network_error');
    return { ok: false, status: 0, code: 'network_error' };
  }
  if (res.status === 401) {
    setCsrf('');
    if (typeof window !== 'undefined') {
      window.dispatchEvent(new CustomEvent('auth:unauthorized'));
    }
    dbg('api', method + ' ' + path + ' -> 401');
    return { ok: false, status: 401, code: 'unauthorized' };
  }
  if (res.status === 429) {
    dbg('api', method + ' ' + path + ' -> 429');
    return { ok: false, status: 429, code: 'rate_limited' };
  }
  let payload = null;
  try {
    payload = await res.json();
  } catch (parseError) {
    dbg('api', method + ' ' + path + ' -> bad_response');
    return { ok: false, status: res.status, code: 'bad_response' };
  }
  if (!res.ok || (payload && payload.success === false)) {
    const fail = envelopeError(res.status, payload);
    dbg('api', method + ' ' + path + ' -> ' + res.status + ' ' + fail.code);
    return fail;
  }
  dbg('api', method + ' ' + path + ' -> ' + res.status + ' ok');
  return { ok: true, status: res.status, data: payload ? payload.data : null, meta: payload ? payload.meta : null };
}

export const api = {
  get(path) {
    return request('GET', path);
  },
  post(path, body) {
    return request('POST', path, body);
  },
  put(path, body) {
    return request('PUT', path, body);
  },
  del(path) {
    return request('DELETE', path);
  },
};

/**
 * Set plain text safely (uses textContent; never innerHTML).
 * @param {Element|null} el
 * @param {unknown} value
 */
export function setText(el, value) {
  if (!el) {
    return;
  }
  el.textContent = value === null || value === undefined ? '' : String(value);
}

/**
 * Escape helper for the rare case a string must be interpolated
 * (prefer setText/createElement instead).
 */
export function esc(value) {
  return String(value === null || value === undefined ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
