<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireLogin($pdo);
applyTimezone($pdo);

$employeeId = (int)$_SESSION['user_id'];
$employeeName = (string)$_SESSION['user_name'];
$attendanceContext = getEmployeeAttendanceContext($pdo, $employeeId);
$today = $attendanceContext['attendance_date'];
$schedule = $attendanceContext['schedule'];

$stmt = $pdo->prepare('SELECT * FROM attendance
    WHERE employee_id = ? AND voided_at IS NULL AND time_out IS NULL
      AND status IN ("currently_working", "on_break", "on_quick_break")
    ORDER BY id DESC LIMIT 1');
$stmt->execute([$employeeId]);
$attendance = $stmt->fetch();

if (!$attendance) {
    $stmt = $pdo->prepare('SELECT * FROM attendance
        WHERE employee_id = ? AND attendance_date = ? AND voided_at IS NULL
        ORDER BY id DESC LIMIT 1');
    $stmt->execute([$employeeId, $today]);
    $attendance = $stmt->fetch();
}

$defaultAttendance = array_replace(attendanceDefault($today), [
    'schedule_timezone' => $schedule['timezone'],
    'shift_id' => $schedule['shift_id'],
    'shift_name' => $schedule['shift_name'],
    'scheduled_start_time' => $schedule['work_start_time'] . ':00',
    'scheduled_end_time' => $schedule['work_end_time'] . ':00',
    'grace_period_minutes' => $schedule['grace_period_minutes'],
    'scheduled_workday' => $schedule['scheduled_workday'],
]);
$attendance = $attendance
    ? array_replace(attendanceDefault((string)$attendance['attendance_date']), $attendance)
    : $defaultAttendance;
$attendanceLate = attendanceIsLate($attendance, $schedule);
$attendanceTimezone = (string)($attendance['schedule_timezone'] ?: $schedule['timezone']);
if (!in_array($attendanceTimezone, DateTimeZone::listIdentifiers(), true)) {
    $attendanceTimezone = $schedule['timezone'];
}

$toAtom = static function (?string $value) use ($attendanceTimezone): string {
    $parsed = parseDatabaseDateTime($value, $attendanceTimezone);
    return $parsed?->format(DATE_ATOM) ?? '';
};
$timeInSource = $toAtom($attendance['time_in'] ?: null);
$lunchStartSource = $toAtom($attendance['break_start'] ?: null);

$quickBreakSeconds = 0;
$quickBreakCount = 0;
$quickBreakStartSource = '';
if ((int)$attendance['id'] > 0) {
    $quickStmt = $pdo->prepare('SELECT id, started_at, ended_at, duration_seconds
        FROM attendance_quick_breaks WHERE attendance_id = ? ORDER BY id ASC');
    $quickStmt->execute([(int)$attendance['id']]);
    foreach ($quickStmt->fetchAll() as $quickBreak) {
        $quickBreakCount++;
        if ($quickBreak['ended_at'] === null) {
            $quickBreakStartSource = $toAtom((string)$quickBreak['started_at']);
        } else {
            $quickBreakSeconds += max(0, (int)$quickBreak['duration_seconds']);
        }
    }
}

$now = date('Y-m-d H:i:s');
$rawCompany = (string)($_SESSION['company'] ?? '');
$annStmt = $pdo->prepare('SELECT a.*, ar.read_at, ar.dismissed
    FROM announcements a
    LEFT JOIN announcement_reads ar ON ar.announcement_id = a.id AND ar.employee_id = ?
    WHERE a.status = "published"
      AND (a.publish_date IS NULL OR a.publish_date <= ?)
      AND (a.expiration_date IS NULL OR a.expiration_date >= ?)
      AND (a.target_audience = "all" OR a.target_audience = ?)
      AND ar.id IS NULL
    ORDER BY a.pinned DESC, a.priority = "urgent" DESC, a.priority = "important" DESC, a.publish_date DESC, a.id DESC');
$annStmt->execute([$employeeId, $now, $now, 'company:' . $rawCompany]);
$pendingAnnouncements = [];
foreach ($annStmt->fetchAll() as $annRow) {
    $pendingAnnouncements[] = [
        'id' => (int)$annRow['id'],
        'title' => (string)$annRow['title'],
        'content' => (string)$annRow['content'],
        'priority' => (string)$annRow['priority'],
        'pinned' => (int)$annRow['pinned'],
    ];
}

$dashboardAlerts = array_slice(
    getEmployeeDashboardAlerts($pdo, $employeeId, $attendance, $schedule, $attendanceContext['now']),
    0,
    6
);

$pageTitle = 'Dashboard';
$activePage = 'dashboard';
include __DIR__ . '/../includes/employee_layout_start.php';
?>

<section class="employee-page-intro">
    <div>
        <p class="employee-eyebrow">Today’s attendance</p>
        <h2>Hello, <?= h($employeeName) ?>.</h2>
        <p>
            <?php if ($attendance['status'] === 'not_started' && (int)$attendance['scheduled_workday'] === 0): ?>Today is a scheduled rest day. You can still clock in if you are working.
            <?php elseif ($attendance['status'] === 'not_started'): ?>Ready to start your workday?
            <?php elseif ($attendance['status'] === 'on_break'): ?>Your lunch timer is running. End lunch when you return.
            <?php elseif ($attendance['status'] === 'on_quick_break'): ?>Your paid quick break is being tracked.
            <?php elseif ($attendance['status'] === 'completed'): ?>Your attendance for today is complete.
            <?php else: ?>Your work session is active and tracking in real time.
            <?php endif; ?>
        </p>
    </div>
    <div class="employee-status-pill">
        <?= statusPill((string)$attendance['status']) ?>
        <?php if ($attendance['status'] !== 'not_started'): ?><?= latenessPill($attendanceLate) ?><?php endif; ?>
    </div>
</section>

<?php if ($dashboardAlerts): ?>
<section class="dashboard-card dashboard-action-center" id="dashboard-action-center" aria-labelledby="dashboard-action-center-title">
    <div class="dashboard-action-center-header">
        <div>
            <p class="employee-eyebrow">Needs your attention</p>
            <h3 id="dashboard-action-center-title">Action Center</h3>
        </div>
        <span class="dashboard-alert-count" data-dashboard-alert-count><?= count($dashboardAlerts) ?> active</span>
    </div>
    <div class="dashboard-alert-list">
        <?php foreach ($dashboardAlerts as $dashboardAlert): ?>
            <article class="dashboard-alert dashboard-alert-<?= h((string)$dashboardAlert['severity']) ?>"
                     data-dashboard-alert data-dashboard-alert-id="<?= h((string)$dashboardAlert['id']) ?>">
                <div class="dashboard-alert-icon" aria-hidden="true">
                    <?php if ($dashboardAlert['severity'] === 'danger'): ?>
                        <svg viewBox="0 0 24 24"><path d="M12 3 2.5 20h19L12 3Z"/><path d="M12 9v5M12 17h.01"/></svg>
                    <?php elseif ($dashboardAlert['severity'] === 'warning'): ?>
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 17h.01"/></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/></svg>
                    <?php endif; ?>
                </div>
                <div class="dashboard-alert-content">
                    <strong><?= h((string)$dashboardAlert['title']) ?></strong>
                    <p><?= h((string)$dashboardAlert['message']) ?></p>
                    <a href="<?= h((string)$dashboardAlert['href']) ?>"><?= h((string)$dashboardAlert['actionLabel']) ?> <span aria-hidden="true">→</span></a>
                </div>
                <?php if (!empty($dashboardAlert['dismissible'])): ?>
                    <button type="button" class="dashboard-alert-dismiss" data-dashboard-alert-dismiss aria-label="Dismiss <?= h((string)$dashboardAlert['title']) ?>">×</button>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="dashboard-card employee-main-card" id="attendance-panel" tabindex="-1">
    <div class="attendance-card-header">
        <div>
            <span class="employee-eyebrow">Attendance date</span>
            <h3><?= h(formatEmployeeDate((string)$attendance['attendance_date'])) ?></h3>
        </div>
        <span class="attendance-schedule"><strong><?= h($attendance['shift_name'] ?: 'Default Schedule') ?></strong> · <?= h(substr((string)$attendance['scheduled_start_time'], 0, 5)) ?>–<?= h(substr((string)$attendance['scheduled_end_time'], 0, 5)) ?> · <?= h($attendanceTimezone) ?><?= (int)$attendance['scheduled_workday'] === 0 ? ' · Rest day' : '' ?></span>
    </div>

    <div class="employee-hero-panel">
        <div class="clock-panel">
            <div class="clock-label">Local time</div>
            <div id="live-clock" class="clock" data-timezone="<?= h($attendanceTimezone) ?>"></div>
            <div class="clock-helper"><?= (int)$attendance['grace_period_minutes'] ?> minute grace period</div>
        </div>

        <div class="employee-hero-actions">
            <?php if ($attendance['status'] === 'not_started'): ?>
                <div class="attendance-empty-action">
                    <h3><?= (int)$attendance['scheduled_workday'] === 0 ? 'Working on a rest day?' : 'Ready to begin?' ?></h3>
                    <p><?= (int)$attendance['scheduled_workday'] === 0 ? 'Clock in if you have authorized rest-day work.' : 'Clock in to start tracking today’s hours and breaks.' ?></p>
                    <form method="post" action="time_action.php">
                        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                        <input type="hidden" name="action" value="time_in">
                        <button class="big-btn success" type="submit">Time In</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="attendance-metrics">
                    <div class="attendance-metric">
                        <span>Time In</span>
                        <strong><?= h(formatEmployeeTime($attendance['time_in'], $attendanceTimezone)) ?></strong>
                    </div>
                    <div class="attendance-metric">
                        <span>Worked</span>
                        <strong id="elapsed-time"><?= $attendance['status'] === 'completed' ? h(formatHours($attendance['total_hours'])) . ' hrs' : '00:00:00' ?></strong>
                    </div>
                    <div class="attendance-metric<?= $attendance['status'] === 'on_break' ? ' is-active' : '' ?>" id="lunch-timer-card">
                        <span>Lunch Timer</span>
                        <strong id="lunch-timer">
                            <?php if ($attendance['status'] === 'on_break'): ?>60:00
                            <?php elseif (!empty($attendance['break_end'])): ?><?= (int)$attendance['break_minutes'] ?> min
                            <?php else: ?>60:00<?php endif; ?>
                        </strong>
                        <small id="lunch-timer-label"><?= !empty($attendance['break_end']) ? 'Completed' : ($attendance['status'] === 'on_break' ? 'Remaining' : 'Available') ?></small>
                    </div>
                    <div class="attendance-metric<?= $attendance['status'] === 'on_quick_break' ? ' is-active' : '' ?>">
                        <span>Quick Breaks</span>
                        <strong id="quick-break-total"><?= h(formatDurationSeconds($quickBreakSeconds)) ?></strong>
                        <small><span id="quick-break-count"><?= $quickBreakCount ?></span> recorded</small>
                    </div>
                </div>

                <?php if (in_array($attendance['status'], ['currently_working', 'on_break', 'on_quick_break'], true)): ?>
                    <input
                        type="hidden"
                        id="elapsed-tracker"
                        data-start-time="<?= h($timeInSource) ?>"
                        data-break-seconds="<?= ((int)$attendance['break_minutes']) * 60 ?>"
                        data-break-start="<?= h($lunchStartSource) ?>"
                        data-on-break="<?= $attendance['status'] === 'on_break' ? '1' : '0' ?>"
                        data-lunch-completed="<?= !empty($attendance['break_end']) ? '1' : '0' ?>"
                        data-quick-break-seconds="<?= $quickBreakSeconds ?>"
                        data-quick-break-start="<?= h($quickBreakStartSource) ?>"
                        data-on-quick-break="<?= $attendance['status'] === 'on_quick_break' ? '1' : '0' ?>"
                    >

                    <div class="attendance-actions">
                        <?php if ($attendance['status'] === 'on_break'): ?>
                            <form method="post" action="time_action.php">
                                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                <input type="hidden" name="action" value="lunch_in">
                                <button class="big-btn primary" type="submit">End Lunch</button>
                            </form>
                        <?php elseif ($attendance['status'] === 'on_quick_break'): ?>
                            <form method="post" action="time_action.php">
                                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                <input type="hidden" name="action" value="quick_break_end">
                                <button class="big-btn primary" type="submit">End Quick Break</button>
                            </form>
                        <?php else: ?>
                            <?php if (empty($attendance['break_end'])): ?>
                                <form method="post" action="time_action.php">
                                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="lunch_out">
                                    <button class="big-btn warning" type="submit">Lunch Break</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="time_action.php">
                                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                <input type="hidden" name="action" value="quick_break_start">
                                <button class="big-btn secondary-action" type="submit">Quick Break</button>
                            </form>
                        <?php endif; ?>

                        <form method="post" action="time_action.php">
                            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                            <input type="hidden" name="action" value="time_out">
                            <button class="big-btn danger" type="submit">Time Out</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="attendance-complete-note">
                        <span>Time Out <strong><?= h(formatEmployeeTime($attendance['time_out'], $attendanceTimezone)) ?></strong></span>
                        <span>Total Hours <strong><?= h(formatHours($attendance['total_hours'])) ?></strong></span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<div id="ann-modal-overlay" class="ann-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ann-modal-title">
    <div class="ann-modal-box">
        <div class="ann-modal-eyebrow" id="ann-modal-eyebrow"></div>
        <h3 id="ann-modal-title" class="ann-modal-title"></h3>
        <div id="ann-modal-content" class="ann-modal-content"></div>
        <div class="ann-modal-footer">
            <span id="ann-counter" class="ann-counter"></span>
            <button type="button" id="ann-ack-btn" class="btn btn-primary">Acknowledge</button>
        </div>
    </div>
</div>

<script>
    window.__announcements = <?= json_encode($pendingAnnouncements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.__annReadUrl = 'announcement_read.php';
    window.__annCsrf = '<?= h(generateCsrfToken()) ?>';
</script>

<?php include __DIR__ . '/../includes/employee_layout_end.php'; ?>
