# OurSonatola — Interactive Admin Password Implementation (Task 4)

Implements the plan from `3-secure-interactive-password-plan.MD`: the admin
bootstrap no longer accepts a password on the command line; it reads the
password through a hidden, TTY-gated interactive prompt.

**No account was created. No database records were changed** (verified: user
count 4 before and after all runs).

---

## 1. Files changed

| File | Status | Summary |
|---|---|---|
| `scripts/create_admin.php` | **Modified** | Removed the `--password` CLI option; added `prompt_hidden()` (echo-off via `stty`), confirmation, argv rejection, and fail-closed behavior. All existing safeguards preserved. |
| `tests/test_create_admin_input.php` | **Added** | Static, no-DB assertions that the script never takes/prints the password from argv and uses a hidden TTY-gated prompt. |

## 2. Exact behavior changes in `scripts/create_admin.php`

- **Removed the password argument.** `getopt('', ['name:', 'email:', 'phone::', 'force'])` — no `password:`. Usage/docblock updated (no `--password`).
- **`prompt_hidden(string $label): string` helper:**
  - Fail-closed if `!stream_isatty(STDIN)` → clear STDERR message, `exit(1)` (this check runs **before** `DB::pdo()`, so no DB contact on a non-TTY).
  - Fail-closed if `shell_exec` is unavailable or `stty -g` returns nothing.
  - Saves exact terminal state (`stty -g`), disables echo (`stty -echo`), reads with `fgets(STDIN)`, restores state in a `finally`-style closure **and** via `register_shutdown_function`.
  - Strips only the trailing `\r`/`\n` (intentional spaces preserved); prints a newline after input.
  - Never prints, logs, or returns the value anywhere except the return.
- **Confirmation + length rule:** prompts twice, compares with `hash_equals()` (mismatch → exit 1), then `strlen($pass) < 12` → exit 1. `password_hash($pass, PASSWORD_DEFAULT)` unchanged.
- **Defense-in-depth argv guard:** if `--password` / `--password=...` appears in `argv`, the script refuses with a clear message instead of silently ignoring it.
- **Preserved:** CLI-only guard, email validation, duplicate-super-admin guard (`--force`), duplicate-email check, Sonatola `upazila_id` lookup, transaction + rollback, and the `super_admin` role insert.
- **Plaintext lifetime:** `$pass = null;` immediately after the hash is computed.

## 3. Verification (all green)

| Check | Command | Result |
|---|---|---|
| Syntax | `php -l scripts/create_admin.php` / `php -l tests/test_create_admin_input.php` | No syntax errors |
| New static test | run via `tests/T.php` harness | **13 passed, 0 failed** |
| Fail-closed (non-TTY) | `php scripts/create_admin.php --name=… --email=… < /dev/null` | exit 1, STDERR "requires an interactive terminal…"; no account |
| argv rejection | `php scripts/create_admin.php … --password=whatever123 < /dev/null` | exit 1, STDERR "Do not pass the password on the command line…" |
| No DB write | `SELECT COUNT(*) FROM users` before/after all runs | `4` → `4` (unchanged) |
| Regression subset | all `tests/test_*.php` except the two DB-dropping live tests | **212 passed, 0 failed, 0 skipped** |

**Deliberately skipped** (destructive — create/drop databases): `tests/test_setup_live.php`, `tests/test_sqlandxss_live.php`.

## 4. What was NOT done

- `scripts/create_admin.php` was **not** run against a real TTY, and no account was created.
- The destructive live tests were not run.
- No `INSERT/UPDATE/DELETE`, migrations, seeds, or cleanup scripts were executed.

## 5. Remaining limitations / notes

- Hidden input depends on the external `stty` utility (POSIX). Where `stty` is absent (e.g. Windows `cmd`/PowerShell) the command **fails closed** with a clear message; run under WSL/Git Bash or a POSIX terminal.
- Ctrl+C during input may bypass the PHP shutdown handler; the shell usually restores echo, but `stty echo` can be run manually if a terminal is left in no-echo state.
- The interactive path itself cannot be unit-tested headlessly; it is covered by static assertions plus the non-TTY fail-closed behavioral check.
- Docs still show `--password` examples (`README.md` L48, `docs/live-db-verification.md` L89/L91, `docs/deployment.md` L32, `docs/remediation.md` L163) — updated in a follow-up, not in this task.
