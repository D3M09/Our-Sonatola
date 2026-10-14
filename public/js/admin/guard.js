/**
 * guard.js — admin role gate (hand-written glue).
 * requireAdmin() fetches GET /api/v1/me and allows only staff roles.
 * UI hiding is UX only; the backend enforces 403 on every call.
 * Role result cached in-memory (60s) to avoid re-fetch per route.
 * Bearer stays in api.js module scope; nothing persisted here.
 */

import { api, getBearer } from '../api.js?v=4C1';
import { dbg } from '../debug.js?v=4C1';

const STAFF_ROLES = ['super_admin', 'upazila_admin', 'union_admin', 'service_officer'];
const CACHE_MS = 60 * 1000;

let cache = null;

export function staffRoles() {
  return STAFF_ROLES.slice();
}

export function isStaffRoles(roles) {
  if (!Array.isArray(roles)) {
    return false;
  }
  return roles.some((r) => STAFF_ROLES.includes(String(r)));
}

/** Global scope (may filter any union) vs union-forced scope. */
export function isGlobalScope(user) {
  if (!user) {
    return false;
  }
  const roles = Array.isArray(user.roles) ? user.roles.map(String) : [];
  if (roles.includes('super_admin') || roles.includes('upazila_admin')) {
    return true;
  }
  return user.union_scoped !== true;
}

export function getCachedAdmin() {
  if (cache && Date.now() - cache.at < CACHE_MS) {
    return cache.user;
  }
  return null;
}

export function clearAdminCache() {
  cache = null;
}

async function fetchMe() {
  const res = await api.get('/me');
  if (!res.ok || !res.data) {
    return null;
  }
  return res.data;
}

/**
 * Gate for admin routes. Returns {ok:true,user} or redirects + {ok:false}.
 * Identity is server-verified via GET /me (bearer OR session cookie) —
 * the heap bearer is deliberately NOT consulted: after a reload the
 * bearer is gone while the session may be alive (Option D), and a
 * stale bearer must never grant anything without a fresh /me.
 * - No verifiable user (/me 401) -> #/login?next=<route>
 * - Citizen-only roles -> #/ + 'admin:denied' event (app shows message)
 */
export async function requireAdmin(routeHash) {
  const next = typeof routeHash === 'string' && routeHash.startsWith('/') ? routeHash : '/admin';
  const hasBearer = getBearer() !== '';
  const cached = getCachedAdmin();
  dbg('guard', 'start route=' + next + ' bearer=' + hasBearer + ' cached=' + !!cached);
  dbg('guard-check', 'bearer=' + hasBearer + ' cached=' + (cached ? 'yes' : 'no') + ' getUser=n/a (single source: guard cache + /me)');
  let user = cached;
  if (!user) {
    user = await fetchMe();
    if (user) {
      cache = { at: Date.now(), user };
    }
  }
  if (!user) {
    dbg('guard', 'end decision=login (me failed)');
    window.location.hash = '#/login?next=' + encodeURIComponent(next);
    return { ok: false };
  }
  if (!isStaffRoles(user.roles)) {
    dbg('guard', 'end decision=denied roles=' + JSON.stringify(user.roles || null));
    window.location.hash = '#/';
    window.dispatchEvent(new CustomEvent('admin:denied'));
    return { ok: false };
  }
  dbg('guard', 'end decision=ok roles=' + JSON.stringify(user.roles));
  return { ok: true, user };
}
