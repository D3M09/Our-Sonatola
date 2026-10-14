/**
 * queue.js — admin complaint triage queue (#/admin/complaints).
 * Filters: status (all staff) + union (global-scope roles only;
 * backend forces own union for scoped staff regardless).
 * Copy via t(); DOM via createElement/textContent (CSP-safe).
 */

import { api, setText } from '../api.js?v=4C1';
import { t, applyI18n } from '../i18n.js?v=4C1';
import { isGlobalScope } from './guard.js?v=4C1';
import {
  clear, fmtDate, pickName, statusLine, showSkeleton,
  showEmpty, showError, pager,
} from '../page.js?v=4C1';

const PER_PAGE = 20;
const STATUSES = ['submitted', 'in_review', 'assigned', 'resolved', 'rejected', 'closed'];

const state = { page: 1, status: 'all', union: 'all' };
let unionsCache = null;

async function loadUnions() {
  if (unionsCache) {
    return unionsCache;
  }
  const res = await api.get('/unions');
  unionsCache = res.ok && Array.isArray(res.data) ? res.data : [];
  return unionsCache;
}

function filterBar(head, user, onApply) {
  const bar = document.createElement('div');
  bar.className = 'filter-bar';
  const statusLabel = document.createElement('label');
  statusLabel.className = 'filter-inline';
  const statusSpan = document.createElement('span');
  setText(statusSpan, t('admin.queue_filter_status'));
  statusLabel.appendChild(statusSpan);
  const statusSel = document.createElement('select');
  statusSel.className = 'filter-select';
  const allOpt = document.createElement('option');
  allOpt.value = 'all';
  setText(allOpt, t('admin.queue_filter_all'));
  statusSel.appendChild(allOpt);
  for (const s of STATUSES) {
    const opt = document.createElement('option');
    opt.value = s;
    setText(opt, t('status.' + s));
    statusSel.appendChild(opt);
  }
  statusSel.value = state.status;
  statusLabel.appendChild(statusSel);
  bar.appendChild(statusLabel);

  let unionSel = null;
  if (isGlobalScope(user)) {
    const unionLabel = document.createElement('label');
    unionLabel.className = 'filter-inline';
    const unionSpan = document.createElement('span');
    setText(unionSpan, t('admin.queue_filter_union'));
    unionLabel.appendChild(unionSpan);
    unionSel = document.createElement('select');
    unionSel.className = 'filter-select';
    unionLabel.appendChild(unionSel);
    bar.appendChild(unionLabel);
  }

  const apply = document.createElement('button');
  apply.type = 'button';
  apply.className = 'btn btn-primary';
  setText(apply, t('admin.queue_filter_apply'));
  apply.addEventListener('click', () => {
    state.status = statusSel.value;
    state.union = unionSel ? unionSel.value : 'all';
    state.page = 1;
    onApply();
  });
  bar.appendChild(apply);
  const clearBtn = document.createElement('button');
  clearBtn.type = 'button';
  clearBtn.className = 'btn btn-secondary';
  setText(clearBtn, t('admin.queue_filter_clear'));
  clearBtn.addEventListener('click', () => {
    state.status = 'all';
    state.union = 'all';
    state.page = 1;
    onApply();
  });
  bar.appendChild(clearBtn);
  head.appendChild(bar);
  return { unionSel };
}

async function fillUnions(unionSel) {
  if (!unionSel) {
    return;
  }
  const unions = await loadUnions();
  unionSel.replaceChildren();
  const allOpt = document.createElement('option');
  allOpt.value = 'all';
  setText(allOpt, t('admin.queue_filter_all'));
  unionSel.appendChild(allOpt);
  for (const u of unions) {
    const opt = document.createElement('option');
    opt.value = String(u.id);
    setText(opt, pickName(u));
    unionSel.appendChild(opt);
  }
  unionSel.value = state.union;
}

function badge(text, prefix, value) {
  const s = document.createElement('span');
  s.className = 'badge ' + prefix + value;
  setText(s, text);
  return s;
}

function renderTable(box, items) {
  clear(box);
  const wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  const table = document.createElement('table');
  table.className = 'data-table';
  const thead = document.createElement('thead');
  const hr = document.createElement('tr');
  for (const key of ['admin.queue_col_tracking', 'admin.queue_col_subject', 'admin.queue_col_status', 'admin.queue_col_priority', 'admin.queue_col_created', 'admin.queue_col_actions']) {
    const th = document.createElement('th');
    setText(th, t(key));
    th.setAttribute('scope', 'col');
    hr.appendChild(th);
  }
  thead.appendChild(hr);
  table.appendChild(thead);
  const tbody = document.createElement('tbody');
  for (const item of items) {
    const tr = document.createElement('tr');
    const tdTrack = document.createElement('td');
    tdTrack.className = 'mono';
    setText(tdTrack, item.tracking_no != null ? String(item.tracking_no) : '');
    tr.appendChild(tdTrack);
    const tdSub = document.createElement('td');
    const link = document.createElement('a');
    link.setAttribute('href', '#/admin/complaints/' + String(item.id));
    setText(link, item.subject);
    tdSub.appendChild(link);
    tr.appendChild(tdSub);
    const tdStatus = document.createElement('td');
    tdStatus.appendChild(badge(String(item.status || ''), 'badge-', String(item.status || 'submitted')));
    tr.appendChild(tdStatus);
    const tdPrio = document.createElement('td');
    tdPrio.appendChild(badge(String(item.priority || ''), 'prio-', String(item.priority || 'normal')));
    tr.appendChild(tdPrio);
    const tdDate = document.createElement('td');
    setText(tdDate, fmtDate(item.created_at));
    tr.appendChild(tdDate);
    const tdAct = document.createElement('td');
    const view = document.createElement('a');
    view.className = 'btn btn-secondary btn-small';
    view.setAttribute('href', '#/admin/complaints/' + String(item.id));
    setText(view, t('admin.queue_view'));
    tdAct.appendChild(view);
    tr.appendChild(tdAct);
    tbody.appendChild(tr);
  }
  table.appendChild(tbody);
  wrap.appendChild(table);
  box.appendChild(wrap);
}

async function load(box, status, pagerBox, ctx) {
  setText(status, t('state.loading'));
  clear(box);
  clear(pagerBox);
  const skel = document.createElement('div');
  showSkeleton(skel, 3);
  box.appendChild(skel);
  let path = '/complaints?page=' + state.page + '&per_page=' + PER_PAGE;
  if (state.status !== 'all') {
    path += '&status=' + encodeURIComponent(state.status);
  }
  if (state.union !== 'all') {
    path += '&union_id=' + encodeURIComponent(state.union);
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
    showError(status, res.code, () => load(box, status, pagerBox, ctx), 'admin.queue_error', 'state.retry');
    return;
  }
  const items = Array.isArray(res.data) ? res.data : [];
  const pg = res.meta && res.meta.pagination ? res.meta.pagination : null;
  const totalPages = pg ? Math.max(1, Number(pg.total_pages) || 1) : 1;
  clear(box);
  if (items.length === 0) {
    showEmpty(status, 'admin.queue_empty');
    return;
  }
  setText(status, '');
  renderTable(box, items);
  clear(pagerBox);
  if (totalPages > 1) {
    pager(pagerBox, state.page, totalPages, (p) => {
      state.page = p;
      load(box, status, pagerBox, ctx);
    }, 'news.pagination_prev', 'news.pagination_next', 'news.page_indicator');
  }
}

export async function renderAdminQueue(body, user, ctx) {
  clear(body);
  const h = document.createElement('h1');
  h.className = 'page-title';
  setText(h, t('admin.queue_title'));
  body.appendChild(h);
  const refs = { status: null, box: null, pagerBox: null };
  const filterRefs = filterBar(body, user, () => {
    if (refs.status && refs.box && refs.pagerBox) {
      load(refs.box, refs.status, refs.pagerBox, ctx);
    }
  });
  refs.status = statusLine(body);
  refs.box = document.createElement('div');
  body.appendChild(refs.box);
  refs.pagerBox = document.createElement('div');
  body.appendChild(refs.pagerBox);
  await fillUnions(filterRefs.unionSel);
  if (ctx.dead()) {
    return;
  }
  applyI18n(body);
  await load(refs.box, refs.status, refs.pagerBox, ctx);
}
