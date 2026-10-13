<?php
declare(strict_types=1);

/**
 * Read-only database inspection + installation-state classification.
 * All probes are SELECTs on information_schema (or a qualified version table);
 * nothing is ever created, selected with FOR UPDATE, or modified here.
 *
 * States:
 *   FRESH       — zero tables in the target schema (proven, not inferred).
 *   INTERRUPTED — partial install matching a progress marker (see InstallLock).
 *   EXISTING    — complete install (11 versions + seed counts + ≥1 super_admin),
 *                 or an install lock is present.
 *   UNSAFE      — anything else (unrelated tables, unknown versions, mismatches).
 * Mutating steps run on FRESH (or resume INTERRUPTED) only; everything else
 * fails closed with observed facts for manual recovery.
 */
final class InstallerState
{
    public const FRESH = 'fresh';
    public const INTERRUPTED = 'interrupted';
    public const EXISTING = 'existing';
    public const UNSAFE = 'unsafe';

    /** @return array<int,string> table names in $db (read-only) */
    public static function tableNames(PDO $pdo, string $dbName): array
    {
        $dbName = preg_replace('/[^A-Za-z0-9_]/', '', $dbName);
        $rows = $pdo->query("SHOW TABLES FROM `{$dbName}`")->fetchAll(PDO::FETCH_NUM);
        $names = [];
        foreach ($rows as $r) {
            $names[] = (string) $r[0];
        }
        sort($names);
        return $names;
    }

    /**
     * Pure classification from observed facts (unit-testable, no PDO needed).
     * @param array<int,string> $tables sorted table names in target DB
     * @param array<int,string> $applied migration versions recorded
     * @param array<string,mixed> $progress setup-progress.json marker (stage => value)
     * @param array{roles:int,permissions:int,settings:int,upazilas:int,categories:int,superAdmins:int} $counts
     * @param bool $lockPresent
     * @return array{state:string,reason:string}
     */
    public static function classify(
        array $tables,
        array $applied,
        array $progress,
        array $counts,
        bool $lockPresent
    ): array {
        if ($lockPresent) {
            return ['state' => self::EXISTING, 'reason' => 'installation lock is present'];
        }
        if ($tables === []) {
            return ['state' => self::FRESH, 'reason' => 'target database contains zero tables'];
        }
        $completeSeeds = $counts['roles'] === 6 && $counts['permissions'] === 18
            && $counts['settings'] >= 6 && $counts['upazilas'] >= 1 && $counts['categories'] >= 3;
        if (count($applied) === 11 && $completeSeeds && $counts['superAdmins'] >= 1) {
            return ['state' => self::EXISTING, 'reason' => 'schema, seeds and administrator all present'];
        }
        // Interrupted only when a progress marker explains the partial state.
        if ($progress !== []) {
            $markedVersions = (int) ($progress['migrated_versions'] ?? -1);
            if ($markedVersions >= 0 && count($applied) === $markedVersions) {
                return ['state' => self::INTERRUPTED, 'reason' => 'partial install matches progress marker'];
            }
            return [
                'state' => self::UNSAFE,
                'reason' => 'progress marker disagrees with recorded migration versions',
            ];
        }
        return [
            'state' => self::UNSAFE,
            'reason' => 'database is not empty and matches no known install state',
        ];
    }

    /**
     * Live classification: gathers facts read-only, then delegates to classify().
     * @return array{state:string,reason:string,tables:array<int,string>,applied:array<int,string>,counts:array<string,int>}
     */
    public static function inspect(PDO $pdo, string $dbName, array $progress, bool $lockPresent): array
    {
        $dbName = preg_replace('/[^A-Za-z0-9_]/', '', $dbName);
        $tables = self::tableNames($pdo, $dbName);
        $applied = [];
        if (in_array('schema_migrations', $tables, true)) {
            $applied = array_column(
                $pdo->query("SELECT version FROM `{$dbName}`.schema_migrations ORDER BY version")->fetchAll(),
                'version'
            );
        }
        $counts = ['roles' => 0, 'permissions' => 0, 'settings' => 0, 'upazilas' => 0, 'categories' => 0, 'superAdmins' => 0];
        if ($tables !== []) {
            $count = static function (string $table) use ($pdo, $dbName): int {
                if (!in_array($table, self::tableNames($pdo, $dbName), true)) {
                    return 0;
                }
                return (int) $pdo->query("SELECT COUNT(*) FROM `{$dbName}`.`{$table}`")->fetchColumn();
            };
            $counts['roles'] = $count('roles');
            $counts['permissions'] = $count('permissions');
            $counts['settings'] = $count('settings');
            $counts['upazilas'] = $count('upazilas');
            $counts['categories'] = $count('news_categories');
            try {
                $counts['superAdmins'] = (int) $pdo->query(
                    "SELECT COUNT(DISTINCT u.id) FROM `{$dbName}`.users u
                     JOIN `{$dbName}`.user_roles ur ON ur.user_id = u.id
                     JOIN `{$dbName}`.roles r ON r.id = ur.role_id AND r.slug = 'super_admin'
                     WHERE u.account_status = 'active'"
                )->fetchColumn();
            } catch (Throwable) {
                $counts['superAdmins'] = 0;
            }
        }
        $verdict = self::classify($tables, $applied, $progress, $counts, $lockPresent);
        $verdict['tables'] = $tables;
        $verdict['applied'] = $applied;
        $verdict['counts'] = $counts;
        return $verdict;
    }
}
