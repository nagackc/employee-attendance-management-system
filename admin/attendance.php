<?php
require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

$schedule = getAttendanceSchedule($pdo);

$employeeFilter = trim($_GET['employee'] ?? '');
$dateFilter = trim($_GET['date'] ?? '');
$companyFilter = trim($_GET['company'] ?? '');
$companyOptions = $pdo->query('SELECT DISTINCT company FROM employees WHERE company <> "" ORDER BY company')->fetchAll(PDO::FETCH_COLUMN);

$query = 'SELECT a.*, e.first_name, e.last_name, e.company FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE 1=1';
$params = [];
if ($employeeFilter !== '') {
    $query .= ' AND (e.first_name LIKE ? OR e.last_name LIKE ?)';
    $like = "%$employeeFilter%";
    $params[] = $like;
    $params[] = $like;
}
if ($dateFilter !== '') {
    $query .= ' AND a.attendance_date = ?';
    $params[] = $dateFilter;
}
if ($companyFilter !== '') {
    $query .= ' AND e.company = ?';
    $params[] = $companyFilter;
}
$query .= ' ORDER BY a.id DESC';
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$attendanceRecords = $stmt->fetchAll();
$attendanceDates = array_column($attendanceRecords, 'attendance_date');
$holidayMap = $attendanceDates ? getHolidayMap($pdo, min($attendanceDates), max($attendanceDates)) : [];
foreach ($attendanceRecords as &$attendanceRecord) {
    $attendanceRecord['_payroll'] = calculateAttendancePayrollMetrics(
        $attendanceRecord,
        $schedule,
        $holidayMap[(string)$attendanceRecord['attendance_date']] ?? null
    );
}
unset($attendanceRecord);

$companyName = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$pageTitle = 'Attendance';
$activeSubPage = 'today_attendance';
include __DIR__ . '/../includes/admin_layout_start.php';
?>
<section class="page-header">
    <h1>Attendance</h1>
    <p>Search, filter, and manage employee attendance records.</p>
</section>

<article class="content-card">
    <div class="card-header">
        <h3>Today's Attendance</h3>
        <a class="button-link" href="reports.php?type=date_range">📊 View Reports</a>
    </div>

    <form method="get" class="form-grid-4" style="margin-bottom:12px;">
        <div>
            <label for="employee">Employee</label>
            <input id="employee" type="text" name="employee" value="<?= h($employeeFilter) ?>" placeholder="Employee name">
        </div>
        <div>
            <label for="date">Date</label>
            <input id="date" type="date" name="date" value="<?= h($dateFilter) ?>">
        </div>
        <div>
            <label for="company">Company</label>
            <select id="company" name="company">
                <option value="">All Companies</option>
                <?php foreach ($companyOptions as $companyOption): ?>
                    <option value="<?= h($companyOption) ?>" <?= $companyFilter === $companyOption ? 'selected' : '' ?>><?= h($companyOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex; align-items:flex-end;">
            <button type="submit">Filter</button>
        </div>
    </form>

    <div class="table-toolbar">
        <input class="table-search" type="search" placeholder="Quick filter current table..." data-table-search>
    </div>
    <div class="table-card" data-sticky-head="true" data-table-enhance="true" data-page-size="12">
        <table>
            <thead><tr><th>Employee</th><th>Shift</th><th>Date</th><th>Day Type</th><th>Time In</th><th>Time Out</th><th>Net</th><th>Regular</th><th>OT</th><th>Late</th><th>Undertime</th><th>Status</th><th data-sort="false">Actions</th></tr></thead>
            <tbody>
                <?php foreach ($attendanceRecords as $record): ?>
                    <?php $payroll = $record['_payroll']; ?>
                    <tr>
                        <td><?= h($record['first_name'] . ' ' . $record['last_name']) ?></td>
                        <td><?= h($record['shift_name'] ?: 'Default Schedule') ?></td>
                        <td><?= h(formatEmployeeDate((string)$record['attendance_date'])) ?></td>
                        <td><?= payrollDayTypePill((string)$payroll['day_type']) ?><?= $payroll['holiday_name'] !== '' ? '<span class="report-cell-note">' . h($payroll['holiday_name']) . '</span>' : '' ?></td>
                        <td><?= h(formatEmployeeTime($record['time_in'], (string)($record['schedule_timezone'] ?: $schedule['timezone']))) ?></td>
                        <td><?= h(formatEmployeeTime($record['time_out'], (string)($record['schedule_timezone'] ?: $schedule['timezone']))) ?></td>
                        <td><?= h(formatHours($record['total_hours'])) ?></td>
                        <td><?= h(formatHours($payroll['regular_minutes'] / 60)) ?></td>
                        <td><?= h(formatHours($payroll['overtime_minutes'] / 60)) ?></td>
                        <td><?= h(formatMinutesDuration((int)$payroll['late_minutes'])) ?></td>
                        <td><?= h(formatMinutesDuration((int)$payroll['undertime_minutes'])) ?></td>
                        <td><?= !empty($record['voided_at']) ? statusPill('voided') : statusPill($record['status']) . ((int)$payroll['late_minutes'] > 0 ? ' ' . latenessPill(true) : '') ?></td>
                        <td>
                            <?php if (empty($record['voided_at'])): ?>
                                <a href="edit_attendance.php?id=<?= (int)$record['id'] ?>">Edit</a>
                                <form method="post" action="delete_attendance.php" class="void-form" id="del-att-<?= (int)$record['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$record['id'] ?>">
                                    <input type="text" name="void_reason" maxlength="1000" required placeholder="Void reason">
                                    <button type="button" class="link-button danger-link"
                                        data-confirm-form="del-att-<?= (int)$record['id'] ?>"
                                        data-confirm-title="Void Record?"
                                        data-confirm-message="The record will be archived and retained with your reason.">Void</button>
                                </form>
                            <?php else: ?>
                                <span class="muted" title="<?= h($record['void_reason']) ?>">Archived</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$attendanceRecords): ?><tr><td colspan="13" class="table-empty-cell">No attendance records match the selected filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</article>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
