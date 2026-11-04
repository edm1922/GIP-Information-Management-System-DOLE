<?php

declare(strict_types=1);

/**
 * GIMS — copy data from the local XAMPP/MariaDB database to Azure Database for MySQL.
 *
 * Run from the LOCAL machine, after the target schema exists on the VM
 * (`php artisan migrate --force` was run there):
 *
 *   php deploy/azure/05-migrate-data.php --dry-run
 *   php deploy/azure/05-migrate-data.php
 *
 * Requires: deploy/azure/.migrate.env (copy of .migrate.env.example).
 * Sessions are deliberately not copied — users simply log in again.
 */
$repoRoot = dirname(__DIR__, 2);
$envFile = __DIR__.'/.migrate.env';
$dryRun = in_array('--dry-run', $argv, true);

if (! is_file($envFile)) {
    fwrite(STDERR, "Missing {$envFile}\nCopy deploy/azure/.migrate.env.example and fill it in.\n");
    exit(1);
}

$cfg = parse_env_file($envFile);

/** Candidate data tables in foreign-key-safe insertion order (filtered to those present on both sides). */
$tables = [
    'companies',
    'document_types',
    'bank_types',
    'users',
    'interns',
    'documents',
    'dtr_days',
    'audit_logs',
    'notifications',
];

/** Never copied: schema state, runtime state, transient rows. */
$skipped = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

echo "Source: {$cfg['SOURCE_HOST']}:{$cfg['SOURCE_PORT']}/{$cfg['SOURCE_DB']}\n";
echo "Target: {$cfg['TARGET_HOST']}:{$cfg['TARGET_PORT']}/{$cfg['TARGET_DB']}\n";
echo $dryRun ? "Mode:   DRY RUN (no writes)\n\n" : "Mode:   LIVE\n\n";

$src = connect(
    $cfg['SOURCE_HOST'], (int) $cfg['SOURCE_PORT'], $cfg['SOURCE_DB'],
    $cfg['SOURCE_USER'], $cfg['SOURCE_PASS'] ?? '', null
);

if ($dryRun) {
    echo "Dry run — source row counts only (no target connection, no writes):\n";
    foreach ($tables as $table) {
        try {
            $count = (int) $src->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            echo "  {$table}: {$count} rows\n";
        } catch (Throwable $e) {
            echo "  {$table}: source table missing\n";
        }
    }
    echo "\nDry run complete.\n";
    exit(0);
}

$caPath = $cfg['TARGET_SSL_CA'] ?? '';
if ($caPath !== '' && ! preg_match('#^(/|[A-Za-z]:)#', $caPath)) {
    $caPath = $repoRoot.DIRECTORY_SEPARATOR.$caPath;
}
if ($caPath !== '' && ! is_file($caPath)) {
    fwrite(STDERR, "WARNING: CA file not found at {$caPath} — connecting without certificate verification.\n");
    $caPath = null;
}

$dst = connect(
    $cfg['TARGET_HOST'], (int) $cfg['TARGET_PORT'], $cfg['TARGET_DB'],
    $cfg['TARGET_USER'], $cfg['TARGET_PASS'] ?? '', $caPath
);

// Only copy tables that exist on both sides (the schema evolved; e.g. bank_types
// was removed by the legacy-201-files cleanup migration).
$srcTables = $src->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$dstTables = $dst->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$candidates = $tables;
$tables = array_values(array_filter(
    $candidates,
    fn (string $table) => in_array($table, $srcTables, true) && in_array($table, $dstTables, true)
));
$absent = array_values(array_diff($candidates, $tables));
if ($absent !== []) {
    echo 'Skipping tables absent from one side: '.implode(', ', $absent)."\n";
}

$dst->exec('SET FOREIGN_KEY_CHECKS=0');

echo "Clearing target tables…\n";
foreach (array_reverse($tables) as $table) {
    $dst->exec("TRUNCATE TABLE `{$table}`");
}

$failed = false;

foreach ($tables as $table) {
    try {
        $columns = $src->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        $rows = $src->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        echo "  [skip] {$table}: source table missing ({$e->getMessage()})\n";

        continue;
    }

    if ($rows === []) {
        echo "  {$table}: 0 rows\n";

        continue;
    }

    $columnList = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
    $placeholder = '('.implode(', ', array_fill(0, count($columns), '?')).')';

    foreach (array_chunk($rows, 100) as $chunk) {
        $values = implode(', ', array_fill(0, count($chunk), $placeholder));
        $params = [];
        foreach ($chunk as $row) {
            foreach ($columns as $column) {
                $params[] = $row[$column] ?? null;
            }
        }
        $dst->prepare("INSERT INTO `{$table}` ({$columnList}) VALUES {$values}")->execute($params);
    }

    $targetCount = (int) $dst->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    $status = $targetCount === count($rows) ? 'OK' : 'MISMATCH';
    if ($status === 'MISMATCH') {
        $failed = true;
    }
    echo "  {$table}: copied ".count($rows)." rows (target now {$targetCount}) {$status}\n";
}

$dst->exec('SET FOREIGN_KEY_CHECKS=1');

echo "\nSkipped (by design): ".implode(', ', $skipped)."\n";

$dst->exec('SET FOREIGN_KEY_CHECKS=1');

// Final row-count comparison for the copied tables.
echo "\nVerification:\n";
foreach ($tables as $table) {
    try {
        $s = (int) $src->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $t = (int) $dst->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $ok = $s === $t ? 'OK' : 'MISMATCH';
        if ($s !== $t) {
            $failed = true;
        }
        echo "  {$table}: source={$s} target={$t} {$ok}\n";
    } catch (Throwable $e) {
        echo "  {$table}: could not compare ({$e->getMessage()})\n";
    }
}

echo $failed ? "\nFinished WITH ERRORS — review the mismatches above.\n" : "\nAll data copied successfully.\n";
exit($failed ? 1 : 0);

function parse_env_file(string $path): array
{
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim(trim($value), "\"'");
    }

    return $values;
}

function connect(string $host, int $port, string $database, string $user, string $password, ?string $sslCa): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
    ];
    if ($sslCa !== null) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    try {
        return new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, $password, $options);
    } catch (Throwable $e) {
        fwrite(STDERR, "Connection failed to {$host}:{$port}/{$database}: {$e->getMessage()}\n");
        exit(1);
    }
}
