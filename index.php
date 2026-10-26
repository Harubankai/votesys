<?php
// Voter list ($voters) comes from the shared setup
require __DIR__ . '/adminDash/includes/init.php';

start_secure_session();
verify_csrf('index.php');

$error = '';
$notice = take_notice();
$voter_id = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $voter_id = strtoupper(trim((string) ($_POST['voter_id'] ?? '')));

    if ($voter_id === '') {
        $error = "Please enter your Voter's ID.";
    } else {
        $found = null;
        foreach ($voters as $voter) {
            if (strcasecmp($voter['voter_id'], $voter_id) === 0) {
                $found = $voter;
                break;
            }
        }

        if ($found === null) {
            $error = "Voter's ID not found. Please check your ID and try again.";
        } else {
            renew_session_after_login();
            $_SESSION['voter_id'] = $found['voter_id'];
            $_SESSION['login_time'] = time();
            header('Location: voter_dashB.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Online Voting System</title>
    <link rel="stylesheet" href="css/login.css">
</head>
<body>
    <main class="login-card">
        <img class="logo" src="images/logo.png" alt="St. Rose College logo">
        <h2>Online Voting System of Supreme Student Council</h2>
        <h4>St. Rose College Educational Foundation Inc.</h4>

        <?php if ($error !== ''): ?>
            <p class="error"><?php echo h($error); ?></p>
        <?php elseif ($notice !== ''): ?>
            <p class="notice"><?php echo h($notice); ?></p>
        <?php endif; ?>

        <form method="POST" action="index.php">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="voter_id">Voter's ID</label>
                <input type="text" id="voter_id" name="voter_id"
                       placeholder="Enter your Voter's ID"
                       value="<?php echo h($voter_id); ?>" maxlength="20" autocomplete="off"
                       required autofocus>
            </div>

            <button type="submit" name="login" class="btn-login">Login</button>
        </form>

        <p class="login-links">
            <a href="tally.php">View Live Tally &amp; Rankings &rarr;</a>
        </p>
    </main>
</body>
</html>
