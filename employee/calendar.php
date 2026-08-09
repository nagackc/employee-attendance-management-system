<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireLogin($pdo);
applyTimezone($pdo);

$employeeId = (int)$_SESSION['user_id'];
$employeeCalendarSection = ($_GET['view'] ?? '') === 'balance' ? 'balance' : 'calendar';
$balanceYear = (int)($_GET['balance_year'] ?? date('Y'));
if ($balanceYear < 2000 || $balanceYear > 2100) {
    $balanceYear = (int)date('Y');
}
$monthValue = trim((string)($_GET['month'] ?? date('Y-m')));
$monthDate = DateTimeImmutable::createFromFormat('!Y-m', $monthValue);
if ($monthDate === false || $monthDate->format('Y-m') !== $monthValue) {
    $monthDate = new DateTimeImmutable('first day of this month');
    $monthValue = $monthDate->format('Y-m');
}

$leaveTypesStmt = $pdo->query('SELECT id, name FROM leave_types WHERE active = 1 ORDER BY name ASC');
$leaveTypes = $leaveTypesStmt->fetchAll();

$myLeaveStmt = $pdo->prepare('SELECT lr.*, lt.name AS leave_type_name
    FROM leave_requests lr
    INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE lr.employee_id = ? ORDER BY lr.created_at DESC, lr.id DESC LIMIT 50');
$myLeaveStmt->execute([$employeeId]);
$myLeaveRequests = $myLeaveStmt->fetchAll();
$leaveBalances = getEmployeeLeaveBalances($pdo, $employeeId, $balanceYear);

$adjustmentStmt = $pdo->prepare('SELECT lba.leave_type_id, lba.adjustment_minutes, lba.effective_date, lba.remarks, lba.created_at,
        CONCAT(a.first_name, " ", a.last_name) AS admin_name
    FROM leave_balance_adjustments lba
    INNER JOIN employees a ON a.id = lba.created_by
    WHERE lba.employee_id = ? AND lba.period_year = ?
    ORDER BY lba.effective_date DESC, lba.id DESC');
$adjustmentStmt->execute([$employeeId, $balanceYear]);
$adjustmentsByType = [];
foreach ($adjustmentStmt->fetchAll() as $adjustment) {
    $adjustmentsByType[(int)$adjustment['leave_type_id']][] = $adjustment;
}

$usedStmt = $pdo->prepare('SELECT lr.id, lr.leave_type_id, lr.start_date, lr.end_date, lr.request_unit,
        SUM(lrc.minutes) AS period_minutes
    FROM leave_requests lr
    INNER JOIN leave_request_charges lrc ON lrc.leave_request_id = lr.id
    WHERE lr.employee_id = ? AND lr.status = "approved" AND YEAR(lrc.charge_date) = ?
    GROUP BY lr.id, lr.leave_type_id, lr.start_date, lr.end_date, lr.request_unit
    ORDER BY lr.start_date DESC, lr.id DESC');
$usedStmt->execute([$employeeId, $balanceYear]);
$usedByType = [];
foreach ($usedStmt->fetchAll() as $usedRequest) {
    $usedByType[(int)$usedRequest['leave_type_id']][] = $usedRequest;
}

$balanceDetails = [];
foreach ($leaveBalances as $balance) {
    $typeId = (int)$balance['leave_type_id'];
    $balanceDetails[$typeId] = [
        'name' => (string)$balance['leave_type_name'],
        'year' => $balanceYear,
        'annual_minutes' => (int)$balance['annual_minutes'],
        'adjustments' => $adjustmentsByType[$typeId] ?? [],
        'used' => $usedByType[$typeId] ?? [],
    ];
}

$leaveStatusMap = [
    'pending' => ['Pending', 'pill-yellow'],
    'approved' => ['Approved', 'pill-green'],
    'rejected' => ['Rejected', 'pill-red'],
    'cancelled' => ['Cancelled', 'pill-black'],
];

$pageTitle = $employeeCalendarSection === 'balance' ? 'Leave Balance' : 'Calendar';
$activePage = 'calendar';
include __DIR__ . '/../includes/employee_layout_start.php';
?>

<section class="employee-page-intro leave-page-intro">
    <div>
        <p class="employee-eyebrow">Time away</p>
        <h2><?= $employeeCalendarSection === 'balance' ? 'Leave Balance' : 'Calendar' ?></h2>
        <p><?= $employeeCalendarSection === 'balance'
            ? 'Review annual credits, adjustments, approved usage, and pending requests.'
            : 'Browse approved schedules and manage your leave requests.' ?></p>
    </div>
    <button type="button" class="btn btn-primary" id="open-leave-request-modal">Request Leave</button>
</section>

<?php if ($employeeCalendarSection === 'balance'): ?>
<section class="dashboard-card leave-balance-card employee-anchor-section" id="leave-balance">
    <div class="leave-balance-header">
        <div>
            <p class="employee-eyebrow">Annual entitlement</p>
            <h3>Leave Balance</h3>
            <p>Cover period: January 1 – December 31, <?= $balanceYear ?></p>
        </div>
        <div class="leave-balance-controls">
            <div class="leave-unit-toggle" role="group" aria-label="Leave balance display unit">
                <button type="button" class="is-active" data-leave-unit="days">Days</button>
                <button type="button" data-leave-unit="hours">Hours</button>
            </div>
            <form method="get" class="leave-balance-year-form">
                <input type="hidden" name="view" value="balance">
                <input type="hidden" name="month" value="<?= h($monthValue) ?>">
                <label for="balance-year">Year</label>
                <select id="balance-year" name="balance_year" onchange="this.form.submit()">
                    <?php for ($yearOption = (int)date('Y') + 1; $yearOption >= (int)date('Y') - 5; $yearOption--): ?>
                        <option value="<?= $yearOption ?>" <?= $yearOption === $balanceYear ? 'selected' : '' ?>><?= $yearOption ?></option>
                    <?php endfor; ?>
                </select>
            </form>
        </div>
    </div>
    <div class="table-card leave-balance-table">
        <table>
            <thead><tr><th>Leave Type</th><th>Annual Credit</th><th>Adjustments</th><th>Approved Used</th><th>Pending</th><th>Available</th><th>Details</th></tr></thead>
            <tbody>
                <?php if ($leaveBalances): ?>
                    <?php foreach ($leaveBalances as $balance): ?>
                        <tr>
                            <td><strong><?= h($balance['leave_type_name']) ?></strong></td>
                            <?php foreach (['annual_minutes', 'adjustment_minutes', 'used_minutes', 'pending_minutes', 'available_minutes'] as $field): ?>
                                <td class="leave-balance-value<?= $field === 'available_minutes' && (int)$balance[$field] < 0 ? ' is-negative' : '' ?>" data-leave-minutes="<?= (int)$balance[$field] ?>"><?= h(formatLeaveMinutes((int)$balance[$field], 'days')) ?></td>
                            <?php endforeach; ?>
                            <td><button type="button" class="icon-btn leave-balance-detail-button" data-balance-type="<?= (int)$balance['leave_type_id'] ?>" aria-label="View <?= h($balance['leave_type_name']) ?> balance details">☷</button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="table-empty-cell">No active leave types are available.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="leave-balance-note">Pending requests are shown separately and do not reduce Available until approved.</p>
</section>
<?php else: ?>

<section class="dashboard-card leave-calendar-card employee-anchor-section" id="employee-calendar">
    <div class="leave-calendar-toolbar">
        <div class="leave-month-navigation">
            <button type="button" class="icon-btn" id="leave-prev-month" aria-label="Previous month">←</button>
            <button type="button" class="btn btn-secondary btn-sm" id="leave-today">Today</button>
            <button type="button" class="icon-btn" id="leave-next-month" aria-label="Next month">→</button>
        </div>
        <h3 id="leave-calendar-title" aria-live="polite"><?= h($monthDate->format('F Y')) ?></h3>
        <label class="leave-month-picker">
            <span>Choose month</span>
            <input type="month" id="leave-month-input" value="<?= h($monthValue) ?>">
        </label>
    </div>

    <div id="leave-calendar-loading" class="calendar-message">Loading leave calendar…</div>
    <div id="leave-calendar-error" class="calendar-message calendar-error" role="alert" hidden></div>
    <div class="leave-calendar-wrap" id="leave-calendar-wrap" hidden>
        <div class="leave-calendar-weekdays" aria-hidden="true">
            <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
        </div>
        <div class="leave-calendar-grid" id="leave-calendar-grid"></div>
    </div>
    <div class="calendar-legend">
        <span><i class="legend-self"></i> My leave</span>
        <span><i class="legend-coworker"></i> Coworker</span>
        <span><i class="legend-holiday"></i> Holiday</span>
    </div>
</section>
<?php endif; ?>

<section class="dashboard-card leave-requests-card">
    <div class="section-header">
        <div>
            <p class="employee-eyebrow">Requests</p>
            <h3>My leave requests</h3>
        </div>
    </div>
    <div class="table-card leave-request-table">
        <table>
            <thead><tr><th>Leave Type</th><th>Duration</th><th>Date Range</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                <?php if ($myLeaveRequests): ?>
                    <?php foreach ($myLeaveRequests as $leaveRequest): ?>
                        <?php
                        $statusKey = strtolower((string)$leaveRequest['status']);
                        [$statusLabel, $statusClass] = $leaveStatusMap[$statusKey] ?? [ucfirst($statusKey), 'pill-gray'];
                        ?>
                        <tr>
                            <td><?= h($leaveRequest['leave_type_name']) ?></td>
                            <td><?= h(formatLeaveMinutes((int)$leaveRequest['requested_minutes'], (string)$leaveRequest['request_unit'])) ?> <?= h((string)$leaveRequest['request_unit']) ?></td>
                            <td><?= h(formatEmployeeDate((string)$leaveRequest['start_date'])) ?> – <?= h(formatEmployeeDate((string)$leaveRequest['end_date'])) ?></td>
                            <td><span class="pill <?= h($statusClass) ?>"><?= h($statusLabel) ?></span></td>
                            <td>
                                <?php if ($statusKey === 'pending'): ?>
                                    <form method="post" action="leave_action.php" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="cancel_leave">
                                        <input type="hidden" name="request_id" value="<?= (int)$leaveRequest['id'] ?>">
                                        <button type="submit" class="link-button danger-link">Cancel</button>
                                    </form>
                                <?php else: ?><span class="muted">—</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="table-empty-cell">No leave requests yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div id="leave-request-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="leave-request-title" aria-hidden="true">
    <div class="modal-box leave-modal-box" tabindex="-1">
        <h4 class="modal-title" id="leave-request-title">Request Leave</h4>
        <form method="post" action="leave_action.php" class="form-layout leave-request-form" id="leave-request-form">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="request_leave">
            <div>
                <label class="required" for="leave_type_id">Leave Type</label>
                <select name="leave_type_id" id="leave_type_id" required>
                    <option value="">Select leave type</option>
                    <?php foreach ($leaveTypes as $leaveType): ?>
                        <option value="<?= (int)$leaveType['id'] ?>"><?= h($leaveType['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <fieldset class="leave-request-unit">
                <legend>Request Type</legend>
                <label><input type="radio" name="request_unit" value="days" checked> Full Days</label>
                <label><input type="radio" name="request_unit" value="hours"> Hours</label>
            </fieldset>
            <div class="form-row" id="leave-days-fields">
                <div><label class="required" for="leave_start_date">Start Date</label><input type="date" name="start_date" id="leave_start_date" required></div>
                <div><label class="required" for="leave_end_date">End Date</label><input type="date" name="end_date" id="leave_end_date" required></div>
            </div>
            <div class="form-row" id="leave-hours-fields" hidden>
                <div><label class="required" for="leave_hour_date">Date</label><input type="date" name="hour_date" id="leave_hour_date"></div>
                <div><label class="required" for="leave_hours_requested">Hours</label><input type="number" name="hours_requested" id="leave_hours_requested" min="0.5" max="8" step="0.5" placeholder="0.5–8"></div>
            </div>
            <div id="leave-request-preview" class="leave-request-preview" aria-live="polite">Choose a leave type and dates to preview the balance.</div>
            <div><label class="required" for="leave_reason">Reason</label><textarea name="reason" id="leave_reason" rows="4" maxlength="1000" required></textarea></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-leave-request-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Submit</button>
            </div>
        </form>
    </div>
</div>

<?php if ($employeeCalendarSection === 'balance'): ?>
<div id="leave-balance-detail-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="leave-balance-detail-title" aria-hidden="true">
    <div class="modal-box leave-balance-detail-box" tabindex="-1">
        <div class="notification-modal-header">
            <div><span class="employee-eyebrow">Leave balance</span><h2 id="leave-balance-detail-title">Balance Details</h2></div>
            <button type="button" class="icon-btn" data-balance-detail-close aria-label="Close balance details">×</button>
        </div>
        <div class="leave-balance-detail-content">
            <p id="leave-balance-cover"></p>
            <h4>Entitlement &amp; Adjustments</h4>
            <div class="table-card"><table><thead><tr><th>Date</th><th>Entry</th><th>Amount</th><th>Remarks</th></tr></thead><tbody id="leave-balance-earned-body"></tbody></table></div>
            <h4>Approved Leave Used</h4>
            <div class="table-card"><table><thead><tr><th>Request</th><th>Date Range</th><th>Amount</th></tr></thead><tbody id="leave-balance-used-body"></tbody></table></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($employeeCalendarSection === 'calendar'): ?>
<div id="leave-detail-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="leave-detail-title" aria-hidden="true">
    <div class="modal-box leave-modal-box" tabindex="-1">
        <h4 class="modal-title" id="leave-detail-title">Leave Details</h4>
        <div class="leave-detail-list">
            <p><strong>Employee Name:</strong> <span id="leave-detail-employee"></span></p>
            <p><strong>Leave Type:</strong> <span id="leave-detail-type"></span></p>
            <p><strong>Start Date:</strong> <span id="leave-detail-start"></span></p>
            <p><strong>End Date:</strong> <span id="leave-detail-end"></span></p>
            <p><strong>Duration:</strong> <span id="leave-detail-duration"></span></p>
        </div>
        <div class="modal-actions"><button type="button" class="btn btn-secondary" data-leave-detail-close>Close</button></div>
    </div>
</div>
<?php endif; ?>

<script>
    window.__leaveCalendarUrl = 'leave_calendar_api.php';
    window.__leaveInitialMonth = '<?= h($monthValue) ?>';
    window.__leaveBalanceYear = <?= $balanceYear ?>;
    window.__leaveBalanceDetails = <?= json_encode($balanceDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.__leaveBalances = <?= json_encode(array_column($leaveBalances, null, 'leave_type_id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.__leaveRequestPreviewUrl = 'leave_request_preview_api.php';
</script>

<?php include __DIR__ . '/../includes/employee_layout_end.php'; ?>
