/**
 * audit.js — admin audit log viewer (#/admin/audit). READ-ONLY.
 * Read: GET /admin/audit-logs?page&per_page  (perm audit.view).
 * No writes, no actions. Values are rendered as text (never innerHTML),
 * so JSON/user-agent/entity strings cannot inject markup. The backend
 * already restricts this endpoint to audit.view holders; the UI merely
 * surfaces 403 honestly.
 * Copy via t(); DOM via createElement/textContent (CSP-safe).
 */

import { api, setText } from '../api.js?v=4C1';
import { t, applyI18n } from '../i18n.js?v=4C1';
import {
  clear, fmtDate, statusLine, showSkeleton, showEmpty, showError, pager,
} from '../page.js?v=4C1';

const PER_PAGE = 25;
const state = { page: 1 };

function truncate(value, max) {
  const s = value === null || value === undefined ? '' : String(value);
  return s.length > max ? s.slice(0, max) + '…' : s;
}

function entityLabel(row) {
  const type = row.entity_type != null ? String(row.entity_type) : '';
  const id = row.entity_id != null ? String(row.entity_id) : '';
  if (type === '') {
    return '';
  }
  return id === '' ? type : type + '#' + id;
}

function renderTable(box, items) {
  clear(box);
  const wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  const table = document.createElement('table');
  table.className = 'data-table';
  const thead = document.createElement('thead');
  const hr = document.createElement('tr');
  for (const key of [
    'admin.audit_col_time', 'admin.audit_col_actor', 'admin.audit_col_action',
    'admin.audit_col_entity', 'admin.audit_col_ip', 'admin.audit_col_metadata',
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

    const tdTime = document.createElement('td');
    tdTime.className = 'mono';
    setText(tdTime, fmtDate(row.created_at));
    tr.appendChild(tdTime);

    const tdActor = document.createElement('td');
    tdActor.className = 'mono';
    setText(tdActor, row.actor_id != null ? String(row.actor_id) : '');
    tr.appendChild(tdActor);

    const tdAction = document.createElement('td');
    setText(tdAction, row.action);
    tr.appendChild(tdAction);

    const tdEntity = document.createElement('td');
    tdEntity.className = 'mono';
    setText(tdEntity, entityLabel(row));
    tr.appendChild(tdEntity);

    const tdIp = document.createElement('td');
    tdIp.className = 'mono';
    setText(tdIp, row.ip_address != null ? String(row.ip_address) : '');
    tr.appendChild(tdIp);

    const tdMeta = document.createElement('td');
    tdMeta.className = 'mono';
    setText(tdMeta, truncate(row.metadata_json, 160));
    tr.appendChild(tdMeta);

    tbody.appendChild(tr);
  }
  table.appendChild(tbody);
  wrap.appendChild(table);
  box.appendChild(wrap);
}

async function load(box, status, pagerBox, ctx) {
  setText(status, t('state.loading'));
  status.className = 'section-status';
  clear(box);
  clear(pagerBox);
  const skel = document.createElement('div');
  showSkeleton(skel, 3);
  box.appendChild(skel);

  const res = await api.get('/admin/audit-logs?page=' + state.page + '&per_page=' + PER_PAGE);
  if (ctx.dead()) {
    return;
  }
  if (!res.ok) {
    if (res.status === 401) {
      return;
    }
    clear(box);
    if (res.status === 403) {
      // Missing audit.view: retry cannot grant a permission — no retry button.
      setText(status, t('admin.no_permission') + ' (' + res.code + ')');
      return;
    }
    showError(status, res.code, () => load(box, status, pagerBox, ctx), 'admin.audit_error', 'state.retry');
    return;
  }
  const items = Array.isArray(res.data) ? res.data : [];
  const pg = res.meta && res.meta.pagination ? res.meta.pagination : null;
  const totalPages = pg ? Math.max(1, Number(pg.total_pages) || 1) : 1;
  clear(box);
  if (items.length === 0) {
    showEmpty(status, 'admin.audit_empty');
    return;
  }
  setText(status, t('admin.audit_list_title') + ' (' + (pg ? Number(pg.total) || items.length : items.length) + ')');
  renderTable(box, items);
  clear(pagerBox);
  if (totalPages > 1) {
    pager(pagerBox, state.page, totalPages, (p) => {
      state.page = p;
      load(box, status, pagerBox, ctx);
    }, 'news.pagination_prev', 'news.pagination_next', 'news.page_indicator');
  }
}

export async function renderAdminAudit(body, user, ctx) {
  clear(body);
  const h = document.createElement('h1');
  h.className = 'page-title';
  setText(h, t('admin.audit_list_title'));
  body.appendChild(h);

  const note = document.createElement('p');
  note.className = 'meta';
  setText(note, t('admin.audit_note'));
  body.appendChild(note);

  const status = statusLine(body);
  const box = document.createElement('div');
  box.setAttribute('data-audit-box', '1');
  body.appendChild(box);
  const pagerBox = document.createElement('div');
  pagerBox.setAttribute('data-audit-pager', '1');
  body.appendChild(pagerBox);
  applyI18n(body);
  await load(box, status, pagerBox, ctx);
  void user;
}
