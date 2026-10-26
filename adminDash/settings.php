<?php
// $settings and $election_title come from includes/init.php (the dashboards use them too)
require __DIR__ . '/includes/init.php';

$page = 'settings';
$root_url = '../';
require_admin($root_url);   // admins only; also checks the CSRF token of every POST

$max_code_attempts = 5;    // wrong end codes allowed before locking
$code_lock_minutes = 15;

// Checks the "End Voting Now" code against the stored hash. Wrong codes are counted,
// and after too many the code is locked for a while. Returns an error message, or null if correct.
function verify_end_code(PDO $db, $code, $max_attempts, $lock_minutes)
{
    $row = $db->query('SELECT end_code_hash, end_code_failures, end_code_locked_until FROM settings WHERE id = 1')->fetch();

    if (!empty($row['end_code_locked_until']) && strtotime($row['end_code_locked_until']) > time()) {
        return 'Too many wrong codes. Please try again after ' . date('g:i A', strtotime($row['end_code_locked_until'])) . '.';
    }
    if (empty($row['end_code_hash'])) {
        return 'No end code is set up. Import database/votesys.sql again to set the starting code.';
    }
    if (password_verify($code, $row['end_code_hash'])) {
        $db->exec('UPDATE settings SET end_code_failures = 0, end_code_locked_until = NULL WHERE id = 1');
        return null;
    }

    $failures = (int) $row['end_code_failures'] + 1;
    if ($failures >= $max_attempts) {
        $db->prepare('UPDATE settings SET end_code_failures = 0, end_code_locked_until = ? WHERE id = 1')
            ->execute([date('Y-m-d H:i:s', strtotime("+$lock_minutes minutes"))]);
        return "Wrong code. Too many wrong attempts, so this is locked for $lock_minutes minutes.";
    }
    $db->prepare('UPDATE settings SET end_code_failures = ? WHERE id = 1')->execute([$failures]);
    return 'Wrong code. ' . ($max_attempts - $failures) . ' attempt(s) left.';
}

$settings_error = '';
$settings_success = '';
$end_error = '';
$code_error = '';
$code_success = '';

// ---------------------------------------------------------------
// Save the election title and schedule
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $title = trim($_POST['election_title'] ?? '');
    $start = trim($_POST['start'] ?? '');
    $end = trim($_POST['end'] ?? '');

    if ($title === '') {
        $settings_error = 'Please enter the election title.';
    } elseif ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false) {
        $settings_error = 'Please set both the start and end of the voting.';
    } elseif (strtotime($end) <= strtotime($start)) {
        $settings_error = 'The end of the voting must be after the start.';
    } else {
        $db->prepare('UPDATE settings SET election_title = ?, voting_start = ?, voting_end = ? WHERE id = 1')
            ->execute([
                $title,
                date('Y-m-d H:i:s', strtotime($start)),
                date('Y-m-d H:i:s', strtotime($end)),
            ]);
        $election_title = $title;
        $settings = ['start' => $start, 'end' => $end];
        $settings_success = 'Election settings saved.';
    }
}

// ---------------------------------------------------------------
// End voting now (needs the end code)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['end_voting'])) {
    if (election_status($settings) !== 'open') {
        $end_error = 'Voting is not open right now, so there is nothing to end.';
    } else {
        $end_error = verify_end_code($db, (string) ($_POST['end_code'] ?? ''), $max_code_attempts, $code_lock_minutes) ?? '';
        if ($end_error === '') {
            $db->prepare('UPDATE settings SET voting_end = ? WHERE id = 1')->execute([date('Y-m-d H:i:s')]);
            header('Location: settings.php?ended=1');
            exit;
        }
    }
}

// ---------------------------------------------------------------
// Reset votes: delete every ballot and mark all voters as not voted.
// Voters, candidates, partylists and departments are kept.
// ---------------------------------------------------------------
$reset_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_votes'])) {
    if (election_status($settings) === 'open') {
        $reset_error = 'Votes can\'t be reset while voting is open. End the voting first.';
    } else {
        $reset_error = verify_end_code($db, (string) ($_POST['reset_code'] ?? ''), $max_code_attempts, $code_lock_minutes) ?? '';
        if ($reset_error === '') {
            $db->beginTransaction();
            try {
                $db->exec('DELETE FROM ballot_choices');
                $db->exec('DELETE FROM ballots');
                $db->exec('UPDATE voters SET has_voted = 0, voted_at = NULL');
                $db->commit();
            } catch (PDOException $e) {
                $db->rollBack();
                throw $e;
            }
            header('Location: settings.php?reset=1');
            exit;
        }
    }
}

// ---------------------------------------------------------------
// Change the end code
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_code'])) {
    $new_code = (string) ($_POST['new_code'] ?? '');

    if (!preg_match('/^\d{4,10}$/', $new_code)) {
        $code_error = 'The new code must be 4 to 10 digits.';
    } elseif ($new_code !== (string) ($_POST['confirm_code'] ?? '')) {
        $code_error = 'The new code and its confirmation do not match.';
    } else {
        $code_error = verify_end_code($db, (string) ($_POST['current_code'] ?? ''), $max_code_attempts, $code_lock_minutes) ?? '';
        if ($code_error === '') {
            $db->prepare('UPDATE settings SET end_code_hash = ? WHERE id = 1')
                ->execute([password_hash($new_code, PASSWORD_DEFAULT)]);
            $code_success = 'The end code was changed.';
        }
    }
}

// ---------------------------------------------------------------
// Change the logged-in admin's password
// ---------------------------------------------------------------
$password_error = '';
$password_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $new_password = (string) ($_POST['new_password'] ?? '');
    $find = $db->prepare('SELECT password_hash FROM admins WHERE id = ?');
    $find->execute([$_SESSION['admin_id']]);
    $current_hash = (string) $find->fetchColumn();

    if (!password_verify((string) ($_POST['current_password'] ?? ''), $current_hash)) {
        $password_error = 'Your current password is wrong.';
    } elseif (strlen($new_password) < 8) {
        $password_error = 'The new password must be at least 8 characters.';
    } elseif ($new_password !== (string) ($_POST['confirm_password'] ?? '')) {
        $password_error = 'The new password and its confirmation do not match.';
    } else {
        $db->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new_password, PASSWORD_DEFAULT), $_SESSION['admin_id']]);
        renew_session_after_login();
        $password_success = 'Your password was changed.';
    }
}

$status = election_status($settings);
$ballot_count = (int) $db->query('SELECT COUNT(*) FROM ballots')->fetchColumn();
$voted_count = (int) $db->query('SELECT COUNT(*) FROM voters WHERE has_voted = 1')->fetchColumn();
$lock = $db->query('SELECT end_code_locked_until FROM settings WHERE id = 1')->fetchColumn();
$locked_until = $lock && strtotime($lock) > time() ? date('g:i A', strtotime($lock)) : null;

require __DIR__ . '/includes/header.php';
?>
<?php if (isset($_GET['ended'])): ?>
    <p class="alert success">
        Voting has been ended. Voters can no longer cast ballots, and the live tally now shows the final results.
    </p>
<?php elseif (isset($_GET['reset'])): ?>
    <p class="alert success">
        All votes were reset. Every voter is marked as not yet voted, and the tally is back to zero.
        Voters and candidates were kept.
    </p>
<?php endif; ?>

<section class="card">
    <h2><i class="bx bx-calendar"></i> Official Voting Schedule</h2>

    <?php if ($settings_error !== ''): ?>
        <p class="alert error"><?php echo h($settings_error); ?></p>
    <?php elseif ($settings_success !== ''): ?>
        <p class="alert success"><?php echo h($settings_success); ?></p>
    <?php endif; ?>

    <form method="POST" action="settings.php">
        <?php echo csrf_field(); ?>
        <div class="form-grid">
            <div class="form-group full">
                <label for="election_title">Election Title</label>
                <input type="text" id="election_title" name="election_title" maxlength="150"
                       value="<?php echo h($election_title); ?>" required>
            </div>
            <div class="form-group">
                <label for="start">Voting Starts</label>
                <input type="datetime-local" id="start" name="start" value="<?php echo h($settings['start']); ?>" required>
            </div>
            <div class="form-group">
                <label for="end">Voting Ends</label>
                <input type="datetime-local" id="end" name="end" value="<?php echo h($settings['end']); ?>" required>
            </div>
        </div>
        <button type="submit" name="save_settings" class="btn"><i class="bx bx-save"></i> Save Settings</button>
    </form>
</section>

<section class="card">
    <h2><i class="bx bx-stop-circle"></i> End Voting Early</h2>

    <?php if ($end_error !== '' && $status !== 'open'): ?>
        <p class="alert error"><?php echo h($end_error); ?></p>
    <?php endif; ?>

    <?php if ($status === 'open'): ?>
        <p class="note">
            Voting is open until <strong><?php echo h(date('F j, Y g:i A', strtotime($settings['end']))); ?></strong>.
            Ending it now closes voting for everyone immediately. Votes already cast are kept.
        </p>
    <?php elseif ($status === 'ended'): ?>
        <p class="note">
            Voting has ended. It closed on <strong><?php echo h(date('F j, Y g:i A', strtotime($settings['end']))); ?></strong>.
            To hold the voting again, set a new schedule above; this button becomes available once voting opens.
        </p>
    <?php elseif ($status === 'upcoming'): ?>
        <p class="note">
            Voting opens on <strong><?php echo h(date('F j, Y g:i A', strtotime($settings['start']))); ?></strong>.
            This button becomes available once voting opens.
        </p>
    <?php else: ?>
        <p class="note">No voting schedule is set yet. This button becomes available once voting opens.</p>
    <?php endif; ?>

    <!-- Always shown; only usable while voting is open -->
    <button type="button" class="btn btn-danger" onclick="openEndVoting()"
            <?php if ($status !== 'open'): ?>disabled title="Available only while voting is open"<?php elseif ($locked_until): ?>disabled<?php endif; ?>>
        <i class="bx bx-stop-circle"></i> End Voting Now
    </button>
    <?php if ($status === 'open' && $locked_until): ?>
        <p class="table-note">Locked after too many wrong codes. Try again after <?php echo h($locked_until); ?>.</p>
    <?php endif; ?>
</section>

<section class="card">
    <h2><i class="bx bx-reset"></i> Reset Votes</h2>
    <p class="note">
        Deletes all <strong><?php echo $ballot_count; ?></strong> cast ballot(s) and marks all
        <strong><?php echo $voted_count; ?></strong> voter(s) who voted as <em>not yet voted</em>, so the tally starts from zero.
        The voter list, candidates, partylists and departments are <strong>not</strong> touched.
    </p>

    <?php if ($reset_error !== '' && $status === 'open'): ?>
        <p class="alert error"><?php echo h($reset_error); ?></p>
    <?php endif; ?>

    <?php if ($status === 'open'): ?>
        <p class="table-note">Not available while voting is open. End the voting first.</p>
    <?php else: ?>
        <button type="button" class="btn btn-danger" onclick="openResetVotes()"
                <?php echo ($locked_until || $ballot_count + $voted_count === 0) ? 'disabled' : ''; ?>>
            <i class="bx bx-reset"></i> Reset All Votes
        </button>
        <?php if ($ballot_count + $voted_count === 0): ?>
            <p class="table-note">There are no votes to reset.</p>
        <?php elseif ($locked_until): ?>
            <p class="table-note">Locked after too many wrong codes. Try again after <?php echo h($locked_until); ?>.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="card">
    <h2><i class="bx bx-lock-alt"></i> Admin Code</h2>
    <p class="note">
        The code asked for when ending voting early or resetting votes. Only share it with the people allowed
        to do this, and change the starting code before election day.
    </p>

    <?php if ($code_error !== ''): ?>
        <p class="alert error"><?php echo h($code_error); ?></p>
    <?php elseif ($code_success !== ''): ?>
        <p class="alert success"><?php echo h($code_success); ?></p>
    <?php endif; ?>

    <form method="POST" action="settings.php" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="form-grid">
            <div class="form-group">
                <label for="current_code">Current Code</label>
                <input type="password" id="current_code" name="current_code" inputmode="numeric" maxlength="10" required>
            </div>
            <div class="form-group">
                <label for="new_code">New Code</label>
                <input type="password" id="new_code" name="new_code" inputmode="numeric" maxlength="10"
                       pattern="\d{4,10}" title="4 to 10 digits" required>
            </div>
            <div class="form-group">
                <label for="confirm_code">Confirm New Code</label>
                <input type="password" id="confirm_code" name="confirm_code" inputmode="numeric" maxlength="10" required>
            </div>
        </div>
        <button type="submit" name="change_code" class="btn btn-secondary"><i class="bx bx-key"></i> Change Code</button>
    </form>
</section>

<section class="card">
    <h2><i class="bx bx-user-circle"></i> Admin Password</h2>
    <p class="note">The password you use to log in to this admin panel. Use at least 8 characters.</p>

    <?php if ($password_error !== ''): ?>
        <p class="alert error"><?php echo h($password_error); ?></p>
    <?php elseif ($password_success !== ''): ?>
        <p class="alert success"><?php echo h($password_success); ?></p>
    <?php endif; ?>

    <form method="POST" action="settings.php" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="form-grid">
            <div class="form-group">
                <label for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
            </div>
            <div class="form-group">
                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>
            </div>
        </div>
        <button type="submit" name="change_password" class="btn btn-secondary"><i class="bx bx-key"></i> Change Password</button>
    </form>
</section>

<!-- End voting: step 1 confirm, step 2 enter the code -->
<div class="modal" id="endModal" onclick="if (event.target === this) closeEndVoting()">
    <div class="modal-content small">
        <button type="button" class="modal-close" onclick="closeEndVoting()" aria-label="Close">&times;</button>

        <div id="endStepConfirm">
            <i class="bx bx-error-circle modal-icon"></i>
            <div class="modal-header">
                <h3>End Voting Now?</h3>
            </div>
            <p>
                Voting will close <strong>immediately</strong> for everyone. Voters who haven't voted yet
                won't be able to. This can't be undone.
            </p>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeEndVoting()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="showEndCodeStep()">Yes, End Voting</button>
            </div>
        </div>

        <div id="endStepCode" hidden>
            <i class="bx bx-lock-alt modal-icon"></i>
            <div class="modal-header">
                <h3>Enter End Code</h3>
            </div>
            <p>Enter the admin code to confirm ending the voting.</p>

            <?php if ($end_error !== '' && $status === 'open'): ?>
                <p class="alert error"><?php echo h($end_error); ?></p>
            <?php endif; ?>

            <form method="POST" action="settings.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="password" id="endCode" name="end_code" class="code-input" inputmode="numeric"
                       maxlength="10" aria-label="End code" required>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="showEndConfirmStep()">Back</button>
                    <button type="submit" name="end_voting" class="btn btn-danger"><i class="bx bx-stop-circle"></i> End Voting</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset votes: step 1 confirm, step 2 enter the code -->
<div class="modal" id="resetModal" onclick="if (event.target === this) closeResetVotes()">
    <div class="modal-content small">
        <button type="button" class="modal-close" onclick="closeResetVotes()" aria-label="Close">&times;</button>

        <div id="resetStepConfirm">
            <i class="bx bx-error-circle modal-icon"></i>
            <div class="modal-header">
                <h3>Reset All Votes?</h3>
            </div>
            <p>
                All <strong><?php echo $ballot_count; ?></strong> ballot(s) will be <strong>permanently deleted</strong>
                and every voter can vote again. Voters and candidates are kept. This can't be undone.
            </p>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeResetVotes()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="showResetCodeStep()">Yes, Reset Votes</button>
            </div>
        </div>

        <div id="resetStepCode" hidden>
            <i class="bx bx-lock-alt modal-icon"></i>
            <div class="modal-header">
                <h3>Enter Admin Code</h3>
            </div>
            <p>Enter the admin code to confirm resetting all votes.</p>

            <?php if ($reset_error !== '' && $status !== 'open'): ?>
                <p class="alert error"><?php echo h($reset_error); ?></p>
            <?php endif; ?>

            <form method="POST" action="settings.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="password" id="resetCode" name="reset_code" class="code-input" inputmode="numeric"
                       maxlength="10" aria-label="Admin code" required>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="showResetConfirmStep()">Back</button>
                    <button type="submit" name="reset_votes" class="btn btn-danger"><i class="bx bx-reset"></i> Reset Votes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ---------- Reset votes modal ----------
function openResetVotes() {
    showResetConfirmStep();
    document.getElementById('resetModal').classList.add('open');
}

function closeResetVotes() {
    document.getElementById('resetModal').classList.remove('open');
}

function showResetConfirmStep() {
    document.getElementById('resetStepConfirm').hidden = false;
    document.getElementById('resetStepCode').hidden = true;
}

function showResetCodeStep() {
    document.getElementById('resetStepConfirm').hidden = true;
    document.getElementById('resetStepCode').hidden = false;
    const input = document.getElementById('resetCode');
    input.value = '';
    input.focus();
}

// A wrong code reopens the modal on the code step
if (<?php echo json_encode($reset_error !== '' && $status !== 'open'); ?>) {
    document.getElementById('resetModal').classList.add('open');
    showResetCodeStep();
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeResetVotes();
});

// ---------- End voting modal ----------
function openEndVoting() {
    showEndConfirmStep();
    document.getElementById('endModal').classList.add('open');
}

function closeEndVoting() {
    document.getElementById('endModal').classList.remove('open');
}

function showEndConfirmStep() {
    document.getElementById('endStepConfirm').hidden = false;
    document.getElementById('endStepCode').hidden = true;
}

function showEndCodeStep() {
    document.getElementById('endStepConfirm').hidden = true;
    document.getElementById('endStepCode').hidden = false;
    const input = document.getElementById('endCode');
    input.value = '';
    input.focus();
}

// While voting hasn't started, refresh the page when it opens so "End Voting Now" turns on.
// (Uses the server's time, and only for a normal page view, not right after submitting a form.)
const SECONDS_UNTIL_OPEN = <?php echo json_encode(
    $status === 'upcoming' && $_SERVER['REQUEST_METHOD'] === 'GET'
        ? strtotime($settings['start']) - time()
        : null
); ?>;
if (SECONDS_UNTIL_OPEN !== null && SECONDS_UNTIL_OPEN < 86400) {
    setTimeout(() => location.reload(), (SECONDS_UNTIL_OPEN + 1) * 1000);
}

// A wrong code reopens the modal on the code step
if (<?php echo json_encode($end_error !== '' && $status === 'open'); ?>) {
    document.getElementById('endModal').classList.add('open');
    showEndCodeStep();
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeEndVoting();
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
