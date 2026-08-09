<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Invalid request.');
    redirect('announcements.php');
}

$id = (int)($_POST['id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
if ($id <= 0 || !in_array($action, ['publish', 'archive', 'delete', 'pin', 'unpin'], true)) {
    setFlash('error', 'Invalid announcement action.');
    redirect('announcements.php');
}

try {
    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT * FROM announcements WHERE id = ? FOR UPDATE');
    $lock->execute([$id]);
    $oldValues = $lock->fetch();
    if (!$oldValues) {
        throw new RuntimeException('Announcement not found.');
    }

    if ($action === 'publish') {
        $publishDate = $oldValues['publish_date'] ?: date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE announcements SET status = "published", publish_date = ? WHERE id = ?')->execute([$publishDate, $id]);
    } elseif ($action === 'archive') {
        $pdo->prepare('UPDATE announcements SET status = "archived", pinned = 0 WHERE id = ?')->execute([$id]);
    } elseif ($action === 'pin') {
        $pdo->prepare('UPDATE announcements SET pinned = 1 WHERE id = ?')->execute([$id]);
    } elseif ($action === 'unpin') {
        $pdo->prepare('UPDATE announcements SET pinned = 0 WHERE id = ?')->execute([$id]);
    } else {
        $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
    }

    $newValues = null;
    if ($action !== 'delete') {
        $newStmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
        $newStmt->execute([$id]);
        $newValues = $newStmt->fetch() ?: null;
    }
    logAdminAudit(
        $pdo,
        (int)$_SESSION['user_id'],
        $action . '_announcement',
        null,
        ucfirst($action) . ' announcement #' . $id . '.',
        $oldValues,
        $newValues,
        'announcement',
        $id
    );
    $pdo->commit();
    $successMessages = [
        'publish' => 'Announcement published.',
        'archive' => 'Announcement archived.',
        'delete' => 'Announcement deleted.',
        'pin' => 'Announcement pinned.',
        'unpin' => 'Announcement unpinned.',
    ];
    setFlash('success', $successMessages[$action]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    setFlash('error', userFacingException($e, 'Announcement action could not be completed.'));
}

redirect('announcements.php');
