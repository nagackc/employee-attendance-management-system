<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Invalid request token.');
    redirect('leave_management.php');
}

$adminId = (int)$_SESSION['user_id'];
$requestId = (int)($_POST['request_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
$comment = trim((string)($_POST['admin_comment'] ?? ''));
if ($requestId <= 0 || !in_array($action, ['approve', 'reject', 'cancel'], true)) {
    setFlash('error', 'Invalid leave request action.');
    redirect('leave_management.php');
}
if (mb_strlen($comment) > 1000) {
    setFlash('error', 'Comment is too long. Please limit to 1000 characters.');
    redirect('leave_management.php');
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT lr.*, lt.name AS leave_type_name,
            CONCAT(e.first_name, " ", e.last_name) AS employee_name
        FROM leave_requests lr
        INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
        INNER JOIN employees e ON e.id = lr.employee_id
        WHERE lr.id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$requestId]);
    $leaveRequest = $stmt->fetch();
    if (!$leaveRequest) {
        throw new RuntimeException('Leave request not found.');
    }

    $currentStatus = strtolower((string)$leaveRequest['status']);
    if (in_array($action, ['approve', 'reject'], true) && $currentStatus !== 'pending') {
        throw new RuntimeException('Only pending requests can be approved or rejected.');
    }
    if ($action === 'cancel' && !in_array($currentStatus, ['pending', 'approved'], true)) {
        throw new RuntimeException('Only pending or approved requests can be cancelled.');
    }

    if ($action === 'approve') {
        $overlap = $pdo->prepare('SELECT id FROM leave_requests
            WHERE employee_id = ? AND id <> ? AND status = "approved"
              AND start_date <= ? AND end_date >= ?
            LIMIT 1 FOR UPDATE');
        $overlap->execute([
            $leaveRequest['employee_id'],
            $requestId,
            $leaveRequest['end_date'],
            $leaveRequest['start_date'],
        ]);
        if ($overlap->fetch()) {
            throw new RuntimeException('This leave overlaps another approved request for the employee.');
        }
    }

    $nextStatus = ['approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled'][$action];
    $expectedStatuses = $action === 'cancel' ? ['pending', 'approved'] : ['pending'];
    $placeholders = implode(',', array_fill(0, count($expectedStatuses), '?'));
    $parameters = [$nextStatus, $adminId, date('Y-m-d H:i:s'), $comment === '' ? null : $comment, $requestId];
    array_push($parameters, ...$expectedStatuses);
    $update = $pdo->prepare('UPDATE leave_requests SET status = ?, approved_by = ?, action_date = ?, admin_comment = ?
        WHERE id = ? AND status IN (' . $placeholders . ')');
    $update->execute($parameters);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('The leave request was already processed.');
    }

    $notificationMessage = 'Your leave request has been ' . $nextStatus . '.';
    createEmployeeNotification($pdo, (int)$leaveRequest['employee_id'], 'Leave Request Update', $notificationMessage);

    $newStmt = $pdo->prepare('SELECT * FROM leave_requests WHERE id = ?');
    $newStmt->execute([$requestId]);
    $newValues = $newStmt->fetch() ?: [];
    $details = 'Request #' . $requestId . ' | ' . $leaveRequest['employee_name'] . ' | '
        . $leaveRequest['leave_type_name'] . ' | ' . $leaveRequest['start_date'] . ' to ' . $leaveRequest['end_date']
        . ($comment !== '' ? ' | Comment: ' . $comment : '');
    logAdminAudit(
        $pdo,
        $adminId,
        $action . '_leave',
        (int)$leaveRequest['employee_id'],
        $details,
        $leaveRequest,
        $newValues,
        'leave_request',
        $requestId
    );
    $pdo->commit();
    setFlash('success', 'Leave request ' . $nextStatus . ' successfully.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    setFlash('error', userFacingException($e, 'Leave request could not be updated.'));
}

redirect('leave_management.php');
