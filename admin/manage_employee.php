<?php
require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);

$message = '';
$id = (int)($_GET['id'] ?? 0);
$employee = null;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
    $stmt->execute([$id]);
    $employee = $stmt->fetch();
}

$formValues = $employee ?: [
    'id' => 0,
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'birthday' => '',
    'phone_number' => '',
    'address' => '',
    'email' => '',
    'company' => '',
    'role' => 'employee',
];
$companyOptions = ['Northstar Operations', 'Summit Services', 'BrightPath Solutions', 'Others'];
$existingCompanyRows = $pdo->query('SELECT DISTINCT company FROM employees WHERE company <> "" ORDER BY company')->fetchAll(PDO::FETCH_COLUMN);
$companyOptions = array_values(array_unique(array_merge($companyOptions, array_map('strval', $existingCompanyRows))));
if (($formValues['company'] ?? '') !== '' && !in_array((string)$formValues['company'], $companyOptions, true)) {
    $companyOptions[] = (string)$formValues['company'];
}
sort($companyOptions, SORT_NATURAL | SORT_FLAG_CASE);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Your session token is invalid. Please try again.';
    } else {
        $action = $_POST['form_action'] ?? 'save';
        $id = (int)($_POST['id'] ?? 0);
        $firstName = trim($_POST['first_name'] ?? '');
        $middleName = trim($_POST['middle_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $birthday = trim($_POST['birthday'] ?? '');
        $phoneNumber = trim($_POST['phone_number'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $role = trim($_POST['role'] ?? 'employee');
        $password = $_POST['password'] ?? '';

        $companies = $companyOptions;
        $roles = ['employee', 'admin'];
        $formValues = [
            'id' => $id,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'birthday' => $birthday,
            'phone_number' => $phoneNumber,
            'address' => $address,
            'email' => $email,
            'company' => $company,
            'role' => $role,
        ];

        if ($action === 'reset_password') {
            if ($id <= 0) {
                $message = 'Invalid employee.';
            } elseif (strlen($password) < 8) {
                $message = 'New password must be at least 8 characters.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $lock = $pdo->prepare('SELECT id FROM employees WHERE id = ? FOR UPDATE');
                    $lock->execute([$id]);
                    if (!$lock->fetch()) {
                        throw new RuntimeException('Employee not found.');
                    }
                    $pdo->prepare('UPDATE employees SET password = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
                    logAdminAudit(
                        $pdo,
                        (int)$_SESSION['user_id'],
                        'reset_employee_password',
                        $id,
                        'Administrator reset employee password.',
                        ['password' => '[redacted]'],
                        ['password' => '[redacted]'],
                        'employee',
                        $id
                    );
                    $pdo->commit();
                    setFlash('success', 'Password reset and audit logged.');
                    redirect('employees.php');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $message = userFacingException($e, 'Password could not be reset.');
                }
            }
        } else {
            if (
                $firstName === '' ||
                $middleName === '' ||
                $lastName === '' ||
                !isValidDateValue($birthday) ||
                $phoneNumber === '' ||
                $address === '' ||
                !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                !in_array($company, $companies, true) ||
                !in_array($role, $roles, true)
            ) {
                $message = 'Please fill in the required fields.';
            } elseif ($id === 0 && strlen($password) < 8) {
                $message = 'A new employee password must be at least 8 characters.';
            } else {
                $emailCheck = $pdo->prepare('SELECT id FROM employees WHERE email = ? AND id != ?');
                $emailCheck->execute([$email, $id]);
                if ($emailCheck->fetch()) {
                    $message = 'That email address is already in use.';
                } elseif ($id > 0) {
                    try {
                        $pdo->beginTransaction();
                        $lock = $pdo->prepare('SELECT * FROM employees WHERE id = ? FOR UPDATE');
                        $lock->execute([$id]);
                        $oldValues = $lock->fetch();
                        if (!$oldValues) {
                            throw new RuntimeException('Employee not found.');
                        }

                        $adminLock = $pdo->query('SELECT id FROM employees WHERE role = "admin" AND active = 1 FOR UPDATE');
                        $activeAdminIds = $adminLock->fetchAll(PDO::FETCH_COLUMN);
                        if (
                            strtolower((string)$oldValues['role']) === 'admin'
                            && $role !== 'admin'
                            && (int)$oldValues['active'] === 1
                            && count($activeAdminIds) <= 1
                        ) {
                            throw new RuntimeException('The final active administrator cannot be changed to an employee role.');
                        }

                        $stmt = $pdo->prepare('UPDATE employees SET first_name = ?, middle_name = ?, last_name = ?, birthday = ?, phone_number = ?, address = ?, email = ?, company = ?, role = ? WHERE id = ?');
                        $stmt->execute([$firstName, $middleName, $lastName, $birthday, $phoneNumber, $address, strtolower($email), $company, $role, $id]);
                        $newStmt = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
                        $newStmt->execute([$id]);
                        $newValues = $newStmt->fetch() ?: [];
                        unset($oldValues['password'], $newValues['password']);
                        logAdminAudit($pdo, (int)$_SESSION['user_id'], 'edit_employee', $id, 'Employee profile updated.', $oldValues, $newValues, 'employee', $id);
                        $pdo->commit();
                        setFlash('success', 'Employee updated and audit logged.');
                        redirect('employees.php');
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $message = userFacingException($e, 'Employee could not be updated.');
                    }
                } elseif ($id === 0) {
                    try {
                        $pdo->beginTransaction();
                        $hashed = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare('INSERT INTO employees (first_name, middle_name, last_name, birthday, phone_number, address, email, password, company, role, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
                        $stmt->execute([$firstName, $middleName, $lastName, $birthday, $phoneNumber, $address, strtolower($email), $hashed, $company, $role]);
                        $newId = (int)$pdo->lastInsertId();
                        $newStmt = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
                        $newStmt->execute([$newId]);
                        $newValues = $newStmt->fetch() ?: [];
                        unset($newValues['password']);
                        logAdminAudit($pdo, (int)$_SESSION['user_id'], 'add_employee', $newId, 'Employee account created.', null, $newValues, 'employee', $newId);
                        $pdo->commit();
                        setFlash('success', 'Employee created and audit logged.');
                        redirect('employees.php');
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Employee creation failed: ' . $e->getMessage());
                        $message = 'Employee could not be created.';
                    }
                }
            }
        }
    }
}

$companyName = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$pageTitle = $employee ? 'Edit Employee' : 'Add Employee';
$activeSubPage = $employee ? 'employee_list' : 'add_employee';
include __DIR__ . '/../includes/admin_layout_start.php';
?>
<section class="page-header">
    <h1><?= $employee ? 'Edit Employee' : 'Add Employee' ?></h1>
    <p>Manage employee profile details and account settings.</p>
</section>

<article class="content-card">
    <div class="card-header">
        <h3>Employee Profile</h3>
        <a href="employees.php" class="btn btn-secondary">Back to List</a>
    </div>
    <?php if ($message): ?><div class="message"><?= h($message) ?></div><?php endif; ?>

    <form method="post" id="profile-form" class="form-layout">
        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="id" value="<?= h($formValues['id'] ?? 0) ?>">
        <input type="hidden" name="form_action" value="save">
        <div class="form-grid-3">
            <div><label class="required">First Name</label><input type="text" name="first_name" value="<?= h($formValues['first_name'] ?? '') ?>" required></div>
            <div><label class="required">Middle Name</label><input type="text" name="middle_name" value="<?= h($formValues['middle_name'] ?? '') ?>" required></div>
            <div><label class="required">Last Name</label><input type="text" name="last_name" value="<?= h($formValues['last_name'] ?? '') ?>" required></div>
        </div>
        <div class="form-grid-2">
            <div><label class="required">Birthday</label><input type="date" name="birthday" value="<?= h($formValues['birthday'] ?? '') ?>" required></div>
            <div><label class="required">Phone Number</label><input type="text" name="phone_number" value="<?= h($formValues['phone_number'] ?? '') ?>" required></div>
        </div>
        <div><label class="required">Address</label><input type="text" name="address" value="<?= h($formValues['address'] ?? '') ?>" required></div>
        <div><label class="required">Email</label><input type="email" name="email" value="<?= h($formValues['email'] ?? '') ?>" required></div>
        <div class="form-grid-2">
            <div>
                <label class="required">Company</label>
                <select name="company" required>
                    <option value="">Select Company</option>
                    <?php foreach ($companyOptions as $companyOption): ?>
                        <option value="<?= h($companyOption) ?>" <?= (($formValues['company'] ?? '') === $companyOption) ? 'selected' : '' ?>><?= h($companyOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="required">Role</label>
                <select name="role">
                    <option value="employee" <?= (($formValues['role'] ?? 'employee') === 'employee') ? 'selected' : '' ?>>Employee</option>
                    <option value="admin" <?= (($formValues['role'] ?? 'employee') === 'admin') ? 'selected' : '' ?>>Admin</option>
                </select>
            </div>
        </div>
        <?php if (!$employee): ?>
            <div><label class="required">Password</label><input type="password" name="password" required minlength="8"></div>
        <?php endif; ?>
        <div><button type="submit">Save Employee</button></div>
    </form>
</article>

<?php if ($employee): ?>
<article class="content-card" style="margin-top:14px;">
    <div class="card-header"><h3>Reset Password</h3></div>
    <p class="muted">Set a new password for this employee. Minimum 8 characters.</p>
    <form method="post" id="reset-pw-form" class="form-layout">
        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int)($formValues['id'] ?? 0) ?>">
        <input type="hidden" name="form_action" value="reset_password">
        <div><label class="required">New Password</label><input type="password" name="password" required minlength="8"></div>
        <div>
            <button type="button" class="danger"
                data-confirm-form="reset-pw-form"
                data-confirm-title="Reset Password?"
                data-confirm-message="This will immediately change the employee's login password.">Reset Password</button>
        </div>
    </form>
</article>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
