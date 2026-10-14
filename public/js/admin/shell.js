/**
 * shell.js — desktop-first admin shell (sidebar + topbar + section).
 * Rendered only after guard.js requireAdmin() passes.
 * 4A-1: dashboard section + sidebar (queue link live in 4A-2;
 * 4B entries shown disabled). 4A-2 plugs queue/detail into
 * the section container via renderAdminSection().
 * 4B-1 wires news list/create/detail sections.
 * 4B-2 wires notices list/create/detail sections (mirror of news).
 * 4C-1 wires the read-only roles & permissions viewer.
 * 4C-2 wires user management (#/admin/users) + audit log viewer (#/admin/audit).
 * Copy via t(); DOM via createElement/textContent (CSP-safe).
 */

import { api, setText } from '../api.js?v=4C1';
import { t, applyI18n } from '../i18n.js?v=4C1';
import { clear } from '../page.js?v=4C1';
import { requireAdmin } from './guard.js?v=4C1';
import { renderAdminQueue } from './queue.js?v=4C1';
import { renderAdminDetail } from './detail.js?v=4C1';
import { renderAdminNewsList, renderAdminNewsNew, renderAdminNewsDetail } from './news.js?v=4C1';
import { renderAdminNoticesList, renderAdminNoticesNew, renderAdminNoticesDetail } from './notices.js?v=4C1';
import { renderAdminRoles } from './roles.js?v=4C1';
import { renderAdminUsers } from './users.js?v=4C1';
import { renderAdminAudit } from './audit.js?v=4C1';
import { clearSession } from '../pages/auth.js?v=4C1';
import { clearAdminCache } from './guard.js?v=4C1';

const SECTIONS = [
  { key: 'dashboard', route: 'admin', link: '#/admin', titleKey: 'admin.sidebar_dashboard', enabled: true },
  { key: 'complaints', route: 'admin-complaints', link: '#/admin/complaints', titleKey: 'admin.sidebar_complaints', enabled: true },
  { key: 'news', route: 'admin-news', link: '#/admin/news', titleKey: 'admin.sidebar_news', enabled: true },
  { key: 'notices', route: 'admin-notices', link: '#/admin/notices', titleKey: 'admin.sidebar_notices', enabled: true },
  { key: 'users', route: 'admin-users', link: '#/admin/users', titleKey: 'admin.sidebar_users', enabled: true },
  { key: 'roles', route: 'admin-roles', link: '#/admin/roles', titleKey: 'admin.sidebar_roles', enabled: true },
  { key: 'audit', route: 'admin-audit', link: '#/admin/audit', titleKey: 'admin.sidebar_audit', enabled: true },
];

function sidebar(host, activeKey) {
  const aside = document.createElement('aside');
  aside.className = 'admin-sidebar';
  const nav = document.createElement('nav');
  nav.setAttribute('aria-label', 'admin');
  const ul = document.createElement('ul');
  for (const s of SECTIONS) {
    const li = document.createElement('li');
    if (s.enabled) {
      const a = document.createElement('a');
      a.className = 'sidebar-link';
      a.setAttribute('href', s.link);
      a.setAttribute('data-route', s.route);
      a.setAttribute('data-i18n', s.titleKey);
      setText(a, t(s.titleKey));
      if (s.key === activeKey) {
        a.setAttribute('aria-current', 'page');
      }
      li.appendChild(a);
    } else {
      const span = document.createElement('span');
      span.className = 'sidebar-link is-disabled';
      span.setAttribute('aria-disabled', 'true');
      span.setAttribute('data-i18n', s.titleKey);
      setText(span, t(s.titleKey));
      li.appendChild(span);
    }
    ul.appendChild(li);
  }
  nav.appendChild(ul);
  aside.appendChild(nav);
  host.appendChild(aside);
}

function topbar(host, user) {
  const bar = document.createElement('div');
  bar.className = 'admin-topbar';
  const title = document.createElement('p');
  title.className = 'admin-title';
  setText(title, t('admin.title'));
  bar.appendChild(title);
  const who = document.createElement('span');
  who.className = 'admin-user';
  setText(who, user && user.name ? String(user.name) : '');
  bar.appendChild(who);
  const out = document.createElement('button');
  out.type = 'button';
  out.className = 'btn btn-secondary admin-logout';
  setText(out, t('admin.logout'));
  out.addEventListener('click', async () => {
    await api.post('/logout');
    clearSession();
    clearAdminCache();
    window.dispatchEvent(new CustomEvent('auth:logout'));
    window.location.hash = '#/';
  });
  bar.appendChild(out);
  host.appendChild(bar);
}

async function renderDashboard(section, user, ctx) {
  const head = document.createElement('div');
  const h = document.createElement('h1');
  h.className = 'page-title';
  setText(h, t('admin.sidebar_dashboard'));
  head.appendChild(h);
  const status = document.createElement('p');
  status.className = 'section-status';
  status.setAttribute('role', 'status');
  setText(status, t('state.loading'));
  head.appendChild(status);
  section.appendChild(head);
  const res = await api.get('/complaints?per_page=1');
  if (ctx.dead()) {
    return;
  }
  if (!res.ok) {
    if (res.status === 401) {
      return;
    }
    setText(status, t('state.error') + ' (' + res.code + ')');
    return;
  }
  const pg = res.meta && res.meta.pagination ? res.meta.pagination : null;
  const total = pg ? Number(pg.total) || 0 : 0;
  setText(status, '');
  const grid = document.createElement('div');
  grid.className = 'stat-grid';
  const card = document.createElement('div');
  card.className = 'stat-card';
  const num = document.createElement('p');
  num.className = 'stat-num';
  setText(num, String(total));
  card.appendChild(num);
  const cap = document.createElement('p');
  cap.className = 'meta';
  setText(cap, t('admin.sidebar_complaints'));
  card.appendChild(cap);
  const go = document.createElement('a');
  go.className = 'btn btn-primary btn-link';
  go.setAttribute('href', '#/admin/complaints');
  setText(go, t('admin.sidebar_complaints'));
  card.appendChild(go);
  grid.appendChild(card);
  section.appendChild(grid);
  applyI18n(section);
  void user;
}

/**
 * Render the admin shell; section is 'dashboard' | 'complaints' | 'detail'
 * | 'news' | 'news-new' | 'news-detail' | 'notices' | 'notices-new'
 * | 'notices-detail' | 'roles' | 'users' | 'audit'. Unknown sections fall
 * back to a coming-soon notice (reused for future entries).
 */
export async function renderAdmin(view, section, match, ctx) {
  clear(view);
  const gateRoutes = {
    dashboard: '/admin',
    complaints: '/admin/complaints',
    detail: '/admin/complaints',
    news: '/admin/news',
    'news-new': '/admin/news',
    'news-detail': '/admin/news',
    notices: '/admin/notices',
    'notices-new': '/admin/notices',
    'notices-detail': '/admin/notices',
    roles: '/admin/roles',
    users: '/admin/users',
    audit: '/admin/audit',
  };
  const gate = await requireAdmin(gateRoutes[section] || '/admin/complaints');
  if (ctx.dead() || !gate.ok) {
    return;
  }
  const shell = document.createElement('div');
  shell.className = 'admin';
  sidebar(shell, section === 'detail' ? 'complaints' : (section === 'news-new' || section === 'news-detail' ? 'news' : (section === 'notices-new' || section === 'notices-detail' ? 'notices' : section)));
  const main = document.createElement('div');
  main.className = 'admin-main';
  topbar(main, gate.user);
  const body = document.createElement('div');
  body.className = 'admin-body';
  main.appendChild(body);
  shell.appendChild(main);
  view.appendChild(shell);
  applyI18n(view);
  if (section === 'complaints') {
    await renderAdminQueue(body, gate.user, ctx);
    return;
  }
  if (section === 'detail') {
    await renderAdminDetail(body, match && match[1] ? match[1] : '', gate.user, ctx);
    return;
  }
  if (section === 'news') {
    await renderAdminNewsList(body, gate.user, ctx);
    return;
  }
  if (section === 'news-new') {
    await renderAdminNewsNew(body, gate.user, ctx);
    return;
  }
  if (section === 'news-detail') {
    await renderAdminNewsDetail(body, match && match[1] ? match[1] : '', gate.user, ctx);
    return;
  }
  if (section === 'notices') {
    await renderAdminNoticesList(body, gate.user, ctx);
    return;
  }
  if (section === 'notices-new') {
    await renderAdminNoticesNew(body, gate.user, ctx);
    return;
  }
  if (section === 'notices-detail') {
    await renderAdminNoticesDetail(body, match && match[1] ? match[1] : '', gate.user, ctx);
    return;
  }
  if (section === 'roles') {
    await renderAdminRoles(body, gate.user, ctx);
    return;
  }
  if (section === 'users') {
    await renderAdminUsers(body, gate.user, ctx);
    return;
  }
  if (section === 'audit') {
    await renderAdminAudit(body, gate.user, ctx);
    return;
  }
  if (section !== 'dashboard') {
    const note = document.createElement('p');
    note.className = 'banner-warn';
    note.setAttribute('role', 'status');
    setText(note, t('admin.coming_soon'));
    body.appendChild(note);
    applyI18n(body);
    return;
  }
  await renderDashboard(body, gate.user, ctx);
}
