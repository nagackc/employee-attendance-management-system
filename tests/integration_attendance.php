<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

$databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$expectedDatabaseName = trim((string)(getenv('EAMS_TEST_DATABASE_NAME') ?: ''));
if ($expectedDatabaseName === '' || !hash_equals($expectedDatabaseName, $databaseName)) {
    fwrite(STDERR, "Refusing to run integration tests without an exact EAMS_TEST_DATABASE_NAME match.\n");
    exit(1);
}

$passed = [];
$assert = static function (bool $condition, string $label) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $label);
    }
    $passed[] = $label;
};

try {
    $pdo->beginTransaction();
    $adminId = (int)$pdo->query('SELECT id FROM employees WHERE role = "admin" AND active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $leaveTypeId = (int)$pdo->query('SELECT id FROM leave_types WHERE active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $email = 'codex-test-' . bin2hex(random_bytes(6)) . '@example.test';
    $employeeInsert = $pdo->prepare('INSERT INTO employees
        (first_name, last_name, email, password, company, role, active, created_at)
        VALUES ("Codex", "Test", ?, ?, "Northstar Operations", "employee", 1, "2030-01-07 07:00:00")');
    $employeeInsert->execute([$email, password_hash('Temporary!123', PASSWORD_DEFAULT)]);
    $employeeId = (int)$pdo->lastInsertId();

    $schedule = [
        'timezone' => 'America/New_York',
        'work_start_time' => '08:00',
        'work_end_time' => '17:00',
        'grace_period_minutes' => 15,
    ];

    $timeIn = '2030-01-07 08:10:00';
    $insertAttendance = $pdo->prepare('INSERT INTO attendance
        (employee_id, attendance_date, time_in, status, schedule_timezone, scheduled_start_time, scheduled_end_time, grace_period_minutes)
        VALUES (?, "2030-01-07", ?, "currently_working", "America/New_York", "08:00", "17:00", 15)');
    $insertAttendance->execute([$employeeId, $timeIn]);
    $attendanceId = (int)$pdo->lastInsertId();
    $assert($attendanceId > 0, 'time in stores a Y-m-d attendance date');

    $duplicateRejected = false;
    try {
        $insertAttendance->execute([$employeeId, $timeIn]);
    } catch (PDOException $e) {
        $duplicateRejected = (string)$e->getCode() === '23000';
    }
    $assert($duplicateRejected, 'duplicate time-in is rejected by the unique constraint');

    $lunchOut = $pdo->prepare('UPDATE attendance SET break_start = "2030-01-07 12:00:00", status = "on_break"
        WHERE id = ? AND status = "currently_working" AND break_start IS NULL AND break_end IS NULL AND time_out IS NULL');
    $lunchOut->execute([$attendanceId]);
    $assert($lunchOut->rowCount() === 1, 'lunch out transitions working to on break');
    $lunchOut->execute([$attendanceId]);
    $assert($lunchOut->rowCount() === 0, 'duplicate lunch out is ignored conditionally');

    $lunchIn = $pdo->prepare('UPDATE attendance SET break_end = "2030-01-07 12:30:00", break_minutes = 30, status = "currently_working"
        WHERE id = ? AND status = "on_break" AND break_start = "2030-01-07 12:00:00" AND break_end IS NULL');
    $lunchIn->execute([$attendanceId]);
    $assert($lunchIn->rowCount() === 1, 'lunch in closes the active break');
    $lunchIn->execute([$attendanceId]);
    $assert($lunchIn->rowCount() === 0, 'duplicate lunch in is ignored conditionally');

    $timeline = validateAttendanceTimeline(
        '2030-01-07',
        new DateTimeImmutable('2030-01-07 08:10:00'),
        new DateTimeImmutable('2030-01-07 17:00:00'),
        new DateTimeImmutable('2030-01-07 12:00:00'),
        new DateTimeImmutable('2030-01-07 12:30:00'),
        'completed'
    );
    $assert($timeline['errors'] === [] && $timeline['break_minutes'] === 30, 'time-out calculation validates break boundaries');
    $timeOut = $pdo->prepare('UPDATE attendance SET time_out = "2030-01-07 17:00:00", total_hours = ?, status = "completed"
        WHERE id = ? AND status IN ("currently_working", "on_break") AND time_out IS NULL');
    $timeOut->execute([$timeline['total_hours'], $attendanceId]);
    $assert($timeOut->rowCount() === 1 && abs((float)$timeline['total_hours'] - 8.33) < 0.01, 'time out stores net hours after lunch');
    $timeOut->execute([$timeline['total_hours'], $attendanceId]);
    $assert($timeOut->rowCount() === 0, 'duplicate time out is ignored conditionally');

    $pdo->prepare('UPDATE attendance SET voided_at = NOW(), voided_by = ?, void_reason = "Integration test" WHERE id = ?')
        ->execute([$adminId, $attendanceId]);
    $insertAttendance->execute([$employeeId, $timeIn]);
    $replacementAttendanceId = (int)$pdo->lastInsertId();
    $assert($replacementAttendanceId > $attendanceId, 'voided attendance is retained while allowing a corrected active record');

    $quickStart = $pdo->prepare('INSERT INTO attendance_quick_breaks (attendance_id, started_at) VALUES (?, ?)');
    $quickStart->execute([$replacementAttendanceId, '2030-01-07 10:00:00']);
    $firstQuickBreakId = (int)$pdo->lastInsertId();
    $quickStatus = $pdo->prepare('UPDATE attendance SET status = "on_quick_break"
        WHERE id = ? AND status = "currently_working" AND time_out IS NULL');
    $quickStatus->execute([$replacementAttendanceId]);
    $assert($quickStatus->rowCount() === 1, 'quick break transitions working attendance to on quick break');

    $duplicateQuickRejected = false;
    try {
        $quickStart->execute([$replacementAttendanceId, '2030-01-07 10:01:00']);
    } catch (PDOException $e) {
        $duplicateQuickRejected = (string)$e->getCode() === '23000';
    }
    $assert($duplicateQuickRejected, 'database constraint rejects two open quick breaks');

    $lunchWhileQuick = $pdo->prepare('UPDATE attendance SET break_start = "2030-01-07 10:02:00", status = "on_break"
        WHERE id = ? AND status = "currently_working" AND break_start IS NULL');
    $lunchWhileQuick->execute([$replacementAttendanceId]);
    $assert($lunchWhileQuick->rowCount() === 0, 'lunch cannot start while a quick break is active');

    $closeQuick = $pdo->prepare('UPDATE attendance_quick_breaks SET ended_at = ?, duration_seconds = ?
        WHERE id = ? AND attendance_id = ? AND ended_at IS NULL');
    $closeQuick->execute(['2030-01-07 10:10:00', 600, $firstQuickBreakId, $replacementAttendanceId]);
    $pdo->prepare('UPDATE attendance SET status = "currently_working" WHERE id = ? AND status = "on_quick_break"')
        ->execute([$replacementAttendanceId]);

    $quickStart->execute([$replacementAttendanceId, '2030-01-07 11:00:00']);
    $secondQuickBreakId = (int)$pdo->lastInsertId();
    $closeQuick->execute(['2030-01-07 11:05:00', 300, $secondQuickBreakId, $replacementAttendanceId]);
    $quickTotals = $pdo->prepare('SELECT COUNT(*) AS break_count, SUM(duration_seconds) AS duration_seconds
        FROM attendance_quick_breaks WHERE attendance_id = ?');
    $quickTotals->execute([$replacementAttendanceId]);
    $quickTotal = $quickTotals->fetch();
    $assert((int)$quickTotal['break_count'] === 2 && (int)$quickTotal['duration_seconds'] === 900, 'multiple quick breaks are retained and accumulated');

    $paidQuickTimeline = validateAttendanceTimeline(
        '2030-01-07',
        new DateTimeImmutable('2030-01-07 08:10:00'),
        new DateTimeImmutable('2030-01-07 17:00:00'),
        null,
        null,
        'completed'
    );
    $assert($paidQuickTimeline['errors'] === [] && $paidQuickTimeline['total_hours'] === 8.83, 'paid quick breaks do not reduce total hours');

    $quickStart->execute([$replacementAttendanceId, '2030-01-07 16:55:00']);
    $openQuickBreakId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE attendance SET status = "on_quick_break" WHERE id = ? AND status = "currently_working"')
        ->execute([$replacementAttendanceId]);
    $closeQuick->execute(['2030-01-07 17:00:00', 300, $openQuickBreakId, $replacementAttendanceId]);
    $replacementTimeOut = $pdo->prepare('UPDATE attendance SET time_out = "2030-01-07 17:00:00", total_hours = ?, status = "completed"
        WHERE id = ? AND status = "on_quick_break" AND time_out IS NULL');
    $replacementTimeOut->execute([$paidQuickTimeline['total_hours'], $replacementAttendanceId]);
    $assert($replacementTimeOut->rowCount() === 1, 'time out can close an active quick break and complete attendance');

    $invalidTimeline = validateAttendanceTimeline(
        '2030-01-07',
        new DateTimeImmutable('2030-01-07 10:00:00'),
        new DateTimeImmutable('2030-01-07 09:00:00'),
        null,
        null,
        'completed'
    );
    $assert($invalidTimeline['errors'] !== [], 'time out before time in is rejected');

    $onTimeRow = ['attendance_date' => '2030-01-07', 'time_in' => '2030-01-07 08:15:00'];
    $lateRow = ['attendance_date' => '2030-01-07', 'time_in' => '2030-01-07 08:15:01'];
    $assert(!attendanceIsLate($onTimeRow, $schedule) && attendanceIsLate($lateRow, $schedule), 'lateness uses start time plus grace period');

    $overnight = validateAttendanceTimeline(
        '2030-01-07',
        new DateTimeImmutable('2030-01-07 22:00:00'),
        new DateTimeImmutable('2030-01-08 06:00:00'),
        null,
        null,
        'completed'
    );
    $assert($overnight['errors'] === [] && $overnight['total_hours'] === 8.0, 'overnight sessions calculate across calendar days');

    $assert(formatEmployeeDate('2030-01-07') === 'January 7, 2030', 'employee dates use full month, day, and year');
    $assert(formatEmployeeTime('2030-01-07 17:05:00', 'America/New_York') === '17:05', 'employee times use 24-hour hours and minutes');
    $assert(formatEmployeeTime(null, 'America/New_York') === '—', 'missing employee times use an em dash');

    $leaveInsert = $pdo->prepare('INSERT INTO leave_requests
        (employee_id, leave_type_id, start_date, end_date, reason, status) VALUES (?, ?, "2030-01-08", "2030-01-09", "Test", "pending")');
    $leaveInsert->execute([$employeeId, $leaveTypeId]);
    $leaveId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE leave_requests SET requested_minutes = 960 WHERE id = ?')->execute([$leaveId]);
    $chargeInsert = $pdo->prepare('INSERT INTO leave_request_charges (leave_request_id, charge_date, minutes) VALUES (?, ?, ?)');
    $chargeInsert->execute([$leaveId, '2030-01-08', 480]);
    $chargeInsert->execute([$leaveId, '2030-01-09', 480]);
    $overlap = $pdo->prepare('SELECT id FROM leave_requests WHERE employee_id = ? AND status IN ("pending", "approved")
        AND start_date <= "2030-01-10" AND end_date >= "2030-01-09" LIMIT 1');
    $overlap->execute([$employeeId]);
    $assert((int)$overlap->fetchColumn() === $leaveId, 'overlapping pending leave is detected');

    $approve = $pdo->prepare('UPDATE leave_requests SET status = "approved", approved_by = ? WHERE id = ? AND status = "pending"');
    $approve->execute([$adminId, $leaveId]);
    $assert($approve->rowCount() === 1, 'pending leave can be approved once');
    $approve->execute([$adminId, $leaveId]);
    $assert($approve->rowCount() === 0, 'duplicate leave approval is ignored conditionally');

    $holiday = $pdo->prepare('INSERT INTO holidays (name, holiday_date, holiday_type, created_by) VALUES (?, "2030-01-10", "Test", ?)');
    $holiday->execute(['Codex Test Holiday ' . bin2hex(random_bytes(3)), $adminId]);
    $absence = calculateScheduledAbsences($pdo, $employeeId, '2030-01-07', '2030-01-11', '2030-01-07', ['2030-01-07'], '2030-01-11');
    $assert($absence === ['scheduled_days' => 2, 'absent' => 1], 'report totals exclude weekends, holidays, and approved leave');

    $workingDates = getLeaveWorkingDates($pdo, '2030-01-07', '2030-01-11');
    $assert($workingDates === ['2030-01-07', '2030-01-08', '2030-01-09', '2030-01-11'], 'leave charges exclude weekends and company holidays');
    $crossYearDates = getLeaveWorkingDates($pdo, '2030-12-30', '2031-01-03');
    $assert(count($crossYearDates) === 5
        && count(array_filter($crossYearDates, static fn(string $date): bool => str_starts_with($date, '2030-'))) === 2
        && count(array_filter($crossYearDates, static fn(string $date): bool => str_starts_with($date, '2031-'))) === 3,
        'chargeable workdays split cleanly across annual periods');
    $hourlyCharges = calculateLeaveRequestCharges($pdo, 'hours', '2030-01-11', '2030-01-11', '2.5');
    $assert($hourlyCharges === ['2030-01-11' => 150], 'hourly leave creates one charge in 30-minute increments');
    $invalidHourlyRejected = false;
    try {
        calculateLeaveRequestCharges($pdo, 'hours', '2030-01-12', '2030-01-12', '8.5');
    } catch (RuntimeException $e) {
        $invalidHourlyRejected = true;
    }
    $weekendHourlyRejected = false;
    try {
        calculateLeaveRequestCharges($pdo, 'hours', '2030-01-12', '2030-01-12', '2');
    } catch (RuntimeException $e) {
        $weekendHourlyRejected = true;
    }
    $assert($invalidHourlyRejected && $weekendHourlyRejected, 'hourly leave rejects durations above eight hours and non-workdays');

    $policy = $pdo->prepare('INSERT INTO leave_entitlement_policies (leave_type_id, effective_year, annual_minutes, updated_by)
        VALUES (?, 2029, 4800, ?)');
    $policy->execute([$leaveTypeId, $adminId]);
    $assert(getLeavePolicyMinutes($pdo, $leaveTypeId, 2030) === 4800, 'latest prior entitlement policy carries into a new reset year');

    $adjustment = $pdo->prepare('INSERT INTO leave_balance_adjustments
        (employee_id, leave_type_id, period_year, adjustment_minutes, effective_date, remarks, created_by)
        VALUES (?, ?, 2030, 240, "2030-01-07", "Integration credit", ?)');
    $adjustment->execute([$employeeId, $leaveTypeId, $adminId]);
    $balance = getEmployeeLeaveBalance($pdo, $employeeId, $leaveTypeId, 2030);
    $assert($balance['annual_minutes'] === 4800 && $balance['adjustment_minutes'] === 240
        && $balance['used_minutes'] === 960 && $balance['available_minutes'] === 4080,
        'balance combines entitlement, immutable adjustments, and approved usage');

    $hourlyLeave = $pdo->prepare('INSERT INTO leave_requests
        (employee_id, leave_type_id, start_date, end_date, request_unit, requested_minutes, reason, status)
        VALUES (?, ?, "2030-01-14", "2030-01-14", "hours", 120, "Hourly test", "pending")');
    $hourlyLeave->execute([$employeeId, $leaveTypeId]);
    $hourlyLeaveId = (int)$pdo->lastInsertId();
    $chargeInsert->execute([$hourlyLeaveId, '2030-01-14', 120]);
    $balanceWithPending = getEmployeeLeaveBalance($pdo, $employeeId, $leaveTypeId, 2030);
    $assert($balanceWithPending['available_minutes'] === 4080 && $balanceWithPending['pending_minutes'] === 120
        && $balanceWithPending['projected_minutes'] === 3960,
        'pending hourly leave is shown separately without reducing available balance');
    $assert(formatLeaveMinutes(480, 'days') === '1' && formatLeaveMinutes(480, 'hours') === '8'
        && formatLeaveMinutes(30, 'hours') === '0.50', 'leave minutes convert consistently between days and hours');

    $negativeAdjustment = $pdo->prepare('INSERT INTO leave_balance_adjustments
        (employee_id, leave_type_id, period_year, adjustment_minutes, effective_date, remarks, created_by)
        VALUES (?, ?, 2031, -480, "2031-01-02", "Integration deduction", ?)');
    $negativeAdjustment->execute([$employeeId, $leaveTypeId, $adminId]);
    $negativeBalance = getEmployeeLeaveBalance($pdo, $employeeId, $leaveTypeId, 2031);
    $assert($negativeBalance['available_minutes'] === 4320, 'signed adjustments are retained in the selected annual period');

    $shiftInsert = $pdo->prepare('INSERT INTO work_shifts
        (name, timezone, start_time, end_time, grace_period_minutes, work_days, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    $shiftInsert->execute(['Codex Company Shift ' . bin2hex(random_bytes(3)), 'Asia/Manila', '09:00', '18:00', 10, '2,3,4,5,6', $adminId]);
    $companyShiftId = (int)$pdo->lastInsertId();
    $shiftInsert->execute(['Codex Night Shift ' . bin2hex(random_bytes(3)), 'Asia/Manila', '22:00', '06:00', 15, '1,2,3,4', $adminId]);
    $employeeShiftId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO company_shift_assignments
        (company, shift_id, effective_from, effective_to, created_by) VALUES ("Northstar Operations", ?, "2032-01-01", "2032-12-31", ?)')
        ->execute([$companyShiftId, $adminId]);
    $pdo->prepare('INSERT INTO employee_shift_assignments
        (employee_id, shift_id, effective_from, effective_to, created_by) VALUES (?, ?, "2032-01-01", "2032-01-31", ?)')
        ->execute([$employeeId, $employeeShiftId, $adminId]);

    $employeeShift = getEmployeeScheduleForDate($pdo, $employeeId, '2032-01-05');
    $assert($employeeShift['source'] === 'employee' && $employeeShift['shift_id'] === $employeeShiftId
        && $employeeShift['scheduled_workday'] === 1, 'employee shift overrides the company schedule on effective dates');
    $companyShift = getEmployeeScheduleForDate($pdo, $employeeId, '2032-02-07');
    $assert($companyShift['source'] === 'company' && $companyShift['shift_id'] === $companyShiftId
        && $companyShift['scheduled_workday'] === 1, 'company shift applies when no employee assignment is effective');
    $restDayShift = getEmployeeScheduleForDate($pdo, $employeeId, '2032-01-10');
    $assert($restDayShift['scheduled_workday'] === 0
        && !attendanceIsLate(['attendance_date' => '2032-01-10', 'time_in' => '2032-01-10 23:00:00', 'scheduled_workday' => 0], $schedule),
        'employee rest-day work is never marked late');
    $overnightContext = getEmployeeAttendanceContext(
        $pdo,
        $employeeId,
        new DateTimeImmutable('2032-01-06 02:00:00', new DateTimeZone('Asia/Manila'))
    );
    $assert($overnightContext['attendance_date'] === '2032-01-05'
        && $overnightContext['schedule']['shift_id'] === $employeeShiftId,
        'after-midnight work resolves to the prior overnight shift date');
    $customLeaveDates = getLeaveWorkingDates($pdo, '2032-01-05', '2032-01-11', $employeeId);
    $assert($customLeaveDates === ['2032-01-05', '2032-01-06', '2032-01-07', '2032-01-08'],
        'leave charges follow employee-specific workdays');

    $availabilityEmployeeStmt = $pdo->prepare('SELECT id, first_name, last_name, company, active, created_at, deactivated_at
        FROM employees WHERE id = ?');
    $availabilityEmployeeStmt->execute([$employeeId]);
    $availabilityEmployee = $availabilityEmployeeStmt->fetch();
    $availability = getTeamAvailability($pdo, [$availabilityEmployee], '2030-01-07', '2030-01-14', 70);
    $assert($availability['2030-01-08']['scheduled_count'] === 1
        && $availability['2030-01-08']['available_count'] === 0
        && $availability['2030-01-08']['approved_full_count'] === 1
        && $availability['2030-01-08']['warning_level'] === 'critical',
        'approved full-day leave reduces team availability and triggers coverage warnings');
    $assert($availability['2030-01-10']['scheduled_count'] === 0
        && $availability['2030-01-10']['warning_level'] === 'holiday',
        'team availability excludes company holidays from required staffing');
    $assert($availability['2030-01-14']['projected_count'] === 1
        && $availability['2030-01-14']['pending_partial_minutes'] === 120
        && $availability['2030-01-14']['staff'][0]['status'] === 'pending_partial',
        'pending hourly leave is visible without removing an employee from projected headcount');

    $shiftAvailability = getTeamAvailability($pdo, [$availabilityEmployee], '2032-01-05', '2032-01-05', 70);
    $assert($shiftAvailability['2032-01-05']['scheduled_count'] === 1
        && $shiftAvailability['2032-01-05']['staff'][0]['shift_time'] === '22:00–06:00',
        'team availability resolves employee-specific shifts in bulk');

    $formerEmployee = $availabilityEmployee;
    $formerEmployee['deactivated_at'] = '2030-01-08 17:00:00';
    $formerAvailability = getTeamAvailability($pdo, [$formerEmployee], '2030-01-08', '2030-01-09', 70);
    $assert($formerAvailability['2030-01-08']['scheduled_count'] === 1
        && $formerAvailability['2030-01-09']['scheduled_count'] === 0,
        'team availability stops staffing counts after an employee deactivation date');
    $inactiveWithoutDate = $availabilityEmployee;
    $inactiveWithoutDate['active'] = 0;
    $inactiveWithoutDate['deactivated_at'] = null;
    $inactiveAvailability = getTeamAvailability($pdo, [$inactiveWithoutDate], '2030-01-08', '2030-01-08', 70);
    $assert($inactiveAvailability['2030-01-08']['scheduled_count'] === 0,
        'inactive employees without a historical deactivation date do not inflate staffing totals');

    $payrollBase = [
        'attendance_date' => '2032-03-01',
        'time_in' => '2032-03-01 08:00:00',
        'time_out' => '2032-03-01 17:00:00',
        'break_minutes' => 60,
        'status' => 'completed',
        'schedule_timezone' => 'Asia/Manila',
        'scheduled_start_time' => '08:00:00',
        'scheduled_end_time' => '17:00:00',
        'grace_period_minutes' => 15,
        'scheduled_workday' => 1,
    ];
    $normalPayroll = calculateAttendancePayrollMetrics($payrollBase, $schedule);
    $assert($normalPayroll['regular_minutes'] === 480 && $normalPayroll['overtime_minutes'] === 0
        && $normalPayroll['late_minutes'] === 0 && $normalPayroll['undertime_minutes'] === 0,
        'normal completed shifts produce eight regular hours without payroll exceptions');

    $overtimePayroll = calculateAttendancePayrollMetrics(array_replace($payrollBase, [
        'time_out' => '2032-03-01 19:00:00',
    ]), $schedule);
    $assert($overtimePayroll['regular_minutes'] === 480 && $overtimePayroll['overtime_minutes'] === 120,
        'completed work beyond eight net hours is classified as overtime');

    $latePayroll = calculateAttendancePayrollMetrics(array_replace($payrollBase, [
        'time_in' => '2032-03-01 08:30:00',
    ]), $schedule);
    $assert($latePayroll['late_minutes'] === 30 && $latePayroll['undertime_minutes'] === 0,
        'late minutes respect grace and are not counted again as undertime');
    $gracePayroll = calculateAttendancePayrollMetrics(array_replace($payrollBase, [
        'time_in' => '2032-03-01 08:10:00',
    ]), $schedule);
    $assert($gracePayroll['late_minutes'] === 0 && $gracePayroll['undertime_minutes'] === 0,
        'arrival within the saved grace period is not late or undertime');

    $earlyOutPayroll = calculateAttendancePayrollMetrics(array_replace($payrollBase, [
        'time_out' => '2032-03-01 16:30:00',
    ]), $schedule);
    $longLunchPayroll = calculateAttendancePayrollMetrics(array_replace($payrollBase, [
        'break_minutes' => 90,
    ]), $schedule);
    $assert($earlyOutPayroll['undertime_minutes'] === 30 && $longLunchPayroll['undertime_minutes'] === 30,
        'early departures and excess unpaid lunch produce undertime');

    $holidayPayroll = calculateAttendancePayrollMetrics($payrollBase, $schedule, ['name' => 'Test Holiday', 'type' => 'Regular']);
    $restPayroll = calculateAttendancePayrollMetrics(array_replace($payrollBase, ['scheduled_workday' => 0]), $schedule);
    $assert($holidayPayroll['holiday_minutes'] === 480 && $holidayPayroll['overtime_minutes'] === 480
        && $holidayPayroll['regular_minutes'] === 0 && $restPayroll['rest_day_minutes'] === 480
        && $restPayroll['overtime_minutes'] === 480,
        'holiday and rest-day work are separated from regular hours and included in overtime');

    $overnightPayroll = calculateAttendancePayrollMetrics(array_replace($payrollBase, [
        'attendance_date' => '2032-03-02',
        'time_in' => '2032-03-02 22:00:00',
        'time_out' => '2032-03-03 06:00:00',
        'break_minutes' => 0,
        'scheduled_start_time' => '22:00:00',
        'scheduled_end_time' => '06:00:00',
    ]), $schedule);
    $assert($overnightPayroll['regular_minutes'] === 480 && $overnightPayroll['overtime_minutes'] === 0,
        'payroll calculations support overnight shift boundaries');

    $openPayroll = calculateAttendancePayrollMetrics(array_replace($payrollBase, [
        'time_in' => '2032-03-01 08:30:00', 'time_out' => null, 'status' => 'currently_working',
    ]), $schedule);
    $assert($openPayroll['complete'] === false && $openPayroll['late_minutes'] === 30
        && $openPayroll['regular_minutes'] === 0 && $openPayroll['overtime_minutes'] === 0,
        'open sessions show lateness without prematurely estimating completed hours');
    $assert(formatMinutesDuration(510) === '8h 30m' && formatMinutesDuration(0) === '0m',
        'payroll minutes use compact readable duration labels');

    $correctionInsert = $pdo->prepare('INSERT INTO attendance_correction_requests
        (attendance_id, employee_id, request_kind, attendance_date, original_values, requested_schedule,
         requested_time_in, requested_time_out, reason)
        VALUES (?, ?, "existing_record", "2030-01-07", ?, ?, "2030-01-07 08:00:00", "2030-01-07 18:00:00", "Correct test hours")');
    $correctionScheduleJson = json_encode(['timezone' => 'America/New_York', 'shift_name' => 'Historical Shift']);
    $correctionInsert->execute([$replacementAttendanceId, $employeeId, json_encode(['time_in' => '2030-01-07 08:10:00']), $correctionScheduleJson]);
    $correctionId = (int)$pdo->lastInsertId();
    $savedCorrectionSchedule = $pdo->query('SELECT requested_schedule FROM attendance_correction_requests WHERE id = ' . $correctionId)->fetchColumn();
    $assert($correctionId > 0 && $savedCorrectionSchedule === $correctionScheduleJson,
        'employee attendance correction requests retain proposed values and their shift snapshot');

    $duplicateCorrectionRejected = false;
    try {
        $correctionInsert->execute([$replacementAttendanceId, $employeeId, '{}', $correctionScheduleJson]);
    } catch (PDOException $e) {
        $duplicateCorrectionRejected = (string)$e->getCode() === '23000';
    }
    $assert($duplicateCorrectionRejected, 'only one pending attendance correction is allowed per employee date');

    $correctionCalculation = validateAttendanceTimeline(
        '2030-01-07',
        new DateTimeImmutable('2030-01-07 08:00:00'),
        new DateTimeImmutable('2030-01-07 18:00:00'),
        null,
        null,
        'completed'
    );
    $pdo->prepare('UPDATE attendance SET time_in = "2030-01-07 08:00:00", time_out = "2030-01-07 18:00:00",
        break_start = NULL, break_end = NULL, break_minutes = ?, total_hours = ?, status = "completed" WHERE id = ?')
        ->execute([$correctionCalculation['break_minutes'], $correctionCalculation['total_hours'], $replacementAttendanceId]);
    $pdo->prepare('UPDATE attendance_correction_requests SET status = "approved", reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ? AND status = "pending"')->execute([$adminId, $correctionId]);
    $correctedHours = (float)$pdo->query('SELECT total_hours FROM attendance WHERE id = ' . $replacementAttendanceId)->fetchColumn();
    $assert($correctedHours === 10.0, 'approved attendance corrections recalculate net worked hours');

    $missingCorrection = $pdo->prepare('INSERT INTO attendance_correction_requests
        (attendance_id, employee_id, request_kind, attendance_date, requested_time_in, requested_time_out, reason)
        VALUES (NULL, ?, "missing_record", "2030-01-15", "2030-01-15 08:00:00", "2030-01-15 17:00:00", "Missing record test")');
    $missingCorrection->execute([$employeeId]);
    $missingCorrectionId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE attendance_correction_requests SET status = "cancelled" WHERE id = ? AND employee_id = ? AND status = "pending"')
        ->execute([$missingCorrectionId, $employeeId]);
    $missingStatus = $pdo->query('SELECT status FROM attendance_correction_requests WHERE id = ' . $missingCorrectionId)->fetchColumn();
    $assert($missingStatus === 'cancelled', 'employees can cancel pending missing-record requests');

    $validProfile = validateEmployeeContactProfile('  Profile.TEST@Example.test ', '+63 917 123 4567', 'Test address');
    $invalidProfile = validateEmployeeContactProfile('not-an-email', '', '');
    $assert($validProfile['valid'] === true
        && $validProfile['email'] === 'profile.test@example.test'
        && $invalidProfile['valid'] === false
        && count($invalidProfile['errors']) === 3,
        'employee profile contact details are normalized and validated');

    $profilePasswordHash = password_hash('CurrentPass123!', PASSWORD_DEFAULT);
    $invalidPasswordChange = validateEmployeePasswordChange($profilePasswordHash, 'wrong', 'short', 'different');
    $validPasswordChange = validateEmployeePasswordChange($profilePasswordHash, 'CurrentPass123!', 'NewPass456!', 'NewPass456!');
    $assert($invalidPasswordChange['valid'] === false && count($invalidPasswordChange['errors']) === 3
        && $validPasswordChange['valid'] === true,
        'employee password changes require the current password, minimum length, and matching confirmation');

    $profileUpdate = $pdo->prepare('UPDATE employees SET phone_number = ?, address = ? WHERE id = ? AND active = 1');
    $profileUpdate->execute([$validProfile['phone_number'], $validProfile['address'], $employeeId]);
    $savedProfileStmt = $pdo->prepare('SELECT phone_number, address FROM employees WHERE id = ?');
    $savedProfileStmt->execute([$employeeId]);
    $savedProfile = $savedProfileStmt->fetch();
    $assert($profileUpdate->rowCount() === 1
        && $savedProfile['phone_number'] === '+63 917 123 4567'
        && $savedProfile['address'] === 'Test address',
        'employee contact updates remain scoped to the authenticated active account');

    $newProfilePasswordHash = password_hash('NewPass456!', PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE employees SET password = ? WHERE id = ? AND active = 1')->execute([$newProfilePasswordHash, $employeeId]);
    $assert(password_verify('NewPass456!', (string)$pdo->query('SELECT password FROM employees WHERE id = ' . $employeeId)->fetchColumn()),
        'employee password updates are stored as secure hashes');

    $alertSchedule = [
        'timezone' => 'America/New_York',
        'work_start_time' => '08:00',
        'work_end_time' => '17:00',
        'grace_period_minutes' => 15,
    ];
    $notStartedAlertRow = array_replace(attendanceDefault('2030-01-07'), [
        'schedule_timezone' => 'America/New_York',
        'scheduled_start_time' => '08:00:00',
        'scheduled_end_time' => '17:00:00',
        'grace_period_minutes' => 15,
        'scheduled_workday' => 1,
    ]);
    $missedAlerts = buildAttendanceDashboardAlerts(
        $notStartedAlertRow,
        $alertSchedule,
        new DateTimeImmutable('2030-01-07 18:00:00', new DateTimeZone('America/New_York'))
    );
    $holidayAlerts = buildAttendanceDashboardAlerts(
        $notStartedAlertRow,
        $alertSchedule,
        new DateTimeImmutable('2030-01-07 18:00:00', new DateTimeZone('America/New_York')),
        ['2030-01-07' => ['name' => 'Test Holiday', 'type' => 'Regular']]
    );
    $assert(($missedAlerts[0]['id'] ?? '') === 'missed-attendance-2030-01-07'
        && ($missedAlerts[0]['severity'] ?? '') === 'danger'
        && $holidayAlerts === [],
        'dashboard alerts flag missed scheduled attendance but suppress holiday reminders');

    $lunchAlertRow = array_replace($notStartedAlertRow, [
        'id' => 901,
        'status' => 'on_break',
        'time_in' => '2030-01-07 08:00:00',
        'break_start' => '2030-01-07 12:00:00',
    ]);
    $lunchAlerts = buildAttendanceDashboardAlerts(
        $lunchAlertRow,
        $alertSchedule,
        new DateTimeImmutable('2030-01-07 13:05:00', new DateTimeZone('America/New_York'))
    );
    $assert(str_starts_with((string)($lunchAlerts[0]['id'] ?? ''), 'lunch-overdue-901-')
        && ($lunchAlerts[0]['severity'] ?? '') === 'danger',
        'dashboard alerts elevate overdue lunches as non-dismissible urgent actions');

    $openShiftAlertRow = array_replace($notStartedAlertRow, [
        'id' => 902,
        'status' => 'currently_working',
        'time_in' => '2030-01-07 08:00:00',
    ]);
    $endedShiftAlerts = buildAttendanceDashboardAlerts(
        $openShiftAlertRow,
        $alertSchedule,
        new DateTimeImmutable('2030-01-07 18:00:00', new DateTimeZone('America/New_York'))
    );
    $longShiftAlerts = buildAttendanceDashboardAlerts(
        $openShiftAlertRow,
        $alertSchedule,
        new DateTimeImmutable('2030-01-08 00:30:00', new DateTimeZone('America/New_York'))
    );
    $assert(($endedShiftAlerts[0]['severity'] ?? '') === 'warning'
        && str_starts_with((string)($endedShiftAlerts[0]['id'] ?? ''), 'shift-ended-902-')
        && ($longShiftAlerts[0]['severity'] ?? '') === 'danger'
        && str_starts_with((string)($longShiftAlerts[0]['id'] ?? ''), 'long-open-shift-902'),
        'dashboard alerts escalate ended and unusually long open work sessions');

    $databaseAlerts = getEmployeeDashboardAlerts(
        $pdo,
        $employeeId,
        $notStartedAlertRow,
        $alertSchedule,
        new DateTimeImmutable('2030-01-07 07:45:00', new DateTimeZone('America/New_York'))
    );
    $databaseAlertIds = array_column($databaseAlerts, 'id');
    $assert(count(array_filter($databaseAlertIds, static fn(string $id): bool => str_starts_with($id, 'pending-leave-'))) === 1
        && in_array('approved-leave-' . $leaveId, $databaseAlertIds, true)
        && in_array('upcoming-holiday-2030-01-10', $databaseAlertIds, true)
        && count(array_filter($databaseAlertIds, static fn(string $id): bool => str_starts_with($id, 'profile-incomplete-'))) === 1,
        'dashboard alert queries remain employee-scoped and combine pending leave, approved leave, holidays, and profile reminders');
    $approvedLeaveDayRow = array_replace($notStartedAlertRow, ['attendance_date' => '2030-01-08']);
    $approvedLeaveDayAlerts = getEmployeeDashboardAlerts(
        $pdo,
        $employeeId,
        $approvedLeaveDayRow,
        $alertSchedule,
        new DateTimeImmutable('2030-01-08 18:00:00', new DateTimeZone('America/New_York'))
    );
    $approvedLeaveDayIds = array_column($approvedLeaveDayAlerts, 'id');
    $assert(!in_array('missed-attendance-2030-01-08', $approvedLeaveDayIds, true)
        && in_array('approved-leave-' . $leaveId, $approvedLeaveDayIds, true),
        'approved full-day leave suppresses missing-attendance reminders while retaining the leave alert');

    $payrollExceptionInsert = $pdo->prepare('INSERT INTO attendance
        (employee_id, attendance_date, time_in, status, schedule_timezone, scheduled_start_time,
         scheduled_end_time, grace_period_minutes, scheduled_workday)
        VALUES (?, "2030-01-12", "2030-01-12 08:00:00", "currently_working", "America/New_York", "08:00", "17:00", 15, 1)');
    $payrollExceptionInsert->execute([$employeeId]);
    $payrollExceptionId = (int)$pdo->lastInsertId();
    $payrollDataset = buildPayrollExportDataset($pdo, '2030-01-07', '2030-01-14', $employeeId, 'Northstar Operations');
    $payrollSummary = $payrollDataset['summaries'][0] ?? [];
    $assert(count($payrollDataset['finalized_rows']) === 1
        && count($payrollDataset['exceptions']) === 1
        && (int)$payrollDataset['exceptions'][0]['id'] === $payrollExceptionId
        && $payrollDataset['exceptions'][0]['_exception_reason'] === 'Open or unfinished attendance status',
        'payroll exports include only finalized attendance and identify unfinished records for review');
    $assert(($payrollSummary['attendance_days'] ?? 0) === 1
        && ($payrollSummary['regular_minutes'] ?? 0) === 480
        && ($payrollSummary['overtime_minutes'] ?? 0) === 120
        && ($payrollSummary['quick_break_minutes'] ?? 0) === 20,
        'payroll summaries retain regular, overtime, and paid quick-break detail from finalized records');
    $assert(count($payrollDataset['approved_leave_rows']) === 2
        && ($payrollSummary['approved_leave_minutes'] ?? 0) === 960
        && ($payrollDataset['totals']['approved_leave_minutes'] ?? 0) === 960,
        'payroll exports include approved leave charges while excluding pending leave');
    $emptyPayrollScope = buildPayrollExportDataset($pdo, '2030-01-07', '2030-01-14', $employeeId, 'CPA');
    $assert($emptyPayrollScope['finalized_rows'] === []
        && $emptyPayrollScope['approved_leave_rows'] === []
        && csvSafeCell('=SUM(A1:A2)') === "'=SUM(A1:A2)"
        && csvSafeCell('Normal') === 'Normal',
        'payroll filters preserve company scope and CSV cells neutralize spreadsheet formulas');
    $pdo->prepare('DELETE FROM attendance WHERE id = ?')->execute([$payrollExceptionId]);

    $auditChanges = buildAuditChangeRows(
        json_encode(['email' => 'old@example.test', 'password' => 'old-hash', 'profile' => ['phone' => '100'], 'unchanged' => 'same']),
        json_encode(['email' => 'new@example.test', 'password' => 'new-hash', 'profile' => ['phone' => '200'], 'unchanged' => 'same'])
    );
    $auditChangesByField = [];
    foreach ($auditChanges as $auditChange) {
        $auditChangesByField[$auditChange['field']] = $auditChange;
    }
    $assert(($auditChangesByField['Password']['before'] ?? '') === '[redacted]'
        && ($auditChangesByField['Password']['after'] ?? '') === '[redacted]'
        && !isset($auditChangesByField['Unchanged']),
        'audit change rendering redacts sensitive values and omits unchanged fields');
    $assert(($auditChangesByField['Profile Phone']['before'] ?? '') === '100'
        && ($auditChangesByField['Profile Phone']['after'] ?? '') === '200',
        'audit change rendering flattens nested before-and-after values');
    $assert(formatAuditAction('approve_attendance_correction') === 'Approve Attendance Correction',
        'audit action identifiers use readable labels');

    createEmployeeNotification($pdo, $employeeId, 'Integration Notification', 'Test notification ownership.');
    $notificationId = (int)$pdo->lastInsertId();
    $wrongOwnerRead = $pdo->prepare('UPDATE employee_notifications SET is_read = 1 WHERE id = ? AND employee_id = ? AND is_read = 0');
    $wrongOwnerRead->execute([$notificationId, $adminId]);
    $correctOwnerRead = $pdo->prepare('UPDATE employee_notifications SET is_read = 1, read_at = NOW()
        WHERE id = ? AND employee_id = ? AND is_read = 0');
    $correctOwnerRead->execute([$notificationId, $employeeId]);
    $assert($wrongOwnerRead->rowCount() === 0 && $correctOwnerRead->rowCount() === 1, 'notifications can only be marked read by their owner');

    $deactivate = $pdo->prepare('UPDATE employees SET active = 0, deactivated_at = NOW(), deactivated_by = ? WHERE id = ? AND active = 1');
    $deactivate->execute([$adminId, $employeeId]);
    $attendanceCount = (int)$pdo->query('SELECT COUNT(*) FROM attendance WHERE employee_id = ' . $employeeId)->fetchColumn();
    $leaveCount = (int)$pdo->query('SELECT COUNT(*) FROM leave_requests WHERE employee_id = ' . $employeeId)->fetchColumn();
    $assert($deactivate->rowCount() === 1 && $attendanceCount === 2 && $leaveCount === 2, 'deactivation preserves attendance and leave history');

    $pdo->rollBack();
    foreach ($passed as $label) {
        echo "PASS: $label\n";
    }
    echo 'Completed ' . count($passed) . " integration assertions.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
