/**
 * services.js — services directory (#/services).
 * Copy from locales only; backend Bengali data shown as-is.
 */

import { api, setText } from '../api.js?v=4C1';
import { t } from '../i18n.js?v=4C1';
import {
  clear, pickName, pageHead, statusLine, showSkeleton,
  showEmpty, showError, cardGrid, metaLine,
} from '../page.js?v=4C1';

function contactParts(item) {
  const parts = [];
  if (item.office_name) {
    parts.push(String(item.office_name));
  }
  if (item.contact_name) {
    parts.push(String(item.contact_name));
  }
  if (item.contact_phone) {
    parts.push(String(item.contact_phone));
  }
  if (item.contact_email) {
    parts.push(String(item.contact_email));
  }
  return parts;
}

function renderItems(grid, items) {
  clear(grid);
  for (const item of items) {
    const card = document.createElement('article');
    card.className = 'card';
    const name = document.createElement('h3');
    setText(name, pickName(item));
    card.appendChild(name);
    if (item.description) {
      const desc = document.createElement('p');
      setText(desc, item.description);
      card.appendChild(desc);
    }
    const contact = contactParts(item);
    if (contact.length > 0) {
      card.appendChild(metaLine(contact));
    }
    grid.appendChild(card);
  }
}

async function load(head, status, grid, ctx) {
  setText(status, t('services.loading'));
  clear(grid);
  showSkeleton(grid, 3);
  const res = await api.get('/services?per_page=50');
  if (ctx.dead()) {
    return;
  }
  if (!res.ok) {
    clear(grid);
    showError(status, res.code, () => load(head, status, grid, ctx), 'services.error', 'state.retry');
    return;
  }
  const items = Array.isArray(res.data) ? res.data : [];
  clear(grid);
  if (items.length === 0) {
    showEmpty(status, 'services.empty');
    return;
  }
  setText(status, '');
  renderItems(grid, items);
}

export async function renderServicesList(view, match, ctx) {
  clear(view);
  const head = pageHead(view, 'services.list_title');
  const status = statusLine(head);
  const grid = cardGrid(head);
  await load(head, status, grid, ctx);
}
