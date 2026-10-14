/**
 * detail.js — admin complaint detail + assign/status actions.
 * Officer dropdown lists active in-scope users from
 * GET /admin/users (rows carry no roles, so no role filter is
 * possible — scope + active status is the validated list).
 * Every option is a real user id: never free-text. Copy via t().
 */

import { api, setText } from '../api.js?v=4C1';
import { t, applyI18n } from '../i18n.js?v=4C1';
import {
  clear, fmtDate, pickName, statusLine, showError, metaLine, backLink,
} from '../page.js?v=4C1';

const NEXT_STATUSES = ['in_review', 'assigned', 'resolved', 'rejected', 'closed'];

let unionsCache = null;
let officersCache = null;

async function loadUnions() {
  if (unionsCache) {
    return unionsCache;
  }
  const res = await api.get('/unions');
  unionsCache = res.ok && Array.isArray(res.data) ? res.data : [];
  return unionsCache;
}

function unionName(unions, unionId) {
  const found = unions.find((u) => String(u.id) === String(unionId));
  return found ? pickName(found) : String(unionId == null ? '' : unionId);
}

async function loadOfficers() {
  if (officersCache) {
    return officersCache;
  }
  const res = await api.get('/admin/users?status=active&per_page=100');
  if (!res.ok || !Array.isArray(res.data)) {
    return { ok: false, code: res.code, status: res.status, items: [] };
  }
  officersCache = res.data;
  return { ok: true, items: officersCache };
}

export function clearOfficersCache() {
  officersCache = null;
}

function officerLabel(o) {
  const parts = [];
  if (o.name) {
    parts.push(String(o.name));
  }
  if (o.email) {
    parts.push(String(o.email));
  }
  return parts.join(' — ') || ('#' + String(o.id));
}

function definitionList(box, rows) {
  const dl = document.createElement('dl');
  dl.className = 'profile-list';
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
  box.appendChild(dl);
}

function actionMessage(box, key, code, isError) {
  const old = box.querySelector('[data-action-msg]');
  if (old) {
    old.remove();
  }
  const p = document.createElement('p');
  p.className = isError ? 'banner-warn' : 'banner-success';
  p.setAttribute('role', 'status');
  p.setAttribute('data-action-msg', '1');
  setText(p, t(key) + (code ? ' (' + code + ')' : ''));
  box.appendChild(p);
}

function assignPanel(box, id, officers, onDone, ctx) {
  const panel = document.createElement('form');
  panel.className = 'action-panel';
  panel.setAttribute('novalidate', '');
  const h = document.createElement('h3');
  setText(h, t('admin.detail_assign'));
  panel.appendChild(h);
  const label = document.createElement('label');
  label.className = 'field';
  const span = document.createElement('span');
  setText(span, t('admin.detail_assign_pick'));
  label.appendChild(span);
  const sel = document.createElement('select');
  sel.className = 'filter-select';
  for (const o of officers) {
    const opt = document.createElement('option');
    opt.value = String(o.id);
    setText(opt, officerLabel(o));
    sel.appendChild(opt);
  }
  label.appendChild(sel);
  panel.appendChild(label);
  const btn = document.createElement('button');
  btn.type = 'submit';
  btn.className = 'btn btn-primary';
  setText(btn, t('admin.detail_assign_submit'));
  panel.appendChild(btn);
  panel.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    btn.disabled = true;
    const res = await api.post('/complaints/' + id + '/assign', { assigned_to: Number(sel.value) });
    if (ctx.dead()) {
      return;
    }
    btn.disabled = false;
    if (!res.ok) {
      if (res.status === 401) {
        return;
      }
      actionMessage(box, 'admin.detail_assign_error', res.code, true);
      return;
    }
    actionMessage(box, 'admin.detail_assign_success', '', false);
    await onDone();
  });
  box.appendChild(panel);
}

function statusPanel(box, id, current, onDone, ctx) {
  const panel = document.createElement('form');
  panel.className = 'action-panel';
  panel.setAttribute('novalidate', '');
  const h = document.createElement('h3');
  setText(h, t('admin.detail_status'));
  panel.appendChild(h);
  const label = document.createElement('label');
  label.className = 'field';
  const span = document.createElement('span');
  setText(span, t('admin.detail_status_pick'));
  label.appendChild(span);
  const sel = document.createElement('select');
  sel.className = 'filter-select';
  for (const s of NEXT_STATUSES) {
    const opt = document.createElement('option');
    opt.value = s;
    setText(opt, t('status.' + s));
    if (s === current) {
      opt.selected = true;
    }
    sel.appendChild(opt);
  }
  label.appendChild(sel);
  panel.appendChild(label);
  const noteLabel = document.createElement('label');
  noteLabel.className = 'field';
  const noteSpan = document.createElement('span');
  setText(noteSpan, t('admin.detail_status_note'));
  noteLabel.appendChild(noteSpan);
  const note = document.createElement('textarea');
  note.rows = 3;
  noteLabel.appendChild(note);
  panel.appendChild(noteLabel);
  const btn = document.createElement('button');
  btn.type = 'submit';
  btn.className = 'btn btn-primary';
  setText(btn, t('admin.detail_status_submit'));
  panel.appendChild(btn);
  panel.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    btn.disabled = true;
    const body = { status: sel.value };
    if (note.value.trim() !== '') {
      body.note = note.value.trim();
    }
    const res = await api.post('/complaints/' + id + '/status', body);
    if (ctx.dead()) {
      return;
    }
    btn.disabled = false;
    if (!res.ok) {
      if (res.status === 401) {
        return;
      }
      actionMessage(box, 'admin.detail_status_error', res.code, true);
      return;
    }
    actionMessage(box, 'admin.detail_status_success', '', false);
    await onDone();
  });
  box.appendChild(panel);
}

function officerName(officers, id) {
  const found = officers.find((o) => String(o.id) === String(id));
  return found ? officerLabel(found) : String(id);
}

async function renderBody(body, id, ctx) {
  clear(body);
  const h = document.createElement('h1');
  h.className = 'page-title';
  setText(h, t('admin.detail_title'));
  body.appendChild(h);
  backLink(body, '#/admin/complaints', 'admin.detail_back');
  const status = statusLine(body);
  setText(status, t('state.loading'));
  const [res, unions, officers] = await Promise.all([api.get('/complaints/' + id), loadUnions(), loadOfficers()]);
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
    showError(status, res.code, () => renderBody(body, id, ctx), 'state.error', 'complaint.retry');
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
  const offItems = officers.ok ? officers.items : [];
  definitionList(article, [
    ['complaint.tracking_no', c.tracking_no != null ? String(c.tracking_no) : ''],
    ['complaint.subject', c.subject != null ? String(c.subject) : ''],
    ['complaint.status', c.status != null ? String(c.status) : ''],
    ['complaint.priority', c.priority != null ? String(c.priority) : ''],
    ['complaint.category', c.category != null ? String(c.category) : ''],
    ['complaint.union', unionName(unions, c.union_id)],
    ['complaint.created_at', fmtDate(c.created_at)],
    ['complaint.assigned_to', c.assigned_to != null ? officerName(offItems, c.assigned_to) : ''],
  ]);
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
    setText(hTitle, t('admin.detail_history'));
    article.appendChild(hTitle);
    const ol = document.createElement('ol');
    ol.className = 'timeline';
    for (const hEv of hist) {
      const li = document.createElement('li');
      li.className = 'timeline-item';
      const parts = [];
      if (hEv.from_status != null && hEv.from_status !== '') {
        parts.push(String(hEv.from_status) + ' → ' + String(hEv.to_status || ''));
      } else if (hEv.to_status != null) {
        parts.push(String(hEv.to_status));
      }
      const when = fmtDate(hEv.created_at);
      if (when) {
        parts.push(when);
      }
      const strong = document.createElement('strong');
      setText(strong, parts.join(' · '));
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
  body.appendChild(article);

  const actions = document.createElement('div');
  actions.className = 'action-panels';
  body.appendChild(actions);
  if (officers.ok && offItems.length > 0) {
    assignPanel(actions, id, offItems, () => renderBody(body, id, ctx), ctx);
  } else {
    const no = document.createElement('p');
    no.setAttribute('role', 'status');
    setText(no, t('admin.detail_no_officers') + (officers.ok ? '' : ' (' + officers.code + ')'));
    actions.appendChild(no);
  }
  statusPanel(actions, id, c.status != null ? String(c.status) : '', () => renderBody(body, id, ctx), ctx);
  applyI18n(body);
}

export async function renderAdminDetail(body, id, user, ctx) {
  if (!/^\d+$/.test(id || '')) {
    clear(body);
    const h = document.createElement('h1');
    h.className = 'page-title';
    setText(h, t('admin.detail_title'));
    body.appendChild(h);
    backLink(body, '#/admin/complaints', 'admin.detail_back');
    const status = statusLine(body);
    setText(status, t('complaint.not_found'));
    return;
  }
  await renderBody(body, id, ctx);
  void user;
}
