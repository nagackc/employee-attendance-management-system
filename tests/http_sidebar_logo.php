<?php
declare(strict_types=1);

$testEnvironmentValue = static function (string $canonicalKey, string $legacyKey): string {
    $value = getenv($canonicalKey);
    if ($value === false) {
        $value = getenv($legacyKey);
    }
    return $value === false ? '' : (string)$value;
};

$baseUrl = rtrim($testEnvironmentValue('EAMS_TEST_BASE_URL', 'FJ_TEST_BASE_URL'), '/');
$adminEmail = $testEnvironmentValue('EAMS_TEST_ADMIN_EMAIL', 'FJ_TEST_ADMIN_EMAIL');
$employeeEmail = $testEnvironmentValue('EAMS_TEST_EMPLOYEE_EMAIL', 'FJ_TEST_EMPLOYEE_EMAIL');
$password = $testEnvironmentValue('EAMS_TEST_PASSWORD', 'FJ_TEST_PASSWORD');
$attendanceId = (int)$testEnvironmentValue('EAMS_TEST_ATTENDANCE_ID', 'FJ_TEST_ATTENDANCE_ID');
if ($baseUrl === '' || $adminEmail === '' || $employeeEmail === '' || $password === '') {
    fwrite(STDERR, "Set EAMS_TEST_BASE_URL, EAMS_TEST_ADMIN_EMAIL, EAMS_TEST_EMPLOYEE_EMAIL, and EAMS_TEST_PASSWORD.\n");
    exit(1);
}

$passed = [];
$assert = static function (bool $condition, string $label) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $label);
    }
    $passed[] = $label;
};

$request = static function (string $url, string $cookieJar, string|array|null $postFields = null): array {
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_USERAGENT => 'EAMS-Verification/1.0',
    ]);
    if ($postFields !== null) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $postFields);
        if (is_string($postFields)) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        }
    }
    $body = curl_exec($handle);
    if (!is_string($body)) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($handle));
    }
    $result = [
        'status' => (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'url' => (string)curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
        'body' => $body,
    ];
    curl_close($handle);
    return $result;
};

$parseHtml = static function (string $html): DOMXPath {
    $document = new DOMDocument();
    libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();
    return new DOMXPath($document);
};

$inputValue = static function (DOMXPath $xpath, string $name): string {
    $node = $xpath->query('//input[@name="' . $name . '"]')->item(0);
    return $node instanceof DOMElement ? $node->getAttribute('value') : '';
};

$settingFields = static function (string $html) use ($parseHtml, $inputValue): array {
    $xpath = $parseHtml($html);
    $timezone = $xpath->query('//select[@name="timezone"]/option[@selected]')->item(0);
    return [
        'csrf_token' => $inputValue($xpath, 'csrf_token'),
        'company_name' => $inputValue($xpath, 'company_name'),
        'timezone' => $timezone instanceof DOMElement ? $timezone->getAttribute('value') : '',
        'work_start_time' => $inputValue($xpath, 'work_start_time'),
        'work_end_time' => $inputValue($xpath, 'work_end_time'),
        'grace_period_minutes' => $inputValue($xpath, 'grace_period_minutes'),
    ];
};

$login = static function (string $email, string $cookieJar) use ($baseUrl, $password, $request, $parseHtml, $inputValue): array {
    $loginPage = $request($baseUrl . '/pages/login.php', $cookieJar);
    $token = $inputValue($parseHtml($loginPage['body']), 'csrf_token');
    return $request($baseUrl . '/pages/login.php', $cookieJar, http_build_query([
        'csrf_token' => $token,
        'email' => $email,
        'password' => $password,
    ]));
};

$adminCookie = tempnam(sys_get_temp_dir(), 'fj-admin-cookie-');
$employeeCookie = tempnam(sys_get_temp_dir(), 'fj-employee-cookie-');
$imageFixture = tempnam(sys_get_temp_dir(), 'fj-logo-image-');
$uploadedLogoPath = '';

try {
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    if ($pngBytes === false || file_put_contents($imageFixture, $pngBytes) === false) {
        throw new RuntimeException('Unable to create the HTTP upload fixture.');
    }

    $adminLogin = $login($adminEmail, $adminCookie);
    $assert($adminLogin['status'] === 200 && str_contains($adminLogin['url'], '/admin/dashboard.php'),
        'admin login reaches the dashboard');

    $adminPages = [
        'dashboard.php', 'employees.php', 'manage_employee.php', 'attendance.php',
        'attendance_corrections.php', 'shifts.php', 'reports.php?type=date_range',
        'payroll_export.php', 'leave_management.php', 'team_availability.php',
        'leave_balances.php', 'leave_types.php', 'holiday_management.php',
        'announcements.php', 'manage_announcement.php', 'settings.php', 'audit_logs.php',
    ];
    if ($attendanceId > 0) {
        $adminPages[] = 'edit_attendance.php?id=' . $attendanceId;
    }
    foreach ($adminPages as $page) {
        $response = $request($baseUrl . '/admin/' . $page, $adminCookie);
        $assert($response['status'] === 200
            && str_contains($response['body'], 'data-portal-shell')
            && str_contains($response['body'], 'portal-sidebar'),
            'admin layout renders for ' . $page);
    }

    $settingsPage = $request($baseUrl . '/admin/settings.php', $adminCookie);
    $fields = $settingFields($settingsPage['body']);
    $assert(!in_array('', $fields, true), 'settings form exposes all required values');
    $uploadFields = ['MAX_FILE_SIZE' => '2097152'] + $fields;
    $uploadFields['company_logo_file'] = new CURLFile($imageFixture, 'image/png', 'company-logo.png');
    $uploadResponse = $request($baseUrl . '/admin/settings.php', $adminCookie, $uploadFields);
    $assert($uploadResponse['status'] === 200 && str_contains($uploadResponse['body'], 'Settings saved successfully.'),
        'a genuine PNG logo uploads through the admin settings form');

    $uploadedSettings = $request($baseUrl . '/admin/settings.php', $adminCookie);
    $uploadedXpath = $parseHtml($uploadedSettings['body']);
    $logoImage = $uploadedXpath->query('//*[@id="settings-logo-preview"]//img')->item(0);
    $logoSource = $logoImage instanceof DOMElement ? $logoImage->getAttribute('src') : '';
    $assert(preg_match('#^\.\./assets/uploads/company-logo-[a-f0-9]{32}\.png$#', $logoSource) === 1,
        'the uploaded logo uses a randomized managed path');
    $uploadedLogoPath = __DIR__ . '/../assets/uploads/' . basename($logoSource);
    $assert(is_file($uploadedLogoPath), 'the uploaded logo file is present in the protected uploads directory');

    $employeeLogin = $login($employeeEmail, $employeeCookie);
    $assert($employeeLogin['status'] === 200 && str_contains($employeeLogin['url'], '/employee/dashboard.php'),
        'employee login reaches the dashboard');
    foreach (['dashboard.php', 'calendar.php', 'history.php', 'announcements.php', 'profile.php'] as $page) {
        $response = $request($baseUrl . '/employee/' . $page, $employeeCookie);
        $assert($response['status'] === 200
            && str_contains($response['body'], 'data-portal-shell')
            && str_contains($response['body'], htmlspecialchars($logoSource, ENT_QUOTES, 'UTF-8')),
            'employee layout and shared logo render for ' . $page);
    }

    $conflictFields = ['MAX_FILE_SIZE' => '2097152'] + $settingFields($uploadedSettings['body']);
    $conflictFields['remove_company_logo'] = '1';
    $conflictFields['company_logo_file'] = new CURLFile($imageFixture, 'image/png', 'replacement.png');
    $conflictResponse = $request($baseUrl . '/admin/settings.php', $adminCookie, $conflictFields);
    $assert(str_contains($conflictResponse['body'], 'Choose either a new logo upload or remove the current logo, not both.')
        && is_file($uploadedLogoPath), 'conflicting upload and removal is rejected without changing the managed logo');

    $removeFields = $settingFields($request($baseUrl . '/admin/settings.php', $adminCookie)['body']);
    $removeFields['remove_company_logo'] = '1';
    $removeResponse = $request($baseUrl . '/admin/settings.php', $adminCookie, http_build_query($removeFields));
    $assert(str_contains($removeResponse['body'], 'Settings saved successfully.'),
        'removing the logo clears the stored setting');
    clearstatcache(true, $uploadedLogoPath);
    $assert(!is_file($uploadedLogoPath), 'removing the logo deletes the previous managed file');
    $uploadedLogoPath = '';

    foreach ($passed as $label) {
        echo "PASS: $label\n";
    }
    echo 'Completed ' . count($passed) . " authenticated HTTP assertions.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    if ($uploadedLogoPath !== '' && is_file($uploadedLogoPath)) {
        @unlink($uploadedLogoPath);
    }
    foreach ([$adminCookie, $employeeCookie, $imageFixture] as $temporaryFile) {
        if (is_string($temporaryFile) && is_file($temporaryFile)) {
            @unlink($temporaryFile);
        }
    }
}
