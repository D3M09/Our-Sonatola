# OurSonatola — Secure Interactive Admin Password Plan (READ-ONLY)

**No files were edited and no database records were changed.** Only source/tests/docs were read and non-mutating environment probes were run (`command -v`, `php -m`, function-existence checks). `scripts/create_admin.php` was **not executed**.

---

## 1. Exact current behavior + references

`scripts/create_admin.php` (84 lines):

| Lines | Behavior |
|---|---|
| L9–10 | `require` `config/config.php` + `src/bootstrap.php` (loads `.env` read-only, starts session). |
| L14–17 | **CLI-only guard:** `php_sapi_name() !== 'cli'` → `http_response_code(403)` + `exit('CLI only.')`. |
| **L20** | **`getopt('', ['name:', 'email:', 'password:', 'phone::', 'force'])`** — password is a required CLI **argument**. |
| L21–24 | Extract `name`, `email` (lowercased), `pass`, `phone`. |
| L26–30 | Required-check: empty `name`/`email`/`pass` → usage to STDERR, `exit(1)`. |
| L31–34 | `filter_var(..., FILTER_VALIDATE_EMAIL)` → "Invalid email." `exit(1)`. |
| **L35–38** | **`strlen($pass) < 12`** → "Admin password must be at least 12 characters." `exit(1)`. |
| L40–44 | `DB::pdo()` in try/catch → "DB connect failed: …" `exit(1)`. |
| L46–52 | Duplicate-super-admin guard; uses `isset($opts['force'])`; `exit(1)` unless `--force`. |
| L54–58 | Duplicate-email check → "Email already exists." `exit(1)`. |
| L60–67 | Resolve Sonatola `upazila_id` (nullable). |
| L69–84 | `beginTransaction()`; **L72** INSERT `users` (`account_status='active'`); **L73 `password_hash($pass, PASSWORD_DEFAULT)`**; L74 `lastInsertId()`; **L75** hardcoded `super_admin` role id; **L76** INSERT `user_roles`; L78 `commit()`; L79 success echo; L80–83 rollback + `"Failed: " . $e->getMessage()`. |
| L7, L27 | Docblock + usage text still advertise `--password="..."`. |

**Confirmed exposure:** the password enters via `argv` (L20), so it is visible in **shell history** and **process listings** (`ps`, `/proc/<pid>/cmdline`). There is **no interactive path** in the current script.

**No test covers this script.** `grep create_admin tests/` → none. Test conventions (`tests/T.php`, `tests/run.php`): auto-discovered `tests/test_*.php`, `T::ok()` / `T::skip()`, pure/no-DB by default; static source-inspection tests exist (`test_security_static.php`, `test_dryrun_readonly.php`). `test_dryrun_readonly.php` invokes a script via `exec(escapeshellarg(...))`.

**Runtime target (README L3, L37):** PHP **8.1+** (tested 8.5). Required extensions (`src/Setup/Requirements.php` L20): `pdo_mysql, mbstring, openssl, json, fileinfo` — **`readline`/`posix`/`pcntl` are NOT requirements** and must not be depended on.

## 2. Recommended input mechanism + compatibility

**Use a native TTY facility: `stty -echo` around a blocking `fgets(STDIN)`**, gated by the core `stream_isatty(STDIN)`.

- `stream_isatty()` — **core function (PHP 7.2+)**, always present; do **not** use `posix_isatty()` (posix extension not required).
- **Do not use `readline()`** — it echoes typed characters by default and the extension isn't guaranteed on the target host (loaded in this dev container, but that is not a contract).
- Save/restore exact terminal state with `stty -g` → `stty "$saved"` (best practice), with `stty echo` as fallback in a `finally`.
- Probe results here (dev container): `stty` at `/usr/bin/stty`; `stream_isatty(STDIN)` returns `false` for piped/`/dev/null` stdin (so the fail-closed branch is reachable). Extensions `readline/posix/pcntl` are loaded **here only** — the plan must not rely on them.
- **No new dependencies**: only core PHP + the external POSIX `stty` already present on Linux/macOS/Termux.

## 3. Minimal proposed changes (not applied)

1. **Remove the argument.** Change L20 to `getopt('', ['name:', 'email:', 'phone::', 'force'])`; delete `$pass = ...` (L23) and drop the `$pass === ''` clause from L26.
2. **Update text**: L7 docblock and L27 usage message — remove `--password`.
3. **Add a small hidden-input helper** (kept in the same script; optionally extracted to `src/Setup/PasswordPrompt.php` for unit-testability — see section 4):
   - Guard: `if (!stream_isatty(STDIN))` → `fwrite(STDERR, "This command needs an interactive terminal for secure password entry; do not pass a password on the command line.\n"); exit(1);` (fail closed, **before** `DB::pdo()` at L40).
   - Guard: `function_exists('shell_exec')` (or `proc_open`); if neither, fail closed.
   - `$saved = shell_exec('stty -g 2>/dev/null'); shell_exec('stty -echo 2>/dev/null');`
   - Read with `fgets(STDIN)`; strip **only** trailing `\r`/`\n` (`rtrim($line, "\r\n")`) so intentional spaces are preserved; if `false` (EOF) → error.
   - `finally`: `shell_exec('stty ' . escapeshellarg((string) $saved) . ' 2>/dev/null')` (fallback `stty echo`); also `register_shutdown_function` to restore echo on fatal paths. Print a newline after reading.
4. **Confirm + validate**, keeping the >=12 rule:
   - Prompt twice ("Enter password", "Confirm password"); compare with `hash_equals()`.
   - Mismatch → `fwrite(STDERR, "Passwords do not match.\n"); exit(1);`.
   - `strlen($p) < 12` → existing "at least 12 characters" message, `exit(1)` (retain L35–38 semantics).
5. **Keep** `password_hash($p, PASSWORD_DEFAULT)` at L73, the transaction (L69–84), the super-admin guard (L46–52), `--force`, email validation, and duplicate checks.
6. **Never leak the password**: no `echo`/`fwrite` of the value; do not interpolate it into any exception/usage string; never store it in an env var or file.
7. **Update docs** that show `--password` (`README.md` L48, `docs/live-db-verification.md` L89/L91, `docs/deployment.md` L32, `docs/remediation.md` L163).

**Proposed shape (illustrative only — NOT written to disk):**

```php
$opts = getopt('', ['name:', 'email:', 'phone::', 'force']);   // no 'password:'
// ... name/email/phone extraction, required + email checks unchanged ...

if (!stream_isatty(STDIN)) {
    fwrite(STDERR, "Interactive terminal required for secure password entry.\n");
    exit(1);
}
$p1 = promptHidden('Admin password (min 12): ');
$p2 = promptHidden('Confirm password: ');
if (!hash_equals($p1, $p2)) { fwrite(STDERR, "Passwords do not match.\n"); exit(1); }
if (strlen($p1) < 12) { fwrite(STDERR, "Admin password must be at least 12 characters.\n"); exit(1); }
$pass = $p1; unset($p1, $p2);
// ... DB::pdo() guard, duplicates, transaction, password_hash($pass, PASSWORD_DEFAULT) unchanged ...
```

## 4. Test plan — no accounts, no DB writes

- **Static assertions** (new `tests/test_create_admin_input.php`, following `test_security_static.php`): read the source as a string and assert
  - `getopt` line does **not** contain `'password'`;
  - source contains `stream_isatty(STDIN)` and the fail-closed message;
  - source contains `stty -echo` and a restore call (`stty -g` / `stty echo`);
  - confirmation uses `hash_equals(` and the `strlen(...) < 12` check is retained;
  - `password_hash(..., PASSWORD_DEFAULT)` retained;
  - no `echo`/`fwrite` line prints `$pass`/`$p1`.
- **Fail-closed behavior test** (only safe because the TTY check is placed **before** `DB::pdo()`): invoke `php scripts/create_admin.php --name=x --email=x@example.com` with **piped stdin** (`< /dev/null`) and assert non-zero exit + the interactive-terminal message and **no** "Super admin created" line. If placement before DB cannot be guaranteed, `T::skip()` this case with reason `requires non-TTY stdin safety guarantee`.
- **Optional unit test** of an extracted `PasswordPrompt` helper with an injectable stream (`php://memory`, non-TTY) to assert it refuses rather than reads.
- Explicitly **never** run the script with a real TTY, and never with `--force`.

## 5. Portability risks (Android / Termux / KSWEB)

- **Linux / macOS**: `stty` + `stream_isatty` reliable.
- **Termux (Android)**: `stty` is available (coreutils/toybox), but stdin is a TTY only inside the Termux terminal — over `adb`/pipes it isn't, so the fail-closed branch triggers correctly.
- **KSWEB (Android, busybox)**: `stty -echo` usually works; `stty -g` save/restore may be limited by the busybox variant — keep the `stty echo` fallback and never assume success. If `stty`/`shell_exec` is unavailable or stdin is not a TTY → **fail closed**.
- **Windows (cmd/PowerShell)**: no `stty`. Plan is to **fail closed** with a clear message (no password argument accepted). Document running under WSL/Git Bash (which ship `stty`) if interactive entry is needed.
- **Shared hosting** with `shell_exec`/`proc_open` in `disable_functions` → fail closed.

## 6. Confirmation

**Confirmed: no files were edited, no source or docs modified, and no database records were changed.** No `INSERT/UPDATE/DELETE`, migrations, seeds, or cleanup ran; `scripts/create_admin.php` was not executed and no account was created. This is planning/reporting only.
