Audit and fix SQL Injection (SQLi) and Cross-Site Scripting (XSS) vulnerabilities in the existing OurSonatola PHP backend.

- Inspect all SQL queries, API inputs, authentication, search/filter logic, and user-controlled output.
- Verify PDO prepared statements, safe dynamic SQL allowlists, and context-aware output escaping.
- Test reflected/stored XSS and SQLi using safe payloads in an isolated environment.
- Fix confirmed vulnerabilities and add regression tests.
- Preserve existing APIs, RBAC, union-level isolation, CSRF, sessions, and installer behavior.
- Never modify "sonatola_test", production data, or ".env". Use only a separate disposable database for live tests.
- Run PHP lint, the full test suite, and read-only migration dry-run.
- Report changed files, confirmed findings, fixes, test results, and unresolved risks. Do not claim unverified tests passed or declare the application production-ready without sufficient evidence.