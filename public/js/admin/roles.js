/**
 * roles.js — read-only Roles & Permissions viewer (#/admin/roles).
 * Phase 4C-1, Option A: two separate read-only tables.
 * GET /api/v1/admin/roles and GET /api/v1/admin/permissions both
 * return plain (non-paginated) {success, data:[...]} envelopes and
 * both require the `role.view` permission. Neither response carries
 * the role_permissions mapping (the backend only resolves it
 * server-side for the current user), so per-role permission mapping
 * is NOT shown and never hardcoded here — a localized note says so.
 * 403 (e.g. union_admin, who lacks role.view) is shown honestly via
 * the existing admin.no_permission copy with NO retry button: retry
 * cannot grant a missing permission. 401 stays silent (global
 * auth:unauthorized handler redirects to login).
 * Read-only: no editing, assignment, or delete in this phase.
 */

import { api, setText } from '../api.js?v=4C1';
import { t, applyI18n } from '../i18n.js?v=4C1';
import {
  clear, statusLine, showSkeleton,
  showEmpty, showError,
} from '../page.js?v=4C1';

function sectionTable(box, headingKey, columns, rows, cellText) {
  const h = document.createElement('h2');
  setText(h, t(headingKey));
  box.appendChild(h);
  const wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  const table = document.createElement('table');
  table.className = 'data-table';
  const thead = document.createElement('thead');
  const hr = document.createElement('tr');
  for (const key of columns) {
    const th = document.createElement('th');
    setText(th, t(key));
    th.setAttribute('scope', 'col');
    hr.appendChild(th);
  }
  thead.appendChild(hr);
  table.appendChild(thead);
  const tbody = document.createElement('tbody');
  for (const row of rows) {
    const tr = document.createElement('tr');
    for (const text of cellText(row)) {
      const td = document.createElement('td');
      if (text && text.mono) {
        td.className = 'mono';
        setText(td, text.value);
      } else {
        setText(td, text && text.value !== undefined ? text.value : text);
      }
      tr.appendChild(td);
    }
    tbody.appendChild(tr);
  }
  table.appendChild(tbody);
  wrap.appendChild(table);
  box.appendChild(wrap);
}

function mono(value) {
  return { mono: true, value: value === null || value === undefined ? '' : String(value) };
}

export async function renderAdminRoles(body, user, ctx) {
  clear(body);
  const h = document.createElement('h1');
  h.className = 'page-title';
  setText(h, t('admin.roles_list_title'));
  body.appendChild(h);
  const status = statusLine(body);
  const box = document.createElement('div');
  box.setAttribute('data-roles-box', '1');
  body.appendChild(box);
  setText(status, t('state.loading'));
  const skel = document.createElement('div');
  showSkeleton(skel, 3);
  box.appendChild(skel);

  const [rolesRes, permsRes] = await Promise.all([
    api.get('/admin/roles'),
    api.get('/admin/permissions'),
  ]);
  if (ctx.dead()) {
    return;
  }
  const first = !rolesRes.ok ? rolesRes : (!permsRes.ok ? permsRes : null);
  if (first) {
    if (first.status === 401) {
      return;
    }
    clear(box);
    if (first.status === 403) {
      // Honest permission denial: retry cannot help, so no retry button.
      setText(status, t('admin.no_permission') + ' (' + first.code + ')');
      return;
    }
    showError(status, first.code, () => renderAdminRoles(body, user, ctx), 'admin.roles_error', 'state.retry');
    return;
  }
  if (!Array.isArray(rolesRes.data) || !Array.isArray(permsRes.data)) {
    clear(box);
    showError(status, 'bad_response', () => renderAdminRoles(body, user, ctx), 'admin.roles_error', 'state.retry');
    return;
  }
  clear(box);
  const roles = rolesRes.data;
  const perms = permsRes.data;
  if (roles.length === 0 && perms.length === 0) {
    showEmpty(status, 'admin.roles_empty');
    return;
  }
  setText(status, '');

  if (roles.length === 0) {
    const p = document.createElement('p');
    p.className = 'section-status';
    p.setAttribute('role', 'status');
    setText(p, t('admin.roles_empty'));
    box.appendChild(p);
  } else {
    sectionTable(
      box,
      'admin.roles_heading',
      ['admin.roles_col_name', 'admin.roles_col_slug', 'admin.roles_col_scope', 'admin.roles_col_description'],
      roles,
      (r) => [r.name, mono(r.slug), r.scope, r.description],
    );
  }

  if (perms.length === 0) {
    const p = document.createElement('p');
    p.className = 'section-status';
    p.setAttribute('role', 'status');
    setText(p, t('admin.perms_empty'));
    box.appendChild(p);
  } else {
    sectionTable(
      box,
      'admin.perms_heading',
      ['admin.perms_col_slug', 'admin.perms_col_name', 'admin.perms_col_description'],
      perms,
      (p) => [mono(p.slug), p.name, p.description],
    );
  }

  const note = document.createElement('p');
  note.className = 'meta';
  setText(note, t('admin.roles_mapping_note'));
  box.appendChild(note);

  applyI18n(body);
  void user;
}
