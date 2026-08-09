<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

$adminId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request token.');
        redirect('shifts.php');
    }
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'create_shift') {
        $name = trim((string)($_POST['name'] ?? ''));
        $timezone = trim((string)($_POST['timezone'] ?? ''));
        $startTime = trim((string)($_POST['start_time'] ?? ''));
        $endTime = trim((string)($_POST['end_time'] ?? ''));
        $grace = (int)($_POST['grace_period_minutes'] ?? 0);
        $workDays = normalizeShiftWorkDays($_POST['work_days'] ?? []);
        if ($name === '' || mb_strlen($name) > 120 || !in_array($timezone, DateTimeZone::listIdentifiers(), true)
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime)
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endTime)
            || $grace < 0 || $grace > 120 || !$workDays) {
            setFlash('error', 'Complete the shift with a unique name, valid times, timezone, grace period, and at least one workday.');
            redirect('shifts.php');
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO work_shifts
                (name, timezone, start_time, end_time, grace_period_minutes, work_days, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $timezone, $startTime, $endTime, $grace, implode(',', $workDays), $adminId]);
            $shiftId = (int)$pdo->lastInsertId();
            logAdminAudit($pdo, $adminId, 'create_work_shift', null, $name,
                null, ['name' => $name, 'timezone' => $timezone, 'start_time' => $startTime, 'end_time' => $endTime, 'grace_period_minutes' => $grace, 'work_days' => $workDays],
                'work_shift', $shiftId);
            setFlash('success', 'Shift template created. Existing assignments remain historically unchanged.');
        } catch (PDOException $e) {
            setFlash('error', (string)$e->getCode() === '23000' ? 'A shift with that name already exists.' : 'The shift template could not be created.');
        }
        redirect('shifts.php');
    }

    if ($action === 'toggle_shift') {
        $shiftId = (int)($_POST['shift_id'] ?? 0);
        $shiftStmt = $pdo->prepare('SELECT * FROM work_shifts WHERE id = ? LIMIT 1');
        $shiftStmt->execute([$shiftId]);
        $shift = $shiftStmt->fetch();
        if (!$shift) {
            setFlash('error', 'Shift template not found.');
            redirect('shifts.php');
        }
        $newActive = (int)$shift['active'] === 1 ? 0 : 1;
        $pdo->prepare('UPDATE work_shifts SET active = ? WHERE id = ?')->execute([$newActive, $shiftId]);
        logAdminAudit($pdo, $adminId, $newActive ? 'reactivate_work_shift' : 'deactivate_work_shift', null,
            (string)$shift['name'], ['active' => (int)$shift['active']], ['active' => $newActive], 'work_shift', $shiftId);
        setFlash('success', $newActive ? 'Shift template reactivated.' : 'Shift template deactivated. Existing assignments remain effective.');
        redirect('shifts.php');
    }

    if ($action === 'assign_shift') {
        $targetType = trim((string)($_POST['target_type'] ?? 'employee'));
        $shiftId = (int)($_POST['shift_id'] ?? 0);
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $company = trim((string)($_POST['company'] ?? ''));
        $effectiveFrom = trim((string)($_POST['effective_from'] ?? ''));
        $effectiveTo = trim((string)($_POST['effective_to'] ?? ''));
        if (!in_array($targetType, ['employee', 'company'], true) || !isValidDateValue($effectiveFrom)
            || ($effectiveTo !== '' && (!isValidDateValue($effectiveTo) || $effectiveTo < $effectiveFrom))) {
            setFlash('error', 'Choose a valid assignment target and effective date range.');
            redirect('shifts.php#shift-assignments');
        }
        $shiftStmt = $pdo->prepare('SELECT id, name FROM work_shifts WHERE id = ? AND active = 1 LIMIT 1');
        $shiftStmt->execute([$shiftId]);
        $shift = $shiftStmt->fetch();
        if (!$shift) {
            setFlash('error', 'Select an active shift template.');
            redirect('shifts.php#shift-assignments');
        }
        $rangeEnd = $effectiveTo !== '' ? $effectiveTo : '9999-12-31';
        if ($targetType === 'employee') {
            $employeeStmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) AS name FROM employees
                WHERE id = ? AND role = "employee" AND active = 1 LIMIT 1');
            $employeeStmt->execute([$employeeId]);
            $targetName = $employeeStmt->fetchColumn();
            if ($targetName === false) {
                setFlash('error', 'Select an active employee.');
                redirect('shifts.php#shift-assignments');
            }
            $overlap = $pdo->prepare('SELECT COUNT(*) FROM employee_shift_assignments
                WHERE employee_id = ? AND effective_from <= ? AND COALESCE(effective_to, "9999-12-31") >= ?');
            $overlap->execute([$employeeId, $rangeEnd, $effectiveFrom]);
            if ((int)$overlap->fetchColumn() > 0) {
                setFlash('error', 'That employee already has a shift assignment overlapping the selected period.');
                redirect('shifts.php#shift-assignments');
            }
            $stmt = $pdo->prepare('INSERT INTO employee_shift_assignments
                (employee_id, shift_id, effective_from, effective_to, created_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$employeeId, $shiftId, $effectiveFrom, $effectiveTo !== '' ? $effectiveTo : null, $adminId]);
            $assignmentId = (int)$pdo->lastInsertId();
            logAdminAudit($pdo, $adminId, 'assign_employee_shift', $employeeId,
                $targetName . ' → ' . $shift['name'], null,
                ['shift_id' => $shiftId, 'effective_from' => $effectiveFrom, 'effective_to' => $effectiveTo ?: null],
                'employee_shift_assignment', $assignmentId);
        } else {
            $companyStmt = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE role = "employee" AND company = ?');
            $companyStmt->execute([$company]);
            if ($company === '' || (int)$companyStmt->fetchColumn() === 0) {
                setFlash('error', 'Select a valid company.');
                redirect('shifts.php#shift-assignments');
            }
            $overlap = $pdo->prepare('SELECT COUNT(*) FROM company_shift_assignments
                WHERE company = ? AND effective_from <= ? AND COALESCE(effective_to, "9999-12-31") >= ?');
            $overlap->execute([$company, $rangeEnd, $effectiveFrom]);
            if ((int)$overlap->fetchColumn() > 0) {
                setFlash('error', 'That company already has a shift assignment overlapping the selected period.');
                redirect('shifts.php#shift-assignments');
            }
            $stmt = $pdo->prepare('INSERT INTO company_shift_assignments
                (company, shift_id, effective_from, effective_to, created_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$company, $shiftId, $effectiveFrom, $effectiveTo !== '' ? $effectiveTo : null, $adminId]);
            $assignmentId = (int)$pdo->lastInsertId();
            logAdminAudit($pdo, $adminId, 'assign_company_shift', null,
                $company . ' → ' . $shift['name'], null,
                ['shift_id' => $shiftId, 'effective_from' => $effectiveFrom, 'effective_to' => $effectiveTo ?: null],
                'company_shift_assignment', $assignmentId);
        }
        setFlash('success', 'Effective-dated shift assignment created.');
        redirect('shifts.php#shift-assignments');
    }

    if ($action === 'end_assignment') {
        $targetType = trim((string)($_POST['target_type'] ?? ''));
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $effectiveTo = trim((string)($_POST['effective_to'] ?? ''));
        $table = $targetType === 'company' ? 'company_shift_assignments' : ($targetType === 'employee' ? 'employee_shift_assignments' : '');
        if ($table === '' || !isValidDateValue($effectiveTo)) {
            setFlash('error', 'Choose a valid assignment end date.');
            redirect('shifts.php#shift-assignments');
        }
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->execute([$assignmentId]);
        $assignment = $stmt->fetch();
        if (!$assignment || $effectiveTo < (string)$assignment['effective_from']) {
            setFlash('error', 'The end date cannot precede the assignment start date.');
            redirect('shifts.php#shift-assignments');
        }
        $scopeColumn = $targetType === 'employee' ? 'employee_id' : 'company';
        $overlap = $pdo->prepare("SELECT COUNT(*) FROM {$table}
            WHERE {$scopeColumn} = ? AND id <> ? AND effective_from <= ?
              AND COALESCE(effective_to, '9999-12-31') >= ?");
        $overlap->execute([$assignment[$scopeColumn], $assignmentId, $effectiveTo, $assignment['effective_from']]);
        if ((int)$overlap->fetchColumn() > 0) {
            setFlash('error', 'That end date would overlap another assignment for the same target.');
            redirect('shifts.php#shift-assignments');
        }
        $pdo->prepare("UPDATE {$table} SET effective_to = ? WHERE id = ?")->execute([$effectiveTo, $assignmentId]);
        logAdminAudit($pdo, $adminId, 'end_' . $targetType . '_shift_assignment', $targetType === 'employee' ? (int)$assignment['employee_id'] : null,
            'Assignment ended ' . $effectiveTo, $assignment, array_merge($assignment, ['effective_to' => $effectiveTo]),
            $targetType . '_shift_assignment', $assignmentId);
        setFlash('success', 'Shift assignment end date updated.');
        redirect('shifts.php#shift-assignments');
    }

    setFlash('error', 'Unsupported shift action.');
    redirect('shifts.php');
}

$shifts = $pdo->query('SELECT ws.*, CONCAT(a.first_name, " ", a.last_name) AS creator_name,
        (SELECT COUNT(*) FROM employee_shift_assignments esa WHERE esa.shift_id = ws.id) AS employee_assignment_count,
        (SELECT COUNT(*) FROM company_shift_assignments csa WHERE csa.shift_id = ws.id) AS company_assignment_count
    FROM work_shifts ws LEFT JOIN employees a ON a.id = ws.created_by ORDER BY ws.active DESC, ws.name')->fetchAll();
$activeShifts = array_values(array_filter($shifts, static fn(array $shift): bool => (int)$shift['active'] === 1));
$employees = $pdo->query('SELECT id, CONCAT(first_name, " ", last_name) AS name, company
    FROM employees WHERE role = "employee" AND active = 1 ORDER BY first_name, last_name')->fetchAll();
$companies = $pdo->query('SELECT DISTINCT company FROM employees WHERE role = "employee" AND company <> "" ORDER BY company')->fetchAll(PDO::FETCH_COLUMN);

$assignmentSql = 'SELECT "employee" AS target_type, esa.id, esa.effective_from, esa.effective_to, esa.created_at,
        e.id AS employee_id, CONCAT(e.first_name, " ", e.last_name) AS target_name, e.company,
        ws.name AS shift_name, ws.start_time, ws.end_time, ws.timezone, ws.work_days
    FROM employee_shift_assignments esa INNER JOIN employees e ON e.id = esa.employee_id
    INNER JOIN work_shifts ws ON ws.id = esa.shift_id
    UNION ALL
    SELECT "company", csa.id, csa.effective_from, csa.effective_to, csa.created_at,
        NULL, csa.company, csa.company, ws.name, ws.start_time, ws.end_time, ws.timezone, ws.work_days
    FROM company_shift_assignments csa INNER JOIN work_shifts ws ON ws.id = csa.shift_id
    ORDER BY effective_from DESC, id DESC';
$assignments = $pdo->query($assignmentSql)->fetchAll();
$timezones = DateTimeZone::listIdentifiers();
$defaultSchedule = getAttendanceSchedule($pdo);
$companyName = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$pageTitle = 'Shift Schedules';
$activeSubPage = 'shift_schedules';
include __DIR__ . '/../includes/admin_layout_start.php';
?>

<section class="page-header"><div><h1>Shift Schedules</h1><p>Create immutable schedule templates and assign them by employee or company with effective dates.</p></div></section>

<section class="shift-admin-grid">
    <article class="content-card">
        <div class="card-header"><div><h3>Create Shift Template</h3><p class="muted">Create a new template when schedule rules change; existing assignments keep their referenced template.</p></div></div>
        <form method="post" class="form-layout">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="create_shift">
            <div><label class="required">Shift Name</label><input type="text" name="name" maxlength="120" placeholder="Night Shift" required></div>
            <div class="form-grid-2"><div><label class="required">Start Time</label><input type="time" name="start_time" value="<?= h($defaultSchedule['work_start_time']) ?>" required></div><div><label class="required">End Time</label><input type="time" name="end_time" value="<?= h($defaultSchedule['work_end_time']) ?>" required></div></div>
            <div class="form-grid-2"><div><label class="required">Timezone</label><select name="timezone" required><?php foreach ($timezones as $timezone): ?><option value="<?= h($timezone) ?>" <?= $timezone === $defaultSchedule['timezone'] ? 'selected' : '' ?>><?= h($timezone) ?></option><?php endforeach; ?></select></div><div><label class="required">Grace Period</label><div class="input-with-suffix"><input type="number" name="grace_period_minutes" min="0" max="120" value="<?= (int)$defaultSchedule['grace_period_minutes'] ?>" required><span>minutes</span></div></div></div>
            <fieldset class="shift-workdays"><legend>Scheduled Workdays</legend><?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $day => $label): ?><label><input type="checkbox" name="work_days[]" value="<?= $day ?>" <?= $day <= 5 ? 'checked' : '' ?>><?= h($label) ?></label><?php endforeach; ?></fieldset>
            <p class="muted">An end time at or before the start time is treated as an overnight shift.</p>
            <button type="submit" class="btn btn-primary">Create Shift</button>
        </form>
    </article>

    <article class="content-card" id="shift-assignments">
        <div class="card-header"><div><h3>Assign Shift</h3><p class="muted">Employee assignments override company assignments for overlapping dates.</p></div></div>
        <?php if ($activeShifts): ?>
        <form method="post" class="form-layout" id="shift-assignment-form">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="assign_shift">
            <div><label class="required">Assignment Scope</label><select name="target_type" id="shift-target-type"><option value="employee">Employee</option><option value="company">Company</option></select></div>
            <div id="shift-employee-target"><label class="required">Employee</label><select name="employee_id"><option value="">Select employee</option><?php foreach ($employees as $employee): ?><option value="<?= (int)$employee['id'] ?>"><?= h($employee['name'] . ' · ' . $employee['company']) ?></option><?php endforeach; ?></select></div>
            <div id="shift-company-target" hidden><label class="required">Company</label><select name="company" disabled><option value="">Select company</option><?php foreach ($companies as $company): ?><option value="<?= h($company) ?>"><?= h($company) ?></option><?php endforeach; ?></select></div>
            <div><label class="required">Shift</label><select name="shift_id" required><option value="">Select active shift</option><?php foreach ($activeShifts as $shift): ?><option value="<?= (int)$shift['id'] ?>"><?= h($shift['name'] . ' · ' . substr((string)$shift['start_time'], 0, 5) . '–' . substr((string)$shift['end_time'], 0, 5)) ?></option><?php endforeach; ?></select></div>
            <div class="form-grid-2"><div><label class="required">Effective From</label><input type="date" name="effective_from" value="<?= h(date('Y-m-d')) ?>" required></div><div><label>Effective To</label><input type="date" name="effective_to"><p class="field-help">Leave blank for no end date.</p></div></div>
            <button type="submit" class="btn btn-primary">Create Assignment</button>
        </form>
        <?php else: ?><div class="message">Create an active shift template before assigning schedules.</div><?php endif; ?>
    </article>
</section>

<article class="content-card">
    <div class="card-header"><div><h3>Shift Templates</h3><p class="muted">Templates cannot be edited after creation, protecting historical schedules.</p></div></div>
    <div class="table-card"><table><thead><tr><th>Shift</th><th>Schedule</th><th>Timezone</th><th>Workdays</th><th>Grace</th><th>Assignments</th><th>Status</th><th>Action</th></tr></thead><tbody>
        <?php if ($shifts): foreach ($shifts as $shift): ?><tr><td><strong><?= h($shift['name']) ?></strong></td><td><?= h(substr((string)$shift['start_time'], 0, 5)) ?>–<?= h(substr((string)$shift['end_time'], 0, 5)) ?><?= (string)$shift['end_time'] <= (string)$shift['start_time'] ? ' <span class="pill pill-purple">Overnight</span>' : '' ?></td><td><?= h($shift['timezone']) ?></td><td><?= h(formatShiftWorkDays($shift['work_days'])) ?></td><td><?= (int)$shift['grace_period_minutes'] ?> min</td><td><?= (int)$shift['employee_assignment_count'] ?> employees · <?= (int)$shift['company_assignment_count'] ?> companies</td><td><?= (int)$shift['active'] === 1 ? '<span class="pill pill-green">Active</span>' : '<span class="pill pill-gray">Inactive</span>' ?></td><td><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="toggle_shift"><input type="hidden" name="shift_id" value="<?= (int)$shift['id'] ?>"><button type="submit" class="link-button"><?= (int)$shift['active'] === 1 ? 'Deactivate' : 'Reactivate' ?></button></form></td></tr><?php endforeach; else: ?><tr><td colspan="8" class="table-empty-cell">No shift templates created yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</article>

<article class="content-card shift-assignment-list">
    <div class="card-header"><div><h3>Assignment History</h3><p class="muted">All employee and company schedule periods.</p></div></div>
    <div class="table-card"><table><thead><tr><th>Scope</th><th>Employee / Company</th><th>Company</th><th>Shift</th><th>Schedule</th><th>Workdays</th><th>Effective Period</th><th>End Assignment</th></tr></thead><tbody>
        <?php if ($assignments): foreach ($assignments as $assignment): ?><tr><td><span class="pill <?= $assignment['target_type'] === 'employee' ? 'pill-blue' : 'pill-purple' ?>"><?= h(ucfirst((string)$assignment['target_type'])) ?></span></td><td><strong><?= h($assignment['target_name']) ?></strong></td><td><?= h($assignment['company']) ?></td><td><?= h($assignment['shift_name']) ?></td><td><?= h(substr((string)$assignment['start_time'], 0, 5)) ?>–<?= h(substr((string)$assignment['end_time'], 0, 5)) ?></td><td><?= h(formatShiftWorkDays($assignment['work_days'])) ?></td><td><?= h(formatEmployeeDate((string)$assignment['effective_from'])) ?> – <?= $assignment['effective_to'] ? h(formatEmployeeDate((string)$assignment['effective_to'])) : 'Ongoing' ?></td><td><form method="post" class="shift-end-form"><input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="end_assignment"><input type="hidden" name="target_type" value="<?= h($assignment['target_type']) ?>"><input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>"><input type="date" name="effective_to" min="<?= h($assignment['effective_from']) ?>" value="<?= h($assignment['effective_to'] ?? '') ?>" required><button type="submit" class="btn btn-secondary btn-sm">Set End</button></form></td></tr><?php endforeach; else: ?><tr><td colspan="8" class="table-empty-cell">No shift assignments yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</article>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
