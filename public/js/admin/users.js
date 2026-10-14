/**
 * users.js — admin user management (#/admin/users).
 * Read:    GET  /admin/users?status&q&page&per_page   (perm user.view; union-scoped server-side)
 * Status:  PUT  /admin/users/{id}/status {account_status} (perm user.manage; CSRF auto via api.js)
 * Roles:   POST /admin/users/{id}/roles  {role}           (perm role.assign; super_admin grant
 *                                                            is super-admin-only — mirrored here
 *                                                            and enforced by the backend)
 * Role list: GET /admin/roles (perm role.view).
 *
 * UX-only gating: controls for missing permissions are simply not rendered.
 * The backend 403 is the real enforcement (defense in depth). Copy via t();
 * DOM via createElement/textContent (CSP-safe) — never innerHTML.
 */

import { api, setText } from '../api.js?v=4C1';
import { t, applyI18n } from '../i18n.js?v=4C1';
import {
  clear, fmtDate, pickName, statusLine, showSkeleton,
  showEmpty, showError, pager,
} from '../page.js?v=4C1';

const PER_PAGE = 20;
const ACCOUNT_STATUSES = ['pending', 'active', 'suspended', 'disabled'];

const state = { page: 1, status: 'all', q: '' };
let unionsCache = null;
let rolesCache = null;

function hasPerm(user, perm) {
  return !!user && Array.isArray(user.permissions) && user.permissions.includes(perm);
}

function isSuperAdmin(user) {
  return !!user && Array.isArray(user.roles) && user.roles.includes('super_admin');
}

async function loadUnions() {
  if (unionsCache) {
    return unionsCache;
  }
  const res = await api.get('/unions');
  unionsCache = res.ok && Array.isArray(res.data) ? res.data : [];
  return unionsCache;
}

async function loadRoles() {
  if (rolesCache) {
    return rolesCache;
  }
  const res = await api.get('/admin/roles');
  rolesCache = res.ok && Array.isArray(res.data) ? res.data : [];
  return rolesCache;
}

function unionLabel(unions, unionId) {
  if (unionId === null || unionId === undefined || unionId === '') {
    return t('admin.users_union_none');
  }
  const found = unions.find((u) => String(u.id) === String(unionId));
  return found ? pickName(found) : String(unionId);
}

function accountStatusText(status) {
  const s = String(status || 'pending');
  return ACCOUNT_STATUSES.includes(s) ? t('admin.users_status_' + s) : s;
}

/* ---------- filters ---------- */

function filterBar(head, onApply) {
  const bar = document.createElement('div');
  bar.className = 'filter-bar';

  const statusLabel = document.createElement('label');
  statusLabel.className = 'filter-inline';
  const statusSpan = document.createElement('span');
  setText(statusSpan, t('admin.users_filter_status'));
  statusLabel.appendChild(statusSpan);
  const statusSel = document.createElement('select');
  statusSel.className = 'filter-select';
  const allOpt = document.createElement('option');
  allOpt.value = 'all';
  setText(allOpt, t('admin.users_filter_all'));
  statusSel.appendChild(allOpt);
  for (const s of ACCOUNT_STATUSES) {
    const opt = document.createElement('option');
    opt.value = s;
    setText(opt, t('admin.users_status_' + s));
    statusSel.appendChild(opt);
  }
  statusSel.value = state.status;
  statusLabel.appendChild(statusSel);
  bar.appendChild(statusLabel);

  const searchLabel = document.createElement('label');
  searchLabel.className = 'filter-inline';
  const searchSpan = document.createElement('span');
  setText(searchSpan, t('admin.users_filter_search'));
  searchLabel.appendChild(searchSpan);
  const search = document.createElement('input');
  search.type = 'search';
  search.className = 'filter-select';
  search.setAttribute('autocomplete', 'off');
  search.value = state.q;
  searchLabel.appendChild(search);
  bar.appendChild(searchLabel);

  const apply = document.createElement('button');
  apply.type = 'button';
  apply.className = 'btn btn-primary';
  setText(apply, t('admin.users_filter_apply'));
  apply.addEventListener('click', () => {
    state.status = statusSel.value;
    state.q = search.value.trim();
    state.page = 1;
    onApply();
  });
  bar.appendChild(apply);

  const clearBtn = document.createElement('button');
  clearBtn.type = 'button';
  clearBtn.className = 'btn btn-secondary';
  setText(clearBtn, t('admin.users_filter_clear'));
  clearBtn.addEventListener('click', () => {
    state.status = 'all';
    state.q = '';
    state.page = 1;
    statusSel.value = 'all';
    search.value = '';
    onApply();
  });
  bar.appendChild(clearBtn);

  head.appendChild(bar);
}

/* ---------- row actions ---------- */

function statusControls(row, user, onMsg) {
  if (!hasPerm(user, 'user.manage')) {
    return null;
  }
  const wrap = document.createElement('div');
  wrap.className = 'row-actions';
  const sel = document.createElement('select');
  sel.className = 'filter-select';
  for (const s of ACCOUNT_STATUSES) {
    const opt = document.createElement('option');
    opt.value = s;
    setText(opt, t('admin.users_status_' + s));
    sel.appendChild(opt);
  }
  sel.value = ACCOUNT_STATUSES.includes(String(row.account_status)) ? String(row.account_status) : 'pending';
  sel.setAttribute('aria-label', t('admin.users_col_status'));
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn btn-primary btn-small';
  setText(btn, t('admin.users_save'));
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    const res = await api.put('/admin/users/' + String(row.id) + '/status', { account_status: sel.value });
    btn.disabled = false;
    if (res.status === 401) {
      return;
    }
    if (!res.ok) {
      onMsg(t(res.status === 403 ? 'admin.no_permission' : 'admin.users_status_error') + ' (' + res.code + ')', false);
      return;
    }
    onMsg(t('admin.users_status_saved'), true);
  });
  wrap.appendChild(sel);
  wrap.appendChild(btn);
  return wrap;
}

function roleControls(row, user, roles, onMsg) {
  if (!hasPerm(user, 'role.assign')) {
    return null;
  }
  const options = roles.filter((r) => {
    if (String(r.slug) === 'super_admin') {
      return isSuperAdmin(user);
    }
    return true;
  });
  if (options.length === 0) {
    return null;
  }
  const wrap = document.createElement('div');
  wrap.className = 'row-actions';
  const sel = document.createElement('select');
  sel.className = 'filter-select';
  const pick = document.createElement('option');
  pick.value = '';
  setText(pick, t('admin.users_role_pick'));
  sel.appendChild(pick);
  for (const r of options) {
    const opt = document.createElement('option');
    opt.value = String(r.slug);
    setText(opt, r.name != null ? String(r.name) : String(r.slug));
    sel.appendChild(opt);
  }
  sel.setAttribute('aria-label', t('admin.users_role_assign'));
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn btn-secondary btn-small';
  setText(btn, t('admin.users_role_assign'));
  btn.addEventListener('click', async () => {
    if (sel.value === '') {
      onMsg(t('admin.users_role_pick'), false);
      return;
    }
    btn.disabled = true;
    const res = await api.post('/admin/users/' + String(row.id) + '/roles', { role: sel.value });
    btn.disabled = false;
    if (res.status === 401) {
      return;
    }
    if (!res.ok) {
      onMsg(t(res.status === 403 ? 'admin.no_permission' : 'admin.users_role_error') + ' (' + res.code + ')', false);
      return;
    }
    onMsg(t('admin.users_role_saved'), true);
  });
  wrap.appendChild(sel);
  wrap.appendChild(btn);
  return wrap;
}

/* ---------- table ---------- */

function renderTable(box, items, unions, roles, user, onMsg) {
  clear(box);
  const wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  const table = document.createElement('table');
  table.className = 'data-table';
  const thead = document.createElement('thead');
  const hr = document.createElement('tr');
  for (const key of [
    'admin.users_col_name', 'admin.users_col_email', 'admin.users_col_phone',
    'admin.users_col_union', 'admin.users_col_status', 'admin.users_col_actions',
  ]) {
    const th = document.createElement('th');
    setText(th, t(key));
    th.setAttribute('scope', 'col');
    hr.appendChild(th);
  }
  thead.appendChild(hr);
  table.appendChild(thead);
  const tbody = document.createElement('tbody');
  for (const row of items) {
    const tr = document.createElement('tr');

    const tdName = document.createElement('td');
    setText(tdName, row.name);
    tr.appendChild(tdName);

    const tdEmail = document.createElement('td');
    tdEmail.className = 'mono';
    setText(tdEmail, row.email);
    tr.appendChild(tdEmail);

    const tdPhone = document.createElement('td');
    setText(tdPhone, row.phone != null ? String(row.phone) : '');
    tr.appendChild(tdPhone);

    const tdUnion = document.createElement('td');
    setText(tdUnion, unionLabel(unions, row.union_id));
    tr.appendChild(tdUnion);

    const tdStatus = document.createElement('td');
    const badge = document.createElement('span');
    badge.className = 'badge badge-' + String(row.account_status || 'pending');
    setText(badge, accountStatusText(row.account_status));
    tdStatus.appendChild(badge);
    tr.appendChild(tdStatus);

    const tdAct = document.createElement('td');
    const controls = document.createElement('div');
    controls.className = 'row-actions-stack';
    const sc = statusControls(row, user, onMsg);
    if (sc) {
      controls.appendChild(sc);
    }
    const rc = roleControls(row, user, roles, onMsg);
    if (rc) {
      controls.appendChild(rc);
    }
    if (controls.childElementCount === 0) {
      setText(tdAct, t('admin.no_permission'));
    } else {
      tdAct.appendChild(controls);
    }
    tr.appendChild(tdAct);

    tbody.appendChild(tr);
  }
  table.appendChild(tbody);
  wrap.appendChild(table);
  box.appendChild(wrap);
}

/* ---------- load ---------- */

async function load(box, status, pagerBox, unions, roles, user, ctx) {
  const onMsg = (msg, ok) => {
    setText(status, msg);
    status.className = ok ? 'section-status banner-success' : 'section-status';
  };
  setText(status, t('state.loading'));
  status.className = 'section-status';
  clear(box);
  clear(pagerBox);
  const skel = document.createElement('div');
  showSkeleton(skel, 3);
  box.appendChild(skel);

  let path = '/admin/users?page=' + state.page + '&per_page=' + PER_PAGE;
  if (state.status !== 'all') {
    path += '&status=' + encodeURIComponent(state.status);
  }
  if (state.q !== '') {
    path += '&q=' + encodeURIComponent(state.q);
  }
  const res = await api.get(path);
  if (ctx.dead()) {
    return;
  }
  if (!res.ok) {
    if (res.status === 401) {
      return;
    }
    clear(box);
    if (res.status === 403) {
      // Missing user.view: retry cannot grant a permission — no retry button.
      setText(status, t('admin.no_permission') + ' (' + res.code + ')');
      return;
    }
    showError(status, res.code, () => load(box, status, pagerBox, unions, roles, user, ctx), 'admin.users_error', 'state.retry');
    return;
  }
  const items = Array.isArray(res.data) ? res.data : [];
  const pg = res.meta && res.meta.pagination ? res.meta.pagination : null;
  const totalPages = pg ? Math.max(1, Number(pg.total_pages) || 1) : 1;
  clear(box);
  if (items.length === 0) {
    showEmpty(status, 'admin.users_empty');
    return;
  }
  setText(status, t('admin.users_list_title') + ' (' + (pg ? Number(pg.total) || items.length : items.length) + ')');
  renderTable(box, items, unions, roles, user, onMsg);
  clear(pagerBox);
  if (totalPages > 1) {
    pager(pagerBox, state.page, totalPages, (p) => {
      state.page = p;
      load(box, status, pagerBox, unions, roles, user, ctx);
    }, 'news.pagination_prev', 'news.pagination_next', 'news.page_indicator');
  }
}

/* ---------- entry ---------- */

export async function renderAdminUsers(body, user, ctx) {
  clear(body);
  const h = document.createElement('h1');
  h.className = 'page-title';
  setText(h, t('admin.users_list_title'));
  body.appendChild(h);

  // Fetch lookup tables up-front (gated by their own permissions).
  const unions = await loadUnions();
  let roles = [];
  if (hasPerm(user, 'role.assign') && hasPerm(user, 'role.view')) {
    roles = await loadRoles();
  }
  if (ctx.dead()) {
    return;
  }

  const refs = { status: null, box: null, pagerBox: null };
  filterBar(body, () => {
    if (refs.status && refs.box && refs.pagerBox) {
      load(refs.box, refs.status, refs.pagerBox, unions, roles, user, ctx);
    }
  });
  refs.status = statusLine(body);
  refs.box = document.createElement('div');
  refs.box.setAttribute('data-users-box', '1');
  body.appendChild(refs.box);
  refs.pagerBox = document.createElement('div');
  refs.pagerBox.setAttribute('data-users-pager', '1');
  body.appendChild(refs.pagerBox);
  applyI18n(body);
  await load(refs.box, refs.status, refs.pagerBox, unions, roles, user, ctx);
}
