<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';

function leaveBalanceMigrationColumnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $check = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = ?');
    $check->execute(['004_leave_balances']);
    if ((int)$check->fetchColumn() > 0) {
        echo "Migration 004_leave_balances is already applied.\n";
        exit(0);
    }

    if (!leaveBalanceMigrationColumnExists($pdo, 'leave_requests', 'request_unit')) {
        $pdo->exec("ALTER TABLE leave_requests ADD COLUMN request_unit ENUM('days','hours') NOT NULL DEFAULT 'days' AFTER end_date");
    }
    if (!leaveBalanceMigrationColumnExists($pdo, 'leave_requests', 'requested_minutes')) {
        $pdo->exec('ALTER TABLE leave_requests ADD COLUMN requested_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER request_unit');
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS leave_entitlement_policies (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        leave_type_id INT NOT NULL,
        effective_year SMALLINT UNSIGNED NOT NULL,
        annual_minutes INT UNSIGNED NOT NULL DEFAULT 0,
        updated_by INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_leave_policy_type_year (leave_type_id, effective_year),
        INDEX idx_leave_policy_year (effective_year),
        CONSTRAINT fk_leave_policy_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE RESTRICT,
        CONSTRAINT fk_leave_policy_admin FOREIGN KEY (updated_by) REFERENCES employees(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS leave_balance_adjustments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        leave_type_id INT NOT NULL,
        period_year SMALLINT UNSIGNED NOT NULL,
        adjustment_minutes INT NOT NULL,
        effective_date DATE NOT NULL,
        remarks VARCHAR(1000) NOT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_leave_adjustment_employee_year (employee_id, period_year),
        INDEX idx_leave_adjustment_type_year (leave_type_id, period_year),
        CONSTRAINT fk_leave_adjustment_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
        CONSTRAINT fk_leave_adjustment_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE RESTRICT,
        CONSTRAINT fk_leave_adjustment_admin FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS leave_request_charges (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        leave_request_id INT NOT NULL,
        charge_date DATE NOT NULL,
        minutes INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_leave_request_charge_date (leave_request_id, charge_date),
        INDEX idx_leave_charge_date (charge_date),
        CONSTRAINT fk_leave_charge_request FOREIGN KEY (leave_request_id) REFERENCES leave_requests(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $holidayStmt = $pdo->prepare('SELECT holiday_date FROM holidays WHERE holiday_date >= ? AND holiday_date <= ?');
    $requestStmt = $pdo->query('SELECT lr.id, lr.start_date, lr.end_date
        FROM leave_requests lr
        WHERE lr.requested_minutes = 0
           OR NOT EXISTS (SELECT 1 FROM leave_request_charges lrc WHERE lrc.leave_request_id = lr.id)
        ORDER BY lr.id');
    $insertCharge = $pdo->prepare('INSERT IGNORE INTO leave_request_charges (leave_request_id, charge_date, minutes)
        VALUES (?, ?, 480)');
    $updateTotal = $pdo->prepare('UPDATE leave_requests SET request_unit = "days", requested_minutes = ? WHERE id = ?');

    foreach ($requestStmt->fetchAll() as $request) {
        $start = new DateTimeImmutable((string)$request['start_date']);
        $end = new DateTimeImmutable((string)$request['end_date']);
        $holidayStmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
        $holidays = array_fill_keys($holidayStmt->fetchAll(PDO::FETCH_COLUMN), true);
        $totalMinutes = 0;
        $period = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));
        foreach ($period as $date) {
            $dateValue = $date->format('Y-m-d');
            if ((int)$date->format('N') > 5 || isset($holidays[$dateValue])) {
                continue;
            }
            $insertCharge->execute([(int)$request['id'], $dateValue]);
            $totalMinutes += 480;
        }
        $updateTotal->execute([$totalMinutes, (int)$request['id']]);
    }

    $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute(['004_leave_balances']);
    echo "Migration 004_leave_balances applied successfully.\n";
} catch (Throwable $e) {
    error_log('Migration 004_leave_balances failed: ' . $e->getMessage());
    fwrite(STDERR, "Migration failed. Review the server error log for details.\n");
    exit(1);
}
