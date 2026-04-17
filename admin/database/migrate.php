<?php

declare(strict_types=1);

/**
 * Database migration script for B-Pay Admin (SQLite).
 *
 * Reads the SQLite-compatible schema and creates the database.
 * Run: php admin/database/migrate.php
 */

$schemaFile = dirname(__DIR__, 2) . '/api/database/schema-sqlite.sql';
$dbPath     = dirname(__DIR__, 2) . '/api/database/bcashpay.sqlite';

if (!is_file($schemaFile)) {
    fwrite(STDERR, "Schema file not found: {$schemaFile}\n");
    exit(1);
}

echo "Connecting to SQLite: {$dbPath}\n";

$pdo = new PDO('sqlite:' . $dbPath, options: [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "Running schema: {$schemaFile}\n";

$sql = file_get_contents($schemaFile);
if ($sql === false) {
    fwrite(STDERR, "Could not read schema file.\n");
    exit(1);
}

// Split on semicolons, filter empty statements
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    fn($s) => $s !== ''
);

$count = 0;
foreach ($statements as $stmt) {
    // Skip comment-only blocks
    $stripped = preg_replace('/--[^\n]*\n?/', '', $stmt);
    if (trim($stripped ?? '') === '') {
        continue;
    }
    try {
        $pdo->exec($stmt);
        $count++;
    } catch (PDOException $e) {
        echo "  [WARN] Statement failed (may already exist): " . $e->getMessage() . "\n";
    }
}

echo "Migration complete. {$count} statements executed.\n";
echo "Database: {$dbPath}\n";
