/**
 * news.js — admin news CRUD (#/admin/news, /new, /{id}).
 * QUIRK 1 (no GET by id): hybrid fetch — module-scope Map cache
 * stashed on list-row click (fast path); on cache miss (reload /
 * direct link) fetch ?per_page=100 and find client-side, else
 * honest 404. If admin news exceeds 100 items, this fallback needs
 * a backend endpoint. Flag for Phase 5 backend follow-up.
 * QUIRK 2 (no server ?status= filter): fetch ?per_page=100 once,
 * filter + paginate (20/page) in memory from the filtered length.
 * Client-side filter window = 100. If admin news exceeds 100 items,
 * add server-side ?status= filter (Phase 5 backend).
 * Slug is server-generated on create and preserved on update
 * (never sent back) — shown read-only.
 */

import { api, setText } from '../api.js?v=4C1';
import { t, applyI18n } from '../i18n.js?v=4C1';
import {
  clear, fmtDate, pickName, statusLine, showSkeleton,
  showEmpty, showError, pager, backLink,
} from '../page.js?v=4C1';

const PAGE_SIZE = 20;
const FILTER_STATUSES = ['draft', 'published'];
const FORM_STATUSES = ['draft', 'published'];

const cache = new Map();
const listState = { status: 'all', page: 1 };
let categoriesCache = null;

export function stashAdminNews(item) {
  if (item && item.id != null) {
    cache.set(String(item.id), item);
  }
}

async function loadCategories() {
  if (categoriesCache) {
    return categoriesCache;
  }
  const res = await api.get('/categories');
  categoriesCache = res.ok && Array.isArray(res.data) ? res.data : [];
  return categoriesCache;
}

function categoryName(categories, item) {
  if (item.category_name) {
    return String(item.category_name);
  }
  if (item.category_id != null) {
    const found = categories.find((c) => String(c.id) === String(item.category_id));
    if (found) {
      return pickName(found);
    }
  }
  return item.category_slug ? String(item.category_slug) : '';
}

async function fetchWindow() {
  const res = await api.get('/admin/news?per_page=100');
  if (!res.ok || !Array.isArray(res.data)) {
    return { ok: false, code: res.code, status: res.status, items: [] };
  }
  for (const item of res.data) {
    stashAdminNews(item);
  }
  return { ok: true, items: res.data };
}

function filtered(items) {
  if (listState.status === 'all') {
    return items;
  }
  return items.filter((it) => String(it.status || '') === listState.status);
}

/* ---------- list ---------- */

function renderRows(tbody, items) {
  tbody.replaceChildren();
  for (const item of items) {
    const tr = document.createElement('tr');
    const tdTitle = document.createElement('td');
    const link = document.createElement('a');
    link.setAttribute('href', '#/admin/news/' + String(item.id));
    setText(link, item.title);
    link.addEventListener('click', () => stashAdminNews(item));
    tdTitle.appendChild(link);
    tr.appendChild(tdTitle);
    const tdStatus = document.createElement('td');
    const badge = document.createElement('span');
    badge.className = 'badge badge-' + String(item.status || 'draft');
    setText(badge, item.status != null ? String(item.status) : '');
    tdStatus.appendChild(badge);
    tr.appendChild(tdStatus);
    const tdDate = document.createElement('td');
    setText(tdDate, fmtDate(item.created_at));
    tr.appendChild(tdDate);
    const tdSlug = document.createElement('td');
    tdSlug.className = 'mono';
    setText(tdSlug, item.slug != null ? String(item.slug) : '');
    tr.appendChild(tdSlug);
    const tdAct = document.createElement('td');
    const edit = document.createElement('a');
    edit.className = 'btn btn-secondary btn-small';
    edit.setAttribute('href', '#/admin/news/' + String(item.id));
    setText(edit, t('admin.news_edit'));
    edit.addEventListener('click', () => stashAdminNews(item));
    tdAct.appendChild(edit);
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
    showError(status, win.code, () => loadList(box, status, pagerBox, ctx), 'admin.news_error', 'state.retry');
    return;
  }
  const categories = await loadCategories();
  if (ctx.dead()) {
    return;
  }
  const items = filtered(win.items);
  clear(box);
  if (items.length === 0) {
    showEmpty(status, 'admin.news_empty');
    return;
  }
  setText(status, t('admin.news_list_title') + ' (' + items.length + ')');
  const wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  const table = document.createElement('table');
  table.className = 'data-table';
  const thead = document.createElement('thead');
  const hr = document.createElement('tr');
  for (const key of ['admin.news_col_title', 'admin.news_col_status', 'admin.news_col_created', 'admin.news_form_slug', 'admin.news_col_actions']) {
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

export async function renderAdminNewsList(body, user, ctx) {
  clear(body);
  const h = document.createElement('h1');
  h.className = 'page-title';
  setText(h, t('admin.news_list_title'));
  body.appendChild(h);
  const bar = document.createElement('div');
  bar.className = 'filter-bar';
  const label = document.createElement('label');
  label.className = 'filter-inline';
  const span = document.createElement('span');
  setText(span, t('admin.news_filter_status'));
  label.appendChild(span);
  const sel = document.createElement('select');
  sel.className = 'filter-select';
  for (const [val, key] of [['all', 'admin.news_filter_all'], ['draft', 'admin.news_filter_draft'], ['published', 'admin.news_filter_published']]) {
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
    const box = body.querySelector('[data-news-box]');
    const pagerBox = body.querySelector('[data-news-pager]');
    if (status && box && pagerBox) {
      loadList(box, status, pagerBox, ctx);
    }
  });
  label.appendChild(sel);
  bar.appendChild(label);
  const fresh = document.createElement('a');
  fresh.className = 'btn btn-primary btn-link';
  fresh.setAttribute('href', '#/admin/news/new');
  setText(fresh, t('admin.news_new'));
  bar.appendChild(fresh);
  body.appendChild(bar);
  const status = statusLine(body);
  const box = document.createElement('div');
  box.setAttribute('data-news-box', '1');
  body.appendChild(box);
  const pagerBox = document.createElement('div');
  pagerBox.setAttribute('data-news-pager', '1');
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

function buildNewsForm(head, categories, initial) {
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
  const titleErr = fieldErrorEl('n-title-error');
  const wTitle = document.createElement('div');
  wTitle.className = 'field';
  const lTitle = document.createElement('label');
  lTitle.setAttribute('for', 'n-title');
  setText(lTitle, t('admin.news_form_title'));
  title.id = 'n-title';
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
  const bodyErr = fieldErrorEl('n-body-error');
  const wBody = document.createElement('div');
  wBody.className = 'field';
  const lBody = document.createElement('label');
  lBody.setAttribute('for', 'n-body');
  setText(lBody, t('admin.news_form_body'));
  body.id = 'n-body';
  body.setAttribute('aria-describedby', bodyErr.id);
  wBody.appendChild(lBody);
  wBody.appendChild(body);
  wBody.appendChild(bodyErr);
  form.appendChild(wBody);

  const cat = document.createElement('select');
  const noCat = document.createElement('option');
  noCat.value = '';
  setText(noCat, t('admin.news_form_category'));
  cat.appendChild(noCat);
  for (const c of categories) {
    const opt = document.createElement('option');
    opt.value = String(c.id);
    setText(opt, pickName(c));
    cat.appendChild(opt);
  }
  if (initial && initial.category_id != null) {
    cat.value = String(initial.category_id);
  }
  const wCat = document.createElement('div');
  wCat.className = 'field';
  const lCat = document.createElement('label');
  lCat.setAttribute('for', 'n-cat');
  setText(lCat, t('admin.news_form_category'));
  cat.id = 'n-cat';
  wCat.appendChild(lCat);
  wCat.appendChild(cat);
  form.appendChild(wCat);

  const st = document.createElement('select');
  for (const s of FORM_STATUSES) {
    const opt = document.createElement('option');
    opt.value = s;
    setText(opt, t('admin.news_form_status_' + s));
    st.appendChild(opt);
  }
  st.value = initial && initial.status ? String(initial.status) : 'draft';
  const wSt = document.createElement('div');
  wSt.className = 'field';
  const lSt = document.createElement('label');
  lSt.setAttribute('for', 'n-status');
  setText(lSt, t('admin.news_form_status'));
  st.id = 'n-status';
  wSt.appendChild(lSt);
  wSt.appendChild(st);
  form.appendChild(wSt);

  const row = document.createElement('div');
  row.className = 'success-actions';
  const save = document.createElement('button');
  save.type = 'submit';
  save.className = 'btn btn-primary';
  setText(save, t('admin.news_form_save'));
  row.appendChild(save);
  const cancel = document.createElement('a');
  cancel.className = 'btn btn-secondary btn-link';
  cancel.setAttribute('href', '#/admin/news');
  setText(cancel, t('admin.news_form_cancel'));
  row.appendChild(cancel);
  form.appendChild(row);
  head.appendChild(form);
  return { form, title, titleErr, body, bodyErr, cat, st, save };
}

function validateNews(f) {
  const problems = [];
  const title = f.title.value.trim();
  const body = f.body.value.trim();
  if (title === '') {
    setFieldError(f.title, f.titleErr, t('admin.news_form_title_required'));
    problems.push(t('admin.news_form_title'));
  } else if (title.length > 255) {
    setFieldError(f.title, f.titleErr, t('admin.news_form_title_max'));
    problems.push(t('admin.news_form_title'));
  } else {
    setFieldError(f.title, f.titleErr, '');
  }
  if (body === '') {
    setFieldError(f.body, f.bodyErr, t('admin.news_form_body_required'));
    problems.push(t('admin.news_form_body'));
  } else if (body.length < 10) {
    setFieldError(f.body, f.bodyErr, t('admin.news_form_body_min'));
    problems.push(t('admin.news_form_body'));
  } else {
    setFieldError(f.body, f.bodyErr, '');
  }
  return { problems, title, body };
}

/* ---------- create ---------- */

export async function renderAdminNewsNew(body, user, ctx) {
  clear(body);
  const h = document.createElement('h1');
  h.className = 'page-title';
  setText(h, t('admin.news_new'));
  body.appendChild(h);
  backLink(body, '#/admin/news', 'admin.detail_back');
  const status = statusLine(body);
  const summary = errorSummary(body);
  setText(status, t('state.loading'));
  const categories = await loadCategories();
  if (ctx.dead()) {
    return;
  }
  setText(status, '');
  const f = buildNewsForm(body, categories, null);
  f.form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const v = validateNews(f);
    if (v.problems.length > 0) {
      showSummary(summary, v.problems);
      return;
    }
    showSummary(summary, []);
    f.save.disabled = true;
    const payload = { title: v.title, body: v.body, status: FORM_STATUSES.includes(f.st.value) ? f.st.value : 'draft' };
    if (f.cat.value !== '') {
      payload.category_id = Number(f.cat.value);
    }
    const res = await api.post('/admin/news', payload);
    if (ctx.dead()) {
      return;
    }
    f.save.disabled = false;
    if (!res.ok) {
      if (res.status === 401) {
        return;
      }
      if (res.status === 403) {
        showSummary(summary, [t('admin.news_form_scope_error') + ' (' + res.code + ')']);
      } else {
        showSummary(summary, [t('admin.news_form_save_error') + ' (' + res.code + ')']);
      }
      return;
    }
    const row = res.data || {};
    if (row.id != null) {
      stashAdminNews(row);
      window.location.hash = '#/admin/news/' + String(row.id);
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
  setText(h, t('admin.news_form_delete_confirm'));
  panel.appendChild(h);
  const row = document.createElement('div');
  row.className = 'success-actions';
  const yes = document.createElement('button');
  yes.type = 'button';
  yes.className = 'btn btn-secondary';
  setText(yes, t('admin.news_form_delete_confirm_yes'));
  yes.addEventListener('click', () => onYes(panel, yes));
  row.appendChild(yes);
  const no = document.createElement('button');
  no.type = 'button';
  no.className = 'btn btn-primary';
  setText(no, t('admin.news_form_delete_confirm_no'));
  no.addEventListener('click', () => panel.remove());
  row.appendChild(no);
  panel.appendChild(row);
  box.appendChild(panel);
  panel.focus();
}

export async function renderAdminNewsDetail(body, id, user, ctx) {
  clear(body);
  if (!/^\d+$/.test(id || '')) {
    const h = document.createElement('h1');
    h.className = 'page-title';
    setText(h, t('admin.detail_title'));
    body.appendChild(h);
    backLink(body, '#/admin/news', 'admin.detail_back');
    const status = statusLine(body);
    setText(status, t('complaint.not_found'));
    return;
  }
  const h = document.createElement('h1');
  h.className = 'page-title';
  setText(h, t('admin.detail_title'));
  body.appendChild(h);
  backLink(body, '#/admin/news', 'admin.detail_back');
  const status = statusLine(body);
  setText(status, t('state.loading'));
  const [found, categories] = await Promise.all([resolveItem(id, ctx), loadCategories()]);
  if (ctx.dead() || found.dead) {
    return;
  }
  if (!found.ok) {
    if (found.status === 401) {
      return;
    }
    if (found.status === 404) {
      setText(status, t('news.detail_not_found'));
      return;
    }
    showError(status, found.code, () => renderAdminNewsDetail(body, id, user, ctx), 'admin.news_error', 'state.retry');
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
  const slugWrap = document.createElement('p');
  slugWrap.className = 'meta';
  const slugCode = document.createElement('code');
  slugCode.className = 'slug-mono';
  setText(slugCode, item.slug != null ? String(item.slug) : '');
  slugWrap.appendChild(slugCode);
  article.appendChild(slugWrap);
  const hint = document.createElement('p');
  hint.className = 'meta';
  setText(hint, t('admin.news_form_slug_hint'));
  article.appendChild(hint);
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
  setText(editBtn, t('admin.news_edit'));
  actions.appendChild(editBtn);
  // UX-only gate: operators without news.delete never see the button.
  // Backend 403 remains the real enforcement (defense in depth).
  // Permission source: enriched /me user (roles+permissions), passed
  // in as `user` — same object the guard authorized.
  const canDelete = !!user && Array.isArray(user.permissions) && user.permissions.includes('news.delete');
  let delBtn = null;
  if (canDelete) {
    delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'btn btn-secondary';
    setText(delBtn, t('admin.news_delete'));
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
    const f = buildNewsForm(formHost, categories, item);
    f.form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const v = validateNews(f);
      if (v.problems.length > 0) {
        showSummary(summary, v.problems);
        return;
      }
      showSummary(summary, []);
      f.save.disabled = true;
      const payload = { title: v.title, body: v.body, status: FORM_STATUSES.includes(f.st.value) ? f.st.value : 'draft' };
      payload.category_id = f.cat.value !== '' ? Number(f.cat.value) : null;
      const res = await api.put('/admin/news/' + String(item.id), payload);
      if (ctx.dead()) {
        return;
      }
      f.save.disabled = false;
      if (!res.ok) {
        if (res.status === 401) {
          return;
        }
        if (res.status === 403) {
          showSummary(summary, [t('admin.news_form_scope_error') + ' (' + res.code + ')']);
        } else {
          showSummary(summary, [t('admin.news_form_save_error') + ' (' + res.code + ')']);
        }
        return;
      }
      if (res.data && res.data.id != null) {
        stashAdminNews(res.data);
      } else {
        cache.delete(String(item.id));
      }
      const ok = document.createElement('p');
      ok.className = 'banner-success';
      ok.setAttribute('role', 'status');
      setText(ok, t('admin.news_form_save_success'));
      formHost.prepend(ok);
      await renderAdminNewsDetail(body, String(item.id), user, ctx);
    });
  });

  if (delBtn) {
    delBtn.addEventListener('click', () => {
    confirmPanel(body, async (panel, yes) => {
      yes.disabled = true;
      const res = await api.del('/admin/news/' + String(item.id));
      if (ctx.dead()) {
        return;
      }
      if (!res.ok) {
        if (res.status === 401) {
          return;
        }
        if (res.status === 404) {
          cache.delete(String(item.id));
          window.location.hash = '#/admin/news';
          return;
        }
        panel.replaceChildren();
        const msg = document.createElement('p');
        setText(msg, (res.status === 403 ? t('admin.news_form_scope_error') : t('admin.news_form_delete_error')) + ' (' + res.code + ')');
        panel.appendChild(msg);
        yes.disabled = false;
        return;
      }
      cache.delete(String(item.id));
      const ok = document.createElement('p');
      ok.className = 'banner-success';
      ok.setAttribute('role', 'status');
      setText(ok, t('admin.news_form_delete_success'));
      panel.replaceChildren();
      panel.appendChild(ok);
      window.setTimeout(() => {
        window.location.hash = '#/admin/news';
      }, 1200);
    });
  });
  }
  applyI18n(body);
}
