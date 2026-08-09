<?php
declare(strict_types=1);

require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin($pdo);
applyTimezone($pdo);

$adminId = (int)$_SESSION['user_id'];
$settingDefaults = [
    'company_name' => 'EAMS Demo Company',
    'company_logo' => '',
    'timezone' => DEFAULT_TIMEZONE,
    'work_start_time' => DEFAULT_WORK_START,
    'work_end_time' => DEFAULT_WORK_END,
    'grace_period_minutes' => (string)DEFAULT_GRACE_MINUTES,
];

$readSettings = static function (PDO $pdo, array $defaults): array {
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
    $values = $defaults;
    foreach ($stmt->fetchAll() as $row) {
        if (array_key_exists((string)$row['setting_key'], $defaults)) {
            $values[(string)$row['setting_key']] = (string)$row['setting_value'];
        }
    }
    return $values;
};
$uploadsDirectory = __DIR__ . '/../assets/uploads';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid request token. Please refresh the page and try again.');
        redirect('settings.php');
    }

    $oldSettings = $readSettings($pdo, $settingDefaults);
    $submitted = [
        'company_name' => trim((string)($_POST['company_name'] ?? '')),
        'company_logo' => $oldSettings['company_logo'],
        'timezone' => trim((string)($_POST['timezone'] ?? '')),
        'work_start_time' => trim((string)($_POST['work_start_time'] ?? '')),
        'work_end_time' => trim((string)($_POST['work_end_time'] ?? '')),
        'grace_period_minutes' => trim((string)($_POST['grace_period_minutes'] ?? '')),
    ];
    $removeLogo = (string)($_POST['remove_company_logo'] ?? '') === '1';
    $logoUpload = $_FILES['company_logo_file'] ?? ['error' => UPLOAD_ERR_NO_FILE];
    $logoUploadError = UPLOAD_ERR_NO_FILE;
    $hasLogoUpload = false;
    $validatedLogoExtension = '';

    $errors = [];
    if ($submitted['company_name'] === '' || mb_strlen($submitted['company_name']) > 150) {
        $errors[] = 'Company name is required and must not exceed 150 characters.';
    }

    if (!is_array($logoUpload) || !isset($logoUpload['error']) || !is_int($logoUpload['error'])) {
        $errors[] = 'The logo upload could not be processed.';
    } else {
        $logoUploadError = $logoUpload['error'];
        $hasLogoUpload = $logoUploadError !== UPLOAD_ERR_NO_FILE;
    }
    if ($removeLogo && $hasLogoUpload) {
        $errors[] = 'Choose either a new logo upload or remove the current logo, not both.';
    }
    if ($hasLogoUpload && $logoUploadError !== UPLOAD_ERR_OK) {
        $errors[] = match ($logoUploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Logo images must be 2 MB or smaller.',
            UPLOAD_ERR_PARTIAL => 'The logo upload was interrupted. Please try again.',
            default => 'The logo upload could not be processed.',
        };
    }
    if ($hasLogoUpload && $logoUploadError === UPLOAD_ERR_OK) {
        $temporaryPath = is_string($logoUpload['tmp_name'] ?? null) ? $logoUpload['tmp_name'] : '';
        $reportedSize = is_int($logoUpload['size'] ?? null) ? $logoUpload['size'] : -1;
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
            $errors[] = 'The logo upload could not be verified.';
        } else {
            $logoValidation = validateCompanyLogoImageFile($temporaryPath, $reportedSize);
            if (!$logoValidation['valid']) {
                $errors[] = $logoValidation['error'];
            } else {
                $validatedLogoExtension = $logoValidation['extension'];
            }
        }
    }
    if (!in_array($submitted['timezone'], DateTimeZone::listIdentifiers(), true)) {
        $errors[] = 'Select a valid timezone.';
    }
    foreach (['work_start_time' => 'Work start time', 'work_end_time' => 'Work end time'] as $key => $label) {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $submitted[$key])) {
            $errors[] = $label . ' must use a valid 24-hour time.';
        }
    }
    if (!preg_match('/^\d{1,3}$/', $submitted['grace_period_minutes'])
        || (int)$submitted['grace_period_minutes'] < 0
        || (int)$submitted['grace_period_minutes'] > 120) {
        $errors[] = 'Grace period must be between 0 and 120 minutes.';
    }

    if ($errors) {
        setFlash('error', implode(' ', $errors));
        redirect('settings.php');
    }

    $submitted['grace_period_minutes'] = (string)(int)$submitted['grace_period_minutes'];
    $newLogoAbsolutePath = '';
    if ($hasLogoUpload) {
        if (!is_dir($uploadsDirectory) || !is_writable($uploadsDirectory)) {
            setFlash('error', 'The logo upload directory is unavailable.');
            redirect('settings.php');
        }
        try {
            $logoFilename = 'company-logo-' . bin2hex(random_bytes(16)) . '.' . $validatedLogoExtension;
        } catch (Throwable $e) {
            error_log('Company logo filename generation failed: ' . $e->getMessage());
            setFlash('error', 'The logo could not be saved. Please try again.');
            redirect('settings.php');
        }
        $newLogoAbsolutePath = $uploadsDirectory . DIRECTORY_SEPARATOR . $logoFilename;
        if (!move_uploaded_file((string)$logoUpload['tmp_name'], $newLogoAbsolutePath)) {
            setFlash('error', 'The logo could not be saved. Please try again.');
            redirect('settings.php');
        }
        @chmod($newLogoAbsolutePath, 0644);
        $submitted['company_logo'] = 'assets/uploads/' . $logoFilename;
    } elseif ($removeLogo) {
        $submitted['company_logo'] = '';
    }

    try {
        $pdo->beginTransaction();
        $upsert = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($submitted as $key => $value) {
            $upsert->execute([$key, $value]);
        }
        logAdminAudit(
            $pdo,
            $adminId,
            'update_system_settings',
            null,
            'Updated company and attendance configuration.',
            $oldSettings,
            $submitted,
            'settings'
        );
        $pdo->commit();
        setFlash('success', 'Settings saved successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($newLogoAbsolutePath !== '' && is_file($newLogoAbsolutePath)) {
            @unlink($newLogoAbsolutePath);
        }
        setFlash('error', userFacingException($e, 'Settings could not be saved.'));
        redirect('settings.php');
    }
    if ($oldSettings['company_logo'] !== $submitted['company_logo']) {
        deleteManagedCompanyLogo($oldSettings['company_logo'], $uploadsDirectory);
    }
    redirect('settings.php');
}

$settings = $readSettings($pdo, $settingDefaults);
$settingsLogoUrl = resolveCompanyLogoUrl($settings['company_logo'], '../');
$timezones = DateTimeZone::listIdentifiers();
$pageTitle = 'Settings';
$activePage = 'settings';
$activeSubPage = 'system_settings';
include __DIR__ . '/../includes/admin_layout_start.php';
?>

<section class="page-header">
    <div>
        <h1>Settings</h1>
        <p>Manage company identity, timezone, and the default attendance schedule.</p>
    </div>
</section>

<form method="post" enctype="multipart/form-data" class="settings-form">
    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">

    <div class="settings-grid">
        <article class="content-card settings-card">
            <div class="card-header">
                <div><h3>Company Identity</h3><p class="muted">Shown throughout the employee and admin portals.</p></div>
            </div>
            <div class="settings-logo-row">
                <div class="settings-logo-preview" id="settings-logo-preview" data-logo-initial-src="<?= h($settingsLogoUrl) ?>">
                    <?php if ($settingsLogoUrl !== ''): ?>
                        <img src="<?= h($settingsLogoUrl) ?>" alt="Current company logo" id="settings-logo-image">
                    <?php else: ?>
                        <span id="settings-logo-fallback">EA</span>
                    <?php endif; ?>
                </div>
                <div class="settings-logo-fields">
                    <label class="required" for="company-name">Company Name</label>
                    <input id="company-name" type="text" name="company_name" maxlength="150" value="<?= h($settings['company_name']) ?>" required>
                    <label for="company-logo-file">Company Logo</label>
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?= COMPANY_LOGO_MAX_BYTES ?>">
                    <input id="company-logo-file" type="file" name="company_logo_file" accept="image/jpeg,image/png,image/webp" data-logo-file-input>
                    <p class="field-help">JPG, PNG, or WebP. Maximum file size: 2 MB.</p>
                    <p class="settings-logo-file-name" data-logo-file-name aria-live="polite"></p>
                    <?php if ($settings['company_logo'] !== ''): ?>
                        <label class="settings-logo-remove" for="remove-company-logo">
                            <input id="remove-company-logo" type="checkbox" name="remove_company_logo" value="1" data-logo-remove>
                            <span>Remove current logo</span>
                        </label>
                    <?php endif; ?>
                </div>
            </div>
        </article>

        <article class="content-card settings-card">
            <div class="card-header">
                <div><h3>Regional Settings</h3><p class="muted">Dates and attendance events use this timezone.</p></div>
            </div>
            <label class="required" for="timezone">Timezone</label>
            <select id="timezone" name="timezone" required>
                <?php foreach ($timezones as $timezone): ?>
                    <option value="<?= h($timezone) ?>" <?= $settings['timezone'] === $timezone ? 'selected' : '' ?>><?= h($timezone) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="settings-current-time">
                <span>Current portal time</span>
                <strong><?= h(date('F j, Y · H:i')) ?></strong>
            </div>
        </article>
    </div>

    <article class="content-card settings-card">
        <div class="card-header">
            <div><h3>Attendance Schedule</h3><p class="muted">Used to determine lateness and scheduled working hours.</p></div>
        </div>
        <div class="form-grid-3">
            <div>
                <label class="required" for="work-start-time">Work Start Time</label>
                <input id="work-start-time" type="time" name="work_start_time" value="<?= h(substr($settings['work_start_time'], 0, 5)) ?>" required>
            </div>
            <div>
                <label class="required" for="work-end-time">Work End Time</label>
                <input id="work-end-time" type="time" name="work_end_time" value="<?= h(substr($settings['work_end_time'], 0, 5)) ?>" required>
            </div>
            <div>
                <label class="required" for="grace-period">Grace Period</label>
                <div class="input-with-suffix"><input id="grace-period" type="number" name="grace_period_minutes" min="0" max="120" value="<?= h($settings['grace_period_minutes']) ?>" required><span>minutes</span></div>
            </div>
        </div>
    </article>

    <div class="settings-actions">
        <span class="muted">Changes apply to new page loads and attendance sessions.</span>
        <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
</form>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>
