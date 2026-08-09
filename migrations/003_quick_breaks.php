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
    $check->execute(['003_quick_breaks']);
    if ((int)$check->fetchColumn() > 0) {
        echo "Migration 003_quick_breaks is already applied.\n";
        exit(0);
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS attendance_quick_breaks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        attendance_id INT NOT NULL,
        started_at DATETIME NOT NULL,
        ended_at DATETIME NULL,
        duration_seconds INT UNSIGNED NULL,
        open_break_key TINYINT GENERATED ALWAYS AS (IF(ended_at IS NULL, 1, NULL)) STORED,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_open_quick_break (attendance_id, open_break_key),
        INDEX idx_quick_break_attendance_start (attendance_id, started_at),
        CONSTRAINT fk_quick_break_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute(['003_quick_breaks']);
    echo "Migration 003_quick_breaks applied successfully.\n";
} catch (Throwable $e) {
    error_log('Migration 003_quick_breaks failed: ' . $e->getMessage());
    fwrite(STDERR, "Migration failed. Review the server error log for details.\n");
    exit(1);
}
