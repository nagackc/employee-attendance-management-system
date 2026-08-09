<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

function reportEmployeeName(array $employee): string {
    $middle = trim((string)($employee['middle_name'] ?? ''));
    return trim((string)$employee['first_name'] . ($middle !== '' ? ' ' . $middle : '') . ' ' . (string)$employee['last_name']);
}

function reportTime(array $record, string $field, array $schedule): string {
    return formatEmployeeTime(
        isset($record[$field]) ? (string)$record[$field] : null,
        (string)($record['schedule_timezone'] ?? $schedule['timezone'])
    );
}

function reportLunchLabel(array $record, array $schedule): string {
    $start = reportTime($record, 'break_start', $schedule);
    $end = reportTime($record, 'break_end', $schedule);
    $minutes = max(0, (int)($record['break_minutes'] ?? 0));
    if ($start === '—' && $end === '—') {
        return $minutes > 0 ? $minutes . ' min' : '—';
    }
    return $start . '–' . ($end === '—' ? 'Open' : $end) . ($minutes > 0 ? ' · ' . $minutes . ' min' : '');
}

$schedule = getAttendanceSchedule($pdo);
$type = (string)($_GET['type'] ?? 'date_range');
if (!in_array($type, ['date_range', 'employee_history'], true)) {
    $type = 'date_range';
}

$employees = $pdo->query('SELECT id, first_name, middle_name, last_name, company, created_at, active, deactivated_at
    FROM employees WHERE role = "employee" ORDER BY active DESC, first_name, last_name')->fetchAll();
$companies = $pdo->query('SELECT DISTINCT company FROM employees WHERE role = "employee" AND company <> "" ORDER BY company')->fetchAll(PDO::FETCH_COLUMN);
$employeeById = [];
foreach ($employees as $employee) {
    $employeeById[(int)$employee['id']] = $employee;
}

$startDate = trim((string)($_GET[$type === 'employee_history' ? 'hist_start' : 'start_date'] ?? ''));
$endDate = trim((string)($_GET[$type === 'employee_history' ? 'hist_end' : 'end_date'] ?? ''));
$employeeSelection = trim((string)($_GET['employee_id'] ?? 'all'));
$companyFilter = trim((string)($_GET['company'] ?? ''));
$export = trim((string)($_GET['export'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$reportError = '';
$reportGenerated = $startDate !== '' || $endDate !== '';

if ($companyFilter !== '' && !in_array($companyFilter, $companies, true)) {
    $reportError = 'Select a valid company.';
} elseif ($employeeSelection !== 'all' && (!ctype_digit($employeeSelection) || !isset($employeeById[(int)$employeeSelection]))) {
    $reportError = 'Select a valid employee or All Employees.';
} elseif ($reportGenerated && (!isValidDateValue($startDate) || !isValidDateValue($endDate))) {
    $reportError = 'Enter valid start and end dates.';
} elseif ($reportGenerated && $endDate < $startDate) {
    $reportError = 'End date cannot be before start date.';
}

$eligibleEmployees = array_values(array_filter($employees, static function (array $employee) use ($companyFilter, $employeeSelection): bool {
    if ($companyFilter !== '' && (string)$employee['company'] !== $companyFilter) {
        return false;
    }
    return $employeeSelection === 'all' || (int)$employee['id'] === (int)$employeeSelection;
}));

$records = [];
$pagedRecords = [];
$summary = [
    'employees' => count($eligibleEmployees),
    'daily_attendance' => 0,
    'present' => 0,
    'late' => 0,
    'scheduled_days' => 0,
    'absent' => 0,
    'total_hours' => 0.0,
    'regular_minutes' => 0,
    'overtime_minutes' => 0,
    'late_minutes' => 0,
    'undertime_minutes' => 0,
    'holiday_minutes' => 0,
    'rest_day_minutes' => 0,
];
$pages = 1;
$totalRecords = 0;

if ($reportGenerated && $reportError === '') {
    $sql = 'SELECT a.*, e.first_name, e.middle_name, e.last_name, e.company, e.active,
            COALESCE(qb.quick_break_seconds, 0) AS quick_break_seconds
        FROM attendance a
        INNER JOIN employees e ON e.id = a.employee_id
        LEFT JOIN (
            SELECT attendance_id,
                SUM(COALESCE(duration_seconds, TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, NOW())))) AS quick_break_seconds
            FROM attendance_quick_breaks GROUP BY attendance_id
        ) qb ON qb.attendance_id = a.id
        WHERE a.attendance_date BETWEEN ? AND ? AND a.voided_at IS NULL';
    $params = [$startDate, $endDate];
    if ($employeeSelection !== 'all') {
        $sql .= ' AND a.employee_id = ?';
        $params[] = (int)$employeeSelection;
    }
    if ($companyFilter !== '') {
        $sql .= ' AND e.company = ?';
        $params[] = $companyFilter;
    }
    $sql .= ' ORDER BY a.attendance_date DESC, e.last_name ASC, e.first_name ASC, a.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    $holidayMap = getHolidayMap($pdo, $startDate, $endDate);

    $presentByEmployee = [];
    $lateCount = 0;
    $totalHours = 0.0;
    $payrollTotals = ['regular_minutes' => 0, 'overtime_minutes' => 0, 'late_minutes' => 0,
        'undertime_minutes' => 0, 'holiday_minutes' => 0, 'rest_day_minutes' => 0];
    foreach ($records as &$record) {
        $recordEmployeeId = (int)$record['employee_id'];
        $presentByEmployee[$recordEmployeeId][(string)$record['attendance_date']] = true;
        $totalHours += (float)($record['total_hours'] ?? 0);
        $record['_payroll'] = calculateAttendancePayrollMetrics(
            $record,
            $schedule,
            $holidayMap[(string)$record['attendance_date']] ?? null
        );
        foreach ($payrollTotals as $metric => $unused) {
            $payrollTotals[$metric] += (int)$record['_payroll'][$metric];
        }
        if ((int)$record['_payroll']['late_minutes'] > 0) {
            $lateCount++;
        }
    }
    unset($record);

    $scheduledDays = 0;
    $absentDays = 0;
    foreach ($eligibleEmployees as $employee) {
        $asOfDate = date('Y-m-d');
        if ((int)$employee['active'] === 0 && !empty($employee['deactivated_at'])) {
            $asOfDate = min($asOfDate, substr((string)$employee['deactivated_at'], 0, 10));
        }
        $absence = calculateScheduledAbsences(
            $pdo,
            (int)$employee['id'],
            $startDate,
            $endDate,
            substr((string)$employee['created_at'], 0, 10),
            $presentByEmployee[(int)$employee['id']] ?? [],
            $asOfDate
        );
        $scheduledDays += (int)$absence['scheduled_days'];
        $absentDays += (int)$absence['absent'];
    }

    $summary = [
        'employees' => count($eligibleEmployees),
        'daily_attendance' => count($records),
        'present' => array_sum(array_map('count', $presentByEmployee)),
        'late' => $lateCount,
        'scheduled_days' => $scheduledDays,
        'absent' => $absentDays,
        'total_hours' => $totalHours,
        'regular_minutes' => $payrollTotals['regular_minutes'],
        'overtime_minutes' => $payrollTotals['overtime_minutes'],
        'late_minutes' => $payrollTotals['late_minutes'],
        'undertime_minutes' => $payrollTotals['undertime_minutes'],
        'holiday_minutes' => $payrollTotals['holiday_minutes'],
        'rest_day_minutes' => $payrollTotals['rest_day_minutes'],
    ];

    $totalRecords = count($records);
    $pages = max(1, (int)ceil($totalRecords / $perPage));
    $page = min($page, $pages);
    $pagedRecords = array_slice($records, ($page - 1) * $perPage, $perPage);

    if ($export === 'csv') {
        $scopeLabel = $employeeSelection === 'all' ? 'All Employees' : reportEmployeeName($employeeById[(int)$employeeSelection]);
        $filenameType = $type === 'employee_history' ? 'employee-history' : 'date-range';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="attendance-' . $filenameType . '-' . $startDate . '-to-' . $endDate . '.csv"');
        echo chr(239) . chr(187) . chr(191);
        $output = fopen('php://output', 'w');
        fputcsv($output, [$type === 'employee_history' ? 'Employee Attendance History' : 'Attendance by Date Range']);
        fputcsv($output, ['Employee Scope', $scopeLabel]);
        fputcsv($output, ['Company', $companyFilter !== '' ? $companyFilter : 'All Companies']);
        fputcsv($output, ['Date Range', formatEmployeeDate($startDate) . ' to ' . formatEmployeeDate($endDate)]);
        fputcsv($output, ['Attendance Records', $summary['daily_attendance'], 'Present Days', $summary['present'], 'Late', $summary['late']]);
        fputcsv($output, ['Scheduled Days', $summary['scheduled_days'], 'Absent', $summary['absent'], 'Net Hours', formatHours($summary['total_hours'])]);
        fputcsv($output, ['Regular Hours', formatHours($summary['regular_minutes'] / 60), 'Overtime Hours', formatHours($summary['overtime_minutes'] / 60),
            'Late Minutes', $summary['late_minutes'], 'Undertime Minutes', $summary['undertime_minutes']]);
        fputcsv($output, ['Holiday Work Hours', formatHours($summary['holiday_minutes'] / 60),
            'Rest Day Work Hours', formatHours($summary['rest_day_minutes'] / 60)]);
        fputcsv($output, []);
        fputcsv($output, ['Employee', 'Company', 'Shift', 'Date', 'Day Type', 'Holiday', 'Time In', 'Lunch', 'Quick Break Minutes', 'Time Out',
            'Net Hours', 'Regular Hours', 'Overtime Hours', 'Late Minutes', 'Undertime Minutes', 'Status']);
        foreach ($records as $record) {
            $payroll = $record['_payroll'];
            fputcsv($output, [
                reportEmployeeName($record),
                $record['company'],
                $record['shift_name'] ?: 'Default Schedule',
                formatEmployeeDate((string)$record['attendance_date']),
                $payroll['day_type'],
                $payroll['holiday_name'],
                reportTime($record, 'time_in', $schedule),
                reportLunchLabel($record, $schedule),
                (int)round((int)$record['quick_break_seconds'] / 60),
                reportTime($record, 'time_out', $schedule),
                formatHours($record['total_hours']),
                formatHours($payroll['regular_minutes'] / 60),
                formatHours($payroll['overtime_minutes'] / 60),
                $payroll['late_minutes'],
                $payroll['undertime_minutes'],
                ucfirst(str_replace('_', ' ', (string)$record['status'])),
            ]);
        }
        fclose($output);
        exit;
    }
}

$selectedEmployeeLabel = $employeeSelection === 'all'
    ? 'All Employees'
    : (isset($employeeById[(int)$employeeSelection]) ? reportEmployeeName($employeeById[(int)$employeeSelection]) : 'Select Employee');
$filterQuery = [
    'type' => $type,
    'employee_id' => $employeeSelection,
    'company' => $companyFilter,
    $type === 'employee_history' ? 'hist_start' : 'start_date' => $startDate,
    $type === 'employee_history' ? 'hist_end' : 'end_date' => $endDate,
];

$companyName = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$pageTitle = 'Reports';
$activePage = 'reports';
$activeSubPage = $type === 'employee_history' ? 'employee_reports' : 'date_range_reports';
include __DIR__ . '/../includes/admin_layout_start.php';
?>

<section class="page-header report-page-header">
    <div><h1>Reports</h1><p>Review attendance, regular hours, overtime, lateness, undertime, and special-day work with exportable results.</p></div>
</section>

<nav class="admin-page-tabs" aria-label="Report type">
    <a class="<?= $type === 'date_range' ? 'is-active' : '' ?>" href="reports.php?type=date_range"<?= $type === 'date_range' ? ' aria-current="page"' : '' ?>>Date Range</a>
    <a class="<?= $type === 'employee_history' ? 'is-active' : '' ?>" href="reports.php?type=employee_history"<?= $type === 'employee_history' ? ' aria-current="page"' : '' ?>>Employee History</a>
    <a href="payroll_export.php">Payroll Export</a>
</nav>

<?php if ($reportError !== ''): ?><div class="message"><?= h($reportError) ?></div><?php endif; ?>

<article class="content-card report-filter-card">
    <div class="card-header">
        <div>
            <h3><?= $type === 'employee_history' ? 'Employee Attendance History' : 'Attendance by Date Range' ?></h3>
            <p class="muted"><?= $type === 'employee_history' ? 'Choose one employee or generate a combined company-wide history.' : 'Generate an attendance register for the selected scope.' ?></p>
        </div>
    </div>
    <form method="get" class="form-grid-5 report-filter-form">
        <input type="hidden" name="type" value="<?= h($type) ?>">
        <div>
            <label for="report-company">Company</label>
            <select id="report-company" name="company">
                <option value="">All Companies</option>
                <?php foreach ($companies as $company): ?><option value="<?= h($company) ?>" <?= $companyFilter === $company ? 'selected' : '' ?>><?= h($company) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="report-employee">Employee</label>
            <select id="report-employee" name="employee_id" required>
                <option value="all" <?= $employeeSelection === 'all' ? 'selected' : '' ?>>All Employees</option>
                <?php foreach ($employees as $employee): ?>
                    <option value="<?= (int)$employee['id'] ?>" data-company="<?= h($employee['company']) ?>" <?= $employeeSelection === (string)$employee['id'] ? 'selected' : '' ?>><?= h(reportEmployeeName($employee) . ' · ' . $employee['company'] . ((int)$employee['active'] === 0 ? ' (Inactive)' : '')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="report-start">Start Date</label>
            <input id="report-start" type="date" name="<?= $type === 'employee_history' ? 'hist_start' : 'start_date' ?>" value="<?= h($startDate) ?>" required>
        </div>
        <div>
            <label for="report-end">End Date</label>
            <input id="report-end" type="date" name="<?= $type === 'employee_history' ? 'hist_end' : 'end_date' ?>" value="<?= h($endDate) ?>" required>
        </div>
        <div class="form-action-cell"><button type="submit" class="btn btn-primary">Generate Report</button></div>
    </form>
</article>

<?php if ($reportGenerated && $reportError === ''): ?>
    <section class="summary-grid report-summary-grid" aria-label="Report summary">
        <div class="summary-card"><strong>Employees</strong><div class="summary-value"><?= (int)$summary['employees'] ?></div></div>
        <div class="summary-card"><strong>Attendance Records</strong><div class="summary-value"><?= (int)$summary['daily_attendance'] ?></div></div>
        <div class="summary-card"><strong>Present Days</strong><div class="summary-value"><?= (int)$summary['present'] ?></div></div>
        <div class="summary-card"><strong>Late</strong><div class="summary-value"><?= (int)$summary['late'] ?></div></div>
        <div class="summary-card"><strong>Scheduled Days</strong><div class="summary-value"><?= (int)$summary['scheduled_days'] ?></div></div>
        <div class="summary-card"><strong>Absent</strong><div class="summary-value"><?= (int)$summary['absent'] ?></div></div>
        <div class="summary-card"><strong>Total Hours</strong><div class="summary-value"><?= h(formatHours($summary['total_hours'])) ?></div></div>
        <div class="summary-card"><strong>Regular Hours</strong><div class="summary-value"><?= h(formatHours($summary['regular_minutes'] / 60)) ?></div></div>
        <div class="summary-card"><strong>Overtime</strong><div class="summary-value"><?= h(formatHours($summary['overtime_minutes'] / 60)) ?></div></div>
        <div class="summary-card"><strong>Late Minutes</strong><div class="summary-value"><?= (int)$summary['late_minutes'] ?></div></div>
        <div class="summary-card"><strong>Undertime</strong><div class="summary-value"><?= h(formatMinutesDuration((int)$summary['undertime_minutes'])) ?></div></div>
        <div class="summary-card"><strong>Holiday / Rest Work</strong><div class="summary-value"><?= h(formatHours(($summary['holiday_minutes'] + $summary['rest_day_minutes']) / 60)) ?></div></div>
    </section>

    <article class="content-card report-results-card">
        <div class="card-header report-results-header">
            <div>
                <h3><?= h($selectedEmployeeLabel) ?></h3>
                <p class="muted"><?= h(formatEmployeeDate($startDate)) ?> – <?= h(formatEmployeeDate($endDate)) ?> · <?= $companyFilter !== '' ? h($companyFilter) : 'All Companies' ?> · <?= $totalRecords ?> record(s) · Payroll values are automatic estimates from saved shift data.</p>
            </div>
            <a class="btn btn-secondary btn-sm" href="reports.php?<?= h(http_build_query(array_merge($filterQuery, ['export' => 'csv']))) ?>">Export CSV</a>
        </div>

        <div class="table-card report-table-card" data-sticky-head="true">
            <table class="report-table">
                <thead><tr><th>Employee</th><th>Company</th><th>Shift</th><th>Date</th><th>Day Type</th><th>Time In</th><th>Lunch</th><th>Quick Break</th><th>Time Out</th><th>Net</th><th>Regular</th><th>OT</th><th>Late</th><th>Undertime</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if ($pagedRecords): foreach ($pagedRecords as $record): $payroll = $record['_payroll']; ?>
                        <tr>
                            <td><strong><?= h(reportEmployeeName($record)) ?></strong><?= (int)$record['active'] === 0 ? '<span class="report-inactive-label">Inactive</span>' : '' ?></td>
                            <td><?= h($record['company']) ?></td>
                            <td><?= h($record['shift_name'] ?: 'Default Schedule') ?></td>
                            <td class="report-date-cell"><?= h(formatEmployeeDate((string)$record['attendance_date'])) ?></td>
                            <td><?= payrollDayTypePill((string)$payroll['day_type']) ?><?= $payroll['holiday_name'] !== '' ? '<span class="report-cell-note">' . h($payroll['holiday_name']) . '</span>' : '' ?></td>
                            <td class="report-time-cell"><?= h(reportTime($record, 'time_in', $schedule)) ?></td>
                            <td class="report-lunch-cell"><?= h(reportLunchLabel($record, $schedule)) ?></td>
                            <td><?= (int)round((int)$record['quick_break_seconds'] / 60) ?> min</td>
                            <td class="report-time-cell"><?= h(reportTime($record, 'time_out', $schedule)) ?></td>
                            <td><?= h(formatHours($record['total_hours'])) ?></td>
                            <td><?= h(formatHours($payroll['regular_minutes'] / 60)) ?></td>
                            <td><?= h(formatHours($payroll['overtime_minutes'] / 60)) ?></td>
                            <td><?= h(formatMinutesDuration((int)$payroll['late_minutes'])) ?></td>
                            <td><?= h(formatMinutesDuration((int)$payroll['undertime_minutes'])) ?></td>
                            <td><?= statusPill((string)$record['status']) ?><?= (int)$payroll['late_minutes'] > 0 ? ' ' . latenessPill(true) : '' ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="15" class="table-empty-cell">No attendance records match this report.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="pagination report-pagination" aria-label="Report pages">
                <?php $paginationBase = $filterQuery; ?>
                <?php if ($page > 1): ?><a href="reports.php?<?= h(http_build_query(array_merge($paginationBase, ['page' => $page - 1]))) ?>">Previous</a><?php endif; ?>
                <span class="current">Page <?= $page ?> of <?= $pages ?></span>
                <?php if ($page < $pages): ?><a href="reports.php?<?= h(http_build_query(array_merge($paginationBase, ['page' => $page + 1]))) ?>">Next</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </article>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
