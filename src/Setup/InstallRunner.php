<?php
declare(strict_types=1);

/**
 * Shared migration/seed/admin-install logic used by BOTH the CLI runner
 * (database/migrate.php) and the browser installer (public/setup.php).
 * Single implementation — no duplicated migration logic.
 *
 * Conventions preserved from the CLI runner:
 * - Files run in filename order; SqlSplitter (quote/comment aware) splits them.
 * - schema_migrations bookkeeping makes reruns safe (INSERT IGNORE).
 * - Destructive DROP statements abort unless explicitly allowed.
 * - DDL is NOT wrapped in transactions (MySQL/MariaDB implicit commit).
 * - Dry-run callers must use probeState()/splitFile() only — never the
 *   apply*() methods. probeState() performs SELECTs on information_schema
 *   exclusively and creates nothing.
 */
final class InstallRunner
{
    /** @return array<int,string> sorted absolute paths of *.sql in $dir */
    public static function migrationFiles(string $migrationDir): array
    {
        $files = glob(rtrim($migrationDir, '/') . '/*.sql') ?: [];
        sort($files);
        return $files;
    }

    /** @return array<int,string> sorted absolute paths of seed *.sql in $dir */
    public static function seedFiles(string $seedDir): array
    {
        $files = glob(rtrim($seedDir, '/') . '/*.sql') ?: [];
        sort($files);
        return $files;
    }

    /** @return array<int,string> individual statements of one .sql file */
    public static function splitFile(string $path): array
    {
        return SqlSplitter::split((string) file_get_contents($path));
    }

    /**
     * Read-only install state probe. Creates nothing.
     * @return array{dbExists:bool, bookkeepingExists:bool, applied:array<int,string>}
     */
    public static function probeState(PDO $pdo, string $dbName): array
    {
        $dbName = preg_replace('/[^A-Za-z0-9_]/', '', $dbName);
        $q = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :db');
        $q->execute([':db' => $dbName]);
        $dbExists = (bool) $q->fetchColumn();
        $bookkeepingExists = false;
        $applied = [];
        if ($dbExists) {
            $t = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'schema_migrations'"
            );
            $t->execute([':db' => $dbName]);
            $bookkeepingExists = ((int) $t->fetchColumn()) > 0;
        }
        if ($bookkeepingExists) {
            // Qualified name is safe: $dbName is strictly [A-Za-z0-9_].
            $applied = array_column(
                $pdo->query("SELECT version FROM `{$dbName}`.schema_migrations ORDER BY version")->fetchAll(),
                'version'
            );
        }
        return ['dbExists' => $dbExists, 'bookkeepingExists' => $bookkeepingExists, 'applied' => $applied];
    }

    /** @return array<int,string> basenames of files in $files not present in $applied */
    public static function pendingBasenames(array $files, array $applied): array
    {
        $pending = [];
        foreach ($files as $f) {
            $v = basename((string) $f, '.sql');
            if (!in_array($v, $applied, true)) {
                $pending[] = $v;
            }
        }
        return $pending;
    }

    /** @return true when any file's SQL contains a destructive DROP */
    public static function containsDestructiveDrop(string $sql): bool
    {
        return (bool) preg_match('/\bDROP\s+(TABLE|DATABASE)\b/i', $sql);
    }

    /** Create the database if missing and select it (real runs only). */
    public static function ensureDatabase(PDO $pdo, string $dbName): void
    {
        $dbName = preg_replace('/[^A-Za-z0-9_]/', '', $dbName);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbName}`");
    }

    /** Execute one migration file statement-by-statement + record bookkeeping. Returns statement count. */
    public static function applyMigrationFile(PDO $pdo, string $file): int
    {
        $n = 0;
        foreach (self::splitFile($file) as $stmt) {
            $pdo->exec($stmt);
            $n++;
        }
        $st = $pdo->prepare('INSERT IGNORE INTO schema_migrations (version) VALUES (:v)');
        $st->execute([':v' => basename($file, '.sql')]);
        return $n;
    }

    /** Execute one seed file statement-by-statement. */
    public static function applySeedFile(PDO $pdo, string $file): void
    {
        foreach (self::splitFile($file) as $stmt) {
            $pdo->exec($stmt);
        }
    }

    /**
     * Create the initial Super Admin atomically.
     * Mutual exclusion across sessions/hosts via a MySQL named advisory lock
     * (a SELECT COUNT(*) … FOR UPDATE cannot serialize when zero rows match,
     * so it is NOT relied upon). Inside the lock a single InnoDB transaction
     * covers the super_admin-exists check, the email-uniqueness check, the
     * users INSERT and the user_roles INSERT — any failure rolls everything
     * back, so no orphan user or role row can remain.
     *
     * @throws AppException with safe (non-secret) messages only
     */
    public static function createSuperAdmin(
        PDO $pdo,
        string $name,
        string $email,
        string $passwordHash,
        ?string $phone = null,
        ?int $upazilaId = null,
        int $lockTimeoutSeconds = 10
    ): int {
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '' || $email === '' || $passwordHash === '') {
            throw AppException::unprocessable('Name, email and password are required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw AppException::unprocessable('Invalid email address.');
        }
        $locked = $pdo->query("SELECT GET_LOCK('sonatola_setup_admin', " . ((int) $lockTimeoutSeconds) . ')')->fetchColumn();
        if ($locked != 1) {
            throw AppException::rateLimited('Another setup is already creating the administrator. Please retry.');
        }
        try {
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare(
                    "SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id
                     JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'super_admin'"
                );
                $st->execute();
                if ((int) $st->fetchColumn() > 0) {
                    throw AppException::forbidden('A Super Admin already exists.');
                }
                $st = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
                $st->execute([':e' => $email]);
                if ($st->fetch()) {
                    throw AppException::conflict('Email already registered.');
                }
                $ins = $pdo->prepare(
                    "INSERT INTO users (name, email, phone, password_hash, upazila_id, account_status)
                     VALUES (:n, :e, :p, :h, :u, 'active')"
                );
                $ins->execute([
                    ':n' => substr($name, 0, 100),
                    ':e' => $email,
                    ':p' => $phone !== null && $phone !== '' ? substr($phone, 0, 20) : null,
                    ':h' => $passwordHash,
                    ':u' => $upazilaId,
                ]);
                $uid = (int) $pdo->lastInsertId();
                $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'super_admin' LIMIT 1")->fetchColumn();
                if ($roleId <= 0) {
                    throw new AppException('Administrator role is not installed yet.', 500);
                }
                $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)')
                    ->execute([':u' => $uid, ':r' => $roleId]);
                $pdo->commit();
                return $uid;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } finally {
            try {
                $pdo->query("SELECT RELEASE_LOCK('sonatola_setup_admin')");
            } catch (Throwable) {
            }
        }
    }
}
