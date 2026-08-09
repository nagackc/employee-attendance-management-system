<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';

$requiredDatabaseName = trim((string)(getenv('EAMS_DEMO_DATABASE_NAME') ?: ''));
$demoPassword = (string)(getenv('EAMS_DEMO_PASSWORD') ?: '');
$databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

if ((string)getenv('EAMS_ALLOW_DEMO_SEED') !== '1'
    || $requiredDatabaseName === ''
    || !hash_equals($requiredDatabaseName, $databaseName)) {
    fwrite(STDERR, "Demo seeding is disabled. Set EAMS_ALLOW_DEMO_SEED=1 and EAMS_DEMO_DATABASE_NAME to the exact disposable database name.\n");
    exit(1);
}
if (strlen($demoPassword) < 12 || strlen($demoPassword) > 255) {
    fwrite(STDERR, "EAMS_DEMO_PASSWORD must be between 12 and 255 characters.\n");
    exit(1);
}

$nonDemoCount = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE email NOT LIKE '%@example.test'")->fetchColumn();
if ($nonDemoCount > 0) {
    fwrite(STDERR, "Demo seeding refused because the database contains non-demo employee accounts.\n");
    exit(1);
}

$timezone = new DateTimeZone('America/New_York');
$today = new DateTimeImmutable('today', $timezone);
$now = new DateTimeImmutable('now', $timezone);
$passwordHash = password_hash($demoPassword, PASSWORD_DEFAULT);

$upsertEmployee = static function (array $employee) use ($pdo, $passwordHash): int {
    $statement = $pdo->prepare('INSERT INTO employees
        (first_name, middle_name, last_name, birthday, phone_number, address, email, password, company, role, active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), first_name = VALUES(first_name), middle_name = VALUES(middle_name),
            last_name = VALUES(last_name), birthday = VALUES(birthday), phone_number = VALUES(phone_number),
            address = VALUES(address), password = VALUES(password), company = VALUES(company), role = VALUES(role), active = 1');
    $statement->execute([
        $employee['first_name'], $employee['middle_name'], $employee['last_name'], $employee['birthday'],
        $employee['phone_number'], $employee['address'], $employee['email'], $passwordHash,
        $employee['company'], $employee['role'],
    ]);
    return (int)$pdo->lastInsertId();
};

$upsertAttendance = static function (int $employeeId, DateTimeImmutable $date, array $values) use ($pdo): int {
    $find = $pdo->prepare('SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ? AND voided_at IS NULL LIMIT 1');
    $find->execute([$employeeId, $date->format('Y-m-d')]);
    $attendanceId = (int)$find->fetchColumn();
    $parameters = [
        $values['time_in'], $values['time_out'], $values['break_start'], $values['break_end'],
        $values['break_minutes'], $values['total_hours'], $values['status'], $values['shift_id'],
        $values['shift_name'], $values['scheduled_workday'],
    ];
    if ($attendanceId > 0) {
        $update = $pdo->prepare('UPDATE attendance SET time_in = ?, time_out = ?, break_start = ?, break_end = ?,
            break_minutes = ?, total_hours = ?, status = ?, schedule_timezone = "America/New_York", shift_id = ?,
            shift_name = ?, scheduled_start_time = "08:00:00", scheduled_end_time = "17:00:00",
            grace_period_minutes = 15, scheduled_workday = ? WHERE id = ?');
        $update->execute([...$parameters, $attendanceId]);
        return $attendanceId;
    }
    $insert = $pdo->prepare('INSERT INTO attendance
        (employee_id, attendance_date, time_in, time_out, break_start, break_end, break_minutes, total_hours, status,
         schedule_timezone, shift_id, shift_name, scheduled_start_time, scheduled_end_time, grace_period_minutes, scheduled_workday)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "America/New_York", ?, ?, "08:00:00", "17:00:00", 15, ?)');
    $insert->execute([$employeeId, $date->format('Y-m-d'), ...$parameters]);
    return (int)$pdo->lastInsertId();
};

try {
    $pdo->beginTransaction();

    // The two explicit environment gates and the account-domain check above limit this reset to a disposable demo database.
    foreach ([
        'attendance_quick_breaks', 'attendance_correction_requests', 'employee_shift_assignments',
        'company_shift_assignments', 'leave_request_charges', 'leave_balance_adjustments',
        'leave_entitlement_policies', 'leave_requests', 'holidays', 'announcement_reads',
        'employee_notifications', 'announcements', 'audit_logs', 'attendance', 'work_shifts',
        'leave_types', 'login_attempts', 'employees',
    ] as $table) {
        $pdo->exec('DELETE FROM `' . $table . '`');
    }

    $settings = [
        'company_name' => 'EAMS Demo Company',
        'company_logo' => '',
        'timezone' => 'America/New_York',
        'work_start_time' => '08:00',
        'work_end_time' => '17:00',
        'grace_period_minutes' => '15',
        'demo_seed_version' => '1',
    ];
    $settingUpsert = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($settings as $key => $value) {
        $settingUpsert->execute([$key, $value]);
    }

    $adminId = $upsertEmployee([
        'first_name' => 'Avery', 'middle_name' => 'Quinn', 'last_name' => 'Morgan', 'birthday' => '1990-04-18',
        'phone_number' => '+1 555 010 1000', 'address' => '100 Example Avenue, Demo City',
        'email' => 'admin@example.test', 'company' => 'EAMS Demo Company', 'role' => 'admin',
    ]);
    $mayaId = $upsertEmployee([
        'first_name' => 'Maya', 'middle_name' => 'Lin', 'last_name' => 'Chen', 'birthday' => '1996-08-12',
        'phone_number' => '+1 555 010 1001', 'address' => '101 Example Avenue, Demo City',
        'email' => 'maya.chen@example.test', 'company' => 'Northstar Operations', 'role' => 'employee',
    ]);
    $jordanId = $upsertEmployee([
        'first_name' => 'Jordan', 'middle_name' => 'Alex', 'last_name' => 'Reyes', 'birthday' => '1994-02-27',
        'phone_number' => '+1 555 010 1002', 'address' => '102 Example Avenue, Demo City',
        'email' => 'jordan.reyes@example.test', 'company' => 'Summit Services', 'role' => 'employee',
    ]);
    $samId = $upsertEmployee([
        'first_name' => 'Sam', 'middle_name' => 'Ravi', 'last_name' => 'Patel', 'birthday' => '1998-11-03',
        'phone_number' => '+1 555 010 1003', 'address' => '103 Example Avenue, Demo City',
        'email' => 'sam.patel@example.test', 'company' => 'Northstar Operations', 'role' => 'employee',
    ]);

    $shift = $pdo->prepare('INSERT INTO work_shifts
        (name, timezone, start_time, end_time, grace_period_minutes, work_days, active, created_by)
        VALUES ("Standard Day", "America/New_York", "08:00:00", "17:00:00", 15, "1,2,3,4,5", 1, ?)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), active = 1, created_by = VALUES(created_by)');
    $shift->execute([$adminId]);
    $shiftId = (int)$pdo->lastInsertId();

    $assignment = $pdo->prepare('INSERT INTO company_shift_assignments
        (company, shift_id, effective_from, effective_to, created_by) VALUES (?, ?, ?, NULL, ?)
        ON DUPLICATE KEY UPDATE shift_id = VALUES(shift_id), effective_to = NULL, created_by = VALUES(created_by)');
    foreach (['Northstar Operations', 'Summit Services'] as $company) {
        $assignment->execute([$company, $shiftId, '2020-01-01', $adminId]);
    }

    $completedDays = [];
    for ($offset = 1; count($completedDays) < 7; $offset++) {
        $candidate = $today->modify('-' . $offset . ' day');
        if ((int)$candidate->format('N') <= 5) {
            $completedDays[] = $candidate;
        }
    }
    foreach ([$mayaId, $jordanId, $samId] as $employeeIndex => $employeeId) {
        foreach ($completedDays as $dayIndex => $date) {
            $lateMinutes = $employeeIndex === 1 && $dayIndex === 1 ? 27 : 0;
            $overtimeMinutes = $employeeIndex === 0 && $dayIndex === 2 ? 60 : 0;
            $timeIn = $date->setTime(8, $lateMinutes)->format('Y-m-d H:i:s');
            $timeOut = $date->setTime(17, $overtimeMinutes)->format('Y-m-d H:i:s');
            $attendanceId = $upsertAttendance($employeeId, $date, [
                'time_in' => $timeIn,
                'time_out' => $timeOut,
                'break_start' => $date->setTime(12, 0)->format('Y-m-d H:i:s'),
                'break_end' => $date->setTime(13, 0)->format('Y-m-d H:i:s'),
                'break_minutes' => 60,
                'total_hours' => 8 + ($overtimeMinutes / 60) - ($lateMinutes / 60),
                'status' => 'completed',
                'shift_id' => $shiftId,
                'shift_name' => 'Standard Day',
                'scheduled_workday' => 1,
            ]);
            if ($employeeIndex === 0 && $dayIndex === 0) {
                $quickBreak = $pdo->prepare('INSERT INTO attendance_quick_breaks
                    (attendance_id, started_at, ended_at, duration_seconds) VALUES (?, ?, ?, 600)');
                $quickBreak->execute([
                    $attendanceId,
                    $date->setTime(10, 15)->format('Y-m-d H:i:s'),
                    $date->setTime(10, 25)->format('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    $upsertAttendance($mayaId, $today, [
        'time_in' => $today->setTime(8, 4)->format('Y-m-d H:i:s'),
        'time_out' => null, 'break_start' => null, 'break_end' => null, 'break_minutes' => 0,
        'total_hours' => 0, 'status' => 'currently_working', 'shift_id' => $shiftId,
        'shift_name' => 'Standard Day', 'scheduled_workday' => (int)$today->format('N') <= 5 ? 1 : 0,
    ]);

    $leaveTypeUpsert = $pdo->prepare('INSERT INTO leave_types (name, active) VALUES (?, 1)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), active = 1');
    $leaveTypeIds = [];
    foreach (['Annual Leave', 'Sick Leave'] as $leaveTypeName) {
        $leaveTypeUpsert->execute([$leaveTypeName]);
        $leaveTypeIds[$leaveTypeName] = (int)$pdo->lastInsertId();
    }
    $policy = $pdo->prepare('INSERT INTO leave_entitlement_policies
        (leave_type_id, effective_year, annual_minutes, updated_by) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE annual_minutes = VALUES(annual_minutes), updated_by = VALUES(updated_by)');
    $policy->execute([$leaveTypeIds['Annual Leave'], (int)$today->format('Y'), 9600, $adminId]);
    $policy->execute([$leaveTypeIds['Sick Leave'], (int)$today->format('Y'), 4800, $adminId]);

    $leaveDate = $today->modify('+10 days');
    while ((int)$leaveDate->format('N') > 5) {
        $leaveDate = $leaveDate->modify('+1 day');
    }
    $leaveFind = $pdo->prepare('SELECT id FROM leave_requests WHERE employee_id = ? AND start_date = ? AND reason = ? LIMIT 1');
    $leaveFind->execute([$mayaId, $leaveDate->format('Y-m-d'), 'Family appointment']);
    $leaveId = (int)$leaveFind->fetchColumn();
    if ($leaveId === 0) {
        $leaveInsert = $pdo->prepare('INSERT INTO leave_requests
            (employee_id, leave_type_id, start_date, end_date, request_unit, requested_minutes, reason, status)
            VALUES (?, ?, ?, ?, "days", 480, "Family appointment", "pending")');
        $leaveInsert->execute([$mayaId, $leaveTypeIds['Annual Leave'], $leaveDate->format('Y-m-d'), $leaveDate->format('Y-m-d')]);
        $leaveId = (int)$pdo->lastInsertId();
    }
    $pdo->prepare('INSERT IGNORE INTO leave_request_charges (leave_request_id, charge_date, minutes) VALUES (?, ?, 480)')
        ->execute([$leaveId, $leaveDate->format('Y-m-d')]);

    $approvedLeaveDate = $today->modify('+13 days');
    while ((int)$approvedLeaveDate->format('N') > 5) {
        $approvedLeaveDate = $approvedLeaveDate->modify('+1 day');
    }
    $approvedLeave = $pdo->prepare('INSERT INTO leave_requests
        (employee_id, leave_type_id, start_date, end_date, request_unit, requested_minutes, reason, status,
         approved_by, action_date, admin_comment)
        VALUES (?, ?, ?, ?, "days", 480, "Personal day", "approved", ?, ?, "Approved for demo coverage planning.")');
    $approvedLeave->execute([
        $samId, $leaveTypeIds['Annual Leave'], $approvedLeaveDate->format('Y-m-d'),
        $approvedLeaveDate->format('Y-m-d'), $adminId, $now->format('Y-m-d H:i:s'),
    ]);
    $approvedLeaveId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO leave_request_charges (leave_request_id, charge_date, minutes) VALUES (?, ?, 480)')
        ->execute([$approvedLeaveId, $approvedLeaveDate->format('Y-m-d')]);

    $holidayDate = $today->modify('+21 days');
    $holiday = $pdo->prepare('INSERT INTO holidays (name, holiday_date, holiday_type, created_by)
        VALUES ("Demo Company Day", ?, "Company", ?)
        ON DUPLICATE KEY UPDATE holiday_type = VALUES(holiday_type), created_by = VALUES(created_by)');
    $holiday->execute([$holidayDate->format('Y-m-d'), $adminId]);

    $announcementTitle = 'Welcome to the EAMS demo';
    $announcementFind = $pdo->prepare('SELECT id FROM announcements WHERE title = ? LIMIT 1');
    $announcementFind->execute([$announcementTitle]);
    $announcementId = (int)$announcementFind->fetchColumn();
    if ($announcementId > 0) {
        $pdo->prepare('UPDATE announcements SET content = ?, priority = "important", status = "published",
            target_audience = "all", publish_date = ?, expiration_date = NULL, pinned = 1, allow_dismiss = 1 WHERE id = ?')
            ->execute(['Review your schedule, leave balance, and recent attendance from one place.', $now->format('Y-m-d H:i:s'), $announcementId]);
    } else {
        $pdo->prepare('INSERT INTO announcements
            (title, content, priority, status, target_audience, publish_date, pinned, allow_dismiss, created_by)
            VALUES (?, ?, "important", "published", "all", ?, 1, 1, ?)')
            ->execute([$announcementTitle, 'Review your schedule, leave balance, and recent attendance from one place.', $now->format('Y-m-d H:i:s'), $adminId]);
    }

    $notificationFind = $pdo->prepare('SELECT id FROM employee_notifications WHERE employee_id = ? AND title = ? LIMIT 1');
    $notificationFind->execute([$mayaId, 'Leave request submitted']);
    if (!$notificationFind->fetchColumn()) {
        $pdo->prepare('INSERT INTO employee_notifications (employee_id, title, message, is_read)
            VALUES (?, "Leave request submitted", "Your annual leave request is waiting for review.", 0)')->execute([$mayaId]);
    }

    $correctionDate = $completedDays[3];
    $correctionFind = $pdo->prepare('SELECT id FROM attendance_correction_requests
        WHERE employee_id = ? AND attendance_date = ? AND status = "pending" LIMIT 1');
    $correctionFind->execute([$jordanId, $correctionDate->format('Y-m-d')]);
    if (!$correctionFind->fetchColumn()) {
        $attendanceFind = $pdo->prepare('SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ? AND voided_at IS NULL LIMIT 1');
        $attendanceFind->execute([$jordanId, $correctionDate->format('Y-m-d')]);
        $attendanceId = (int)$attendanceFind->fetchColumn();
        $pdo->prepare('INSERT INTO attendance_correction_requests
            (attendance_id, employee_id, request_kind, attendance_date, requested_time_in, requested_time_out,
             reason, status, requested_schedule)
            VALUES (?, ?, "existing_record", ?, ?, ?, "Forgot to record the final hour of the shift.", "pending", ?)')
            ->execute([
                $attendanceId, $jordanId, $correctionDate->format('Y-m-d'),
                $correctionDate->setTime(8, 0)->format('Y-m-d H:i:s'),
                $correctionDate->setTime(18, 0)->format('Y-m-d H:i:s'),
                json_encode(['timezone' => 'America/New_York', 'start_time' => '08:00', 'end_time' => '17:00']),
            ]);
    }

    $auditFind = $pdo->prepare('SELECT id FROM audit_logs WHERE action = "seed_demo_data" AND admin_id = ? LIMIT 1');
    $auditFind->execute([$adminId]);
    if (!$auditFind->fetchColumn()) {
        $pdo->prepare('INSERT INTO audit_logs (admin_id, action, details, object_type, object_id)
            VALUES (?, "seed_demo_data", "Created fictional portfolio demonstration data.", "system", NULL)')->execute([$adminId]);
    }

    $pdo->commit();
    echo "Fictional demo data is ready.\n";
    echo "Admin: admin@example.test\nEmployee: maya.chen@example.test\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('EAMS demo seed failed: ' . $e->getMessage());
    fwrite(STDERR, "Demo seeding failed. Review the server log for details.\n");
    exit(1);
}
