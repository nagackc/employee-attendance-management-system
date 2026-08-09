<?php
require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

$adminId = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request token.');
        redirect('leave_types.php');
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));

    if ($action === 'add') {
        if ($name === '') {
            setFlash('error', 'Leave type name is required.');
            redirect('leave_types.php');
        }

        if (mb_strlen($name) > 120) {
            setFlash('error', 'Leave type name must be 120 characters or less.');
            redirect('leave_types.php');
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO leave_types (name, active) VALUES (?, 1)');
            $stmt->execute([$name]);
            logAdminAudit($pdo, $adminId, 'add_leave_type', null, 'Leave type: ' . $name);
            setFlash('success', 'Leave type added.');
        } catch (PDOException $e) {
            setFlash('error', 'Leave type already exists or could not be added.');
        }
        redirect('leave_types.php');
    }

    if ($action === 'edit') {
        if ($leaveTypeId <= 0 || $name === '') {
            setFlash('error', 'Invalid leave type update.');
            redirect('leave_types.php');
        }

        if (mb_strlen($name) > 120) {
            setFlash('error', 'Leave type name must be 120 characters or less.');
            redirect('leave_types.php');
        }

        $stmt = $pdo->prepare('UPDATE leave_types SET name = ? WHERE id = ?');
        $stmt->execute([$name, $leaveTypeId]);
        logAdminAudit($pdo, $adminId, 'edit_leave_type', null, 'Leave type #' . $leaveTypeId . ' renamed to ' . $name);
        setFlash('success', 'Leave type updated.');
        redirect('leave_types.php');
    }

    if ($action === 'toggle') {
        $active = (int)($_POST['active'] ?? 0) === 1 ? 1 : 0;
        if ($leaveTypeId <= 0) {
            setFlash('error', 'Invalid leave type selection.');
            redirect('leave_types.php');
        }

        $stmt = $pdo->prepare('UPDATE leave_types SET active = ? WHERE id = ?');
        $stmt->execute([$active, $leaveTypeId]);
        logAdminAudit($pdo, $adminId, $active === 1 ? 'enable_leave_type' : 'disable_leave_type', null, 'Leave type #' . $leaveTypeId . ' set active=' . $active);
        setFlash('success', $active === 1 ? 'Leave type enabled.' : 'Leave type disabled.');
        redirect('leave_types.php');
    }

    if ($action === 'delete') {
        if ($leaveTypeId <= 0) {
            setFlash('error', 'Invalid leave type selection.');
            redirect('leave_types.php');
        }

        $usageStmt = $pdo->prepare('SELECT COUNT(*) FROM leave_requests WHERE leave_type_id = ?');
        $usageStmt->execute([$leaveTypeId]);
        $usageCount = (int)$usageStmt->fetchColumn();
        if ($usageCount > 0) {
            setFlash('error', 'Cannot delete a leave type that is already used in requests. Disable it instead.');
            redirect('leave_types.php');
        }

        $nameStmt = $pdo->prepare('SELECT name FROM leave_types WHERE id = ? LIMIT 1');
        $nameStmt->execute([$leaveTypeId]);
        $typeName = (string)($nameStmt->fetchColumn() ?: '');

        $stmt = $pdo->prepare('DELETE FROM leave_types WHERE id = ?');
        $stmt->execute([$leaveTypeId]);
        logAdminAudit($pdo, $adminId, 'delete_leave_type', null, 'Deleted leave type #' . $leaveTypeId . ($typeName !== '' ? ' (' . $typeName . ')' : ''));
        setFlash('success', 'Leave type deleted.');
        redirect('leave_types.php');
    }

    setFlash('error', 'Unsupported leave type action.');
    redirect('leave_types.php');
}

$stmt = $pdo->query('SELECT id, name, active, created_at FROM leave_types ORDER BY active DESC, name ASC');
$leaveTypes = $stmt->fetchAll();

$companyName = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$pageTitle = 'Leave Types';
$activePage = '';
$activeSubPage = 'leave_types';
include __DIR__ . '/../includes/admin_layout_start.php';
?>
<section class="page-header">
    <div>
        <h1>Leave Types</h1>
        <p>Create and maintain dynamic leave types for employee requests.</p>
    </div>
</section>

<article class="content-card" data-search-item>
    <div class="card-header">
        <h3>Add Leave Type</h3>
    </div>
    <form method="post" class="form-layout leave-inline-form">
        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="action" value="add">
        <div class="form-grid-3">
            <div style="grid-column: span 2;">
                <label class="required" for="leave-type-name">Leave Type Name</label>
                <input id="leave-type-name" type="text" name="name" maxlength="120" required placeholder="e.g. Emergency Leave">
            </div>
            <div style="display:flex;align-items:flex-end;">
                <button type="submit" class="btn btn-primary" data-loading-text="Saving...">Add Type</button>
            </div>
        </div>
    </form>
</article>

<article class="content-card" data-search-item>
    <div class="card-header">
        <h3>Leave Type List</h3>
    </div>
    <div class="table-card" data-sticky-head="true" data-table-enhance="true" data-page-size="10">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th data-sort="false">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($leaveTypes): ?>
                    <?php foreach ($leaveTypes as $leaveType): ?>
                        <tr>
                            <td>
                                <form method="post" class="leave-inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="leave_type_id" value="<?= (int)$leaveType['id'] ?>">
                                    <div class="leave-inline-edit">
                                        <input type="text" name="name" maxlength="120" value="<?= h($leaveType['name']) ?>" required>
                                        <button type="submit" class="btn btn-secondary btn-sm" data-loading-text="Updating...">Save</button>
                                    </div>
                                </form>
                            </td>
                            <td><?= (int)$leaveType['active'] === 1 ? '<span class="pill pill-green">Active</span>' : '<span class="pill pill-gray">Disabled</span>' ?></td>
                            <td><?= h($leaveType['created_at']) ?></td>
                            <td>
                                <div class="leave-action-row">
                                    <form method="post" class="inline-form" id="toggle-type-<?= (int)$leaveType['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="leave_type_id" value="<?= (int)$leaveType['id'] ?>">
                                        <input type="hidden" name="active" value="<?= (int)$leaveType['active'] === 1 ? 0 : 1 ?>">
                                        <button
                                            type="button"
                                            class="btn btn-sm <?= (int)$leaveType['active'] === 1 ? 'btn-secondary' : 'btn-success' ?>"
                                            data-confirm-form="toggle-type-<?= (int)$leaveType['id'] ?>"
                                            data-confirm-title="<?= (int)$leaveType['active'] === 1 ? 'Disable Leave Type?' : 'Enable Leave Type?' ?>"
                                            data-confirm-message="<?= (int)$leaveType['active'] === 1 ? 'Employees will no longer be able to select this type.' : 'Employees will be able to select this type again.' ?>"
                                        >
                                            <?= (int)$leaveType['active'] === 1 ? 'Disable' : 'Enable' ?>
                                        </button>
                                    </form>

                                    <form method="post" class="inline-form" id="delete-type-<?= (int)$leaveType['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="leave_type_id" value="<?= (int)$leaveType['id'] ?>">
                                        <button
                                            type="button"
                                            class="btn btn-danger btn-sm"
                                            data-confirm-form="delete-type-<?= (int)$leaveType['id'] ?>"
                                            data-confirm-title="Delete Leave Type?"
                                            data-confirm-message="This will permanently remove the leave type if it has no requests yet."
                                        >Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4"><div class="empty-state">No leave types found.</div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</article>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
