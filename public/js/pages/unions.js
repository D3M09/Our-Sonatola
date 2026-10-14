/**
 * unions.js — union directory (#/unions).
 * Copy from locales only; backend Bengali data shown as-is.
 * (Unions carry name + code; no contact columns exist on the backend.)
 */

import { api, setText } from '../api.js?v=4C1';
import { t } from '../i18n.js?v=4C1';
import {
  clear, pickName, pageHead, statusLine, showSkeleton,
  showEmpty, showError, cardGrid, metaLine,
} from '../page.js?v=4C1';

function renderItems(grid, items) {
  clear(grid);
  for (const item of items) {
    const card = document.createElement('article');
    card.className = 'card';
    const name = document.createElement('h3');
    setText(name, pickName(item));
    card.appendChild(name);
    if (item.code) {
      card.appendChild(metaLine([String(item.code)]));
    }
    grid.appendChild(card);
  }
}

async function load(head, status, grid, ctx) {
  setText(status, t('unions.loading'));
  clear(grid);
  showSkeleton(grid, 3);
  const res = await api.get('/unions');
  if (ctx.dead()) {
    return;
  }
  if (!res.ok) {
    clear(grid);
    showError(status, res.code, () => load(head, status, grid, ctx), 'unions.error', 'state.retry');
    return;
  }
  const items = Array.isArray(res.data) ? res.data : [];
  clear(grid);
  if (items.length === 0) {
    showEmpty(status, 'unions.empty');
    return;
  }
  setText(status, '');
  renderItems(grid, items);
}

export async function renderUnionsList(view, match, ctx) {
  clear(view);
  const head = pageHead(view, 'unions.list_title');
  const status = statusLine(head);
  const grid = cardGrid(head);
  await load(head, status, grid, ctx);
}
