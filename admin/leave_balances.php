<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

$adminId = (int)$_SESSION['user_id'];
$tab = trim((string)($_REQUEST['tab'] ?? 'overview'));
if (!in_array($tab, ['overview', 'policies', 'adjustments'], true)) {
    $tab = 'overview';
}
$year = (int)($_REQUEST['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}
$companyFilter = trim((string)($_REQUEST['company'] ?? ''));
$employeeFilter = (int)($_REQUEST['employee_id'] ?? 0);
$typeFilter = (int)($_REQUEST['leave_type_id'] ?? 0);
$searchFilter = trim((string)($_REQUEST['search'] ?? ''));

$redirectToBalances = static function (string $targetTab, array $extra = []) use ($year, $companyFilter, $employeeFilter, $typeFilter, $searchFilter): never {
    $query = array_merge([
        'tab' => $targetTab,
        'year' => $year,
        'company' => $companyFilter,
        'employee_id' => $employeeFilter,
        'leave_type_id' => $typeFilter,
        'search' => $searchFilter,
    ], $extra);
    redirect('leave_balances.php?' . http_build_query(array_filter($query, static fn(mixed $value): bool => $value !== '' && $value !== 0)));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request token.');
        $redirectToBalances($tab);
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
    $unit = trim((string)($_POST['unit'] ?? 'days'));
    $amountRaw = trim((string)($_POST['amount'] ?? ''));
    $multiplier = $unit === 'hours' ? 60 : LEAVE_MINUTES_PER_DAY;

    if ($action === 'save_policy') {
        if ($leaveTypeId <= 0 || !in_array($unit, ['days', 'hours'], true) || !is_numeric($amountRaw) || (float)$amountRaw < 0) {
            setFlash('error', 'Enter a valid non-negative entitlement amount.');
            $redirectToBalances('policies');
        }
        $minutes = (int)round((float)$amountRaw * $multiplier);
        if ($minutes > 1000000 || $minutes % 30 !== 0) {
            setFlash('error', 'Entitlements must resolve to 30-minute increments.');
            $redirectToBalances('policies');
        }
        $typeStmt = $pdo->prepare('SELECT name FROM leave_types WHERE id = ? LIMIT 1');
        $typeStmt->execute([$leaveTypeId]);
        $typeName = $typeStmt->fetchColumn();
        if ($typeName === false) {
            setFlash('error', 'Leave type not found.');
            $redirectToBalances('policies');
        }
        $oldStmt = $pdo->prepare('SELECT * FROM leave_entitlement_policies WHERE leave_type_id = ? AND effective_year = ? LIMIT 1');
        $oldStmt->execute([$leaveTypeId, $year]);
        $oldPolicy = $oldStmt->fetch() ?: null;
        $stmt = $pdo->prepare('INSERT INTO leave_entitlement_policies (leave_type_id, effective_year, annual_minutes, updated_by)
            VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE annual_minutes = VALUES(annual_minutes), updated_by = VALUES(updated_by)');
        $stmt->execute([$leaveTypeId, $year, $minutes, $adminId]);
        logAdminAudit($pdo, $adminId, 'set_leave_entitlement', null,
            $typeName . ' | effective year ' . $year . ' | ' . $minutes . ' minutes',
            $oldPolicy, ['leave_type_id' => $leaveTypeId, 'effective_year' => $year, 'annual_minutes' => $minutes],
            'leave_entitlement_policy', $leaveTypeId);
        setFlash('success', 'Annual entitlement saved for ' . $typeName . '.');
        $redirectToBalances('policies');
    }

    if ($action === 'add_adjustment') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $direction = trim((string)($_POST['direction'] ?? 'credit'));
        $effectiveDate = trim((string)($_POST['effective_date'] ?? ''));
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        if ($employeeId <= 0 || $leaveTypeId <= 0 || !in_array($unit, ['days', 'hours'], true)
            || !in_array($direction, ['credit', 'deduct'], true) || !is_numeric($amountRaw) || (float)$amountRaw <= 0
            || !isValidDateValue($effectiveDate) || (int)substr($effectiveDate, 0, 4) !== $year
            || $remarks === '' || mb_strlen($remarks) > 1000) {
            setFlash('error', 'Complete all adjustment fields with valid values for the selected year.');
            $redirectToBalances('adjustments');
        }
        $minutes = (int)round((float)$amountRaw * $multiplier);
        if ($minutes <= 0 || $minutes > 1000000 || $minutes % 30 !== 0) {
            setFlash('error', 'Adjustments must resolve to positive 30-minute increments.');
            $redirectToBalances('adjustments');
        }
        if ($direction === 'deduct') {
            $minutes *= -1;
        }
        $employeeStmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) AS name, company FROM employees
            WHERE id = ? AND role = "employee" AND active = 1 LIMIT 1');
        $employeeStmt->execute([$employeeId]);
        $employeeRow = $employeeStmt->fetch();
        $typeStmt = $pdo->prepare('SELECT name FROM leave_types WHERE id = ? LIMIT 1');
        $typeStmt->execute([$leaveTypeId]);
        $typeName = $typeStmt->fetchColumn();
        if (!$employeeRow || $typeName === false) {
            setFlash('error', 'Active employee or leave type not found.');
            $redirectToBalances('adjustments');
        }
        $stmt = $pdo->prepare('INSERT INTO leave_balance_adjustments
            (employee_id, leave_type_id, period_year, adjustment_minutes, effective_date, remarks, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$employeeId, $leaveTypeId, $year, $minutes, $effectiveDate, $remarks, $adminId]);
        $adjustmentId = (int)$pdo->lastInsertId();
        logAdminAudit($pdo, $adminId, 'adjust_leave_balance', $employeeId,
            $employeeRow['name'] . ' | ' . $typeName . ' | ' . $minutes . ' minutes | ' . $remarks,
            null, ['adjustment_minutes' => $minutes, 'period_year' => $year, 'effective_date' => $effectiveDate, 'remarks' => $remarks],
            'leave_balance_adjustment', $adjustmentId);
        setFlash('success', 'Immutable leave adjustment recorded.');
        $redirectToBalances('adjustments', ['employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId]);
    }

    setFlash('error', 'Unsupported leave balance action.');
    $redirectToBalances($tab);
}

$employees = $pdo->query('SELECT id, CONCAT(first_name, " ", last_name) AS name, company, active, deactivated_at
    FROM employees WHERE role = "employee" ORDER BY active DESC, first_name, last_name')->fetchAll();
$activeEmployees = array_values(array_filter($employees, static fn(array $employee): bool => (int)$employee['active'] === 1));
$employeeById = [];
foreach ($employees as $employee) {
    $employeeById[(int)$employee['id']] = $employee;
}
$companies = $pdo->query('SELECT DISTINCT company FROM employees WHERE role = "employee" AND company <> "" ORDER BY company')->fetchAll(PDO::FETCH_COLUMN);

$policyStmt = $pdo->prepare('SELECT lt.id, lt.name, lt.active, p.annual_minutes, p.effective_year
    FROM leave_types lt
    LEFT JOIN leave_entitlement_policies p ON p.id = (
        SELECT p2.id FROM leave_entitlement_policies p2
        WHERE p2.leave_type_id = lt.id AND p2.effective_year <= ?
        ORDER BY p2.effective_year DESC, p2.id DESC LIMIT 1
    ) ORDER BY lt.active DESC, lt.name');
$policyStmt->execute([$year]);
$policyRows = $policyStmt->fetchAll();
$policyByType = [];
foreach ($policyRows as $policy) {
    $policyByType[(int)$policy['id']] = $policy;
}

$filteredEmployees = array_values(array_filter($employees, static function (array $employee) use ($companyFilter, $employeeFilter, $searchFilter): bool {
    if ($companyFilter !== '' && (string)$employee['company'] !== $companyFilter) {
        return false;
    }
    if ($employeeFilter > 0 && (int)$employee['id'] !== $employeeFilter) {
        return false;
    }
    return $searchFilter === '' || stripos((string)$employee['name'], $searchFilter) !== false;
}));
$filteredPolicies = array_values(array_filter($policyRows, static fn(array $policy): bool => $typeFilter === 0 || (int)$policy['id'] === $typeFilter));

$adjustmentTotalsStmt = $pdo->prepare('SELECT employee_id, leave_type_id, SUM(adjustment_minutes) AS total
    FROM leave_balance_adjustments WHERE period_year = ? GROUP BY employee_id, leave_type_id');
$adjustmentTotalsStmt->execute([$year]);
$adjustmentTotals = [];
foreach ($adjustmentTotalsStmt->fetchAll() as $row) {
    $adjustmentTotals[(int)$row['employee_id']][(int)$row['leave_type_id']] = (int)$row['total'];
}
$usageTotalsStmt = $pdo->prepare('SELECT lr.employee_id, lr.leave_type_id, lr.status, SUM(lrc.minutes) AS total
    FROM leave_requests lr INNER JOIN leave_request_charges lrc ON lrc.leave_request_id = lr.id
    WHERE YEAR(lrc.charge_date) = ? AND lr.status IN ("approved", "pending")
    GROUP BY lr.employee_id, lr.leave_type_id, lr.status');
$usageTotalsStmt->execute([$year]);
$usageTotals = [];
foreach ($usageTotalsStmt->fetchAll() as $row) {
    $usageTotals[(int)$row['employee_id']][(int)$row['leave_type_id']][(string)$row['status']] = (int)$row['total'];
}

$balancesByEmployee = [];
$overviewRows = [];
foreach ($filteredEmployees as $employee) {
    $employeeId = (int)$employee['id'];
    $overview = [
        'employee' => $employee,
        'leave_type_count' => count($filteredPolicies),
        'annual_minutes' => 0,
        'adjustment_minutes' => 0,
        'used_minutes' => 0,
        'pending_minutes' => 0,
        'available_minutes' => 0,
        'negative_types' => 0,
    ];
    foreach ($filteredPolicies as $policy) {
        $leaveTypeId = (int)$policy['id'];
        $annual = max(0, (int)($policy['annual_minutes'] ?? 0));
        $adjustment = $adjustmentTotals[$employeeId][$leaveTypeId] ?? 0;
        $used = $usageTotals[$employeeId][$leaveTypeId]['approved'] ?? 0;
        $pending = $usageTotals[$employeeId][$leaveTypeId]['pending'] ?? 0;
        $available = $annual + $adjustment - $used;
        $balance = [
            'leave_type_id' => $leaveTypeId,
            'leave_type_name' => (string)$policy['name'],
            'active' => (int)$policy['active'],
            'effective_year' => $policy['effective_year'],
            'annual_minutes' => $annual,
            'adjustment_minutes' => $adjustment,
            'used_minutes' => $used,
            'pending_minutes' => $pending,
            'available_minutes' => $available,
            'projected_minutes' => $available - $pending,
        ];
        $balancesByEmployee[$employeeId][] = $balance;
        foreach (['annual_minutes', 'adjustment_minutes', 'used_minutes', 'pending_minutes', 'available_minutes'] as $field) {
            $overview[$field] += $balance[$field];
        }
        if ($available < 0) {
            $overview['negative_types']++;
        }
    }
    $overviewRows[] = $overview;
}

$overviewTotals = [
    'employees' => count($overviewRows),
    'annual_minutes' => 0,
    'used_minutes' => 0,
    'pending_minutes' => 0,
    'available_minutes' => 0,
    'negative_balances' => 0,
];
foreach ($overviewRows as $row) {
    foreach (['annual_minutes', 'used_minutes', 'pending_minutes', 'available_minutes'] as $field) {
        $overviewTotals[$field] += $row[$field];
    }
    $overviewTotals['negative_balances'] += $row['negative_types'];
}

$detailEmployeeId = (int)($_GET['detail_employee'] ?? 0);
$detailEmployee = $employeeById[$detailEmployeeId] ?? null;
$detailAdjustments = [];
$detailUsage = [];
if ($detailEmployee) {
    $detailAdjustmentSql = 'SELECT lba.*, lt.name AS leave_type_name, CONCAT(a.first_name, " ", a.last_name) AS admin_name
        FROM leave_balance_adjustments lba INNER JOIN leave_types lt ON lt.id = lba.leave_type_id
        INNER JOIN employees a ON a.id = lba.created_by
        WHERE lba.employee_id = ? AND lba.period_year = ?';
    $detailAdjustmentParams = [$detailEmployeeId, $year];
    if ($typeFilter > 0) {
        $detailAdjustmentSql .= ' AND lba.leave_type_id = ?';
        $detailAdjustmentParams[] = $typeFilter;
    }
    $detailAdjustmentSql .= ' ORDER BY lba.effective_date DESC, lba.id DESC';
    $stmt = $pdo->prepare($detailAdjustmentSql);
    $stmt->execute($detailAdjustmentParams);
    $detailAdjustments = $stmt->fetchAll();

    $detailUsageSql = 'SELECT lr.id, lr.leave_type_id, lt.name AS leave_type_name, lr.start_date, lr.end_date,
            lr.request_unit, lr.status, SUM(lrc.minutes) AS period_minutes
        FROM leave_requests lr INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
        INNER JOIN leave_request_charges lrc ON lrc.leave_request_id = lr.id
        WHERE lr.employee_id = ? AND YEAR(lrc.charge_date) = ? AND lr.status IN ("approved", "pending")';
    $detailUsageParams = [$detailEmployeeId, $year];
    if ($typeFilter > 0) {
        $detailUsageSql .= ' AND lr.leave_type_id = ?';
        $detailUsageParams[] = $typeFilter;
    }
    $detailUsageSql .= ' GROUP BY lr.id, lr.leave_type_id, lt.name, lr.start_date, lr.end_date, lr.request_unit, lr.status
        ORDER BY lr.start_date DESC, lr.id DESC';
    $stmt = $pdo->prepare($detailUsageSql);
    $stmt->execute($detailUsageParams);
    $detailUsage = $stmt->fetchAll();
}

$ledgerSql = 'SELECT lba.*, e.company, e.active, CONCAT(e.first_name, " ", e.last_name) AS employee_name,
        lt.name AS leave_type_name, CONCAT(a.first_name, " ", a.last_name) AS admin_name
    FROM leave_balance_adjustments lba INNER JOIN employees e ON e.id = lba.employee_id
    INNER JOIN leave_types lt ON lt.id = lba.leave_type_id INNER JOIN employees a ON a.id = lba.created_by
    WHERE lba.period_year = ?';
$ledgerParams = [$year];
if ($companyFilter !== '') { $ledgerSql .= ' AND e.company = ?'; $ledgerParams[] = $companyFilter; }
if ($employeeFilter > 0) { $ledgerSql .= ' AND lba.employee_id = ?'; $ledgerParams[] = $employeeFilter; }
if ($typeFilter > 0) { $ledgerSql .= ' AND lba.leave_type_id = ?'; $ledgerParams[] = $typeFilter; }
if ($searchFilter !== '') { $ledgerSql .= ' AND (e.first_name LIKE ? OR e.last_name LIKE ? OR lba.remarks LIKE ?)'; $like = '%' . $searchFilter . '%'; array_push($ledgerParams, $like, $like, $like); }
$ledgerSql .= ' ORDER BY lba.effective_date DESC, lba.id DESC';
$ledgerStmt = $pdo->prepare($ledgerSql);
$ledgerStmt->execute($ledgerParams);
$adjustmentLedger = $ledgerStmt->fetchAll();

$baseFilters = ['year' => $year, 'company' => $companyFilter, 'employee_id' => $employeeFilter, 'leave_type_id' => $typeFilter, 'search' => $searchFilter];
$overviewFilterQuery = array_merge(['tab' => 'overview'], $baseFilters);
$companyName = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$pageTitle = 'Leave Balances';
$activeSubPage = 'leave_balances';
include __DIR__ . '/../includes/admin_layout_start.php';
?>

<section class="page-header leave-balance-page-header">
    <div><h1>Leave Balances</h1><p>Review employee balances, configure annual policies, and record audited adjustments.</p></div>
    <form method="get" class="leave-balance-page-year">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <label for="leave-balance-year">Year</label>
        <input id="leave-balance-year" type="number" name="year" min="2000" max="2100" value="<?= $year ?>">
        <button type="submit" class="btn btn-secondary btn-sm">Go</button>
    </form>
</section>

<nav class="admin-page-tabs" aria-label="Leave balance sections">
    <?php foreach (['overview' => 'Overview', 'policies' => 'Entitlement Policies', 'adjustments' => 'Adjustments'] as $tabKey => $tabLabel): ?>
        <a class="<?= $tab === $tabKey ? 'is-active' : '' ?>" href="leave_balances.php?<?= h(http_build_query(['tab' => $tabKey, 'year' => $year])) ?>"<?= $tab === $tabKey ? ' aria-current="page"' : '' ?>><?= h($tabLabel) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'overview'): ?>
    <article class="content-card leave-balance-toolbar-card">
        <form method="get" class="form-grid-5 leave-balance-filter">
            <input type="hidden" name="tab" value="overview"><input type="hidden" name="year" value="<?= $year ?>">
            <div><label for="balance-search">Search Employee</label><input id="balance-search" type="search" name="search" value="<?= h($searchFilter) ?>" placeholder="Name"></div>
            <div><label for="balance-company">Company</label><select id="balance-company" name="company"><option value="">All Companies</option><?php foreach ($companies as $company): ?><option value="<?= h($company) ?>" <?= $companyFilter === $company ? 'selected' : '' ?>><?= h($company) ?></option><?php endforeach; ?></select></div>
            <div><label for="balance-employee">Employee</label><select id="balance-employee" name="employee_id"><option value="0">All Employees</option><?php foreach ($employees as $employee): ?><option value="<?= (int)$employee['id'] ?>" <?= $employeeFilter === (int)$employee['id'] ? 'selected' : '' ?>><?= h($employee['name'] . ' · ' . $employee['company'] . ((int)$employee['active'] === 0 ? ' (Inactive)' : '')) ?></option><?php endforeach; ?></select></div>
            <div><label for="balance-type">Leave Type</label><select id="balance-type" name="leave_type_id"><option value="0">All Leave Types</option><?php foreach ($policyRows as $policy): ?><option value="<?= (int)$policy['id'] ?>" <?= $typeFilter === (int)$policy['id'] ? 'selected' : '' ?>><?= h($policy['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-action-cell"><button type="submit" class="btn btn-secondary">Apply Filters</button></div>
        </form>
    </article>

    <div class="leave-balance-overview-actions">
        <div class="leave-unit-toggle" role="group" aria-label="Balance display unit"><button type="button" class="is-active" data-leave-unit="days">Days</button><button type="button" data-leave-unit="hours">Hours</button></div>
        <span class="muted">One day equals eight hours.</span>
    </div>

    <section class="summary-grid leave-balance-overview-summary" aria-label="Leave balance summary">
        <div class="summary-card"><strong>Employees</strong><div class="summary-value"><?= $overviewTotals['employees'] ?></div></div>
        <div class="summary-card"><strong>Annual Credit</strong><div class="summary-value leave-balance-value" data-leave-minutes="<?= $overviewTotals['annual_minutes'] ?>"><?= h(formatLeaveMinutes($overviewTotals['annual_minutes'])) ?></div></div>
        <div class="summary-card"><strong>Approved Used</strong><div class="summary-value leave-balance-value" data-leave-minutes="<?= $overviewTotals['used_minutes'] ?>"><?= h(formatLeaveMinutes($overviewTotals['used_minutes'])) ?></div></div>
        <div class="summary-card"><strong>Pending</strong><div class="summary-value leave-balance-value" data-leave-minutes="<?= $overviewTotals['pending_minutes'] ?>"><?= h(formatLeaveMinutes($overviewTotals['pending_minutes'])) ?></div></div>
        <div class="summary-card"><strong>Available</strong><div class="summary-value leave-balance-value<?= $overviewTotals['available_minutes'] < 0 ? ' is-negative' : '' ?>" data-leave-minutes="<?= $overviewTotals['available_minutes'] ?>"><?= h(formatLeaveMinutes($overviewTotals['available_minutes'])) ?></div></div>
        <div class="summary-card"><strong>Negative Types</strong><div class="summary-value<?= $overviewTotals['negative_balances'] > 0 ? ' is-negative' : '' ?>"><?= $overviewTotals['negative_balances'] ?></div></div>
    </section>

    <article class="content-card">
        <div class="card-header"><div><h3>Employee Overview</h3><p class="muted">Totals reflect the selected leave-type filter.</p></div><span class="pill pill-gray"><?= count($overviewRows) ?> employees</span></div>
        <div class="table-card leave-balance-master-table"><table><thead><tr><th>Employee</th><th>Company</th><th>Types</th><th>Credit</th><th>Approved Used</th><th>Pending</th><th>Available</th><th>Attention</th><th>Details</th></tr></thead><tbody>
            <?php if ($overviewRows): foreach ($overviewRows as $row): ?>
                <?php $employee = $row['employee']; $detailUrl = 'leave_balances.php?' . http_build_query(array_merge($overviewFilterQuery, ['detail_employee' => (int)$employee['id']])) . '#balance-details'; ?>
                <tr>
                    <td><strong><?= h($employee['name']) ?></strong><?= (int)$employee['active'] === 0 ? '<span class="report-inactive-label">Inactive</span>' : '' ?></td>
                    <td><?= h($employee['company']) ?></td><td><?= (int)$row['leave_type_count'] ?></td>
                    <?php foreach (['annual_minutes', 'used_minutes', 'pending_minutes', 'available_minutes'] as $field): ?><td class="leave-balance-value<?= $field === 'available_minutes' && $row[$field] < 0 ? ' is-negative' : '' ?>" data-leave-minutes="<?= (int)$row[$field] ?>"><?= h(formatLeaveMinutes((int)$row[$field])) ?></td><?php endforeach; ?>
                    <td><?= $row['negative_types'] > 0 ? '<span class="pill pill-red">' . (int)$row['negative_types'] . ' negative</span>' : '<span class="pill pill-green">Clear</span>' ?></td>
                    <td><a class="btn btn-secondary btn-sm" href="<?= h($detailUrl) ?>">View</a></td>
                </tr>
            <?php endforeach; else: ?><tr><td colspan="9" class="table-empty-cell">No employees match the selected filters.</td></tr><?php endif; ?>
        </tbody></table></div>
    </article>

    <?php if ($detailEmployee): ?>
        <article class="content-card leave-balance-focus-panel" id="balance-details">
            <div class="card-header">
                <div><span class="employee-eyebrow">Balance details</span><h3><?= h($detailEmployee['name']) ?></h3><p class="muted"><?= h($detailEmployee['company']) ?> · January 1–December 31, <?= $year ?></p></div>
                <a class="btn btn-secondary btn-sm" href="leave_balances.php?<?= h(http_build_query($overviewFilterQuery)) ?>">Close Details</a>
            </div>
            <h4>Balances by Leave Type</h4>
            <div class="table-card"><table><thead><tr><th>Leave Type</th><th>Policy Since</th><th>Credit</th><th>Adjustments</th><th>Approved</th><th>Pending</th><th>Available</th><th>Projected</th></tr></thead><tbody>
                <?php foreach ($balancesByEmployee[$detailEmployeeId] ?? [] as $balance): ?><tr>
                    <td><strong><?= h($balance['leave_type_name']) ?></strong><?= !$balance['active'] ? ' <span class="pill pill-gray">Inactive</span>' : '' ?></td>
                    <td><?= $balance['effective_year'] !== null ? (int)$balance['effective_year'] : 'Not configured' ?></td>
                    <?php foreach (['annual_minutes', 'adjustment_minutes', 'used_minutes', 'pending_minutes', 'available_minutes', 'projected_minutes'] as $field): ?><td class="leave-balance-value<?= in_array($field, ['available_minutes', 'projected_minutes'], true) && $balance[$field] < 0 ? ' is-negative' : '' ?>" data-leave-minutes="<?= (int)$balance[$field] ?>"><?= h(formatLeaveMinutes((int)$balance[$field])) ?></td><?php endforeach; ?>
                </tr><?php endforeach; ?>
            </tbody></table></div>
            <div class="leave-balance-detail-columns">
                <section><h4>Immutable Adjustments</h4><div class="table-card"><table><thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Remarks</th></tr></thead><tbody>
                    <?php if ($detailAdjustments): foreach ($detailAdjustments as $adjustment): ?><tr><td><?= h(formatEmployeeDate((string)$adjustment['effective_date'])) ?></td><td><?= h($adjustment['leave_type_name']) ?></td><td class="leave-balance-value<?= (int)$adjustment['adjustment_minutes'] < 0 ? ' is-negative' : '' ?>" data-leave-minutes="<?= (int)$adjustment['adjustment_minutes'] ?>"><?= h(formatLeaveMinutes((int)$adjustment['adjustment_minutes'])) ?></td><td><?= h($adjustment['remarks']) ?></td></tr><?php endforeach; else: ?><tr><td colspan="4" class="table-empty-cell">No adjustments for this period.</td></tr><?php endif; ?>
                </tbody></table></div></section>
                <section><h4>Leave Usage</h4><div class="table-card"><table><thead><tr><th>Request</th><th>Type</th><th>Date Range</th><th>Amount</th><th>Status</th></tr></thead><tbody>
                    <?php if ($detailUsage): foreach ($detailUsage as $used): ?><tr><td>#<?= (int)$used['id'] ?></td><td><?= h($used['leave_type_name']) ?></td><td><?= h(formatEmployeeDate((string)$used['start_date'])) ?> – <?= h(formatEmployeeDate((string)$used['end_date'])) ?></td><td class="leave-balance-value" data-leave-minutes="<?= (int)$used['period_minutes'] ?>"><?= h(formatLeaveMinutes((int)$used['period_minutes'])) ?></td><td><?= leaveStatusPill((string)$used['status']) ?></td></tr><?php endforeach; else: ?><tr><td colspan="5" class="table-empty-cell">No approved or pending usage for this period.</td></tr><?php endif; ?>
                </tbody></table></div></section>
            </div>
        </article>
    <?php endif; ?>

<?php elseif ($tab === 'policies'): ?>
    <article class="content-card leave-policy-workspace">
        <div class="card-header"><div><h3>Annual Entitlement Policies · <?= $year ?></h3><p class="muted">The latest policy at or before this year remains effective until replaced.</p></div></div>
        <div class="table-card"><table><thead><tr><th>Leave Type</th><th>Status</th><th>Effective Source</th><th>Current Credit</th><th>Set Credit for <?= $year ?></th></tr></thead><tbody>
            <?php foreach ($policyRows as $policy): ?><tr>
                <td><strong><?= h($policy['name']) ?></strong></td><td><?= (int)$policy['active'] === 1 ? '<span class="pill pill-green">Active</span>' : '<span class="pill pill-gray">Inactive</span>' ?></td>
                <td><?= $policy['effective_year'] !== null ? 'Effective since ' . (int)$policy['effective_year'] : '<span class="muted">Not configured</span>' ?></td>
                <td><span class="leave-balance-value" data-leave-minutes="<?= (int)($policy['annual_minutes'] ?? 0) ?>"><?= h(formatLeaveMinutes((int)($policy['annual_minutes'] ?? 0))) ?></span> days</td>
                <td><form method="post" class="leave-policy-form"><input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="save_policy"><input type="hidden" name="tab" value="policies"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="leave_type_id" value="<?= (int)$policy['id'] ?>"><input type="number" name="amount" min="0" step="0.5" value="<?= h(formatLeaveMinutes((int)($policy['annual_minutes'] ?? 0))) ?>" required><select name="unit"><option value="days">Days</option><option value="hours">Hours</option></select><button class="btn btn-secondary btn-sm" type="submit">Save</button></form></td>
            </tr><?php endforeach; ?>
        </tbody></table></div>
    </article>

<?php else: ?>
    <article class="content-card leave-adjustment-filter-card">
        <form method="get" class="form-grid-5 leave-balance-filter"><input type="hidden" name="tab" value="adjustments"><input type="hidden" name="year" value="<?= $year ?>">
            <div><label>Search</label><input type="search" name="search" value="<?= h($searchFilter) ?>" placeholder="Employee or remarks"></div>
            <div><label>Company</label><select name="company"><option value="">All Companies</option><?php foreach ($companies as $company): ?><option value="<?= h($company) ?>" <?= $companyFilter === $company ? 'selected' : '' ?>><?= h($company) ?></option><?php endforeach; ?></select></div>
            <div><label>Employee</label><select name="employee_id"><option value="0">All Employees</option><?php foreach ($employees as $employee): ?><option value="<?= (int)$employee['id'] ?>" <?= $employeeFilter === (int)$employee['id'] ? 'selected' : '' ?>><?= h($employee['name']) ?></option><?php endforeach; ?></select></div>
            <div><label>Leave Type</label><select name="leave_type_id"><option value="0">All Leave Types</option><?php foreach ($policyRows as $policy): ?><option value="<?= (int)$policy['id'] ?>" <?= $typeFilter === (int)$policy['id'] ? 'selected' : '' ?>><?= h($policy['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-action-cell"><button class="btn btn-secondary" type="submit">Apply Filters</button></div>
        </form>
    </article>
    <div class="leave-adjustment-workspace">
        <article class="content-card leave-adjustment-form-card">
            <div class="card-header"><div><h3>Record Adjustment</h3><p class="muted">Entries are permanent. Correct mistakes with an opposite entry.</p></div></div>
            <form method="post" class="form-layout">
                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="add_adjustment"><input type="hidden" name="tab" value="adjustments"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="company" value="<?= h($companyFilter) ?>"><input type="hidden" name="search" value="<?= h($searchFilter) ?>">
                <div><label class="required">Employee</label><select name="employee_id" required><option value="">Select active employee</option><?php foreach ($activeEmployees as $employee): ?><option value="<?= (int)$employee['id'] ?>" <?= $employeeFilter === (int)$employee['id'] ? 'selected' : '' ?>><?= h($employee['name'] . ' · ' . $employee['company']) ?></option><?php endforeach; ?></select></div>
                <div><label class="required">Leave Type</label><select name="leave_type_id" required><option value="">Select leave type</option><?php foreach ($policyRows as $policy): ?><option value="<?= (int)$policy['id'] ?>" <?= $typeFilter === (int)$policy['id'] ? 'selected' : '' ?>><?= h($policy['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-grid-2"><div><label class="required">Direction</label><select name="direction"><option value="credit">Credit</option><option value="deduct">Deduct</option></select></div><div><label class="required">Effective Date</label><input type="date" name="effective_date" value="<?= h($year . '-' . ($year === (int)date('Y') ? date('m-d') : '01-01')) ?>" required></div></div>
                <div class="form-grid-2"><div><label class="required">Amount</label><input type="number" name="amount" min="0.5" step="0.5" required></div><div><label>Unit</label><select name="unit"><option value="days">Days</option><option value="hours">Hours</option></select></div></div>
                <div><label class="required">Remarks</label><textarea name="remarks" rows="3" maxlength="1000" placeholder="Reason for this adjustment" required></textarea></div>
                <button type="submit" class="btn btn-primary">Record Immutable Adjustment</button>
            </form>
        </article>
        <article class="content-card leave-adjustment-ledger-card">
            <div class="card-header"><div><h3>Adjustment Ledger</h3><p class="muted"><?= count($adjustmentLedger) ?> immutable entries in <?= $year ?></p></div><div class="leave-unit-toggle" role="group" aria-label="Adjustment display unit"><button type="button" class="is-active" data-leave-unit="days">Days</button><button type="button" data-leave-unit="hours">Hours</button></div></div>
            <div class="table-card leave-adjustment-ledger"><table><thead><tr><th>Effective Date</th><th>Employee</th><th>Company</th><th>Leave Type</th><th>Amount</th><th>Remarks</th><th>Recorded By</th><th>Recorded At</th></tr></thead><tbody>
                <?php if ($adjustmentLedger): foreach ($adjustmentLedger as $entry): ?><tr><td><?= h(formatEmployeeDate((string)$entry['effective_date'])) ?></td><td><strong><?= h($entry['employee_name']) ?></strong><?= (int)$entry['active'] === 0 ? '<span class="report-inactive-label">Inactive</span>' : '' ?></td><td><?= h($entry['company']) ?></td><td><?= h($entry['leave_type_name']) ?></td><td class="leave-balance-value<?= (int)$entry['adjustment_minutes'] < 0 ? ' is-negative' : '' ?>" data-leave-minutes="<?= (int)$entry['adjustment_minutes'] ?>"><?= h(formatLeaveMinutes((int)$entry['adjustment_minutes'])) ?></td><td><?= h($entry['remarks']) ?></td><td><?= h($entry['admin_name']) ?></td><td><?= h(formatEmployeeDate(substr((string)$entry['created_at'], 0, 10))) ?> <?= h(substr((string)$entry['created_at'], 11, 5)) ?></td></tr><?php endforeach; else: ?><tr><td colspan="8" class="table-empty-cell">No adjustments match the selected filters.</td></tr><?php endif; ?>
            </tbody></table></div>
        </article>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
