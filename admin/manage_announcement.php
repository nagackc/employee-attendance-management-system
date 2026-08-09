<?php
require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

$id = (int)($_GET['id'] ?? 0);
$message = '';
$announcement = null;
$companyRows = $pdo->query('SELECT DISTINCT company FROM employees WHERE active = 1 ORDER BY company')->fetchAll(PDO::FETCH_COLUMN);
$availableCompanies = array_values(array_filter(array_map('strval', $companyRows)));

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $announcement = $stmt->fetch();
    if (!$announcement) {
        setFlash('error', 'Announcement not found.');
        redirect('announcements.php');
    }
}

$formValues = $announcement ?: [
    'title' => '',
    'content' => '',
    'priority' => 'normal',
    'status' => 'draft',
    'target_audience' => 'all',
    'publish_date' => '',
    'expiration_date' => '',
    'pinned' => 0,
    'allow_dismiss' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token. Please try again.';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $priority = trim($_POST['priority'] ?? 'normal');
        $targetType = trim($_POST['target_type'] ?? 'all');
        $targetCompany = trim($_POST['target_company'] ?? '');
        $publishNow = ($_POST['publish_now'] ?? '0') === '1';
        $publishDate = trim($_POST['publish_date'] ?? '');
        $expirationDate = trim($_POST['expiration_date'] ?? '');
        $allowDismiss = isset($_POST['allow_dismiss']) ? 1 : 0;
        $pinned = isset($_POST['pinned']) ? 1 : 0;

        $allowedPriority = ['normal', 'important', 'urgent'];
        if (!in_array($priority, $allowedPriority, true)) {
            $priority = 'normal';
        }

        if ($targetType === 'company' && in_array($targetCompany, $availableCompanies, true)) {
            $targetAudience = 'company:' . $targetCompany;
        } else {
            $targetAudience = 'all';
            if ($targetType === 'company') {
                $message = 'Select a valid company audience.';
            }
        }

        if ($publishNow) {
            $publishDate = date('Y-m-d H:i:s');
        } elseif ($publishDate === '') {
            $publishDate = null;
        }

        if ($expirationDate === '') {
            $expirationDate = null;
        } else {
            $expirationDate .= ':00';
        }

        if ($publishDate !== null && strlen($publishDate) === 16) {
            $publishDate .= ':00';
        }

        $formValues = [
            'id' => $id,
            'title' => $title,
            'content' => $content,
            'priority' => $priority,
            'status' => 'draft',
            'target_audience' => $targetAudience,
            'publish_date' => $publishDate ?? '',
            'expiration_date' => $expirationDate ?? '',
            'pinned' => $pinned,
            'allow_dismiss' => $allowDismiss,
        ];

        if ($message !== '') {
            // Keep the audience validation message.
        } elseif ($title === '' || $content === '') {
            $message = 'Title and content are required.';
        } elseif ($publishDate !== null && !strtotime($publishDate)) {
            $message = 'Publish date is invalid.';
        } elseif ($expirationDate !== null && !strtotime($expirationDate)) {
            $message = 'Expiration date is invalid.';
        } elseif ($publishDate !== null && $expirationDate !== null && strtotime($expirationDate) <= strtotime($publishDate)) {
            $message = 'Expiration date must be later than publish date.';
        } else {
            try {
                $pdo->beginTransaction();
                if ($id > 0) {
                    $lock = $pdo->prepare('SELECT * FROM announcements WHERE id = ? FOR UPDATE');
                    $lock->execute([$id]);
                    $oldValues = $lock->fetch();
                    if (!$oldValues) {
                        throw new RuntimeException('Announcement not found.');
                    }
                    $stmt = $pdo->prepare('UPDATE announcements
                        SET title = ?, content = ?, priority = ?, target_audience = ?, publish_date = ?, expiration_date = ?, pinned = ?, allow_dismiss = ?, status = IF(? = 1, "published", status)
                        WHERE id = ?');
                    $stmt->execute([$title, $content, $priority, $targetAudience, $publishDate, $expirationDate, $pinned, $allowDismiss, $publishNow ? 1 : 0, $id]);
                    $newStmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
                    $newStmt->execute([$id]);
                    $newValues = $newStmt->fetch() ?: [];
                    logAdminAudit($pdo, (int)$_SESSION['user_id'], 'edit_announcement', null, 'Announcement updated.', $oldValues, $newValues, 'announcement', $id);
                    setFlash('success', $publishNow ? 'Announcement updated and published.' : 'Announcement updated successfully.');
                } else {
                    $status = $publishNow ? 'published' : 'draft';
                    $stmt = $pdo->prepare('INSERT INTO announcements
                        (title, content, priority, status, target_audience, publish_date, expiration_date, pinned, allow_dismiss, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$title, $content, $priority, $status, $targetAudience, $publishDate, $expirationDate, $pinned, $allowDismiss, (int)$_SESSION['user_id']]);
                    $id = (int)$pdo->lastInsertId();
                    $newStmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
                    $newStmt->execute([$id]);
                    $newValues = $newStmt->fetch() ?: [];
                    logAdminAudit($pdo, (int)$_SESSION['user_id'], 'create_announcement', null, 'Announcement created.', null, $newValues, 'announcement', $id);
                    setFlash('success', $status === 'published' ? 'Announcement published successfully.' : 'Announcement draft created successfully.');
                }
                $pdo->commit();
                redirect('announcements.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Announcement save failed: ' . $e->getMessage());
                $message = 'Announcement could not be saved.';
            }
        }
    }
}

$targetType = 'all';
$targetCompany = '';
if (strpos((string)$formValues['target_audience'], 'company:') === 0) {
    $targetType = 'company';
    $targetCompany = substr((string)$formValues['target_audience'], 8);
}

$companyName = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$pageTitle = $announcement ? 'Edit Announcement' : 'New Announcement';
$activePage = 'announcements';
include __DIR__ . '/../includes/admin_layout_start.php';
?>
<section class="page-header">
    <div>
        <h1><?= $announcement ? 'Edit Announcement' : 'Create Announcement' ?></h1>
        <p>Manage announcement visibility, priority, and targeting.</p>
    </div>
    <div class="header-actions">
        <a href="announcements.php" class="btn btn-secondary">Back to Announcements</a>
    </div>
</section>

<article class="content-card">
    <?php if ($message !== ''): ?><div class="message"><?= h($message) ?></div><?php endif; ?>

    <form method="post" class="form-layout">
        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int)($formValues['id'] ?? 0) ?>">

        <div>
            <label for="ann-title" class="required">Title</label>
            <input id="ann-title" name="title" type="text" required maxlength="255" value="<?= h($formValues['title']) ?>">
        </div>

        <div>
            <label for="ann-content" class="required">Announcement Content</label>
            <textarea id="ann-content" name="content" rows="8" required style="width:100%;border-radius:10px;border:1px solid var(--border);padding:12px;"><?= h($formValues['content']) ?></textarea>
        </div>

        <div class="form-grid-3">
            <div>
                <label for="ann-priority" class="required">Priority</label>
                <select id="ann-priority" name="priority" required>
                    <option value="normal" <?= $formValues['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                    <option value="important" <?= $formValues['priority'] === 'important' ? 'selected' : '' ?>>Important</option>
                    <option value="urgent" <?= $formValues['priority'] === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                </select>
            </div>
            <div>
                <label for="target-type" class="required">Target Audience</label>
                <select id="target-type" name="target_type" required>
                    <option value="all" <?= $targetType === 'all' ? 'selected' : '' ?>>All Employees</option>
                    <option value="company" <?= $targetType === 'company' ? 'selected' : '' ?>>Specific Company</option>
                </select>
            </div>
            <div>
                <label for="publish-now">Publish Immediately</label>
                <select id="publish-now" name="publish_now">
                    <option value="0">No</option>
                    <option value="1">Yes</option>
                </select>
            </div>
        </div>

        <div class="form-grid-2">
            <div id="target-company-wrap" style="<?= $targetType === 'company' ? '' : 'display:none;' ?>">
                <label for="target-company">Company</label>
                <select id="target-company" name="target_company">
                    <option value="">Select company</option>
                    <?php foreach ($availableCompanies as $companyOption): ?>
                        <option value="<?= h($companyOption) ?>" <?= $targetCompany === $companyOption ? 'selected' : '' ?>><?= h($companyOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-grid-2">
            <div>
                <label for="publish-date">Publish Date</label>
                <input id="publish-date" name="publish_date" type="datetime-local" value="<?= h($formValues['publish_date'] ? date('Y-m-d\TH:i', strtotime((string)$formValues['publish_date'])) : '') ?>">
            </div>
            <div>
                <label for="expiration-date">Expiration Date (optional)</label>
                <input id="expiration-date" name="expiration_date" type="datetime-local" value="<?= h($formValues['expiration_date'] ? date('Y-m-d\TH:i', strtotime((string)$formValues['expiration_date'])) : '') ?>">
            </div>
        </div>

        <div class="form-grid-2">
            <label style="display:flex;align-items:center;gap:8px;font-weight:500;">
                <input type="checkbox" name="allow_dismiss" value="1" <?= (int)$formValues['allow_dismiss'] === 1 ? 'checked' : '' ?> style="width:auto;">
                Allow employees to dismiss this announcement
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-weight:500;">
                <input type="checkbox" name="pinned" value="1" <?= (int)$formValues['pinned'] === 1 ? 'checked' : '' ?> style="width:auto;">
                Pin announcement above all others
            </label>
        </div>

        <div>
            <button type="submit"><?= $announcement ? 'Save Changes' : 'Create Announcement' ?></button>
        </div>
    </form>
</article>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var targetType = document.getElementById('target-type');
    var companyWrap = document.getElementById('target-company-wrap');
    if (!targetType) return;
    var syncTargetFields = function () {
        companyWrap.style.display = targetType.value === 'company' ? '' : 'none';
    };
    targetType.addEventListener('change', syncTargetFields);
    syncTargetFields();
});
</script>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
