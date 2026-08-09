<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';

function auditLogIndexExists(PDO $pdo, string $index): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "audit_logs" AND INDEX_NAME = ?');
    $stmt->execute([$index]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $check = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = ?');
    $check->execute(['007_audit_log_indexes']);
    if ((int)$check->fetchColumn() > 0) {
        echo "Migration 007_audit_log_indexes is already applied.\n";
        exit(0);
    }
    $tableExists = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "audit_logs"')->fetchColumn() > 0;
    if (!$tableExists) {
        throw new RuntimeException('The audit_logs table is missing.');
    }
    if (!auditLogIndexExists($pdo, 'idx_audit_created')) {
        $pdo->exec('ALTER TABLE audit_logs ADD INDEX idx_audit_created (created_at, id)');
    }
    if (!auditLogIndexExists($pdo, 'idx_audit_action_created')) {
        $pdo->exec('ALTER TABLE audit_logs ADD INDEX idx_audit_action_created (action, created_at)');
    }
    $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute(['007_audit_log_indexes']);
    echo "Migration 007_audit_log_indexes applied successfully.\n";
} catch (Throwable $e) {
    error_log('Migration 007_audit_log_indexes failed: ' . $e->getMessage());
    fwrite(STDERR, "Migration failed. Review the server error log for details.\n");
    exit(1);
}
