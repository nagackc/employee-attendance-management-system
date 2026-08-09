<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Only GET requests are supported.']);
    exit;
}

requireLogin($pdo);
applyTimezone($pdo);

$monthValue = trim((string)($_GET['month'] ?? ''));
$calendarMonth = DateTimeImmutable::createFromFormat('!Y-m', $monthValue);
if ($calendarMonth === false || $calendarMonth->format('Y-m') !== $monthValue) {
    http_response_code(400);
    echo json_encode(['error' => 'Month must use YYYY-MM format.']);
    exit;
}

$employeeId = (int)$_SESSION['user_id'];
$company = (string)($_SESSION['company'] ?? '');
$gridStart = $calendarMonth->modify('-' . (int)$calendarMonth->format('w') . ' days');
$gridEnd = $gridStart->modify('+41 days');
$gridStartValue = $gridStart->format('Y-m-d');
$gridEndValue = $gridEnd->format('Y-m-d');
$eventsByDate = [];

try {
    $leaveStmt = $pdo->prepare('SELECT lr.employee_id, lr.start_date, lr.end_date, lr.request_unit, lr.requested_minutes, lt.name AS leave_type_name,
            CONCAT(e.first_name, " ", e.last_name) AS employee_name
        FROM leave_requests lr
        INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
        INNER JOIN employees e ON e.id = lr.employee_id
        WHERE lr.status = "approved" AND e.company = ?
          AND lr.end_date >= ? AND lr.start_date <= ?
        ORDER BY lr.start_date ASC, lr.id ASC');
    $leaveStmt->execute([$company, $gridStartValue, $gridEndValue]);

    foreach ($leaveStmt->fetchAll() as $leave) {
        $eventStart = max($gridStartValue, (string)$leave['start_date']);
        $eventEnd = min($gridEndValue, (string)$leave['end_date']);
        if ($eventEnd < $eventStart) {
            continue;
        }
        $event = [
            'kind' => (int)$leave['employee_id'] === $employeeId ? 'self' : 'coworker',
            'employee_name' => (string)$leave['employee_name'],
            'leave_type' => (string)$leave['leave_type_name'],
            'start_date' => (string)$leave['start_date'],
            'end_date' => (string)$leave['end_date'],
            'request_unit' => (string)$leave['request_unit'],
            'duration_label' => formatLeaveMinutes((int)$leave['requested_minutes'], (string)$leave['request_unit']) . ' ' . (string)$leave['request_unit'],
        ];
        $period = new DatePeriod(
            new DateTimeImmutable($eventStart),
            new DateInterval('P1D'),
            (new DateTimeImmutable($eventEnd))->modify('+1 day')
        );
        foreach ($period as $date) {
            $eventsByDate[$date->format('Y-m-d')][] = $event;
        }
    }

    $holidayStmt = $pdo->prepare('SELECT name, holiday_date, holiday_type FROM holidays
        WHERE holiday_date >= ? AND holiday_date <= ? ORDER BY holiday_date ASC, id ASC');
    $holidayStmt->execute([$gridStartValue, $gridEndValue]);
    foreach ($holidayStmt->fetchAll() as $holiday) {
        $date = (string)$holiday['holiday_date'];
        $eventsByDate[$date][] = [
            'kind' => 'holiday',
            'employee_name' => 'Company Holiday',
            'leave_type' => (string)$holiday['name'] . ' (' . (string)$holiday['holiday_type'] . ')',
            'start_date' => $date,
            'end_date' => $date,
            'request_unit' => 'days',
            'duration_label' => 'Holiday',
        ];
    }

    ksort($eventsByDate);
    echo json_encode([
        'month' => $calendarMonth->format('Y-m'),
        'label' => $calendarMonth->format('F Y'),
        'grid_start' => $gridStartValue,
        'grid_end' => $gridEndValue,
        'events_by_date' => $eventsByDate,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Leave calendar API failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The leave calendar could not be loaded.']);
}
