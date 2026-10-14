# OurSonatola — Admin UI Browser Verification (Task 8) — PARTIAL / STOPPED FOR CREDENTIALS

**Scope:** browser-verify the Task 7 admin screens (`#/admin/users`, `#/admin/audit`).
**Outcome:** Credential-free browser checks passed; the **authenticated** verification
is **BLOCKED** (no admin credentials/permissions) and was stopped for clarification,
per the stop condition. **No code was changed and no account was created.**

---

## 1. Browser tooling available (verified)

- Headless **Chromium 153.0.8010.12** present at
  `/root/.cache/ms-playwright/chromium-1243/chrome-linux-arm64/chrome` — runs headless
  (`--headless=new --dump-dom` returned rendered DOM).
- Bonus cached binaries: puppeteer `chrome/linux_arm-148.0.7778.97`, playwright
  `chromium_headless_shell-1243`.
- **No** `playwright`/`puppeteer` **npm package** installed (no `package.json`,
  no `node_modules`, no global libs) and Node 20 has no global `WebSocket`, so scripted
  interaction (clicks/typing/CDP) is unavailable without installing a driver.
  Available method used: headless `--dump-dom` + `--enable-logging=stderr`.

## 2. Credential / permission blocker (STOP)

Live DB `sonatola_test` user inventory (read-only): **3 citizens + 1 union_admin;
0 super_admin / 0 upazila_admin**. No account passwords are known.

| Screen | Needs | Available? |
|---|---|---|
| `#/admin/users` list | `user.view` | union_admin has it, but **password unknown** |
| status change | `user.manage` | only super_admin/upazila_admin — **none exist** |
| role assignment | `role.assign` | **only super_admin** — none exists |
| `#/admin/audit` | `audit.view` | only super_admin/upazila_admin — **none exists** |

Creating accounts is forbidden by the task, and no test credentials were supplied.
→ **Authenticated scenarios cannot be exercised. Stopped for credentials.**

## 3. Credential-free browser checks actually run (PASS)

Served locally with `php -S 127.0.0.1:8099 -t public` via a temporary `/tmp` router
(no repo files added), then driven with headless Chromium.

| Check | Command/observable | Result |
|---|---|---|
| API health | `GET /api/v1/health` | **200** |
| Admin users unauth | `GET /api/v1/admin/users` | **401** (enforced) |
| Admin audit unauth | `GET /api/v1/admin/audit-logs` | **401** (enforced) |
| Locale JSON served | `GET /js/locales/en.json` | **200** |
| SPA boots + i18n runs | home DOM contains `সোনাতলা উপজেলা` | **present (1)** |
| Console output on home | `--enable-logging=stderr` grep | **0 console lines / no errors** |
| Unauth `#/admin/users` | DOM | admin shell **absent** (`admin-sidebar`=0), login password field **present** |

Interpretation: the guard correctly blocks unauthenticated access to the admin route
and redirects to login; the public shell and i18n boot with no console errors.

## 4. Scenarios NOT tested (blocked / untested)

- Authenticated `#/admin/users` list rendering, filters (status/q), pagination.
- Status change (`PUT .../status`) + CSRF round-trip in a real browser.
- Role assignment (`POST .../roles`) incl. super_admin-only option behaviour.
- `#/admin/audit` rendering, pagination, read-only behaviour in a browser.
- 403 handling for a logged-in user lacking `user.manage`/`audit.view`.
- Responsive mobile layouts (viewport emulation).
- XSS handling with live data (rendering path is `textContent`-only by source, and the
  static test asserts no `.innerHTML`/`insertAdjacentHTML`, but not exercised with data).

## 5. Tests

- No new automated tests were added: **no confirmed defect** was found in the portions
  tested (a Task 7 false-positive assertion was already corrected then).
- Full non-destructive suite: **260 passed, 0 failed, 0 skipped** (exit 0).
- Skipped (destructive, not approved): `tests/test_setup_live.php`,
  `tests/test_sqlandxss_live.php`.

## 6. Git status

- No repo files changed by this task. 1 commit (`a3d11ae`); nothing committed/pushed.
- Working tree unchanged from Task 7 (untracked `public/js/**`, `tests/test_admin_ui.php`,
  `freebuff-audit/**`, etc.).

## 7. Decisions requested

1. Provide a **test admin login** (preferred: create a super_admin via
   `scripts/create_admin.php` interactively, or supply an existing account's
   email + password) so the authenticated scenarios can be verified; OR
2. Approve installing a driver (`npm i -D puppeteer-core`, using the cached browser) for
   scripted interaction + responsive/viewport checks; OR
3. Accept the credential-free verification as the limit for this task.

No admin account was created and no production data was modified.
