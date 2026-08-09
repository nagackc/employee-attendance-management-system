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
    $check->execute(['005_attendance_corrections']);
    if ((int)$check->fetchColumn() > 0) {
        echo "Migration 005_attendance_corrections is already applied.\n";
        exit(0);
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS attendance_correction_requests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        attendance_id INT NULL,
        employee_id INT NOT NULL,
        request_kind ENUM("existing_record", "missing_record") NOT NULL,
        attendance_date DATE NOT NULL,
        original_values LONGTEXT NULL,
        requested_time_in DATETIME NOT NULL,
        requested_time_out DATETIME NULL,
        requested_break_start DATETIME NULL,
        requested_break_end DATETIME NULL,
        reason VARCHAR(1000) NOT NULL,
        status ENUM("pending", "approved", "rejected", "cancelled") NOT NULL DEFAULT "pending",
        admin_comment VARCHAR(1000) NULL,
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        pending_scope_key VARCHAR(80) GENERATED ALWAYS AS (
            IF(status = "pending", CONCAT(employee_id, ":", attendance_date), NULL)
        ) STORED,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_pending_employee_date (pending_scope_key),
        INDEX idx_correction_status_created (status, created_at),
        INDEX idx_correction_employee_status (employee_id, status),
        INDEX idx_correction_attendance (attendance_id),
        CONSTRAINT fk_correction_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE RESTRICT,
        CONSTRAINT fk_correction_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
        CONSTRAINT fk_correction_reviewer FOREIGN KEY (reviewed_by) REFERENCES employees(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute(['005_attendance_corrections']);
    echo "Migration 005_attendance_corrections applied successfully.\n";
} catch (Throwable $e) {
    error_log('Migration 005_attendance_corrections failed: ' . $e->getMessage());
    fwrite(STDERR, "Migration failed. Review the server error log for details.\n");
    exit(1);
}
