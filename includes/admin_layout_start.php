<?php
$pageTitle = $pageTitle ?? 'Admin';
$activePage = $activePage ?? '';
$activeSubPage = $activeSubPage ?? '';
$layoutCompanyName = $companyName ?? (isset($pdo) ? getSetting($pdo, 'company_name', 'EAMS Demo Company') : 'EAMS Demo Company');
$layoutCompanyLogo = isset($pdo) ? resolveCompanyLogoUrl(getSetting($pdo, 'company_logo', ''), '../') : '';
$adminName = $_SESSION['user_name'] ?? 'Admin';
$currentDateLabel = date('D, M d, Y'); // DATE FORMAT: Mon, Jan 01, 2024
$flashSuccess = getFlash('success');
$flashError   = getFlash('error');

$isActive = static function (string $page) use ($activePage): string {
    return $activePage === $page ? 'is-active' : '';
};
$isSubActive = static function (string $subPage) use ($activeSubPage): string {
    return $activeSubPage === $subPage ? 'is-active' : '';
};
$isGroupActive = static function (array $subPages, array $pages = []) use ($activeSubPage, $activePage): bool {
    return in_array($activeSubPage, $subPages, true) || in_array($activePage, $pages, true);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> | <?= h($layoutCompanyName) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= (int)filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body>
    <?php if ($flashSuccess !== ''): ?>
        <div id="php-flash-success" hidden><?= h($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div id="php-flash-error" hidden><?= h($flashError) ?></div>
    <?php endif; ?>

    <div class="admin-shell portal-shell" id="admin-shell" data-portal-shell>
        <aside class="admin-sidebar portal-sidebar" id="admin-sidebar" aria-label="Admin navigation" data-portal-sidebar>
            <div class="sidebar-brand">
                <?php if ($layoutCompanyLogo !== ''): ?>
                    <img src="<?= h($layoutCompanyLogo) ?>" alt="<?= h($layoutCompanyName) ?> logo" class="sidebar-brand-logo">
                <?php else: ?>
                    <div class="sidebar-brand-logo fallback">EA</div>
                <?php endif; ?>
                <div class="sidebar-brand-text">
                    <strong><?= h($layoutCompanyName) ?></strong>
                    <span>Admin Panel</span>
                </div>
            </div>

            <nav class="sidebar-nav portal-sidebar-nav" data-portal-sidebar-nav>
                <a class="nav-item portal-nav-item <?= $isActive('dashboard') ?>" href="dashboard.php"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M3 11.5 12 4l9 7.5v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg><span>Dashboard</span></a>

                <?php $employeesGroupOpen = $isGroupActive(['employee_list', 'add_employee']); ?>
                <div class="nav-group portal-nav-group<?= $employeesGroupOpen ? ' is-open' : '' ?>" data-admin-nav-group data-nav-key="employees" data-active="<?= $employeesGroupOpen ? 'true' : 'false' ?>">
                    <button class="nav-group-label portal-nav-item portal-nav-group-label" type="button" aria-expanded="<?= $employeesGroupOpen ? 'true' : 'false' ?>" aria-controls="admin-nav-employees" data-admin-nav-toggle><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg><span>Employees</span><svg class="nav-chevron" aria-hidden="true" viewBox="0 0 24 24"><path d="m8 10 4 4 4-4"/></svg></button>
                    <div class="nav-submenu portal-nav-submenu" id="admin-nav-employees">
                        <a class="nav-sub-item portal-nav-subitem <?= $isSubActive('employee_list') ?>" href="employees.php">Employee List</a>
                        <a class="nav-sub-item portal-nav-subitem <?= $isSubActive('add_employee') ?>" href="manage_employee.php">Add Employee</a>
                    </div>
                </div>

                <?php $attendanceGroupOpen = $isGroupActive(['today_attendance', 'attendance_corrections', 'shift_schedules']); ?>
                <div class="nav-group portal-nav-group<?= $attendanceGroupOpen ? ' is-open' : '' ?>" data-admin-nav-group data-nav-key="attendance" data-active="<?= $attendanceGroupOpen ? 'true' : 'false' ?>">
                    <button class="nav-group-label portal-nav-item portal-nav-group-label" type="button" aria-expanded="<?= $attendanceGroupOpen ? 'true' : 'false' ?>" aria-controls="admin-nav-attendance" data-admin-nav-toggle><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span>Attendance</span><svg class="nav-chevron" aria-hidden="true" viewBox="0 0 24 24"><path d="m8 10 4 4 4-4"/></svg></button>
                    <div class="nav-submenu portal-nav-submenu" id="admin-nav-attendance">
                        <a class="nav-sub-item portal-nav-subitem <?= $isSubActive('today_attendance') ?>" href="attendance.php">Today's Attendance</a>
                        <a class="nav-sub-item portal-nav-subitem <?= $isSubActive('attendance_corrections') ?>" href="attendance_corrections.php">Correction Requests</a>
                        <a class="nav-sub-item portal-nav-subitem <?= $isSubActive('shift_schedules') ?>" href="shifts.php">Shift Schedules</a>
                    </div>
                </div>

                <?php $reportsGroupOpen = $isGroupActive(['date_range_reports', 'employee_reports', 'payroll_export'], ['reports']); ?>
                <div class="nav-group portal-nav-group<?= $reportsGroupOpen ? ' is-open' : '' ?>" data-admin-nav-group data-nav-key="reports" data-active="<?= $reportsGroupOpen ? 'true' : 'false' ?>">
                    <button class="nav-group-label portal-nav-item portal-nav-group-label" type="button" aria-expanded="<?= $reportsGroupOpen ? 'true' : 'false' ?>" aria-controls="admin-nav-reports" data-admin-nav-toggle><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg><span>Reports</span><svg class="nav-chevron" aria-hidden="true" viewBox="0 0 24 24"><path d="m8 10 4 4 4-4"/></svg></button>
                    <div class="nav-submenu portal-nav-submenu" id="admin-nav-reports">
                        <a class="nav-sub-item portal-nav-subitem <?= $isSubActive('date_range_reports') ?>" href="reports.php?type=date_range">Attendance by Date Range</a>
                        <a class="nav-sub-item portal-nav-subitem <?= $isSubActive('employee_reports') ?>" href="reports.php?type=employee_history">Employee Attendance History</a>
                        <a class="nav-sub-item portal-nav-subitem <?= $isSubActive('payroll_export') ?>" href="payroll_export.php">Payroll Export</a>
                    </div>
                </div>

                <?php $leaveGroupOpen = $isGroupActive(['leave_management', 'team_availability', 'leave_balances', 'leave_types', 'holidays']); ?>
                <div class="nav-group portal-nav-group<?= $leaveGroupOpen ? ' is-open' : '' ?>" data-admin-nav-group data-nav-key="leave" data-active="<?= $leaveGroupOpen ? 'true' : 'false' ?>">
                    <button class="nav-group-label portal-nav-item portal-nav-group-label" type="button" aria-expanded="<?= $leaveGroupOpen ? 'true' : 'false' ?>" aria-controls="admin-nav-leave" data-admin-nav-toggle><svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/></svg><span>Leave</span><svg class="nav-chevron" aria-hidden="true" viewBox="0 0 24 24"><path d="m8 10 4 4 4-4"/></svg></button>
                    <div class="nav-submenu portal-nav-submenu" id="admin-nav-leave">
                        <a class="nav-sub-item portal-nav-subitem <?= $isSubActive('leave_management') ?>" href="leave_management.php">Leave Requests</a>
                        <a class="nav-sub-item portal-nav-subitem <?= $isSubActive('team_availability') ?>" href="team_availability.php">Team Availability</a>
                        <a class="nav-sub-item portal-nav-subitem <?= $isSubActive('leave_balances') ?>" href="leave_balances.php">Leave Balances</a>
                        <a class="nav-sub-item portal-nav-subitem <?= $isSubActive('leave_types') ?>" href="leave_types.php">Leave Types</a>
                        <a class="nav-sub-item portal-nav-subitem <?= $isSubActive('holidays') ?>" href="holiday_management.php">Holidays</a>
                    </div>
                </div>

                <?php $communicationGroupOpen = $isGroupActive([], ['announcements']); ?>
                <div class="nav-group portal-nav-group<?= $communicationGroupOpen ? ' is-open' : '' ?>" data-admin-nav-group data-nav-key="communication" data-active="<?= $communicationGroupOpen ? 'true' : 'false' ?>">
                    <button class="nav-group-label portal-nav-item portal-nav-group-label" type="button" aria-expanded="<?= $communicationGroupOpen ? 'true' : 'false' ?>" aria-controls="admin-nav-communication" data-admin-nav-toggle><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 13V9l12-5v14L4 13Zm0 0 2 7h4l-2-6M18 8a5 5 0 0 1 0 6"/></svg><span>Communication</span><svg class="nav-chevron" aria-hidden="true" viewBox="0 0 24 24"><path d="m8 10 4 4 4-4"/></svg></button>
                    <div class="nav-submenu portal-nav-submenu" id="admin-nav-communication"><a class="nav-sub-item portal-nav-subitem <?= $isActive('announcements') ?>" href="announcements.php">Announcements</a></div>
                </div>

                <?php $systemGroupOpen = $isGroupActive(['system_settings', 'audit_logs'], ['settings']); ?>
                <div class="nav-group portal-nav-group<?= $systemGroupOpen ? ' is-open' : '' ?>" data-admin-nav-group data-nav-key="system" data-active="<?= $systemGroupOpen ? 'true' : 'false' ?>">
                    <button class="nav-group-label portal-nav-item portal-nav-group-label" type="button" aria-expanded="<?= $systemGroupOpen ? 'true' : 'false' ?>" aria-controls="admin-nav-system" data-admin-nav-toggle><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21H9.6v-.09A1.7 1.7 0 0 0 8.5 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.6-1H3v-4h.09A1.7 1.7 0 0 0 4.6 8.5a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3h4v.09A1.7 1.7 0 0 0 15.5 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.15.37.6 1 1.5 1.05H21v4h-.09A1.7 1.7 0 0 0 19.4 15Z"/></svg><span>System</span><svg class="nav-chevron" aria-hidden="true" viewBox="0 0 24 24"><path d="m8 10 4 4 4-4"/></svg></button>
                    <div class="nav-submenu portal-nav-submenu" id="admin-nav-system">
                        <a class="nav-sub-item portal-nav-subitem <?= $isSubActive('system_settings') ?>" href="settings.php">Settings</a>
                        <a class="nav-sub-item portal-nav-subitem <?= $isSubActive('audit_logs') ?>" href="audit_logs.php">Audit Log</a>
                    </div>
                </div>

                <a class="nav-item portal-nav-item nav-item-logout portal-nav-logout" href="../pages/logout.php"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M10 4H4v16h6M14 8l4 4-4 4M8 12h10"/></svg><span>Logout</span></a>
            </nav>
        </aside>

        <div class="admin-backdrop portal-backdrop" id="admin-backdrop" data-portal-backdrop aria-hidden="true"></div>

        <div class="admin-main portal-main">
            <header class="topbar">
                <div class="topbar-left">
                    <button class="icon-btn menu-toggle portal-sidebar-toggle" id="sidebar-toggle" type="button" aria-label="Hide sidebar" aria-controls="admin-sidebar" aria-expanded="true" data-portal-sidebar-toggle><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
                    <div class="topbar-company">

                        <div>
                            <strong><?= h($layoutCompanyName) ?></strong>

                        </div>
                    </div>
                </div>

                <div class="topbar-right">
                   <div class="topbar-date"><?= h($currentDateLabel) ?></div>
                    <div class="profile-menu-wrap">
                        <button class="profile-btn" id="profile-menu-toggle" type="button" aria-haspopup="true" aria-expanded="false">
                            <span class="avatar">👤</span>

                        </button>
                        <div class="profile-dropdown" id="profile-menu">
                            <a href="settings.php">Settings</a>
                            <a href="../pages/logout.php">Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <main class="admin-content" data-searchable>
