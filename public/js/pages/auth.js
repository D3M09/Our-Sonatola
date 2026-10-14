/**
 * auth.js — citizen auth pages (#/login, #/register, #/me) + session glue.
 * Bearer token lives in api.js module scope ONLY (setBearer) — never
 * localStorage/sessionStorage/cookies. Lost on reload by design.
 * All copy via t(); DOM via createElement/textContent (CSP-safe).
 * Form errors: summary (role=alert, focus moved) + inline field errors
 * linked with aria-describedby (uiuxpromax ux guidance).
 */

import { api, setText, setCsrf, setBearer, getBearer } from '../api.js?v=4C1';
import { dbg } from '../debug.js?v=4C1';
import { isStaffRoles } from '../admin/guard.js?v=4C1';
import { t, applyI18n } from '../i18n.js?v=4C1';
import { clear, pageHead, statusLine } from '../page.js?v=4C1';

let cachedUser = null;

export function isAuthenticated() {
  return getBearer() !== '';
}

export function getUser() {
  return cachedUser;
}

export function setSession(token, csrf, user) {
  setBearer(token);
  setCsrf(csrf);
  // Persist CSRF only (per-tab, cleared on tab close). Useless without
  // the HttpOnly session cookie, and readable-from-DOM anyway on XSS.
  // Bearer is NEVER persisted (unchanged rule).
  try {
    window.sessionStorage.setItem('csrf', typeof csrf === 'string' ? csrf : '');
  } catch (storageError) {
    // Private mode etc: reload-restore simply won't work.
  }
  cachedUser = user || null;
  window.dispatchEvent(new CustomEvent('auth:success'));
}

export function clearSession() {
  setBearer('');
  setCsrf('');
  try {
    window.sessionStorage.removeItem('csrf');
  } catch (storageError) {
    // ignore
  }
  cachedUser = null;
}

/**
 * Reload restore (Option D): the session cookie may have survived
 * while the module heap (bearer) did not. Re-hydrate identity from
 * /me + the stored CSRF so writes keep working. Returns true/false.
 */
export async function restoreSession() {
  let saved = '';
  try {
    saved = window.sessionStorage.getItem('csrf') || '';
  } catch (storageError) {
    saved = '';
  }
  if (!saved) {
    return false;
  }
  try {
    const resp = await api.get('/me');
    if (resp.ok && resp.data) {
      setBearer('');
      setCsrf(saved);
      try {
        window.sessionStorage.setItem('csrf', saved);
      } catch (storageError) {
        // ignore
      }
      cachedUser = resp.data;
      window.dispatchEvent(new CustomEvent('auth:success'));
      dbg('session-restore', 'ok roles=' + JSON.stringify(resp.data.roles || null));
      return true;
    }
    try {
      window.sessionStorage.removeItem('csrf');
    } catch (storageError) {
      // ignore
    }
    dbg('session-restore', 'failed status=' + resp.status);
  } catch (restoreError) {
    try {
      window.sessionStorage.removeItem('csrf');
    } catch (storageError) {
      // ignore
    }
    dbg('session-restore', 'error');
  }
  return false;
}

function queryOf(match) {
  let q = '';
  if (match && match[1]) {
    q = String(match[1]);
    const qi = q.indexOf('?');
    if (qi !== -1) {
      q = q.slice(qi + 1);
    }
  }
  return new URLSearchParams(q);
}

function go(hash) {
  window.location.hash = hash;
}

export function requireAuth() {
  if (!isAuthenticated()) {
    const cur = window.location.hash || '#/';
    const next = cur.startsWith('#/') ? cur.slice(1) : '/me';
    go('#/login?next=' + encodeURIComponent(next));
    return false;
  }
  return true;
}

/* ---------- form builders ---------- */

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

function textField(box, n, labelKey, opts) {
  const o = opts || {};
  const wrap = document.createElement('div');
  wrap.className = 'field';
  const label = document.createElement('label');
  label.setAttribute('for', 'f-' + n);
  setText(label, t(labelKey));
  const input = document.createElement('input');
  input.id = 'f-' + n;
  input.name = n;
  input.type = o.type || 'text';
  if (o.autocomplete) {
    input.setAttribute('autocomplete', o.autocomplete);
  }
  if (o.required) {
    input.required = true;
  }
  if (o.inputMode) {
    input.setAttribute('inputmode', o.inputMode);
  }
  const err = fieldErrorEl('f-' + n + '-error');
  input.setAttribute('aria-describedby', err.id);
  wrap.appendChild(label);
  wrap.appendChild(input);
  wrap.appendChild(err);
  box.appendChild(wrap);
  return { input, err };
}

function submitRow(box, labelKey) {
  const btn = document.createElement('button');
  btn.type = 'submit';
  btn.className = 'btn btn-primary';
  setText(btn, t(labelKey));
  box.appendChild(btn);
  return btn;
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const PHONE_RE = /^[0-9+\-\s]{6,20}$/;

/* ---------- nav ---------- */

export function renderAuthNav() {
  const host = document.getElementById('auth-nav');
  if (!host) {
    return;
  }
  host.replaceChildren();
  if (!isAuthenticated()) {
    for (const [key, href] of [['nav.login', '#/login'], ['nav.register', '#/register']]) {
      const li = document.createElement('li');
      const a = document.createElement('a');
      a.setAttribute('href', href);
      a.setAttribute('data-route', key === 'nav.login' ? 'login' : 'register');
      a.setAttribute('data-i18n', key);
      setText(a, t(key));
      li.appendChild(a);
      host.appendChild(li);
    }
  } else {
    const links = [
      ['nav.new_complaint', '#/complaints/new', 'complaints-new'],
      ['nav.my_complaints', '#/complaints/mine', 'complaints-mine'],
      ['nav.profile', '#/me', 'me'],
    ];
    for (const [key, href, route] of links) {
      const li = document.createElement('li');
      const a = document.createElement('a');
      a.setAttribute('href', href);
      a.setAttribute('data-route', route);
      a.setAttribute('data-i18n', key);
      setText(a, t(key));
      li.appendChild(a);
      host.appendChild(li);
    }
    const liOut = document.createElement('li');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'nav-logout';
    btn.setAttribute('data-i18n', 'nav.logout');
    setText(btn, t('nav.logout'));
    btn.addEventListener('click', async () => {
      await api.post('/logout');
      clearSession();
      window.dispatchEvent(new CustomEvent('auth:logout'));
      renderAuthNav();
      go('#/');
    });
    liOut.appendChild(btn);
    host.appendChild(liOut);
  }
  applyI18n(host);
}

/* ---------- login ---------- */

function backoff(btn, seconds, ctx) {
  let left = seconds;
  btn.disabled = true;
  const base = btn.dataset.label || btn.textContent;
  btn.dataset.label = base;
  const tick = () => {
    if (ctx.dead()) {
      window.clearInterval(timer);
      return;
    }
    if (left <= 0) {
      window.clearInterval(timer);
      btn.disabled = false;
      setText(btn, base);
      return;
    }
    setText(btn, base + ' (' + left + ')');
    left -= 1;
  };
  const timer = window.setInterval(tick, 1000);
  tick();
}

export async function renderLogin(view, match, ctx) {
  clear(view);
  const head = pageHead(view, 'auth.login_title');
  const params = queryOf(match);
  if (params.get('registered') === '1') {
    const ok = document.createElement('p');
    ok.className = 'banner-success';
    ok.setAttribute('role', 'status');
    setText(ok, t('auth.register_success'));
    head.appendChild(ok);
  }
  if (params.get('expired') === '1') {
    const warn = document.createElement('p');
    warn.className = 'banner-warn';
    warn.setAttribute('role', 'status');
    setText(warn, t('auth.session_expired'));
    head.appendChild(warn);
  }
  const summary = errorSummary(head);
  const form = document.createElement('form');
  form.className = 'auth-form';
  form.setAttribute('novalidate', '');
  const em = textField(form, 'email', 'auth.login_email', { type: 'email', autocomplete: 'email', required: true });
  const pw = textField(form, 'password', 'auth.login_password', { type: 'password', autocomplete: 'current-password', required: true });
  const btn = submitRow(form, 'auth.login_submit');
  head.appendChild(form);
  const prefill = params.get('email');
  if (prefill) {
    em.input.value = prefill;
  }

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const email = em.input.value.trim();
    const pass = pw.input.value;
    let bad = false;
    if (!EMAIL_RE.test(email)) {
      setFieldError(em.input, em.err, t('auth.email_invalid'));
      bad = true;
    } else {
      setFieldError(em.input, em.err, '');
    }
    if (pass === '') {
      setFieldError(pw.input, pw.err, t('auth.password_too_short'));
      bad = true;
    } else {
      setFieldError(pw.input, pw.err, '');
    }
    if (bad) {
      showSummary(summary, [t('state.error')]);
      return;
    }
    showSummary(summary, []);
    btn.disabled = true;
    const res = await api.post('/login', { email, password: pass });
    if (ctx.dead()) {
      return;
    }
    btn.disabled = false;
    if (!res.ok) {
      if (res.status === 401) {
        showSummary(summary, [t('auth.login_error')]);
      } else if (res.status === 429) {
        showSummary(summary, [t('auth.login_backoff') + ' (' + res.code + ')']);
        backoff(btn, 30, ctx);
      } else {
        showSummary(summary, [t('state.error') + ' (' + res.code + ')']);
      }
      return;
    }
    const d = res.data || {};
    // Login's embedded user row carries NO roles (bare SELECT * —
    // enrichment happens in middleware). Fetch /me so cachedUser has
    // roles before anything (nav, guard) reads them.
    setBearer(d.token || '');
    setCsrf(d.csrf || '');
    let user = d.user || null;
    const me = await api.get('/me');
    if (!ctx.dead() && me.ok && me.data) {
      user = me.data;
    }
    if (ctx.dead()) {
      return;
    }
    dbg('login-success', 'roles=' + JSON.stringify((user && user.roles) || null));
    setSession(d.token || '', d.csrf || '', user);
    renderAuthNav();
    // Role-aware redirect (Q1/Q2): staff honor admin next=, else #/admin;
    // citizens always go Home (#/), ignoring next=.
    const rawNext = params.get('next');
    const staff = isStaffRoles(user && user.roles);
    let target = '#/';
    if (staff) {
      target = (typeof rawNext === 'string' && rawNext.startsWith('/admin')) ? '#' + rawNext : '#/admin';
    }
    dbg('login-redirect', 'target=' + target + ' isStaff=' + staff + ' next=' + (rawNext || 'null'));
    go(target);
  });
}

/* ---------- register ---------- */

export async function renderRegister(view, match, ctx) {
  clear(view);
  const head = pageHead(view, 'auth.register_title');
  const summary = errorSummary(head);
  const form = document.createElement('form');
  form.className = 'auth-form';
  form.setAttribute('novalidate', '');
  const nm = textField(form, 'name', 'auth.register_name', { autocomplete: 'name', required: true });
  const em = textField(form, 'email', 'auth.register_email', { type: 'email', autocomplete: 'email', required: true });
  const ph = textField(form, 'phone', 'auth.register_phone', { type: 'tel', autocomplete: 'tel', inputMode: 'tel' });
  const pw = textField(form, 'password', 'auth.register_password', { type: 'password', autocomplete: 'new-password', required: true });
  const pw2 = textField(form, 'password_confirm', 'auth.register_password_confirm', { type: 'password', autocomplete: 'new-password', required: true });
  const btn = submitRow(form, 'auth.register_submit');
  head.appendChild(form);
  void match;

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const v = {
      name: nm.input.value.trim(),
      email: em.input.value.trim(),
      phone: ph.input.value.trim(),
      password: pw.input.value,
      confirm: pw2.input.value,
    };
    const problems = [];
    if (v.name === '' || v.name.length > 100) {
      setFieldError(nm.input, nm.err, t('auth.register_name'));
      problems.push(t('auth.register_name'));
    } else {
      setFieldError(nm.input, nm.err, '');
    }
    if (!EMAIL_RE.test(v.email) || v.email.length > 190) {
      setFieldError(em.input, em.err, t('auth.email_invalid'));
      problems.push(t('auth.register_email'));
    } else {
      setFieldError(em.input, em.err, '');
    }
    if (v.phone !== '' && !PHONE_RE.test(v.phone)) {
      setFieldError(ph.input, ph.err, t('state.error'));
      problems.push(t('auth.register_phone'));
    } else {
      setFieldError(ph.input, ph.err, '');
    }
    if (v.password.length < 8 || v.password.length > 200) {
      setFieldError(pw.input, pw.err, t('auth.password_too_short'));
      problems.push(t('auth.register_password'));
    } else {
      setFieldError(pw.input, pw.err, '');
    }
    if (v.confirm !== v.password) {
      setFieldError(pw2.input, pw2.err, t('auth.password_mismatch'));
      problems.push(t('auth.register_password_confirm'));
    } else {
      setFieldError(pw2.input, pw2.err, '');
    }
    if (problems.length > 0) {
      showSummary(summary, problems);
      return;
    }
    showSummary(summary, []);
    btn.disabled = true;
    const body = { name: v.name, email: v.email, password: v.password };
    if (v.phone !== '') {
      body.phone = v.phone;
    }
    const res = await api.post('/register', body);
    if (ctx.dead()) {
      return;
    }
    btn.disabled = false;
    if (!res.ok) {
      if (res.status === 409) {
        setFieldError(em.input, em.err, t('auth.register_email_taken'));
        showSummary(summary, [t('auth.register_email_taken')]);
      } else if (res.status === 422) {
        showSummary(summary, [t('state.error') + ' (' + res.code + ')']);
      } else if (res.status === 429) {
        showSummary(summary, [t('auth.login_backoff') + ' (' + res.code + ')']);
      } else {
        showSummary(summary, [t('state.error') + ' (' + res.code + ')']);
      }
      return;
    }
    go('#/login?registered=1&email=' + encodeURIComponent(v.email));
  });
}

/* ---------- me ---------- */

function rolesText(user) {
  const r = user ? user.roles : null;
  if (Array.isArray(r)) {
    return r.map((x) => {
      if (x === null || x === undefined) {
        return '';
      }
      if (typeof x === 'object') {
        return String(x.slug || x.name || x.id || '');
      }
      return String(x);
    }).filter(Boolean).join(', ');
  }
  return r === null || r === undefined ? '' : String(r);
}

function definitionList(box, rows) {
  const dl = document.createElement('dl');
  dl.className = 'profile-list';
  for (const [termKey, value] of rows) {
    const dt = document.createElement('dt');
    setText(dt, t(termKey));
    const dd = document.createElement('dd');
    setText(dd, value);
    dl.appendChild(dt);
    dl.appendChild(dd);
  }
  box.appendChild(dl);
}

export async function renderMe(view, match, ctx) {
  clear(view);
  if (!requireAuth()) {
    return;
  }
  const head = pageHead(view, 'auth.me_title');
  const status = statusLine(head);
  setText(status, t('state.loading'));
  const res = await api.get('/me');
  if (ctx.dead()) {
    return;
  }
  if (!res.ok) {
    if (res.status === 401) {
      return;
    }
    setText(status, t('auth.me_update_error') + ' (' + res.code + ')');
    return;
  }
  cachedUser = res.data || {};
  setText(status, '');
  const u = cachedUser;
  definitionList(head, [
    ['auth.me_name', u.name != null ? String(u.name) : ''],
    ['auth.me_email', u.email != null ? String(u.email) : ''],
    ['auth.me_phone', u.phone != null ? String(u.phone) : ''],
    ['auth.me_status', u.account_status != null ? String(u.account_status) : ''],
    ['auth.me_roles', rolesText(u)],
  ]);

  const summary = errorSummary(head);
  const form = document.createElement('form');
  form.className = 'auth-form';
  form.setAttribute('novalidate', '');
  const nm = textField(form, 'name', 'auth.me_name', { autocomplete: 'name' });
  nm.input.value = u.name != null ? String(u.name) : '';
  const ph = textField(form, 'phone', 'auth.me_phone', { type: 'tel', autocomplete: 'tel', inputMode: 'tel' });
  ph.input.value = u.phone != null ? String(u.phone) : '';
  const btn = submitRow(form, 'auth.me_save');
  head.appendChild(form);
  void match;

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const name = nm.input.value.trim();
    const phone = ph.input.value.trim();
    if (name === '' || name.length > 100) {
      setFieldError(nm.input, nm.err, t('auth.me_name'));
      showSummary(summary, [t('auth.me_name')]);
      return;
    }
    setFieldError(nm.input, nm.err, '');
    if (phone !== '' && !PHONE_RE.test(phone)) {
      setFieldError(ph.input, ph.err, t('state.error'));
      showSummary(summary, [t('auth.me_phone')]);
      return;
    }
    setFieldError(ph.input, ph.err, '');
    showSummary(summary, []);
    btn.disabled = true;
    const put = await api.put('/me', { name, phone });
    if (ctx.dead()) {
      return;
    }
    btn.disabled = false;
    if (!put.ok) {
      if (put.status === 401) {
        return;
      }
      showSummary(summary, [t('auth.me_update_error') + ' (' + put.code + ')']);
      return;
    }
    const ok = document.createElement('p');
    ok.className = 'banner-success';
    ok.setAttribute('role', 'status');
    setText(ok, t('auth.me_saved'));
    form.appendChild(ok);
    const fresh = await api.get('/me');
    if (!ctx.dead() && fresh.ok) {
      cachedUser = fresh.data || cachedUser;
    }
  });
}
