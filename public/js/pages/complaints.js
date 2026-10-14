/**
 * complaints.js — citizen complaint pages (#/complaints/new, /mine, /{id}).
 * Auth guard via auth.js requireAuth (Bearer module-scope only).
 * Retry-after-failure re-fetches /complaints/mine first: no idempotency
 * key exists backend-side, so a blind retry could double-submit.
 * Copy via t(); DOM via createElement/textContent (CSP-safe).
 */

import { api, setText } from '../api.js?v=4C1';
import { t, applyI18n } from '../i18n.js?v=4C1';
import { requireAuth } from './auth.js?v=4C1';
import {
  clear, fmtDate, pickName, pageHead, statusLine, showSkeleton,
  showEmpty, showError, cardGrid, metaLine, pager, backLink,
} from '../page.js?v=4C1';

const PER_PAGE = 10;
const PHONE_RE = /^[0-9+\-\s]{6,20}$/;
const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

let unionsCache = null;
let minePage = 1;

async function loadUnions() {
  if (unionsCache) {
    return { ok: true, items: unionsCache };
  }
  const res = await api.get('/unions');
  if (!res.ok || !Array.isArray(res.data)) {
    return { ok: false, code: res.code, items: [] };
  }
  unionsCache = res.data;
  return { ok: true, items: unionsCache };
}

/** Tolerant list for read-only name mapping (detail page). */
async function unionList() {
  const r = await loadUnions();
  return r.items;
}

function unionName(unions, unionId) {
  const found = unions.find((u) => String(u.id) === String(unionId));
  return found ? pickName(found) : String(unionId == null ? '' : unionId);
}

/* ---------- shared form bits (same pattern as auth.js) ---------- */

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

function labeledControl(box, n, labelKey, control, err) {
  const wrap = document.createElement('div');
  wrap.className = 'field';
  const label = document.createElement('label');
  label.setAttribute('for', 'c-' + n);
  setText(label, t(labelKey));
  control.id = 'c-' + n;
  control.setAttribute('aria-describedby', err.id);
  wrap.appendChild(label);
  wrap.appendChild(control);
  wrap.appendChild(err);
  box.appendChild(wrap);
}

/* ---------- #/complaints/new ---------- */

function buildForm(head, unions) {
  const form = document.createElement('form');
  form.className = 'auth-form complaint-form';
  form.setAttribute('novalidate', '');

  const subject = document.createElement('input');
  subject.type = 'text';
  subject.required = true;
  subject.setAttribute('maxlength', '200');
  subject.setAttribute('autocomplete', 'off');
  const subjectErr = fieldErrorEl('c-subject-error');
  labeledControl(form, 'subject', 'complaint.subject', subject, subjectErr);

  const desc = document.createElement('textarea');
  desc.required = true;
  desc.rows = 5;
  const descErr = fieldErrorEl('c-description-error');
  labeledControl(form, 'description', 'complaint.description', desc, descErr);

  const unionSel = document.createElement('select');
  unionSel.required = true;
  const prompt = document.createElement('option');
  prompt.value = '';
  setText(prompt, t('complaint.union'));
  unionSel.appendChild(prompt);
  for (const u of unions) {
    const opt = document.createElement('option');
    opt.value = String(u.id);
    setText(opt, pickName(u));
    unionSel.appendChild(opt);
  }
  const unionErr = fieldErrorEl('c-union-error');
  labeledControl(form, 'union', 'complaint.union', unionSel, unionErr);

  const cat = document.createElement('input');
  cat.type = 'text';
  cat.setAttribute('maxlength', '100');
  cat.setAttribute('autocomplete', 'off');
  const catErr = fieldErrorEl('c-category-error');
  labeledControl(form, 'category', 'complaint.category', cat, catErr);

  const phone = document.createElement('input');
  phone.type = 'tel';
  phone.setAttribute('inputmode', 'tel');
  phone.setAttribute('autocomplete', 'tel');
  const phoneErr = fieldErrorEl('c-contact-phone-error');
  labeledControl(form, 'contact_phone', 'complaint.contact_phone', phone, phoneErr);

  const prio = document.createElement('select');
  for (const p of PRIORITIES) {
    const opt = document.createElement('option');
    opt.value = p;
    setText(opt, t('complaint.priority_' + p));
    if (p === 'normal') {
      opt.selected = true;
    }
    prio.appendChild(opt);
  }
  const prioErr = fieldErrorEl('c-priority-error');
  labeledControl(form, 'priority', 'complaint.priority', prio, prioErr);

  const btn = document.createElement('button');
  btn.type = 'submit';
  btn.className = 'btn btn-primary';
  setText(btn, t('complaint.submit'));
  form.appendChild(btn);
  head.appendChild(form);
  return { form, subject, subjectErr, desc, descErr, unionSel, unionErr, cat, catErr, phone, phoneErr, prio, btn };
}

function readValues(f) {
  return {
    subject: f.subject.value.trim(),
    description: f.desc.value.trim(),
    unionId: f.unionSel.value,
    category: f.cat.value.trim(),
    phone: f.phone.value.trim(),
    priority: PRIORITIES.includes(f.prio.value) ? f.prio.value : 'normal',
  };
}

function validate(v, f) {
  const problems = [];
  if (v.subject === '' || v.subject.length > 200) {
    setFieldError(f.subject, f.subjectErr, t('complaint.subject'));
    problems.push(t('complaint.subject'));
  } else {
    setFieldError(f.subject, f.subjectErr, '');
  }
  if (v.description.length < 10) {
    setFieldError(f.desc, f.descErr, t('complaint.description'));
    problems.push(t('complaint.description'));
  } else {
    setFieldError(f.desc, f.descErr, '');
  }
  if (v.unionId === '') {
    setFieldError(f.unionSel, f.unionErr, t('complaint.union'));
    problems.push(t('complaint.union'));
  } else {
    setFieldError(f.unionSel, f.unionErr, '');
  }
  if (v.category.length > 100) {
    setFieldError(f.cat, f.catErr, t('complaint.category'));
    problems.push(t('complaint.category'));
  } else {
    setFieldError(f.cat, f.catErr, '');
  }
  if (v.phone !== '' && !PHONE_RE.test(v.phone)) {
    setFieldError(f.phone, f.phoneErr, t('state.error'));
    problems.push(t('complaint.contact_phone'));
  } else {
    setFieldError(f.phone, f.phoneErr, '');
  }
  return problems;
}

function submitBody(v) {
  const body = {
    subject: v.subject,
    description: v.description,
    union_id: Number(v.unionId),
    priority: v.priority,
  };
  if (v.category !== '') {
    body.category = v.category;
  }
  if (v.phone !== '') {
    body.contact_phone = v.phone;
  }
  return body;
}

function showSuccess(head, trackingNo) {
  head.replaceChildren();
  pageHead(head, 'complaint.new_title');
  const panel = document.createElement('div');
  panel.className = 'success-panel';
  const msg = document.createElement('p');
  msg.setAttribute('role', 'status');
  setText(msg, t('complaint.sent'));
  panel.appendChild(msg);
  const label = document.createElement('p');
  label.className = 'tracking-label';
  setText(label, t('complaint.tracking_no'));
  panel.appendChild(label);
  const no = document.createElement('p');
  no.className = 'tracking-no';
  setText(no, trackingNo);
  panel.appendChild(no);
  const row = document.createElement('div');
  row.className = 'success-actions';
  const copy = document.createElement('button');
  copy.type = 'button';
  copy.className = 'btn btn-secondary';
  setText(copy, t('complaint.copy'));
  copy.addEventListener('click', async () => {
    let done = false;
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(trackingNo);
        done = true;
      }
    } catch (clipError) {
      done = false;
    }
    if (!done) {
      const ta = document.createElement('textarea');
      ta.value = trackingNo;
      ta.setAttribute('aria-hidden', 'true');
      document.body.appendChild(ta);
      ta.select();
      try {
        done = document.execCommand('copy');
      } catch (fallbackError) {
        done = false;
      }
      ta.remove();
    }
    if (done) {
      setText(copy, t('complaint.copied'));
      copy.disabled = true;
    }
  });
  row.appendChild(copy);
  const mine = document.createElement('a');
  mine.className = 'btn btn-primary btn-link';
  mine.setAttribute('href', '#/complaints/mine');
  setText(mine, t('complaint.view_mine'));
  row.appendChild(mine);
  panel.appendChild(row);
  head.appendChild(panel);
  applyI18n(head);
}

async function sendFailed(head, status, v, f, summary, btn, ctx, failCode) {
  setText(status, t('complaint.send_failed') + (failCode ? ' (' + failCode + ')' : ''));
  const retry = document.createElement('button');
  retry.type = 'button';
  retry.className = 'btn btn-secondary retry-btn';
  setText(retry, t('complaint.retry'));
  status.appendChild(document.createTextNode(' '));
  status.appendChild(retry);
  retry.addEventListener('click', async () => {
    retry.disabled = true;
    setText(status, t('complaint.checking_before_retry'));
    // No idempotency key backend-side: the failed POST may still have
    // landed. Look for a fresh matching item before re-submitting.
    const check = await api.get('/complaints/mine?per_page=20');
    if (ctx.dead()) {
      return;
    }
    if (check.ok && Array.isArray(check.data)) {
      const cutoff = Date.now() - 10 * 60 * 1000;
      const dup = check.data.find((it) => it
        && String(it.subject || '') === v.subject
        && String(it.description || '') === v.description
        && Date.parse(String(it.created_at || '').replace(' ', 'T') + 'Z') >= cutoff);
      if (dup && dup.tracking_no) {
        showSuccess(head, String(dup.tracking_no));
        return;
      }
    }
    setText(status, '');
    await submitOnce(head, status, summary, v, f, btn, ctx);
  }, { once: true });
  if (failCode && failCode !== 'network_error') {
    showSummary(summary, [t('complaint.send_failed') + ' (' + failCode + ')']);
  }
}

async function submitOnce(head, status, summary, v, f, btn, ctx) {
  btn.disabled = true;
  const res = await api.post('/complaints', submitBody(v));
  if (ctx.dead()) {
    return;
  }
  btn.disabled = false;
  if (!res.ok) {
    if (res.status === 401) {
      return;
    }
    if (res.status === 429) {
      showSummary(summary, [t('auth.login_backoff') + ' (' + res.code + ')']);
      return;
    }
    if (res.status === 422) {
      showSummary(summary, [t('state.error') + ' (' + res.code + ')']);
      return;
    }
    await sendFailed(head, status, v, f, summary, btn, ctx, res.code);
    return;
  }
  const row = res.data || {};
  showSuccess(head, row.tracking_no != null ? String(row.tracking_no) : '');
}

export async function renderComplaintNew(view, match, ctx) {
  clear(view);
  if (!requireAuth()) {
    return;
  }
  void match;
  const head = pageHead(view, 'complaint.new_title');
  const status = statusLine(head);
  setText(status, t('state.loading'));
  const held = await loadUnions();
  if (ctx.dead()) {
    return;
  }
  if (!held.ok) {
    setText(status, '');
    showError(status, held.code, () => renderComplaintNew(view, match, ctx), 'state.error', 'complaint.retry');
    return;
  }
  if (held.items.length === 0) {
    setText(status, t('complaint.no_unions'));
    const go = document.createElement('a');
    go.className = 'back-link';
    go.setAttribute('href', '#/unions');
    setText(go, t('complaint.no_unions_link'));
    head.appendChild(go);
    return;
  }
  setText(status, '');
  const summary = errorSummary(head);
  const f = buildForm(head, held.items);
  f.form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const v = readValues(f);
    const problems = validate(v, f);
    if (problems.length > 0) {
      showSummary(summary, problems);
      return;
    }
    showSummary(summary, []);
    setText(status, '');
    await submitOnce(head, status, summary, v, f, f.btn, ctx);
  });
}

/* ---------- #/complaints/mine ---------- */

function renderMineItems(grid, items) {
  clear(grid);
  for (const item of items) {
    const a = document.createElement('a');
    a.className = 'card card-link';
    a.setAttribute('href', '#/complaints/' + String(item.id));
    const sub = document.createElement('h3');
    setText(sub, item.subject);
    a.appendChild(sub);
    a.appendChild(metaLine([
      item.tracking_no != null ? String(item.tracking_no) : '',
      item.status != null ? String(item.status) : '',
      fmtDate(item.created_at),
    ]));
    grid.appendChild(a);
  }
}

async function loadMine(head, status, grid, pagerBox, ctx) {
  setText(status, t('state.loading'));
  clear(grid);
  clear(pagerBox);
  showSkeleton(grid, 3);
  const res = await api.get('/complaints/mine?page=' + minePage + '&per_page=' + PER_PAGE);
  if (ctx.dead()) {
    return;
  }
  if (!res.ok) {
    if (res.status === 401) {
      return;
    }
    clear(grid);
    showError(status, res.code, () => loadMine(head, status, grid, pagerBox, ctx), 'state.error', 'complaint.retry');
    return;
  }
  const items = Array.isArray(res.data) ? res.data : [];
  const pg = res.meta && res.meta.pagination ? res.meta.pagination : null;
  const totalPages = pg ? Math.max(1, Number(pg.total_pages) || 1) : 1;
  clear(grid);
  if (items.length === 0) {
    showEmpty(status, 'complaint.empty');
    return;
  }
  setText(status, '');
  renderMineItems(grid, items);
  clear(pagerBox);
  if (totalPages > 1) {
    pager(pagerBox, minePage, totalPages, (p) => {
      minePage = p;
      loadMine(head, status, grid, pagerBox, ctx);
    }, 'news.pagination_prev', 'news.pagination_next', 'news.page_indicator');
  }
}

export async function renderComplaintMine(view, match, ctx) {
  clear(view);
  if (!requireAuth()) {
    return;
  }
  void match;
  const head = pageHead(view, 'complaint.mine_title');
  const status = statusLine(head);
  const grid = cardGrid(head);
  const pagerBox = document.createElement('div');
  head.appendChild(pagerBox);
  await loadMine(head, status, grid, pagerBox, ctx);
}

/* ---------- #/complaints/{id} ---------- */

export async function renderComplaintDetail(view, match, ctx) {
  clear(view);
  if (!requireAuth()) {
    return;
  }
  const id = /^\d+$/.test(match[1] || '') ? match[1] : '';
  const head = pageHead(view, 'complaint.detail_title');
  backLink(head, '#/complaints/mine', 'complaint.back_to_mine');
  const status = statusLine(head);
  if (!id) {
    setText(status, t('complaint.not_found'));
    return;
  }
  setText(status, t('state.loading'));
  const [res, unions] = await Promise.all([api.get('/complaints/' + id), unionList()]);
  if (ctx.dead()) {
    return;
  }
  if (!res.ok) {
    if (res.status === 401) {
      return;
    }
    if (res.status === 403) {
      setText(status, t('complaint.not_yours'));
      return;
    }
    if (res.status === 404) {
      setText(status, t('complaint.not_found'));
      return;
    }
    showError(status, res.code, () => renderComplaintDetail(view, match, ctx), 'state.error', 'complaint.retry');
    return;
  }
  const c = res.data || {};
  setText(status, '');
  const article = document.createElement('article');
  article.className = 'article';
  const sub = document.createElement('h2');
  sub.className = 'article-title';
  setText(sub, c.subject);
  article.appendChild(sub);
  article.appendChild(metaLine([
    c.tracking_no != null ? String(c.tracking_no) : '',
    c.status != null ? String(c.status) : '',
    fmtDate(c.created_at),
  ]));
  const dl = document.createElement('dl');
  dl.className = 'profile-list';
  const rows = [
    ['complaint.tracking_no', c.tracking_no != null ? String(c.tracking_no) : ''],
    ['complaint.status', c.status != null ? String(c.status) : ''],
    ['complaint.priority', c.priority != null ? String(c.priority) : ''],
    ['complaint.category', c.category != null ? String(c.category) : ''],
    ['complaint.union', unionName(unions, c.union_id)],
    ['complaint.created_at', fmtDate(c.created_at)],
    ['complaint.assigned_to', c.assigned_to != null ? String(c.assigned_to) : ''],
  ];
  for (const [termKey, value] of rows) {
    if (!value) {
      continue;
    }
    const dt = document.createElement('dt');
    setText(dt, t(termKey));
    const dd = document.createElement('dd');
    setText(dd, value);
    dl.appendChild(dt);
    dl.appendChild(dd);
  }
  article.appendChild(dl);
  if (c.description) {
    for (const chunk of String(c.description).split(/\r?\n\r?\n|\r?\n/)) {
      if (!chunk.trim()) {
        continue;
      }
      const p = document.createElement('p');
      setText(p, chunk.trim());
      article.appendChild(p);
    }
  }
  const hist = Array.isArray(c.history) ? c.history : [];
  if (hist.length > 0) {
    const hTitle = document.createElement('h3');
    setText(hTitle, t('complaint.history'));
    article.appendChild(hTitle);
    const ol = document.createElement('ol');
    ol.className = 'timeline';
    for (const hEv of hist) {
      const li = document.createElement('li');
      li.className = 'timeline-item';
      const to = hEv.to_status != null ? String(hEv.to_status) : '';
      const when = fmtDate(hEv.created_at);
      const strong = document.createElement('strong');
      setText(strong, [to, when].filter(Boolean).join(' · '));
      li.appendChild(strong);
      if (hEv.note) {
        const note = document.createElement('p');
        setText(note, hEv.note);
        li.appendChild(note);
      }
      ol.appendChild(li);
    }
    article.appendChild(ol);
  }
  head.appendChild(article);
}
