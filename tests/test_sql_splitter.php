<?php
declare(strict_types=1);
// SqlSplitter: quote/comment-aware splitting (no naive explode) + real migration files
require_once __DIR__ . '/../src/Core/SqlSplitter.php';

echo "sql_splitter:\n";
// Semicolon inside string literal must NOT split
$parts = SqlSplitter::split("INSERT INTO t (a) VALUES ('a;b');\nINSERT INTO t (a) VALUES ('c');");
T::ok(count($parts) === 2 && str_contains($parts[0], "'a;b'"), 'semicolon in string preserved');

// Line and block comments with semicolons ignored
$parts = SqlSplitter::split("-- comment; with semicolon\nCREATE TABLE a (id INT);\n/* block; comment */\nCREATE TABLE b (id INT);");
T::ok(count($parts) === 2 && str_starts_with($parts[0], 'CREATE TABLE a'), 'comments ignored');

// Escaped quotes
$parts = SqlSplitter::split("INSERT INTO t (a) VALUES ('it''s; ok');\nSELECT 1;");
T::ok(count($parts) === 2, 'escaped quote handled');

// Empty input
T::ok(SqlSplitter::split("  \n -- only comment\n ") === [], 'empty/comment-only yields none');

// Real migration files: statement counts must match CREATE TABLE counts
$cases = [
    '001_schema_migrations.sql' => 1,
    '003_roles_permissions.sql' => 3,
    '007_complaints.sql' => 3,
    '011_roles_scope.sql' => 2,
];
foreach ($cases as $file => $expected) {
    $sql = (string) file_get_contents(__DIR__ . '/../database/migrations/' . $file);
    $n = count(SqlSplitter::split($sql));
    T::ok($n === $expected, "split {$file} = {$expected} (got {$n})");
}

// migrate.php must split via SqlSplitter (directly or through InstallRunner's
// splitFile, which is its only splitter) and must NOT claim transactional DDL rollback
$mig = (string) file_get_contents(__DIR__ . '/../database/migrate.php');
T::ok(str_contains($mig, 'SqlSplitter::split') || str_contains($mig, 'InstallRunner::splitFile'), 'migrate uses splitter');
T::ok(str_contains($mig, 'implicit commit') || str_contains($mig, 'NOT wrap DDL'), 'migrate documents implicit commit');
T::ok(!preg_match('/beginTransaction\(\).*exec\(\$sql\)/s', $mig), 'no single-exec transaction around whole file');
