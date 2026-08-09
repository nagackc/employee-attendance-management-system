<?php
declare(strict_types=1);

const DEFAULT_TIMEZONE = 'America/New_York';
const DEFAULT_WORK_START = '08:00';
const DEFAULT_WORK_END = '17:00';
const DEFAULT_GRACE_MINUTES = 15;
const MAX_OPEN_SESSION_SECONDS = 129600; // 36 hours; older sessions need an admin correction.
const LEAVE_MINUTES_PER_DAY = 480;
const COMPANY_LOGO_MAX_BYTES = 2097152;

date_default_timezone_set(DEFAULT_TIMEZONE);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (PHP_SAPI !== 'cli') {
    set_exception_handler(static function (Throwable $e): void {
        error_log('Unhandled EAMS error: ' . $e->getMessage());
        if (!headers_sent()) {
            http_response_code(500);
        }
        exit('An unexpected error occurred. Please try again later.');
    });
}

function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function isAdmin(): bool {
    return !empty($_SESSION['role']) && strtolower(trim((string)$_SESSION['role'])) === 'admin';
}

function h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatDate(string $date): string {
    return date('Y-m-d', strtotime($date));
}

function formatDateTime(string $datetime): string {
    return date('Y-m-d H:i:s', strtotime($datetime));
}

function formatEmployeeDate(?string $date): string {
    if ($date === null || trim($date) === '') {
        return '—';
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
    return $parsed === false ? '—' : $parsed->format('F j, Y');
}

function formatEmployeeTime(?string $datetime, string $timezone = DEFAULT_TIMEZONE): string {
    if ($datetime === null || trim($datetime) === '') {
        return '—';
    }
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        $timezone = DEFAULT_TIMEZONE;
    }
    $parsed = parseDatabaseDateTime($datetime, $timezone);
    return $parsed === null ? '—' : $parsed->format('H:i');
}

function formatDurationSeconds(int $seconds): string {
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    return $hours > 0
        ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds % 60)
        : sprintf('%02d:%02d', $minutes, $seconds % 60);
}

function formatHours(mixed $hours): string {
    if ($hours === null || $hours === '') {
        return '0.00';
    }
    return number_format((float)$hours, 2, '.', '');
}

function formatMinutesDuration(int $minutes): string {
    $minutes = max(0, $minutes);
    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;
    if ($hours > 0 && $remainingMinutes > 0) {
        return $hours . 'h ' . $remainingMinutes . 'm';
    }
    return $hours > 0 ? $hours . 'h' : $remainingMinutes . 'm';
}

function validateEmployeeContactProfile(string $email, string $phoneNumber, string $address): array {
    $email = strtolower(trim($email));
    $phoneNumber = trim($phoneNumber);
    $address = trim($address);
    $errors = [];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        $errors[] = 'Enter a valid email address of 150 characters or fewer.';
    }
    if ($phoneNumber === '' || mb_strlen($phoneNumber) > 50) {
        $errors[] = 'Enter a phone number of 50 characters or fewer.';
    }
    if ($address === '' || mb_strlen($address) > 1000) {
        $errors[] = 'Enter an address of 1,000 characters or fewer.';
    }
    return [
        'valid' => $errors === [],
        'errors' => $errors,
        'email' => $email,
        'phone_number' => $phoneNumber,
        'address' => $address,
    ];
}

function validateEmployeePasswordChange(
    string $storedPasswordHash,
    string $currentPassword,
    string $newPassword,
    string $confirmPassword
): array {
    $errors = [];
    if ($currentPassword === '' || !password_verify($currentPassword, $storedPasswordHash)) {
        $errors[] = 'The current password is incorrect.';
    }
    if (strlen($newPassword) < 8 || strlen($newPassword) > 255) {
        $errors[] = 'The new password must be between 8 and 255 characters.';
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'The new password and confirmation do not match.';
    }
    if ($newPassword !== '' && password_verify($newPassword, $storedPasswordHash)) {
        $errors[] = 'Choose a password different from the current password.';
    }
    return ['valid' => $errors === [], 'errors' => $errors];
}

function formatAuditAction(string $action): string {
    return ucwords(str_replace('_', ' ', trim($action)));
}

function decodeAuditSnapshot(?string $json): array {
    if ($json === null || trim($json) === '') {
        return [];
    }
    try {
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    } catch (JsonException) {
        return [];
    }
}

function redactAuditSnapshotValue(mixed $value, string $key = ''): mixed {
    if ($key !== '' && preg_match('/password|passwd|token|secret|credential|api[_-]?key|hash/i', $key) === 1) {
        return '[redacted]';
    }
    if (!is_array($value)) {
        return $value;
    }
    $redacted = [];
    foreach ($value as $childKey => $childValue) {
        $redacted[$childKey] = redactAuditSnapshotValue($childValue, (string)$childKey);
    }
    return $redacted;
}

function flattenAuditSnapshot(array $snapshot, string $prefix = ''): array {
    $flattened = [];
    foreach ($snapshot as $key => $value) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
        $value = redactAuditSnapshotValue($value, (string)$key);
        if (is_array($value) && !array_is_list($value)) {
            $flattened += flattenAuditSnapshot($value, $path);
        } else {
            $flattened[$path] = $value;
        }
    }
    return $flattened;
}

function formatAuditValue(mixed $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';
    }
    return (string)$value;
}

function buildAuditChangeRows(?string $oldJson, ?string $newJson): array {
    $oldValues = flattenAuditSnapshot(decodeAuditSnapshot($oldJson));
    $newValues = flattenAuditSnapshot(decodeAuditSnapshot($newJson));
    $keys = array_values(array_unique(array_merge(array_keys($oldValues), array_keys($newValues))));
    sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
    $changes = [];
    foreach ($keys as $key) {
        $hasOld = array_key_exists($key, $oldValues);
        $hasNew = array_key_exists($key, $newValues);
        $oldValue = $hasOld ? $oldValues[$key] : null;
        $newValue = $hasNew ? $newValues[$key] : null;
        if ($hasOld && $hasNew && $oldValue === $newValue && $oldValue !== '[redacted]') {
            continue;
        }
        $changes[] = [
            'field' => formatAuditAction(str_replace('.', ' ', $key)),
            'before' => formatAuditValue($oldValue),
            'after' => formatAuditValue($newValue),
        ];
    }
    return $changes;
}

function statusPill(string $status): string {
    static $map = [
        'not_started'       => ['Not Started',  'pill-gray'],
        'currently_working' => ['Working',      'pill-blue'],
        'on_break'          => ['Lunch Break',  'pill-orange'],
        'on_quick_break'    => ['Quick Break',  'pill-purple'],
        'completed'         => ['Completed',    'pill-green'],
        'voided'            => ['Voided',       'pill-black'],
        'late'              => ['Late (Legacy)','pill-yellow'],
        'absent'            => ['Absent',       'pill-red'],
        'leave'             => ['On Leave',     'pill-purple'],
    ];
    [$label, $class] = $map[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'pill-gray'];
    return '<span class="pill ' . $class . '">' . h($label) . '</span>';
}

function latenessPill(bool $isLate): string {
    return $isLate
        ? '<span class="pill pill-yellow">Late</span>'
        : '<span class="pill pill-green">On time</span>';
}

function payrollDayTypePill(string $dayType): string {
    $class = match ($dayType) {
        'Holiday Work' => 'pill-purple',
        'Rest Day Work' => 'pill-blue',
        default => 'pill-gray',
    };
    return '<span class="pill ' . $class . '">' . h($dayType) . '</span>';
}

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function verifyCsrfToken(mixed $token): bool {
    $saved = (string)($_SESSION['csrf_token'] ?? '');
    return $saved !== '' && hash_equals($saved, (string)$token);
}

function destroyUserSession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function enforceSessionTimeout(string $redirectPath = '../pages/login.php'): void {
    if (empty($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
        return;
    }

    if (time() - (int)$_SESSION['last_activity'] > 1800) {
        destroyUserSession();
        redirect($redirectPath);
    }

    $_SESSION['last_activity'] = time();
}

function revalidateSessionUser(PDO $pdo): bool {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT id, first_name, last_name, company, role, active FROM employees WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || (int)$user['active'] !== 1) {
        return false;
    }

    $_SESSION['user_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
    $_SESSION['company'] = (string)$user['company'];
    $_SESSION['role'] = strtolower(trim((string)$user['role']));
    return true;
}

function requireLogin(PDO $pdo, string $redirectPath = '../pages/login.php'): void {
    if (!isLoggedIn()) {
        redirect($redirectPath);
    }

    enforceSessionTimeout($redirectPath);
    if (!revalidateSessionUser($pdo)) {
        destroyUserSession();
        redirect($redirectPath);
    }
}

function requireAdmin(PDO $pdo, string $redirectPath = '../pages/login.php'): void {
    requireLogin($pdo, $redirectPath);
    if (!isAdmin()) {
        redirect($redirectPath);
    }
}

function priorityPill(string $priority): string {
    static $map = [
        'normal'    => ['Normal',    'pill-gray'],
        'important' => ['Important', 'pill-yellow'],
        'urgent'    => ['Urgent',    'pill-red'],
    ];
    [$label, $class] = $map[$priority] ?? [ucfirst($priority), 'pill-gray'];
    return '<span class="pill ' . $class . '">' . h($label) . '</span>';
}

function announcementStatusPill(string $status): string {
    static $map = [
        'draft'     => ['Draft',     'pill-gray'],
        'published' => ['Published', 'pill-green'],
        'archived'  => ['Archived',  'pill-purple'],
    ];
    [$label, $class] = $map[$status] ?? [ucfirst($status), 'pill-gray'];
    return '<span class="pill ' . $class . '">' . h($label) . '</span>';
}

function setFlash(string $key, string $message): void {
    $_SESSION['flash'][$key] = $message;
}

function getFlash(string $key): string {
    $message = $_SESSION['flash'][$key] ?? '';
    unset($_SESSION['flash'][$key]);
    return (string)$message;
}

function getSetting(PDO $pdo, string $key, string $default = ''): string {
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . $key;
    if (!array_key_exists($cacheKey, $cache)) {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $cache[$cacheKey] = $row !== false ? (string)$row['setting_value'] : $default;
    }
    return $cache[$cacheKey] !== '' ? $cache[$cacheKey] : $default;
}

function resolveCompanyLogoUrl(string $storedPath, string $relativePrefix = '../'): string {
    $storedPath = trim($storedPath);
    if ($storedPath === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $storedPath) === 1) {
        return filter_var($storedPath, FILTER_VALIDATE_URL) !== false ? $storedPath : '';
    }

    if (str_starts_with($storedPath, '/') && !str_contains($storedPath, '..') && !str_contains($storedPath, '\\')) {
        return $storedPath;
    }

    if (preg_match('#^assets/uploads/[A-Za-z0-9][A-Za-z0-9._/-]*$#', $storedPath) !== 1
        || str_contains($storedPath, '..')
        || str_contains($storedPath, '\\')) {
        return '';
    }

    return rtrim($relativePrefix, '/') . '/' . $storedPath;
}

function validateCompanyLogoImageFile(string $path, int $reportedSize): array {
    if ($reportedSize <= 0 || $reportedSize > COMPANY_LOGO_MAX_BYTES || !is_file($path) || !is_readable($path)) {
        return ['valid' => false, 'extension' => '', 'error' => 'Logo images must be 2 MB or smaller.'];
    }

    $actualSize = filesize($path);
    if ($actualSize === false || $actualSize <= 0 || $actualSize > COMPANY_LOGO_MAX_BYTES) {
        return ['valid' => false, 'extension' => '', 'error' => 'Logo images must be 2 MB or smaller.'];
    }

    try {
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    } catch (Throwable $e) {
        error_log('Company logo MIME validation failed: ' . $e->getMessage());
        return ['valid' => false, 'extension' => '', 'error' => 'The selected logo could not be validated.'];
    }

    $allowedTypes = [
        'image/jpeg' => ['extension' => 'jpg', 'image_type' => IMAGETYPE_JPEG],
        'image/png' => ['extension' => 'png', 'image_type' => IMAGETYPE_PNG],
        'image/webp' => ['extension' => 'webp', 'image_type' => IMAGETYPE_WEBP],
    ];
    if (!is_string($mimeType) || !isset($allowedTypes[$mimeType])) {
        return ['valid' => false, 'extension' => '', 'error' => 'Choose a JPG, PNG, or WebP logo image.'];
    }

    $imageInfo = @getimagesize($path);
    if ($imageInfo === false || (int)($imageInfo[2] ?? 0) !== $allowedTypes[$mimeType]['image_type']) {
        return ['valid' => false, 'extension' => '', 'error' => 'The selected file is not a valid logo image.'];
    }

    return ['valid' => true, 'extension' => $allowedTypes[$mimeType]['extension'], 'error' => ''];
}

function isManagedCompanyLogoPath(string $storedPath): bool {
    return preg_match('#^assets/uploads/company-logo-[a-f0-9]{32}\.(?:jpg|png|webp)$#', trim($storedPath)) === 1;
}

function deleteManagedCompanyLogo(string $storedPath, string $uploadsDirectory): void {
    if (!isManagedCompanyLogoPath($storedPath)) {
        return;
    }

    $uploadsRoot = realpath($uploadsDirectory);
    if ($uploadsRoot === false) {
        return;
    }

    $target = $uploadsRoot . DIRECTORY_SEPARATOR . basename($storedPath);
    if (!is_file($target)) {
        return;
    }
    if (!unlink($target)) {
        error_log('Unable to remove the previous managed company logo: ' . $target);
    }
}

function getAttendanceSchedule(PDO $pdo): array {
    $timezone = getSetting($pdo, 'timezone', DEFAULT_TIMEZONE);
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        $timezone = DEFAULT_TIMEZONE;
    }

    $start = getSetting($pdo, 'work_start_time', DEFAULT_WORK_START);
    $end = getSetting($pdo, 'work_end_time', DEFAULT_WORK_END);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start)) {
        $start = DEFAULT_WORK_START;
    }
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end)) {
        $end = DEFAULT_WORK_END;
    }

    $grace = (int)getSetting($pdo, 'grace_period_minutes', (string)DEFAULT_GRACE_MINUTES);
    $grace = max(0, min(720, $grace));
    return [
        'timezone' => $timezone,
        'work_start_time' => $start,
        'work_end_time' => $end,
        'grace_period_minutes' => $grace,
    ];
}

function normalizeShiftWorkDays(mixed $workDays): array {
    $values = is_array($workDays) ? $workDays : explode(',', (string)$workDays);
    $normalized = [];
    foreach ($values as $value) {
        $day = (int)$value;
        if ($day >= 1 && $day <= 7) {
            $normalized[$day] = $day;
        }
    }
    ksort($normalized);
    return array_values($normalized);
}

function formatShiftWorkDays(mixed $workDays): string {
    $days = normalizeShiftWorkDays($workDays);
    $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    if ($days === [1, 2, 3, 4, 5]) {
        return 'Monday–Friday';
    }
    return $days ? implode(', ', array_map(static fn(int $day): string => $labels[$day], $days)) : 'No workdays';
}

function getEmployeeScheduleForDate(PDO $pdo, int $employeeId, string $date): array {
    static $cache = [];
    $fallback = getAttendanceSchedule($pdo);
    $fallback += [
        'shift_id' => null,
        'shift_name' => 'Default Schedule',
        'work_days' => [1, 2, 3, 4, 5],
        'source' => 'global',
    ];
    if ($employeeId <= 0 || !isValidDateValue($date)) {
        $fallback['scheduled_workday'] = isValidDateValue($date) ? (int)in_array((int)(new DateTimeImmutable($date))->format('N'), $fallback['work_days'], true) : 0;
        return $fallback;
    }

    $cacheKey = spl_object_id($pdo) . ':' . $employeeId . ':' . $date;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $employeeStmt = $pdo->prepare('SELECT company FROM employees WHERE id = ? AND role = "employee" LIMIT 1');
    $employeeStmt->execute([$employeeId]);
    $company = $employeeStmt->fetchColumn();
    if ($company === false) {
        $fallback['scheduled_workday'] = (int)in_array((int)(new DateTimeImmutable($date))->format('N'), $fallback['work_days'], true);
        return $cache[$cacheKey] = $fallback;
    }

    $assignmentStmt = $pdo->prepare('SELECT ws.*, "employee" AS assignment_source
        FROM employee_shift_assignments esa INNER JOIN work_shifts ws ON ws.id = esa.shift_id
        WHERE esa.employee_id = ? AND esa.effective_from <= ? AND (esa.effective_to IS NULL OR esa.effective_to >= ?)
        ORDER BY esa.effective_from DESC, esa.id DESC LIMIT 1');
    $assignmentStmt->execute([$employeeId, $date, $date]);
    $shift = $assignmentStmt->fetch();
    if (!$shift) {
        $companyStmt = $pdo->prepare('SELECT ws.*, "company" AS assignment_source
            FROM company_shift_assignments csa INNER JOIN work_shifts ws ON ws.id = csa.shift_id
            WHERE csa.company = ? AND csa.effective_from <= ? AND (csa.effective_to IS NULL OR csa.effective_to >= ?)
            ORDER BY csa.effective_from DESC, csa.id DESC LIMIT 1');
        $companyStmt->execute([(string)$company, $date, $date]);
        $shift = $companyStmt->fetch();
    }
    if (!$shift) {
        $fallback['scheduled_workday'] = (int)in_array((int)(new DateTimeImmutable($date))->format('N'), $fallback['work_days'], true);
        return $cache[$cacheKey] = $fallback;
    }

    $timezone = (string)$shift['timezone'];
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        $timezone = $fallback['timezone'];
    }
    $workDays = normalizeShiftWorkDays((string)$shift['work_days']);
    $schedule = [
        'timezone' => $timezone,
        'work_start_time' => substr((string)$shift['start_time'], 0, 5),
        'work_end_time' => substr((string)$shift['end_time'], 0, 5),
        'grace_period_minutes' => max(0, (int)$shift['grace_period_minutes']),
        'shift_id' => (int)$shift['id'],
        'shift_name' => (string)$shift['name'],
        'work_days' => $workDays,
        'scheduled_workday' => (int)in_array((int)(new DateTimeImmutable($date))->format('N'), $workDays, true),
        'source' => (string)$shift['assignment_source'],
    ];
    return $cache[$cacheKey] = $schedule;
}

/**
 * Build a schedule-aware staffing view for a date range without querying once
 * per employee/day. Approved full-day charges reduce available headcount;
 * pending full-day charges reduce projected headcount. Hourly leave remains
 * visible as partial leave and does not remove the employee from headcount.
 */
function getTeamAvailability(
    PDO $pdo,
    array $employees,
    string $startDate,
    string $endDate,
    int $minimumCoveragePercent = 70
): array {
    if (!isValidDateValue($startDate) || !isValidDateValue($endDate) || $endDate < $startDate) {
        return [];
    }

    $minimumCoveragePercent = max(1, min(100, $minimumCoveragePercent));
    $employeesById = [];
    $companies = [];
    foreach ($employees as $employee) {
        $employeeId = (int)($employee['id'] ?? 0);
        if ($employeeId <= 0) {
            continue;
        }
        $employee['id'] = $employeeId;
        $employee['company'] = trim((string)($employee['company'] ?? ''));
        $employeesById[$employeeId] = $employee;
        if ($employee['company'] !== '') {
            $companies[$employee['company']] = $employee['company'];
        }
    }

    $defaultSchedule = getAttendanceSchedule($pdo) + [
        'shift_id' => null,
        'shift_name' => 'Default Schedule',
        'work_days' => [1, 2, 3, 4, 5],
        'source' => 'global',
    ];
    $employeeAssignments = [];
    $companyAssignments = [];
    $leaveCharges = [];

    if ($employeesById) {
        $employeePlaceholders = implode(',', array_fill(0, count($employeesById), '?'));
        $assignmentStmt = $pdo->prepare('SELECT esa.employee_id, esa.effective_from, esa.effective_to,
                ws.id AS shift_id, ws.name AS shift_name, ws.timezone, ws.start_time, ws.end_time,
                ws.grace_period_minutes, ws.work_days
            FROM employee_shift_assignments esa
            INNER JOIN work_shifts ws ON ws.id = esa.shift_id
            WHERE esa.employee_id IN (' . $employeePlaceholders . ')
              AND esa.effective_from <= ? AND (esa.effective_to IS NULL OR esa.effective_to >= ?)
            ORDER BY esa.employee_id ASC, esa.effective_from DESC, esa.id DESC');
        $assignmentStmt->execute(array_merge(array_keys($employeesById), [$endDate, $startDate]));
        foreach ($assignmentStmt->fetchAll() as $assignment) {
            $employeeAssignments[(int)$assignment['employee_id']][] = $assignment;
        }

        $chargeStmt = $pdo->prepare('SELECT lrc.charge_date, lrc.minutes, lr.id AS request_id,
                lr.employee_id, lr.status, lr.request_unit, lt.name AS leave_type_name
            FROM leave_request_charges lrc
            INNER JOIN leave_requests lr ON lr.id = lrc.leave_request_id
            INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
            WHERE lr.employee_id IN (' . $employeePlaceholders . ')
              AND lr.status IN ("approved", "pending")
              AND lrc.charge_date BETWEEN ? AND ?
            ORDER BY lrc.charge_date ASC, lr.employee_id ASC, lr.id ASC');
        $chargeStmt->execute(array_merge(array_keys($employeesById), [$startDate, $endDate]));
        foreach ($chargeStmt->fetchAll() as $charge) {
            $date = (string)$charge['charge_date'];
            $employeeId = (int)$charge['employee_id'];
            $status = (string)$charge['status'];
            if (!isset($leaveCharges[$date][$employeeId][$status])) {
                $leaveCharges[$date][$employeeId][$status] = [
                    'minutes' => 0,
                    'leave_types' => [],
                    'request_ids' => [],
                ];
            }
            $leaveCharges[$date][$employeeId][$status]['minutes'] += max(0, (int)$charge['minutes']);
            $leaveCharges[$date][$employeeId][$status]['leave_types'][(string)$charge['leave_type_name']] = true;
            $leaveCharges[$date][$employeeId][$status]['request_ids'][] = (int)$charge['request_id'];
        }
    }

    if ($companies) {
        $companyPlaceholders = implode(',', array_fill(0, count($companies), '?'));
        $companyStmt = $pdo->prepare('SELECT csa.company, csa.effective_from, csa.effective_to,
                ws.id AS shift_id, ws.name AS shift_name, ws.timezone, ws.start_time, ws.end_time,
                ws.grace_period_minutes, ws.work_days
            FROM company_shift_assignments csa
            INNER JOIN work_shifts ws ON ws.id = csa.shift_id
            WHERE csa.company IN (' . $companyPlaceholders . ')
              AND csa.effective_from <= ? AND (csa.effective_to IS NULL OR csa.effective_to >= ?)
            ORDER BY csa.company ASC, csa.effective_from DESC, csa.id DESC');
        $companyStmt->execute(array_merge(array_values($companies), [$endDate, $startDate]));
        foreach ($companyStmt->fetchAll() as $assignment) {
            $companyAssignments[(string)$assignment['company']][] = $assignment;
        }
    }

    $holidayStmt = $pdo->prepare('SELECT holiday_date, name, holiday_type FROM holidays
        WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date ASC, id ASC');
    $holidayStmt->execute([$startDate, $endDate]);
    $holidays = [];
    foreach ($holidayStmt->fetchAll() as $holiday) {
        $holidays[(string)$holiday['holiday_date']][] = [
            'name' => (string)$holiday['name'],
            'type' => (string)$holiday['holiday_type'],
        ];
    }

    $scheduleFromAssignment = static function (?array $assignment, array $fallback, string $date): array {
        if ($assignment === null) {
            $schedule = $fallback;
        } else {
            $timezone = (string)$assignment['timezone'];
            if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                $timezone = (string)$fallback['timezone'];
            }
            $schedule = [
                'timezone' => $timezone,
                'work_start_time' => substr((string)$assignment['start_time'], 0, 5),
                'work_end_time' => substr((string)$assignment['end_time'], 0, 5),
                'grace_period_minutes' => max(0, (int)$assignment['grace_period_minutes']),
                'shift_id' => (int)$assignment['shift_id'],
                'shift_name' => (string)$assignment['shift_name'],
                'work_days' => normalizeShiftWorkDays((string)$assignment['work_days']),
                'source' => isset($assignment['employee_id']) ? 'employee' : 'company',
            ];
        }
        $schedule['scheduled_workday'] = (int)in_array(
            (int)(new DateTimeImmutable($date))->format('N'),
            $schedule['work_days'],
            true
        );
        return $schedule;
    };

    $findAssignment = static function (array $assignments, string $date): ?array {
        foreach ($assignments as $assignment) {
            if ((string)$assignment['effective_from'] <= $date
                && ($assignment['effective_to'] === null || (string)$assignment['effective_to'] >= $date)) {
                return $assignment;
            }
        }
        return null;
    };

    $days = [];
    $period = new DatePeriod(
        new DateTimeImmutable($startDate),
        new DateInterval('P1D'),
        (new DateTimeImmutable($endDate))->modify('+1 day')
    );
    foreach ($period as $dateObject) {
        $date = $dateObject->format('Y-m-d');
        $holidayRows = $holidays[$date] ?? [];
        $staff = [];
        $scheduledCount = 0;
        $approvedFullCount = 0;
        $pendingFullCount = 0;
        $approvedPartialMinutes = 0;
        $pendingPartialMinutes = 0;

        foreach ($employeesById as $employeeId => $employee) {
            $employmentStart = substr((string)($employee['created_at'] ?? ''), 0, 10);
            $deactivatedDate = substr((string)($employee['deactivated_at'] ?? ''), 0, 10);
            if (((int)($employee['active'] ?? 1) === 0 && $deactivatedDate === '')
                || ($employmentStart !== '' && $employmentStart > $date)
                || ($deactivatedDate !== '' && $deactivatedDate < $date)) {
                continue;
            }

            $assignment = $findAssignment($employeeAssignments[$employeeId] ?? [], $date);
            if ($assignment === null) {
                $assignment = $findAssignment($companyAssignments[(string)$employee['company']] ?? [], $date);
            }
            $schedule = $scheduleFromAssignment($assignment, $defaultSchedule, $date);
            if ((int)$schedule['scheduled_workday'] !== 1 || $holidayRows) {
                continue;
            }

            $scheduledCount++;
            $approved = $leaveCharges[$date][$employeeId]['approved'] ?? ['minutes' => 0, 'leave_types' => [], 'request_ids' => []];
            $pending = $leaveCharges[$date][$employeeId]['pending'] ?? ['minutes' => 0, 'leave_types' => [], 'request_ids' => []];
            $approvedMinutes = min(LEAVE_MINUTES_PER_DAY, (int)$approved['minutes']);
            $pendingMinutes = min(LEAVE_MINUTES_PER_DAY, (int)$pending['minutes']);
            $status = 'available';
            if ($approvedMinutes >= LEAVE_MINUTES_PER_DAY) {
                $status = 'approved_leave';
                $approvedFullCount++;
            } elseif ($pendingMinutes >= LEAVE_MINUTES_PER_DAY) {
                $status = 'pending_leave';
                $pendingFullCount++;
            } elseif ($approvedMinutes > 0) {
                $status = 'approved_partial';
                $approvedPartialMinutes += $approvedMinutes;
            } elseif ($pendingMinutes > 0) {
                $status = 'pending_partial';
                $pendingPartialMinutes += $pendingMinutes;
            }

            $staff[] = [
                'employee_id' => $employeeId,
                'employee_name' => trim((string)($employee['first_name'] ?? '') . ' ' . (string)($employee['last_name'] ?? '')),
                'company' => (string)$employee['company'],
                'shift_name' => (string)$schedule['shift_name'],
                'shift_time' => (string)$schedule['work_start_time'] . '–' . (string)$schedule['work_end_time'],
                'status' => $status,
                'approved_minutes' => $approvedMinutes,
                'pending_minutes' => $pendingMinutes,
                'leave_types' => array_values(array_unique(array_merge(
                    array_keys($approved['leave_types']),
                    array_keys($pending['leave_types'])
                ))),
                'request_ids' => array_values(array_unique(array_merge($approved['request_ids'], $pending['request_ids']))),
            ];
        }

        $availableCount = max(0, $scheduledCount - $approvedFullCount);
        $projectedCount = max(0, $availableCount - $pendingFullCount);
        $approvedCoverage = $scheduledCount > 0 ? (int)round(($availableCount / $scheduledCount) * 100) : 100;
        $projectedCoverage = $scheduledCount > 0 ? (int)round(($projectedCount / $scheduledCount) * 100) : 100;
        $warningLevel = 'normal';
        if ($scheduledCount > 0 && $approvedCoverage < $minimumCoveragePercent) {
            $warningLevel = 'critical';
        } elseif ($scheduledCount > 0 && $projectedCoverage < $minimumCoveragePercent) {
            $warningLevel = 'warning';
        }

        $days[$date] = [
            'date' => $date,
            'holidays' => $holidayRows,
            'scheduled_count' => $scheduledCount,
            'available_count' => $availableCount,
            'projected_count' => $projectedCount,
            'approved_full_count' => $approvedFullCount,
            'pending_full_count' => $pendingFullCount,
            'approved_partial_minutes' => $approvedPartialMinutes,
            'pending_partial_minutes' => $pendingPartialMinutes,
            'approved_coverage' => $approvedCoverage,
            'projected_coverage' => $projectedCoverage,
            'warning_level' => $holidayRows ? 'holiday' : $warningLevel,
            'staff' => $staff,
        ];
    }

    return $days;
}

function getEmployeeAttendanceContext(PDO $pdo, int $employeeId, ?DateTimeImmutable $now = null): array {
    $fallback = getAttendanceSchedule($pdo);
    $instant = $now ?? new DateTimeImmutable('now', new DateTimeZone($fallback['timezone']));
    $fallbackNow = $instant->setTimezone(new DateTimeZone($fallback['timezone']));
    $candidateDate = $fallbackNow->format('Y-m-d');
    $candidateSchedule = getEmployeeScheduleForDate($pdo, $employeeId, $candidateDate);
    $localNow = $instant->setTimezone(new DateTimeZone($candidateSchedule['timezone']));
    if ($localNow->format('Y-m-d') !== $candidateDate) {
        $candidateDate = $localNow->format('Y-m-d');
        $candidateSchedule = getEmployeeScheduleForDate($pdo, $employeeId, $candidateDate);
        $localNow = $instant->setTimezone(new DateTimeZone($candidateSchedule['timezone']));
    }

    $previousDate = (new DateTimeImmutable($candidateDate))->modify('-1 day')->format('Y-m-d');
    $previousSchedule = getEmployeeScheduleForDate($pdo, $employeeId, $previousDate);
    $previousNow = $instant->setTimezone(new DateTimeZone($previousSchedule['timezone']));
    $overnight = $previousSchedule['work_end_time'] <= $previousSchedule['work_start_time'];
    $previousEndDate = (new DateTimeImmutable($previousDate))->modify('+1 day')->format('Y-m-d');
    if ($overnight && $previousSchedule['scheduled_workday'] === 1
        && $previousNow->format('Y-m-d') === $previousEndDate
        && $previousNow->format('H:i') <= $previousSchedule['work_end_time']) {
        return ['attendance_date' => $previousDate, 'schedule' => $previousSchedule, 'now' => $previousNow];
    }
    return ['attendance_date' => $candidateDate, 'schedule' => $candidateSchedule, 'now' => $localNow];
}

function applyTimezone(PDO $pdo): void {
    $schedule = getAttendanceSchedule($pdo);
    date_default_timezone_set($schedule['timezone']);
}

function attendanceDefault(string $date): array {
    return [
        'id' => 0,
        'employee_id' => 0,
        'attendance_date' => $date,
        'time_in' => null,
        'time_out' => null,
        'break_start' => null,
        'break_end' => null,
        'break_minutes' => 0,
        'total_hours' => 0.00,
        'status' => 'not_started',
        'voided_at' => null,
        'voided_by' => null,
        'void_reason' => null,
        'schedule_timezone' => DEFAULT_TIMEZONE,
        'shift_id' => null,
        'shift_name' => 'Default Schedule',
        'scheduled_start_time' => DEFAULT_WORK_START . ':00',
        'scheduled_end_time' => DEFAULT_WORK_END . ':00',
        'grace_period_minutes' => DEFAULT_GRACE_MINUTES,
        'scheduled_workday' => 1,
    ];
}

function isValidDateValue(string $date): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function parseDatabaseDateTime(?string $value, string $timezone): ?DateTimeImmutable {
    if ($value === null || trim($value) === '') {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', trim($value), new DateTimeZone($timezone));
    return $parsed !== false ? $parsed : null;
}

function parseDateTimeLocal(string $value, string $timezone): ?DateTimeImmutable {
    $value = trim($value);
    foreach (['!Y-m-d\TH:i', '!Y-m-d\TH:i:s'] as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone($timezone));
        if ($parsed !== false) {
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                return $parsed;
            }
        }
    }
    return null;
}

function attendanceIsLate(array $attendance, array $fallbackSchedule): bool {
    if (empty($attendance['time_in']) || empty($attendance['attendance_date'])) {
        return false;
    }
    if (array_key_exists('scheduled_workday', $attendance) && (int)$attendance['scheduled_workday'] === 0) {
        return false;
    }

    $timezone = (string)($attendance['schedule_timezone'] ?? $fallbackSchedule['timezone']);
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        $timezone = $fallbackSchedule['timezone'];
    }
    $startTime = substr((string)($attendance['scheduled_start_time'] ?? $fallbackSchedule['work_start_time']), 0, 5);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime)) {
        $startTime = $fallbackSchedule['work_start_time'];
    }
    $grace = max(0, (int)($attendance['grace_period_minutes'] ?? $fallbackSchedule['grace_period_minutes']));

    $timeIn = parseDatabaseDateTime((string)$attendance['time_in'], $timezone);
    if ($timeIn === null) {
        return false;
    }
    $threshold = new DateTimeImmutable((string)$attendance['attendance_date'] . ' ' . $startTime . ':00', new DateTimeZone($timezone));
    return $timeIn > $threshold->modify('+' . $grace . ' minutes');
}

function getHolidayMap(PDO $pdo, string $startDate, string $endDate): array {
    if (!isValidDateValue($startDate) || !isValidDateValue($endDate) || $endDate < $startDate) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT holiday_date, name, holiday_type FROM holidays
        WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date, name');
    $stmt->execute([$startDate, $endDate]);
    $map = [];
    foreach ($stmt->fetchAll() as $holiday) {
        $date = (string)$holiday['holiday_date'];
        if (!isset($map[$date])) {
            $map[$date] = ['name' => (string)$holiday['name'], 'type' => (string)$holiday['holiday_type']];
            continue;
        }
        $map[$date]['name'] .= ', ' . (string)$holiday['name'];
        $map[$date]['type'] .= ', ' . (string)$holiday['holiday_type'];
    }
    return $map;
}

function sortDashboardAlerts(array $alerts): array {
    $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];
    usort($alerts, static function (array $left, array $right) use ($rank): int {
        $priorityComparison = ($rank[$left['severity'] ?? 'info'] ?? 3) <=> ($rank[$right['severity'] ?? 'info'] ?? 3);
        return $priorityComparison !== 0
            ? $priorityComparison
            : strcmp((string)($left['id'] ?? ''), (string)($right['id'] ?? ''));
    });
    return $alerts;
}

function buildAttendanceDashboardAlerts(
    array $attendance,
    array $fallbackSchedule,
    DateTimeImmutable $now,
    array $holidays = []
): array {
    $timezoneName = (string)($attendance['schedule_timezone'] ?? $fallbackSchedule['timezone'] ?? DEFAULT_TIMEZONE);
    if (!in_array($timezoneName, DateTimeZone::listIdentifiers(), true)) {
        $timezoneName = DEFAULT_TIMEZONE;
    }
    $timezone = new DateTimeZone($timezoneName);
    $now = $now->setTimezone($timezone);
    $date = (string)($attendance['attendance_date'] ?? $now->format('Y-m-d'));
    if (!isValidDateValue($date)) {
        $date = $now->format('Y-m-d');
    }
    $startTime = substr((string)($attendance['scheduled_start_time'] ?? $fallbackSchedule['work_start_time'] ?? DEFAULT_WORK_START), 0, 5);
    $endTime = substr((string)($attendance['scheduled_end_time'] ?? $fallbackSchedule['work_end_time'] ?? DEFAULT_WORK_END), 0, 5);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime)) {
        $startTime = DEFAULT_WORK_START;
    }
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endTime)) {
        $endTime = DEFAULT_WORK_END;
    }
    $scheduledStart = new DateTimeImmutable($date . ' ' . $startTime . ':00', $timezone);
    $scheduledEnd = new DateTimeImmutable($date . ' ' . $endTime . ':00', $timezone);
    if ($endTime <= $startTime) {
        $scheduledEnd = $scheduledEnd->modify('+1 day');
    }
    $status = (string)($attendance['status'] ?? 'not_started');
    $attendanceId = max(0, (int)($attendance['id'] ?? 0));
    $alerts = [];
    $add = static function (
        string $id,
        string $severity,
        string $title,
        string $message,
        string $href,
        string $actionLabel,
        bool $dismissible = false
    ) use (&$alerts): void {
        $alerts[] = compact('id', 'severity', 'title', 'message', 'href', 'actionLabel', 'dismissible');
    };

    if ($status === 'not_started'
        && (int)($attendance['scheduled_workday'] ?? 1) === 1
        && !isset($holidays[$date])) {
        $graceMinutes = max(0, (int)($attendance['grace_period_minutes'] ?? $fallbackSchedule['grace_period_minutes'] ?? 0));
        $graceEnd = $scheduledStart->modify('+' . $graceMinutes . ' minutes');
        if ($now > $scheduledEnd) {
            $add(
                'missed-attendance-' . $date,
                'danger',
                'No attendance recorded',
                'Your scheduled shift ended at ' . $scheduledEnd->format('H:i') . '. Review your history and request a correction if you missed a time entry.',
                'history.php',
                'Review history'
            );
        } elseif ($now > $graceEnd) {
            $add(
                'clock-in-reminder-' . $date,
                'warning',
                'Time in reminder',
                'Your shift began at ' . $scheduledStart->format('H:i') . ' and no time in has been recorded.',
                '#attendance-panel',
                'Time in now'
            );
        } elseif ($now >= $scheduledStart->modify('-30 minutes')) {
            $minutesUntilStart = max(0, (int)ceil(($scheduledStart->getTimestamp() - $now->getTimestamp()) / 60));
            $add(
                'shift-starting-' . $date,
                'info',
                $minutesUntilStart > 0 ? 'Shift starts soon' : 'Shift is starting',
                $minutesUntilStart > 0
                    ? 'Your shift starts at ' . $scheduledStart->format('H:i') . ' (' . $minutesUntilStart . ' minutes from now).'
                    : 'Your shift starts at ' . $scheduledStart->format('H:i') . '.',
                '#attendance-panel',
                'Open attendance',
                true
            );
        }
    }

    if ($status === 'on_break') {
        $breakStart = parseDatabaseDateTime($attendance['break_start'] ?? null, $timezoneName);
        if ($breakStart !== null && $now > $breakStart) {
            $elapsedSeconds = $now->getTimestamp() - $breakStart->getTimestamp();
            if ($elapsedSeconds >= 3600) {
                $overdueMinutes = max(1, (int)floor(($elapsedSeconds - 3600) / 60));
                $add(
                    'lunch-overdue-' . $attendanceId . '-' . (string)($attendance['break_start'] ?? ''),
                    'danger',
                    'Lunch timer is overdue',
                    'Your 60-minute lunch ended ' . $overdueMinutes . ' minute' . ($overdueMinutes === 1 ? '' : 's') . ' ago. End lunch when you return.',
                    '#attendance-panel',
                    'End lunch'
                );
            } elseif ($elapsedSeconds >= 3000) {
                $remainingMinutes = max(1, (int)ceil((3600 - $elapsedSeconds) / 60));
                $add(
                    'lunch-ending-' . $attendanceId . '-' . (string)($attendance['break_start'] ?? ''),
                    'warning',
                    'Lunch is almost finished',
                    $remainingMinutes . ' minute' . ($remainingMinutes === 1 ? '' : 's') . ' remain on your 60-minute lunch timer.',
                    '#attendance-panel',
                    'View lunch timer'
                );
            }
        }
    }

    if (in_array($status, ['currently_working', 'on_break', 'on_quick_break'], true)) {
        $timeIn = parseDatabaseDateTime($attendance['time_in'] ?? null, $timezoneName);
        if ($timeIn !== null && $now > $timeIn) {
            $openSeconds = $now->getTimestamp() - $timeIn->getTimestamp();
            if ($openSeconds >= 16 * 3600) {
                $add(
                    'long-open-shift-' . $attendanceId,
                    'danger',
                    'Work session needs attention',
                    'This session has been open for ' . formatMinutesDuration((int)floor($openSeconds / 60)) . '. Time out or ask an administrator for help if the record is incorrect.',
                    '#attendance-panel',
                    'Review session'
                );
            } elseif ($now > $scheduledEnd->modify('+30 minutes')) {
                $add(
                    'shift-ended-' . $attendanceId . '-' . $date,
                    'warning',
                    'Your scheduled shift has ended',
                    'Your shift ended at ' . $scheduledEnd->format('H:i') . '. Remember to time out when your work is complete.',
                    '#attendance-panel',
                    'Review attendance'
                );
            }
        }
    }

    return sortDashboardAlerts($alerts);
}

function getEmployeeDashboardAlerts(
    PDO $pdo,
    int $employeeId,
    array $attendance,
    array $fallbackSchedule,
    ?DateTimeImmutable $now = null
): array {
    if ($employeeId <= 0) {
        return [];
    }
    $timezoneName = (string)($attendance['schedule_timezone'] ?? $fallbackSchedule['timezone'] ?? DEFAULT_TIMEZONE);
    if (!in_array($timezoneName, DateTimeZone::listIdentifiers(), true)) {
        $timezoneName = DEFAULT_TIMEZONE;
    }
    $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone($timezoneName)))->setTimezone(new DateTimeZone($timezoneName));
    $today = $now->format('Y-m-d');
    $holidayHorizon = $now->modify('+14 days')->format('Y-m-d');
    $leaveHorizon = $now->modify('+30 days')->format('Y-m-d');
    $attendanceDate = (string)($attendance['attendance_date'] ?? $today);
    $holidays = getHolidayMap($pdo, min($today, $attendanceDate), $holidayHorizon);
    $attendanceExceptions = $holidays;
    if (isValidDateValue($attendanceDate)) {
        $approvedChargeStmt = $pdo->prepare('SELECT COALESCE(SUM(lrc.minutes), 0)
            FROM leave_request_charges lrc
            INNER JOIN leave_requests lr ON lr.id = lrc.leave_request_id
            WHERE lr.employee_id = ? AND lr.status = "approved" AND lrc.charge_date = ?');
        $approvedChargeStmt->execute([$employeeId, $attendanceDate]);
        if ((int)$approvedChargeStmt->fetchColumn() >= LEAVE_MINUTES_PER_DAY) {
            $attendanceExceptions[$attendanceDate] = ['name' => 'Approved Leave', 'type' => 'leave'];
        }
    }
    $alerts = buildAttendanceDashboardAlerts($attendance, $fallbackSchedule, $now, $attendanceExceptions);

    $profileStmt = $pdo->prepare('SELECT birthday, phone_number, address FROM employees WHERE id = ? AND active = 1 LIMIT 1');
    $profileStmt->execute([$employeeId]);
    $profile = $profileStmt->fetch();
    if ($profile) {
        $missingFields = [];
        if (trim((string)($profile['phone_number'] ?? '')) === '') {
            $missingFields[] = 'phone number';
        }
        if (trim((string)($profile['address'] ?? '')) === '') {
            $missingFields[] = 'address';
        }
        if (trim((string)($profile['birthday'] ?? '')) === '') {
            $missingFields[] = 'birthday';
        }
        if ($missingFields) {
            $alerts[] = [
                'id' => 'profile-incomplete-' . implode('-', array_map(static fn(string $field): string => str_replace(' ', '-', $field), $missingFields)),
                'severity' => 'warning',
                'title' => 'Profile information is incomplete',
                'message' => 'Missing: ' . implode(', ', $missingFields) . '. You can update contact details or ask HR to correct managed information.',
                'href' => 'profile.php',
                'actionLabel' => 'Review profile',
                'dismissible' => true,
            ];
        }
    }

    $correctionStmt = $pdo->prepare('SELECT COUNT(*) AS total, COALESCE(MAX(id), 0) AS latest_id
        FROM attendance_correction_requests WHERE employee_id = ? AND status = "pending"');
    $correctionStmt->execute([$employeeId]);
    $correction = $correctionStmt->fetch() ?: ['total' => 0, 'latest_id' => 0];
    if ((int)$correction['total'] > 0) {
        $count = (int)$correction['total'];
        $alerts[] = [
            'id' => 'pending-corrections-' . (int)$correction['latest_id'] . '-' . $count,
            'severity' => 'info',
            'title' => 'Attendance correction pending',
            'message' => $count . ' correction request' . ($count === 1 ? ' is' : 's are') . ' waiting for administrator review.',
            'href' => 'history.php',
            'actionLabel' => 'View history',
            'dismissible' => true,
        ];
    }

    $pendingLeaveStmt = $pdo->prepare('SELECT COUNT(*) AS total, COALESCE(MAX(id), 0) AS latest_id
        FROM leave_requests WHERE employee_id = ? AND status = "pending"');
    $pendingLeaveStmt->execute([$employeeId]);
    $pendingLeave = $pendingLeaveStmt->fetch() ?: ['total' => 0, 'latest_id' => 0];
    if ((int)$pendingLeave['total'] > 0) {
        $count = (int)$pendingLeave['total'];
        $alerts[] = [
            'id' => 'pending-leave-' . (int)$pendingLeave['latest_id'] . '-' . $count,
            'severity' => 'info',
            'title' => 'Leave request pending',
            'message' => $count . ' leave request' . ($count === 1 ? ' is' : 's are') . ' waiting for approval.',
            'href' => 'calendar.php',
            'actionLabel' => 'View requests',
            'dismissible' => true,
        ];
    }

    $approvedLeaveStmt = $pdo->prepare('SELECT lr.id, lr.start_date, lr.end_date, lr.request_unit, lr.requested_minutes,
            lt.name AS leave_type_name
        FROM leave_requests lr INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
        WHERE lr.employee_id = ? AND lr.status = "approved"
          AND lr.end_date >= ? AND lr.start_date <= ?
        ORDER BY lr.start_date ASC, lr.id ASC LIMIT 1');
    $approvedLeaveStmt->execute([$employeeId, $today, $leaveHorizon]);
    $approvedLeave = $approvedLeaveStmt->fetch();
    if ($approvedLeave) {
        $isCurrent = (string)$approvedLeave['start_date'] <= $today && (string)$approvedLeave['end_date'] >= $today;
        $dateLabel = (string)$approvedLeave['start_date'] === (string)$approvedLeave['end_date']
            ? formatEmployeeDate((string)$approvedLeave['start_date'])
            : formatEmployeeDate((string)$approvedLeave['start_date']) . '–' . formatEmployeeDate((string)$approvedLeave['end_date']);
        $alerts[] = [
            'id' => 'approved-leave-' . (int)$approvedLeave['id'],
            'severity' => 'info',
            'title' => $isCurrent ? 'You are on approved leave' : 'Approved leave is coming up',
            'message' => (string)$approvedLeave['leave_type_name'] . ' · ' . $dateLabel,
            'href' => 'calendar.php',
            'actionLabel' => 'Open calendar',
            'dismissible' => true,
        ];
    }

    $nextHoliday = null;
    foreach ($holidays as $date => $holiday) {
        if ($date >= $today && $date <= $holidayHorizon) {
            $nextHoliday = ['date' => $date, 'name' => (string)$holiday['name'], 'type' => (string)$holiday['type']];
            break;
        }
    }
    if ($nextHoliday !== null) {
        $alerts[] = [
            'id' => 'upcoming-holiday-' . $nextHoliday['date'],
            'severity' => 'info',
            'title' => $nextHoliday['date'] === $today ? 'Today is a company holiday' : 'Company holiday coming up',
            'message' => $nextHoliday['name'] . ' · ' . formatEmployeeDate($nextHoliday['date']),
            'href' => 'calendar.php?month=' . substr($nextHoliday['date'], 0, 7),
            'actionLabel' => 'Open calendar',
            'dismissible' => true,
        ];
    }

    return sortDashboardAlerts($alerts);
}

function calculateAttendancePayrollMetrics(array $attendance, array $fallbackSchedule, ?array $holiday = null): array {
    $date = (string)($attendance['attendance_date'] ?? '');
    $timezoneName = (string)($attendance['schedule_timezone'] ?? $fallbackSchedule['timezone'] ?? DEFAULT_TIMEZONE);
    if (!in_array($timezoneName, DateTimeZone::listIdentifiers(), true)) {
        $timezoneName = (string)($fallbackSchedule['timezone'] ?? DEFAULT_TIMEZONE);
    }
    if (!in_array($timezoneName, DateTimeZone::listIdentifiers(), true)) {
        $timezoneName = DEFAULT_TIMEZONE;
    }
    $startTime = substr((string)($attendance['scheduled_start_time'] ?? $fallbackSchedule['work_start_time'] ?? DEFAULT_WORK_START), 0, 5);
    $endTime = substr((string)($attendance['scheduled_end_time'] ?? $fallbackSchedule['work_end_time'] ?? DEFAULT_WORK_END), 0, 5);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime)) {
        $startTime = DEFAULT_WORK_START;
    }
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endTime)) {
        $endTime = DEFAULT_WORK_END;
    }

    $scheduledWorkday = (int)($attendance['scheduled_workday'] ?? 1) === 1;
    $isHoliday = $holiday !== null;
    $dayType = $isHoliday ? 'Holiday Work' : ($scheduledWorkday ? 'Regular Day' : 'Rest Day Work');
    $result = [
        'day_type' => $dayType,
        'holiday_name' => $isHoliday ? (string)($holiday['name'] ?? 'Company Holiday') : '',
        'holiday_type' => $isHoliday ? (string)($holiday['type'] ?? '') : '',
        'scheduled_minutes' => 0,
        'net_minutes' => 0,
        'regular_minutes' => 0,
        'overtime_minutes' => 0,
        'late_minutes' => 0,
        'undertime_minutes' => 0,
        'holiday_minutes' => 0,
        'rest_day_minutes' => 0,
        'complete' => false,
    ];
    if (!isValidDateValue($date)) {
        return $result;
    }

    $timezone = new DateTimeZone($timezoneName);
    $scheduledStart = new DateTimeImmutable($date . ' ' . $startTime . ':00', $timezone);
    $scheduledEnd = new DateTimeImmutable($date . ' ' . $endTime . ':00', $timezone);
    if ($endTime <= $startTime) {
        $scheduledEnd = $scheduledEnd->modify('+1 day');
    }
    $shiftSpanMinutes = max(0, (int)round(($scheduledEnd->getTimestamp() - $scheduledStart->getTimestamp()) / 60));
    $scheduledMinutes = $scheduledWorkday && !$isHoliday ? min(LEAVE_MINUTES_PER_DAY, $shiftSpanMinutes) : 0;
    $result['scheduled_minutes'] = $scheduledMinutes;

    $timeIn = parseDatabaseDateTime(isset($attendance['time_in']) ? (string)$attendance['time_in'] : null, $timezoneName);
    $timeOut = parseDatabaseDateTime(isset($attendance['time_out']) ? (string)$attendance['time_out'] : null, $timezoneName);
    $graceMinutes = max(0, (int)($attendance['grace_period_minutes'] ?? $fallbackSchedule['grace_period_minutes'] ?? 0));
    $arrivalDelayMinutes = $timeIn !== null && $timeIn > $scheduledStart
        ? (int)ceil(($timeIn->getTimestamp() - $scheduledStart->getTimestamp()) / 60)
        : 0;
    if ($timeIn !== null && $scheduledWorkday && !$isHoliday && $timeIn > $scheduledStart->modify('+' . $graceMinutes . ' minutes')) {
        $result['late_minutes'] = $arrivalDelayMinutes;
    }

    $complete = $timeIn !== null && $timeOut !== null && $timeOut > $timeIn
        && (string)($attendance['status'] ?? '') === 'completed';
    $result['complete'] = $complete;
    if (!$complete) {
        return $result;
    }

    $grossMinutes = (int)round(($timeOut->getTimestamp() - $timeIn->getTimestamp()) / 60);
    $netMinutes = max(0, $grossMinutes - max(0, (int)($attendance['break_minutes'] ?? 0)));
    $result['net_minutes'] = $netMinutes;
    if ($isHoliday) {
        $result['holiday_minutes'] = $netMinutes;
        $result['overtime_minutes'] = $netMinutes;
        return $result;
    }
    if (!$scheduledWorkday) {
        $result['rest_day_minutes'] = $netMinutes;
        $result['overtime_minutes'] = $netMinutes;
        return $result;
    }

    $result['regular_minutes'] = min($scheduledMinutes, $netMinutes);
    $result['overtime_minutes'] = max(0, $netMinutes - $scheduledMinutes);
    $deficitMinutes = max(0, $scheduledMinutes - $netMinutes);
    $deficitExcludingArrival = max(0, $deficitMinutes - min($deficitMinutes, $arrivalDelayMinutes));
    $earlyOutMinutes = $timeOut < $scheduledEnd
        ? (int)ceil(($scheduledEnd->getTimestamp() - $timeOut->getTimestamp()) / 60)
        : 0;
    $result['undertime_minutes'] = max($earlyOutMinutes, $deficitExcludingArrival);
    return $result;
}

function employeeDisplayName(array $employee): string {
    return trim(implode(' ', array_filter([
        trim((string)($employee['first_name'] ?? '')),
        trim((string)($employee['middle_name'] ?? '')),
        trim((string)($employee['last_name'] ?? '')),
    ], static fn(string $part): bool => $part !== '')));
}

function payrollExceptionReason(array $record, array $payroll): string {
    if ((string)($record['status'] ?? '') !== 'completed') {
        return 'Open or unfinished attendance status';
    }
    if (empty($record['time_in'])) {
        return 'Missing time in';
    }
    if (empty($record['time_out'])) {
        return 'Missing time out';
    }
    return empty($payroll['complete']) ? 'Invalid or incomplete attendance timeline' : '';
}

function csvSafeCell(mixed $value): mixed {
    if (!is_string($value)) {
        return $value;
    }
    return preg_match('/^[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
}

function buildPayrollExportDataset(
    PDO $pdo,
    string $startDate,
    string $endDate,
    ?int $employeeId = null,
    string $company = ''
): array {
    $emptyTotals = [
        'employees' => 0,
        'attendance_days' => 0,
        'net_minutes' => 0,
        'regular_minutes' => 0,
        'overtime_minutes' => 0,
        'holiday_minutes' => 0,
        'rest_day_minutes' => 0,
        'late_minutes' => 0,
        'undertime_minutes' => 0,
        'lunch_minutes' => 0,
        'quick_break_minutes' => 0,
        'approved_leave_minutes' => 0,
    ];
    if (!isValidDateValue($startDate) || !isValidDateValue($endDate) || $endDate < $startDate || ($employeeId !== null && $employeeId <= 0)) {
        return ['finalized_rows' => [], 'approved_leave_rows' => [], 'exceptions' => [], 'summaries' => [], 'totals' => $emptyTotals];
    }

    $sql = 'SELECT a.*, e.first_name, e.middle_name, e.last_name, e.company, e.active,
            COALESCE(qb.quick_break_seconds, 0) AS quick_break_seconds
        FROM attendance a
        INNER JOIN employees e ON e.id = a.employee_id AND e.role = "employee"
        LEFT JOIN (
            SELECT attendance_id,
                SUM(COALESCE(duration_seconds, TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, NOW())))) AS quick_break_seconds
            FROM attendance_quick_breaks GROUP BY attendance_id
        ) qb ON qb.attendance_id = a.id
        WHERE a.attendance_date BETWEEN ? AND ? AND a.voided_at IS NULL';
    $params = [$startDate, $endDate];
    if ($employeeId !== null) {
        $sql .= ' AND a.employee_id = ?';
        $params[] = $employeeId;
    }
    if ($company !== '') {
        $sql .= ' AND e.company = ?';
        $params[] = $company;
    }
    $sql .= ' ORDER BY e.company ASC, e.last_name ASC, e.first_name ASC, a.attendance_date ASC, a.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    $holidayMap = getHolidayMap($pdo, $startDate, $endDate);
    $fallbackSchedule = getAttendanceSchedule($pdo);
    $finalizedRows = [];
    $approvedLeaveRows = [];
    $exceptions = [];
    $summaries = [];
    $ensureSummary = static function (array $record) use (&$summaries): void {
        $recordEmployeeId = (int)$record['employee_id'];
        if (isset($summaries[$recordEmployeeId])) {
            return;
        }
        $summaries[$recordEmployeeId] = [
            'employee_id' => $recordEmployeeId,
            'employee_number' => 'EMP-' . str_pad((string)$recordEmployeeId, 6, '0', STR_PAD_LEFT),
            'employee_name' => employeeDisplayName($record),
            'company' => (string)$record['company'],
            'active' => (int)$record['active'],
            'attendance_days' => 0,
            'net_minutes' => 0,
            'regular_minutes' => 0,
            'overtime_minutes' => 0,
            'holiday_minutes' => 0,
            'rest_day_minutes' => 0,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'lunch_minutes' => 0,
            'quick_break_minutes' => 0,
            'approved_leave_minutes' => 0,
        ];
    };

    foreach ($records as $record) {
        $payroll = calculateAttendancePayrollMetrics(
            $record,
            $fallbackSchedule,
            $holidayMap[(string)$record['attendance_date']] ?? null
        );
        $record['_payroll'] = $payroll;
        $record['_employee_name'] = employeeDisplayName($record);
        $record['_quick_break_minutes'] = max(0, (int)round((int)$record['quick_break_seconds'] / 60));
        if (empty($payroll['complete'])) {
            $record['_exception_reason'] = payrollExceptionReason($record, $payroll);
            $exceptions[] = $record;
            continue;
        }

        $finalizedRows[] = $record;
        $recordEmployeeId = (int)$record['employee_id'];
        $ensureSummary($record);
        $summaries[$recordEmployeeId]['attendance_days']++;
        foreach (['net_minutes', 'regular_minutes', 'overtime_minutes', 'holiday_minutes', 'rest_day_minutes', 'late_minutes', 'undertime_minutes'] as $metric) {
            $summaries[$recordEmployeeId][$metric] += (int)$payroll[$metric];
        }
        $summaries[$recordEmployeeId]['lunch_minutes'] += max(0, (int)$record['break_minutes']);
        $summaries[$recordEmployeeId]['quick_break_minutes'] += (int)$record['_quick_break_minutes'];
    }

    $leaveSql = 'SELECT lrc.charge_date, lrc.minutes, lr.id AS leave_request_id, lr.employee_id,
            lr.request_unit, lt.name AS leave_type_name,
            e.first_name, e.middle_name, e.last_name, e.company, e.active
        FROM leave_request_charges lrc
        INNER JOIN leave_requests lr ON lr.id = lrc.leave_request_id AND lr.status = "approved"
        INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
        INNER JOIN employees e ON e.id = lr.employee_id AND e.role = "employee"
        WHERE lrc.charge_date BETWEEN ? AND ?';
    $leaveParams = [$startDate, $endDate];
    if ($employeeId !== null) {
        $leaveSql .= ' AND lr.employee_id = ?';
        $leaveParams[] = $employeeId;
    }
    if ($company !== '') {
        $leaveSql .= ' AND e.company = ?';
        $leaveParams[] = $company;
    }
    $leaveSql .= ' ORDER BY e.company ASC, e.last_name ASC, e.first_name ASC, lrc.charge_date ASC, lr.id ASC';
    $leaveStmt = $pdo->prepare($leaveSql);
    $leaveStmt->execute($leaveParams);
    foreach ($leaveStmt->fetchAll() as $leaveRow) {
        $leaveRow['_employee_name'] = employeeDisplayName($leaveRow);
        $approvedLeaveRows[] = $leaveRow;
        $leaveEmployeeId = (int)$leaveRow['employee_id'];
        $ensureSummary($leaveRow);
        $summaries[$leaveEmployeeId]['approved_leave_minutes'] += max(0, (int)$leaveRow['minutes']);
    }

    $summaries = array_values($summaries);
    usort($summaries, static fn(array $left, array $right): int => [
        (string)$left['company'], (string)$left['employee_name'], (int)$left['employee_id'],
    ] <=> [
        (string)$right['company'], (string)$right['employee_name'], (int)$right['employee_id'],
    ]);
    $totals = $emptyTotals;
    $totals['employees'] = count($summaries);
    foreach ($summaries as $summary) {
        foreach (array_keys($emptyTotals) as $metric) {
            if ($metric !== 'employees') {
                $totals[$metric] += (int)$summary[$metric];
            }
        }
    }

    return [
        'finalized_rows' => $finalizedRows,
        'approved_leave_rows' => $approvedLeaveRows,
        'exceptions' => $exceptions,
        'summaries' => $summaries,
        'totals' => $totals,
    ];
}

function validateAttendanceTimeline(
    string $attendanceDate,
    ?DateTimeImmutable $timeIn,
    ?DateTimeImmutable $timeOut,
    ?DateTimeImmutable $breakStart,
    ?DateTimeImmutable $breakEnd,
    string $status,
    int $fallbackBreakMinutes = 0
): array {
    $errors = [];
    if (!isValidDateValue($attendanceDate)) {
        $errors[] = 'Attendance date is invalid.';
    }
    if ($timeIn === null) {
        $errors[] = 'Time in is required and must be valid.';
    } elseif ($timeIn->format('Y-m-d') !== $attendanceDate) {
        $errors[] = 'Time in must fall on the attendance date.';
    }
    if ($timeIn !== null && $timeOut !== null && $timeOut <= $timeIn) {
        $errors[] = 'Time out must be after time in.';
    }
    if (($breakStart === null) !== ($breakEnd === null) && $status !== 'on_break') {
        $errors[] = 'Lunch out and lunch in must both be provided.';
    }
    if ($timeIn !== null && $breakStart !== null && $breakStart < $timeIn) {
        $errors[] = 'Lunch out must be after time in.';
    }
    if ($breakStart !== null && $breakEnd !== null && $breakEnd <= $breakStart) {
        $errors[] = 'Lunch in must be after lunch out.';
    }
    if ($timeOut !== null && $breakStart !== null && $breakStart > $timeOut) {
        $errors[] = 'Lunch out must be inside the work session.';
    }
    if ($timeOut !== null && $breakEnd !== null && $breakEnd > $timeOut) {
        $errors[] = 'Lunch in must be inside the work session.';
    }

    if ($status === 'completed' && $timeOut === null) {
        $errors[] = 'Completed attendance requires a time out.';
    } elseif ($status === 'on_break' && ($timeOut !== null || $breakStart === null || $breakEnd !== null)) {
        $errors[] = 'An on-break session needs an open lunch break and no time out.';
    } elseif ($status === 'currently_working' && ($timeOut !== null || ($breakStart !== null && $breakEnd === null))) {
        $errors[] = 'A working session cannot have a time out or an open lunch break.';
    }

    $breakMinutes = max(0, $fallbackBreakMinutes);
    if ($breakStart !== null && $breakEnd !== null) {
        $breakMinutes = (int)round(($breakEnd->getTimestamp() - $breakStart->getTimestamp()) / 60);
    }

    $totalHours = 0.0;
    if ($timeIn !== null && $timeOut !== null && $timeOut > $timeIn) {
        $grossSeconds = $timeOut->getTimestamp() - $timeIn->getTimestamp();
        $totalHours = round(max(0, $grossSeconds - ($breakMinutes * 60)) / 3600, 2);
    }

    return ['errors' => array_values(array_unique($errors)), 'break_minutes' => $breakMinutes, 'total_hours' => $totalHours];
}

function leaveStatusPill(string $status): string {
    static $map = [
        'pending' => ['Pending', 'pill-yellow'],
        'approved' => ['Approved', 'pill-green'],
        'rejected' => ['Rejected', 'pill-red'],
        'cancelled' => ['Cancelled', 'pill-black'],
    ];
    [$label, $class] = $map[$status] ?? [ucfirst($status), 'pill-gray'];
    return '<span class="pill ' . $class . '">' . h($label) . '</span>';
}

function countDateRangeDays(string $startDate, string $endDate, bool $excludeWeekends = false): int {
    if (!isValidDateValue($startDate) || !isValidDateValue($endDate) || $endDate < $startDate) {
        return 0;
    }

    $period = new DatePeriod(
        new DateTimeImmutable($startDate),
        new DateInterval('P1D'),
        (new DateTimeImmutable($endDate))->modify('+1 day')
    );
    $days = 0;
    foreach ($period as $day) {
        $weekday = (int)$day->format('N');
        if ($excludeWeekends && ($weekday === 6 || $weekday === 7)) {
            continue;
        }
        $days++;
    }
    return $days;
}

function calculateScheduledAbsences(
    PDO $pdo,
    int $employeeId,
    string $startDate,
    string $endDate,
    string $employeeStartDate,
    array $presentDates,
    ?string $asOfDate = null
): array {
    if (!isValidDateValue($startDate) || !isValidDateValue($endDate) || !isValidDateValue($employeeStartDate)) {
        return ['scheduled_days' => 0, 'absent' => 0];
    }

    $asOfDate = $asOfDate !== null && isValidDateValue($asOfDate) ? $asOfDate : date('Y-m-d');
    $effectiveStart = max($startDate, $employeeStartDate);
    $effectiveEnd = min($endDate, $asOfDate);
    if ($effectiveEnd < $effectiveStart) {
        return ['scheduled_days' => 0, 'absent' => 0];
    }

    $holidayStmt = $pdo->prepare('SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?');
    $holidayStmt->execute([$effectiveStart, $effectiveEnd]);
    $holidayDates = array_fill_keys($holidayStmt->fetchAll(PDO::FETCH_COLUMN), true);

    $approvedLeaveDates = [];
    $leaveStmt = $pdo->prepare('SELECT start_date, end_date FROM leave_requests
        WHERE employee_id = ? AND status = "approved" AND start_date <= ? AND end_date >= ?');
    $leaveStmt->execute([$employeeId, $effectiveEnd, $effectiveStart]);
    foreach ($leaveStmt->fetchAll() as $leave) {
        $leaveStart = max($effectiveStart, (string)$leave['start_date']);
        $leaveEnd = min($effectiveEnd, (string)$leave['end_date']);
        $leavePeriod = new DatePeriod(
            new DateTimeImmutable($leaveStart),
            new DateInterval('P1D'),
            (new DateTimeImmutable($leaveEnd))->modify('+1 day')
        );
        foreach ($leavePeriod as $leaveDay) {
            $approvedLeaveDates[$leaveDay->format('Y-m-d')] = true;
        }
    }

    $presentLookup = array_is_list($presentDates) ? array_fill_keys($presentDates, true) : $presentDates;
    $scheduledDays = 0;
    $absent = 0;
    $period = new DatePeriod(
        new DateTimeImmutable($effectiveStart),
        new DateInterval('P1D'),
        (new DateTimeImmutable($effectiveEnd))->modify('+1 day')
    );
    foreach ($period as $day) {
        $dateKey = $day->format('Y-m-d');
        $daySchedule = getEmployeeScheduleForDate($pdo, $employeeId, $dateKey);
        if ((int)$daySchedule['scheduled_workday'] !== 1 || isset($holidayDates[$dateKey]) || isset($approvedLeaveDates[$dateKey])) {
            continue;
        }
        $scheduledDays++;
        if (!isset($presentLookup[$dateKey])) {
            $timezone = new DateTimeZone((string)$daySchedule['timezone']);
            $now = new DateTimeImmutable('now', $timezone);
            $scheduledEnd = new DateTimeImmutable($dateKey . ' ' . $daySchedule['work_end_time'] . ':00', $timezone);
            if ($daySchedule['work_end_time'] <= $daySchedule['work_start_time']) {
                $scheduledEnd = $scheduledEnd->modify('+1 day');
            }
            if ($dateKey !== $now->format('Y-m-d') || $now >= $scheduledEnd) {
                $absent++;
            }
        }
    }

    return ['scheduled_days' => $scheduledDays, 'absent' => $absent];
}

function getClientIpAddress(): string {
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function userFacingException(Throwable $e, string $fallback): string {
    if ($e instanceof PDOException) {
        error_log($fallback . ' Database error: ' . $e->getMessage());
        return $fallback;
    }
    return $e->getMessage();
}

function createEmployeeNotification(PDO $pdo, int $employeeId, string $title, string $message): void {
    if ($employeeId <= 0 || trim($title) === '' || trim($message) === '') {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO employee_notifications (employee_id, title, message, is_read) VALUES (?, ?, ?, 0)');
    $stmt->execute([$employeeId, trim($title), trim($message)]);
}

function getLeaveWorkingDates(PDO $pdo, string $startDate, string $endDate, ?int $employeeId = null): array {
    if (!isValidDateValue($startDate) || !isValidDateValue($endDate) || $endDate < $startDate) {
        return [];
    }
    $holidayStmt = $pdo->prepare('SELECT holiday_date FROM holidays WHERE holiday_date >= ? AND holiday_date <= ?');
    $holidayStmt->execute([$startDate, $endDate]);
    $holidays = array_fill_keys($holidayStmt->fetchAll(PDO::FETCH_COLUMN), true);
    $dates = [];
    $period = new DatePeriod(
        new DateTimeImmutable($startDate),
        new DateInterval('P1D'),
        (new DateTimeImmutable($endDate))->modify('+1 day')
    );
    foreach ($period as $date) {
        $dateValue = $date->format('Y-m-d');
        $isWorkday = $employeeId !== null && $employeeId > 0
            ? (int)getEmployeeScheduleForDate($pdo, $employeeId, $dateValue)['scheduled_workday'] === 1
            : (int)$date->format('N') <= 5;
        if ($isWorkday && !isset($holidays[$dateValue])) {
            $dates[] = $dateValue;
        }
    }
    return $dates;
}

function calculateLeaveRequestCharges(
    PDO $pdo,
    string $requestUnit,
    string $startDate,
    string $endDate,
    string $hoursRequested = '',
    ?int $employeeId = null
): array {
    if (!in_array($requestUnit, ['days', 'hours'], true)) {
        throw new RuntimeException('Choose a valid leave request type.');
    }
    if (!isValidDateValue($startDate) || !isValidDateValue($endDate) || $endDate < $startDate) {
        throw new RuntimeException('Choose valid leave dates.');
    }
    if ($requestUnit === 'days') {
        $charges = [];
        foreach (getLeaveWorkingDates($pdo, $startDate, $endDate, $employeeId) as $chargeDate) {
            $charges[$chargeDate] = LEAVE_MINUTES_PER_DAY;
        }
        if (!$charges) {
            throw new RuntimeException('The selected range has no chargeable workdays. Scheduled rest days and company holidays are excluded.');
        }
        return $charges;
    }
    if ($startDate !== $endDate || !is_numeric($hoursRequested)) {
        throw new RuntimeException('Hourly leave requires one date and a valid hour amount.');
    }
    $minutes = (int)round((float)$hoursRequested * 60);
    if ($minutes < 30 || $minutes > LEAVE_MINUTES_PER_DAY || $minutes % 30 !== 0) {
        throw new RuntimeException('Hourly leave must be between 0.5 and 8 hours in 0.5-hour increments.');
    }
    if (getLeaveWorkingDates($pdo, $startDate, $startDate, $employeeId) !== [$startDate]) {
        throw new RuntimeException('Hourly leave must be requested on a scheduled workday that is not a company holiday.');
    }
    return [$startDate => $minutes];
}

function getLeavePolicyMinutes(PDO $pdo, int $leaveTypeId, int $year): int {
    $stmt = $pdo->prepare('SELECT annual_minutes FROM leave_entitlement_policies
        WHERE leave_type_id = ? AND effective_year <= ? ORDER BY effective_year DESC, id DESC LIMIT 1');
    $stmt->execute([$leaveTypeId, $year]);
    return max(0, (int)($stmt->fetchColumn() ?: 0));
}

function getEmployeeLeaveBalances(PDO $pdo, int $employeeId, int $year, bool $includeInactive = false): array {
    $typesSql = 'SELECT id, name, active FROM leave_types';
    if (!$includeInactive) {
        $typesSql .= ' WHERE active = 1';
    }
    $typesSql .= ' ORDER BY active DESC, name ASC';
    $leaveTypes = $pdo->query($typesSql)->fetchAll();

    $adjustmentStmt = $pdo->prepare('SELECT leave_type_id, COALESCE(SUM(adjustment_minutes), 0) AS total
        FROM leave_balance_adjustments WHERE employee_id = ? AND period_year = ? GROUP BY leave_type_id');
    $adjustmentStmt->execute([$employeeId, $year]);
    $adjustments = [];
    foreach ($adjustmentStmt->fetchAll() as $row) {
        $adjustments[(int)$row['leave_type_id']] = (int)$row['total'];
    }

    $usageStmt = $pdo->prepare('SELECT lr.leave_type_id, lr.status, COALESCE(SUM(lrc.minutes), 0) AS total
        FROM leave_requests lr
        INNER JOIN leave_request_charges lrc ON lrc.leave_request_id = lr.id
        WHERE lr.employee_id = ? AND YEAR(lrc.charge_date) = ? AND lr.status IN ("approved", "pending")
        GROUP BY lr.leave_type_id, lr.status');
    $usageStmt->execute([$employeeId, $year]);
    $usage = [];
    foreach ($usageStmt->fetchAll() as $row) {
        $usage[(int)$row['leave_type_id']][(string)$row['status']] = (int)$row['total'];
    }

    $balances = [];
    foreach ($leaveTypes as $leaveType) {
        $leaveTypeId = (int)$leaveType['id'];
        $annual = getLeavePolicyMinutes($pdo, $leaveTypeId, $year);
        $adjustment = $adjustments[$leaveTypeId] ?? 0;
        $used = $usage[$leaveTypeId]['approved'] ?? 0;
        $pending = $usage[$leaveTypeId]['pending'] ?? 0;
        $balances[] = [
            'leave_type_id' => $leaveTypeId,
            'leave_type_name' => (string)$leaveType['name'],
            'active' => (int)$leaveType['active'],
            'annual_minutes' => $annual,
            'adjustment_minutes' => $adjustment,
            'used_minutes' => $used,
            'pending_minutes' => $pending,
            'available_minutes' => $annual + $adjustment - $used,
            'projected_minutes' => $annual + $adjustment - $used - $pending,
        ];
    }
    return $balances;
}

function getEmployeeLeaveBalance(PDO $pdo, int $employeeId, int $leaveTypeId, int $year): array {
    foreach (getEmployeeLeaveBalances($pdo, $employeeId, $year, true) as $balance) {
        if ((int)$balance['leave_type_id'] === $leaveTypeId) {
            return $balance;
        }
    }
    return [
        'leave_type_id' => $leaveTypeId,
        'leave_type_name' => '',
        'active' => 0,
        'annual_minutes' => 0,
        'adjustment_minutes' => 0,
        'used_minutes' => 0,
        'pending_minutes' => 0,
        'available_minutes' => 0,
        'projected_minutes' => 0,
    ];
}

function formatLeaveMinutes(int $minutes, string $unit = 'days'): string {
    $divisor = $unit === 'hours' ? 60 : LEAVE_MINUTES_PER_DAY;
    $value = $minutes / $divisor;
    return number_format($value, abs($value - round($value)) < 0.00001 ? 0 : 2, '.', '');
}

function logAdminAudit(
    PDO $pdo,
    int $adminId,
    string $action,
    ?int $affectedEmployeeId = null,
    string $details = '',
    ?array $oldValues = null,
    ?array $newValues = null,
    ?string $objectType = null,
    ?int $objectId = null
): void {
    if ($adminId <= 0 || trim($action) === '') {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO audit_logs
        (admin_id, action, affected_employee_id, details, ip_address, old_values, new_values, object_type, object_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $ipAddress = getClientIpAddress();
    $stmt->execute([
        $adminId,
        trim($action),
        $affectedEmployeeId,
        trim($details) === '' ? null : trim($details),
        $ipAddress === '' ? null : $ipAddress,
        $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $objectType,
        $objectId,
    ]);
}

function isStrongPassword(string $password): bool {
    return strlen($password) >= 12
        && preg_match('/[a-z]/', $password) === 1
        && preg_match('/[A-Z]/', $password) === 1
        && preg_match('/\d/', $password) === 1
        && preg_match('/[^a-zA-Z0-9]/', $password) === 1;
}

function loginRateLimitKey(string $email): array {
    return [hash('sha256', strtolower(trim($email))), hash('sha256', getClientIpAddress())];
}

function isLoginRateLimited(PDO $pdo, string $email): bool {
    [$emailHash, $ipHash] = loginRateLimitKey($email);
    $stmt = $pdo->prepare('SELECT blocked_until FROM login_attempts WHERE email_hash = ? AND ip_hash = ? LIMIT 1');
    $stmt->execute([$emailHash, $ipHash]);
    $blockedUntil = $stmt->fetchColumn();
    return $blockedUntil !== false && $blockedUntil !== null && strtotime((string)$blockedUntil) > time();
}

function recordLoginFailure(PDO $pdo, string $email): void {
    [$emailHash, $ipHash] = loginRateLimitKey($email);
    $stmt = $pdo->prepare('INSERT INTO login_attempts (email_hash, ip_hash, attempt_count, window_started, blocked_until, last_attempt_at)
        VALUES (?, ?, 1, NOW(), NULL, NOW())
        ON DUPLICATE KEY UPDATE
            blocked_until = IF(
                window_started < DATE_SUB(NOW(), INTERVAL 15 MINUTE),
                NULL,
                IF(attempt_count + 1 >= 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), blocked_until)
            ),
            attempt_count = IF(window_started < DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, attempt_count + 1),
            window_started = IF(window_started < DATE_SUB(NOW(), INTERVAL 15 MINUTE), NOW(), window_started),
            last_attempt_at = NOW()');
    $stmt->execute([$emailHash, $ipHash]);
}

function clearLoginFailures(PDO $pdo, string $email): void {
    [$emailHash, $ipHash] = loginRateLimitKey($email);
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE email_hash = ? AND ip_hash = ?');
    $stmt->execute([$emailHash, $ipHash]);
}
