<?php
require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireLogin($pdo);
applyTimezone($pdo);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid request']);
    exit;
}

$employeeId = (int)$_SESSION['user_id'];
$announcementId = (int)($_POST['announcement_id'] ?? 0);
$dismissed = (int)(($_POST['dismissed'] ?? '0') === '1');

if ($announcementId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Invalid announcement']);
    exit;
}

$now = date('Y-m-d H:i:s');
$companyTarget = 'company:' . (string)($_SESSION['company'] ?? '');
$stmt = $pdo->prepare('SELECT id, allow_dismiss FROM announcements
    WHERE id = ? AND status = "published"
      AND (publish_date IS NULL OR publish_date <= ?)
      AND (expiration_date IS NULL OR expiration_date >= ?)
      AND (target_audience = "all" OR target_audience = ?)
    LIMIT 1');
$stmt->execute([$announcementId, $now, $now, $companyTarget]);
$announcement = $stmt->fetch();
if (!$announcement) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Announcement not found']);
    exit;
}

if ((int)$announcement['allow_dismiss'] !== 1) {
    $dismissed = 0;
}

$stmt = $pdo->prepare('INSERT INTO announcement_reads (announcement_id, employee_id, dismissed)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP, dismissed = VALUES(dismissed)');
$stmt->execute([$announcementId, $employeeId, $dismissed]);

echo json_encode(['ok' => true]);
