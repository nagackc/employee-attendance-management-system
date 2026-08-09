
<?php
require __DIR__ . '/functions/helpers.php';
require __DIR__ . '/config/database.php';
if (isLoggedIn()) {
    if (revalidateSessionUser($pdo)) {
        redirect(isAdmin() ? 'admin/dashboard.php' : 'employee/dashboard.php');
    }
    destroyUserSession();
}
redirect('pages/login.php');

?>
