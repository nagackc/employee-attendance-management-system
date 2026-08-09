<?php
require __DIR__ . '/../functions/helpers.php';
require __DIR__ . '/../config/database.php';

if (isLoggedIn() && revalidateSessionUser($pdo)) {
    redirect(isAdmin() ? '../admin/dashboard.php' : '../employee/dashboard.php');
} elseif (isLoggedIn()) {
    destroyUserSession();
}

$message = '';
$emailValue = '';
$companyName = getSetting($pdo, 'company_name', 'EAMS Demo Company');
$companyLogo = resolveCompanyLogoUrl(getSetting($pdo, 'company_logo', ''), '../');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid CSRF token.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $emailValue = $email;
        $password = $_POST['password'] ?? '';

        $genericError = 'Unable to sign in with the provided credentials.';
        if ($email === '' || $password === '' || isLoginRateLimited($pdo, $email)) {
            if ($email !== '') {
                recordLoginFailure($pdo, $email);
            }
            $message = $genericError;
        } else {
            $stmt = $pdo->prepare('SELECT * FROM employees WHERE LOWER(email) = LOWER(?) AND active = 1 LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                clearLoginFailures($pdo, $email);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['company'] = $user['company'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity'] = time();

                if (isAdmin()) {
                    redirect('../admin/dashboard.php');
                }
                redirect('../employee/dashboard.php');
            } else {
                if (!$user) {
                    password_verify($password, '$2y$10$5FJgIfgm8cM4yHknYysYXOEao9QMfdoCQpdsmzBkT3vs3T6hH3Hse');
                }
                recordLoginFailure($pdo, $email);
                $message = $genericError;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | EAMS</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="auth-page">
    <div class="auth-wrapper">
        <div class="auth-brand">
            <div class="auth-brand-logo">
                <?php if ($companyLogo !== ''): ?>
                    <img src="<?= h($companyLogo) ?>" alt="<?= h($companyName) ?> logo">
                <?php else: ?>
                    <span>EAMS</span>
                <?php endif; ?>
            </div>
            <h1><?= h($companyName) ?></h1>
            <p>Employee Attendance Management System</p>
        </div>

        <div class="auth-card">
            <h2>Welcome back</h2>
            <p class="muted">Please sign in to continue.</p>

            <?php if ($message): ?><div class="message"><?= h($message) ?></div><?php endif; ?>

            <form method="post" class="form-layout">
                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                <div>
                    <label for="email" class="required">Email</label>
                    <input id="email" type="email" name="email" value="<?= h($emailValue) ?>" placeholder="you@company.com" required autocomplete="username">
                </div>
                <div>
                    <label for="password" class="required">Password</label>
                    <input id="password" type="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
                </div>
                <button type="submit">Sign In</button>
            </form>

            <p class="auth-link">Need access? Contact your HR administrator.</p>
        </div>
    </div>

</body>
</html>
