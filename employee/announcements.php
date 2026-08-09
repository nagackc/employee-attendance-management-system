<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireLogin($pdo);
applyTimezone($pdo);

$employeeId = (int)$_SESSION['user_id'];
$company = (string)($_SESSION['company'] ?? '');
$now = date('Y-m-d H:i:s');

$stmt = $pdo->prepare('SELECT a.*, ar.read_at, ar.dismissed
    FROM announcements a
    LEFT JOIN announcement_reads ar ON ar.announcement_id = a.id AND ar.employee_id = ?
    WHERE a.status = "published"
      AND (a.publish_date IS NULL OR a.publish_date <= ?)
      AND (a.expiration_date IS NULL OR a.expiration_date >= ?)
      AND (a.target_audience = "all" OR a.target_audience = ?)
    ORDER BY a.pinned DESC, a.priority = "urgent" DESC, a.priority = "important" DESC, a.publish_date DESC, a.id DESC');
$stmt->execute([$employeeId, $now, $now, 'company:' . $company]);
$announcements = $stmt->fetchAll();

$pageTitle = 'Announcements';
$activePage = 'announcements';
include __DIR__ . '/../includes/employee_layout_start.php';
?>

<section class="employee-page-intro">
    <div>
        <p class="employee-eyebrow">Company updates</p>
        <h2>Announcements</h2>
        <p>Important notices and current information for your team.</p>
    </div>
</section>

<section class="dashboard-card">
    <?php if ($announcements): ?>
        <?php foreach ($announcements as $announcement): ?>
            <?php
            $isRead = !empty($announcement['read_at']);
            $cardClass = 'ann-card' . (!$isRead ? ' ann-unread' : '') . ((int)$announcement['pinned'] === 1 ? ' ann-pinned' : '');
            ?>
            <article class="<?= h($cardClass) ?>">
                <div class="ann-card-header">
                    <h3 class="ann-card-title"><?= h($announcement['title']) ?></h3>
                    <div class="ann-card-badges">
                        <?= priorityPill((string)$announcement['priority']) ?>
                        <?php if ((int)$announcement['pinned'] === 1): ?><span class="ann-pin-label">Pinned</span><?php endif; ?>
                        <span class="ann-read-badge<?= $isRead ? '' : ' is-unread' ?>"><?= $isRead ? 'Read' : 'Unread' ?></span>
                    </div>
                </div>
                <div class="ann-card-content ann-card-content-full"><?= h($announcement['content']) ?></div>
                <div class="ann-card-meta">
                    <span>Published: <?= h(formatEmployeeDate(substr((string)($announcement['publish_date'] ?: $announcement['created_at']), 0, 10))) ?></span>
                    <span>Expires: <?= $announcement['expiration_date'] ? h(formatEmployeeDate(substr((string)$announcement['expiration_date'], 0, 10))) : 'No expiry' ?></span>
                </div>
            </article>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">No active announcements at this time.</div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/employee_layout_end.php'; ?>
