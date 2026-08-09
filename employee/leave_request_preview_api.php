<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Only GET requests are supported.']);
    exit;
}

requireLogin($pdo);
applyTimezone($pdo);

$employeeId = (int)$_SESSION['user_id'];
$leaveTypeId = (int)($_GET['leave_type_id'] ?? 0);
$requestUnit = trim((string)($_GET['request_unit'] ?? 'days'));
$startDate = $requestUnit === 'hours' ? trim((string)($_GET['hour_date'] ?? '')) : trim((string)($_GET['start_date'] ?? ''));
$endDate = $requestUnit === 'hours' ? $startDate : trim((string)($_GET['end_date'] ?? ''));
$hoursRequested = trim((string)($_GET['hours_requested'] ?? ''));

$fail = static function (string $message): never {
    http_response_code(422);
    echo json_encode(['error' => $message]);
    exit;
};

if ($leaveTypeId <= 0 || !in_array($requestUnit, ['days', 'hours'], true)) {
    $fail('Choose a leave type and request type.');
}
$typeStmt = $pdo->prepare('SELECT name FROM leave_types WHERE id = ? AND active = 1 LIMIT 1');
$typeStmt->execute([$leaveTypeId]);
$leaveTypeName = $typeStmt->fetchColumn();
if ($leaveTypeName === false) {
    $fail('The selected leave type is not available.');
}
if (!isValidDateValue($startDate) || !isValidDateValue($endDate) || $endDate < $startDate) {
    $fail('Choose valid leave dates.');
}

try {
    $charges = calculateLeaveRequestCharges($pdo, $requestUnit, $startDate, $endDate, $hoursRequested, $employeeId);
} catch (RuntimeException $e) {
    $fail($e->getMessage());
}

$minutesByYear = [];
foreach ($charges as $date => $minutes) {
    $year = (int)substr($date, 0, 4);
    $minutesByYear[$year] = ($minutesByYear[$year] ?? 0) + $minutes;
}

$periods = [];
foreach ($minutesByYear as $year => $minutes) {
    $balance = getEmployeeLeaveBalance($pdo, $employeeId, $leaveTypeId, $year);
    $periods[] = [
        'year' => $year,
        'request_minutes' => $minutes,
        'available_minutes' => (int)$balance['available_minutes'],
        'existing_pending_minutes' => (int)$balance['pending_minutes'],
        'projected_minutes' => (int)$balance['projected_minutes'] - $minutes,
    ];
}

echo json_encode([
    'ok' => true,
    'leave_type' => (string)$leaveTypeName,
    'request_unit' => $requestUnit,
    'charge_count' => count($charges),
    'total_minutes' => array_sum($charges),
    'periods' => $periods,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
