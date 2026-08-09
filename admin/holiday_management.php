<?php
require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

$adminId = (int)($_SESSION['user_id'] ?? 0);
$editId = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request token.');
        redirect('holiday_management.php');
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $holidayId = (int)($_POST['holiday_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $holidayDate = trim((string)($_POST['holiday_date'] ?? ''));
    $holidayType = trim((string)($_POST['holiday_type'] ?? ''));

    if (in_array($action, ['add', 'edit'], true)) {
        if ($name === '' || $holidayDate === '' || $holidayType === '') {
            setFlash('error', 'Holiday name, date, and type are required.');
            redirect($action === 'edit' ? 'holiday_management.php?edit=' . $holidayId : 'holiday_management.php');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate)) {
            setFlash('error', 'Holiday date must be a valid date.');
            redirect($action === 'edit' ? 'holiday_management.php?edit=' . $holidayId : 'holiday_management.php');
        }

        if (mb_strlen($name) > 150 || mb_strlen($holidayType) > 80) {
            setFlash('error', 'Holiday name/type is too long.');
            redirect($action === 'edit' ? 'holiday_management.php?edit=' . $holidayId : 'holiday_management.php');
        }
    }

    if ($action === 'add') {
        $stmt = $pdo->prepare('INSERT INTO holidays (name, holiday_date, holiday_type, created_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $holidayDate, $holidayType, $adminId > 0 ? $adminId : null]);
        logAdminAudit($pdo, $adminId, 'add_holiday', null, $name . ' on ' . $holidayDate . ' (' . $holidayType . ')');
        setFlash('success', 'Holiday added.');
        redirect('holiday_management.php');
    }

    if ($action === 'edit') {
        if ($holidayId <= 0) {
            setFlash('error', 'Invalid holiday record.');
            redirect('holiday_management.php');
        }

        $stmt = $pdo->prepare('UPDATE holidays SET name = ?, holiday_date = ?, holiday_type = ? WHERE id = ?');
        $stmt->execute([$name, $holidayDate, $holidayType, $holidayId]);
        logAdminAudit($pdo, $adminId, 'edit_holiday', null, 'Holiday #' . $holidayId . ' updated to ' . $name . ' on ' . $holidayDate . ' (' . $holidayType . ')');
        setFlash('success', 'Holiday updated.');
        redirect('holiday_management.php');
    }

    if ($action === 'delete') {
        if ($holidayId <= 0) {
            setFlash('error', 'Invalid holiday record.');
            redirect('holiday_management.php');
        }

        $stmt = $pdo->prepare('SELECT name, holiday_date FROM holidays WHERE id = ? LIMIT 1');
        $stmt->execute([$holidayId]);
        $target = $stmt->fetch();

        $delStmt = $pdo->prepare('DELETE FROM holidays WHERE id = ?');
        $delStmt->execute([$holidayId]);

        if ($target) {
            logAdminAudit($pdo, $adminId, 'delete_holiday', null, 'Deleted holiday: ' . $target['name'] . ' on ' . $target['holiday_date']);
        }

        setFlash('success', 'Holiday deleted.');
        redirect('holiday_management.php');
    }

    setFlash('error', 'Unsupported holiday action.');
    redirect('holiday_management.php');
}

$editHoliday = null;
if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT * FROM holidays WHERE id = ? LIMIT 1');
    $editStmt->execute([$editId]);
    $editHoliday = $editStmt->fetch();
    if (!$editHoliday) {
        setFlash('error', 'Holiday record not found.');
        redirect('holiday_management.php');
    }
}

$listStmt = $pdo->query('SELECT h.*, CONCAT(e.first_name, " ", e.last_name) AS created_by_name
    FROM holidays h
    LEFT JOIN employees e ON e.id = h.created_by
    ORDER BY h.holiday_date DESC, h.id DESC');
$holidays = $listStmt->fetchAll();

$companyName = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$pageTitle = 'Holiday Management';
$activePage = '';
$activeSubPage = 'holidays';
include __DIR__ . '/../includes/admin_layout_start.php';
?>
<section class="page-header">
    <div>
        <h1>Holiday Management</h1>
        <p>Maintain company holidays that appear in the employee leave calendar.</p>
    </div>
</section>

<article class="content-card" data-search-item>
    <div class="card-header">
        <h3><?= $editHoliday ? 'Edit Holiday' : 'Add Holiday' ?></h3>
        <?php if ($editHoliday): ?>
            <a href="holiday_management.php" class="btn btn-secondary btn-sm">Cancel Edit</a>
        <?php endif; ?>
    </div>
    <form method="post" class="form-layout">
        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="action" value="<?= $editHoliday ? 'edit' : 'add' ?>">
        <input type="hidden" name="holiday_id" value="<?= (int)($editHoliday['id'] ?? 0) ?>">

        <div class="form-grid-3">
            <div>
                <label class="required" for="holiday-name">Holiday Name</label>
                <input id="holiday-name" type="text" name="name" required maxlength="150" value="<?= h($editHoliday['name'] ?? '') ?>" placeholder="e.g. New Year's Day">
            </div>
            <div>
                <label class="required" for="holiday-date">Holiday Date</label>
                <input id="holiday-date" type="date" name="holiday_date" required value="<?= h($editHoliday['holiday_date'] ?? '') ?>">
            </div>
            <div>
                <label class="required" for="holiday-type">Holiday Type</label>
                <input id="holiday-type" type="text" name="holiday_type" required maxlength="80" value="<?= h($editHoliday['holiday_type'] ?? '') ?>" placeholder="Regular Holiday">
            </div>
        </div>

        <div>
            <button type="submit" class="btn btn-primary" data-loading-text="Saving...">
                <?= $editHoliday ? 'Update Holiday' : 'Add Holiday' ?>
            </button>
        </div>
    </form>
</article>

<article class="content-card" data-search-item>
    <div class="card-header">
        <h3>Holiday List</h3>
    </div>

    <div class="table-card" data-sticky-head="true" data-table-enhance="true" data-page-size="10">
        <table>
            <thead>
                <tr>
                    <th>Holiday Name</th>
                    <th>Holiday Date</th>
                    <th>Holiday Type</th>
                    <th>Created By</th>
                    <th data-sort="false">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($holidays): ?>
                    <?php foreach ($holidays as $holiday): ?>
                        <tr>
                            <td><?= h($holiday['name']) ?></td>
                            <td><?= h($holiday['holiday_date']) ?></td>
                            <td><?= h($holiday['holiday_type']) ?></td>
                            <td><?= h($holiday['created_by_name'] ?: 'System') ?></td>
                            <td>
                                <div class="leave-action-row">
                                    <a href="holiday_management.php?edit=<?= (int)$holiday['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                                    <form method="post" class="inline-form" id="del-holiday-<?= (int)$holiday['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="holiday_id" value="<?= (int)$holiday['id'] ?>">
                                        <button
                                            type="button"
                                            class="btn btn-danger btn-sm"
                                            data-confirm-form="del-holiday-<?= (int)$holiday['id'] ?>"
                                            data-confirm-title="Delete Holiday?"
                                            data-confirm-message="This holiday will be removed from the calendar."
                                        >Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5"><div class="empty-state">No holidays added yet.</div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</article>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
