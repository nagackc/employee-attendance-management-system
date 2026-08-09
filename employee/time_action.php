<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireLogin($pdo);
applyTimezone($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Invalid attendance request. Please try again.');
    redirect('dashboard.php');
}

$employeeId = (int)$_SESSION['user_id'];
$action = trim((string)($_POST['action'] ?? ''));
$allowedActions = ['time_in', 'lunch_out', 'lunch_in', 'quick_break_start', 'quick_break_end', 'time_out'];
if (!in_array($action, $allowedActions, true)) {
    setFlash('error', 'Unsupported attendance action.');
    redirect('dashboard.php');
}

$attendanceContext = getEmployeeAttendanceContext($pdo, $employeeId);
$schedule = $attendanceContext['schedule'];
$now = $attendanceContext['now'];
$nowValue = $now->format('Y-m-d H:i:s');
$today = $attendanceContext['attendance_date'];

try {
    $pdo->beginTransaction();

    // Locking the employee row serializes time-in requests even when no attendance row exists yet.
    $employeeLock = $pdo->prepare('SELECT id FROM employees WHERE id = ? AND active = 1 FOR UPDATE');
    $employeeLock->execute([$employeeId]);
    if (!$employeeLock->fetch()) {
        throw new RuntimeException('Your account is no longer active.');
    }

    $openStmt = $pdo->prepare('SELECT * FROM attendance
        WHERE employee_id = ? AND voided_at IS NULL AND time_out IS NULL
          AND status IN ("currently_working", "on_break", "on_quick_break")
        ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $openStmt->execute([$employeeId]);
    $openAttendance = $openStmt->fetch();

    $todayStmt = $pdo->prepare('SELECT * FROM attendance
        WHERE employee_id = ? AND attendance_date = ? AND voided_at IS NULL
        ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $todayStmt->execute([$employeeId, $today]);
    $todayAttendance = $todayStmt->fetch();

    if ($action === 'time_in') {
        if ($openAttendance) {
            throw new RuntimeException('You already have an open work session.');
        }
        if ($todayAttendance) {
            throw new RuntimeException('Attendance has already been recorded for today.');
        }

        $insert = $pdo->prepare('INSERT INTO attendance
            (employee_id, attendance_date, time_in, status, schedule_timezone, shift_id, shift_name,
             scheduled_start_time, scheduled_end_time, grace_period_minutes, scheduled_workday, created_at)
            VALUES (?, ?, ?, "currently_working", ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([
            $employeeId,
            $today,
            $nowValue,
            $schedule['timezone'],
            $schedule['shift_id'],
            $schedule['shift_name'],
            $schedule['work_start_time'],
            $schedule['work_end_time'],
            $schedule['grace_period_minutes'],
            $schedule['scheduled_workday'],
            $nowValue,
        ]);
        $pdo->commit();
        setFlash('success', 'Time in recorded successfully.');
        redirect('dashboard.php');
    }

    if (!$openAttendance) {
        throw new RuntimeException('No open work session was found. The action may already have been processed.');
    }

    $sessionTimezoneName = (string)($openAttendance['schedule_timezone'] ?: $schedule['timezone']);
    if (!in_array($sessionTimezoneName, DateTimeZone::listIdentifiers(), true)) {
        $sessionTimezoneName = $schedule['timezone'];
    }
    $actionNow = new DateTimeImmutable('now', new DateTimeZone($sessionTimezoneName));
    $actionNowValue = $actionNow->format('Y-m-d H:i:s');
    $timeIn = parseDatabaseDateTime((string)$openAttendance['time_in'], $sessionTimezoneName);
    if ($timeIn === null || $actionNow <= $timeIn) {
        throw new RuntimeException('The work session has invalid timing and needs an administrator correction.');
    }
    if (($actionNow->getTimestamp() - $timeIn->getTimestamp()) > MAX_OPEN_SESSION_SECONDS) {
        throw new RuntimeException('This session has been open for more than 36 hours. Ask an administrator to correct it safely.');
    }

    $quickBreakStmt = $pdo->prepare('SELECT * FROM attendance_quick_breaks
        WHERE attendance_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $quickBreakStmt->execute([(int)$openAttendance['id']]);
    $openQuickBreak = $quickBreakStmt->fetch();

    if ($action === 'lunch_out') {
        if ($openAttendance['status'] !== 'currently_working') {
            throw new RuntimeException('End the active break before starting lunch.');
        }
        if ($openQuickBreak) {
            throw new RuntimeException('End the quick break before starting lunch.');
        }
        if (!empty($openAttendance['break_start']) || !empty($openAttendance['break_end'])) {
            throw new RuntimeException('A lunch break has already been recorded for this work session.');
        }

        $update = $pdo->prepare('UPDATE attendance SET break_start = ?, break_end = NULL, status = "on_break"
            WHERE id = ? AND employee_id = ? AND status = "currently_working"
              AND time_out IS NULL AND voided_at IS NULL AND break_start IS NULL AND break_end IS NULL');
        $update->execute([$actionNowValue, $openAttendance['id'], $employeeId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The lunch action was already processed.');
        }
        $pdo->commit();
        setFlash('success', 'Lunch out recorded successfully.');
        redirect('dashboard.php');
    }

    if ($action === 'quick_break_start') {
        if ($openAttendance['status'] !== 'currently_working' || $openQuickBreak) {
            throw new RuntimeException('Another break is already active.');
        }

        $insertQuickBreak = $pdo->prepare('INSERT INTO attendance_quick_breaks (attendance_id, started_at)
            VALUES (?, ?)');
        $insertQuickBreak->execute([(int)$openAttendance['id'], $actionNowValue]);

        $update = $pdo->prepare('UPDATE attendance SET status = "on_quick_break"
            WHERE id = ? AND employee_id = ? AND status = "currently_working"
              AND time_out IS NULL AND voided_at IS NULL');
        $update->execute([(int)$openAttendance['id'], $employeeId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The quick-break action was already processed.');
        }

        $pdo->commit();
        setFlash('success', 'Quick break started.');
        redirect('dashboard.php');
    }

    if ($action === 'quick_break_end') {
        if ($openAttendance['status'] !== 'on_quick_break' || !$openQuickBreak) {
            throw new RuntimeException('There is no open quick break to end.');
        }

        $quickBreakStart = parseDatabaseDateTime((string)$openQuickBreak['started_at'], $sessionTimezoneName);
        if ($quickBreakStart === null || $quickBreakStart < $timeIn || $actionNow <= $quickBreakStart) {
            throw new RuntimeException('The quick-break timing is invalid and needs an administrator correction.');
        }
        $durationSeconds = $actionNow->getTimestamp() - $quickBreakStart->getTimestamp();

        $closeQuickBreak = $pdo->prepare('UPDATE attendance_quick_breaks
            SET ended_at = ?, duration_seconds = ?
            WHERE id = ? AND attendance_id = ? AND ended_at IS NULL');
        $closeQuickBreak->execute([
            $actionNowValue,
            $durationSeconds,
            (int)$openQuickBreak['id'],
            (int)$openAttendance['id'],
        ]);
        if ($closeQuickBreak->rowCount() !== 1) {
            throw new RuntimeException('The quick-break action was already processed.');
        }

        $update = $pdo->prepare('UPDATE attendance SET status = "currently_working"
            WHERE id = ? AND employee_id = ? AND status = "on_quick_break"
              AND time_out IS NULL AND voided_at IS NULL');
        $update->execute([(int)$openAttendance['id'], $employeeId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The quick-break attendance state changed unexpectedly.');
        }

        $pdo->commit();
        setFlash('success', 'Quick break ended.');
        redirect('dashboard.php');
    }

    if ($action === 'lunch_in') {
        if ($openAttendance['status'] !== 'on_break' || empty($openAttendance['break_start']) || !empty($openAttendance['break_end'])) {
            throw new RuntimeException('There is no open lunch break to end.');
        }

        $breakStart = parseDatabaseDateTime((string)$openAttendance['break_start'], $sessionTimezoneName);
        if ($breakStart === null || $breakStart < $timeIn || $actionNow <= $breakStart) {
            throw new RuntimeException('The lunch break timing is invalid and needs an administrator correction.');
        }
        $breakMinutes = (int)round(($actionNow->getTimestamp() - $breakStart->getTimestamp()) / 60);

        $update = $pdo->prepare('UPDATE attendance
            SET break_end = ?, break_minutes = ?, status = "currently_working"
            WHERE id = ? AND employee_id = ? AND status = "on_break"
              AND time_out IS NULL AND voided_at IS NULL AND break_start = ? AND break_end IS NULL');
        $update->execute([$actionNowValue, $breakMinutes, $openAttendance['id'], $employeeId, $openAttendance['break_start']]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The lunch-in action was already processed.');
        }
        $pdo->commit();
        setFlash('success', 'Lunch in recorded successfully.');
        redirect('dashboard.php');
    }

    if ($action === 'time_out') {
        $breakStart = parseDatabaseDateTime($openAttendance['break_start'] ?: null, $sessionTimezoneName);
        $breakEnd = parseDatabaseDateTime($openAttendance['break_end'] ?: null, $sessionTimezoneName);
        if ($openAttendance['status'] === 'on_break') {
            if ($breakStart === null || $actionNow <= $breakStart) {
                throw new RuntimeException('The open lunch break timing is invalid.');
            }
            $breakEnd = $actionNow;
        }

        if ($openAttendance['status'] === 'on_quick_break') {
            if (!$openQuickBreak) {
                throw new RuntimeException('The open quick break could not be found.');
            }
            $quickBreakStart = parseDatabaseDateTime((string)$openQuickBreak['started_at'], $sessionTimezoneName);
            if ($quickBreakStart === null || $quickBreakStart < $timeIn || $actionNow <= $quickBreakStart) {
                throw new RuntimeException('The open quick-break timing is invalid.');
            }
            $closeQuickBreak = $pdo->prepare('UPDATE attendance_quick_breaks
                SET ended_at = ?, duration_seconds = ?
                WHERE id = ? AND attendance_id = ? AND ended_at IS NULL');
            $closeQuickBreak->execute([
                $actionNowValue,
                $actionNow->getTimestamp() - $quickBreakStart->getTimestamp(),
                (int)$openQuickBreak['id'],
                (int)$openAttendance['id'],
            ]);
            if ($closeQuickBreak->rowCount() !== 1) {
                throw new RuntimeException('The quick break was already closed.');
            }
        }

        $calculation = validateAttendanceTimeline(
            (string)$openAttendance['attendance_date'],
            $timeIn,
            $actionNow,
            $breakStart,
            $breakEnd,
            'completed',
            (int)$openAttendance['break_minutes']
        );
        if ($calculation['errors']) {
            throw new RuntimeException(implode(' ', $calculation['errors']));
        }

        $update = $pdo->prepare('UPDATE attendance
            SET time_out = ?, break_end = ?, break_minutes = ?, total_hours = ?, status = "completed"
            WHERE id = ? AND employee_id = ? AND status IN ("currently_working", "on_break", "on_quick_break")
              AND time_out IS NULL AND voided_at IS NULL');
        $update->execute([
            $actionNowValue,
            $breakEnd?->format('Y-m-d H:i:s'),
            $calculation['break_minutes'],
            $calculation['total_hours'],
            $openAttendance['id'],
            $employeeId,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Time out was already processed.');
        }
        $pdo->commit();
        setFlash('success', 'Time out recorded successfully.');
        redirect('dashboard.php');
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ((string)$e->getCode() === '23000') {
        setFlash('error', 'That attendance action was already recorded.');
    } else {
        error_log('Attendance action failed: ' . $e->getMessage());
        setFlash('error', 'Attendance could not be updated. Please try again.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    setFlash('error', userFacingException($e, 'Attendance could not be updated.'));
}

redirect('dashboard.php');
