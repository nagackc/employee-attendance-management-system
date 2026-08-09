<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve the project root.\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $item): bool {
            return !$item->isDir() || !in_array($item->getFilename(), ['vendor', 'node_modules', '.git'], true);
        }
    )
);

$phpFiles = [];
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'php') {
        $phpFiles[] = $file->getPathname();
    }
}
sort($phpFiles, SORT_NATURAL);

foreach ($phpFiles as $phpFile) {
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($phpFile);
    exec($command, $output, $status);
    if ($status !== 0) {
        fwrite(STDERR, implode("\n", $output) . "\n");
        exit($status);
    }
    echo 'PASS: ' . substr($phpFile, strlen($root) + 1) . "\n";
    $output = [];
}

echo 'Completed PHP lint for ' . count($phpFiles) . " files.\n";
