<?php
require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

$statusFilter   = in_array($_GET['status']   ?? '', ['draft', 'published', 'archived'], true) ? $_GET['status']   : '';
$priorityFilter = in_array($_GET['priority'] ?? '', ['normal', 'important', 'urgent'],  true) ? $_GET['priority'] : '';

$query  = 'SELECT a.*, e.first_name, e.last_name FROM announcements a JOIN employees e ON e.id = a.created_by WHERE 1=1';
$params = [];

if ($statusFilter !== '') {
    $query  .= ' AND a.status = ?';
    $params[] = $statusFilter;
}
if ($priorityFilter !== '') {
    $query  .= ' AND a.priority = ?';
    $params[] = $priorityFilter;
}
$query .= ' ORDER BY a.pinned DESC, a.created_at DESC';

$stmt         = $pdo->prepare($query);
$stmt->execute($params);
$announcements = $stmt->fetchAll();

$companyName  = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$pageTitle    = 'Announcements';
$activePage   = 'announcements';
include __DIR__ . '/../includes/admin_layout_start.php';
?>
<section class="page-header">
    <div>
        <h1>Announcements</h1>
        <p>Create and manage announcements for employees.</p>
    </div>
    <div class="header-actions">
        <a href="manage_announcement.php" class="btn btn-primary">➕ New Announcement</a>
    </div>
</section>

<article class="content-card" data-search-item>
    <form method="get" class="ann-filter-bar">
        <div>
            <label for="status-filter">Status</label>
            <select id="status-filter" name="status">
                <option value="">All Statuses</option>
                <option value="draft"     <?= $statusFilter === 'draft'     ? 'selected' : '' ?>>Draft</option>
                <option value="published" <?= $statusFilter === 'published' ? 'selected' : '' ?>>Published</option>
                <option value="archived"  <?= $statusFilter === 'archived'  ? 'selected' : '' ?>>Archived</option>
            </select>
        </div>
        <div>
            <label for="priority-filter">Priority</label>
            <select id="priority-filter" name="priority">
                <option value="">All Priorities</option>
                <option value="normal"    <?= $priorityFilter === 'normal'    ? 'selected' : '' ?>>Normal</option>
                <option value="important" <?= $priorityFilter === 'important' ? 'selected' : '' ?>>Important</option>
                <option value="urgent"    <?= $priorityFilter === 'urgent'    ? 'selected' : '' ?>>Urgent</option>
            </select>
        </div>
        <?php if ($statusFilter !== '' || $priorityFilter !== ''): ?>
            <div style="display:flex;align-items:flex-end;gap:6px;">
                <button type="submit" class="btn btn-secondary">Apply</button>
                <a href="announcements.php" class="btn btn-secondary">Clear</a>
            </div>
        <?php else: ?>
            <div style="display:flex;align-items:flex-end;">
                <button type="submit" class="btn btn-secondary">Apply</button>
            </div>
        <?php endif; ?>
    </form>

    <div class="table-toolbar">
        <input class="table-search" type="search" placeholder="Search announcements…" data-table-search>
    </div>

    <div class="table-card" data-sticky-head="true" data-table-enhance="true" data-page-size="10">
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Audience</th>
                    <th>Created By</th>
                    <th>Publish Date</th>
                    <th>Expires</th>
                    <th data-sort="false">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($announcements): ?>
                    <?php foreach ($announcements as $ann): ?>
                        <tr>
                            <td>
                                <?php if ($ann['pinned']): ?><span title="Pinned">📌 </span><?php endif; ?>
                                <?= h($ann['title']) ?>
                                <div style="font-size:0.78rem;color:#6b7280;margin-top:2px;">
                                    <?= h(strlen((string)$ann['content']) > 60 ? substr((string)$ann['content'], 0, 60) . '...' : (string)$ann['content']) ?>
                                </div>
                            </td>
                            <td><?= priorityPill($ann['priority']) ?></td>
                            <td><?= announcementStatusPill($ann['status']) ?></td>
                            <td><?= h($ann['target_audience'] === 'all' ? 'All Employees' : $ann['target_audience']) ?></td>
                            <td><?= h($ann['first_name'] . ' ' . $ann['last_name']) ?></td>
                            <td><?= $ann['publish_date'] ? h($ann['publish_date']) : '<span class="muted">—</span>' ?></td>
                            <td><?= $ann['expiration_date'] ? h($ann['expiration_date']) : '<span class="muted">No expiry</span>' ?></td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                                    <a href="manage_announcement.php?id=<?= (int)$ann['id'] ?>" class="btn btn-secondary btn-sm">View</a>
                                    <a href="manage_announcement.php?id=<?= (int)$ann['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>

                                    <?php if ($ann['status'] === 'draft'): ?>
                                        <form method="post" action="announcement_action.php" class="inline-form" id="pub-ann-<?= (int)$ann['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                            <input type="hidden" name="id"     value="<?= (int)$ann['id'] ?>">
                                            <input type="hidden" name="action" value="publish">
                                            <button type="button" class="btn btn-success btn-sm"
                                                data-confirm-form="pub-ann-<?= (int)$ann['id'] ?>"
                                                data-confirm-title="Publish Announcement?"
                                                data-confirm-message="This will make the announcement visible to employees.">Publish</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($ann['status'] === 'published'): ?>
                                        <?php if ($ann['pinned']): ?>
                                            <form method="post" action="announcement_action.php" class="inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                                <input type="hidden" name="id"     value="<?= (int)$ann['id'] ?>">
                                                <input type="hidden" name="action" value="unpin">
                                                <button type="submit" class="btn btn-secondary btn-sm">Unpin</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" action="announcement_action.php" class="inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                                <input type="hidden" name="id"     value="<?= (int)$ann['id'] ?>">
                                                <input type="hidden" name="action" value="pin">
                                                <button type="submit" class="btn btn-secondary btn-sm">Pin</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" action="announcement_action.php" class="inline-form" id="arc-ann-<?= (int)$ann['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                            <input type="hidden" name="id"     value="<?= (int)$ann['id'] ?>">
                                            <input type="hidden" name="action" value="archive">
                                            <button type="button" class="btn btn-secondary btn-sm"
                                                data-confirm-form="arc-ann-<?= (int)$ann['id'] ?>"
                                                data-confirm-title="Archive Announcement?"
                                                data-confirm-message="Employees will no longer see this announcement.">Archive</button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="post" action="announcement_action.php" class="inline-form" id="del-ann-<?= (int)$ann['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                        <input type="hidden" name="id"     value="<?= (int)$ann['id'] ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="button" class="link-button danger-link"
                                            data-confirm-form="del-ann-<?= (int)$ann['id'] ?>"
                                            data-confirm-title="Delete Announcement?"
                                            data-confirm-message="This will permanently remove the announcement and all read records.">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="table-empty" style="text-align:center;padding:24px;">No announcements found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</article>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
