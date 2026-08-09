<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireLogin($pdo);
applyTimezone($pdo);

$employeeId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Your session token is invalid. Please try again.');
        redirect('profile.php');
    }

    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'update_contact') {
            $validated = validateEmployeeContactProfile(
                (string)($_POST['email'] ?? ''),
                (string)($_POST['phone_number'] ?? ''),
                (string)($_POST['address'] ?? '')
            );
            if (!$validated['valid']) {
                throw new RuntimeException(implode(' ', $validated['errors']));
            }

            $pdo->beginTransaction();
            $lockStmt = $pdo->prepare('SELECT id, email, password FROM employees
                WHERE id = ? AND active = 1 FOR UPDATE');
            $lockStmt->execute([$employeeId]);
            $lockedEmployee = $lockStmt->fetch();
            if (!$lockedEmployee) {
                throw new RuntimeException('Your active employee account could not be found.');
            }

            $emailChanged = strtolower((string)$lockedEmployee['email']) !== (string)$validated['email'];
            if ($emailChanged && !password_verify((string)($_POST['current_password'] ?? ''), (string)$lockedEmployee['password'])) {
                throw new RuntimeException('Enter your current password to change your login email.');
            }

            $duplicateStmt = $pdo->prepare('SELECT id FROM employees WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1');
            $duplicateStmt->execute([$validated['email'], $employeeId]);
            if ($duplicateStmt->fetch()) {
                throw new RuntimeException('That email address is already in use.');
            }

            $updateStmt = $pdo->prepare('UPDATE employees
                SET email = ?, phone_number = ?, address = ?
                WHERE id = ? AND active = 1');
            $updateStmt->execute([
                $validated['email'],
                $validated['phone_number'],
                $validated['address'],
                $employeeId,
            ]);
            $pdo->commit();
            setFlash('success', $emailChanged
                ? 'Profile updated. Use your new email address the next time you sign in.'
                : 'Contact information updated.');
            redirect('profile.php');
        }

        if ($action === 'change_password') {
            $pdo->beginTransaction();
            $lockStmt = $pdo->prepare('SELECT id, password FROM employees
                WHERE id = ? AND active = 1 FOR UPDATE');
            $lockStmt->execute([$employeeId]);
            $lockedEmployee = $lockStmt->fetch();
            if (!$lockedEmployee) {
                throw new RuntimeException('Your active employee account could not be found.');
            }

            $passwordValidation = validateEmployeePasswordChange(
                (string)$lockedEmployee['password'],
                (string)($_POST['current_password'] ?? ''),
                (string)($_POST['new_password'] ?? ''),
                (string)($_POST['confirm_password'] ?? '')
            );
            if (!$passwordValidation['valid']) {
                throw new RuntimeException(implode(' ', $passwordValidation['errors']));
            }

            $newPasswordHash = password_hash((string)$_POST['new_password'], PASSWORD_DEFAULT);
            if ($newPasswordHash === false) {
                throw new RuntimeException('The new password could not be secured.');
            }
            $updateStmt = $pdo->prepare('UPDATE employees SET password = ? WHERE id = ? AND active = 1');
            $updateStmt->execute([$newPasswordHash, $employeeId]);
            $pdo->commit();
            session_regenerate_id(true);
            $_SESSION['last_activity'] = time();
            setFlash('success', 'Password changed successfully.');
            redirect('profile.php');
        }

        throw new RuntimeException('Unsupported profile action.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlash('error', $e->getMessage());
        redirect('profile.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Employee profile update failed: ' . $e->getMessage());
        setFlash('error', 'Your profile could not be updated. Please try again.');
        redirect('profile.php');
    }
}

$employeeStmt = $pdo->prepare('SELECT id, first_name, middle_name, last_name, birthday,
        phone_number, address, email, company, role, active, created_at, updated_at
    FROM employees WHERE id = ? LIMIT 1');
$employeeStmt->execute([$employeeId]);
$employee = $employeeStmt->fetch();
if (!$employee) {
    destroyUserSession();
    redirect('../pages/login.php');
}

$fullName = trim(implode(' ', array_filter([
    (string)$employee['first_name'],
    (string)($employee['middle_name'] ?? ''),
    (string)$employee['last_name'],
], static fn(string $part): bool => trim($part) !== '')));
$initials = strtoupper(substr((string)$employee['first_name'], 0, 1) . substr((string)$employee['last_name'], 0, 1));
$currentSchedule = getEmployeeScheduleForDate($pdo, $employeeId, date('Y-m-d'));

$attendanceCountStmt = $pdo->prepare('SELECT COUNT(*) FROM attendance
    WHERE employee_id = ? AND voided_at IS NULL');
$attendanceCountStmt->execute([$employeeId]);
$attendanceCount = (int)$attendanceCountStmt->fetchColumn();
$approvedLeaveStmt = $pdo->prepare('SELECT COUNT(*) FROM leave_requests
    WHERE employee_id = ? AND status = "approved"');
$approvedLeaveStmt->execute([$employeeId]);
$approvedLeaveCount = (int)$approvedLeaveStmt->fetchColumn();

$pageTitle = 'My Profile';
$activePage = 'profile';
include __DIR__ . '/../includes/employee_layout_start.php';
?>

<section class="employee-profile-hero">
    <div class="employee-profile-avatar" aria-hidden="true"><?= h($initials !== '' ? $initials : 'EP') ?></div>
    <div class="employee-profile-heading">
        <p class="employee-eyebrow">Employee account</p>
        <h2><?= h($fullName) ?></h2>
        <p><?= h((string)$employee['company']) ?> · <?= h(ucfirst((string)$employee['role'])) ?> · #<?= str_pad((string)$employeeId, 6, '0', STR_PAD_LEFT) ?></p>
    </div>
    <div class="employee-profile-hero-stats" aria-label="Employee activity summary">
        <span><strong><?= number_format($attendanceCount) ?></strong> Attendance records</span>
        <span><strong><?= number_format($approvedLeaveCount) ?></strong> Approved requests</span>
    </div>
</section>

<div class="employee-profile-grid">
    <div class="employee-profile-primary">
        <section class="dashboard-card employee-profile-card">
            <div class="section-header">
                <div>
                    <p class="employee-eyebrow">Contact details</p>
                    <h3>Personal Information</h3>
                </div>
                <span class="pill pill-green">Active account</span>
            </div>

            <div class="employee-profile-readonly-grid">
                <div class="profile-readonly-field"><span>Full Name</span><strong><?= h($fullName) ?></strong></div>
                <div class="profile-readonly-field"><span>Birthday</span><strong><?= h(formatEmployeeDate($employee['birthday'] !== null ? (string)$employee['birthday'] : null)) ?></strong></div>
            </div>
            <p class="profile-managed-note">Names and birthday are managed by HR. Contact an administrator if they need correction.</p>

            <form method="post" class="form-layout employee-profile-form">
                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_contact">
                <div class="form-grid-2">
                    <div>
                        <label class="required" for="profile-email">Login Email</label>
                        <input id="profile-email" type="email" name="email" maxlength="150" required autocomplete="email" value="<?= h((string)$employee['email']) ?>">
                        <small class="field-help">Changing this also changes the email used to sign in.</small>
                    </div>
                    <div>
                        <label class="required" for="profile-phone">Phone Number</label>
                        <input id="profile-phone" type="tel" name="phone_number" maxlength="50" required autocomplete="tel" value="<?= h((string)($employee['phone_number'] ?? '')) ?>">
                    </div>
                </div>
                <div>
                    <label class="required" for="profile-address">Address</label>
                    <textarea id="profile-address" name="address" rows="3" maxlength="1000" required autocomplete="street-address"><?= h((string)($employee['address'] ?? '')) ?></textarea>
                </div>
                <div>
                    <label for="profile-current-password">Current Password</label>
                    <input id="profile-current-password" type="password" name="current_password" autocomplete="current-password">
                    <small class="field-help">Only required when changing your login email.</small>
                </div>
                <div class="profile-form-actions">
                    <button type="submit" class="btn btn-primary" data-loading-text="Saving…">Save Contact Information</button>
                </div>
            </form>
        </section>

        <section class="dashboard-card employee-profile-card employee-password-card">
            <div class="section-header">
                <div>
                    <p class="employee-eyebrow">Account security</p>
                    <h3>Change Password</h3>
                </div>
                <svg class="profile-section-icon" aria-hidden="true" viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg>
            </div>
            <p class="muted">Use at least eight characters and choose a password different from your current one.</p>
            <form method="post" class="form-layout employee-profile-form" id="employee-password-form">
                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="change_password">
                <div>
                    <label class="required" for="password-current">Current Password</label>
                    <input id="password-current" type="password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="form-grid-2">
                    <div>
                        <label class="required" for="password-new">New Password</label>
                        <input id="password-new" type="password" name="new_password" minlength="8" maxlength="255" required autocomplete="new-password">
                    </div>
                    <div>
                        <label class="required" for="password-confirm">Confirm New Password</label>
                        <input id="password-confirm" type="password" name="confirm_password" minlength="8" maxlength="255" required autocomplete="new-password">
                    </div>
                </div>
                <div class="profile-form-actions">
                    <button type="submit" class="btn btn-primary" data-confirm-form="employee-password-form" data-confirm-title="Change Password?" data-confirm-message="Your login password will be updated immediately.">Change Password</button>
                </div>
            </form>
        </section>
    </div>

    <aside class="employee-profile-secondary" aria-label="Employment information">
        <section class="dashboard-card employee-profile-card">
            <div class="section-header">
                <div>
                    <p class="employee-eyebrow">HR-managed</p>
                    <h3>Employment Details</h3>
                </div>
            </div>
            <dl class="profile-detail-list">
                <div><dt>Employee Number</dt><dd>EMP-<?= str_pad((string)$employeeId, 6, '0', STR_PAD_LEFT) ?></dd></div>
                <div><dt>Company</dt><dd><?= h((string)$employee['company']) ?></dd></div>
                <div><dt>Role</dt><dd><?= h(ucfirst((string)$employee['role'])) ?></dd></div>
                <div><dt>Status</dt><dd><span class="pill pill-green">Active</span></dd></div>
                <div><dt>Member Since</dt><dd><?= h(formatEmployeeDate(substr((string)$employee['created_at'], 0, 10))) ?></dd></div>
            </dl>
        </section>

        <section class="dashboard-card employee-profile-card">
            <div class="section-header">
                <div>
                    <p class="employee-eyebrow">Current assignment</p>
                    <h3>Work Schedule</h3>
                </div>
            </div>
            <dl class="profile-detail-list">
                <div><dt>Shift</dt><dd><?= h((string)$currentSchedule['shift_name']) ?></dd></div>
                <div><dt>Work Hours</dt><dd><?= h((string)$currentSchedule['work_start_time']) ?>–<?= h((string)$currentSchedule['work_end_time']) ?></dd></div>
                <div><dt>Work Days</dt><dd><?= h(formatShiftWorkDays($currentSchedule['work_days'])) ?></dd></div>
                <div><dt>Timezone</dt><dd><?= h((string)$currentSchedule['timezone']) ?></dd></div>
                <div><dt>Grace Period</dt><dd><?= (int)$currentSchedule['grace_period_minutes'] ?> minutes</dd></div>
            </dl>
        </section>
    </aside>
</div>

<?php include __DIR__ . '/../includes/employee_layout_end.php'; ?>
