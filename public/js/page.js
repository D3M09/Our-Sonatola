/**
 * page.js — shared list/detail view helpers (hand-written glue).
 * All visible copy comes from locales via t() — no hardcoded strings,
 * no Bengali/English literals in this file. DOM built with
 * createElement/textContent only (CSP-safe, XSS-safe).
 */

import { setText } from './api.js?v=4C1';
import { t, getLocale } from './i18n.js?v=4C1';

export function clear(el) {
  if (el) {
    el.replaceChildren();
  }
}

function dateLocale() {
  return getLocale() === 'bn' ? 'bn-BD' : 'en-GB';
}

export function fmtDate(iso) {
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

/** Locale-aware display name: name_bn first in bn locale, else name_en. */
export function pickName(row) {
  if (!row) {
    return '';
  }
  if (getLocale() === 'bn') {
    return row.name_bn || row.name_en || '';
  }
  return row.name_en || row.name_bn || '';
}

export function pageHead(el, titleKey) {
  const wrap = document.createElement('div');
  wrap.className = 'container';
  const h = document.createElement('h1');
  h.className = 'page-title';
  setText(h, t(titleKey));
  wrap.appendChild(h);
  el.appendChild(wrap);
  return wrap;
}

export function statusLine(container) {
  const p = document.createElement('p');
  p.className = 'section-status';
  p.setAttribute('role', 'status');
  container.appendChild(p);
  return p;
}

export function showSkeleton(container, count) {
  for (let i = 0; i < count; i++) {
    const s = document.createElement('div');
    s.className = 'skeleton';
    s.setAttribute('aria-hidden', 'true');
    container.appendChild(s);
  }
}

export function showEmpty(statusEl, emptyKey) {
  setText(statusEl, t(emptyKey));
}

export function showError(statusEl, code, onRetry, errorKey, retryKey) {
  setText(statusEl, t(errorKey) + ' (' + code + ')');
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn btn-secondary retry-btn';
  setText(btn, t(retryKey));
  btn.addEventListener('click', onRetry);
  statusEl.appendChild(document.createTextNode(' '));
  statusEl.appendChild(btn);
}

export function cardGrid(container) {
  const grid = document.createElement('div');
  grid.className = 'cards';
  container.appendChild(grid);
  return grid;
}

export function metaLine(parts) {
  const p = document.createElement('p');
  p.className = 'meta';
  setText(p, parts.filter(Boolean).join(' · '));
  return p;
}

/**
 * Pagination bar: prev/next buttons + "page X / Y" indicator.
 * Buttons disable at the bounds (disabled semantics, not taps-to-nowhere).
 */
export function pager(container, page, totalPages, onPage, prevKey, nextKey, indicatorKey) {
  const nav = document.createElement('nav');
  nav.className = 'pager';
  nav.setAttribute('aria-label', t(indicatorKey).replace('{page}', String(page)).replace('{total}', String(totalPages)));
  const prev = document.createElement('button');
  prev.type = 'button';
  prev.className = 'btn btn-secondary';
  setText(prev, t(prevKey));
  prev.disabled = page <= 1;
  prev.addEventListener('click', () => onPage(page - 1));
  const ind = document.createElement('span');
  ind.className = 'pager-indicator';
  ind.setAttribute('role', 'status');
  setText(ind, t(indicatorKey).replace('{page}', String(page)).replace('{total}', String(totalPages)));
  const next = document.createElement('button');
  next.type = 'button';
  next.className = 'btn btn-secondary';
  setText(next, t(nextKey));
  next.disabled = page >= totalPages;
  next.addEventListener('click', () => onPage(page + 1));
  nav.appendChild(prev);
  nav.appendChild(ind);
  nav.appendChild(next);
  container.appendChild(nav);
}

/** Category <select> filter with an "all" option. onChange receives id|'all'. */
export function categorySelect(container, categories, current, onChange, labelKey, allKey) {
  const row = document.createElement('div');
  row.className = 'filter-row';
  const label = document.createElement('label');
  label.className = 'filter-label';
  const span = document.createElement('span');
  setText(span, t(labelKey));
  label.appendChild(span);
  const select = document.createElement('select');
  select.className = 'filter-select';
  const all = document.createElement('option');
  all.value = 'all';
  setText(all, t(allKey));
  select.appendChild(all);
  for (const c of categories) {
    const opt = document.createElement('option');
    opt.value = String(c.id);
    setText(opt, pickName(c));
    select.appendChild(opt);
  }
  select.value = current === 'all' ? 'all' : String(current);
  select.addEventListener('change', () => {
    onChange(select.value === 'all' ? 'all' : Number(select.value));
  });
  label.appendChild(select);
  row.appendChild(label);
  container.appendChild(row);
}

export function backLink(container, href, backKey) {
  const a = document.createElement('a');
  a.className = 'back-link';
  a.setAttribute('href', href);
  setText(a, backKey ? t(backKey) : '');
  container.appendChild(a);
  return a;
}
