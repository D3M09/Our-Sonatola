/**
 * home.js — Home page data wiring (structure from shell, copy from locales).
 * Public endpoints only: /news?per_page=5 and /notices?per_page=5.
 * All dynamic text via textContent. Backend Bengali data shown as-is.
 */

import { api, setText } from '../api.js?v=4C1';
import { t, getLocale } from '../i18n.js?v=4C1';

const cache = { news: null, notices: null };
let wired = false;

function dateLocale() {
  return getLocale() === 'bn' ? 'bn-BD' : 'en-GB';
}

function formatDate(iso) {
  if (!iso) {
    return '';
  }
  const d = new Date(String(iso).replace(' ', 'T') + 'Z');
  if (Number.isNaN(d.getTime())) {
    return String(iso).slice(0, 10);
  }
  try {
    return new Intl.DateTimeFormat(dateLocale(), { dateStyle: 'medium' }).format(d);
  } catch (formatError) {
    return String(iso).slice(0, 10);
  }
}

function statusEl(scope, section) {
  return scope.querySelector('[data-section="' + section + '"]');
}

function listEl(scope, section) {
  return scope.querySelector('[data-list="' + section + '"]');
}

function showSkeleton(list, count) {
  list.replaceChildren();
  for (let i = 0; i < count; i++) {
    const s = document.createElement('div');
    s.className = 'skeleton';
    s.setAttribute('aria-hidden', 'true');
    list.appendChild(s);
  }
}

function showRetry(status, onRetry) {
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn btn-secondary retry-btn';
  setText(btn, t('state.retry'));
  btn.addEventListener('click', onRetry);
  status.appendChild(document.createTextNode(' '));
  status.appendChild(btn);
}

function renderNews(scope, items) {
  const list = listEl(scope, 'news');
  const status = statusEl(scope, 'news');
  list.replaceChildren();
  setText(status, '');
  if (!items || items.length === 0) {
    setText(status, t('state.empty.news'));
    return;
  }
  for (const item of items.slice(0, 5)) {
    const card = document.createElement('article');
    card.className = 'card';
    const title = document.createElement('h3');
    setText(title, item.title);
    card.appendChild(title);
    const meta = document.createElement('p');
    meta.className = 'meta';
    const parts = [];
    if (item.category_name) {
      parts.push(String(item.category_name));
    }
    const when = formatDate(item.published_at);
    if (when) {
      parts.push(when);
    }
    setText(meta, parts.join(' · '));
    card.appendChild(meta);
    list.appendChild(card);
  }
}

function renderNotices(scope, items) {
  const list = listEl(scope, 'notices');
  const status = statusEl(scope, 'notices');
  list.replaceChildren();
  setText(status, '');
  if (!items || items.length === 0) {
    setText(status, t('state.empty.notices'));
    return;
  }
  for (const item of items.slice(0, 5)) {
    const li = document.createElement('li');
    li.className = 'notice-item';
    const title = document.createElement('span');
    title.className = 'notice-title';
    setText(title, item.title);
    li.appendChild(title);
    const when = formatDate(item.published_at);
    if (when) {
      const date = document.createElement('time');
      date.className = 'meta';
      setText(date, when);
      li.appendChild(date);
    }
    list.appendChild(li);
  }
}

function renderError(scope, section, code, onRetry) {
  const status = statusEl(scope, section);
  // Generic message + machine code only (never raw API strings).
  setText(status, t('state.error') + ' (' + code + ')');
  showRetry(status, onRetry);
}

async function loadNews(scope) {
  const list = listEl(scope, 'news');
  const status = statusEl(scope, 'news');
  setText(status, t('state.loading'));
  showSkeleton(list, 3);
  const res = await api.get('/news?per_page=5');
  if (!res.ok) {
    list.replaceChildren();
    renderError(scope, 'news', res.code, () => loadNews(scope));
    return;
  }
  cache.news = Array.isArray(res.data) ? res.data : [];
  renderNews(scope, cache.news);
}

async function loadNotices(scope) {
  const list = listEl(scope, 'notices');
  const status = statusEl(scope, 'notices');
  setText(status, t('state.loading'));
  showSkeleton(list, 3);
  const res = await api.get('/notices?per_page=5');
  if (!res.ok) {
    list.replaceChildren();
    renderError(scope, 'notices', res.code, () => loadNotices(scope));
    return;
  }
  cache.notices = Array.isArray(res.data) ? res.data : [];
  renderNotices(scope, cache.notices);
}

export async function initHome(scope) {
  const root = scope || document;
  await Promise.all([loadNews(root), loadNotices(root)]);
  if (wired) {
    return;
  }
  wired = true;
  window.addEventListener('locale:changed', () => {
    // Re-render cached data so dates follow the new locale.
    if (cache.news) {
      renderNews(root, cache.news);
    }
    if (cache.notices) {
      renderNotices(root, cache.notices);
    }
  });
}
