/**
 * i18n.js — security glue (hand-written, reviewable).
 * Locale loader: all visible copy comes from locales/*.json.
 * Default locale 'bn'; override persisted ONLY as localStorage['locale'].
 * Only the locale preference may live in localStorage (never tokens).
 */

import { setText } from './api.js?v=4C1';

const SUPPORTED = ['bn', 'en'];
const DEFAULT_LOCALE = 'bn';
const STORAGE_KEY = 'locale';

const cache = {};
let current = DEFAULT_LOCALE;

export function getLocale() {
  return current;
}

export function supportedLocales() {
  return SUPPORTED.slice();
}

function storedLocale() {
  try {
    const v = window.localStorage.getItem(STORAGE_KEY);
    return SUPPORTED.includes(v) ? v : null;
  } catch (storageError) {
    return null;
  }
}

export async function loadLocale(lang) {
  const want = SUPPORTED.includes(lang) ? lang : DEFAULT_LOCALE;
  if (cache[want]) {
    return cache[want];
  }
  const res = await fetch('/js/locales/' + want + '.json?v=4C1', { credentials: 'same-origin' });
  if (!res.ok) {
    throw new Error('locale load failed');
  }
  const data = await res.json();
  cache[want] = data;
  return data;
}

/**
 * Translate a key. Falls back to default locale, then the key itself.
 * Never throws for missing keys (returns key so gaps are visible).
 */
export function t(key) {
  const table = cache[current] || {};
  if (Object.prototype.hasOwnProperty.call(table, key)) {
    return table[key];
  }
  const fallback = cache[DEFAULT_LOCALE] || {};
  if (Object.prototype.hasOwnProperty.call(fallback, key)) {
    return fallback[key];
  }
  return key;
}

/** Apply current locale table to [data-i18n] bindings under root. */
export function applyI18n(root) {
  const scope = root || document;
  const textNodes = scope.querySelectorAll('[data-i18n]');
  for (const el of textNodes) {
    const key = el.getAttribute('data-i18n');
    if (key) {
      setText(el, t(key));
    }
  }
  const ariaNodes = scope.querySelectorAll('[data-i18n-aria-label]');
  for (const el of ariaNodes) {
    const key = el.getAttribute('data-i18n-aria-label');
    if (key) {
      el.setAttribute('aria-label', t(key));
    }
  }
  const altNodes = scope.querySelectorAll('[data-i18n-alt]');
  for (const el of altNodes) {
    const key = el.getAttribute('data-i18n-alt');
    if (key) {
      el.setAttribute('alt', t(key));
    }
  }
  const phNodes = scope.querySelectorAll('[data-i18n-placeholder]');
  for (const el of phNodes) {
    const key = el.getAttribute('data-i18n-placeholder');
    if (key) {
      el.setAttribute('placeholder', t(key));
    }
  }
  document.documentElement.setAttribute('lang', current);
}

export async function setLocale(lang, root) {
  const want = SUPPORTED.includes(lang) ? lang : DEFAULT_LOCALE;
  await loadLocale(want);
  if (want !== DEFAULT_LOCALE) {
    await loadLocale(DEFAULT_LOCALE).catch(() => {});
  }
  current = want;
  try {
    window.localStorage.setItem(STORAGE_KEY, current);
  } catch (storageError) {
    // Private mode etc: locale simply won't persist; UI still switches.
  }
  applyI18n(root || document);
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent('locale:changed', { detail: { locale: current } }));
  }
  return current;
}

export async function initI18n(root) {
  const initial = storedLocale() || DEFAULT_LOCALE;
  await loadLocale(DEFAULT_LOCALE);
  if (initial !== DEFAULT_LOCALE) {
    await loadLocale(initial).catch(() => {});
    current = initial;
  }
  applyI18n(root || document);
  return current;
}
