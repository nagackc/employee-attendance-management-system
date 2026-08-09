<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$migrationFiles = glob(__DIR__ . '/../migrations/*.php') ?: [];
sort($migrationFiles, SORT_NATURAL);

foreach ($migrationFiles as $migrationFile) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($migrationFile);
    passthru($command, $status);
    if ($status !== 0) {
        fwrite(STDERR, 'Migration command failed for ' . basename($migrationFile) . ".\n");
        exit($status);
    }
}

echo "All migrations are up to date.\n";
