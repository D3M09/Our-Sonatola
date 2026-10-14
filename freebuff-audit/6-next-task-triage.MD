# OurSonatola — Next-Task Triage (READ-ONLY) — STOPPED FOR CLARIFICATION

**No code was changed. No database was written. No commit was made.**
This task stopped for clarification per the stop condition: multiple materially
different candidate tasks exist and the top candidate needs decisions from the
owner.

---

## 1. Repository state snapshot

- **Stack:** plain PHP 8.1+ (tested on 8.5 CLI), MySQL/MariaDB, PDO, no framework.
- **Backend:** complete per README progress checklist (Phases 1–8 all `[x]`).
- **Frontend exists** (README line 86 is stale): `public/js/pages/*` (home, news,
  notices, services, unions, auth, complaints) and `public/js/admin/*`
  (`shell`, `guard`, `queue`, `detail`, `news`, `notices`, `roles`).
- **Local DB:** `sonatola_test` @ `127.0.0.1`, 11 migrations applied, 23 tables,
  4 users (0 super_admin). Read-only inspection only.
- **Setup wizard:** `public/setup.php` present; `storage/setup-progress.json` exists.

## 2. Git status (summary)

- **1 commit:** `a3d11ae backend basic V1`.
- **Pre-existing deletions:** 172 `.opencode/skills/**` files (`D`) — not from this work.
- **Modified (tracked):** `README.md`, `docs/deployment.md`,
  `docs/live-db-verification.md`, `docs/remediation.md`, `scripts/create_admin.php`
  (the interactive-password change), and `src/routes.php` (pre-existing SPA-shell change).
- **Untracked:** `public/js/**`, `public/css/**`, `public/img/**`,
  `scripts/check-todo.sh`, `tests/test_create_admin_input.php`,
  `access_log`, `public/access_log`, `storage/setup-progress.json`, `freebuff-audit/`.
- Nothing committed by this or prior assistant tasks.

## 3. Roadmap / task sources inspected

- `README.md` — progress checklist (all phases `[x]`) + **Limitations / remaining**.
- `Prompt/01. First-Step [Done]/*` — backend build + audit + live-DB prep (done).
- `Prompt/02. SetupFile [Done]/01. todo.MD` — setup.php (done; file present).
- `Prompt/sqlandxss/todo.MD` — SQLi/XSS audit/fix. Folder is **not** renamed `[Done]`,
  but `tests/test_sqlandxss.php` + `tests/test_sqlandxss_live.php` and
  `docs/remediation.md` exist and pass → appears already implemented.
- `scripts/check-todo.sh` — read-only i18n gate.

## 4. Test status (actual, this run)

- Non-destructive suite: **227 passed, 0 failed, 0 skipped** (exit 0).
- Skipped (destructive, not approved): `tests/test_setup_live.php`,
  `tests/test_sqlandxss_live.php`.
- `bash scripts/check-todo.sh` → **exit 1**: `public/js/locales/en.json` and
  `bn.json` each contain **134 `_TODO` markers** (268 total). Script header says
  "production deploy BLOCKED".

## 5. Candidate next tasks (highest priority first)

### A. Clear the 268 `_TODO` locale placeholders (i18n finalization) — **top candidate**
- **Evidence:** `scripts/check-todo.sh` is the only failing automated gate; it
  exists specifically as a production-deploy blocker. 133 keys are `admin.*`
  (queue/detail/etc.), plus `complaint.*`, `auth.*`, `status.*`, `priority.*`.
- **Ambiguity / decisions needed:** `en.json` L2 states the keys are
  "pending **human** review". Resolving means either (a) you approve the English
  wording and provide/review the **Bengali** translations, or (b) someone strips
  the `_TODO` suffix — which would falsely assert review. Bengali wording cannot
  be decided autonomously.

### B. Admin "Users" and "Audit Log" UI (backend APIs already exist)
- **Evidence:** `public/js/admin/shell.js` SECTIONS contains
  `{ key:'users', enabled:false }` and `{ key:'audit', enabled:false }`; there is
  **no** `public/js/admin/users.js`. Backend has `GET /api/v1/admin/users`,
  `PUT /admin/users/{id}/status`, `POST /admin/users/{id}/roles`,
  `GET /admin/audit-logs`.
- **Ambiguity:** screen scope/UX, which actions to expose (status change, role
  assign — super_admin-only), and whether audit logging is in scope for v1.

### C. Authenticated file-serving endpoint (`GET /files/{id}`)
- **Evidence:** README limitations list it as pending; `UploadService` writes
  `media_files` metadata but nothing serves files.
- **Ambiguity:** `docs/api-v1.md` explicitly says uploads are deferred and
  "do not add public file routes without authz", and a test asserts
  `no public file-serving route`. Implementing changes the API contract + tests
  and needs an authz/public-vs-private model decision.

### D. Push notifications (FCM)
- **Evidence:** notifications/device-token tables exist; delivery not implemented.
- **Ambiguity:** requires an external service choice + credentials — blocked on you.

### E. Applications public endpoints
- **Evidence:** table + `applications.online_enabled=0` exist; no endpoints.
- **Ambiguity:** intentionally disabled by design — needs a product decision to enable.

## 6. Why this stopped

- Several **materially different** tasks are open (A–E) with no single authoritative
  "next" pointer in the repo.
- The strongest signal (failing `check-todo.sh` gate → A) requires **human review of
  English wording and Bengali translation**, which cannot be decided autonomously and
  must not be faked by stripping `_TODO`.
- Options B/C/D/E each have clear backend hooks but require scope/contract/credential
  decisions from the owner.

## 7. Safety

No files were changed by this task; no destructive DB operations, no account
creation, no `--force`, and no commit/push. Only read-only inspection and the
non-destructive test suite were run.
