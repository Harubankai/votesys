<?php
$page = 'vote';
$root_url = '../';
require __DIR__ . '/includes/init.php';

$ballot = candidates_by_position($candidates, $positions);

// ---------------------------------------------------------------
// Submit ballot
// ---------------------------------------------------------------
$vote_errors = [];
$selected = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cast_vote'])) {
    $selected = is_array($_POST['choice'] ?? null) ? $_POST['choice'] : [];

    if ($voter['voted']) {
        $vote_errors[] = 'You have already voted.';
    } elseif ($status !== 'open') {
        $vote_errors[] = 'Voting is not open right now.';
    } else {
        $choices = [];
        foreach ($ballot as $position => $list) {
            $value = (string) ($selected[$position] ?? '');
            $valid_ids = array_map('strval', array_column($list, 'id'));

            if ($value === '') {
                $vote_errors[] = "Please choose a candidate or Abstain for $position.";
            } elseif ($value === 'abstain') {
                $choices[$position] = null;
            } elseif (in_array($value, $valid_ids, true)) {
                $choices[$position] = (int) $value;
            } else {
                $vote_errors[] = "Invalid choice for $position.";
            }
        }
    }

    if (empty($vote_errors)) {
        $db->beginTransaction();
        try {
            // Lock the voter's row so a double submit (two tabs, double click) can't vote twice
            $check = $db->prepare('SELECT has_voted FROM voters WHERE voter_id = ? FOR UPDATE');
            $check->execute([$voter['voter_id']]);
            $row = $check->fetch();

            if ($row && !$row['has_voted']) {
                $db->prepare('UPDATE voters SET has_voted = 1, voted_at = ? WHERE voter_id = ?')
                    ->execute([date('Y-m-d H:i:s'), $voter['voter_id']]);

                // The ballot is never linked to the voter, and its random ID
                // keeps the order of ballots from revealing who cast which one
                $ballot_id = bin2hex(random_bytes(16));
                $db->prepare('INSERT INTO ballots (id) VALUES (?)')->execute([$ballot_id]);

                $insert_choice = $db->prepare(
                    'INSERT INTO ballot_choices (ballot_id, position_id, candidate_id) VALUES (?, ?, ?)'
                );
                foreach ($choices as $position => $candidate_id) {
                    $insert_choice->execute([$ballot_id, $position_ids[$position], $candidate_id]);
                }
            }

            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            throw $e;
        }

        header('Location: vote.php?done=1');
        exit;
    }
}

require __DIR__ . '/includes/header.php';
?>
<?php if ($voter['voted']): ?>
    <!-- ===================== ALREADY VOTED ===================== -->
    <section class="card notice">
        <i class="bx bx-check-circle notice-icon success"></i>
        <h2><?php echo isset($_GET['done']) ? 'Thank you for voting!' : 'You have already voted'; ?></h2>
        <p>
            Your ballot for the <?php echo h($election_title); ?> has been recorded
            <?php if (!empty($voter['voted_at'])): ?>
                on <?php echo h(format_datetime($voter['voted_at'])); ?>
            <?php endif; ?>.
            Votes can't be changed after submitting.
        </p>
        <a href="../voter_dashB.php" class="btn"><i class="bx bx-grid-alt"></i> Back to Dashboard</a>
    </section>

<?php elseif ($status !== 'open'): ?>
    <!-- ===================== VOTING CLOSED ===================== -->
    <section class="card notice">
        <i class="bx bx-time-five notice-icon"></i>
        <?php if ($status === 'upcoming'): ?>
            <h2>Voting hasn't started yet</h2>
            <p>Voting opens on <?php echo h(format_datetime($settings['start'])); ?>. Come back then to cast your vote.</p>
        <?php elseif ($status === 'ended'): ?>
            <h2>Voting has ended</h2>
            <p>Voting closed on <?php echo h(format_datetime($settings['end'])); ?>. Ballots can no longer be submitted.</p>
        <?php else: ?>
            <h2>No election scheduled</h2>
            <p>The voting schedule hasn't been announced yet. Please check back later.</p>
        <?php endif; ?>
        <a href="candidates.php" class="btn btn-secondary"><i class="bx bx-id-card"></i> View Candidates</a>
    </section>

<?php elseif (empty($ballot)): ?>
    <section class="card notice">
        <i class="bx bx-info-circle notice-icon"></i>
        <h2>No candidates yet</h2>
        <p>There are no candidates on the ballot yet. Please check back later.</p>
    </section>

<?php else: ?>
    <!-- ===================== BALLOT ===================== -->
    <?php if (!empty($vote_errors)): ?>
        <div class="alert error">
            <ul>
                <?php foreach ($vote_errors as $error): ?>
                    <li><?php echo h($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <p class="note">
        Choose <strong>one</strong> candidate for each position, or <strong>Abstain</strong> to skip it.
        You can only vote once, so review your choices before submitting.
    </p>

    <form method="POST" action="vote.php" id="ballotForm">
        <?php echo csrf_field(); ?>
        <?php foreach ($ballot as $position => $list): ?>
            <section class="card ballot-position" data-position="<?php echo h($position); ?>">
                <div class="party-header">
                    <h2><?php echo h($position); ?></h2>
                    <span class="dept-count">Choose 1</span>
                </div>

                <div class="choices">
                    <?php foreach ($list as $candidate): ?>
                        <?php $photo = photo_url($candidate, $root_url); ?>
                        <label class="choice">
                            <input type="radio" name="choice[<?php echo h($position); ?>]"
                                   value="<?php echo (int) $candidate['id']; ?>"
                                   data-label="<?php echo h($candidate['name'] . ' (' . $candidate['partylist'] . ')'); ?>"
                                   <?php echo (string) ($selected[$position] ?? '') === (string) $candidate['id'] ? 'checked' : ''; ?>
                                   required>
                            <span class="choice-body">
                                <span class="choice-photo">
                                    <?php if ($photo): ?>
                                        <img src="<?php echo h($photo); ?>" alt="">
                                    <?php else: ?>
                                        <?php echo h(initials($candidate['name'])); ?>
                                    <?php endif; ?>
                                </span>
                                <span class="choice-text">
                                    <strong><?php echo h($candidate['name']); ?></strong>
                                    <small><?php echo h($candidate['partylist']); ?> · <?php echo h($candidate['department']); ?></small>
                                </span>
                                <i class="bx bxs-check-circle choice-check"></i>
                            </span>
                        </label>
                    <?php endforeach; ?>

                    <label class="choice">
                        <input type="radio" name="choice[<?php echo h($position); ?>]" value="abstain" data-label="Abstain"
                               <?php echo ($selected[$position] ?? '') === 'abstain' ? 'checked' : ''; ?>>
                        <span class="choice-body abstain">
                            <span class="choice-photo"><i class="bx bx-minus-circle"></i></span>
                            <span class="choice-text">
                                <strong>Abstain</strong>
                                <small>Skip this position</small>
                            </span>
                            <i class="bx bxs-check-circle choice-check"></i>
                        </span>
                    </label>
                </div>
            </section>
        <?php endforeach; ?>

        <div class="form-actions">
            <button type="button" class="btn" onclick="reviewVote()"><i class="bx bx-list-check"></i> Review My Vote</button>
        </div>

        <!-- Review modal (inside the form so Submit Vote sends it) -->
        <div class="modal" id="reviewModal" onclick="if (event.target === this) closeReview()">
            <div class="modal-content wide">
                <button type="button" class="modal-close" onclick="closeReview()" aria-label="Close">&times;</button>
                <div class="modal-header">
                    <h3>Review Your Vote</h3>
                </div>
                <p class="note">Please check your choices. Once submitted, your vote can't be changed.</p>
                <dl class="details review-list" id="reviewList"></dl>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeReview()">Go Back</button>
                    <button type="submit" name="cast_vote" class="btn" id="submitVote"><i class="bx bx-send"></i> Submit Vote</button>
                </div>
            </div>
        </div>
    </form>

    <script>
    // ---------- Review before submitting ----------
    function reviewVote() {
        const form = document.getElementById('ballotForm');
        if (!form.reportValidity()) return;

        const list = document.getElementById('reviewList');
        list.innerHTML = '';
        document.querySelectorAll('.ballot-position').forEach(section => {
            const checked = section.querySelector('input:checked');
            const dt = document.createElement('dt');
            const dd = document.createElement('dd');
            dt.textContent = section.dataset.position;
            dd.textContent = checked.dataset.label;
            list.append(dt, dd);
        });

        document.getElementById('reviewModal').classList.add('open');
    }

    function closeReview() {
        if (submitting) return;
        document.getElementById('reviewModal').classList.remove('open');
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeReview();
    });

    // ---------- Submit once ----------
    // The server already blocks double votes; this just stops repeat clicks on a slow connection
    let submitting = false;
    const submitButton = document.getElementById('submitVote');
    const submitLabel = submitButton.innerHTML;

    document.getElementById('ballotForm').addEventListener('submit', e => {
        if (submitting) {
            e.preventDefault();
            return;
        }
        submitting = true;

        // A disabled button isn't sent with the form, so send cast_vote as a hidden field instead
        const flag = document.createElement('input');
        flag.type = 'hidden';
        flag.name = 'cast_vote';
        flag.value = '1';
        e.target.append(flag);

        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Submitting...';
        document.querySelectorAll('#reviewModal button[type="button"]').forEach(b => b.disabled = true);
    });

    // Re-enable the button if the voter comes back with the browser's Back button
    window.addEventListener('pageshow', e => {
        if (!e.persisted) return;
        submitting = false;
        submitButton.disabled = false;
        submitButton.innerHTML = submitLabel;
        document.querySelectorAll('#reviewModal button[type="button"]').forEach(b => b.disabled = false);
        document.querySelectorAll('#ballotForm input[name="cast_vote"][type="hidden"]').forEach(i => i.remove());
    });
    </script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
