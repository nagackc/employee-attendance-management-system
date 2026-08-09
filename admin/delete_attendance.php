<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Invalid attendance archive request.');
    redirect('attendance.php');
}

$id = (int)($_POST['id'] ?? 0);
$reason = trim((string)($_POST['void_reason'] ?? ''));
if ($id <= 0 || $reason === '' || mb_strlen($reason) > 1000) {
    setFlash('error', 'A reason of up to 1000 characters is required to void attendance.');
    redirect('attendance.php');
}

try {
    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT * FROM attendance WHERE id = ? FOR UPDATE');
    $lock->execute([$id]);
    $oldValues = $lock->fetch();
    if (!$oldValues || !empty($oldValues['voided_at'])) {
        throw new RuntimeException('This attendance record is already voided or no longer exists.');
    }

    $update = $pdo->prepare('UPDATE attendance SET voided_at = NOW(), voided_by = ?, void_reason = ?
        WHERE id = ? AND voided_at IS NULL');
    $update->execute([(int)$_SESSION['user_id'], $reason, $id]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('This attendance record was already voided.');
    }

    $newStmt = $pdo->prepare('SELECT * FROM attendance WHERE id = ?');
    $newStmt->execute([$id]);
    $newValues = $newStmt->fetch();
    logAdminAudit(
        $pdo,
        (int)$_SESSION['user_id'],
        'void_attendance',
        (int)$oldValues['employee_id'],
        $reason,
        $oldValues,
        $newValues ?: null,
        'attendance',
        $id
    );
    $pdo->commit();
    setFlash('success', 'Attendance record voided and retained in the archive.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    setFlash('error', userFacingException($e, 'Attendance could not be voided.'));
}

redirect('attendance.php');
