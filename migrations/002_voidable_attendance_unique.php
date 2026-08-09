<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';

try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $check = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = ?');
    $check->execute(['002_voidable_attendance_unique']);
    if ((int)$check->fetchColumn() > 0) {
        echo "Migration 002_voidable_attendance_unique is already applied.\n";
        exit(0);
    }

    $columnStmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "attendance" AND COLUMN_NAME = "active_attendance_key"');
    $columnStmt->execute();
    if ((int)$columnStmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE attendance ADD COLUMN active_attendance_key TINYINT
            GENERATED ALWAYS AS (IF(voided_at IS NULL, 1, NULL)) STORED AFTER void_reason');
    }

    $indexStmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "attendance" AND INDEX_NAME = ?');
    $indexStmt->execute(['uniq_attendance_employee_day']);
    if ((int)$indexStmt->fetchColumn() > 0) {
        $pdo->exec('ALTER TABLE attendance DROP INDEX uniq_attendance_employee_day');
    }
    $indexStmt->execute(['uniq_active_attendance_employee_day']);
    if ((int)$indexStmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE attendance ADD UNIQUE KEY uniq_active_attendance_employee_day
            (employee_id, attendance_date, active_attendance_key)');
    }

    $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute(['002_voidable_attendance_unique']);
    echo "Migration 002_voidable_attendance_unique applied successfully.\n";
} catch (Throwable $e) {
    error_log('Migration 002_voidable_attendance_unique failed: ' . $e->getMessage());
    fwrite(STDERR, "Migration failed. Review the server error log for details.\n");
    exit(1);
}
