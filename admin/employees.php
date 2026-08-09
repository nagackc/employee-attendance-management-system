<?php
require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);

$stmt = $pdo->query('SELECT * FROM employees ORDER BY id DESC');
$employees = $stmt->fetchAll();

$companyName = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$pageTitle = 'Employees';
$activeSubPage = 'employee_list';
include __DIR__ . '/../includes/admin_layout_start.php';
?>
<section class="page-header">
    <h1>Employees</h1>
    <p>Manage employee records and account access.</p>
</section>

<article class="content-card" data-search-item>
    <div class="card-header">
        <h3>Employee Directory</h3>
        <a href="manage_employee.php" class="button-link">➕ Add Employee</a>
    </div>

    <div class="table-card" data-sticky-head="true" data-table-enhance="true" data-page-size="10">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Birthday</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Company</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th data-sort="false">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $employee): ?>
                    <tr data-search-item>
                        <td><?= h(trim($employee['first_name'] . ' ' . ($employee['middle_name'] ?? '') . ' ' . $employee['last_name'])) ?></td>
                        <td><?= h($employee['birthday'] ?? '') ?></td>
                        <td><?= h($employee['phone_number'] ?? '') ?></td>
                        <td><?= h($employee['email']) ?></td>
                        <td><?= h($employee['company']) ?></td>
                        <td>
                            <span class="pill <?= strtolower($employee['role']) === 'admin' ? 'pill-blue' : 'pill-gray' ?>">
                                <?= h(ucfirst($employee['role'])) ?>
                            </span>
                        </td>
                        <td><?= (int)$employee['active'] === 1 ? '<span class="pill pill-green">Active</span>' : '<span class="pill pill-gray">Deactivated</span>' ?></td>
                        <td>
                            <a href="manage_employee.php?id=<?= (int)$employee['id'] ?>">Edit</a>
                            <?php if ((int)$employee['id'] !== (int)$_SESSION['user_id'] && (int)$employee['active'] === 1): ?>
                                <form method="post" action="delete_employee.php" class="inline-form" id="del-emp-<?= (int)$employee['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$employee['id'] ?>">
                                    <input type="hidden" name="action" value="deactivate">
                                    <button type="button" class="link-button danger-link"
                                        data-confirm-form="del-emp-<?= (int)$employee['id'] ?>"
                                        data-confirm-title="Deactivate Employee?"
                                        data-confirm-message="Login access will be disabled while all historical records remain intact.">Deactivate</button>
                                </form>
                            <?php elseif ((int)$employee['active'] === 0): ?>
                                <form method="post" action="delete_employee.php" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$employee['id'] ?>">
                                    <input type="hidden" name="action" value="reactivate">
                                    <button type="submit" class="link-button">Reactivate</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</article>
<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
