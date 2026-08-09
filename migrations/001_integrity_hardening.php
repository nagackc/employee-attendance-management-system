<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';

function migrationTableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function migrationColumnInfo(PDO $pdo, string $table, string $column): ?array {
    $stmt = $pdo->prepare('SELECT DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function migrationColumnExists(PDO $pdo, string $table, string $column): bool {
    return migrationColumnInfo($pdo, $table, $column) !== null;
}

function migrationIndexExists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function migrationAddColumn(PDO $pdo, string $table, string $column, string $definition): void {
    if (!migrationColumnExists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function migrationReplaceForeignKey(PDO $pdo, string $table, string $column, string $definition): void {
    $stmt = $pdo->prepare('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL');
    $stmt->execute([$table, $column]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $constraint) {
        $pdo->exec("ALTER TABLE `$table` DROP FOREIGN KEY `" . str_replace('`', '``', (string)$constraint) . "`");
    }
    $pdo->exec("ALTER TABLE `$table` ADD $definition");
}

function migrationConvertAttendanceTime(PDO $pdo, string $column, string $dateExpression): void {
    $info = migrationColumnInfo($pdo, 'attendance', $column);
    $temporary = $column . '_dt_migration';
    if ($info === null && migrationColumnExists($pdo, 'attendance', $temporary)) {
        $pdo->exec("ALTER TABLE attendance CHANGE COLUMN `$temporary` `$column` DATETIME NULL");
        return;
    }
    if ($info === null || strtolower((string)$info['DATA_TYPE']) !== 'time') {
        if (migrationColumnExists($pdo, 'attendance', $temporary)) {
            $pdo->exec("ALTER TABLE attendance DROP COLUMN `$temporary`");
        }
        return;
    }

    migrationAddColumn($pdo, 'attendance', $temporary, 'DATETIME NULL');
    $pdo->exec("UPDATE attendance SET `$temporary` = CASE WHEN `$column` IS NULL THEN NULL ELSE $dateExpression END");
    $pdo->exec("ALTER TABLE attendance DROP COLUMN `$column`");
    $pdo->exec("ALTER TABLE attendance CHANGE COLUMN `$temporary` `$column` DATETIME NULL");
}

try {
    if (!migrationTableExists($pdo, 'employees') || !migrationTableExists($pdo, 'attendance')) {
        throw new RuntimeException('Base tables are missing. Use install.php for a new installation.');
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $check = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = ?');
    $check->execute(['001_integrity_hardening']);
    if ((int)$check->fetchColumn() > 0) {
        echo "Migration 001_integrity_hardening is already applied.\n";
        exit(0);
    }

    migrationAddColumn($pdo, 'employees', 'active', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER role');
    migrationAddColumn($pdo, 'employees', 'deactivated_at', 'DATETIME NULL AFTER active');
    migrationAddColumn($pdo, 'employees', 'deactivated_by', 'INT NULL AFTER deactivated_at');
    migrationAddColumn($pdo, 'employees', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
    if (!migrationIndexExists($pdo, 'employees', 'idx_employees_role_active')) {
        $pdo->exec('ALTER TABLE employees ADD INDEX idx_employees_role_active (role, active)');
    }

    migrationConvertAttendanceTime($pdo, 'time_in', 'TIMESTAMP(attendance_date, time_in)');
    migrationConvertAttendanceTime($pdo, 'time_out', 'TIMESTAMP(DATE_ADD(attendance_date, INTERVAL IF(time_in IS NOT NULL AND time_out < TIME(time_in), 1, 0) DAY), time_out)');
    migrationConvertAttendanceTime($pdo, 'break_start', 'TIMESTAMP(DATE_ADD(attendance_date, INTERVAL IF(time_in IS NOT NULL AND break_start < TIME(time_in), 1, 0) DAY), break_start)');
    migrationConvertAttendanceTime($pdo, 'break_end', 'TIMESTAMP(DATE_ADD(attendance_date, INTERVAL IF(COALESCE(break_start, time_in) IS NOT NULL AND break_end < TIME(COALESCE(break_start, time_in)), 1, 0) DAY), break_end)');

    migrationAddColumn($pdo, 'attendance', 'schedule_timezone', "VARCHAR(64) NOT NULL DEFAULT 'America/New_York' AFTER status");
    migrationAddColumn($pdo, 'attendance', 'scheduled_start_time', "TIME NOT NULL DEFAULT '08:00:00' AFTER schedule_timezone");
    migrationAddColumn($pdo, 'attendance', 'scheduled_end_time', "TIME NOT NULL DEFAULT '17:00:00' AFTER scheduled_start_time");
    migrationAddColumn($pdo, 'attendance', 'grace_period_minutes', 'INT NOT NULL DEFAULT 15 AFTER scheduled_end_time');
    migrationAddColumn($pdo, 'attendance', 'voided_at', 'DATETIME NULL AFTER grace_period_minutes');
    migrationAddColumn($pdo, 'attendance', 'voided_by', 'INT NULL AFTER voided_at');
    migrationAddColumn($pdo, 'attendance', 'void_reason', 'VARCHAR(1000) NULL AFTER voided_by');
    migrationAddColumn($pdo, 'attendance', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');

    $settingStmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $setting = static function (string $key, string $default) use ($settingStmt): string {
        $settingStmt->execute([$key]);
        $value = $settingStmt->fetchColumn();
        return $value !== false && trim((string)$value) !== '' ? (string)$value : $default;
    };
    $timezone = $setting('timezone', 'America/New_York');
    $workStart = $setting('work_start_time', '08:00');
    $workEnd = $setting('work_end_time', '17:00');
    $grace = max(0, (int)$setting('grace_period_minutes', '15'));
    $backfill = $pdo->prepare('UPDATE attendance SET schedule_timezone = ?, scheduled_start_time = ?, scheduled_end_time = ?, grace_period_minutes = ?');
    $backfill->execute([$timezone, $workStart, $workEnd, $grace]);
    $pdo->exec("UPDATE attendance SET status = CASE WHEN time_out IS NULL THEN 'currently_working' ELSE 'completed' END WHERE status = 'late'");
    if (!migrationIndexExists($pdo, 'attendance', 'idx_attendance_open')) {
        $pdo->exec('ALTER TABLE attendance ADD INDEX idx_attendance_open (employee_id, time_out, voided_at)');
    }
    if (!migrationIndexExists($pdo, 'attendance', 'idx_attendance_date_voided')) {
        $pdo->exec('ALTER TABLE attendance ADD INDEX idx_attendance_date_voided (attendance_date, voided_at)');
    }

    migrationAddColumn($pdo, 'audit_logs', 'old_values', 'LONGTEXT NULL AFTER details');
    migrationAddColumn($pdo, 'audit_logs', 'new_values', 'LONGTEXT NULL AFTER old_values');
    migrationAddColumn($pdo, 'audit_logs', 'object_type', 'VARCHAR(80) NULL AFTER new_values');
    migrationAddColumn($pdo, 'audit_logs', 'object_id', 'INT NULL AFTER object_type');
    if (!migrationIndexExists($pdo, 'audit_logs', 'idx_audit_object')) {
        $pdo->exec('ALTER TABLE audit_logs ADD INDEX idx_audit_object (object_type, object_id)');
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email_hash CHAR(64) NOT NULL,
        ip_hash CHAR(64) NOT NULL,
        attempt_count INT NOT NULL DEFAULT 0,
        window_started DATETIME NOT NULL,
        blocked_until DATETIME NULL,
        last_attempt_at DATETIME NOT NULL,
        UNIQUE KEY uniq_login_identity (email_hash, ip_hash),
        INDEX idx_login_blocked_until (blocked_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $defaultSettings = [
        'company_name' => 'EAMS Demo Company',
        'company_logo' => '',
        'timezone' => 'America/New_York',
        'work_start_time' => '08:00',
        'work_end_time' => '17:00',
        'grace_period_minutes' => '15',
        'installation_complete' => '1',
    ];
    $upsert = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key)');
    foreach ($defaultSettings as $key => $value) {
        $upsert->execute([$key, $value]);
    }

    migrationReplaceForeignKey($pdo, 'attendance', 'employee_id', 'CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT');
    migrationReplaceForeignKey($pdo, 'attendance', 'voided_by', 'CONSTRAINT fk_attendance_voided_by FOREIGN KEY (voided_by) REFERENCES employees(id) ON DELETE RESTRICT');
    migrationReplaceForeignKey($pdo, 'announcements', 'created_by', 'CONSTRAINT fk_announcements_creator FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE RESTRICT');
    migrationReplaceForeignKey($pdo, 'announcement_reads', 'employee_id', 'CONSTRAINT fk_announcement_reads_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT');
    migrationReplaceForeignKey($pdo, 'employee_notifications', 'employee_id', 'CONSTRAINT fk_notifications_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT');
    migrationReplaceForeignKey($pdo, 'leave_requests', 'employee_id', 'CONSTRAINT fk_leave_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT');
    migrationReplaceForeignKey($pdo, 'leave_requests', 'approved_by', 'CONSTRAINT fk_leave_approver FOREIGN KEY (approved_by) REFERENCES employees(id) ON DELETE RESTRICT');
    migrationReplaceForeignKey($pdo, 'audit_logs', 'admin_id', 'CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES employees(id) ON DELETE RESTRICT');
    migrationReplaceForeignKey($pdo, 'audit_logs', 'affected_employee_id', 'CONSTRAINT fk_audit_employee FOREIGN KEY (affected_employee_id) REFERENCES employees(id) ON DELETE RESTRICT');

    $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute(['001_integrity_hardening']);
    echo "Migration 001_integrity_hardening applied successfully.\n";
} catch (Throwable $e) {
    error_log('Migration 001_integrity_hardening failed: ' . $e->getMessage());
    fwrite(STDERR, "Migration failed. Review the server error log for details.\n");
    exit(1);
}
