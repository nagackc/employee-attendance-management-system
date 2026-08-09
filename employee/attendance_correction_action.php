<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireLogin($pdo);
applyTimezone($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('history.php');
}
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Invalid request token.');
    redirect('history.php');
}

$employeeId = (int)$_SESSION['user_id'];
$action = trim((string)($_POST['action'] ?? ''));

if ($action === 'cancel') {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $stmt = $pdo->prepare('UPDATE attendance_correction_requests
        SET status = "cancelled" WHERE id = ? AND employee_id = ? AND status = "pending"');
    $stmt->execute([$requestId, $employeeId]);
    setFlash($stmt->rowCount() === 1 ? 'success' : 'error', $stmt->rowCount() === 1
        ? 'Correction request cancelled.'
        : 'The pending correction request was not found.');
    redirect('history.php#correction-requests');
}

if ($action !== 'submit') {
    setFlash('error', 'Unsupported correction action.');
    redirect('history.php');
}

$requestKind = trim((string)($_POST['request_kind'] ?? ''));
$attendanceId = (int)($_POST['attendance_id'] ?? 0);
$attendanceDate = trim((string)($_POST['attendance_date'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));
$timeInRaw = trim((string)($_POST['requested_time_in'] ?? ''));
$timeOutRaw = trim((string)($_POST['requested_time_out'] ?? ''));
$breakStartRaw = trim((string)($_POST['requested_break_start'] ?? ''));
$breakEndRaw = trim((string)($_POST['requested_break_end'] ?? ''));
$schedule = getAttendanceSchedule($pdo);
$recordTimezone = $schedule['timezone'];
$originalValues = null;
$requestedSchedule = null;

if (!in_array($requestKind, ['existing_record', 'missing_record'], true)) {
    setFlash('error', 'Select a valid correction request type.');
    redirect('history.php');
}
if ($reason === '' || mb_strlen($reason) > 1000) {
    setFlash('error', 'Explain the correction in 1–1000 characters.');
    redirect('history.php');
}

if ($requestKind === 'existing_record') {
    $recordStmt = $pdo->prepare('SELECT * FROM attendance WHERE id = ? AND employee_id = ? AND voided_at IS NULL LIMIT 1');
    $recordStmt->execute([$attendanceId, $employeeId]);
    $record = $recordStmt->fetch();
    if (!$record) {
        setFlash('error', 'The attendance record was not found.');
        redirect('history.php');
    }
    $attendanceDate = (string)$record['attendance_date'];
    $candidateTimezone = (string)($record['schedule_timezone'] ?? '');
    if (in_array($candidateTimezone, DateTimeZone::listIdentifiers(), true)) {
        $recordTimezone = $candidateTimezone;
    }
    $requestedSchedule = [
        'timezone' => $recordTimezone,
        'shift_id' => $record['shift_id'] ?? null,
        'shift_name' => $record['shift_name'] ?? null,
        'work_start_time' => substr((string)($record['scheduled_start_time'] ?? $schedule['work_start_time']), 0, 5),
        'work_end_time' => substr((string)($record['scheduled_end_time'] ?? $schedule['work_end_time']), 0, 5),
        'grace_period_minutes' => (int)($record['grace_period_minutes'] ?? $schedule['grace_period_minutes']),
        'scheduled_workday' => (int)($record['scheduled_workday'] ?? 1),
    ];
    $originalValues = json_encode([
        'attendance_date' => $record['attendance_date'],
        'time_in' => $record['time_in'],
        'time_out' => $record['time_out'],
        'break_start' => $record['break_start'],
        'break_end' => $record['break_end'],
        'break_minutes' => $record['break_minutes'],
        'total_hours' => $record['total_hours'],
        'status' => $record['status'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
    $attendanceId = 0;
    if (!isValidDateValue($attendanceDate) || $attendanceDate > date('Y-m-d')) {
        setFlash('error', 'Missing attendance requests require a valid date that is not in the future.');
        redirect('history.php');
    }
    $employmentStmt = $pdo->prepare('SELECT DATE(created_at) FROM employees WHERE id = ? AND role = "employee" LIMIT 1');
    $employmentStmt->execute([$employeeId]);
    $employmentStart = (string)$employmentStmt->fetchColumn();
    if ($employmentStart === '' || $attendanceDate < $employmentStart) {
        setFlash('error', 'The attendance date cannot be before your employment start date.');
        redirect('history.php');
    }
    $existingStmt = $pdo->prepare('SELECT id FROM attendance
        WHERE employee_id = ? AND attendance_date = ? AND voided_at IS NULL LIMIT 1');
    $existingStmt->execute([$employeeId, $attendanceDate]);
    if ($existingStmt->fetchColumn() !== false) {
        setFlash('error', 'An attendance record already exists for that date. Request a correction from its row instead.');
        redirect('history.php');
    }
    $schedule = getEmployeeScheduleForDate($pdo, $employeeId, $attendanceDate);
    $recordTimezone = $schedule['timezone'];
    $requestedSchedule = $schedule;
}

$timeIn = parseDateTimeLocal($timeInRaw, $recordTimezone);
$timeOut = $timeOutRaw === '' ? null : parseDateTimeLocal($timeOutRaw, $recordTimezone);
$breakStart = $breakStartRaw === '' ? null : parseDateTimeLocal($breakStartRaw, $recordTimezone);
$breakEnd = $breakEndRaw === '' ? null : parseDateTimeLocal($breakEndRaw, $recordTimezone);
if ($timeIn === null || ($timeOutRaw !== '' && $timeOut === null)
    || ($breakStartRaw !== '' && $breakStart === null) || ($breakEndRaw !== '' && $breakEnd === null)) {
    setFlash('error', 'One or more requested date/time values are invalid.');
    redirect('history.php');
}

$requestedStatus = $timeOut !== null ? 'completed' : ($breakStart !== null && $breakEnd === null ? 'on_break' : 'currently_working');
if ($requestKind === 'missing_record' && $attendanceDate < date('Y-m-d') && $timeOut === null) {
    setFlash('error', 'A missing record for a past date must include Time Out.');
    redirect('history.php');
}
$calculation = validateAttendanceTimeline($attendanceDate, $timeIn, $timeOut, $breakStart, $breakEnd, $requestedStatus);
if ($calculation['errors']) {
    setFlash('error', implode(' ', $calculation['errors']));
    redirect('history.php');
}
if ($requestKind === 'existing_record') {
    $quickBreakStmt = $pdo->prepare('SELECT started_at, ended_at FROM attendance_quick_breaks WHERE attendance_id = ?');
    $quickBreakStmt->execute([$attendanceId]);
    foreach ($quickBreakStmt->fetchAll() as $quickBreak) {
        $quickStart = parseDatabaseDateTime((string)$quickBreak['started_at'], $recordTimezone);
        $quickEnd = empty($quickBreak['ended_at']) ? null : parseDatabaseDateTime((string)$quickBreak['ended_at'], $recordTimezone);
        if ($quickEnd === null || ($quickStart !== null && $quickStart < $timeIn)
            || ($timeOut !== null && $quickEnd > $timeOut)) {
            setFlash('error', 'Requested work times must contain every completed quick break. End an active quick break before requesting a correction.');
            redirect('history.php');
        }
    }
}

try {
    $stmt = $pdo->prepare('INSERT INTO attendance_correction_requests
        (attendance_id, employee_id, request_kind, attendance_date, original_values, requested_schedule,
         requested_time_in, requested_time_out, requested_break_start, requested_break_end, reason)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $attendanceId > 0 ? $attendanceId : null,
        $employeeId,
        $requestKind,
        $attendanceDate,
        $originalValues,
        json_encode($requestedSchedule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $timeIn->format('Y-m-d H:i:s'),
        $timeOut?->format('Y-m-d H:i:s'),
        $breakStart?->format('Y-m-d H:i:s'),
        $breakEnd?->format('Y-m-d H:i:s'),
        $reason,
    ]);
    setFlash('success', 'Attendance correction request submitted for admin review.');
} catch (PDOException $e) {
    if ((string)$e->getCode() === '23000') {
        setFlash('error', 'You already have a pending correction request for that date.');
    } else {
        error_log('Attendance correction request failed: ' . $e->getMessage());
        setFlash('error', 'The correction request could not be submitted.');
    }
}
redirect('history.php#correction-requests');
