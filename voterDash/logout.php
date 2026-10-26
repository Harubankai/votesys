<?php
// End the voter's session and go back to the login page.
// Only a POST with this session's CSRF token logs out, so another site can't log the voter out.
require dirname(__DIR__) . '/adminDash/includes/init.php';

start_secure_session();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../voter_dashB.php');
    exit;
}
verify_csrf('../voter_dashB.php');

end_session();

header('Location: ../index.php');
exit;
