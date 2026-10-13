# API v1

Base: `/api/v1`. JSON only. Envelope: `{success: bool, data|error, meta?}`.

## Public (no login)

| Method | URL | Notes |
|---|---|---|
| GET | `/api/v1/health` | liveness |
| GET | `/api/v1/news?page&per_page&q&category_id` | published only, paginated |
| GET | `/api/v1/news/{slug}` | 404 if not published |
| GET | `/api/v1/notices?page&per_page&q` | published only, **expired (`expires_at` past) hidden** |
| GET | `/api/v1/unions` | active unions |
| GET | `/api/v1/services?page&per_page&q` | active services |
| GET | `/api/v1/categories` | active news categories |

> Uploads: **deferred — no file upload/download endpoints in v1.** `UploadService` validates (JPG/PNG/WEBP/PDF, magic bytes, 5 MB, private `storage/uploads` outside docroot, `is_public=0`) but is not wired to any route, so private files cannot be exposed without a future authenticated + authorized file controller. Do not add public file routes without authz.

## Auth

| Method | URL | Body | Resp |
|---|---|---|---|
| POST | `/api/v1/register` | `{name, email, password>=8, phone?, union_id?}` | 201 `{id,email}` |
| POST | `/api/v1/login` | `{email, password}` | 200 `{user, token, csrf}` — sets session cookie + returns Bearer token for Capacitor |
| POST | `/api/v1/logout` | — (Bearer or session) | 200 |
| GET | `/api/v1/me` | auth required | current user + roles/permissions |
| PUT | `/api/v1/me` | `{name?, phone?}` + `X-CSRF-Token` if session | 200 |
| POST | `/api/v1/password/request` | `{email}` | always 200 (anti-enumeration). **Disabled by default** — no token is issued unless `PASSWORD_RESET_ENABLED=true` AND settings `auth.password_reset_enabled=1` with SMTP configured (see below). |
| POST | `/api/v1/password/reset` | `{token, password>=8}` | 200, atomic single-use token; revokes all API sessions on success |

Auth: `Authorization: Bearer <token>` (mobile) or session cookie (browser). Session writes need `X-CSRF-Token` or `_csrf` — including `POST /api/v1/complaints` for cookie sessions (Bearer clients exempt). Server sessions expire after `SESSION_IDLE_MINUTES` (default 120) idle or `SESSION_ABSOLUTE_HOURS` (default 12) absolute.

## Password reset (delivery)

1. Disabled by default. Both env `PASSWORD_RESET_ENABLED=true` (see `.env.example`) and DB settings `auth.password_reset_enabled=1` (see `database/seeds/004_auth_settings.sql`) must be enabled.
2. Configure SMTP delivery before enabling: on `requestPasswordReset`, the raw token must be emailed immediately to the account address. Raw tokens are never logged, stored, or returned by the API (only SHA-256 hashes in `auth_tokens`).
3. Tokens are 1-hour, atomic single-use (concurrent consumes cannot both succeed), and a successful reset revokes all `api_session` tokens for that user.
4. Without SMTP, keep the feature disabled: requests return the generic message and are audit-logged as `user.password_reset_requested_disabled` with no token issued.

## Complaints

| Method | URL | Perm | Notes |
|---|---|---|---|
| POST | `/api/v1/complaints` | auth (any active) + CSRF for cookie sessions | `{subject, description>=10, union_id, category?, location_text?, contact_phone?, priority: low|normal|high|urgent}` → 201 with `tracking_no` (retried on rare collision) |
| GET | `/api/v1/complaints/mine` | auth | own complaints paginated |
| GET | `/api/v1/complaints/{id}` | owner or `complaint.view` (union-scoped) | includes `history` |
| GET | `/api/v1/complaints?status&union_id&page` | `complaint.view` | `status` allowlist-validated (422 on invalid); union admins forced to own union |
| POST | `/api/v1/complaints/{id}/assign` | `complaint.assign` | `{assigned_to, note?}` |
| POST | `/api/v1/complaints/{id}/status` | `complaint.status` | `{status: in_review|assigned|resolved|rejected|closed, note?}` |

## Admin: news / notices (scoped)

`GET /api/v1/admin/news`, `POST`, `PUT /{id}`, `DELETE /{id}` — perms `news.view/create/edit/delete`, union-scoped listings (own union + upazila-wide NULL-union items).
Same for `/api/v1/admin/notices` with `notice.*` (admin sees expired; public hides them). Union admins can only edit rows of their own `union_id` (403 otherwise).

Union scoping policy: `src/Core/ScopePolicy.php` (DB-driven `roles.scope` with `UNION_SCOPED` fallback). To add e.g. `volunteer_coordinator`, insert the role with `scope='union'` — no middleware change needed.

Example create:
```json
POST /api/v1/admin/news
{"title":"...","body":"...>=10 chars","category_id":1,"status":"draft"}
```

## Admin: users / roles / audit

| Method | URL | Perm |
|---|---|---|
| GET | `/api/v1/admin/users?status&q` | `user.view` (union-scoped: union staff see own union only; `status` allowlist-validated) |
| PUT | `/api/v1/admin/users/{id}/status` | `user.manage` + CSRF, body `{account_status: pending|active|suspended|disabled}` — cannot disable the last active Super Admin |
| POST | `/api/v1/admin/users/{id}/roles` | `role.assign` + CSRF, body `{role: slug}` (super_admin grant needs super_admin) |
| GET | `/api/v1/admin/roles` | `role.view` |
| GET | `/api/v1/admin/permissions` | `role.view` |
| GET | `/api/v1/admin/audit-logs?page` | `audit.view` |

## Errors

- 400 bad_request, 401 unauthorized, 403 forbidden, 404 not_found, 409 conflict, 422 validation_failed, 429 rate_limited, 500 internal_error, 503 db_unavailable.
- Error shape: `{success:false, error:{code, message, details?}}`. Production hides internals; enable `APP_DEBUG=true` locally only.

## Pagination

`?page=1&per_page=15` (max 100). Response `meta.pagination: {page, per_page, total, total_pages}`.

## Capacitor notes

- Store `token` in secure storage, send as Bearer. Tokens expire in 30 days; re-login on 401.
- Respect `X-CSRF-Token` only for cookie sessions; Bearer clients are exempt.
- Restrict WebView origins; never log tokens.
