<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireLogin($pdo);
applyTimezone($pdo);

$employeeId = (int)$_SESSION['user_id'];
$schedule = getAttendanceSchedule($pdo);
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND voided_at IS NULL');
$countStmt->execute([$employeeId]);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare('SELECT a.*,
        (SELECT COUNT(*) FROM attendance_quick_breaks qb WHERE qb.attendance_id = a.id) AS quick_break_count,
        (SELECT COALESCE(SUM(COALESCE(qb.duration_seconds, 0)), 0) FROM attendance_quick_breaks qb WHERE qb.attendance_id = a.id) AS quick_break_seconds
    FROM attendance a
    WHERE a.employee_id = ? AND a.voided_at IS NULL
    ORDER BY a.attendance_date DESC, a.id DESC LIMIT ? OFFSET ?');
$stmt->execute([$employeeId, $perPage, $offset]);
$records = $stmt->fetchAll();
$recordDates = array_column($records, 'attendance_date');
$holidayMap = $recordDates ? getHolidayMap($pdo, min($recordDates), max($recordDates)) : [];

$correctionStmt = $pdo->prepare('SELECT acr.*, CONCAT(a.first_name, " ", a.last_name) AS reviewer_name
    FROM attendance_correction_requests acr
    LEFT JOIN employees a ON a.id = acr.reviewed_by
    WHERE acr.employee_id = ? ORDER BY acr.created_at DESC, acr.id DESC LIMIT 30');
$correctionStmt->execute([$employeeId]);
$correctionRequests = $correctionStmt->fetchAll();
$pendingCorrectionsByDate = [];
foreach ($correctionRequests as $correctionRequest) {
    if ((string)$correctionRequest['status'] === 'pending') {
        $pendingCorrectionsByDate[(string)$correctionRequest['attendance_date']] = (int)$correctionRequest['id'];
    }
}

function employeeHistoryPageUrl(int $page): string {
    return 'history.php?page=' . $page;
}

$pageTitle = 'Attendance History';
$activePage = 'history';
include __DIR__ . '/../includes/employee_layout_start.php';
?>

<section class="employee-page-intro">
    <div>
        <p class="employee-eyebrow">Your records</p>
        <h2>Attendance history</h2>
        <p><?= $total ?> attendance record<?= $total === 1 ? '' : 's' ?>.</p>
    </div>
    <button type="button" class="btn btn-primary" data-correction-open data-correction-kind="missing_record">Report Missing Record</button>
</section>

<section class="dashboard-card">
    <div class="table-card employee-history-table">
        <table>
            <thead>
                <tr>
                    <th>Date</th><th>Shift</th><th>Time In</th><th>Lunch Out</th><th>Lunch In</th><th>Time Out</th>
                    <th>Lunch</th><th>Quick Breaks</th><th>Work Summary</th><th>Status</th><th>Correction</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($records): ?>
                    <?php foreach ($records as $row): ?>
                        <?php
                        $rowTimezone = (string)($row['schedule_timezone'] ?: $schedule['timezone']);
                        if (!in_array($rowTimezone, DateTimeZone::listIdentifiers(), true)) {
                            $rowTimezone = $schedule['timezone'];
                        }
                        $payroll = calculateAttendancePayrollMetrics($row, $schedule, $holidayMap[(string)$row['attendance_date']] ?? null);
                        ?>
                        <tr>
                            <td><?= h(formatEmployeeDate((string)$row['attendance_date'])) ?></td>
                            <td><?= h($row['shift_name'] ?: 'Default Schedule') ?></td>
                            <td><?= h(formatEmployeeTime($row['time_in'], $rowTimezone)) ?></td>
                            <td><?= h(formatEmployeeTime($row['break_start'], $rowTimezone)) ?></td>
                            <td><?= h(formatEmployeeTime($row['break_end'], $rowTimezone)) ?></td>
                            <td><?= h(formatEmployeeTime($row['time_out'], $rowTimezone)) ?></td>
                            <td><?= (int)($row['break_minutes'] ?? 0) ?> min</td>
                            <td><?= (int)$row['quick_break_count'] ?> · <?= h(formatDurationSeconds((int)$row['quick_break_seconds'])) ?></td>
                            <td class="payroll-summary-cell"><strong><?= h(formatHours($row['total_hours'])) ?> net</strong><span><?= h(formatMinutesDuration((int)$payroll['regular_minutes'])) ?> regular · <?= h(formatMinutesDuration((int)$payroll['overtime_minutes'])) ?> OT</span><span><?= h(formatMinutesDuration((int)$payroll['late_minutes'])) ?> late · <?= h(formatMinutesDuration((int)$payroll['undertime_minutes'])) ?> under</span></td>
                            <td><?= statusPill((string)$row['status']) ?> <?= payrollDayTypePill((string)$payroll['day_type']) ?><?= $payroll['holiday_name'] !== '' ? '<span class="report-cell-note">' . h($payroll['holiday_name']) . '</span>' : '' ?></td>
                            <td>
                                <?php if (isset($pendingCorrectionsByDate[(string)$row['attendance_date']])): ?>
                                    <span class="pill pill-yellow">Pending</span>
                                <?php else: ?>
                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-sm correction-row-button"
                                        data-correction-open
                                        data-correction-kind="existing_record"
                                        data-attendance-id="<?= (int)$row['id'] ?>"
                                        data-attendance-date="<?= h($row['attendance_date']) ?>"
                                        data-time-in="<?= h(str_replace(' ', 'T', substr((string)($row['time_in'] ?? ''), 0, 16))) ?>"
                                        data-time-out="<?= h(str_replace(' ', 'T', substr((string)($row['time_out'] ?? ''), 0, 16))) ?>"
                                        data-break-start="<?= h(str_replace(' ', 'T', substr((string)($row['break_start'] ?? ''), 0, 16))) ?>"
                                        data-break-end="<?= h(str_replace(' ', 'T', substr((string)($row['break_end'] ?? ''), 0, 16))) ?>"
                                    >Request</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="11" class="table-empty-cell">No attendance records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Attendance history pages">
            <?php if ($page > 1): ?>
                <a href="<?= h(employeeHistoryPageUrl(1)) ?>">« First</a>
                <a href="<?= h(employeeHistoryPageUrl($page - 1)) ?>">Previous</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <?php if ($i === $page): ?><span class="current"><?= $i ?></span>
                <?php else: ?><a href="<?= h(employeeHistoryPageUrl($i)) ?>"><?= $i ?></a><?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a href="<?= h(employeeHistoryPageUrl($page + 1)) ?>">Next</a>
                <a href="<?= h(employeeHistoryPageUrl($totalPages)) ?>">Last »</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<section class="dashboard-card correction-request-history" id="correction-requests">
    <div class="section-header">
        <div><p class="employee-eyebrow">Review status</p><h3>Correction Requests</h3></div>
    </div>
    <div class="table-card">
        <table>
            <thead><tr><th>Date</th><th>Request</th><th>Requested Schedule</th><th>Reason</th><th>Status</th><th>Admin Response</th><th>Action</th></tr></thead>
            <tbody>
                <?php if ($correctionRequests): foreach ($correctionRequests as $request): ?>
                    <?php $requestTimezone = $schedule['timezone']; ?>
                    <tr>
                        <td><?= h(formatEmployeeDate((string)$request['attendance_date'])) ?></td>
                        <td><?= $request['request_kind'] === 'missing_record' ? 'Missing record' : 'Existing record correction' ?></td>
                        <td class="correction-request-times">
                            <span>In <?= h(formatEmployeeTime($request['requested_time_in'], $requestTimezone)) ?></span>
                            <span>Out <?= h(formatEmployeeTime($request['requested_time_out'], $requestTimezone)) ?></span>
                            <?php if ($request['requested_break_start'] || $request['requested_break_end']): ?><span>Lunch <?= h(formatEmployeeTime($request['requested_break_start'], $requestTimezone)) ?>–<?= h(formatEmployeeTime($request['requested_break_end'], $requestTimezone)) ?></span><?php endif; ?>
                        </td>
                        <td><?= h($request['reason']) ?></td>
                        <td><?= leaveStatusPill((string)$request['status']) ?></td>
                        <td><?= $request['admin_comment'] !== null && $request['admin_comment'] !== '' ? h($request['admin_comment']) : '—' ?></td>
                        <td>
                            <?php if ((string)$request['status'] === 'pending'): ?>
                                <form method="post" action="attendance_correction_action.php" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                                    <button type="submit" class="link-button danger-link">Cancel</button>
                                </form>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?><tr><td colspan="7" class="table-empty-cell">No correction requests submitted yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div id="attendance-correction-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="attendance-correction-title" aria-hidden="true">
    <div class="modal-box correction-request-modal-box" tabindex="-1">
        <div class="notification-modal-header">
            <div><span class="employee-eyebrow">Attendance correction</span><h2 id="attendance-correction-title">Request Correction</h2></div>
            <button type="button" class="icon-btn" data-correction-close aria-label="Close correction request">×</button>
        </div>
        <form method="post" action="attendance_correction_action.php" class="form-layout correction-request-form" id="attendance-correction-form">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="submit"><input type="hidden" name="request_kind" id="correction-kind"><input type="hidden" name="attendance_id" id="correction-attendance-id">
            <div><label class="required" for="correction-date">Attendance Date</label><input type="date" name="attendance_date" id="correction-date" max="<?= h(date('Y-m-d')) ?>" required></div>
            <div class="form-grid-2">
                <div><label class="required" for="correction-time-in">Time In</label><input type="datetime-local" name="requested_time_in" id="correction-time-in" required></div>
                <div><label for="correction-time-out">Time Out</label><input type="datetime-local" name="requested_time_out" id="correction-time-out"></div>
            </div>
            <div class="form-grid-2">
                <div><label for="correction-break-start">Lunch Out</label><input type="datetime-local" name="requested_break_start" id="correction-break-start"></div>
                <div><label for="correction-break-end">Lunch In</label><input type="datetime-local" name="requested_break_end" id="correction-break-end"></div>
            </div>
            <p class="correction-form-help">For overnight shifts, select the following date for Time Out or Lunch In. Quick breaks remain unchanged.</p>
            <div><label class="required" for="correction-reason">Reason</label><textarea name="reason" id="correction-reason" rows="4" maxlength="1000" placeholder="Explain what is incorrect or why the record is missing." required></textarea></div>
            <div class="modal-actions"><button type="button" class="btn btn-secondary" data-correction-close>Cancel</button><button type="submit" class="btn btn-primary">Submit for Review</button></div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/employee_layout_end.php'; ?>
