<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

function auditObjectLabel(?string $type, mixed $id): string {
    $type = trim((string)$type);
    if ($type === '') {
        return '—';
    }
    return formatAuditAction($type) . ((int)$id > 0 ? ' #' . (int)$id : '');
}

function auditObjectUrl(?string $type, mixed $id): ?string {
    $objectId = (int)$id;
    return match ((string)$type) {
        'employee' => $objectId > 0 ? 'manage_employee.php?id=' . $objectId : null,
        'attendance' => $objectId > 0 ? 'edit_attendance.php?id=' . $objectId : null,
        'attendance_correction_request' => $objectId > 0 ? 'attendance_corrections.php?id=' . $objectId . '#correction-review' : null,
        'work_shift', 'employee_shift_assignment', 'company_shift_assignment' => 'shifts.php',
        'leave_entitlement_policy' => 'leave_balances.php?tab=policies' . ($objectId > 0 ? '&leave_type_id=' . $objectId : ''),
        'leave_balance_adjustment' => 'leave_balances.php?tab=adjustments',
        'settings' => 'settings.php',
        default => null,
    };
}

function auditChangeSummary(?string $oldJson, ?string $newJson): string {
    $parts = [];
    foreach (buildAuditChangeRows($oldJson, $newJson) as $change) {
        $parts[] = $change['field'] . ': ' . $change['before'] . ' -> ' . $change['after'];
    }
    return implode(' | ', $parts);
}

$search = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 120);
$actionFilter = trim((string)($_GET['action'] ?? ''));
$adminFilter = max(0, (int)($_GET['admin_id'] ?? 0));
$employeeFilter = max(0, (int)($_GET['employee_id'] ?? 0));
$objectFilter = trim((string)($_GET['object_type'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$export = trim((string)($_GET['export'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$filterError = '';

if ($dateFrom !== '' && !isValidDateValue($dateFrom)) {
    $filterError = 'Start date is invalid.';
} elseif ($dateTo !== '' && !isValidDateValue($dateTo)) {
    $filterError = 'End date is invalid.';
} elseif ($dateFrom !== '' && $dateTo !== '' && $dateTo < $dateFrom) {
    $filterError = 'End date cannot be before start date.';
}

$actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$objectTypes = $pdo->query('SELECT DISTINCT object_type FROM audit_logs
    WHERE object_type IS NOT NULL AND object_type <> "" ORDER BY object_type')->fetchAll(PDO::FETCH_COLUMN);
$administrators = $pdo->query('SELECT DISTINCT e.id, CONCAT(e.first_name, " ", e.last_name) AS name, e.active
    FROM audit_logs al INNER JOIN employees e ON e.id = al.admin_id ORDER BY active DESC, name')->fetchAll();
$employees = $pdo->query('SELECT id, CONCAT(first_name, " ", last_name) AS name, company, active
    FROM employees ORDER BY active DESC, first_name, last_name')->fetchAll();

$where = [];
$params = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(al.action LIKE ? OR al.details LIKE ? OR al.object_type LIKE ? OR al.ip_address LIKE ?
        OR CONCAT(ad.first_name, " ", ad.last_name) LIKE ? OR CONCAT(ae.first_name, " ", ae.last_name) LIKE ?
        OR CAST(al.object_id AS CHAR) LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}
if ($actionFilter !== '') {
    $where[] = 'al.action = ?';
    $params[] = $actionFilter;
}
if ($adminFilter > 0) {
    $where[] = 'al.admin_id = ?';
    $params[] = $adminFilter;
}
if ($employeeFilter > 0) {
    $where[] = 'al.affected_employee_id = ?';
    $params[] = $employeeFilter;
}
if ($objectFilter !== '') {
    $where[] = 'al.object_type = ?';
    $params[] = $objectFilter;
}
if ($dateFrom !== '' && $filterError === '') {
    $where[] = 'al.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '' && $filterError === '') {
    $where[] = 'al.created_at < ?';
    $params[] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$fromSql = ' FROM audit_logs al
    INNER JOIN employees ad ON ad.id = al.admin_id
    LEFT JOIN employees ae ON ae.id = al.affected_employee_id ';
$selectSql = 'SELECT al.*, CONCAT(ad.first_name, " ", ad.last_name) AS admin_name, ad.active AS admin_active,
        CONCAT(ae.first_name, " ", ae.last_name) AS affected_employee_name, ae.company AS affected_company,
        ae.active AS affected_employee_active' . $fromSql . $whereSql;

$summaryStmt = $pdo->prepare('SELECT COUNT(*) AS total, COUNT(DISTINCT al.admin_id) AS administrators,
        COUNT(DISTINCT al.action) AS actions, COUNT(DISTINCT al.affected_employee_id) AS affected_employees' . $fromSql . $whereSql);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch() ?: ['total' => 0, 'administrators' => 0, 'actions' => 0, 'affected_employees' => 0];
$totalRows = (int)$summary['total'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);

$filterQuery = array_filter([
    'search' => $search,
    'action' => $actionFilter,
    'admin_id' => $adminFilter,
    'employee_id' => $employeeFilter,
    'object_type' => $objectFilter,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
], static fn(mixed $value): bool => $value !== '' && $value !== 0);

if ($export === 'csv' && $filterError === '') {
    $exportStmt = $pdo->prepare($selectSql . ' ORDER BY al.created_at DESC, al.id DESC');
    $exportStmt->execute($params);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="admin-audit-log-' . date('Y-m-d') . '.csv"');
    echo chr(239) . chr(187) . chr(191);
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Audit ID', 'Timestamp', 'Administrator', 'Action', 'Affected Employee', 'Company',
        'Object', 'Details', 'Changes', 'IP Address']);
    while ($row = $exportStmt->fetch()) {
        fputcsv($output, [
            (int)$row['id'],
            (string)$row['created_at'],
            (string)$row['admin_name'],
            formatAuditAction((string)$row['action']),
            (string)($row['affected_employee_name'] ?? ''),
            (string)($row['affected_company'] ?? ''),
            auditObjectLabel($row['object_type'], $row['object_id']),
            (string)($row['details'] ?? ''),
            auditChangeSummary($row['old_values'], $row['new_values']),
            (string)($row['ip_address'] ?? ''),
        ]);
    }
    fclose($output);
    exit;
}

$offset = ($page - 1) * $perPage;
$rowsStmt = $pdo->prepare($selectSql . ' ORDER BY al.created_at DESC, al.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$rowsStmt->execute($params);
$auditRows = $rowsStmt->fetchAll();

$detailId = max(0, (int)($_GET['id'] ?? 0));
$detail = null;
if ($detailId > 0) {
    $detailStmt = $pdo->prepare('SELECT al.*, CONCAT(ad.first_name, " ", ad.last_name) AS admin_name, ad.active AS admin_active,
            CONCAT(ae.first_name, " ", ae.last_name) AS affected_employee_name, ae.company AS affected_company,
            ae.active AS affected_employee_active
        FROM audit_logs al INNER JOIN employees ad ON ad.id = al.admin_id
        LEFT JOIN employees ae ON ae.id = al.affected_employee_id WHERE al.id = ? LIMIT 1');
    $detailStmt->execute([$detailId]);
    $detail = $detailStmt->fetch() ?: null;
}
$detailChanges = $detail ? buildAuditChangeRows($detail['old_values'], $detail['new_values']) : [];

$companyName = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$pageTitle = 'Audit Log';
$activeSubPage = 'audit_logs';
include __DIR__ . '/../includes/admin_layout_start.php';
?>

<section class="page-header audit-page-header">
    <div><h1>Admin Audit Log</h1><p>Review immutable administrator activity, affected records, and before-and-after values.</p></div>
    <a class="btn btn-secondary btn-sm" href="audit_logs.php?<?= h(http_build_query(array_merge($filterQuery, ['export' => 'csv']))) ?>">Export CSV</a>
</section>

<?php if ($filterError !== ''): ?><div class="message"><?= h($filterError) ?></div><?php endif; ?>

<section class="summary-grid audit-summary-grid" aria-label="Audit summary">
    <div class="summary-card"><strong>Matching Events</strong><div class="summary-value"><?= $totalRows ?></div></div>
    <div class="summary-card"><strong>Administrators</strong><div class="summary-value"><?= (int)$summary['administrators'] ?></div></div>
    <div class="summary-card"><strong>Action Types</strong><div class="summary-value"><?= (int)$summary['actions'] ?></div></div>
    <div class="summary-card"><strong>Affected Employees</strong><div class="summary-value"><?= (int)$summary['affected_employees'] ?></div></div>
</section>

<article class="content-card audit-filter-card">
    <form method="get" class="audit-filter-grid">
        <div class="audit-search-field"><label for="audit-search">Search</label><input id="audit-search" type="search" name="search" maxlength="120" value="<?= h($search) ?>" placeholder="Action, details, person, object, or IP"></div>
        <div><label for="audit-action">Action</label><select id="audit-action" name="action"><option value="">All Actions</option><?php foreach ($actions as $action): ?><option value="<?= h($action) ?>" <?= $actionFilter === $action ? 'selected' : '' ?>><?= h(formatAuditAction((string)$action)) ?></option><?php endforeach; ?></select></div>
        <div><label for="audit-admin">Administrator</label><select id="audit-admin" name="admin_id"><option value="0">All Administrators</option><?php foreach ($administrators as $administrator): ?><option value="<?= (int)$administrator['id'] ?>" <?= $adminFilter === (int)$administrator['id'] ? 'selected' : '' ?>><?= h($administrator['name'] . ((int)$administrator['active'] === 0 ? ' (Inactive)' : '')) ?></option><?php endforeach; ?></select></div>
        <div><label for="audit-employee">Affected Employee</label><select id="audit-employee" name="employee_id"><option value="0">All Employees</option><?php foreach ($employees as $employee): ?><option value="<?= (int)$employee['id'] ?>" <?= $employeeFilter === (int)$employee['id'] ? 'selected' : '' ?>><?= h($employee['name'] . ' · ' . $employee['company'] . ((int)$employee['active'] === 0 ? ' (Inactive)' : '')) ?></option><?php endforeach; ?></select></div>
        <div><label for="audit-object">Object Type</label><select id="audit-object" name="object_type"><option value="">All Objects</option><?php foreach ($objectTypes as $objectType): ?><option value="<?= h($objectType) ?>" <?= $objectFilter === $objectType ? 'selected' : '' ?>><?= h(formatAuditAction((string)$objectType)) ?></option><?php endforeach; ?></select></div>
        <div><label for="audit-from">From</label><input id="audit-from" type="date" name="date_from" value="<?= h($dateFrom) ?>"></div>
        <div><label for="audit-to">To</label><input id="audit-to" type="date" name="date_to" value="<?= h($dateTo) ?>"></div>
        <div class="audit-filter-actions"><button type="submit" class="btn btn-primary">Apply Filters</button><a class="btn btn-secondary" href="audit_logs.php">Reset</a></div>
    </form>
</article>

<article class="content-card audit-list-card">
    <div class="card-header"><div><h3>Activity</h3><p class="muted">Showing <?= $totalRows === 0 ? 0 : $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> of <?= $totalRows ?> events.</p></div></div>
    <div class="table-card audit-table-card" data-sticky-head="true"><table><thead><tr><th>When</th><th>Administrator</th><th>Action</th><th>Affected Employee</th><th>Object</th><th>Details</th><th>IP</th><th>Review</th></tr></thead><tbody>
        <?php if ($auditRows): foreach ($auditRows as $row): ?>
            <?php $detailUrl = 'audit_logs.php?' . http_build_query(array_merge($filterQuery, ['page' => $page, 'id' => (int)$row['id']])) . '#audit-detail'; ?>
            <tr>
                <td class="audit-time-cell"><strong><?= h(date('M j, Y', strtotime((string)$row['created_at']))) ?></strong><span><?= h(date('H:i:s', strtotime((string)$row['created_at']))) ?></span></td>
                <td><strong><?= h($row['admin_name']) ?></strong><?= (int)$row['admin_active'] === 0 ? '<span class="report-inactive-label">Inactive</span>' : '' ?></td>
                <td><span class="pill pill-blue"><?= h(formatAuditAction((string)$row['action'])) ?></span></td>
                <td><?= $row['affected_employee_name'] ? '<strong>' . h($row['affected_employee_name']) . '</strong><span class="report-cell-note">' . h($row['affected_company']) . '</span>' : '—' ?></td>
                <td><?= h(auditObjectLabel($row['object_type'], $row['object_id'])) ?></td>
                <td class="audit-details-cell"><?= h($row['details'] ?: '—') ?></td>
                <td class="audit-ip-cell"><?= h($row['ip_address'] ?: '—') ?></td>
                <td><a class="btn btn-secondary btn-sm" href="<?= h($detailUrl) ?>">View</a></td>
            </tr>
        <?php endforeach; else: ?><tr><td colspan="8" class="table-empty-cell">No audit events match the selected filters.</td></tr><?php endif; ?>
    </tbody></table></div>

    <?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Audit log pages">
        <?php if ($page > 1): ?><a href="audit_logs.php?<?= h(http_build_query(array_merge($filterQuery, ['page' => $page - 1]))) ?>">Previous</a><?php endif; ?>
        <?php for ($pageNumber = max(1, $page - 2); $pageNumber <= min($totalPages, $page + 2); $pageNumber++): ?>
            <?= $pageNumber === $page ? '<span class="current">' . $pageNumber . '</span>' : '<a href="audit_logs.php?' . h(http_build_query(array_merge($filterQuery, ['page' => $pageNumber]))) . '">' . $pageNumber . '</a>' ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?><a href="audit_logs.php?<?= h(http_build_query(array_merge($filterQuery, ['page' => $page + 1]))) ?>">Next</a><?php endif; ?>
    </nav><?php endif; ?>
</article>

<?php if ($detail): ?>
    <?php $objectUrl = auditObjectUrl($detail['object_type'], $detail['object_id']); ?>
    <article class="content-card audit-detail-card" id="audit-detail" tabindex="-1">
        <div class="card-header"><div><span class="employee-eyebrow">Audit event #<?= (int)$detail['id'] ?></span><h3><?= h(formatAuditAction((string)$detail['action'])) ?></h3><p class="muted"><?= h(date('F j, Y · H:i:s', strtotime((string)$detail['created_at']))) ?></p></div><a class="btn btn-secondary btn-sm" href="audit_logs.php?<?= h(http_build_query(array_merge($filterQuery, ['page' => $page]))) ?>">Close Details</a></div>
        <dl class="audit-event-meta">
            <div><dt>Administrator</dt><dd><?= h($detail['admin_name']) ?></dd></div>
            <div><dt>Affected Employee</dt><dd><?= h($detail['affected_employee_name'] ?: '—') ?><?= $detail['affected_company'] ? ' · ' . h($detail['affected_company']) : '' ?></dd></div>
            <div><dt>Object</dt><dd><?= $objectUrl ? '<a href="' . h($objectUrl) . '">' . h(auditObjectLabel($detail['object_type'], $detail['object_id'])) . '</a>' : h(auditObjectLabel($detail['object_type'], $detail['object_id'])) ?></dd></div>
            <div><dt>IP Address</dt><dd><?= h($detail['ip_address'] ?: '—') ?></dd></div>
        </dl>
        <section class="audit-detail-message"><strong>Recorded details</strong><p><?= nl2br(h($detail['details'] ?: 'No additional details were recorded.')) ?></p></section>
        <h4>Before and After</h4>
        <?php if ($detailChanges): ?><div class="table-card audit-change-table"><table><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody><?php foreach ($detailChanges as $change): ?><tr><td><strong><?= h($change['field']) ?></strong></td><td><?= h($change['before']) ?></td><td><?= h($change['after']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php else: ?><div class="empty-state">This event does not contain a before-and-after snapshot.</div><?php endif; ?>
    </article>
<?php elseif ($detailId > 0): ?><div class="message">The requested audit event was not found.</div><?php endif; ?>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
