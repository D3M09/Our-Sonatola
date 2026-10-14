/**
 * news.js — news list (#/news) + detail (#/news/{slug}).
 * Copy from locales only; backend Bengali data shown as-is via textContent.
 * Slugs round-trip with encodeURIComponent (links) / decodeURIComponent (read).
 */

import { api, setText } from '../api.js?v=4C1';
import { t } from '../i18n.js?v=4C1';
import {
  clear, fmtDate, pickName, pageHead, statusLine, showSkeleton,
  showEmpty, showError, cardGrid, metaLine, pager, categorySelect, backLink,
} from '../page.js?v=4C1';

const PER_PAGE = 10;

let categoriesCache = null;
const listState = { page: 1, category: 'all' };

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

function newsHref(slug) {
  return '#/news/' + encodeURIComponent(String(slug));
}

function renderCards(grid, items, categories) {
  clear(grid);
  for (const item of items) {
    const a = document.createElement('a');
    a.className = 'card card-link';
    a.setAttribute('href', newsHref(item.slug));
    const title = document.createElement('h3');
    setText(title, item.title);
    a.appendChild(title);
    a.appendChild(metaLine([categoryName(categories, item), fmtDate(item.published_at)]));
    if (item.excerpt) {
      const ex = document.createElement('p');
      ex.className = 'card-excerpt';
      setText(ex, item.excerpt);
      a.appendChild(ex);
    }
    grid.appendChild(a);
  }
}

async function loadList(view, status, grid, pagerBox, filterBox, ctx) {
  setText(status, t('news.loading'));
  clear(grid);
  clear(pagerBox);
  showSkeleton(grid, 3);
  const params = '/news?page=' + listState.page + '&per_page=' + PER_PAGE
    + (listState.category === 'all' ? '' : '&category_id=' + listState.category);
  const [res, categories] = await Promise.all([api.get(params), loadCategories()]);
  if (ctx.dead()) {
    return;
  }
  if (!res.ok) {
    clear(grid);
    showError(status, res.code, () => loadList(view, status, grid, pagerBox, filterBox, ctx), 'news.error', 'news.retry');
    return;
  }
  const items = Array.isArray(res.data) ? res.data : [];
  const pg = res.meta && res.meta.pagination ? res.meta.pagination : null;
  const totalPages = pg ? Math.max(1, Number(pg.total_pages) || 1) : 1;
  clear(filterBox);
  if (categories.length > 0) {
    categorySelect(filterBox, categories, listState.category, (v) => {
      listState.category = v;
      listState.page = 1;
      loadList(view, status, grid, pagerBox, filterBox, ctx);
    }, 'news.filter_category', 'news.filter_all');
  }
  clear(grid);
  if (items.length === 0) {
    showEmpty(status, 'news.empty');
    return;
  }
  setText(status, '');
  renderCards(grid, items, categories);
  clear(pagerBox);
  if (totalPages > 1) {
    pager(pagerBox, listState.page, totalPages, (p) => {
      listState.page = p;
      loadList(view, status, grid, pagerBox, filterBox, ctx);
    }, 'news.pagination_prev', 'news.pagination_next', 'news.page_indicator');
  }
}

export async function renderNewsList(view, match, ctx) {
  clear(view);
  const head = pageHead(view, 'news.list_title');
  const filterBox = document.createElement('div');
  head.appendChild(filterBox);
  const status = statusLine(head);
  const grid = cardGrid(head);
  const pagerBox = document.createElement('div');
  head.appendChild(pagerBox);
  await loadList(view, status, grid, pagerBox, filterBox, ctx);
}

export async function renderNewsDetail(view, match, ctx) {
  clear(view);
  const slug = decodeURIComponent(match[1] || '');
  const head = pageHead(view, 'news.list_title');
  backLink(head, '#/news', 'news.detail_back');
  const status = statusLine(head);
  setText(status, t('news.loading'));
  const res = await api.get('/news/' + encodeURIComponent(slug));
  if (ctx.dead()) {
    return;
  }
  if (!res.ok) {
    if (res.status === 404) {
      setText(status, '');
      const miss = document.createElement('p');
      miss.className = 'not-found-text';
      setText(miss, t('news.detail_not_found'));
      head.appendChild(miss);
      backLink(head, '#/news', 'news.detail_back');
      return;
    }
    showError(status, res.code, () => renderNewsDetail(view, match, ctx), 'news.error', 'news.retry');
    return;
  }
  const item = res.data || {};
  setText(status, '');
  const article = document.createElement('article');
  article.className = 'article';
  const title = document.createElement('h2');
  title.className = 'article-title';
  setText(title, item.title);
  article.appendChild(title);
  const categories = await loadCategories();
  if (ctx.dead()) {
    return;
  }
  article.appendChild(metaLine([categoryName(categories, item), fmtDate(item.published_at)]));
  const body = item.body != null ? String(item.body) : '';
  const paras = body.split(/\r?\n\r?\n|\r?\n/);
  let hasPara = false;
  for (const chunk of paras) {
    const text = chunk.trim();
    if (!text) {
      continue;
    }
    const p = document.createElement('p');
    setText(p, text);
    article.appendChild(p);
    hasPara = true;
  }
  if (!hasPara && item.excerpt) {
    const p = document.createElement('p');
    setText(p, item.excerpt);
    article.appendChild(p);
  }
  head.appendChild(article);
}
