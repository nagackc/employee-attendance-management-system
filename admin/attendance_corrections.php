<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

$schedule = getAttendanceSchedule($pdo);
$statusFilter = trim((string)($_GET['status'] ?? 'pending'));
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'cancelled', 'all'], true)) {
    $statusFilter = 'pending';
}
$companyFilter = trim((string)($_GET['company'] ?? ''));
$employeeFilter = (int)($_GET['employee_id'] ?? 0);
$dateFilter = trim((string)($_GET['date'] ?? ''));

$employees = $pdo->query('SELECT id, CONCAT(first_name, " ", last_name) AS name, company, active
    FROM employees WHERE role = "employee" ORDER BY active DESC, first_name, last_name')->fetchAll();
$companies = $pdo->query('SELECT DISTINCT company FROM employees WHERE role = "employee" AND company <> "" ORDER BY company')->fetchAll(PDO::FETCH_COLUMN);

$sql = 'SELECT acr.*, e.first_name, e.last_name, e.company, e.active,
        a.schedule_timezone, CONCAT(r.first_name, " ", r.last_name) AS reviewer_name
    FROM attendance_correction_requests acr
    INNER JOIN employees e ON e.id = acr.employee_id
    LEFT JOIN attendance a ON a.id = acr.attendance_id
    LEFT JOIN employees r ON r.id = acr.reviewed_by
    WHERE 1=1';
$params = [];
if ($statusFilter !== 'all') { $sql .= ' AND acr.status = ?'; $params[] = $statusFilter; }
if ($companyFilter !== '') { $sql .= ' AND e.company = ?'; $params[] = $companyFilter; }
if ($employeeFilter > 0) { $sql .= ' AND acr.employee_id = ?'; $params[] = $employeeFilter; }
if ($dateFilter !== '') { $sql .= ' AND acr.attendance_date = ?'; $params[] = $dateFilter; }
$sql .= ' ORDER BY (acr.status = "pending") DESC, acr.created_at DESC, acr.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pendingCount = (int)$pdo->query('SELECT COUNT(*) FROM attendance_correction_requests WHERE status = "pending"')->fetchColumn();
$detailId = (int)($_GET['id'] ?? 0);
$detail = null;
foreach ($requests as $request) {
    if ((int)$request['id'] === $detailId) {
        $detail = $request;
        break;
    }
}
if ($detail === null && $detailId > 0) {
    $detailStmt = $pdo->prepare('SELECT acr.*, e.first_name, e.last_name, e.company, e.active,
            a.schedule_timezone, CONCAT(r.first_name, " ", r.last_name) AS reviewer_name
        FROM attendance_correction_requests acr INNER JOIN employees e ON e.id = acr.employee_id
        LEFT JOIN attendance a ON a.id = acr.attendance_id LEFT JOIN employees r ON r.id = acr.reviewed_by
        WHERE acr.id = ? LIMIT 1');
    $detailStmt->execute([$detailId]);
    $detail = $detailStmt->fetch() ?: null;
}

$filterQuery = ['status' => $statusFilter, 'company' => $companyFilter, 'employee_id' => $employeeFilter, 'date' => $dateFilter];
$companyName = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$pageTitle = 'Attendance Corrections';
$activeSubPage = 'attendance_corrections';
include __DIR__ . '/../includes/admin_layout_start.php';
?>

<section class="page-header">
    <div><h1>Attendance Corrections</h1><p>Review employee-submitted corrections and missing attendance records.</p></div>
    <span class="pill <?= $pendingCount > 0 ? 'pill-yellow' : 'pill-green' ?>"><?= $pendingCount ?> pending</span>
</section>

<article class="content-card correction-filter-card">
    <form method="get" class="form-grid-4 leave-balance-filter">
        <div><label for="correction-status-filter">Status</label><select id="correction-status-filter" name="status"><?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'all' => 'All Statuses'] as $value => $label): ?><option value="<?= h($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
        <div><label for="correction-company-filter">Company</label><select id="correction-company-filter" name="company"><option value="">All Companies</option><?php foreach ($companies as $company): ?><option value="<?= h($company) ?>" <?= $companyFilter === $company ? 'selected' : '' ?>><?= h($company) ?></option><?php endforeach; ?></select></div>
        <div><label for="correction-employee-filter">Employee</label><select id="correction-employee-filter" name="employee_id"><option value="0">All Employees</option><?php foreach ($employees as $employee): ?><option value="<?= (int)$employee['id'] ?>" <?= $employeeFilter === (int)$employee['id'] ? 'selected' : '' ?>><?= h($employee['name'] . ' · ' . $employee['company'] . ((int)$employee['active'] === 0 ? ' (Inactive)' : '')) ?></option><?php endforeach; ?></select></div>
        <div><label for="correction-date-filter">Attendance Date</label><input id="correction-date-filter" type="date" name="date" value="<?= h($dateFilter) ?>"></div>
        <div><button type="submit" class="btn btn-secondary">Apply Filters</button></div>
    </form>
</article>

<article class="content-card">
    <div class="card-header"><div><h3>Correction Requests</h3><p class="muted"><?= count($requests) ?> request(s) match the filters.</p></div></div>
    <div class="table-card correction-admin-table"><table><thead><tr><th>Employee</th><th>Company</th><th>Attendance Date</th><th>Request</th><th>Requested In</th><th>Requested Out</th><th>Submitted</th><th>Status</th><th>Review</th></tr></thead><tbody>
        <?php if ($requests): foreach ($requests as $request): ?>
            <?php
            $requestSchedule = json_decode((string)($request['requested_schedule'] ?? ''), true);
            $requestTimezone = is_array($requestSchedule) ? (string)($requestSchedule['timezone'] ?? '') : '';
            $timezone = in_array($requestTimezone, DateTimeZone::listIdentifiers(), true)
                ? $requestTimezone
                : (in_array((string)$request['schedule_timezone'], DateTimeZone::listIdentifiers(), true) ? (string)$request['schedule_timezone'] : $schedule['timezone']);
            $detailUrl = 'attendance_corrections.php?' . http_build_query(array_merge($filterQuery, ['id' => (int)$request['id']])) . '#correction-review';
            ?>
            <tr>
                <td><strong><?= h($request['first_name'] . ' ' . $request['last_name']) ?></strong><?= (int)$request['active'] === 0 ? '<span class="report-inactive-label">Inactive</span>' : '' ?></td>
                <td><?= h($request['company']) ?></td><td><?= h(formatEmployeeDate((string)$request['attendance_date'])) ?></td>
                <td><?= $request['request_kind'] === 'missing_record' ? 'Missing record' : 'Correction' ?></td>
                <td class="report-time-cell"><?= h(formatEmployeeTime($request['requested_time_in'], $timezone)) ?></td><td class="report-time-cell"><?= h(formatEmployeeTime($request['requested_time_out'], $timezone)) ?></td>
                <td><?= h(formatEmployeeDate(substr((string)$request['created_at'], 0, 10))) ?><span class="correction-submitted-time"><?= h(substr((string)$request['created_at'], 11, 5)) ?></span></td>
                <td><?= leaveStatusPill((string)$request['status']) ?></td><td><a class="btn btn-secondary btn-sm" href="<?= h($detailUrl) ?>">View</a></td>
            </tr>
        <?php endforeach; else: ?><tr><td colspan="9" class="table-empty-cell">No correction requests match the selected filters.</td></tr><?php endif; ?>
    </tbody></table></div>
</article>

<?php if ($detail): ?>
    <?php
    $detailSchedule = json_decode((string)($detail['requested_schedule'] ?? ''), true);
    $detailScheduleTimezone = is_array($detailSchedule) ? (string)($detailSchedule['timezone'] ?? '') : '';
    $detailTimezone = in_array($detailScheduleTimezone, DateTimeZone::listIdentifiers(), true)
        ? $detailScheduleTimezone
        : (in_array((string)$detail['schedule_timezone'], DateTimeZone::listIdentifiers(), true) ? (string)$detail['schedule_timezone'] : $schedule['timezone']);
    $original = json_decode((string)($detail['original_values'] ?? ''), true);
    $requestedStatus = $detail['requested_time_out'] ? 'completed' : ($detail['requested_break_start'] && !$detail['requested_break_end'] ? 'on_break' : 'currently_working');
    ?>
    <article class="content-card correction-review-panel" id="correction-review">
        <div class="card-header">
            <div><span class="employee-eyebrow">Request #<?= (int)$detail['id'] ?></span><h3><?= h($detail['first_name'] . ' ' . $detail['last_name']) ?> · <?= h(formatEmployeeDate((string)$detail['attendance_date'])) ?></h3><p class="muted"><?= h($detail['company']) ?> · <?= $detail['request_kind'] === 'missing_record' ? 'Missing attendance record' : 'Existing record correction' ?></p></div>
            <a class="btn btn-secondary btn-sm" href="attendance_corrections.php?<?= h(http_build_query($filterQuery)) ?>">Close Review</a>
        </div>
        <div class="correction-reason-card"><strong>Employee reason</strong><p><?= nl2br(h($detail['reason'])) ?></p></div>
        <div class="correction-comparison">
            <section><h4>Original Record</h4>
                <?php if (is_array($original)): ?><dl class="correction-detail-list">
                    <div><dt>Time In</dt><dd><?= h(formatEmployeeTime($original['time_in'] ?? null, $detailTimezone)) ?></dd></div>
                    <div><dt>Lunch Out</dt><dd><?= h(formatEmployeeTime($original['break_start'] ?? null, $detailTimezone)) ?></dd></div>
                    <div><dt>Lunch In</dt><dd><?= h(formatEmployeeTime($original['break_end'] ?? null, $detailTimezone)) ?></dd></div>
                    <div><dt>Time Out</dt><dd><?= h(formatEmployeeTime($original['time_out'] ?? null, $detailTimezone)) ?></dd></div>
                    <div><dt>Lunch</dt><dd><?= (int)($original['break_minutes'] ?? 0) ?> min</dd></div>
                    <div><dt>Total</dt><dd><?= h(formatHours($original['total_hours'] ?? 0)) ?></dd></div>
                    <div><dt>Status</dt><dd><?= statusPill((string)($original['status'] ?? 'not_started')) ?></dd></div>
                </dl><?php else: ?><div class="correction-missing-state"><strong>No attendance record</strong><span>Approval will create a new record for this date.</span></div><?php endif; ?>
            </section>
            <section><h4>Requested Record</h4><dl class="correction-detail-list">
                <div><dt>Time In</dt><dd><?= h(formatEmployeeTime($detail['requested_time_in'], $detailTimezone)) ?></dd></div>
                <div><dt>Lunch Out</dt><dd><?= h(formatEmployeeTime($detail['requested_break_start'], $detailTimezone)) ?></dd></div>
                <div><dt>Lunch In</dt><dd><?= h(formatEmployeeTime($detail['requested_break_end'], $detailTimezone)) ?></dd></div>
                <div><dt>Time Out</dt><dd><?= h(formatEmployeeTime($detail['requested_time_out'], $detailTimezone)) ?></dd></div>
                <div><dt>Status</dt><dd><?= statusPill($requestedStatus) ?></dd></div>
            </dl></section>
        </div>

        <?php if ((string)$detail['status'] === 'pending'): ?>
            <div class="correction-review-actions">
                <form method="post" action="attendance_correction_action.php" class="correction-decision-form">
                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="request_id" value="<?= (int)$detail['id'] ?>"><input type="hidden" name="decision" value="approve">
                    <label for="approve-comment">Approval Note <span class="muted">(optional)</span></label><textarea id="approve-comment" name="admin_comment" maxlength="1000" rows="3" placeholder="Approved as requested."></textarea>
                    <button type="submit" class="btn btn-primary">Approve and Apply</button>
                </form>
                <form method="post" action="attendance_correction_action.php" class="correction-decision-form is-reject">
                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="request_id" value="<?= (int)$detail['id'] ?>"><input type="hidden" name="decision" value="reject">
                    <label class="required" for="reject-comment">Rejection Reason</label><textarea id="reject-comment" name="admin_comment" maxlength="1000" rows="3" required placeholder="Explain what the employee should correct."></textarea>
                    <button type="submit" class="btn btn-danger">Reject Request</button>
                </form>
            </div>
        <?php else: ?>
            <div class="correction-reviewed-note"><strong><?= ucfirst(h((string)$detail['status'])) ?></strong><span><?= h($detail['admin_comment'] ?: 'No admin comment.') ?><?= $detail['reviewer_name'] ? ' · ' . h($detail['reviewer_name']) : '' ?></span></div>
        <?php endif; ?>
    </article>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
