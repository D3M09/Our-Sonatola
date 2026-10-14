# Audit #9 — Task 8: Admin UI Browser Verification (authenticated)

- **Date:** 2026-10-14 (session)
- **Scope:** Real-browser verification of the Task 7 Admin **Users** (`#/admin/users`) and **Audit Log**
  (`#/admin/audit`) UI: navigation, permissions, 403 handling, filters, pagination, responsive mobile
  layout, status change, role assignment, CSRF handling, audit read-only behaviour, console/network
  errors, rendering and XSS-safe output.
- **Environment:** `APP_ENV=development`, DB `sonatola_test` (`127.0.0.1:3306`, MySQL), app served via
  PHP built-in server (`php -S`) on `127.0.0.1:8099` with a static-file router fallback.
- **Result:** ✅ **No new defects confirmed.** All exercised scenarios behaved as designed. Fits the
  “verify” objective — no source changes were required.

---

## 1. Tooling & method

| Item | Detail |
|---|---|
| Browser | Cached Chromium/Puppeteer binary `/root/.cache/ms-playwright/chromium-1243/chrome-linux-arm64/chrome` (v153), `--headless=new` |
| Driver | Zero-dependency Node **CDP** harness (`node --experimental-websocket`) using `Runtime`, `Log`, `Network`, `Page`, `Emulation` domains |
| Auth | Real login form (`form.auth-form` email + password) driven through the SPA, exactly as a user would |
| Test account | `browser-test-admin@example.com` (user id 5, role `super_admin`) — created **only with explicit user approval** during this session |
| Password | Generated, never printed, mode-600 file at `/tmp/bw_cred`; rotated once when a PTY echo race exposed the first value |

The harness navigated the app, drove the real UI controls, and captured console `Runtime.exceptionThrown`
/ `Log.entryAdded` errors plus every `Network.responseReceived` with status ≥ 400.

## 2. Scenario results

### Authentication & navigation
| Check | Result |
|---|---|
| Login form renders | ✅ |
| Login → role-aware redirect to `#/admin…` | ✅ |
| `GET /api/v1/me` after login | ✅ HTTP 200, `roles=["super_admin"]` |
| Admin sidebar renders on `#/admin/users` | ✅ |
| Sidebar exposes `#/admin/users` and `#/admin/audit` | ✅ both present |
| `aria-current="page"` tracks the active section | ✅ `#/admin/users`, then `#/admin/audit` |

### Users page (`#/admin/users`)
| Check | Result |
|---|---|
| Rows rendered from API | ✅ 5 rows (5 users in DB) |
| Columns | ✅ name / email / phone / union / status / actions (Bangla-first, `_TODO` i18n placeholders intact) |
| Status controls rendered | ✅ inline `<select>` per row |
| Status change via UI (safe no-op, same value) | ✅ success banner `অবস্থা হালনাগাদ হয়েছে_TODO` |
| Role assignment via UI (safe no-op, already-held `super_admin`) | ✅ success banner `ভূমিকা ন্যস্ত হয়েছে_TODO` |
| Status filter applies + re-renders | ✅ status line `ব্যবহারকারী পরিচালনা_TODO (5)` |
| Pagination | ⚠️ not exercisable — only 5 users, below page size, so no `.pager` rendered (coverage gap, not a defect) |

### Permission / CSRF / validation (negative + positive)
| Case | Expected | Observed |
|---|---|---|
| `PUT …/status` **without** CSRF token | 403 | ✅ 403 `forbidden` — “Invalid CSRF token.” |
| `PUT …/status` invalid `account_status`, valid CSRF | 422 | ✅ 422 `validation_failed` — “Invalid status.” |
| `POST …/roles` unknown role slug, valid CSRF | 422 | ✅ 422 `validation_failed` — “Unknown role.” |
| Super Admin bypasses permission lookup; every admin route requires auth | — | ✅ enforced by `RbacMiddleware` + `AuthMiddleware` |

### Audit log page (`#/admin/audit`)
| Check | Result |
|---|---|
| Audit table renders | ✅ 25 rows (default page) |
| Active nav = `#/admin/audit` | ✅ |
| Columns | ✅ time / actor id / action / entity / IP / details |
| Read-only — **zero** write controls (buttons) in the audit box | ✅ 0 buttons |
| Explanatory note present | ✅ |

### Responsive (mobile)
| Viewport | Result |
|---|---|
| 390 × 844 (`Emulation.setDeviceMetricsOverride`, mobile) | ✅ sidebar + main render; `scrollWidth == innerWidth == 390` → **no horizontal overflow**; no layout break |

## 3. Console & network findings

- `pageErrorCount = 0` — no uncaught JS exceptions anywhere.
- `consoleErrorCount = 4`, all accounted for:
  - 3 × deliberate negative-test `403`s (the CSRF/validation probes above), and
  - 1 × `GET /favicon.ico → 404` (see 4.1).
- `failedRequestsAfterNormalUse` = **only** the favicon 404. During ordinary UI flows (login, users load,
  filter, status submit, role submit, audit load) there were **no** failed API requests.

## 4. Findings

### 4.1 Minor pre-existing observation (not a defect of Task 7; not fixed)
The SPA shell serves no favicon, so Chromium auto-requests `/favicon.ico` and logs a 404 in every page’s
console. It does not affect the admin UI, is unrelated to Task 7, and fixing it would touch the public
shell/route layer, so it was left as a report-only observation to keep this change set scoped.

### 4.2 Harness artefacts (not defects)
The initial render snapshot reported `hasSaveBtn=false` / `hasRoleBtn=false` because those probes used
English-only regexes (`/save/`, `/assign/`) against Bangla-first labels (`সংরক্ষণ`, `ন্যস্ত`). The control
probes that used the correct bilingual regexes located and successfully operated the buttons — confirming
the controls exist. No product change was warranted.

## 5. Fixes

**None required.** No confirmed defect in the Task 7 admin UI was found, so no source files were changed
in this task.

## 6. Data-safety note (disclosed)

To disambiguate whether “invalid input” returns a validation error versus a CSRF error, one extra
authenticated probe set a **non-self** test row (`users.id=1`, a pre-existing test account) to
`suspended`, which also appended one `audit_logs` row. This was an unintended write against the local
**test** database. It was **fully reverted in one transaction**:
- `users.id=1` → `account_status='active'` (its pre-probe value, confirmed against the audit trail), and
- the spurious `audit_logs` row (id 65, `user.status`, `{"to":"suspended"}`) removed.

Post-restore verification: `users#1` back to `active`; `audit_logs` back to its original 64 rows;
`users#1` audit count back to 6. No production data, schema, or API contract was touched.

The approved test account (user id 5, `super_admin`) remains in the local **test** DB for reproducibility;
it can be removed on request.

## 7. Automated checks

| Check | Command (from repo root) | Result |
|---|---|---|
| Non-destructive regression suite | all `tests/test_*.php` except the two `_live` suites | ✅ **260 passed, 0 failed** (exit 0) |
| Installer live suite | `tests/test_setup_live.php` | ⏭️ **not run** — creates/drops disposable DBs (excluded per “no destructive tests”) |
| SQLi/XSS live suite | `tests/test_sqlandxss_live.php` | ⏭️ **not run** — same reason |
| i18n `_TODO` guard | `bash scripts/check-todo.sh` | ⚠️ exit 1 (340 `_TODO` markers present **by design**; human localization review pending — must not be removed) |

## 8. Git status

No files were modified by this task’s verification work. The working tree still contains only the
**pre-existing** Task 7 changes (preserved) plus the untracked `freebuff-audit/` records (1–9). No
commit or push was performed. See the session transcript for the exact `git status` output at the time of
writing.

## 9. Remaining limitations

- **403/guard redirect for a lower-privilege role** was not exercised in-browser: only a `super_admin`
  credential was approved. API-level 403 behaviour (CSRF and permission checks) **was** verified.
- **Pagination UI** could not be exercised (dataset smaller than one page).
- **Live XSS injection into the admin UI** was not attempted (would require creating content). Safety is
  established by construction — all admin rendering uses `createElement`/`textContent`/`setText`
  (never `innerHTML`), the harness recorded **0** uncaught page errors, and stored-payload round-trip
  fidelity is already covered by `tests/test_sqlandxss.php`.
- The favicon 404 noted in 4.1 remains in the console (cosmetic, pre-existing).

## 10. Stop-condition notes

- Browser tooling **was** available and used; the application **was** actually exercised in a real browser.
- Credentials for a lower-privilege role were not available/approved → that portion was left untested
  rather than faked.
- No destructive test, schema change, API-contract change, or `_TODO` removal was performed.
