# OurSonatola — Admin Users + Audit Log UI (Task 7)

Implements the "Comprehensive Admin Panel Completion" milestone: the missing
**Admin Users** screen and **read-only Audit Log viewer**, wired into the admin
shell using existing API contracts. No backend/API/schema changes.

## 1. Scope decision

The prior triage (`6-next-task-triage.MD`) listed several candidates; the owner
selected the admin-panel completion. The authorization model was **not**
ambiguous — it is fixed by the existing backend:

| Action | Endpoint | Permission |
|---|---|---|
| List users | `GET /api/v1/admin/users` | `user.view` (union-scoped server-side) |
| Change status | `PUT /api/v1/admin/users/{id}/status` | `user.manage` (+ CSRF) |
| Assign role | `POST /api/v1/admin/users/{id}/roles` | `role.assign` (+ CSRF); `super_admin` grant is super-admin-only |
| Role list | `GET /api/v1/admin/roles` | `role.view` |
| Audit log | `GET /api/v1/admin/audit-logs` | `audit.view` |

No frozen contract or schema was touched.

## 2. Files changed

| File | Status | Purpose |
|---|---|---|
| `public/js/admin/users.js` | Added | Users list/filter/status/role-assign |
| `public/js/admin/audit.js` | Added | Read-only paginated audit viewer |
| `public/js/admin/shell.js` | Modified | Enable + wire `users`/`audit` nav; route-aware gate map |
| `public/js/app.js` | Modified | Routes `#/admin/users`, `#/admin/audit` |
| `public/js/locales/en.json` | Modified | 36 new keys, all `_TODO` suffixed |
| `public/js/locales/bn.json` | Modified | Same 36 keys (Bengali draft), all `_TODO` suffixed |
| `tests/test_admin_ui.php` | Added | 33 static/contract assertions |
| `README.md` | Modified | Admin UI + i18n notes; migration range fixed; stale bullet updated |

## 3. Security decisions

- **Permission gating (UX) mirrors backend (enforcement).** Status controls render
  only with `user.manage`; role assignment only with `role.assign`; role list is
  fetched only with `role.view`; `super_admin` is excluded from the role picker for
  non-super-admins. Missing `user.view` / `audit.view` shows an honest 403 message
  with **no retry button** (retry cannot grant a permission).
- **CSRF**: writes go through `api.js`, which attaches `X-CSRF-Token` on
  PUT/POST/DELETE for session clients.
- **XSS**: all output via `setText`/`textContent`; **no `.innerHTML`** or
  `insertAdjacentHTML`. The audit `metadata_json` is rendered as truncated text.
- **Secrets/PII**: audit view shows only what the endpoint already returns to
  `audit.view` holders; no tokens/hashes are requested or displayed.
- **Read-only viewer**: `audit.js` performs no writes.
- **i18n**: new keys keep the `_TODO` suffix — no translation is claimed reviewed,
  and no existing `_TODO` marker was removed.

## 4. Tests actually run

| Check | Result |
|---|---|
| `php -l tests/test_admin_ui.php` | No syntax errors |
| `node --check` (ESM, temp `.mjs`) on users/audit/shell/app.js | All OK |
| JSON parse of `en.json` / `bn.json` | Both OK |
| New test `tests/test_admin_ui.php` | **33 passed, 0 failed, 0 skipped** |
| Full non-destructive suite | **260 passed, 0 failed, 0 skipped** |
| `bash scripts/check-todo.sh` | **exit 1** — 170 `_TODO` per locale (340 total), expected: 36 new agent keys added, none reviewed |

Note: one initial assertion (`no innerHTML`) was a false positive (the word appears
only in safety comments); it was corrected to assert no `.innerHTML` /
`insertAdjacentHTML` **usage** — the security requirement is still enforced.

Skipped (destructive, not approved): `tests/test_setup_live.php`,
`tests/test_sqlandxss_live.php`.

## 5. Git status

- 1 commit (`a3d11ae`); nothing committed/pushed by this task.
- Modified: `README.md`, `public/js/admin/shell.js`, `public/js/app.js`,
  `public/js/locales/en.json`, `public/js/locales/bn.json`
  (+ pre-existing `scripts/create_admin.php`, `src/routes.php`).
- Added (untracked): `public/js/admin/users.js`, `public/js/admin/audit.js`,
  `tests/test_admin_ui.php`, `freebuff-audit/7-admin-users-audit-ui.md`.

## 6. Limitations / unresolved

- **Not browser-verified here.** Only static/contract tests + JS syntax checks were
  run; the screens were not exercised in a live browser against the running app.
- `check-todo.sh` still fails by design (human i18n review pending) — see Task 6.
- Audit view has no filters (backend exposes only `page`/`per_page`).
- Role picker shows role `name` (English) from the API; no localized role names.
- No `union` filter on the users screen (backend `listUsers` supports `status`/`q`
  and forces union scope; adding a union picker was out of scope).
