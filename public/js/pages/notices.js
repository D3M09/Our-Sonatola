/**
 * notices.js — notices list (#/notices). No detail view: the backend
 * exposes no GET /notices/{id}, so cards are not links.
 * Copy from locales only; backend Bengali data shown as-is.
 */

import { api, setText } from '../api.js?v=4C1';
import { t } from '../i18n.js?v=4C1';
import {
  clear, fmtDate, pageHead, statusLine, showSkeleton,
  showEmpty, showError, pager,
} from '../page.js?v=4C1';

const PER_PAGE = 10;
let page = 1;

function renderItems(list, items) {
  clear(list);
  for (const item of items) {
    const li = document.createElement('li');
    li.className = 'notice-item';
    const title = document.createElement('span');
    title.className = 'notice-title';
    setText(title, item.title);
    li.appendChild(title);
    const when = fmtDate(item.published_at);
    if (when) {
      const date = document.createElement('time');
      date.className = 'meta';
      setText(date, when);
      li.appendChild(date);
    }
    list.appendChild(li);
  }
}

async function load(view, status, list, pagerBox, ctx) {
  setText(status, t('notices.loading'));
  clear(list);
  clear(pagerBox);
  showSkeleton(list, 3);
  const res = await api.get('/notices?page=' + page + '&per_page=' + PER_PAGE);
  if (ctx.dead()) {
    return;
  }
  if (!res.ok) {
    clear(list);
    showError(status, res.code, () => load(view, status, list, pagerBox, ctx), 'notices.error', 'state.retry');
    return;
  }
  const items = Array.isArray(res.data) ? res.data : [];
  const pg = res.meta && res.meta.pagination ? res.meta.pagination : null;
  const totalPages = pg ? Math.max(1, Number(pg.total_pages) || 1) : 1;
  clear(list);
  if (items.length === 0) {
    showEmpty(status, 'notices.empty');
    return;
  }
  setText(status, '');
  renderItems(list, items);
  clear(pagerBox);
  if (totalPages > 1) {
    pager(pagerBox, page, totalPages, (p) => {
      page = p;
      load(view, status, list, pagerBox, ctx);
    }, 'news.pagination_prev', 'news.pagination_next', 'news.page_indicator');
  }
}

export async function renderNoticesList(view, match, ctx) {
  clear(view);
  const head = pageHead(view, 'notices.list_title');
  const status = statusLine(head);
  const list = document.createElement('ul');
  list.className = 'notice-list';
  head.appendChild(list);
  const pagerBox = document.createElement('div');
  head.appendChild(pagerBox);
  await load(view, status, list, pagerBox, ctx);
}
