<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/config/database.php';

function installerEnvironmentValue(string $key, string $default = ''): string {
    $value = getenv($key);
    return $value === false ? $default : trim((string)$value);
}

$adminEmail = strtolower(installerEnvironmentValue('EAMS_ADMIN_EMAIL'));
$adminPasswordPlaintext = installerEnvironmentValue('EAMS_ADMIN_PASSWORD');
$adminFirstName = installerEnvironmentValue('EAMS_ADMIN_FIRST_NAME', 'Admin');
$adminLastName = installerEnvironmentValue('EAMS_ADMIN_LAST_NAME', 'User');
$adminCompany = installerEnvironmentValue('EAMS_ADMIN_COMPANY', 'EAMS Demo Company');

if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "EAMS_ADMIN_EMAIL must contain a valid administrator email address.\n");
    exit(1);
}
if (strlen($adminPasswordPlaintext) < 12 || strlen($adminPasswordPlaintext) > 255) {
    fwrite(STDERR, "EAMS_ADMIN_PASSWORD must be between 12 and 255 characters.\n");
    exit(1);
}
if ($adminFirstName === '' || $adminLastName === '' || $adminCompany === '') {
    fwrite(STDERR, "Administrator name and company values must not be empty.\n");
    exit(1);
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    $sql = 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

$sql = [];
$sql[] = "CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    birthday DATE NULL,
    phone_number VARCHAR(50) NULL,
    address TEXT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    company VARCHAR(150) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'employee',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

$sql[] = "CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL
)";

$sql[] = "CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    priority ENUM('normal','important','urgent') NOT NULL DEFAULT 'normal',
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    target_audience VARCHAR(180) NOT NULL DEFAULT 'all',
    publish_date DATETIME NULL,
    expiration_date DATETIME NULL,
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    allow_dismiss TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_announcements_visibility (status, publish_date, expiration_date),
    INDEX idx_announcements_priority (pinned, priority),
    FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE RESTRICT
)";

$sql[] = "CREATE TABLE IF NOT EXISTS announcement_reads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    announcement_id INT NOT NULL,
    employee_id INT NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dismissed TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_announcement_employee (announcement_id, employee_id),
    INDEX idx_announcement_reads_employee (employee_id, read_at),
    FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT
)";

$sql[] = "CREATE TABLE IF NOT EXISTS employee_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    title VARCHAR(180) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_employee_read (employee_id, is_read, created_at),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT
)";

$sql[] = "CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action VARCHAR(150) NOT NULL,
    affected_employee_id INT NULL,
    details TEXT NULL,
    old_values LONGTEXT NULL,
    new_values LONGTEXT NULL,
    object_type VARCHAR(80) NULL,
    object_id INT NULL,
    ip_address VARCHAR(64) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_admin (admin_id),
    INDEX idx_audit_employee (affected_employee_id),
    INDEX idx_audit_object (object_type, object_id),
    INDEX idx_audit_created (created_at, id),
    INDEX idx_audit_action_created (action, created_at),
    FOREIGN KEY (admin_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (affected_employee_id) REFERENCES employees(id) ON DELETE RESTRICT
)";

$sql[] = "CREATE TABLE IF NOT EXISTS work_shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    timezone VARCHAR(100) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    grace_period_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    work_days VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE RESTRICT
)";

$sql[] = "CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    time_in DATETIME NULL,
    time_out DATETIME NULL,
    break_start DATETIME NULL,
    break_end DATETIME NULL,
    break_minutes INT NOT NULL DEFAULT 0,
    total_hours DECIMAL(6,2) DEFAULT 0.00,
    status VARCHAR(30) NOT NULL DEFAULT 'not_started',
    schedule_timezone VARCHAR(100) NOT NULL DEFAULT 'America/New_York',
    shift_id INT NULL,
    shift_name VARCHAR(120) NULL,
    scheduled_start_time TIME NOT NULL DEFAULT '08:00:00',
    scheduled_end_time TIME NOT NULL DEFAULT '17:00:00',
    grace_period_minutes INT NOT NULL DEFAULT 15,
    scheduled_workday TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_attendance_employee_day (employee_id, attendance_date),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (shift_id) REFERENCES work_shifts(id) ON DELETE RESTRICT
)";

$sql[] = "CREATE TABLE IF NOT EXISTS attendance_quick_breaks (
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
    FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE RESTRICT
)";

$sql[] = "CREATE TABLE IF NOT EXISTS attendance_correction_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    attendance_id INT NULL,
    employee_id INT NOT NULL,
    request_kind ENUM('existing_record','missing_record') NOT NULL,
    attendance_date DATE NOT NULL,
    original_values LONGTEXT NULL,
    requested_schedule LONGTEXT NULL,
    requested_time_in DATETIME NOT NULL,
    requested_time_out DATETIME NULL,
    requested_break_start DATETIME NULL,
    requested_break_end DATETIME NULL,
    reason VARCHAR(1000) NOT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    admin_comment VARCHAR(1000) NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    pending_scope_key VARCHAR(80) GENERATED ALWAYS AS (IF(status = 'pending', CONCAT(employee_id, ':', attendance_date), NULL)) STORED,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pending_employee_date (pending_scope_key),
    INDEX idx_correction_status_created (status, created_at),
    INDEX idx_correction_employee_status (employee_id, status),
    INDEX idx_correction_attendance (attendance_id),
    FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE RESTRICT,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (reviewed_by) REFERENCES employees(id) ON DELETE RESTRICT
)";

$sql[] = "CREATE TABLE IF NOT EXISTS employee_shift_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    shift_id INT NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_employee_shift_start (employee_id, effective_from),
    INDEX idx_employee_shift_period (employee_id, effective_from, effective_to),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (shift_id) REFERENCES work_shifts(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE RESTRICT
)";

$sql[] = "CREATE TABLE IF NOT EXISTS company_shift_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company VARCHAR(150) NOT NULL,
    shift_id INT NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_company_shift_start (company, effective_from),
    INDEX idx_company_shift_period (company, effective_from, effective_to),
    FOREIGN KEY (shift_id) REFERENCES work_shifts(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE RESTRICT
)";

$sql[] = "CREATE TABLE IF NOT EXISTS leave_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

$sql[] = "CREATE TABLE IF NOT EXISTS leave_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    leave_type_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    request_unit ENUM('days','hours') NOT NULL DEFAULT 'days',
    requested_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    reason TEXT NOT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    approved_by INT NULL,
    action_date DATETIME NULL,
    admin_comment TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE RESTRICT,
    FOREIGN KEY (approved_by) REFERENCES employees(id) ON DELETE RESTRICT
)";

$sql[] = "CREATE TABLE IF NOT EXISTS holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    holiday_date DATE NOT NULL,
    holiday_type VARCHAR(80) NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_holiday_date_name (holiday_date, name),
    FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE RESTRICT
)";

$sql[] = "CREATE TABLE IF NOT EXISTS leave_entitlement_policies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    leave_type_id INT NOT NULL,
    effective_year SMALLINT UNSIGNED NOT NULL,
    annual_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    updated_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leave_policy_type_year (leave_type_id, effective_year),
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by) REFERENCES employees(id) ON DELETE RESTRICT
)";

$sql[] = "CREATE TABLE IF NOT EXISTS leave_balance_adjustments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    leave_type_id INT NOT NULL,
    period_year SMALLINT UNSIGNED NOT NULL,
    adjustment_minutes INT NOT NULL,
    effective_date DATE NOT NULL,
    remarks VARCHAR(1000) NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_leave_adjustment_employee_year (employee_id, period_year),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE RESTRICT
)";

$sql[] = "CREATE TABLE IF NOT EXISTS leave_request_charges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    leave_request_id INT NOT NULL,
    charge_date DATE NOT NULL,
    minutes INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leave_request_charge_date (leave_request_id, charge_date),
    INDEX idx_leave_charge_date (charge_date),
    FOREIGN KEY (leave_request_id) REFERENCES leave_requests(id) ON DELETE RESTRICT
)";

try {
    foreach ($sql as $statement) {
        $pdo->exec($statement);
    }

    if (!columnExists($pdo, 'employees', 'middle_name')) {
        $pdo->exec('ALTER TABLE employees ADD COLUMN middle_name VARCHAR(100) NULL AFTER first_name');
    }
    if (!columnExists($pdo, 'employees', 'birthday')) {
        $pdo->exec('ALTER TABLE employees ADD COLUMN birthday DATE NULL AFTER last_name');
    }
    if (!columnExists($pdo, 'employees', 'phone_number')) {
        $pdo->exec('ALTER TABLE employees ADD COLUMN phone_number VARCHAR(50) NULL AFTER birthday');
    }
    if (!columnExists($pdo, 'employees', 'address')) {
        $pdo->exec('ALTER TABLE employees ADD COLUMN address TEXT NULL AFTER phone_number');
    }
    $pdo->exec('ALTER TABLE employees MODIFY company VARCHAR(150) NOT NULL');

    $defaultSettings = [
        'company_name'         => 'EAMS Demo Company',
        'company_logo'         => '',
        'timezone'             => 'America/New_York',
        'work_start_time'      => '09:00',
        'work_end_time'        => '18:00',
        'grace_period_minutes' => '15',
    ];
    $upsert = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_key = setting_key');
    foreach ($defaultSettings as $key => $value) {
        $upsert->execute([$key, $value]);
    }

    if (!columnExists($pdo, 'attendance', 'break_start')) {
        $pdo->exec('ALTER TABLE attendance ADD COLUMN break_start DATETIME NULL AFTER time_out');
    }
    if (!columnExists($pdo, 'attendance', 'break_end')) {
        $pdo->exec('ALTER TABLE attendance ADD COLUMN break_end DATETIME NULL AFTER break_start');
    }
    if (!columnExists($pdo, 'attendance', 'break_minutes')) {
        $pdo->exec('ALTER TABLE attendance ADD COLUMN break_minutes INT NOT NULL DEFAULT 0 AFTER break_end');
    }
    if (!columnExists($pdo, 'leave_requests', 'request_unit')) {
        $pdo->exec("ALTER TABLE leave_requests ADD COLUMN request_unit ENUM('days','hours') NOT NULL DEFAULT 'days' AFTER end_date");
    }
    if (!columnExists($pdo, 'leave_requests', 'requested_minutes')) {
        $pdo->exec('ALTER TABLE leave_requests ADD COLUMN requested_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER request_unit');
    }

    $adminPassword = password_hash($adminPasswordPlaintext, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('SELECT id FROM employees WHERE email = ?');
    $stmt->execute([$adminEmail]);
    if (!$stmt->fetch()) {
        $pdo->prepare('INSERT INTO employees (first_name, last_name, email, password, company, role) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$adminFirstName, $adminLastName, $adminEmail, $adminPassword, $adminCompany, 'admin']);
    }

    echo "Database setup completed successfully for {$adminEmail}.\n";
} catch (Throwable $e) {
    error_log('EAMS setup failed: ' . $e->getMessage());
    fwrite(STDERR, "Database setup failed. Review the server log for details.\n");
    exit(1);
}
