<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Invalid employee status request.');
    redirect('employees.php');
}

$adminId = (int)$_SESSION['user_id'];
$employeeId = (int)($_POST['id'] ?? 0);
$action = trim((string)($_POST['action'] ?? 'deactivate'));
if ($employeeId <= 0 || !in_array($action, ['deactivate', 'reactivate'], true)) {
    setFlash('error', 'Invalid employee status action.');
    redirect('employees.php');
}

try {
    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT * FROM employees WHERE id = ? FOR UPDATE');
    $lock->execute([$employeeId]);
    $oldValues = $lock->fetch();
    if (!$oldValues) {
        throw new RuntimeException('Employee not found.');
    }

    $adminLock = $pdo->query('SELECT id FROM employees WHERE role = "admin" AND active = 1 FOR UPDATE');
    $activeAdminIds = array_map('intval', $adminLock->fetchAll(PDO::FETCH_COLUMN));

    if ($action === 'deactivate') {
        if ($employeeId === $adminId) {
            throw new RuntimeException('You cannot deactivate your own account.');
        }
        if ((int)$oldValues['active'] !== 1) {
            throw new RuntimeException('This account is already deactivated.');
        }
        if (strtolower((string)$oldValues['role']) === 'admin' && count($activeAdminIds) <= 1) {
            throw new RuntimeException('The final active administrator cannot be deactivated.');
        }
        $update = $pdo->prepare('UPDATE employees SET active = 0, deactivated_at = NOW(), deactivated_by = ? WHERE id = ? AND active = 1');
        $update->execute([$adminId, $employeeId]);
    } else {
        if ((int)$oldValues['active'] === 1) {
            throw new RuntimeException('This account is already active.');
        }
        $update = $pdo->prepare('UPDATE employees SET active = 1, deactivated_at = NULL, deactivated_by = NULL WHERE id = ? AND active = 0');
        $update->execute([$employeeId]);
    }

    $newStmt = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
    $newStmt->execute([$employeeId]);
    $newValues = $newStmt->fetch();
    logAdminAudit(
        $pdo,
        $adminId,
        $action === 'deactivate' ? 'deactivate_employee' : 'reactivate_employee',
        $employeeId,
        ucfirst($action) . ' employee account.',
        $oldValues,
        $newValues ?: null,
        'employee',
        $employeeId
    );
    $pdo->commit();
    setFlash('success', $action === 'deactivate' ? 'Employee deactivated. Historical records were preserved.' : 'Employee reactivated.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    setFlash('error', userFacingException($e, 'Employee status could not be changed.'));
}

redirect('employees.php');
