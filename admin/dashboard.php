<?php

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);

applyTimezone($pdo);

$companyName   = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$schedule      = getAttendanceSchedule($pdo);
$todayDate     = date('Y-m-d');

$stmt = $pdo->query('SELECT COUNT(*) AS total FROM employees WHERE role = "employee" AND active = 1');
$totalEmployees = $stmt->fetch()['total'];

$stmt = $pdo->prepare('SELECT COUNT(DISTINCT a.employee_id) AS present_today FROM attendance a JOIN employees e ON e.id = a.employee_id
    WHERE e.active = 1 AND a.voided_at IS NULL AND (a.attendance_date = ? OR (a.time_out IS NULL AND a.status IN ("currently_working", "on_break", "on_quick_break")))');
$stmt->execute([$todayDate]);
$presentToday = $stmt->fetch()['present_today'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS working FROM attendance a JOIN employees e ON e.id = a.employee_id
    WHERE e.active = 1 AND a.voided_at IS NULL AND a.time_out IS NULL AND a.status IN ("currently_working", "on_break", "on_quick_break")');
$stmt->execute();
$working = $stmt->fetch()['working'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS timed_out FROM attendance a JOIN employees e ON e.id = a.employee_id
    WHERE e.active = 1 AND a.voided_at IS NULL AND a.time_out IS NOT NULL AND DATE(a.time_out) = ? AND a.status = "completed"');
$stmt->execute([$todayDate]);
$timedOut = $stmt->fetch()['timed_out'];

$stmt = $pdo->prepare('SELECT a.* FROM attendance a JOIN employees e ON e.id = a.employee_id
    WHERE e.active = 1 AND a.voided_at IS NULL AND a.attendance_date = ? AND a.time_in IS NOT NULL');
$stmt->execute([$todayDate]);
$lateEmployees = 0;
foreach ($stmt->fetchAll() as $attendanceRow) {
    if (attendanceIsLate($attendanceRow, $schedule)) {
        $lateEmployees++;
    }
}

$absent = 0;
$presentDateStmt = $pdo->prepare('SELECT DISTINCT a.employee_id FROM attendance a JOIN employees e ON e.id = a.employee_id
    WHERE e.active = 1 AND a.voided_at IS NULL
      AND (a.attendance_date = ? OR (a.time_out IS NULL AND a.status IN ("currently_working", "on_break", "on_quick_break")))');
$presentDateStmt->execute([$todayDate]);
$presentDateLookup = array_fill_keys(array_map('intval', $presentDateStmt->fetchAll(PDO::FETCH_COLUMN)), [$todayDate => true]);
$activeEmployeeStmt = $pdo->query('SELECT id, DATE(created_at) AS employment_start FROM employees WHERE role = "employee" AND active = 1');
foreach ($activeEmployeeStmt->fetchAll() as $activeEmployee) {
    $absence = calculateScheduledAbsences($pdo, (int)$activeEmployee['id'], $todayDate, $todayDate,
        (string)$activeEmployee['employment_start'], $presentDateLookup[(int)$activeEmployee['id']] ?? [], $todayDate);
    $absent += (int)$absence['absent'];
}

$stmt = $pdo->prepare('SELECT a.*, e.first_name, e.last_name, e.company FROM attendance a JOIN employees e ON e.id = a.employee_id
    WHERE e.active = 1 AND a.voided_at IS NULL AND (a.attendance_date = ? OR (a.time_out IS NULL AND a.status IN ("currently_working", "on_break", "on_quick_break"))) ORDER BY a.id DESC LIMIT 15');
$stmt->execute([$todayDate]);
$todayAttendance = $stmt->fetchAll();

$activeAnnouncementsStmt = $pdo->prepare('SELECT COUNT(*) FROM announcements
    WHERE status = "published"
      AND (publish_date IS NULL OR publish_date <= ?)
      AND (expiration_date IS NULL OR expiration_date >= ?)');
$activeAnnouncementsStmt->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
$activeAnnouncementsCount = (int)$activeAnnouncementsStmt->fetchColumn();

$latestAnnouncementsStmt = $pdo->prepare('SELECT id, title, priority, publish_date, pinned
    FROM announcements
    WHERE status = "published"
    ORDER BY pinned DESC, publish_date DESC, id DESC
    LIMIT 4');
$latestAnnouncementsStmt->execute();
$latestAnnouncements = $latestAnnouncementsStmt->fetchAll();

$pageTitle = 'Dashboard';
$activePage = 'dashboard';
$activeSubPage = '';
include __DIR__ . '/../includes/admin_layout_start.php';
?>
<section class="page-header dashboard-page-header">
    <div>
        <h1>Admin Dashboard</h1>
        <p><?= h($companyName) ?> </p>
    </div>
    <div class="header-actions">
        <a class="btn btn-primary" href="attendance.php">View Attendance</a>
    </div>
</section>

<section class="stats-grid">
    <article class="stat-card stat-card--blue" data-search-item>
        <div class="stat-card-top">
            <div class="stat-icon">👥</div>
            <span class="pill pill-blue">Active</span>
        </div>
        <div class="stat-value"><?= (int)$totalEmployees ?></div>
        <div class="stat-label">Total Employees</div>

    </article>
    <article class="stat-card stat-card--green" data-search-item>
        <div class="stat-card-top">
            <div class="stat-icon">✅</div>
            <span class="pill pill-green">On time</span>
        </div>
        <div class="stat-value"><?= (int)$presentToday ?></div>
        <div class="stat-label">Present Today</div>

    </article>
    <article class="stat-card stat-card--blue" data-search-item>
        <div class="stat-card-top">
            <div class="stat-icon">💼</div>
            <span class="pill pill-blue">Now</span>
        </div>
        <div class="stat-value"><?= (int)$working ?></div>
        <div class="stat-label">Working</div>

    </article>
    <article class="stat-card stat-card--yellow" data-search-item>
        <div class="stat-card-top">
            <div class="stat-icon">⏰</div>
            <span class="pill pill-yellow">Late</span>
        </div>
        <div class="stat-value"><?= (int)$lateEmployees ?></div>
        <div class="stat-label">Late</div>

    </article>
    <article class="stat-card stat-card--red" data-search-item>
        <div class="stat-card-top">
            <div class="stat-icon">❌</div>
            <span class="pill pill-red">Attention</span>
        </div>
        <div class="stat-value"><?= (int)$absent ?></div>
        <div class="stat-label">Absent</div>

    </article>
    <article class="stat-card stat-card--purple" data-search-item>
        <div class="stat-card-top">
            <div class="stat-icon">🕔</div>
            <span class="pill pill-purple">Completed</span>
        </div>
        <div class="stat-value"><?= (int)$timedOut ?></div>
        <div class="stat-label">Clocked Out</div>

    </article>
</section>

<section class="admin-grid">
    <div>
        <article class="content-card" data-search-item>
            <div class="card-header">
                <h3>Attendance Summary</h3>
                <a class="btn btn-secondary btn-sm" href="reports.php?type=date_range">View Reports</a>
            </div>
            <div class="summary-grid">
                <div class="summary-card"><strong>Present</strong><div class="summary-value"><?= (int)$presentToday ?></div></div>
                <div class="summary-card"><strong>Working</strong><div class="summary-value"><?= (int)$working ?></div></div>
                <div class="summary-card"><strong>Late</strong><div class="summary-value"><?= (int)$lateEmployees ?></div></div>
                <div class="summary-card"><strong>Absent</strong><div class="summary-value"><?= (int)$absent ?></div></div>
            </div>
        </article>
        <article class="content-card" data-search-item>
            <div class="card-header">
                <h3>Attendance for Today</h3>
                <span class="pill pill-green">Live view</span>
            </div>
            <div class="table-card" data-sticky-head="true" data-table-enhance="true" data-page-size="8">
                <table>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Shift</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Hours</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($todayAttendance): ?>
                            <?php foreach ($todayAttendance as $row): ?>
                                <tr>
                                    <td><?= h($row['first_name'] . ' ' . $row['last_name']) ?></td>
                                    <td><?= h($row['shift_name'] ?: 'Default Schedule') ?></td>
                                    <td><?= h(formatEmployeeDate((string)$row['attendance_date'])) ?></td>
                                    <td><?= statusPill($row['status']) ?> <?= latenessPill(attendanceIsLate($row, $schedule)) ?></td>
                                    <td><?= h(formatHours($row['total_hours'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="table-empty">No attendance records for today yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </div>

    <div>
        <article class="content-card" data-search-item>
            <div class="card-header">
                <h3>Announcements</h3>
                <a class="btn btn-secondary btn-sm" href="announcements.php">Manage</a>
            </div>
            <div class="summary-card" style="margin-bottom:10px;">
                <strong>Active Published</strong>
                <div class="summary-value"><?= $activeAnnouncementsCount ?></div>
            </div>
            <?php if ($latestAnnouncements): ?>
                <div class="mini-list">
                    <?php foreach ($latestAnnouncements as $ann): ?>
                        <div class="mini-list-item">
                            <div>
                                <strong><?= h($ann['title']) ?> <?php if ((int)$ann['pinned'] === 1): ?>📌<?php endif; ?></strong>
                                <span class="muted"><?= h($ann['publish_date'] ?: 'Scheduled') ?></span>
                            </div>
                            <?= priorityPill((string)$ann['priority']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">No announcements yet. Create one to notify employees.</div>
            <?php endif; ?>
        </article>
    </div>
</section>
<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
