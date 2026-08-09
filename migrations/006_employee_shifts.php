<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';

function employeeShiftColumnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function employeeShiftForeignKeyExists(PDO $pdo, string $name): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = "FOREIGN KEY"');
    $stmt->execute([$name]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $check = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = ?');
    $check->execute(['006_employee_shifts']);
    if ((int)$check->fetchColumn() > 0) {
        echo "Migration 006_employee_shifts is already applied.\n";
        exit(0);
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS work_shifts (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL UNIQUE,
        timezone VARCHAR(100) NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        grace_period_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        work_days VARCHAR(20) NOT NULL DEFAULT "1,2,3,4,5",
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_work_shift_active (active),
        CONSTRAINT fk_work_shift_admin FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS employee_shift_assignments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        shift_id INT NOT NULL,
        effective_from DATE NOT NULL,
        effective_to DATE NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_employee_shift_start (employee_id, effective_from),
        INDEX idx_employee_shift_period (employee_id, effective_from, effective_to),
        CONSTRAINT fk_employee_shift_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
        CONSTRAINT fk_employee_shift_template FOREIGN KEY (shift_id) REFERENCES work_shifts(id) ON DELETE RESTRICT,
        CONSTRAINT fk_employee_shift_admin FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS company_shift_assignments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        company VARCHAR(150) NOT NULL,
        shift_id INT NOT NULL,
        effective_from DATE NOT NULL,
        effective_to DATE NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_company_shift_start (company, effective_from),
        INDEX idx_company_shift_period (company, effective_from, effective_to),
        CONSTRAINT fk_company_shift_template FOREIGN KEY (shift_id) REFERENCES work_shifts(id) ON DELETE RESTRICT,
        CONSTRAINT fk_company_shift_admin FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    if (!employeeShiftColumnExists($pdo, 'attendance', 'shift_id')) {
        $pdo->exec('ALTER TABLE attendance ADD COLUMN shift_id INT NULL AFTER schedule_timezone');
    }
    if (!employeeShiftColumnExists($pdo, 'attendance', 'shift_name')) {
        $pdo->exec('ALTER TABLE attendance ADD COLUMN shift_name VARCHAR(120) NULL AFTER shift_id');
    }
    if (!employeeShiftColumnExists($pdo, 'attendance', 'scheduled_workday')) {
        $pdo->exec('ALTER TABLE attendance ADD COLUMN scheduled_workday TINYINT(1) NOT NULL DEFAULT 1 AFTER grace_period_minutes');
        $pdo->exec('UPDATE attendance SET scheduled_workday = IF(DAYOFWEEK(attendance_date) IN (1, 7), 0, 1)');
    }
    if (!employeeShiftColumnExists($pdo, 'attendance_correction_requests', 'requested_schedule')) {
        $pdo->exec('ALTER TABLE attendance_correction_requests ADD COLUMN requested_schedule LONGTEXT NULL AFTER original_values');
    }
    if (!employeeShiftForeignKeyExists($pdo, 'fk_attendance_shift')) {
        $pdo->exec('ALTER TABLE attendance ADD CONSTRAINT fk_attendance_shift
            FOREIGN KEY (shift_id) REFERENCES work_shifts(id) ON DELETE RESTRICT');
    }

    $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute(['006_employee_shifts']);
    echo "Migration 006_employee_shifts applied successfully.\n";
} catch (Throwable $e) {
    error_log('Migration 006_employee_shifts failed: ' . $e->getMessage());
    fwrite(STDERR, "Migration failed. Review the server error log for details.\n");
    exit(1);
}
