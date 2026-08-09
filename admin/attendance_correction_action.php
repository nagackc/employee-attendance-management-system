<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('attendance_corrections.php');
}
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Invalid request token.');
    redirect('attendance_corrections.php');
}

$requestId = (int)($_POST['request_id'] ?? 0);
$decision = trim((string)($_POST['decision'] ?? ''));
$comment = trim((string)($_POST['admin_comment'] ?? ''));
if ($requestId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    setFlash('error', 'Select a valid correction request action.');
    redirect('attendance_corrections.php');
}
if (mb_strlen($comment) > 1000 || ($decision === 'reject' && $comment === '')) {
    setFlash('error', 'A rejection reason is required and comments must not exceed 1000 characters.');
    redirect('attendance_corrections.php?id=' . $requestId . '#correction-review');
}

$adminId = (int)$_SESSION['user_id'];
$schedule = getAttendanceSchedule($pdo);

try {
    $pdo->beginTransaction();
    $requestStmt = $pdo->prepare('SELECT acr.*, CONCAT(e.first_name, " ", e.last_name) AS employee_name
        FROM attendance_correction_requests acr INNER JOIN employees e ON e.id = acr.employee_id
        WHERE acr.id = ? FOR UPDATE');
    $requestStmt->execute([$requestId]);
    $request = $requestStmt->fetch();
    if (!$request || (string)$request['status'] !== 'pending') {
        throw new RuntimeException('This correction request is no longer pending.');
    }

    if ($decision === 'reject') {
        $update = $pdo->prepare('UPDATE attendance_correction_requests SET status = "rejected", admin_comment = ?,
            reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = "pending"');
        $update->execute([$comment, $adminId, $requestId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('This correction request was already reviewed.');
        }
        createEmployeeNotification($pdo, (int)$request['employee_id'], 'Attendance Correction Rejected',
            'Your correction request for ' . formatEmployeeDate((string)$request['attendance_date']) . ' was rejected. ' . $comment);
        logAdminAudit($pdo, $adminId, 'reject_attendance_correction', (int)$request['employee_id'],
            $comment, ['status' => 'pending'], ['status' => 'rejected', 'admin_comment' => $comment],
            'attendance_correction_request', $requestId);
        $pdo->commit();
        setFlash('success', 'Correction request rejected and the employee was notified.');
        redirect('attendance_corrections.php');
    }

    $attendance = null;
    $requestedSchedule = json_decode((string)($request['requested_schedule'] ?? ''), true);
    if (!is_array($requestedSchedule)) {
        $requestedSchedule = getEmployeeScheduleForDate($pdo, (int)$request['employee_id'], (string)$request['attendance_date']);
    }
    $recordTimezone = in_array((string)($requestedSchedule['timezone'] ?? ''), DateTimeZone::listIdentifiers(), true)
        ? (string)$requestedSchedule['timezone']
        : $schedule['timezone'];
    if (!empty($request['attendance_id'])) {
        $attendanceStmt = $pdo->prepare('SELECT * FROM attendance WHERE id = ? AND employee_id = ? AND voided_at IS NULL FOR UPDATE');
        $attendanceStmt->execute([(int)$request['attendance_id'], (int)$request['employee_id']]);
        $attendance = $attendanceStmt->fetch();
        if (!$attendance) {
            throw new RuntimeException('The attendance record is no longer available.');
        }
    }

    $timeIn = parseDatabaseDateTime((string)$request['requested_time_in'], $recordTimezone);
    $timeOut = empty($request['requested_time_out']) ? null : parseDatabaseDateTime((string)$request['requested_time_out'], $recordTimezone);
    $breakStart = empty($request['requested_break_start']) ? null : parseDatabaseDateTime((string)$request['requested_break_start'], $recordTimezone);
    $breakEnd = empty($request['requested_break_end']) ? null : parseDatabaseDateTime((string)$request['requested_break_end'], $recordTimezone);
    $status = $timeOut !== null ? 'completed' : ($breakStart !== null && $breakEnd === null ? 'on_break' : 'currently_working');
    $calculation = validateAttendanceTimeline(
        (string)$request['attendance_date'],
        $timeIn,
        $timeOut,
        $breakStart,
        $breakEnd,
        $status
    );
    if ($calculation['errors']) {
        throw new RuntimeException(implode(' ', $calculation['errors']));
    }
    if ($attendance) {
        $quickBreakStmt = $pdo->prepare('SELECT started_at, ended_at FROM attendance_quick_breaks WHERE attendance_id = ? FOR UPDATE');
        $quickBreakStmt->execute([(int)$attendance['id']]);
        foreach ($quickBreakStmt->fetchAll() as $quickBreak) {
            $quickStart = parseDatabaseDateTime((string)$quickBreak['started_at'], $recordTimezone);
            $quickEnd = empty($quickBreak['ended_at']) ? null : parseDatabaseDateTime((string)$quickBreak['ended_at'], $recordTimezone);
            if ($quickEnd === null || ($quickStart !== null && $quickStart < $timeIn)
                || ($timeOut !== null && $quickEnd > $timeOut)) {
                throw new RuntimeException('Requested work times must contain every completed quick break, and no quick break may remain open.');
            }
        }
    }

    $oldValues = $attendance ?: null;
    if ($attendance) {
        $attendanceId = (int)$attendance['id'];
        $updateAttendance = $pdo->prepare('UPDATE attendance SET time_in = ?, time_out = ?, break_start = ?, break_end = ?,
            break_minutes = ?, total_hours = ?, status = ? WHERE id = ? AND voided_at IS NULL');
        $updateAttendance->execute([
            $timeIn?->format('Y-m-d H:i:s'), $timeOut?->format('Y-m-d H:i:s'),
            $breakStart?->format('Y-m-d H:i:s'), $breakEnd?->format('Y-m-d H:i:s'),
            $calculation['break_minutes'], $calculation['total_hours'], $status, $attendanceId,
        ]);
    } else {
        $duplicateStmt = $pdo->prepare('SELECT id FROM attendance
            WHERE employee_id = ? AND attendance_date = ? AND voided_at IS NULL FOR UPDATE');
        $duplicateStmt->execute([(int)$request['employee_id'], (string)$request['attendance_date']]);
        if ($duplicateStmt->fetchColumn() !== false) {
            throw new RuntimeException('An attendance record now exists for this employee and date. Review that record instead.');
        }
        $insertAttendance = $pdo->prepare('INSERT INTO attendance
            (employee_id, attendance_date, time_in, time_out, break_start, break_end, break_minutes, total_hours,
             status, schedule_timezone, shift_id, shift_name, scheduled_start_time, scheduled_end_time,
             grace_period_minutes, scheduled_workday)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insertAttendance->execute([
            (int)$request['employee_id'], (string)$request['attendance_date'],
            $timeIn?->format('Y-m-d H:i:s'), $timeOut?->format('Y-m-d H:i:s'),
            $breakStart?->format('Y-m-d H:i:s'), $breakEnd?->format('Y-m-d H:i:s'),
            $calculation['break_minutes'], $calculation['total_hours'], $status,
            $recordTimezone, $requestedSchedule['shift_id'] ?? null, $requestedSchedule['shift_name'] ?? null,
            substr((string)($requestedSchedule['work_start_time'] ?? $schedule['work_start_time']), 0, 5) . ':00',
            substr((string)($requestedSchedule['work_end_time'] ?? $schedule['work_end_time']), 0, 5) . ':00',
            (int)($requestedSchedule['grace_period_minutes'] ?? $schedule['grace_period_minutes']),
            (int)($requestedSchedule['scheduled_workday'] ?? 1),
        ]);
        $attendanceId = (int)$pdo->lastInsertId();
    }

    $newStmt = $pdo->prepare('SELECT * FROM attendance WHERE id = ?');
    $newStmt->execute([$attendanceId]);
    $newValues = $newStmt->fetch() ?: null;
    $approvalComment = $comment !== '' ? $comment : 'Approved as requested.';
    $updateRequest = $pdo->prepare('UPDATE attendance_correction_requests SET attendance_id = ?, status = "approved",
        admin_comment = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = "pending"');
    $updateRequest->execute([$attendanceId, $approvalComment, $adminId, $requestId]);
    if ($updateRequest->rowCount() !== 1) {
        throw new RuntimeException('This correction request was already reviewed.');
    }

    createEmployeeNotification($pdo, (int)$request['employee_id'], 'Attendance Correction Approved',
        'Your correction request for ' . formatEmployeeDate((string)$request['attendance_date']) . ' was approved.');
    logAdminAudit($pdo, $adminId, 'approve_attendance_correction', (int)$request['employee_id'],
        'Correction request #' . $requestId . '. ' . $approvalComment,
        $oldValues, $newValues, 'attendance', $attendanceId);
    $pdo->commit();
    setFlash('success', 'Attendance corrected and the employee was notified.');
    redirect('attendance_corrections.php');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    setFlash('error', userFacingException($e, 'The correction request could not be reviewed.'));
    redirect('attendance_corrections.php?id=' . $requestId . '#correction-review');
}
