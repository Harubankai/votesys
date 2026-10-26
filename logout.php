<?php
// End the admin session and go back to the admin login page.
// Only a POST with this session's CSRF token logs out, so another site can't log the admin out.
require __DIR__ . '/adminDash/includes/init.php';

start_secure_session();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_dashB.php');
    exit;
}
verify_csrf('admin_dashB.php');

end_session();

header('Location: adminDash/login.php');
exit;
