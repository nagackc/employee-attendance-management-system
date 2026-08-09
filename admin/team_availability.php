<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

$monthValue = trim((string)($_GET['month'] ?? date('Y-m')));
$monthDate = DateTimeImmutable::createFromFormat('!Y-m', $monthValue);
if ($monthDate === false || $monthDate->format('Y-m') !== $monthValue) {
    $monthDate = new DateTimeImmutable('first day of this month');
    $monthValue = $monthDate->format('Y-m');
}

$companyFilter = trim((string)($_GET['company'] ?? ''));
$minimumCoverage = filter_var($_GET['coverage'] ?? 70, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 100],
]);
$minimumCoverage = $minimumCoverage === false ? 70 : (int)$minimumCoverage;

$companies = $pdo->query('SELECT DISTINCT company FROM employees
    WHERE role = "employee" AND company <> "" ORDER BY company ASC')->fetchAll(PDO::FETCH_COLUMN);
if ($companyFilter !== '' && !in_array($companyFilter, $companies, true)) {
    $companyFilter = '';
}

$monthStart = $monthDate->format('Y-m-d');
$monthEnd = $monthDate->modify('last day of this month')->format('Y-m-d');
$gridStartDate = $monthDate->modify('-' . (int)$monthDate->format('w') . ' days');
$gridEndDate = $gridStartDate->modify('+41 days');
$gridStart = $gridStartDate->format('Y-m-d');
$gridEnd = $gridEndDate->format('Y-m-d');

$employeeSql = 'SELECT id, first_name, last_name, company, active, created_at, deactivated_at
    FROM employees
    WHERE role = "employee" AND (active = 1 OR deactivated_at IS NOT NULL) AND DATE(created_at) <= ?
      AND (deactivated_at IS NULL OR DATE(deactivated_at) >= ?)';
$employeeParams = [$gridEnd, $gridStart];
if ($companyFilter !== '') {
    $employeeSql .= ' AND company = ?';
    $employeeParams[] = $companyFilter;
}
$employeeSql .= ' ORDER BY company ASC, last_name ASC, first_name ASC, id ASC';
$employeeStmt = $pdo->prepare($employeeSql);
$employeeStmt->execute($employeeParams);
$employees = $employeeStmt->fetchAll();

$availability = getTeamAvailability($pdo, $employees, $gridStart, $gridEnd, $minimumCoverage);

$summary = [
    'scheduled_staff_days' => 0,
    'approved_full_days' => 0,
    'pending_full_days' => 0,
    'partial_hours' => 0.0,
    'warning_days' => 0,
    'lowest_coverage' => null,
];
foreach ($availability as $date => $day) {
    if ($date < $monthStart || $date > $monthEnd) {
        continue;
    }
    $summary['scheduled_staff_days'] += (int)$day['scheduled_count'];
    $summary['approved_full_days'] += (int)$day['approved_full_count'];
    $summary['pending_full_days'] += (int)$day['pending_full_count'];
    $summary['partial_hours'] += ((int)$day['approved_partial_minutes'] + (int)$day['pending_partial_minutes']) / 60;
    if (in_array($day['warning_level'], ['warning', 'critical'], true)) {
        $summary['warning_days']++;
    }
    if ((int)$day['scheduled_count'] > 0) {
        $coverage = (int)$day['projected_coverage'];
        $summary['lowest_coverage'] = $summary['lowest_coverage'] === null
            ? $coverage
            : min((int)$summary['lowest_coverage'], $coverage);
    }
}

$selectedDate = trim((string)($_GET['date'] ?? ''));
if (!isValidDateValue($selectedDate) || $selectedDate < $gridStart || $selectedDate > $gridEnd) {
    $today = date('Y-m-d');
    $selectedDate = $today >= $monthStart && $today <= $monthEnd ? $today : $monthStart;
}
$selectedDay = $availability[$selectedDate] ?? null;

$queryFor = static function (array $changes = []) use ($monthValue, $companyFilter, $minimumCoverage, $selectedDate): string {
    $query = [
        'month' => $monthValue,
        'coverage' => $minimumCoverage,
        'date' => $selectedDate,
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

$previousMonth = $monthDate->modify('-1 month')->format('Y-m');
$nextMonth = $monthDate->modify('+1 month')->format('Y-m');
$todayMonth = date('Y-m');
$statusLabels = [
    'available' => ['Available', 'pill-green'],
    'approved_leave' => ['Approved Leave', 'pill-purple'],
    'pending_leave' => ['Pending Leave', 'pill-yellow'],
    'approved_partial' => ['Partial Leave', 'pill-blue'],
    'pending_partial' => ['Pending Partial', 'pill-yellow'],
];

$companyName = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$pageTitle = 'Team Availability';
$activePage = '';
$activeSubPage = 'team_availability';
include __DIR__ . '/../includes/admin_layout_start.php';
?>

<section class="page-header team-availability-header">
    <div>
        <p class="employee-eyebrow">Leave planning</p>
        <h1>Team Availability</h1>
        <p>See approved and pending time off against scheduled staffing before leave requests are approved.</p>
    </div>
    <a class="btn btn-secondary" href="leave_management.php?<?= h(http_build_query(['start_date' => $monthStart, 'end_date' => $monthEnd])) ?>">Review Leave Requests</a>
</section>

<article class="content-card availability-filter-card">
    <form method="get" class="availability-filter-form">
        <div>
            <label for="availability-month">Month</label>
            <input id="availability-month" type="month" name="month" value="<?= h($monthValue) ?>" required>
        </div>
        <div>
            <label for="availability-company">Company</label>
            <select id="availability-company" name="company">
                <option value="">All companies</option>
                <?php foreach ($companies as $company): ?>
                    <option value="<?= h($company) ?>" <?= $companyFilter === $company ? 'selected' : '' ?>><?= h($company) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="availability-coverage">Minimum coverage</label>
            <div class="coverage-input-wrap">
                <input id="availability-coverage" type="number" name="coverage" min="1" max="100" value="<?= $minimumCoverage ?>" required>
                <span>%</span>
            </div>
        </div>
        <div class="availability-filter-actions">
            <button class="btn btn-primary" type="submit">Apply</button>
            <a class="btn btn-secondary" href="team_availability.php?month=<?= h($todayMonth) ?>">Today</a>
        </div>
    </form>
</article>

<section class="stats-grid availability-summary" aria-label="Monthly availability summary">
    <article class="stat-card stat-card--blue"><div class="stat-card-top"><span class="stat-icon" aria-hidden="true">👥</span></div><p class="stat-value"><?= count($employees) ?></p><p class="stat-label">Employees in scope</p><p class="stat-description"><?= $companyFilter !== '' ? h($companyFilter) : 'All companies' ?></p></article>
    <article class="stat-card stat-card--green"><div class="stat-card-top"><span class="stat-icon" aria-hidden="true">✓</span></div><p class="stat-value"><?= number_format($summary['scheduled_staff_days']) ?></p><p class="stat-label">Scheduled staff-days</p><p class="stat-description">Before approved leave</p></article>
    <article class="stat-card stat-card--purple"><div class="stat-card-top"><span class="stat-icon" aria-hidden="true">−</span></div><p class="stat-value"><?= number_format($summary['approved_full_days']) ?></p><p class="stat-label">Approved leave days</p><p class="stat-description">Removed from availability</p></article>
    <article class="stat-card stat-card--yellow"><div class="stat-card-top"><span class="stat-icon" aria-hidden="true">?</span></div><p class="stat-value"><?= number_format($summary['pending_full_days']) ?></p><p class="stat-label">Pending leave days</p><p class="stat-description"><?= number_format($summary['partial_hours'], 1) ?> partial hours</p></article>
    <article class="stat-card <?= $summary['warning_days'] > 0 ? 'stat-card--red' : 'stat-card--gray' ?>"><div class="stat-card-top"><span class="stat-icon" aria-hidden="true">!</span></div><p class="stat-value"><?= number_format($summary['warning_days']) ?></p><p class="stat-label">Coverage warning days</p><p class="stat-description">Lowest projection: <?= $summary['lowest_coverage'] === null ? '—' : (int)$summary['lowest_coverage'] . '%' ?></p></article>
</section>

<article class="content-card team-availability-calendar-card">
    <div class="availability-calendar-toolbar">
        <div class="leave-month-navigation">
            <a class="icon-btn" href="team_availability.php?<?= h($queryFor(['month' => $previousMonth, 'date' => null])) ?>" aria-label="Previous month">←</a>
            <a class="btn btn-secondary btn-sm" href="team_availability.php?<?= h($queryFor(['month' => $todayMonth, 'date' => date('Y-m-d')])) ?>">Today</a>
            <a class="icon-btn" href="team_availability.php?<?= h($queryFor(['month' => $nextMonth, 'date' => null])) ?>" aria-label="Next month">→</a>
        </div>
        <div class="availability-calendar-title">
            <p class="employee-eyebrow">Staffing calendar</p>
            <h2><?= h($monthDate->format('F Y')) ?></h2>
        </div>
        <div class="availability-calendar-legend" aria-label="Calendar legend">
            <span><i class="coverage-good"></i> Covered</span>
            <span><i class="coverage-warning"></i> Pending risk</span>
            <span><i class="coverage-critical"></i> Below target</span>
            <span><i class="coverage-holiday"></i> Holiday</span>
        </div>
    </div>

    <?php if (!$employees): ?>
        <div class="empty-state">No employees match this company and month.</div>
    <?php else: ?>
        <div class="availability-calendar-wrap">
            <div class="availability-weekdays" aria-hidden="true">
                <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
            </div>
            <div class="availability-calendar-grid">
                <?php foreach ($availability as $date => $day): ?>
                    <?php
                    $isOutside = substr($date, 0, 7) !== $monthValue;
                    $isSelected = $date === $selectedDate;
                    $dayLabel = (new DateTimeImmutable($date))->format('l, F j, Y');
                    $cellLabel = $day['holidays']
                        ? $dayLabel . ', company holiday'
                        : sprintf('%s, %d of %d available, %d%% projected coverage', $dayLabel, $day['projected_count'], $day['scheduled_count'], $day['projected_coverage']);
                    ?>
                    <a class="availability-day is-<?= h($day['warning_level']) ?><?= $isOutside ? ' is-outside' : '' ?><?= $isSelected ? ' is-selected' : '' ?>"
                       href="team_availability.php?<?= h($queryFor(['date' => $date])) ?>#availability-day-details"
                       aria-label="<?= h($cellLabel) ?>" <?= $isSelected ? 'aria-current="date"' : '' ?>>
                        <span class="availability-day-heading"><strong><?= (int)(new DateTimeImmutable($date))->format('j') ?></strong><?php if ($date === date('Y-m-d')): ?><small>Today</small><?php endif; ?></span>
                        <?php if ($day['holidays']): ?>
                            <span class="availability-holiday-name"><?= h($day['holidays'][0]['name']) ?></span>
                        <?php elseif ((int)$day['scheduled_count'] === 0): ?>
                            <span class="availability-rest-day">No scheduled staff</span>
                        <?php else: ?>
                            <span class="availability-headcount"><strong><?= (int)$day['projected_count'] ?></strong> / <?= (int)$day['scheduled_count'] ?> projected</span>
                            <span class="availability-coverage-bar" aria-hidden="true"><i style="width: <?= min(100, (int)$day['projected_coverage']) ?>%"></i></span>
                            <span class="availability-day-meta"><?= (int)$day['approved_full_count'] ?> approved · <?= (int)$day['pending_full_count'] ?> pending</span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</article>

<?php if ($selectedDay): ?>
<article class="content-card availability-detail-card" id="availability-day-details">
    <div class="card-header availability-detail-header">
        <div>
            <p class="employee-eyebrow">Daily detail</p>
            <h3><?= h(formatEmployeeDate($selectedDate)) ?></h3>
            <?php if ($selectedDay['holidays']): ?>
                <p><?= h(implode(', ', array_column($selectedDay['holidays'], 'name'))) ?> · No staffing coverage required.</p>
            <?php else: ?>
                <p><?= (int)$selectedDay['available_count'] ?> available now; <?= (int)$selectedDay['projected_count'] ?> if pending full-day requests are approved.</p>
            <?php endif; ?>
        </div>
        <div class="availability-detail-metrics">
            <span><strong><?= (int)$selectedDay['approved_coverage'] ?>%</strong> approved coverage</span>
            <span><strong><?= (int)$selectedDay['projected_coverage'] ?>%</strong> projected coverage</span>
        </div>
    </div>

    <?php if (!$selectedDay['staff']): ?>
        <div class="empty-state"><?= $selectedDay['holidays'] ? 'This is a company holiday.' : 'No employees are scheduled for this date.' ?></div>
    <?php else: ?>
        <div class="table-card availability-detail-table">
            <table>
                <thead><tr><th>Employee</th><th>Company</th><th>Shift</th><th>Status</th><th>Leave Amount</th><th>Leave Type</th></tr></thead>
                <tbody>
                    <?php foreach ($selectedDay['staff'] as $staff): ?>
                        <?php [$statusLabel, $statusClass] = $statusLabels[$staff['status']] ?? ['Available', 'pill-gray']; ?>
                        <tr>
                            <td><strong><?= h($staff['employee_name']) ?></strong></td>
                            <td><?= h($staff['company']) ?></td>
                            <td><?= h($staff['shift_name']) ?><span class="muted table-subtext"><?= h($staff['shift_time']) ?></span></td>
                            <td><span class="pill <?= h($statusClass) ?>"><?= h($statusLabel) ?></span></td>
                            <td><?php
                                $leaveMinutes = max((int)$staff['approved_minutes'], (int)$staff['pending_minutes']);
                                echo $leaveMinutes > 0 ? h(formatLeaveMinutes($leaveMinutes, 'hours') . ' hours') : '—';
                            ?></td>
                            <td><?= $staff['leave_types'] ? h(implode(', ', $staff['leave_types'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="availability-detail-actions">
            <a class="btn btn-secondary btn-sm" href="leave_management.php?<?= h(http_build_query(['company' => $companyFilter, 'start_date' => $selectedDate, 'end_date' => $selectedDate])) ?>">Review requests for this day</a>
        </div>
    <?php endif; ?>
</article>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
