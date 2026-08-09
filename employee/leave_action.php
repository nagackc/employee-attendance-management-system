<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireLogin($pdo);
applyTimezone($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Invalid request token. Please try again.');
    redirect('calendar.php');
}

$employeeId = (int)$_SESSION['user_id'];
$action = trim((string)($_POST['action'] ?? ''));

if ($action === 'cancel_leave') {
    $requestId = (int)($_POST['request_id'] ?? 0);
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id, status FROM leave_requests WHERE id = ? AND employee_id = ? FOR UPDATE');
        $lock->execute([$requestId, $employeeId]);
        $request = $lock->fetch();
        if (!$request) {
            throw new RuntimeException('Leave request not found.');
        }
        if ($request['status'] !== 'pending') {
            throw new RuntimeException('Only your own pending leave request can be cancelled.');
        }
        $update = $pdo->prepare('UPDATE leave_requests SET status = "cancelled", action_date = NOW()
            WHERE id = ? AND employee_id = ? AND status = "pending"');
        $update->execute([$requestId, $employeeId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The leave request was already processed.');
        }
        $pdo->commit();
        setFlash('success', 'Leave request cancelled.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlash('error', userFacingException($e, 'Leave request could not be cancelled.'));
    }
    redirect('calendar.php');
}

if ($action !== 'request_leave') {
    setFlash('error', 'Invalid leave request action.');
    redirect('calendar.php');
}

$leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
$requestUnit = trim((string)($_POST['request_unit'] ?? 'days'));
$startDate = $requestUnit === 'hours'
    ? trim((string)($_POST['hour_date'] ?? ''))
    : trim((string)($_POST['start_date'] ?? ''));
$endDate = $requestUnit === 'hours'
    ? $startDate
    : trim((string)($_POST['end_date'] ?? ''));
$hoursRequested = trim((string)($_POST['hours_requested'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));

if ($leaveTypeId <= 0 || $reason === '' || !in_array($requestUnit, ['days', 'hours'], true)) {
    setFlash('error', 'All leave request fields are required.');
    redirect('calendar.php');
}
if (!isValidDateValue($startDate) || !isValidDateValue($endDate)) {
    setFlash('error', 'Please provide valid start and end dates.');
    redirect('calendar.php');
}
if ($endDate < $startDate) {
    setFlash('error', 'End date cannot be before start date.');
    redirect('calendar.php');
}
if (mb_strlen($reason) > 1000) {
    setFlash('error', 'Reason is too long. Please limit it to 1000 characters.');
    redirect('calendar.php');
}

try {
    $charges = calculateLeaveRequestCharges($pdo, $requestUnit, $startDate, $endDate, $hoursRequested, $employeeId);
} catch (RuntimeException $e) {
    setFlash('error', $e->getMessage());
    redirect('calendar.php');
}
$requestedMinutes = array_sum($charges);

try {
    $pdo->beginTransaction();
    $employeeLock = $pdo->prepare('SELECT id FROM employees WHERE id = ? AND active = 1 FOR UPDATE');
    $employeeLock->execute([$employeeId]);
    if (!$employeeLock->fetch()) {
        throw new RuntimeException('Your account is not active.');
    }

    $leaveTypeStmt = $pdo->prepare('SELECT id FROM leave_types WHERE id = ? AND active = 1 LIMIT 1');
    $leaveTypeStmt->execute([$leaveTypeId]);
    if (!$leaveTypeStmt->fetch()) {
        throw new RuntimeException('Selected leave type is not available.');
    }

    $overlap = $pdo->prepare('SELECT id FROM leave_requests
        WHERE employee_id = ? AND status IN ("pending", "approved")
          AND start_date <= ? AND end_date >= ?
        LIMIT 1 FOR UPDATE');
    $overlap->execute([$employeeId, $endDate, $startDate]);
    if ($overlap->fetch()) {
        throw new RuntimeException('This request overlaps an existing pending or approved leave request.');
    }

    $insert = $pdo->prepare('INSERT INTO leave_requests
        (employee_id, leave_type_id, start_date, end_date, request_unit, requested_minutes, reason, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, "pending")');
    $insert->execute([$employeeId, $leaveTypeId, $startDate, $endDate, $requestUnit, $requestedMinutes, $reason]);
    $requestId = (int)$pdo->lastInsertId();
    $insertCharge = $pdo->prepare('INSERT INTO leave_request_charges (leave_request_id, charge_date, minutes)
        VALUES (?, ?, ?)');
    foreach ($charges as $chargeDate => $minutes) {
        $insertCharge->execute([$requestId, $chargeDate, $minutes]);
    }
    $pdo->commit();
    setFlash('success', 'Leave request submitted successfully for ' . formatLeaveMinutes($requestedMinutes, $requestUnit) . ' ' . $requestUnit . '.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    setFlash('error', userFacingException($e, 'Leave request could not be submitted.'));
}

redirect('calendar.php');
