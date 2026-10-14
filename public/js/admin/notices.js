/**
 * notices.js — admin notices CRUD (#/admin/notices, /new, /{id}).
 * Mirror of news.js (Phase 4B-1) for notices (Phase 4B-2).
 * QUIRK 1 (no GET by id): hybrid fetch — module-scope Map cache
 * stashed on list-row click (fast path); on cache miss (reload /
 * direct link) fetch ?per_page=100 and find client-side, else
 * honest 404. If admin notices exceed 100 items, this fallback needs
 * a backend endpoint. Flag for Phase 5 backend follow-up.
 * QUIRK 2 (no server ?status= filter): fetch ?per_page=100 once,
 * filter + paginate (20/page) in memory from the filtered length.
 * Client-side filter window = 100. If admin notices exceed 100 items,
 * add server-side ?status= filter (Phase 5 backend).
 * Notices have NO slug and NO category (unlike news). They have
 * expires_at (nullable DATETIME, NULL = never expires): NULL or a
 * future value keeps a published notice visible on the public list;
 * a past value hides it there at once. The backend performs NO
 * validation on expires_at (stores whatever string it receives), so
 * all expiry checks here are UX-only; the backend stays authoritative.
 * D1: backend accepts archived; UI exposes draft/published only
 * for parity with news. Archive flow deferred.
 */

import { api, setText } from '../api.js?v=4C1';
import { t, applyI18n } from '../i18n.js?v=4C1';
import {
  clear, fmtDate, statusLine, showSkeleton,
  showEmpty, showError, pager, backLink,
} from '../page.js?v=4C1';

const PAGE_SIZE = 20;
// D1: backend accepts archived; UI exposes draft/published only for
// parity with news. Archive flow deferred.
const FILTER_STATUSES = ['draft', 'published'];
const FORM_STATUSES = ['draft', 'published'];

const cache = new Map();
const listState = { status: 'all', page: 1 };

export function stashAdminNotice(item) {
  if (item && item.id != null) {
    cache.set(String(item.id), item);
  }
}

async function fetchWindow() {
  const res = await api.get('/admin/notices?per_page=100');
  if (!res.ok || !Array.isArray(res.data)) {
    return { ok: false, code: res.code, status: res.status, items: [] };
  }
  for (const item of res.data) {
    stashAdminNotice(item);
  }
  return { ok: true, items: res.data };
}

function filtered(items) {
  if (listState.status === 'all') {
    return items;
  }
  return items.filter((it) => String(it.status || '') === listState.status);
}

/* ---------- expiry helpers (UX-only; backend is authoritative) ---------- */

/** Parse a "YYYY-MM-DD HH:MM:SS" value as UTC, like fmtDate does. */
function parseDbTime(value) {
  if (value === null || value === undefined || value === '') {
    return null;
  }
  const d = new Date(String(value).replace(' ', 'T') + 'Z');
  if (Number.isNaN(d.getTime())) {
    return null;
  }
  return d;
}

/** Expired = expires_at set AND at or past now (mirrors the public rule). */
export function isNoticeExpired(item) {
  if (!item || item.expires_at === null || item.expires_at === undefined || item.expires_at === '') {
    return false;
  }
  const d = parseDbTime(item.expires_at);
  if (!d) {
    return false;
  }
  return d.getTime() <= Date.now();
}

/** "YYYY-MM-DD HH:MM:SS" -> "YYYY-MM-DDTHH:MM" for datetime-local. */
function toDatetimeLocal(value) {
  if (value === null || value === undefined || value === '') {
    return '';
  }
  const m = /^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})(?::\d{2})?$/.exec(String(value).trim());
  if (!m) {
    return '';
  }
  return m[1] + 'T' + m[2];
}

/** "YYYY-MM-DDTHH:MM" -> "YYYY-MM-DD HH:MM:SS" for the backend. */
function fromDatetimeLocal(value) {
  const m = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})$/.exec(String(value || '').trim());
  if (!m) {
    return null;
  }
  return m[1] + ' ' + m[2] + ':00';
}

/** True when the datetime-local value is set and already in the past. */
function isPastLocal(value) {
  if (!value) {
    return false;
  }
  const d = new Date(String(value) + ':00');
  if (Number.isNaN(d.getTime())) {
    return false;
  }
  return d.getTime() <= Date.now();
}

/* ---------- list ---------- */

function expiredBadge() {
  const badge = document.createElement('span');
  badge.className = 'badge badge-expired';
  setText(badge, t('admin.notices_expired'));
  return badge;
}

function renderRows(tbody, items) {
  tbody.replaceChildren();
  for (const item of items) {
    const tr = document.createElement('tr');
    const tdTitle = document.createElement('td');
    const link = document.createElement('a');
    link.setAttribute('href', '#/admin/notices/' + String(item.id));
    setText(link, item.title);
    link.addEventListener('click', () => stashAdminNotice(item));
    tdTitle.appendChild(link);
    tr.appendChild(tdTitle);
    const tdStatus = document.createElement('td');
    const badge = document.createElement('span');
    badge.className = 'badge badge-' + String(item.status || 'draft');
    setText(badge, item.status != null ? String(item.status) : '');
    tdStatus.appendChild(badge);
    if (isNoticeExpired(item)) {
      tdStatus.appendChild(document.createTextNode(' '));
      tdStatus.appendChild(expiredBadge());
    }
    tr.appendChild(tdStatus);
    const tdExp = document.createElement('td');
    setText(tdExp, fmtDate(item.expires_at));
    tr.appendChild(tdExp);
    const tdDate = document.createElement('td');
    setText(tdDate, fmtDate(item.created_at));
    tr.appendChild(tdDate);
    const tdAct = document.createElement('td');
    const view = document.createElement('a');
    view.className = 'btn btn-secondary btn-small';
    view.setAttribute('href', '#/admin/notices/' + String(item.id));
    setText(view, t('admin.notices_view'));
    view.addEventListener('click', () => stashAdminNotice(item));
    tdAct.appendChild(view);
    tdAct.appendChild(document.createTextNode(' '));
    const edit = document.createElement('a');
    edit.className = 'btn btn-secondary btn-small';
    edit.setAttribute('href', '#/admin/notices/' + String(item.id));
    setText(edit, t('admin.notices_edit'));
    edit.addEventListener('click', () => stashAdminNotice(item));
    tdAct.appendChild(edit);
    tr.appendChild(tdAct);
    tbody.appendChild(tr);
  }
}

async function loadList(box, status, pagerBox, ctx) {
  setText(status, t('state.loading'));
  clear(box);
  clear(pagerBox);
  const skel = document.createElement('div');
  showSkeleton(skel, 3);
  box.appendChild(skel);
  const win = await fetchWindow();
  if (ctx.dead()) {
    return;
  }
  if (!win.ok) {
    if (win.status === 401) {
      return;
    }
    clear(box);
    showError(status, win.code, () => loadList(box, status, pagerBox, ctx), 'admin.notices_error', 'state.retry');
    return;
  }
  const items = filtered(win.items);
  clear(box);
  if (items.length === 0) {
    showEmpty(status, 'admin.notices_empty');
    return;
  }
  setText(status, t('admin.notices_list_title') + ' (' + items.length + ')');
  const wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  const table = document.createElement('table');
  table.className = 'data-table';
  const thead = document.createElement('thead');
  const hr = document.createElement('tr');
  for (const key of ['admin.notices_col_title', 'admin.notices_col_status', 'admin.notices_col_expires', 'admin.notices_col_created', 'admin.notices_col_actions']) {
    const th = document.createElement('th');
    setText(th, t(key));
    th.setAttribute('scope', 'col');
    hr.appendChild(th);
  }
  thead.appendChild(hr);
  table.appendChild(thead);
  const tbody = document.createElement('tbody');
  table.appendChild(tbody);
  wrap.appendChild(table);
  box.appendChild(wrap);
  const pages = Math.max(1, Math.ceil(items.length / PAGE_SIZE));
  const page = Math.min(listState.page, pages);
  listState.page = page;
  renderRows(tbody, items.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE));
  clear(pagerBox);
  if (pages > 1) {
    pager(pagerBox, page, pages, (p) => {
      listState.page = p;
      loadList(box, status, pagerBox, ctx);
    }, 'news.pagination_prev', 'news.pagination_next', 'news.page_indicator');
  }
}

export async function renderAdminNoticesList(body, user, ctx) {
  clear(body);
  const h = document.createElement('h1');
  h.className = 'page-title';
  setText(h, t('admin.notices_list_title'));
  body.appendChild(h);
  const bar = document.createElement('div');
  bar.className = 'filter-bar';
  const label = document.createElement('label');
  label.className = 'filter-inline';
  const span = document.createElement('span');
  setText(span, t('admin.notices_filter_status'));
  label.appendChild(span);
  const sel = document.createElement('select');
  sel.className = 'filter-select';
  for (const [val, key] of [['all', 'admin.notices_filter_all'], ['draft', 'admin.notices_filter_draft'], ['published', 'admin.notices_filter_published']]) {
    const opt = document.createElement('option');
    opt.value = val;
    setText(opt, t(key));
    sel.appendChild(opt);
  }
  sel.value = listState.status;
  sel.addEventListener('change', () => {
    listState.status = sel.value;
    listState.page = 1;
    const status = body.querySelector('.section-status');
    const box = body.querySelector('[data-notice-box]');
    const pagerBox = body.querySelector('[data-notice-pager]');
    if (status && box && pagerBox) {
      loadList(box, status, pagerBox, ctx);
    }
  });
  label.appendChild(sel);
  bar.appendChild(label);
  const fresh = document.createElement('a');
  fresh.className = 'btn btn-primary btn-link';
  fresh.setAttribute('href', '#/admin/notices/new');
  setText(fresh, t('admin.notices_new'));
  bar.appendChild(fresh);
  body.appendChild(bar);
  const status = statusLine(body);
  const box = document.createElement('div');
  box.setAttribute('data-notice-box', '1');
  body.appendChild(box);
  const pagerBox = document.createElement('div');
  pagerBox.setAttribute('data-notice-pager', '1');
  body.appendChild(pagerBox);
  applyI18n(body);
  await loadList(box, status, pagerBox, ctx);
  void user;
}

/* ---------- shared form ---------- */

function fieldErrorEl(id) {
  const p = document.createElement('p');
  p.className = 'field-error';
  p.id = id;
  p.hidden = true;
  return p;
}

function setFieldError(input, errEl, message) {
  if (message) {
    setText(errEl, message);
    errEl.hidden = false;
    input.setAttribute('aria-invalid', 'true');
  } else {
    setText(errEl, '');
    errEl.hidden = true;
    input.removeAttribute('aria-invalid');
  }
}

function errorSummary(box) {
  const div = document.createElement('div');
  div.className = 'form-errors';
  div.setAttribute('role', 'alert');
  div.setAttribute('tabindex', '-1');
  div.hidden = true;
  box.appendChild(div);
  return div;
}

function showSummary(summary, messages) {
  summary.replaceChildren();
  if (!messages || messages.length === 0) {
    summary.hidden = true;
    return;
  }
  const h = document.createElement('h2');
  h.className = 'form-errors-title';
  setText(h, t('state.error'));
  summary.appendChild(h);
  const ul = document.createElement('ul');
  for (const m of messages) {
    const li = document.createElement('li');
    setText(li, m);
    ul.appendChild(li);
  }
  summary.appendChild(ul);
  summary.hidden = false;
  summary.focus();
}

function buildNoticeForm(head, initial) {
  const form = document.createElement('form');
  form.className = 'auth-form complaint-form';
  form.setAttribute('novalidate', '');

  const title = document.createElement('input');
  title.type = 'text';
  title.required = true;
  title.setAttribute('maxlength', '255');
  title.setAttribute('autocomplete', 'off');
  if (initial && initial.title) {
    title.value = String(initial.title);
  }
  const titleErr = fieldErrorEl('nt-title-error');
  const wTitle = document.createElement('div');
  wTitle.className = 'field';
  const lTitle = document.createElement('label');
  lTitle.setAttribute('for', 'nt-title');
  setText(lTitle, t('admin.notices_form_title'));
  title.id = 'nt-title';
  title.setAttribute('aria-describedby', titleErr.id);
  wTitle.appendChild(lTitle);
  wTitle.appendChild(title);
  wTitle.appendChild(titleErr);
  form.appendChild(wTitle);

  const body = document.createElement('textarea');
  body.required = true;
  body.rows = 8;
  if (initial && initial.body) {
    body.value = String(initial.body);
  }
  const bodyErr = fieldErrorEl('nt-body-error');
  const wBody = document.createElement('div');
  wBody.className = 'field';
  const lBody = document.createElement('label');
  lBody.setAttribute('for', 'nt-body');
  setText(lBody, t('admin.notices_form_body'));
  body.id = 'nt-body';
  body.setAttribute('aria-describedby', bodyErr.id);
  wBody.appendChild(lBody);
  wBody.appendChild(body);
  wBody.appendChild(bodyErr);
  form.appendChild(wBody);

  const st = document.createElement('select');
  for (const s of FORM_STATUSES) {
    const opt = document.createElement('option');
    opt.value = s;
    setText(opt, t('admin.notices_form_status_' + s));
    st.appendChild(opt);
  }
  st.value = initial && initial.status ? String(initial.status) : 'draft';
  const wSt = document.createElement('div');
  wSt.className = 'field';
  const lSt = document.createElement('label');
  lSt.setAttribute('for', 'nt-status');
  setText(lSt, t('admin.notices_form_status'));
  st.id = 'nt-status';
  wSt.appendChild(lSt);
  wSt.appendChild(st);
  form.appendChild(wSt);

  // expires_at: optional UX-only datetime input. Empty = never expires
  // (NULL). A past value only warns — saving is still allowed, the
  // notice simply hides from the public list at once.
  const exp = document.createElement('input');
  exp.type = 'datetime-local';
  if (initial && initial.expires_at) {
    exp.value = toDatetimeLocal(initial.expires_at);
  }
  const expHint = document.createElement('p');
  expHint.className = 'field-hint';
  expHint.id = 'nt-expires-hint';
  expHint.hidden = true;
  const wExp = document.createElement('div');
  wExp.className = 'field';
  const lExp = document.createElement('label');
  lExp.setAttribute('for', 'nt-expires');
  setText(lExp, t('admin.notices_form_expires'));
  exp.id = 'nt-expires';
  exp.setAttribute('aria-describedby', expHint.id);
  const expRow = document.createElement('div');
  expRow.className = 'field-row';
  expRow.appendChild(exp);
  const expClear = document.createElement('button');
  expClear.type = 'button';
  expClear.className = 'btn btn-secondary btn-small';
  setText(expClear, t('admin.notices_form_expires_clear'));
  expClear.addEventListener('click', () => {
    exp.value = '';
    updatePastHint();
    exp.focus();
  });
  expRow.appendChild(expClear);
  wExp.appendChild(lExp);
  wExp.appendChild(expRow);
  wExp.appendChild(expHint);
  form.appendChild(wExp);

  function updatePastHint() {
    if (isPastLocal(exp.value)) {
      setText(expHint, t('admin.notices_form_expires_past_warn'));
      expHint.hidden = false;
    } else {
      setText(expHint, '');
      expHint.hidden = true;
    }
  }
  exp.addEventListener('input', updatePastHint);
  exp.addEventListener('change', updatePastHint);
  updatePastHint();

  const row = document.createElement('div');
  row.className = 'success-actions';
  const save = document.createElement('button');
  save.type = 'submit';
  save.className = 'btn btn-primary';
  setText(save, t('admin.notices_form_save'));
  row.appendChild(save);
  const cancel = document.createElement('a');
  cancel.className = 'btn btn-secondary btn-link';
  cancel.setAttribute('href', '#/admin/notices');
  setText(cancel, t('admin.notices_form_cancel'));
  row.appendChild(cancel);
  form.appendChild(row);
  head.appendChild(form);
  return { form, title, titleErr, body, bodyErr, st, exp, save };
}

function validateNotice(f) {
  const problems = [];
  const title = f.title.value.trim();
  const body = f.body.value.trim();
  if (title === '') {
    setFieldError(f.title, f.titleErr, t('admin.notices_form_title_required'));
    problems.push(t('admin.notices_form_title'));
  } else if (title.length > 255) {
    setFieldError(f.title, f.titleErr, t('admin.notices_form_title_max'));
    problems.push(t('admin.notices_form_title'));
  } else {
    setFieldError(f.title, f.titleErr, '');
  }
  if (body === '') {
    setFieldError(f.body, f.bodyErr, t('admin.notices_form_body_required'));
    problems.push(t('admin.notices_form_body'));
  } else if (body.length < 10) {
    setFieldError(f.body, f.bodyErr, t('admin.notices_form_body_min'));
    problems.push(t('admin.notices_form_body'));
  } else {
    setFieldError(f.body, f.bodyErr, '');
  }
  // expires_at is never a blocking error: empty = NULL (never expires),
  // past = allowed (warned inline); the backend stores it as-is.
  return { problems, title, body };
}

function expiryPayload(expInput) {
  const raw = expInput && expInput.value ? String(expInput.value).trim() : '';
  if (raw === '') {
    return null;
  }
  return fromDatetimeLocal(raw);
}

/* ---------- create ---------- */

export async function renderAdminNoticesNew(body, user, ctx) {
  clear(body);
  const h = document.createElement('h1');
  h.className = 'page-title';
  setText(h, t('admin.notices_new'));
  body.appendChild(h);
  backLink(body, '#/admin/notices', 'admin.detail_back');
  const status = statusLine(body);
  const summary = errorSummary(body);
  setText(status, '');
  const f = buildNoticeForm(body, null);
  f.form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const v = validateNotice(f);
    if (v.problems.length > 0) {
      showSummary(summary, v.problems);
      return;
    }
    showSummary(summary, []);
    f.save.disabled = true;
    const payload = { title: v.title, body: v.body, status: FORM_STATUSES.includes(f.st.value) ? f.st.value : 'draft' };
    const exp = expiryPayload(f.exp);
    if (exp !== null) {
      payload.expires_at = exp;
    }
    const res = await api.post('/admin/notices', payload);
    if (ctx.dead()) {
      return;
    }
    f.save.disabled = false;
    if (!res.ok) {
      if (res.status === 401) {
        return;
      }
      if (res.status === 403) {
        showSummary(summary, [t('admin.notices_form_scope_error') + ' (' + res.code + ')']);
      } else {
        showSummary(summary, [t('admin.notices_form_save_error') + ' (' + res.code + ')']);
      }
      return;
    }
    const row = res.data || {};
    if (row.id != null) {
      stashAdminNotice(row);
      window.location.hash = '#/admin/notices/' + String(row.id);
    }
  });
  applyI18n(body);
  void user;
}

/* ---------- detail / edit / delete ---------- */

async function resolveItem(id, ctx) {
  if (cache.has(String(id))) {
    return { ok: true, item: cache.get(String(id)) };
  }
  const win = await fetchWindow();
  if (ctx.dead()) {
    return { ok: false, dead: true };
  }
  if (!win.ok) {
    return { ok: false, status: win.status, code: win.code };
  }
  const found = win.items.find((it) => String(it.id) === String(id));
  if (!found) {
    return { ok: false, status: 404, code: 'not_found' };
  }
  return { ok: true, item: found };
}

function confirmPanel(box, onYes) {
  const panel = document.createElement('div');
  panel.className = 'form-errors';
  panel.setAttribute('role', 'alert');
  panel.setAttribute('tabindex', '-1');
  const h = document.createElement('h2');
  h.className = 'form-errors-title';
  setText(h, t('admin.notices_form_delete_confirm'));
  panel.appendChild(h);
  const row = document.createElement('div');
  row.className = 'success-actions';
  const yes = document.createElement('button');
  yes.type = 'button';
  yes.className = 'btn btn-secondary';
  setText(yes, t('admin.notices_form_delete_confirm_yes'));
  yes.addEventListener('click', () => onYes(panel, yes));
  row.appendChild(yes);
  const no = document.createElement('button');
  no.type = 'button';
  no.className = 'btn btn-primary';
  setText(no, t('admin.notices_form_delete_confirm_no'));
  no.addEventListener('click', () => panel.remove());
  row.appendChild(no);
  panel.appendChild(row);
  box.appendChild(panel);
  panel.focus();
}

export async function renderAdminNoticesDetail(body, id, user, ctx) {
  clear(body);
  if (!/^\d+$/.test(id || '')) {
    const h = document.createElement('h1');
    h.className = 'page-title';
    setText(h, t('admin.detail_title'));
    body.appendChild(h);
    backLink(body, '#/admin/notices', 'admin.detail_back');
    const status = statusLine(body);
    setText(status, t('admin.notices_detail_not_found'));
    return;
  }
  const h = document.createElement('h1');
  h.className = 'page-title';
  setText(h, t('admin.detail_title'));
  body.appendChild(h);
  backLink(body, '#/admin/notices', 'admin.detail_back');
  const status = statusLine(body);
  setText(status, t('state.loading'));
  const found = await resolveItem(id, ctx);
  if (ctx.dead() || found.dead) {
    return;
  }
  if (!found.ok) {
    if (found.status === 401) {
      return;
    }
    if (found.status === 404) {
      setText(status, t('admin.notices_detail_not_found'));
      return;
    }
    showError(status, found.code, () => renderAdminNoticesDetail(body, id, user, ctx), 'admin.notices_error', 'state.retry');
    return;
  }
  const item = found.item;
  setText(status, '');
  const summary = errorSummary(body);

  const article = document.createElement('article');
  article.className = 'article';
  const title = document.createElement('h2');
  title.className = 'article-title';
  setText(title, item.title);
  article.appendChild(title);
  const statusWrap = document.createElement('p');
  statusWrap.className = 'meta';
  const badge = document.createElement('span');
  badge.className = 'badge badge-' + String(item.status || 'draft');
  setText(badge, item.status != null ? String(item.status) : '');
  statusWrap.appendChild(badge);
  if (isNoticeExpired(item)) {
    statusWrap.appendChild(document.createTextNode(' '));
    statusWrap.appendChild(expiredBadge());
  }
  article.appendChild(statusWrap);
  const pubWrap = document.createElement('p');
  pubWrap.className = 'meta';
  const pubLabel = document.createElement('span');
  setText(pubLabel, t('admin.notices_form_published'));
  pubWrap.appendChild(pubLabel);
  if (fmtDate(item.published_at) !== '') {
    pubWrap.appendChild(document.createTextNode(' ' + fmtDate(item.published_at)));
  }
  article.appendChild(pubWrap);
  const expWrap = document.createElement('p');
  expWrap.className = 'meta';
  const expLabel = document.createElement('span');
  setText(expLabel, t('admin.notices_form_expires'));
  expWrap.appendChild(expLabel);
  if (fmtDate(item.expires_at) !== '') {
    expWrap.appendChild(document.createTextNode(' ' + fmtDate(item.expires_at)));
  }
  article.appendChild(expWrap);
  if (item.body) {
    for (const chunk of String(item.body).split(/\r?\n\r?\n|\r?\n/)) {
      if (!chunk.trim()) {
        continue;
      }
      const p = document.createElement('p');
      setText(p, chunk.trim());
      article.appendChild(p);
    }
  }
  body.appendChild(article);

  const actions = document.createElement('div');
  actions.className = 'success-actions';
  const editBtn = document.createElement('button');
  editBtn.type = 'button';
  editBtn.className = 'btn btn-primary';
  setText(editBtn, t('admin.notices_edit'));
  actions.appendChild(editBtn);
  // UX-only gate: operators without notice.delete never see the button.
  // Backend 403 remains the real enforcement (defense in depth).
  // Permission source: enriched /me user (roles+permissions), passed
  // in as `user` — same object the guard authorized.
  const canDelete = !!user && Array.isArray(user.permissions) && user.permissions.includes('notice.delete');
  let delBtn = null;
  if (canDelete) {
    delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'btn btn-secondary';
    setText(delBtn, t('admin.notices_delete'));
    actions.appendChild(delBtn);
  }
  body.appendChild(actions);

  const formHost = document.createElement('div');
  body.appendChild(formHost);
  let editing = false;
  editBtn.addEventListener('click', () => {
    if (editing) {
      return;
    }
    editing = true;
    formHost.replaceChildren();
    const f = buildNoticeForm(formHost, item);
    f.form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const v = validateNotice(f);
      if (v.problems.length > 0) {
        showSummary(summary, v.problems);
        return;
      }
      showSummary(summary, []);
      f.save.disabled = true;
      const payload = { title: v.title, body: v.body, status: FORM_STATUSES.includes(f.st.value) ? f.st.value : 'draft' };
      payload.expires_at = expiryPayload(f.exp);
      const res = await api.put('/admin/notices/' + String(item.id), payload);
      if (ctx.dead()) {
        return;
      }
      f.save.disabled = false;
      if (!res.ok) {
        if (res.status === 401) {
          return;
        }
        if (res.status === 403) {
          showSummary(summary, [t('admin.notices_form_scope_error') + ' (' + res.code + ')']);
        } else {
          showSummary(summary, [t('admin.notices_form_save_error') + ' (' + res.code + ')']);
        }
        return;
      }
      if (res.data && res.data.id != null) {
        stashAdminNotice(res.data);
      } else {
        cache.delete(String(item.id));
      }
      const ok = document.createElement('p');
      ok.className = 'banner-success';
      ok.setAttribute('role', 'status');
      setText(ok, t('admin.notices_form_save_success'));
      formHost.prepend(ok);
      await renderAdminNoticesDetail(body, String(item.id), user, ctx);
    });
  });

  if (delBtn) {
    delBtn.addEventListener('click', () => {
    confirmPanel(body, async (panel, yes) => {
      yes.disabled = true;
      const res = await api.del('/admin/notices/' + String(item.id));
      if (ctx.dead()) {
        return;
      }
      if (!res.ok) {
        if (res.status === 401) {
          return;
        }
        if (res.status === 404) {
          cache.delete(String(item.id));
          window.location.hash = '#/admin/notices';
          return;
        }
        panel.replaceChildren();
        const msg = document.createElement('p');
        setText(msg, (res.status === 403 ? t('admin.notices_form_scope_error') : t('admin.notices_form_delete_error')) + ' (' + res.code + ')');
        panel.appendChild(msg);
        yes.disabled = false;
        return;
      }
      cache.delete(String(item.id));
      const ok = document.createElement('p');
      ok.className = 'banner-success';
      ok.setAttribute('role', 'status');
      setText(ok, t('admin.notices_form_delete_success'));
      panel.replaceChildren();
      panel.appendChild(ok);
      window.setTimeout(() => {
        window.location.hash = '#/admin/notices';
      }, 1200);
    });
  });
  }
  applyI18n(body);
}
