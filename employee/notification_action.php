<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireLogin($pdo);
applyTimezone($pdo);

$wantsJson = str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

$respond = static function (bool $ok, string $message, int $status, array $data = []) use ($wantsJson): never {
    if ($wantsJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $data));
        exit;
    }
    setFlash($ok ? 'success' : 'error', $message);
    redirect('dashboard.php');
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(false, 'Only POST requests are supported.', 405);
}
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $respond(false, 'Invalid notification request.', 403);
}

$employeeId = (int)$_SESSION['user_id'];
$action = trim((string)($_POST['action'] ?? ''));

$unreadCount = static function () use ($pdo, $employeeId): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM employee_notifications WHERE employee_id = ? AND is_read = 0');
    $stmt->execute([$employeeId]);
    return (int)$stmt->fetchColumn();
};

if ($action === 'mark_all_read') {
    $stmt = $pdo->prepare('UPDATE employee_notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
        WHERE employee_id = ? AND is_read = 0');
    $stmt->execute([$employeeId]);
    $respond(true, 'Notifications marked as read.', 200, ['unread_count' => $unreadCount()]);
}

if ($action === 'mark_read') {
    $notificationId = (int)($_POST['notification_id'] ?? 0);
    if ($notificationId <= 0) {
        $respond(false, 'Invalid notification.', 422);
    }
    $ownedStmt = $pdo->prepare('SELECT id FROM employee_notifications WHERE id = ? AND employee_id = ? LIMIT 1');
    $ownedStmt->execute([$notificationId, $employeeId]);
    if (!$ownedStmt->fetch()) {
        $respond(false, 'Notification not found.', 404);
    }
    $stmt = $pdo->prepare('UPDATE employee_notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
        WHERE id = ? AND employee_id = ?');
    $stmt->execute([$notificationId, $employeeId]);
    $respond(true, 'Notification marked as read.', 200, [
        'notification_id' => $notificationId,
        'unread_count' => $unreadCount(),
    ]);
}

$respond(false, 'Unsupported notification action.', 422);
