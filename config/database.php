<?php
declare(strict_types=1);

$databaseConfig = ['host' => '', 'port' => '3306', 'socket' => '', 'name' => '', 'user' => '', 'pass' => ''];

$localConfigPath = __DIR__ . '/database.local.php';
if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (is_array($localConfig)) {
        $databaseConfig = array_merge($databaseConfig, $localConfig);
    }
}

$environmentKeys = [
    'host' => ['EAMS_DB_HOST', 'FJ_DB_HOST'],
    'port' => ['EAMS_DB_PORT', 'FJ_DB_PORT'],
    'socket' => ['EAMS_DB_SOCKET', 'FJ_DB_SOCKET'],
    'name' => ['EAMS_DB_NAME', 'FJ_DB_NAME'],
    'user' => ['EAMS_DB_USER', 'FJ_DB_USER'],
    'pass' => ['EAMS_DB_PASS', 'FJ_DB_PASS'],
];
foreach ($environmentKeys as $configKey => $candidateKeys) {
    foreach ($candidateKeys as $environmentKey) {
        $value = getenv($environmentKey);
        if ($value !== false) {
            $databaseConfig[$configKey] = (string)$value;
            break;
        }
    }
}

try {
    if (($databaseConfig['host'] === '' && $databaseConfig['socket'] === '')
        || $databaseConfig['name'] === ''
        || $databaseConfig['user'] === '') {
        throw new RuntimeException('Database configuration is incomplete.');
    }

    $dsn = $databaseConfig['socket'] !== ''
        ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $databaseConfig['socket'], $databaseConfig['name'])
        : sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $databaseConfig['host'],
            $databaseConfig['port'],
            $databaseConfig['name']
        );
    $pdo = new PDO($dsn, $databaseConfig['user'], $databaseConfig['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    error_log('EAMS database connection failed: ' . $e->getMessage());
    if (PHP_SAPI !== 'cli') {
        http_response_code(503);
    }
    exit('The service is temporarily unavailable. Please try again later.');
}
