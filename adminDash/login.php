<?php
// Admin login. When no admin account exists yet, this page instead lets someone
// on the server computer itself create the first one.
require __DIR__ . '/includes/init.php';

start_secure_session();
verify_csrf('login.php');

if (!empty($_SESSION['admin_id'])) {
    header('Location: ../admin_dashB.php');
    exit;
}

$setup = (int) $db->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0;
$error = '';
$notice = take_notice();
$username = '';

// ---------------------------------------------------------------
// Create the first admin account
// ---------------------------------------------------------------
if ($setup && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_admin'])) {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!is_local_request()) {
        $error = 'The first admin account can only be created on the server computer (http://localhost).';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        $error = 'Username must be 3 to 50 letters, numbers, dots, dashes or underscores.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== (string) ($_POST['confirm_password'] ?? '')) {
        $error = 'The password and its confirmation do not match.';
    } else {
        // Only if still no admin exists (two people could submit at the same time)
        $db->prepare(
            'INSERT INTO admins (username, password_hash)
             SELECT ?, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM admins)'
        )->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);

        $_SESSION['notice'] = 'Admin account created. Please log in.';
        header('Location: login.php');
        exit;
    }
}

// ---------------------------------------------------------------
// Log in
// ---------------------------------------------------------------
if (!$setup && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $find = $db->prepare('SELECT id, username, password_hash, failed_attempts, locked_until FROM admins WHERE username = ?');
    $find->execute([$username]);
    $admin = $find->fetch();

    if ($admin && !empty($admin['locked_until']) && strtotime($admin['locked_until']) > time()) {
        $error = 'Too many wrong passwords. Please try again after ' . date('g:i A', strtotime($admin['locked_until'])) . '.';
    } elseif ($admin && password_verify($password, $admin['password_hash'])) {
        $db->prepare('UPDATE admins SET failed_attempts = 0, locked_until = NULL, last_login_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $admin['id']]);
        if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
            $db->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
        }

        renew_session_after_login();
        $_SESSION['admin_id'] = (int) $admin['id'];
        header('Location: ../admin_dashB.php');
        exit;
    } else {
        if ($admin) {
            $failures = (int) $admin['failed_attempts'] + 1;
            if ($failures >= ADMIN_MAX_ATTEMPTS) {
                $db->prepare('UPDATE admins SET failed_attempts = 0, locked_until = ? WHERE id = ?')
                    ->execute([date('Y-m-d H:i:s', strtotime('+' . ADMIN_LOCK_MINUTES . ' minutes')), $admin['id']]);
            } else {
                $db->prepare('UPDATE admins SET failed_attempts = ? WHERE id = ?')->execute([$failures, $admin['id']]);
            }
        } else {
            // Same work as a real check, so the response time doesn't reveal which usernames exist
            password_verify($password, '$2y$10$J8xUkp8gJbSQCH12X1ddZ.559eL5K2oLVv3yNjxr/4HNI6VCghY0u');
        }
        $error = 'Wrong username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | Online Voting System</title>
    <link rel="stylesheet" href="../css/login.css">
</head>
<body>
    <main class="login-card">
        <img class="logo" src="../images/logo.png" alt="St. Rose College logo">
        <h2><?php echo $setup ? 'Create Admin Account' : 'Admin Login'; ?></h2>
        <h4>Online Voting System of Supreme Student Council</h4>

        <?php if ($error !== ''): ?>
            <p class="error"><?php echo h($error); ?></p>
        <?php elseif ($notice !== ''): ?>
            <p class="notice"><?php echo h($notice); ?></p>
        <?php endif; ?>

        <?php if ($setup && !is_local_request()): ?>
            <p class="notice">
                No admin account exists yet. Open this page on the server computer
                (<strong>http://localhost/votesys/adminDash/login.php</strong>) to create the first one.
            </p>
        <?php elseif ($setup): ?>
            <p class="notice">No admin account exists yet. Create the first one below.</p>

            <form method="POST" action="login.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" maxlength="50"
                           value="<?php echo h($username); ?>" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>
                    <p class="hint">At least 8 characters.</p>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>
                </div>
                <button type="submit" name="create_admin" class="btn-login">Create Account</button>
            </form>
        <?php else: ?>
            <form method="POST" action="login.php">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" maxlength="50" autocomplete="username"
                           value="<?php echo h($username); ?>" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" autocomplete="current-password" required>
                </div>
                <button type="submit" name="login" class="btn-login">Login</button>
            </form>
        <?php endif; ?>

        <p class="login-links">
            <a href="../index.php">&larr; Voter Login</a>
        </p>
    </main>
</body>
</html>
