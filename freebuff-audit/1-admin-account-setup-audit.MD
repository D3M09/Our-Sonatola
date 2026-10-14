# OurSonatola — Admin Account Setup Audit (READ-ONLY)

**Goal:** prepare safe, exact CLI/API instructions for creating four accounts:
`super_admin`, `upazila_admin`, `union_admin`, `service_officer`.

**Result: No account was created. No database was modified.** Only `SELECT` reads were used (see §2).

---

## 1. Verified files & relevant line ranges

| File | Lines | What it proves |
|---|---|---|
| `scripts/create_admin.php` | 20, 34, 40, 44–51, 72–73, 75–76 | CLI bootstrap. `getopt` accepts only `--name --email --password --phone --force` (L20). Password `strlen < 12` rejected (L34). Guard refuses if a `super_admin` exists (L44–51). **Role is hardcoded to `super_admin`** (L75) — there is no `--role` option. Inserts `users` with `account_status='active'` + Sonatola `upazila_id` (L72–73). |
| `src/routes.php` | 23, 50–53 | `POST /api/v1/register` (public). `POST /api/v1/admin/users/{id}/roles` (L52), `PUT …/status` (L51), `GET …/users` (L50). No route sets a user's `union_id`. |
| `src/Controllers/AuthController.php` | 16–21, 23–34 | register → `AuthService::register`; login returns `{user, token, csrf}`. |
| `src/Services/AuthService.php` | 17–48 | register validation (L20–24, **password min 8**), `union_id` cast to int with **no existence/active check** (L33), insert (L34–41) with `account_status='active'` (L40), always assigns `citizen` (L42–44). |
| `src/Controllers/AdminUserController.php` | 55–76 | `assignRole`: requires `role.assign` (L57), CSRF (L58), body field `role` = slug (L60), only `super_admin` may grant `super_admin`, `INSERT IGNORE` add (L73). **Does not touch `union_id`.** |
| `src/Repositories/UserRepository.php` | 22–37, 58–62 | `create()` writes `union_id`; `assignRole()` is `INSERT IGNORE` (adds, never replaces). |
| `src/Middleware/RbacMiddleware.php` | 25–42, 48–71 | `requirePermission` (super_admin bypasses). `allowedUnionId` throws `403 No union assigned` when a union-scoped role has `union_id = NULL` (L61). |
| `src/Core/ScopePolicy.php` | 19–24, 37 | Union-scoped roles = `union_admin, service_officer, volunteer_coordinator`. |
| `config/config.php` | `load()` / `db()` | `.env` loader; DB keys `DB_HOST/PORT/NAME/USER/PASS/CHARSET`. |
| `database/migrations/002_locations.sql` | unions table + FK | `fk_users_union` target. |
| `database/migrations/004_users.sql` | users + `user_roles` | `union_id` nullable FK; `account_status` enum; `user_roles` composite PK. |
| `database/migrations/011_roles_scope.sql` | `scope` column | sets `union_admin`/`service_officer` to `union`. |
| `database/seeds/001_roles_permissions.sql` | roles + perms | `role.assign` granted **only to `super_admin`**; `upazila_admin` lacks it. |
| `database/seeds/003_sonatola_base.sql` | upazila only | **No unions are seeded** (by design — `database/schema.md` L9). |
| `docs/api-v1.md` | Auth + Admin users tables | `register` body `{name,email,password>=8,phone?,union_id?}`; roles body `{role: slug}`. |

## 2. Database target & how it resolves

`scripts/create_admin.php` requires `config/config.php` + `src/bootstrap.php`, then `DB::pdo()` → `Config::db()` → **`.env` at the project root** (`DB_HOST/PORT/NAME/USER/PASS/CHARSET`). No `.env` content, password, or token was printed.

A reachable DB **is** configured, so **strictly read-only `SELECT`s** were run. Verified results:

- **roles (6):** `super_admin`(global), `upazila_admin`(global), `union_admin`(union), `editor`(global), `service_officer`(union), `citizen`(global) — matches migration/seed.
- **unions (1):** `id=1`, `name_en="TEST-UNION-E2E-DO-NOT-USE"`, `is_active=1`.
- **upazilas (1):** `id=1`, `Sonatola`.
- **users (4):** 3× `citizen` (all `union_id=NULL`), 1× `union_admin` (`union_id=1`); **0 active `super_admin`**.

Read-only re-check command (no secrets printed):

```bash
php -r 'require "config/config.php"; Config::load(getcwd()); $c=Config::db();
$p=new PDO(sprintf("mysql:host=%s;port=%d;dbname=%s;charset=%s",$c["host"],(int)$c["port"],$c["name"],$c["charset"]??"utf8mb4"),$c["user"],$c["pass"],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach($p->query("SELECT slug,scope FROM roles ORDER BY id") as $r) echo "role ".$r["slug"]." ".$r["scope"]."\n";
foreach($p->query("SELECT id,name_en,is_active FROM unions ORDER BY id") as $u) echo "union ".$u["id"]." ".$u["name_en"]." active=".$u["is_active"]."\n";'
```

## 3. Creation path per role (verified against code)

- **`super_admin`** — supported only by `scripts/create_admin.php` (CLI). No API creates a super_admin.
- **`upazila_admin`, `union_admin`, `service_officer`** — the only supported path is: `POST /api/v1/register` (public, creates an **active `citizen`**) → then `POST /api/v1/admin/users/{id}/roles` with `{role:"<slug>"}`, executed by an **existing `super_admin`** (`role.assign` is granted only to `super_admin`).
- **`union_id` assignment:** it can be set **only at registration** (optional `union_id` field, `AuthService.php:33`) and **never afterwards** — no endpoint or repository method updates it. Validation is **only the DB FK `fk_users_union`**: the value is cast with `(int)` and never checked for existence/`is_active`; a bad id therefore surfaces as a **500**, not a clean 422. For a union-scoped role created with `union_id=NULL`, every scoped request returns **403 `No union assigned to this account.`**

## 4. Exact commands — placeholders only, NOT executed

**Step A — first super_admin (CLI; guard currently passes since 0 super_admins exist):**

```bash
php scripts/create_admin.php \
  --name="Super Admin" \
  --email="super_admin@example.gov.bd" \
  --password="<STRONG_PW_SUPER_ADMIN_12+>"
```

**Step B — register the staff accounts** (public; rate limit 10/hour/IP):

```bash
# upazila_admin (global role — omit union_id)
curl -sS -X POST http://localhost:8080/api/v1/register -H 'Content-Type: application/json' \
  -d '{"name":"Upazila Admin","email":"upazila_admin@example.gov.bd","password":"<STRONG_PW_UPAZILA_12+>"}'

# union_admin  (union-scoped — union_id REQUIRED; see BLOCKER in section 5)
curl -sS -X POST http://localhost:8080/api/v1/register -H 'Content-Type: application/json' \
  -d '{"name":"Union Admin","email":"union_admin@example.gov.bd","password":"<STRONG_PW_UNION_12+>","union_id":<UNION_ID>}'

# service_officer (union-scoped — union_id REQUIRED)
curl -sS -X POST http://localhost:8080/api/v1/register -H 'Content-Type: application/json' \
  -d '{"name":"Service Officer","email":"service_officer@example.gov.bd","password":"<STRONG_PW_OFFICER_12+>","union_id":<UNION_ID>}'
```

Each returns `{id, email}` — capture `id`.

**Step C — login as super_admin to obtain `token`/`csrf`:**

```bash
curl -sS -X POST http://localhost:8080/api/v1/login -H 'Content-Type: application/json' \
  -d '{"email":"super_admin@example.gov.bd","password":"<STRONG_PW_SUPER_ADMIN_12+>"}'
```

**Step D — assign each role (Bearer ⇒ CSRF-exempt):**

```bash
curl -sS -X POST http://localhost:8080/api/v1/admin/users/<USER_ID>/roles \
  -H 'Authorization: Bearer <TOKEN>' -H 'Content-Type: application/json' \
  -d '{"role":"upazila_admin"}'      # then "union_admin", then "service_officer"
```

## 5. Blockers, permissions, security risks

- **BLOCKER (union_admin / service_officer):** the only Union row is `id=1 "TEST-UNION-E2E-DO-NOT-USE"`. There is **no genuine official Union**. No Union was created and `id=1` is **not assumed** to be correct. **STOP** for these two roles until an officially verified Union exists. (Note: a `union_admin` already holds `union_id=1`.)
- **Environment:** the data (`TEST-UNION-E2E-DO-NOT-USE`, E2E users) indicates a **test/E2E database**, not production. Confirm the target before creating real accounts.
- **Permission:** role assignment needs `role.assign` — held **only by `super_admin`**; therefore a super_admin must exist first (created via CLI) and perform Step D.
- **CSRF:** if you assign via a cookie session instead of Bearer, `X-CSRF-Token` is mandatory (403 otherwise).
- **`--password` on the CLI:** arguments are exposed via **shell history** and **process listings** (`ps`, `/proc/<pid>/cmdline`). The script has **no interactive prompt** (`getopt` only) — it was **not** modified. Mitigate only by operating in a private shell / clearing history and rotating; a truly safe interactive input would require a script change (out of scope here).
- **Registered staff keep the `citizen` role** in addition to the granted admin role (`assignRole` uses `INSERT IGNORE`; `citizen` is added automatically). Harmless for checks but worth noting.
- **Passwords:** registration enforces min 8, CLI enforces min 12 — use **four distinct 12+ character passwords** from a manager; never in reports, SQL, or Git.

## 6. Confirmation

Confirmed: **no account was created and no database was modified.** All database access was read-only `SELECT` queries against `roles`, `unions`, `upazilas`, and user/role metadata. No `INSERT/UPDATE/DELETE`, no migration/seed run, and no `.env` contents, passwords, or tokens were printed.
