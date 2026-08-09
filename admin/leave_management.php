<?php
require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

$employeeFilter = trim((string)($_GET['employee_name'] ?? ''));
$companyFilter = trim((string)($_GET['company'] ?? ''));
$leaveTypeFilter = (int)($_GET['leave_type_id'] ?? 0);
$statusFilter = trim((string)($_GET['status'] ?? ''));
$startDateFilter = trim((string)($_GET['start_date'] ?? ''));
$endDateFilter = trim((string)($_GET['end_date'] ?? ''));
$quickFilter = trim((string)($_GET['quick'] ?? ''));
$employeeSearch = trim((string)($_GET['q'] ?? ''));
$filterError = '';

$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

if ($quickFilter === 'pending') {
    $statusFilter = 'pending';
} elseif ($quickFilter === 'approved') {
    $statusFilter = 'approved';
} elseif ($quickFilter === 'rejected') {
    $statusFilter = 'rejected';
} elseif ($quickFilter === 'this_week') {
    $startDateFilter = date('Y-m-d', strtotime('monday this week'));
    $endDateFilter = date('Y-m-d', strtotime('sunday this week'));
} elseif ($quickFilter === 'this_month') {
    $startDateFilter = date('Y-m-01');
    $endDateFilter = date('Y-m-t');
}

if (($startDateFilter !== '' && !isValidDateValue($startDateFilter)) || ($endDateFilter !== '' && !isValidDateValue($endDateFilter))) {
    $filterError = 'Enter valid leave filter dates.';
} elseif ($startDateFilter !== '' && $endDateFilter !== '' && $endDateFilter < $startDateFilter) {
    $filterError = 'Leave filter end date cannot be before the start date.';
}

$companiesStmt = $pdo->query('SELECT DISTINCT company FROM employees WHERE role = "employee" ORDER BY company ASC');
$companies = $companiesStmt->fetchAll();

$typesStmt = $pdo->query('SELECT id, name FROM leave_types ORDER BY name ASC');
$leaveTypes = $typesStmt->fetchAll();

$query = 'SELECT lr.*, lt.name AS leave_type_name,
        CONCAT(e.first_name, " ", e.last_name) AS employee_name,
        e.company,
        NULL AS employee_position,
        CONCAT(a.first_name, " ", a.last_name) AS action_admin_name
    FROM leave_requests lr
    INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
    INNER JOIN employees e ON e.id = lr.employee_id
    LEFT JOIN employees a ON a.id = lr.approved_by
    WHERE 1 = 1';
$params = [];

if ($employeeFilter !== '') {
    $query .= ' AND CONCAT(e.first_name, " ", e.last_name) LIKE ?';
    $params[] = '%' . $employeeFilter . '%';
}

if ($employeeSearch !== '') {
    $query .= ' AND CONCAT(e.first_name, " ", e.last_name) LIKE ?';
    $params[] = '%' . $employeeSearch . '%';
}

if ($companyFilter !== '') {
    $query .= ' AND e.company = ?';
    $params[] = $companyFilter;
}

if ($leaveTypeFilter > 0) {
    $query .= ' AND lr.leave_type_id = ?';
    $params[] = $leaveTypeFilter;
}

if ($statusFilter !== '') {
    $query .= ' AND lr.status = ?';
    $params[] = $statusFilter;
}

if ($filterError === '' && $startDateFilter !== '') {
    $query .= ' AND lr.end_date >= ?';
    $params[] = $startDateFilter;
}

if ($filterError === '' && $endDateFilter !== '') {
    $query .= ' AND lr.start_date <= ?';
    $params[] = $endDateFilter;
}

$query .= ' ORDER BY FIELD(lr.status, "pending", "approved", "rejected", "cancelled"), lr.created_at DESC, lr.id DESC';

if ($filterError !== '') {
    $query = 'SELECT lr.*, lt.name AS leave_type_name, CONCAT(e.first_name, " ", e.last_name) AS employee_name,
        e.company, NULL AS employee_position, NULL AS action_admin_name
        FROM leave_requests lr INNER JOIN leave_types lt ON lt.id = lr.leave_type_id INNER JOIN employees e ON e.id = lr.employee_id
        WHERE 1 = 0';
    $params = [];
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$leaveRequests = $stmt->fetchAll();
$requestBalancePreviews = [];
$chargePeriodStmt = $pdo->prepare('SELECT YEAR(charge_date) AS period_year, SUM(minutes) AS period_minutes
    FROM leave_request_charges WHERE leave_request_id = ? GROUP BY YEAR(charge_date) ORDER BY period_year');
foreach ($leaveRequests as $leaveRequestRow) {
    if ((string)$leaveRequestRow['status'] !== 'pending') {
        continue;
    }
    $chargePeriodStmt->execute([(int)$leaveRequestRow['id']]);
    $parts = [];
    foreach ($chargePeriodStmt->fetchAll() as $period) {
        $periodYear = (int)$period['period_year'];
        $periodMinutes = (int)$period['period_minutes'];
        $periodBalance = getEmployeeLeaveBalance($pdo, (int)$leaveRequestRow['employee_id'], (int)$leaveRequestRow['leave_type_id'], $periodYear);
        $parts[] = $periodYear . ': available ' . formatLeaveMinutes((int)$periodBalance['available_minutes'], 'days')
            . ' days · request ' . formatLeaveMinutes($periodMinutes, 'days')
            . ' days · after approval ' . formatLeaveMinutes((int)$periodBalance['available_minutes'] - $periodMinutes, 'days') . ' days';
    }
    $requestBalancePreviews[(int)$leaveRequestRow['id']] = implode(' | ', $parts);
}

$companyName = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$pageTitle = 'Leave Management';
$activePage = '';
$activeSubPage = 'leave_management';
include __DIR__ . '/../includes/admin_layout_start.php';
?>
<section class="page-header">
    <div>
        <h1>Leave Management</h1>
        <p>Review, approve, reject, or cancel leave requests.</p>
    </div>
</section>

<?php if ($filterError !== ''): ?><div class="message"><?= h($filterError) ?></div><?php endif; ?>

<article class="content-card" data-search-item>
    <form method="get" class="leave-filter-bar">
        <div class="form-grid-4">
            <div>
                <label for="employee_name">Employee Name</label>
                <input type="text" id="employee_name" name="employee_name" value="<?= h($employeeFilter) ?>" placeholder="Employee full name">
            </div>
            <div>
                <label for="company">Company</label>
                <select id="company" name="company">
                    <option value="">All companies</option>
                    <?php foreach ($companies as $companyRow): ?>
                        <option value="<?= h($companyRow['company']) ?>" <?= $companyFilter === (string)$companyRow['company'] ? 'selected' : '' ?>><?= h($companyRow['company']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="leave_type_id">Leave Type</label>
                <select id="leave_type_id" name="leave_type_id">
                    <option value="0">All leave types</option>
                    <?php foreach ($leaveTypes as $leaveType): ?>
                        <option value="<?= (int)$leaveType['id'] ?>" <?= $leaveTypeFilter === (int)$leaveType['id'] ? 'selected' : '' ?>><?= h($leaveType['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach (['pending', 'approved', 'rejected', 'cancelled'] as $statusOption): ?>
                        <option value="<?= h($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>><?= h(ucfirst($statusOption)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-grid-3" style="margin-top:12px;">
            <div>
                <label for="start_date">Date Range From</label>
                <input type="date" id="start_date" name="start_date" value="<?= h($startDateFilter) ?>">
            </div>
            <div>
                <label for="end_date">Date Range To</label>
                <input type="date" id="end_date" name="end_date" value="<?= h($endDateFilter) ?>">
            </div>
            <div style="display:flex;align-items:flex-end;gap:8px;">
                <button type="submit" class="btn btn-secondary">Apply Filters</button>
                <a href="leave_management.php" class="btn btn-secondary">Reset</a>
            </div>
        </div>

        <div class="quick-filter-row">
            <a href="leave_management.php?quick=pending" class="pill quick-pill">Pending</a>
            <a href="leave_management.php?quick=approved" class="pill quick-pill">Approved</a>
            <a href="leave_management.php?quick=rejected" class="pill quick-pill">Rejected</a>
            <a href="leave_management.php?quick=this_week" class="pill quick-pill">This Week</a>
            <a href="leave_management.php?quick=this_month" class="pill quick-pill">This Month</a>
        </div>
    </form>

    <form method="get" class="table-toolbar leave-search-toolbar">
        <input type="hidden" name="employee_name" value="<?= h($employeeFilter) ?>">
        <input type="hidden" name="company" value="<?= h($companyFilter) ?>">
        <input type="hidden" name="leave_type_id" value="<?= (int)$leaveTypeFilter ?>">
        <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
        <input type="hidden" name="start_date" value="<?= h($startDateFilter) ?>">
        <input type="hidden" name="end_date" value="<?= h($endDateFilter) ?>">
        <input class="table-search" type="search" name="q" value="<?= h($employeeSearch) ?>" placeholder="Search employee name...">
        <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    </form>

    <div class="table-card" data-sticky-head="true" data-table-enhance="true" data-page-size="10">
        <table>
            <thead>
                <tr>
                    <th>Employee Name</th>
                    <th>Company</th>
                    <th>Leave Type</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Duration</th>
                    <th>Current Status</th>
                    <th>Date Submitted</th>
                    <th data-sort="false">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($leaveRequests): ?>
                    <?php foreach ($leaveRequests as $leave): ?>
                        <?php
                        $requestMinutes = (int)($leave['requested_minutes'] ?? 0);
                        $durationLabel = formatLeaveMinutes($requestMinutes, (string)($leave['request_unit'] ?? 'days')) . ' ' . (string)($leave['request_unit'] ?? 'days');
                        ?>
                        <tr>
                            <td><?= h($leave['employee_name']) ?></td>
                            <td><?= h($leave['company']) ?></td>
                            <td><?= h($leave['leave_type_name']) ?></td>
                            <td><?= h($leave['start_date']) ?></td>
                            <td><?= h($leave['end_date']) ?></td>
                            <td><?= h($durationLabel) ?></td>
                            <td><?= leaveStatusPill((string)$leave['status']) ?></td>
                            <td><?= h($leave['created_at']) ?></td>
                            <td>
                                <div class="leave-action-row">
                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-sm leave-view-btn"
                                        data-leave-view="1"
                                        data-employee="<?= h($leave['employee_name']) ?>"
                                        data-company="<?= h($leave['company']) ?>"
                                        data-position="<?= h($leave['employee_position'] ?: 'N/A') ?>"
                                        data-type="<?= h($leave['leave_type_name']) ?>"
                                        data-start="<?= h($leave['start_date']) ?>"
                                        data-end="<?= h($leave['end_date']) ?>"
                                        data-days="<?= h($durationLabel) ?>"
                                        data-reason="<?= h($leave['reason']) ?>"
                                    >View</button>

                                    <?php if ($leave['status'] === 'pending'): ?>
                                        <button type="button" class="btn btn-success btn-sm leave-admin-action" data-request-id="<?= (int)$leave['id'] ?>" data-action="approve" data-label="Approve leave request" data-require-comment="0"
                                            data-balance-type="<?= h($leave['leave_type_name']) ?>"
                                            data-balance-summary="<?= h($requestBalancePreviews[(int)$leave['id']] ?? 'Balance preview unavailable') ?>">Approve</button>
                                        <button type="button" class="btn btn-danger btn-sm leave-admin-action" data-request-id="<?= (int)$leave['id'] ?>" data-action="reject" data-label="Reject leave request" data-require-comment="1">Reject</button>
                                        <button type="button" class="btn btn-secondary btn-sm leave-admin-action" data-request-id="<?= (int)$leave['id'] ?>" data-action="cancel" data-label="Cancel leave request" data-require-comment="1">Cancel</button>
                                    <?php elseif ($leave['status'] === 'approved'): ?>
                                        <button type="button" class="btn btn-secondary btn-sm leave-admin-action" data-request-id="<?= (int)$leave['id'] ?>" data-action="cancel" data-label="Cancel approved leave" data-require-comment="1">Cancel</button>
                                    <?php else: ?>
                                        <span class="muted">No actions</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty-state">No leave requests found for the selected filters.</div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</article>

<div id="leave-view-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="leave-view-title">
    <div class="modal-box leave-admin-modal-box">
        <h4 class="modal-title" id="leave-view-title">Leave Request Details</h4>
        <div class="leave-view-grid">
            <h5>Employee Information</h5>
            <p><strong>Name:</strong> <span id="lv-employee"></span></p>
            <p><strong>Company:</strong> <span id="lv-company"></span></p>
            <p><strong>Position:</strong> <span id="lv-position"></span></p>

            <h5>Leave Information</h5>
            <p><strong>Leave Type:</strong> <span id="lv-type"></span></p>
            <p><strong>Start Date:</strong> <span id="lv-start"></span></p>
            <p><strong>End Date:</strong> <span id="lv-end"></span></p>
            <p><strong>Duration:</strong> <span id="lv-days"></span></p>
            <p><strong>Reason:</strong> <span id="lv-reason"></span></p>

            <h5>Attachments</h5>
            <p class="muted">No attachments uploaded yet.</p>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-secondary" data-leave-view-close>Close</button>
        </div>
    </div>
</div>

<div id="leave-admin-action-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="leave-action-title">
    <div class="modal-box leave-admin-modal-box">
        <h4 class="modal-title" id="leave-action-title">Confirm Action</h4>
        <p id="leave-action-message" class="muted"></p>
        <div id="leave-action-balance" class="leave-approval-balance" hidden></div>
        <form method="post" action="leave_action.php" id="leave-admin-action-form" class="form-layout">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
            <input type="hidden" name="request_id" id="leave-action-request-id" value="0">
            <input type="hidden" name="action" id="leave-action-name" value="">

            <div>
                <label for="leave-action-comment">Comment (optional)</label>
                <textarea id="leave-action-comment" name="admin_comment" rows="4" maxlength="1000" placeholder="Add optional context for this action..."></textarea>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-leave-action-close>Cancel</button>
                <button type="submit" class="btn btn-primary" data-loading-text="Processing...">Confirm</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
