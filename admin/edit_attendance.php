<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    redirect('attendance.php');
}

$stmt = $pdo->prepare('SELECT a.*, e.first_name, e.last_name FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE a.id = ?');
$stmt->execute([$id]);
$record = $stmt->fetch();
if (!$record || !empty($record['voided_at'])) {
    setFlash('error', 'The attendance record was not found or has been voided.');
    redirect('attendance.php');
}

$message = '';
$statuses = ['currently_working', 'on_break', 'on_quick_break', 'completed'];
$schedule = getAttendanceSchedule($pdo);
$recordTimezone = (string)($record['schedule_timezone'] ?: $schedule['timezone']);
if (!in_array($recordTimezone, DateTimeZone::listIdentifiers(), true)) {
    $recordTimezone = $schedule['timezone'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid session token. Please try again.';
    } else {
        $attendanceDate = trim((string)($_POST['attendance_date'] ?? ''));
        $timeInRaw = trim((string)($_POST['time_in'] ?? ''));
        $timeOutRaw = trim((string)($_POST['time_out'] ?? ''));
        $breakStartRaw = trim((string)($_POST['break_start'] ?? ''));
        $breakEndRaw = trim((string)($_POST['break_end'] ?? ''));
        $fallbackBreakMinutes = max(0, (int)($_POST['break_minutes'] ?? 0));
        $status = trim((string)($_POST['status'] ?? ''));
        $editReason = trim((string)($_POST['edit_reason'] ?? ''));

        $timeIn = parseDateTimeLocal($timeInRaw, $recordTimezone);
        $timeOut = $timeOutRaw === '' ? null : parseDateTimeLocal($timeOutRaw, $recordTimezone);
        $breakStart = $breakStartRaw === '' ? null : parseDateTimeLocal($breakStartRaw, $recordTimezone);
        $breakEnd = $breakEndRaw === '' ? null : parseDateTimeLocal($breakEndRaw, $recordTimezone);

        if (!in_array($status, $statuses, true)) {
            $message = 'Select a valid operational status.';
        } elseif ($editReason === '' || mb_strlen($editReason) > 1000) {
            $message = 'An edit reason of up to 1000 characters is required.';
        } elseif (($timeOutRaw !== '' && $timeOut === null) || ($breakStartRaw !== '' && $breakStart === null) || ($breakEndRaw !== '' && $breakEnd === null)) {
            $message = 'One or more attendance date/time values are invalid.';
        } else {
            $calculation = validateAttendanceTimeline(
                $attendanceDate,
                $timeIn,
                $timeOut,
                $breakStart,
                $breakEnd,
                $status,
                $fallbackBreakMinutes
            );
            if ($calculation['errors']) {
                $message = implode(' ', $calculation['errors']);
            } else {
                try {
                    $pdo->beginTransaction();
                    $lock = $pdo->prepare('SELECT * FROM attendance WHERE id = ? AND voided_at IS NULL FOR UPDATE');
                    $lock->execute([$id]);
                    $oldValues = $lock->fetch();
                    if (!$oldValues) {
                        throw new RuntimeException('The attendance record is no longer available.');
                    }

                    $update = $pdo->prepare('UPDATE attendance SET attendance_date = ?, time_in = ?, time_out = ?,
                        break_start = ?, break_end = ?, break_minutes = ?, total_hours = ?, status = ?
                        WHERE id = ? AND voided_at IS NULL');
                    $update->execute([
                        $attendanceDate,
                        $timeIn?->format('Y-m-d H:i:s'),
                        $timeOut?->format('Y-m-d H:i:s'),
                        $breakStart?->format('Y-m-d H:i:s'),
                        $breakEnd?->format('Y-m-d H:i:s'),
                        $calculation['break_minutes'],
                        $calculation['total_hours'],
                        $status,
                        $id,
                    ]);

                    $newStmt = $pdo->prepare('SELECT * FROM attendance WHERE id = ?');
                    $newStmt->execute([$id]);
                    $newValues = $newStmt->fetch();
                    logAdminAudit(
                        $pdo,
                        (int)$_SESSION['user_id'],
                        'correct_attendance',
                        (int)$oldValues['employee_id'],
                        $editReason,
                        $oldValues,
                        $newValues ?: null,
                        'attendance',
                        $id
                    );
                    $pdo->commit();
                    setFlash('success', 'Attendance corrected and audit logged.');
                    redirect('attendance.php');
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ((string)$e->getCode() === '23000') {
                        $message = 'This employee already has an attendance record for that date.';
                    } else {
                        error_log('Attendance correction failed: ' . $e->getMessage());
                        $message = 'Attendance could not be corrected.';
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $message = userFacingException($e, 'Attendance could not be corrected.');
                }
            }
        }
    }
}

$employeeName = h($record['first_name'] . ' ' . $record['last_name']);
$companyName = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$pageTitle = 'Edit Attendance';
$activeSubPage = 'today_attendance';
include __DIR__ . '/../includes/admin_layout_start.php';
?>
<section class="page-header">
    <h1>Edit Attendance</h1>
    <p><?= $employeeName ?> · <?= h($record['attendance_date']) ?></p>
</section>

<article class="content-card">
    <div class="card-header"><h3>Attendance Details</h3><a href="attendance.php" class="btn btn-secondary">Back</a></div>
    <?php if ($message): ?><div class="message"><?= h($message) ?></div><?php endif; ?>
    <form method="post" id="edit-att-form" class="form-layout">
        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">

        <div>
            <label class="required">Attendance Date</label>
            <input type="date" name="attendance_date" value="<?= h($_POST['attendance_date'] ?? $record['attendance_date']) ?>" required>
        </div>
        <div class="form-grid-2">
            <div><label class="required">Time In</label><input type="datetime-local" name="time_in" value="<?= h($_POST['time_in'] ?? str_replace(' ', 'T', substr((string)$record['time_in'], 0, 16))) ?>" required></div>
            <div><label>Time Out</label><input type="datetime-local" name="time_out" value="<?= h($_POST['time_out'] ?? str_replace(' ', 'T', substr((string)($record['time_out'] ?? ''), 0, 16))) ?>"></div>
        </div>
        <div class="form-grid-2">
            <div><label>Lunch Out</label><input type="datetime-local" name="break_start" value="<?= h($_POST['break_start'] ?? str_replace(' ', 'T', substr((string)($record['break_start'] ?? ''), 0, 16))) ?>"></div>
            <div><label>Lunch In</label><input type="datetime-local" name="break_end" value="<?= h($_POST['break_end'] ?? str_replace(' ', 'T', substr((string)($record['break_end'] ?? ''), 0, 16))) ?>"></div>
        </div>
        <div><label>Lunch Break (minutes)</label><input type="number" name="break_minutes" min="0" value="<?= (int)($_POST['break_minutes'] ?? $record['break_minutes'] ?? 0) ?>"><p class="muted">When both lunch times are supplied, minutes are recalculated from those values.</p></div>
        <div>
            <label class="required">Status</label>
            <select name="status" required>
                <?php foreach ($statuses as $statusOption): ?>
                    <option value="<?= h($statusOption) ?>" <?= (($_POST['status'] ?? $record['status']) === $statusOption) ? 'selected' : '' ?>><?= h(ucfirst(str_replace('_', ' ', $statusOption))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label class="required">Edit Reason</label><textarea name="edit_reason" maxlength="1000" required placeholder="Explain why this correction is needed."><?= h($_POST['edit_reason'] ?? '') ?></textarea></div>
        <div><button type="button" data-confirm-form="edit-att-form" data-confirm-title="Save Correction?" data-confirm-message="This correction and its reason will be written to the audit log.">Save Changes</button></div>
    </form>
</article>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
