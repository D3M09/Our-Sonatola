# OurSonatola — Admin Setup Preflight (READ-ONLY)

**No account was created. No database was modified.** Only `SELECT`/`SHOW COLUMNS` reads were used. No `.env` contents, secrets, or password hashes were printed.

---

## 1. Database target (from configuration)

| Key | Value |
|---|---|
| `DB_HOST` | `127.0.0.1` |
| `DB_NAME` | `sonatola_test` |

The target is a **local test database** (`sonatola_test`), consistent with a test/demo environment. Only `DB_HOST` and `DB_NAME` are reported, as instructed.

## 2. `unions` / `upazilas` schema + read-only query results

**`unions` columns:** `id` (PK, int unsigned), `upazila_id` (FK→`upazilas`, NOT NULL), `name_en` (varchar100, NOT NULL), `name_bn` (varchar100, NULL), `code` (varchar50, UNIQUE, NULL), `is_active` (tinyint1, default 1), timestamps.

**`upazilas` columns:** `id` (PK), `name_en` (NOT NULL), `name_bn` (NULL), `district` (default Bogura), `division` (default Rajshahi), timestamps.

**Union rows (all) — count = 1:**

| id | upazila_id | name_en | name_bn | code | is_active |
|---|---|---|---|---|---|
| 1 | 1 | `TEST-UNION-E2E-DO-NOT-USE` | `পরীক্ষা` ("test") | `TEST-E2E` | 1 |

**Upazila rows (all) — count = 1:**

| id | name_en | name_bn | district | division |
|---|---|---|---|---|
| 1 | `Sonatola` | `সোনাতলা` | `Bogura` | `Rajshahi` |

## 3. Comparison against the human-verified Union identity

The **specific name/code of the human-verified genuine Union was not included in this request**, so an exact string match cannot be performed. Independently of that, the database contains **exactly one** union row and every field marks it as test data:

- name_en = `TEST-UNION-E2E-DO-NOT-USE`
- code = `TEST-E2E`
- name_bn = `পরীক্ষা` (Bengali for **"test"**)

**No genuine/official Union row exists.** ID 1 is **not** treated as the correct Union, and the test row is **not** treated as the genuine one.

## 4. STOP — verified Union does not exist

Per instructions, this is a **STOP** for any union-scoped account (`union_admin`, `service_officer`). **No union was created and no test data was deleted.**

Authorized process to add the official Union record (`database/import-official-data.md`):

1. Obtain the official Union list from the **Upazila Nirbahi Office / LGED / EC gazette**; record source + date.
2. Create `unions-official.csv` with `upazila_name_en,union_name_en,union_name_bn,code`.
3. Validate every row against the gazette with a **two-person check**; keep the signed source PDF in a private folder (never commit).
4. Resolve the parent upazila id (`SELECT id FROM upazilas WHERE name_en='Sonatola';` → `1`), then insert via the documented statement:
   ```sql
   INSERT INTO unions (upazila_id, name_en, name_bn, code, is_active)
   VALUES (<UPAZILA_ID>,'Name EN','নাম BN','UNION-CODE',1)
   ON DUPLICATE KEY UPDATE name_en=VALUES(name_en), name_bn=VALUES(name_bn), is_active=1;
   ```
   (or `LOAD DATA LOCAL INFILE` via a staging table with a review `SELECT` first).
5. Log the import in `audit_logs` (`action=data.import`, `entity=unions`) with the source reference.
6. Never import contacts from unofficial sources.

## 5. Active `super_admin` (SELECT only)

- Total users with `super_admin` role: **0**
- Active (`account_status='active'`) users with `super_admin` role: **0**
- Total users in DB: **4** (3 citizens, 1 union_admin; no password hashes or PII printed)

## 6. `scripts/create_admin.php` — validation & password-exposure risk (not run)

- **CLI-only** guard: `php_sapi_name() !== 'cli'` → 403 (L14–17).
- **Required:** `--name`, `--email`, `--password` (L25–30).
- **Email** validated with `FILTER_VALIDATE_EMAIL` (L31–34).
- **Password** minimum **12 characters** (L35–38); stored via `password_hash(..., PASSWORD_DEFAULT)` — never plaintext (L73).
- **Guard:** refuses if any `super_admin` exists unless `--force` (L46–52). Current count = 0, so it would run without `--force`.
- Uses a transaction; inserts `users` (with Sonatola `upazila_id` resolved at L60–67) + `user_roles` for hardcoded `super_admin`.
- **Password-exposure risk (confirmed):** the password is passed as a **command-line argument** (`--password:` in `getopt`, L20). CLI args are visible in **shell history** and **process listings** (`ps`, `/proc/<pid>/cmdline`). There is **no interactive prompt** in the script. Mitigate via a private shell / history suppression and rotating the password; a genuine fix requires a script change (out of scope).

## 7. Preflight verdict — safe to proceed?

- **`super_admin`:** safe to create on this **local test DB** (`sonatola_test`, host `127.0.0.1`) — guard passes (0 existing super_admins), demo-only.
- **`upazila_admin`:** global role, no `union_id` needed — technically createable, but it is a real-privilege account; confirm this test DB is the intended target before creating.
- **`union_admin` / `service_officer`:** **BLOCKED.** The genuine Union does not exist; only `TEST-UNION-E2E-DO-NOT-USE` is present. **Do not** target ID 1. Add official directory data first (section 4).
- Overall: the database is a **local test/demo** target, so **demo-only** accounts are acceptable in principle — except the union-scoped roles, which must wait for a genuine Union.

**Confirmed: no account was created, no database was modified, no test data deleted, and no secrets or password hashes were output.** Stopping here for explicit approval.
