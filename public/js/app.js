/**
 * app.js — SPA entry (visual shell wiring; copy comes from locales only).
 * No hardcoded user-facing strings. No tokens in storage.
 * Hash router: #/ home, #/news, #/news/{slug}, #/notices, #/services,
 * #/unions, #/login, #/register, #/me, unknown hashes -> 404 view.
 * CAPACITOR BUILD FLAG: bundle public/js/app.js + api.js + i18n.js +
 * router.js + page.js + pages/*.js (+ locales) for Public+Citizen only.
 * Admin UI (public/js/admin/shell.js + future admin pages) is web-only
 * and MUST be excluded from Capacitor. public/js/admin/guard.js is
 * shared role-check glue (no DOM, no fetch at import).
 */

import { dbg, buildTag } from './debug.js?v=4C1';
import { initI18n, setLocale, getLocale, applyI18n, t } from './i18n.js?v=4C1';
import { setText, getBearer } from './api.js?v=4C1';
import { startRouter } from './router.js?v=4C1';
import { initHome } from './pages/home.js?v=4C1';
import { renderNewsList, renderNewsDetail } from './pages/news.js?v=4C1';
import { renderNoticesList } from './pages/notices.js?v=4C1';
import { renderServicesList } from './pages/services.js?v=4C1';
import { renderUnionsList } from './pages/unions.js?v=4C1';
import { renderLogin, renderRegister, renderMe, renderAuthNav, clearSession, getUser, restoreSession } from './pages/auth.js?v=4C1';
import { renderComplaintNew, renderComplaintMine, renderComplaintDetail } from './pages/complaints.js?v=4C1';
import { getCachedAdmin, isStaffRoles, clearAdminCache } from './admin/guard.js?v=4C1';
import { renderAdmin } from './admin/shell.js?v=4C1';

function wireLocaleToggle() {
  const btn = document.getElementById('locale-toggle');
  if (!btn) {
    return;
  }
  btn.addEventListener('click', async () => {
    const next = getLocale() === 'bn' ? 'en' : 'bn';
    await setLocale(next, document);
    refreshToggle(btn);
  });
  refreshToggle(btn);
}

function refreshToggle(btn) {
  btn.textContent = t('locale.toggle_label');
  btn.setAttribute('aria-label', t('locale.toggle_aria'));
}

function wireAuthEvents() {
  // 401 anywhere (except the auth forms themselves, which handle their
  // own 401): drop the dead session and send the user to #/login with
  // the current route preserved as ?next= for post-login return.
  window.addEventListener('auth:unauthorized', () => {
    clearSession();
    renderAuthNav();
    const cur = window.location.hash || '#/';
    if (cur === '#/login' || cur.startsWith('#/login?') || cur === '#/register' || cur.startsWith('#/register?')) {
      return;
    }
    const next = cur.startsWith('#/') ? cur.slice(1) : '/me';
    window.location.hash = '#/login?next=' + encodeURIComponent(next) + '&expired=1';
  });
  const rerenderNav = () => {
    dbg('nav', 'rerender:auth-success');
    renderAuthNav();
    renderAdminNav();
  };
  window.addEventListener('auth:success', rerenderNav);
  window.addEventListener('auth:logout', () => {
    clearAdminCache();
    renderAuthNav();
  });
  window.addEventListener('admin:denied', () => {
    adminDeniedPending = true;
    showAdminDenied();
  });
  window.addEventListener('locale:changed', () => {
    const btn = document.getElementById('locale-toggle');
    if (btn) {
      refreshToggle(btn);
    }
  });
  // bfcache restore: the DOM may be frozen mid-session while the heap
  // was discarded (or kept). Attempt session restore (Option D);
  // fall back to a clean logged-out nav.
  window.addEventListener('pageshow', async (ev) => {
    dbg('pageshow', 'persisted=' + !!((ev && ev.persisted)));
    if (!ev || !ev.persisted) {
      return;
    }
    const { getBearer } = await import('./api.js?v=4C1');
    if (getBearer() === '') {
      clearAdminCache();
      const ok = await restoreSession();
      renderAuthNav();
      if (ok) {
        renderAdminNav();
      }
      return;
    }
    const { api } = await import('./api.js?v=4C1');
    const me = await api.get('/me');
    if (me.ok) {
      renderAuthNav();
      renderAdminNav();
    } else {
      clearSession();
      clearAdminCache();
      renderAuthNav();
    }
  });
}

function renderNotFound(view) {
  view.replaceChildren();
  const wrap = document.createElement('div');
  wrap.className = 'container not-found';
  const h = document.createElement('h1');
  h.className = 'page-title';
  setText(h, t('state.not_found'));
  wrap.appendChild(h);
  const msg = document.createElement('p');
  setText(msg, t('state.not_found_message'));
  wrap.appendChild(msg);
  const a = document.createElement('a');
  a.className = 'back-link';
  a.setAttribute('href', '#/');
  setText(a, t('state.back_home'));
  wrap.appendChild(a);
  view.appendChild(wrap);
}

let adminDeniedPending = false;

function showAdminDenied() {
  if (!adminDeniedPending) {
    return;
  }
  const home = document.getElementById('view-home');
  if (!home || home.hidden || home.querySelector('[data-denied-banner]')) {
    return;
  }
  adminDeniedPending = false;
  const p = document.createElement('p');
  p.className = 'banner-warn';
  p.setAttribute('role', 'alert');
  p.setAttribute('data-denied-banner', '1');
  setText(p, t('admin.no_permission'));
  home.prepend(p);
}

/** Append the Admin Panel link for staff roles (citizens see nothing). */
function renderAdminNav() {
  const host = document.getElementById('auth-nav');
  if (!host || host.querySelector('[data-route="admin"]')) {
    return;
  }
  const u = getUser() || getCachedAdmin();
  if (!u || !isStaffRoles(u.roles)) {
    dbg('nav', 'renderAdminNav roles=' + JSON.stringify((u && u.roles) || null) + ' appended=false');
    return;
  }
  const li = document.createElement('li');
  const a = document.createElement('a');
  a.setAttribute('href', '#/admin');
  a.setAttribute('data-route', 'admin');
  a.setAttribute('data-i18n', 'admin.title');
  setText(a, t('admin.title'));
  li.appendChild(a);
  host.appendChild(li);
  dbg('nav', 'renderAdminNav roles=' + JSON.stringify(u.roles) + ' appended=true');
}

async function renderHome(home, ctx) {
  if (ctx.dead()) {
    return;
  }
  await initHome(home);
  showAdminDenied();
}

async function main() {
  // Boot (Option D, replaces FIX 2-B's unconditional clearSession):
  // a surviving session cookie + stored CSRF restores the user via
  // /me. Without stored CSRF there is nothing to restore with.
  // SECURITY: only CSRF is re-read from per-tab storage; the bearer
  // stays heap-only, and CSRF is useless without the live session
  // cookie (see auth.js setSession comment).
  clearAdminCache();
  dbg('boot', 'build=' + buildTag() + ' bearer=' + (getBearer() !== '') + ' hash=' + ((window.location.hash || '(none)').split('?')[0]));
  await initI18n(document);
  wireLocaleToggle();
  wireAuthEvents();
  await restoreSession();
  applyI18n(document);
  renderAuthNav();
  renderAdminNav();
  const view = document.getElementById('view');
  const home = document.getElementById('view-home');
  if (!view || !home) {
    await initHome(document);
    return;
  }
  startRouter({
    view,
    home,
    renderHome,
    renderNotFound,
    table: [
      { key: 'home', match: (h) => (h === '#/' ? [] : null), render: () => {} },
      { key: 'news', match: (h) => (h === '#/news' ? [] : null), render: renderNewsList },
      {
        key: 'news',
        match: (h) => {
          if (!h.startsWith('#/news/')) {
            return null;
          }
          const rest = h.slice('#/news/'.length).split('?')[0];
          return rest ? [h, rest] : null;
        },
        render: renderNewsDetail,
      },
      { key: 'notices', match: (h) => (h === '#/notices' ? [] : null), render: renderNoticesList },
      { key: 'services', match: (h) => (h === '#/services' ? [] : null), render: renderServicesList },
      { key: 'unions', match: (h) => (h === '#/unions' ? [] : null), render: renderUnionsList },
      {
        key: 'login',
        match: (h) => {
          if (h === '#/login') {
            return [h, ''];
          }
          return h.startsWith('#/login?') ? [h, h.slice('#/login'.length)] : null;
        },
        render: renderLogin,
      },
      {
        key: 'register',
        match: (h) => {
          if (h === '#/register') {
            return [h, ''];
          }
          return h.startsWith('#/register?') ? [h, h.slice('#/register'.length)] : null;
        },
        render: renderRegister,
      },
      { key: 'me', match: (h) => (h === '#/me' ? [] : null), render: renderMe },
      { key: 'admin', match: (h) => (h === '#/admin' ? [] : null), render: (el, m, ctx) => renderAdmin(el, 'dashboard', m, ctx) },
      { key: 'admin-complaints', match: (h) => (h === '#/admin/complaints' ? [] : null), render: (el, m, ctx) => renderAdmin(el, 'complaints', m, ctx) },
      {
        key: 'admin-complaints',
        match: (h) => {
          if (!h.startsWith('#/admin/complaints/')) {
            return null;
          }
          const rest = h.slice('#/admin/complaints/'.length).split('?')[0];
          return rest ? [h, rest] : null;
        },
        render: (el, m, ctx) => renderAdmin(el, 'detail', m, ctx),
      },
      { key: 'admin-news', match: (h) => (h === '#/admin/news' ? [] : null), render: (el, m, ctx) => renderAdmin(el, 'news', m, ctx) },
      { key: 'admin-news', match: (h) => (h === '#/admin/news/new' ? [] : null), render: (el, m, ctx) => renderAdmin(el, 'news-new', m, ctx) },
      {
        key: 'admin-news',
        match: (h) => {
          if (!h.startsWith('#/admin/news/')) {
            return null;
          }
          const rest = h.slice('#/admin/news/'.length).split('?')[0];
          return rest && /^\d+$/.test(rest) ? [h, rest] : null;
        },
        render: (el, m, ctx) => renderAdmin(el, 'news-detail', m, ctx),
      },
      { key: 'admin-notices', match: (h) => (h === '#/admin/notices' ? [] : null), render: (el, m, ctx) => renderAdmin(el, 'notices', m, ctx) },
      { key: 'admin-notices', match: (h) => (h === '#/admin/notices/new' ? [] : null), render: (el, m, ctx) => renderAdmin(el, 'notices-new', m, ctx) },
      {
        key: 'admin-notices',
        match: (h) => {
          if (!h.startsWith('#/admin/notices/')) {
            return null;
          }
          const rest = h.slice('#/admin/notices/'.length).split('?')[0];
          return rest && /^\d+$/.test(rest) ? [h, rest] : null;
        },
        render: (el, m, ctx) => renderAdmin(el, 'notices-detail', m, ctx),
      },
      { key: 'admin-roles', match: (h) => (h === '#/admin/roles' ? [] : null), render: (el, m, ctx) => renderAdmin(el, 'roles', m, ctx) },
      { key: 'admin-users', match: (h) => (h === '#/admin/users' ? [] : null), render: (el, m, ctx) => renderAdmin(el, 'users', m, ctx) },
      { key: 'admin-audit', match: (h) => (h === '#/admin/audit' ? [] : null), render: (el, m, ctx) => renderAdmin(el, 'audit', m, ctx) },
      { key: 'complaints-new', match: (h) => (h === '#/complaints/new' ? [] : null), render: renderComplaintNew },
      { key: 'complaints-mine', match: (h) => (h === '#/complaints/mine' ? [] : null), render: renderComplaintMine },
      {
        key: 'complaints-detail',
        match: (h) => {
          if (!h.startsWith('#/complaints/')) {
            return null;
          }
          const rest = h.slice('#/complaints/'.length).split('?')[0];
          return rest ? [h, rest] : null;
        },
        render: renderComplaintDetail,
      },
    ],
  });
}

main().catch(() => {
  const mainEl = document.getElementById('main');
  if (mainEl) {
    mainEl.setAttribute('data-boot-error', '1');
  }
});
