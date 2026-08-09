<?php
$pageTitle = $pageTitle ?? 'Employee';
$activePage = $activePage ?? '';
$layoutCompanyName = isset($pdo) ? getSetting($pdo, 'company_name', 'EAMS Demo Company') : 'EAMS Demo Company';
$layoutCompanyLogo = isset($pdo) ? resolveCompanyLogoUrl(getSetting($pdo, 'company_logo', ''), '../') : '';
$layoutEmployeeName = (string)($_SESSION['user_name'] ?? 'Employee');
$layoutEmployeeCompany = (string)($_SESSION['company'] ?? '');
$layoutEmployeeId = (int)($_SESSION['user_id'] ?? 0);
$employeeCalendarSection = $employeeCalendarSection ?? 'calendar';
$flashSuccess = getFlash('success');
$flashError = getFlash('error');

$notificationCountStmt = $pdo->prepare('SELECT COUNT(*) FROM employee_notifications WHERE employee_id = ? AND is_read = 0');
$notificationCountStmt->execute([$layoutEmployeeId]);
$layoutUnreadNotificationCount = (int)$notificationCountStmt->fetchColumn();

$notificationListStmt = $pdo->prepare('SELECT id, title, message, is_read, created_at, read_at
    FROM employee_notifications WHERE employee_id = ? ORDER BY created_at DESC, id DESC LIMIT 10');
$notificationListStmt->execute([$layoutEmployeeId]);
$layoutNotifications = $notificationListStmt->fetchAll();

$isEmployeeActive = static function (string $page) use ($activePage): string {
    return $activePage === $page ? 'is-active' : '';
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
    <?php if ($flashSuccess !== ''): ?><div id="php-flash-success" hidden><?= h($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError !== ''): ?><div id="php-flash-error" hidden><?= h($flashError) ?></div><?php endif; ?>

    <div class="employee-shell portal-shell" id="employee-shell" data-portal-shell>
        <aside class="employee-sidebar portal-sidebar" id="employee-sidebar" aria-label="Employee navigation" data-portal-sidebar>
            <div class="sidebar-brand">
                <?php if ($layoutCompanyLogo !== ''): ?>
                    <img src="<?= h($layoutCompanyLogo) ?>" alt="<?= h($layoutCompanyName) ?> logo" class="sidebar-brand-logo">
                <?php else: ?>
                    <div class="sidebar-brand-logo fallback">EA</div>
                <?php endif; ?>
                <div class="sidebar-brand-text">
                    <strong><?= h($layoutCompanyName) ?></strong>
                    <span>Employee Portal</span>
                </div>
            </div>

            <nav class="employee-sidebar-nav portal-sidebar-nav" data-portal-sidebar-nav>
                <a class="employee-nav-item portal-nav-item <?= $isEmployeeActive('dashboard') ?>" href="dashboard.php">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M3 11.5 12 4l9 7.5v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>
                    <span>Dashboard</span>
                </a>
                <div class="employee-nav-group portal-nav-group<?= $activePage === 'calendar' ? ' is-open' : '' ?>" data-employee-nav-group>
                    <div class="employee-nav-group-row">
                        <a class="employee-nav-item portal-nav-item employee-nav-parent <?= $isEmployeeActive('calendar') ?>" href="calendar.php">
                            <svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/></svg>
                            <span>Calendar</span>
                        </a>
                        <button
                            type="button"
                            class="employee-nav-expand"
                            aria-label="Toggle Calendar submenu"
                            aria-expanded="<?= $activePage === 'calendar' ? 'true' : 'false' ?>"
                            aria-controls="employee-calendar-submenu"
                            data-employee-nav-toggle
                        >
                            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m8 10 4 4 4-4"/></svg>
                        </button>
                    </div>
                    <div class="employee-nav-submenu portal-nav-submenu" id="employee-calendar-submenu">
                        <a class="employee-nav-subitem portal-nav-subitem<?= $activePage === 'calendar' && $employeeCalendarSection === 'calendar' ? ' is-active' : '' ?>" href="calendar.php"<?= $activePage === 'calendar' && $employeeCalendarSection === 'calendar' ? ' aria-current="page"' : '' ?>>
                            <span aria-hidden="true"></span>Calendar View
                        </a>
                        <a class="employee-nav-subitem portal-nav-subitem<?= $activePage === 'calendar' && $employeeCalendarSection === 'balance' ? ' is-active' : '' ?>" href="calendar.php?view=balance"<?= $activePage === 'calendar' && $employeeCalendarSection === 'balance' ? ' aria-current="page"' : '' ?>>
                            <span aria-hidden="true"></span>Leave Balance
                        </a>
                    </div>
                </div>
                <a class="employee-nav-item portal-nav-item <?= $isEmployeeActive('history') ?>" href="history.php">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
                    <span>Attendance History</span>
                </a>
                <a class="employee-nav-item portal-nav-item <?= $isEmployeeActive('announcements') ?>" href="announcements.php">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 13V9l12-5v14L4 13Zm0 0 2 7h4l-2-6M18 8a5 5 0 0 1 0 6"/></svg>
                    <span>Announcements</span>
                </a>
                <a class="employee-nav-item portal-nav-item <?= $isEmployeeActive('profile') ?>" href="profile.php"<?= $activePage === 'profile' ? ' aria-current="page"' : '' ?>>
                    <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                    <span>My Profile</span>
                </a>
                <a class="employee-nav-item portal-nav-item employee-nav-logout portal-nav-logout" href="../pages/logout.php">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M10 4H4v16h6M14 8l4 4-4 4M8 12h10"/></svg>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <div class="employee-backdrop portal-backdrop" id="employee-backdrop" data-portal-backdrop aria-hidden="true"></div>

        <div class="employee-main portal-main">
            <header class="employee-topbar">
                <div class="employee-topbar-left">
                    <button class="icon-btn employee-menu-toggle portal-sidebar-toggle" id="employee-sidebar-toggle" type="button" aria-label="Hide sidebar" aria-controls="employee-sidebar" aria-expanded="true" data-portal-sidebar-toggle>
                        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    </button>
                    <div>
                        <h1><?= h($pageTitle) ?></h1>
                        <span><?= h($layoutEmployeeCompany) ?></span>
                    </div>
                </div>
                <div class="employee-topbar-right">
                    <div class="employee-topbar-date"><?= h(date('F j, Y')) ?></div>
                    <button
                        class="icon-btn notification-bell<?= $layoutUnreadNotificationCount > 0 ? ' has-dot' : '' ?>"
                        id="notification-bell"
                        type="button"
                        aria-label="Open notifications<?= $layoutUnreadNotificationCount > 0 ? ', ' . $layoutUnreadNotificationCount . ' unread' : '' ?>"
                        aria-haspopup="dialog"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                        <span class="notification-badge" id="notification-badge"<?= $layoutUnreadNotificationCount === 0 ? ' hidden' : '' ?>><?= $layoutUnreadNotificationCount ?></span>
                    </button>
                    <a class="employee-identity" href="profile.php" aria-label="Open my profile">
                        <span class="employee-avatar" aria-hidden="true"><?= h(strtoupper(substr($layoutEmployeeName, 0, 1))) ?></span>
                        <span><?= h($layoutEmployeeName) ?></span>
                    </a>
                </div>
            </header>

            <main class="employee-content">
