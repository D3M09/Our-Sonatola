/**
 * debug.js — DEV-ONLY, remove before production.
 * On-page debug panel for mobile browsers without DevTools.
 * Visible only when location.search contains debug=1.
 * NEVER logs tokens, passwords, emails, or PII: callers pass only
 * booleans, paths, status codes, and route hashes.
 */

const BUILD = '4B1-debug1';
const MAX_LINES = 200;
const MAX_MSG = 500;

const buffer = [];

export function buildTag() {
  return BUILD;
}

export function isDebug() {
  try {
    return /[?&]debug=1(?:&|$)/.test(window.location.search);
  } catch (checkError) {
    return false;
  }
}

function now() {
  try {
    return new Date().toISOString().slice(11, 23);
  } catch (timeError) {
    return '?';
  }
}

function panel() {
  if (!isDebug()) {
    return null;
  }
  let el = document.getElementById('debug-panel');
  if (el) {
    return el;
  }
  el = document.createElement('div');
  el.id = 'debug-panel';
  const head = document.createElement('div');
  head.className = 'debug-head';
  const title = document.createElement('span');
  title.textContent = 'DEBUG ' + BUILD;
  head.appendChild(title);
  const clearBtn = document.createElement('button');
  clearBtn.type = 'button';
  clearBtn.textContent = 'Clear';
  clearBtn.addEventListener('click', () => {
    buffer.length = 0;
    const body = document.getElementById('debug-body');
    if (body) {
      body.replaceChildren();
    }
  });
  head.appendChild(clearBtn);
  const copyBtn = document.createElement('button');
  copyBtn.type = 'button';
  copyBtn.textContent = 'Copy';
  copyBtn.addEventListener('click', async () => {
    const text = buffer.map((l) => l.t + ' ' + l.k + ' ' + l.m).join('\n');
    try {
      await navigator.clipboard.writeText(text);
      copyBtn.textContent = 'Copied';
    } catch (copyError) {
      copyBtn.textContent = 'Copy failed';
    }
    setTimeout(() => {
      copyBtn.textContent = 'Copy';
    }, 1500);
  });
  head.appendChild(copyBtn);
  el.appendChild(head);
  const body = document.createElement('div');
  body.id = 'debug-body';
  el.appendChild(body);
  const host = document.body || document.documentElement;
  host.appendChild(el);
  return el;
}

function paint(line) {
  const el = panel();
  if (!el) {
    return;
  }
  const body = document.getElementById('debug-body');
  if (!body) {
    return;
  }
  const row = document.createElement('div');
  row.className = 'debug-line';
  row.textContent = line.t + ' ' + line.k + ' ' + line.m;
  body.appendChild(row);
  while (body.children.length > MAX_LINES) {
    body.removeChild(body.firstChild);
  }
  el.scrollTop = el.scrollHeight;
}

/** Safe trace: type + short message (already PII-free by contract). */
export function dbg(kind, msg) {
  const line = { t: now(), k: String(kind).slice(0, 12), m: String(msg).slice(0, MAX_MSG) };
  buffer.push(line);
  if (buffer.length > MAX_LINES) {
    buffer.shift();
  }
  try {
    paint(line);
  } catch (paintError) {
    // Panel must never break the app.
  }
}

function hookGlobal() {
  if (hookGlobal.done) {
    return;
  }
  hookGlobal.done = true;
  window.addEventListener('error', (ev) => {
    const m = ev && ev.message ? String(ev.message) : 'unknown error';
    dbg('onerror', m.slice(0, MAX_MSG));
  });
  window.addEventListener('unhandledrejection', (ev) => {
    let m = 'rejection';
    try {
      m = String((ev && ev.reason && (ev.reason.message || ev.reason)) || m);
    } catch (reasonError) {
      m = 'rejection';
    }
    dbg('unhandled', m.slice(0, MAX_MSG));
  });
  const origErr = console.error.bind(console);
  console.error = (...args) => {
    try {
      dbg('console.err', args.map(String).join(' ').slice(0, MAX_MSG));
    } catch (wrapError) {
      // ignore
    }
    origErr(...args);
  };
  const origWarn = console.warn.bind(console);
  console.warn = (...args) => {
    try {
      dbg('console.warn', args.map(String).join(' ').slice(0, MAX_MSG));
    } catch (wrapWarnError) {
      // ignore
    }
    origWarn(...args);
  };
}

hookGlobal.done = false;
hookGlobal();
dbg('debug', 'panel ready build=' + BUILD);
