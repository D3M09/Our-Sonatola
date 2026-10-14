/**
 * router.js — hash routing glue (hand-written, reviewable).
 * Hash-based (#/news, #/news/{slug}, …) so Capacitor WebView needs no
 * server route config. Listens to hashchange for back/forward support,
 * scrolls to top on route change, highlights the active nav item.
 * No visible copy here (delegated to page modules + locales).
 */

import { applyI18n } from './i18n.js?v=4C1';

let navToken = 0;

function currentHash() {
  const h = window.location.hash || '';
  return h === '' || h === '#' ? '#/' : h;
}

function setActiveNav(routeKey) {
  const links = document.querySelectorAll('[data-route]');
  for (const a of links) {
    if (a.getAttribute('data-route') === routeKey) {
      a.setAttribute('aria-current', 'page');
    } else {
      a.removeAttribute('aria-current');
    }
  }
}

/**
 * @param {object} opts
 * @param {HTMLElement} opts.view   routed-page container (#view)
 * @param {HTMLElement} opts.home   home container (#view-home)
 * @param {Array} opts.table        [{key, match(hash)->array|null, render(el, match, ctx)}]
 * @param {function} opts.renderHome (el, ctx) home preview loader
 * @param {function} opts.renderNotFound (el, ctx)
 */
export function startRouter(opts) {
  const { view, home, table, renderHome, renderNotFound } = opts;

  function navigate(replay) {
    const token = ++navToken;
    const ctx = { dead: () => token !== navToken };
    const hash = currentHash();
    let matched = null;
    for (const r of table) {
      const m = r.match(hash);
      if (m) {
        matched = { route: r, m };
        break;
      }
    }
    if (!matched) {
      home.hidden = true;
      view.hidden = false;
      view.replaceChildren();
      setActiveNav('');
      if (!replay) {
        window.scrollTo(0, 0);
      }
      renderNotFound(view, ctx);
      applyI18n(view);
      return;
    }
    if (matched.route.key === 'home') {
      view.hidden = true;
      view.replaceChildren();
      home.hidden = false;
      setActiveNav('home');
      if (!replay) {
        window.scrollTo(0, 0);
      }
      renderHome(home, ctx);
      return;
    }
    home.hidden = true;
    view.hidden = false;
    view.replaceChildren();
    setActiveNav(matched.route.key);
    if (!replay) {
      window.scrollTo(0, 0);
    }
    matched.route.render(view, matched.m, ctx);
    applyI18n(view);
  }

  window.addEventListener('hashchange', () => navigate(false));
  window.addEventListener('locale:changed', () => navigate(true));
  navigate(false);
}
