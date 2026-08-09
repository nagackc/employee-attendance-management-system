<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

$todayDate = new DateTimeImmutable('today');
$monthFirst = $todayDate->modify('first day of this month');
$monthLast = $todayDate->modify('last day of this month');
if ((int)$todayDate->format('j') <= 15) {
    $currentPeriodStart = $monthFirst;
    $currentPeriodEnd = $monthFirst->setDate((int)$monthFirst->format('Y'), (int)$monthFirst->format('m'), 15);
    $previousMonth = $monthFirst->modify('-1 month');
    $previousPeriodStart = $previousMonth->setDate((int)$previousMonth->format('Y'), (int)$previousMonth->format('m'), 16);
    $previousPeriodEnd = $previousMonth->modify('last day of this month');
} else {
    $currentPeriodStart = $monthFirst->setDate((int)$monthFirst->format('Y'), (int)$monthFirst->format('m'), 16);
    $currentPeriodEnd = $monthLast;
    $previousPeriodStart = $monthFirst;
    $previousPeriodEnd = $monthFirst->setDate((int)$monthFirst->format('Y'), (int)$monthFirst->format('m'), 15);
}

$startDate = trim((string)($_GET['start_date'] ?? $currentPeriodStart->format('Y-m-d')));
$endDate = trim((string)($_GET['end_date'] ?? $currentPeriodEnd->format('Y-m-d')));
$companyFilter = trim((string)($_GET['company'] ?? ''));
$employeeSelection = trim((string)($_GET['employee_id'] ?? 'all'));
$exportType = trim((string)($_GET['export'] ?? ''));
$summaryPage = max(1, (int)($_GET['page'] ?? 1));
$reviewPage = max(1, (int)($_GET['review_page'] ?? 1));

$employees = $pdo->query('SELECT id, first_name, middle_name, last_name, company, active
    FROM employees WHERE role = "employee"
    ORDER BY active DESC, company ASC, last_name ASC, first_name ASC')->fetchAll();
$companies = $pdo->query('SELECT DISTINCT company FROM employees
    WHERE role = "employee" AND company <> "" ORDER BY company ASC')->fetchAll(PDO::FETCH_COLUMN);
$employeeById = [];
foreach ($employees as $employee) {
    $employeeById[(int)$employee['id']] = $employee;
}

$error = '';
if (!isValidDateValue($startDate) || !isValidDateValue($endDate)) {
    $error = 'Enter valid start and end dates.';
} elseif ($endDate < $startDate) {
    $error = 'End date cannot be before start date.';
} elseif ((new DateTimeImmutable($startDate))->diff(new DateTimeImmutable($endDate))->days > 366) {
    $error = 'Payroll exports are limited to a 366-day date range.';
} elseif ($companyFilter !== '' && !in_array($companyFilter, $companies, true)) {
    $error = 'Select a valid company.';
} elseif ($employeeSelection !== 'all' && (!ctype_digit($employeeSelection) || !isset($employeeById[(int)$employeeSelection]))) {
    $error = 'Select a valid employee or All Employees.';
} elseif ($employeeSelection !== 'all' && $companyFilter !== ''
    && (string)$employeeById[(int)$employeeSelection]['company'] !== $companyFilter) {
    $error = 'The selected employee does not belong to the selected company.';
}

$dataset = [
    'finalized_rows' => [],
    'approved_leave_rows' => [],
    'exceptions' => [],
    'summaries' => [],
    'totals' => [
        'employees' => 0, 'attendance_days' => 0, 'net_minutes' => 0, 'regular_minutes' => 0,
        'overtime_minutes' => 0, 'holiday_minutes' => 0, 'rest_day_minutes' => 0,
        'late_minutes' => 0, 'undertime_minutes' => 0, 'lunch_minutes' => 0, 'quick_break_minutes' => 0,
        'approved_leave_minutes' => 0,
    ],
];
if ($error === '') {
    $dataset = buildPayrollExportDataset(
        $pdo,
        $startDate,
        $endDate,
        $employeeSelection === 'all' ? null : (int)$employeeSelection,
        $companyFilter
    );
}

$queryFor = static function (array $changes = []) use ($startDate, $endDate, $companyFilter, $employeeSelection): string {
    $query = [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'employee_id' => $employeeSelection,
    ];
    if ($companyFilter !== '') {
        $query['company'] = $companyFilter;
    }
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return http_build_query($query);
};

if ($error === '' && in_array($exportType, ['summary', 'detail'], true)) {
    $filename = 'payroll-' . $exportType . '-' . $startDate . '-to-' . $endDate . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo chr(239) . chr(187) . chr(191);
    $output = fopen('php://output', 'w');
    $writeRow = static function ($output, array $row): void {
        fputcsv($output, array_map('csvSafeCell', $row));
    };
    $writeRow($output, ['Payroll Export', ucfirst($exportType)]);
    $writeRow($output, ['Date Range', $startDate, $endDate]);
    $writeRow($output, ['Company', $companyFilter !== '' ? $companyFilter : 'All Companies']);
    $writeRow($output, ['Employee', $employeeSelection === 'all'
        ? 'All Employees'
        : employeeDisplayName($employeeById[(int)$employeeSelection])]);
    $writeRow($output, ['Finalized Records', count($dataset['finalized_rows']), 'Approved Leave Charges', count($dataset['approved_leave_rows']), 'Needs Review', count($dataset['exceptions'])]);
    $writeRow($output, []);

    if ($exportType === 'summary') {
        $writeRow($output, ['Employee No.', 'Employee', 'Company', 'Attendance Days', 'Net Hours', 'Regular Hours',
            'Overtime Hours', 'Holiday Work Hours', 'Rest Day Work Hours', 'Late Minutes', 'Undertime Minutes',
            'Approved Leave Hours', 'Lunch Minutes', 'Quick Break Minutes', 'Account Status']);
        foreach ($dataset['summaries'] as $summary) {
            $writeRow($output, [
                $summary['employee_number'], $summary['employee_name'], $summary['company'], $summary['attendance_days'],
                formatHours($summary['net_minutes'] / 60), formatHours($summary['regular_minutes'] / 60),
                formatHours($summary['overtime_minutes'] / 60), formatHours($summary['holiday_minutes'] / 60),
                formatHours($summary['rest_day_minutes'] / 60), $summary['late_minutes'], $summary['undertime_minutes'],
                formatHours($summary['approved_leave_minutes'] / 60), $summary['lunch_minutes'], $summary['quick_break_minutes'],
                (int)$summary['active'] === 1 ? 'Active' : 'Inactive',
            ]);
        }
    } else {
        $writeRow($output, ['Employee No.', 'Employee', 'Company', 'Attendance Date', 'Shift', 'Timezone', 'Day Type',
            'Holiday', 'Time In', 'Lunch Out', 'Lunch In', 'Lunch Minutes', 'Quick Break Minutes', 'Time Out',
            'Net Hours', 'Regular Hours', 'Overtime Hours', 'Holiday Work Hours', 'Rest Day Work Hours',
            'Late Minutes', 'Undertime Minutes', 'Status']);
        foreach ($dataset['finalized_rows'] as $record) {
            $payroll = $record['_payroll'];
            $timezone = (string)$record['schedule_timezone'];
            $writeRow($output, [
                'EMP-' . str_pad((string)$record['employee_id'], 6, '0', STR_PAD_LEFT),
                $record['_employee_name'], $record['company'], $record['attendance_date'],
                $record['shift_name'] ?: 'Default Schedule', $timezone, $payroll['day_type'], $payroll['holiday_name'],
                formatEmployeeTime((string)$record['time_in'], $timezone),
                formatEmployeeTime($record['break_start'] !== null ? (string)$record['break_start'] : null, $timezone),
                formatEmployeeTime($record['break_end'] !== null ? (string)$record['break_end'] : null, $timezone),
                (int)$record['break_minutes'], (int)$record['_quick_break_minutes'],
                formatEmployeeTime((string)$record['time_out'], $timezone),
                formatHours($payroll['net_minutes'] / 60), formatHours($payroll['regular_minutes'] / 60),
                formatHours($payroll['overtime_minutes'] / 60), formatHours($payroll['holiday_minutes'] / 60),
                formatHours($payroll['rest_day_minutes'] / 60), $payroll['late_minutes'], $payroll['undertime_minutes'],
                'Completed',
            ]);
        }
        if ($dataset['approved_leave_rows']) {
            $writeRow($output, []);
            $writeRow($output, ['Approved Leave Charges']);
            $writeRow($output, ['Employee No.', 'Employee', 'Company', 'Charge Date', 'Leave Type', 'Hours', 'Request No.']);
            foreach ($dataset['approved_leave_rows'] as $leaveRow) {
                $writeRow($output, [
                    'EMP-' . str_pad((string)$leaveRow['employee_id'], 6, '0', STR_PAD_LEFT),
                    $leaveRow['_employee_name'], $leaveRow['company'], $leaveRow['charge_date'], $leaveRow['leave_type_name'],
                    formatHours((int)$leaveRow['minutes'] / 60), 'LEAVE-' . str_pad((string)$leaveRow['leave_request_id'], 6, '0', STR_PAD_LEFT),
                ]);
            }
        }
    }
    fclose($output);
    exit;
}

$summaryPerPage = 25;
$summaryPages = max(1, (int)ceil(count($dataset['summaries']) / $summaryPerPage));
$summaryPage = min($summaryPage, $summaryPages);
$pagedSummaries = array_slice($dataset['summaries'], ($summaryPage - 1) * $summaryPerPage, $summaryPerPage);
$reviewPerPage = 20;
$reviewPages = max(1, (int)ceil(count($dataset['exceptions']) / $reviewPerPage));
$reviewPage = min($reviewPage, $reviewPages);
$pagedExceptions = array_slice($dataset['exceptions'], ($reviewPage - 1) * $reviewPerPage, $reviewPerPage);
$totals = $dataset['totals'];

$shortcutQuery = static function (DateTimeImmutable $from, DateTimeImmutable $to) use ($companyFilter, $employeeSelection): string {
    $query = ['start_date' => $from->format('Y-m-d'), 'end_date' => $to->format('Y-m-d'), 'employee_id' => $employeeSelection];
    if ($companyFilter !== '') {
        $query['company'] = $companyFilter;
    }
    return http_build_query($query);
};

$companyName = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$pageTitle = 'Payroll Export';
$activePage = 'reports';
$activeSubPage = 'payroll_export';
include __DIR__ . '/../includes/admin_layout_start.php';
?>

<section class="page-header payroll-page-header">
    <div>
        <p class="employee-eyebrow">Finalized time data</p>
        <h1>Payroll Export</h1>
        <p>Review payroll-ready hours and resolve incomplete attendance before downloading CSV files.</p>
    </div>
</section>

<nav class="admin-page-tabs" aria-label="Report type">
    <a href="reports.php?type=date_range">Date Range</a>
    <a href="reports.php?type=employee_history">Employee History</a>
    <a class="is-active" href="payroll_export.php" aria-current="page">Payroll Export</a>
</nav>

<?php if ($error !== ''): ?><div class="message"><?= h($error) ?></div><?php endif; ?>

<article class="content-card payroll-filter-card">
    <div class="payroll-period-shortcuts" aria-label="Pay period shortcuts">
        <span>Quick periods</span>
        <a href="payroll_export.php?<?= h($shortcutQuery($currentPeriodStart, $currentPeriodEnd)) ?>">Current cutoff</a>
        <a href="payroll_export.php?<?= h($shortcutQuery($previousPeriodStart, $previousPeriodEnd)) ?>">Previous cutoff</a>
        <a href="payroll_export.php?<?= h($shortcutQuery($monthFirst, $monthLast)) ?>">This month</a>
    </div>
    <form method="get" class="payroll-filter-form">
        <div>
            <label for="report-company">Company</label>
            <select id="report-company" name="company">
                <option value="">All Companies</option>
                <?php foreach ($companies as $company): ?><option value="<?= h($company) ?>" <?= $companyFilter === $company ? 'selected' : '' ?>><?= h($company) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="report-employee">Employee</label>
            <select id="report-employee" name="employee_id">
                <option value="all" <?= $employeeSelection === 'all' ? 'selected' : '' ?>>All Employees</option>
                <?php foreach ($employees as $employee): ?>
                    <option value="<?= (int)$employee['id'] ?>" data-company="<?= h((string)$employee['company']) ?>" <?= $employeeSelection === (string)$employee['id'] ? 'selected' : '' ?>><?= h(employeeDisplayName($employee) . ' · ' . $employee['company'] . ((int)$employee['active'] === 0 ? ' (Inactive)' : '')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label for="payroll-start">Start Date</label><input id="payroll-start" type="date" name="start_date" value="<?= h($startDate) ?>" required></div>
        <div><label for="payroll-end">End Date</label><input id="payroll-end" type="date" name="end_date" value="<?= h($endDate) ?>" required></div>
        <div class="payroll-filter-action"><button class="btn btn-primary" type="submit">Generate Preview</button></div>
    </form>
</article>

<?php if ($error === ''): ?>
<section class="summary-grid payroll-summary-grid" aria-label="Payroll export summary">
    <div class="summary-card"><strong>Employees</strong><div class="summary-value"><?= (int)$totals['employees'] ?></div></div>
    <div class="summary-card"><strong>Finalized Records</strong><div class="summary-value"><?= count($dataset['finalized_rows']) ?></div></div>
    <div class="summary-card"><strong>Regular Hours</strong><div class="summary-value"><?= h(formatHours($totals['regular_minutes'] / 60)) ?></div></div>
    <div class="summary-card"><strong>Overtime Hours</strong><div class="summary-value"><?= h(formatHours($totals['overtime_minutes'] / 60)) ?></div></div>
    <div class="summary-card"><strong>Approved Leave</strong><div class="summary-value"><?= h(formatHours($totals['approved_leave_minutes'] / 60)) ?></div></div>
    <div class="summary-card"><strong>Late</strong><div class="summary-value"><?= h(formatMinutesDuration((int)$totals['late_minutes'])) ?></div></div>
    <div class="summary-card"><strong>Undertime</strong><div class="summary-value"><?= h(formatMinutesDuration((int)$totals['undertime_minutes'])) ?></div></div>
    <div class="summary-card payroll-review-summary"><strong>Needs Review</strong><div class="summary-value"><?= count($dataset['exceptions']) ?></div></div>
</section>

<article class="content-card payroll-export-card">
    <div class="card-header payroll-export-header">
        <div>
            <h3>Payroll Summary</h3>
            <p class="muted"><?= h(formatEmployeeDate($startDate)) ?> – <?= h(formatEmployeeDate($endDate)) ?> · Overtime includes holiday and rest-day work. Approved leave is separate because paid/unpaid rules are not configured. Pay rates are not applied.</p>
        </div>
        <div class="payroll-export-actions">
            <?php if ($dataset['finalized_rows'] || $dataset['approved_leave_rows']): ?>
                <a class="btn btn-secondary btn-sm" href="payroll_export.php?<?= h($queryFor(['export' => 'summary'])) ?>">Download Summary CSV</a>
                <a class="btn btn-primary btn-sm" href="payroll_export.php?<?= h($queryFor(['export' => 'detail'])) ?>">Download Detailed CSV</a>
            <?php else: ?>
                <span class="muted">No finalized records to export.</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-card payroll-summary-table" data-sticky-head="true">
        <table>
            <thead><tr><th>Employee</th><th>Company</th><th>Days</th><th>Net</th><th>Regular</th><th>Overtime</th><th>Holiday</th><th>Rest Day</th><th>Approved Leave</th><th>Late</th><th>Undertime</th><th>Breaks</th></tr></thead>
            <tbody>
                <?php if ($pagedSummaries): foreach ($pagedSummaries as $summary): ?>
                    <tr>
                        <td><strong><?= h($summary['employee_name']) ?></strong><span class="table-subtext"><?= h($summary['employee_number']) ?><?= (int)$summary['active'] === 0 ? ' · Inactive' : '' ?></span></td>
                        <td><?= h($summary['company']) ?></td>
                        <td><?= (int)$summary['attendance_days'] ?></td>
                        <td><strong><?= h(formatHours($summary['net_minutes'] / 60)) ?></strong></td>
                        <td><?= h(formatHours($summary['regular_minutes'] / 60)) ?></td>
                        <td><?= h(formatHours($summary['overtime_minutes'] / 60)) ?></td>
                        <td><?= h(formatHours($summary['holiday_minutes'] / 60)) ?></td>
                        <td><?= h(formatHours($summary['rest_day_minutes'] / 60)) ?></td>
                        <td><?= h(formatHours($summary['approved_leave_minutes'] / 60)) ?></td>
                        <td><?= h(formatMinutesDuration((int)$summary['late_minutes'])) ?></td>
                        <td><?= h(formatMinutesDuration((int)$summary['undertime_minutes'])) ?></td>
                        <td><span class="payroll-break-cell">Lunch <?= (int)$summary['lunch_minutes'] ?>m</span><span class="payroll-break-cell">Quick <?= (int)$summary['quick_break_minutes'] ?>m</span></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="12" class="table-empty-cell">No finalized attendance or approved leave records match this pay period.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($summaryPages > 1): ?>
        <nav class="pagination report-pagination" aria-label="Payroll summary pages">
            <?php if ($summaryPage > 1): ?><a href="payroll_export.php?<?= h($queryFor(['page' => $summaryPage - 1, 'review_page' => $reviewPage])) ?>">Previous</a><?php endif; ?>
            <span class="current">Page <?= $summaryPage ?> of <?= $summaryPages ?></span>
            <?php if ($summaryPage < $summaryPages): ?><a href="payroll_export.php?<?= h($queryFor(['page' => $summaryPage + 1, 'review_page' => $reviewPage])) ?>">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</article>

<article class="content-card payroll-review-card" id="payroll-review">
    <div class="card-header payroll-review-header">
        <div>
            <p class="employee-eyebrow">Excluded from exports</p>
            <h3>Needs Review</h3>
            <p class="muted">Open or invalid attendance is shown here and is never included in payroll CSV totals.</p>
        </div>
        <span class="pill <?= $dataset['exceptions'] ? 'pill-red' : 'pill-green' ?>"><?= count($dataset['exceptions']) ?> record(s)</span>
    </div>
    <div class="table-card payroll-review-table">
        <table>
            <thead><tr><th>Employee</th><th>Company</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Status</th><th>Issue</th><th>Action</th></tr></thead>
            <tbody>
                <?php if ($pagedExceptions): foreach ($pagedExceptions as $record): ?>
                    <tr>
                        <td><strong><?= h($record['_employee_name']) ?></strong></td><td><?= h($record['company']) ?></td>
                        <td><?= h(formatEmployeeDate((string)$record['attendance_date'])) ?></td>
                        <td><?= h(formatEmployeeTime($record['time_in'] !== null ? (string)$record['time_in'] : null, (string)$record['schedule_timezone'])) ?></td>
                        <td><?= h(formatEmployeeTime($record['time_out'] !== null ? (string)$record['time_out'] : null, (string)$record['schedule_timezone'])) ?></td>
                        <td><?= statusPill((string)$record['status']) ?></td>
                        <td><span class="payroll-exception-reason"><?= h($record['_exception_reason']) ?></span></td>
                        <td><a class="btn btn-secondary btn-sm" href="edit_attendance.php?id=<?= (int)$record['id'] ?>">Review</a></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="8" class="table-empty-cell">All attendance records in this period are finalized.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($reviewPages > 1): ?>
        <nav class="pagination report-pagination" aria-label="Payroll review pages">
            <?php if ($reviewPage > 1): ?><a href="payroll_export.php?<?= h($queryFor(['review_page' => $reviewPage - 1, 'page' => $summaryPage])) ?>#payroll-review">Previous</a><?php endif; ?>
            <span class="current">Page <?= $reviewPage ?> of <?= $reviewPages ?></span>
            <?php if ($reviewPage < $reviewPages): ?><a href="payroll_export.php?<?= h($queryFor(['review_page' => $reviewPage + 1, 'page' => $summaryPage])) ?>#payroll-review">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</article>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
