<?php
$page = 'dashboard';
$root_url = '';
require __DIR__ . '/voterDash/includes/init.php';

// ---------------------------------------------------------------
// Banner status text
// ---------------------------------------------------------------
$status_labels = [
    'open'     => 'Active Election Live',
    'upcoming' => 'Upcoming Election',
    'ended'    => 'Election Ended',
    'unset'    => 'No Election Scheduled',
];

// ---------------------------------------------------------------
// Recent activity (newest first; items without a time go last)
// ---------------------------------------------------------------
$activity = [];
if ($voter['voted']) {
    $activity[] = [
        'icon' => 'bx-check-circle',
        'text' => 'You cast your vote.',
        'time' => $voter['voted_at'] ?? null,
    ];
}
$activity[] = ['icon' => 'bx-log-in', 'text' => 'You logged in.', 'time' => $_SESSION['login_time'] ?? null];
if ($status === 'open' || $status === 'ended') {
    $activity[] = ['icon' => 'bx-door-open', 'text' => 'Voting opened.', 'time' => $settings['start']];
}
if ($status === 'ended') {
    $activity[] = ['icon' => 'bx-lock-alt', 'text' => 'Voting closed.', 'time' => $settings['end']];
}
$activity[] = [
    'icon' => 'bx-id-card',
    'text' => count($candidates) . ' candidate(s) from ' . count($partylists) . ' partylist(s) are running.',
    'time' => null,
];

usort($activity, function ($a, $b) {
    $ta = $a['time'] === null ? 0 : (is_numeric($a['time']) ? (int) $a['time'] : strtotime($a['time']));
    $tb = $b['time'] === null ? 0 : (is_numeric($b['time']) ? (int) $b['time'] : strtotime($b['time']));
    return $tb <=> $ta;
});

require __DIR__ . '/voterDash/includes/header.php';
?>
<p class="live-pill status-<?php echo h($status); ?>">
    <span class="pulse"></span> <?php echo h($status_labels[$status]); ?>
</p>

<!-- ===================== ELECTION BANNER ===================== -->
<section class="banner">
    <div class="banner-text">
        <h2><?php echo h($election_title); ?></h2>
        <p class="banner-sub">Supreme Student Council · St. Rose College Educational Foundation Inc.</p>

        <p class="banner-label" id="countdownLabel">
            <?php echo $status === 'unset' ? 'The voting schedule has not been announced yet.' : 'Loading…'; ?>
        </p>
        <?php if ($status !== 'unset'): ?>
            <div class="banner-countdown" aria-live="polite">
                <div><strong id="cdDays">00</strong><span>Days</span></div>
                <b>:</b>
                <div><strong id="cdHours">00</strong><span>Hours</span></div>
                <b>:</b>
                <div><strong id="cdMinutes">00</strong><span>Minutes</span></div>
                <b>:</b>
                <div><strong id="cdSeconds">00</strong><span>Seconds</span></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="banner-action">
        <?php if ($voter['voted']): ?>
            <span class="voted-chip"><i class="bx bx-check-circle"></i> You have voted</span>
        <?php elseif ($status === 'open'): ?>
            <a href="voterDash/vote.php" class="btn btn-light">Vote Now <i class="bx bx-right-arrow-alt"></i></a>
        <?php else: ?>
            <span class="btn btn-light disabled" aria-disabled="true">
                <?php echo $status === 'ended' ? 'Voting Closed' : 'Voting Not Open Yet'; ?>
            </span>
        <?php endif; ?>
    </div>
</section>

<div class="dash-grid">
    <!-- ===================== RECENT ACTIVITY ===================== -->
    <section class="card">
        <h2><i class="bx bx-history"></i> Recent Activity</h2>
        <ul class="activity">
            <?php foreach ($activity as $item): ?>
                <li>
                    <span class="activity-icon"><i class="bx <?php echo h($item['icon']); ?>"></i></span>
                    <div>
                        <p><?php echo h($item['text']); ?></p>
                        <?php if ($item['time'] !== null): ?>
                            <time><?php echo h(format_datetime($item['time'])); ?></time>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <!-- ===================== QUICK ACTIONS ===================== -->
    <section class="card">
        <h2><i class="bx bx-bolt-circle"></i> Quick Actions</h2>
        <div class="quick-actions">
            <a href="voterDash/candidates.php" class="quick-action">
                <i class="bx bx-id-card"></i> View Candidates <i class="bx bx-chevron-right"></i>
            </a>
            <button type="button" class="quick-action" onclick="openModal('howToVoteModal')">
                <i class="bx bx-help-circle"></i> How to Vote <i class="bx bx-chevron-right"></i>
            </button>
            <button type="button" class="quick-action" onclick="openModal('guidelinesModal')">
                <i class="bx bx-book-open"></i> Guidelines &amp; Policies <i class="bx bx-chevron-right"></i>
            </button>
        </div>
    </section>
</div>

<!-- How to vote modal -->
<div class="modal" id="howToVoteModal" onclick="if (event.target === this) closeModal('howToVoteModal')">
    <div class="modal-content wide">
        <button type="button" class="modal-close" onclick="closeModal('howToVoteModal')" aria-label="Close">&times;</button>
        <div class="modal-header">
            <h3>How to Vote</h3>
        </div>
        <ol class="steps">
            <li><strong>Review the candidates.</strong> Open <em>View Candidates</em> to read each candidate's platform and credentials.</li>
            <li><strong>Open your ballot.</strong> While voting is open, click <em>Vote Now</em> or <em>Cast your Vote</em> in the sidebar.</li>
            <li><strong>Choose one per position.</strong> Pick one candidate for each position, or choose <em>Abstain</em> to skip a position.</li>
            <li><strong>Review your choices.</strong> Click <em>Review My Vote</em> and check the summary carefully.</li>
            <li><strong>Submit.</strong> Click <em>Submit Vote</em>. You can only vote once, and your vote can't be changed after submitting.</li>
        </ol>
    </div>
</div>

<!-- Guidelines & policies modal -->
<div class="modal" id="guidelinesModal" onclick="if (event.target === this) closeModal('guidelinesModal')">
    <div class="modal-content wide">
        <button type="button" class="modal-close" onclick="closeModal('guidelinesModal')" aria-label="Close">&times;</button>
        <div class="modal-header">
            <h3>Guidelines &amp; Policies</h3>
        </div>
        <ul class="steps">
            <li><strong>One student, one vote.</strong> Each registered voter can submit one ballot only.</li>
            <li><strong>Keep your Voter's ID private.</strong> Do not share it with anyone. Anyone who has it can vote in your place.</li>
            <li><strong>Your vote is secret.</strong> Ballots are stored separately from voter records, so no one can see who you voted for.</li>
            <li><strong>Vote within the schedule.</strong> Ballots can only be submitted while voting is open.</li>
            <li><strong>Final submission.</strong> Votes cannot be changed or withdrawn once submitted.</li>
            <li><strong>Need help?</strong> Report problems with your Voter's ID or ballot to the SSC Election Committee.</li>
        </ul>
    </div>
</div>

<script>
// ---------- Info modals ----------
function openModal(id) {
    document.getElementById(id).classList.add('open');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal('howToVoteModal');
        closeModal('guidelinesModal');
    }
});

// ---------- Election countdown ----------
(function () {
    const start = <?php echo json_encode($settings['start'], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const end = <?php echo json_encode($settings['end'], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    if (!start || !end) return;
    let lastStatus = null;

    const label = document.getElementById('countdownLabel');
    const parts = {
        days: document.getElementById('cdDays'),
        hours: document.getElementById('cdHours'),
        minutes: document.getElementById('cdMinutes'),
        seconds: document.getElementById('cdSeconds'),
    };
    const startTime = new Date(start).getTime();
    const endTime = new Date(end).getTime();
    const pad = n => String(n).padStart(2, '0');

    function show(ms) {
        const total = Math.max(0, Math.floor(ms / 1000));
        parts.days.textContent = pad(Math.floor(total / 86400));
        parts.hours.textContent = pad(Math.floor((total % 86400) / 3600));
        parts.minutes.textContent = pad(Math.floor((total % 3600) / 60));
        parts.seconds.textContent = pad(total % 60);
    }

    function tick() {
        const now = Date.now();
        const current = now < startTime ? 'upcoming' : (now < endTime ? 'open' : 'ended');

        // Reload when voting opens or closes while the page is open,
        // so the Vote Now button updates
        if (lastStatus !== null && current !== lastStatus) {
            location.reload();
            return;
        }
        lastStatus = current;

        if (current === 'upcoming') {
            label.textContent = 'Voting starts in';
            show(startTime - now);
        } else if (current === 'open') {
            label.textContent = 'Voting ends in';
            show(endTime - now);
        } else {
            label.textContent = 'Voting has ended.';
            show(0);
            clearInterval(timer);
        }
    }

    const timer = setInterval(tick, 1000);
    tick();
})();
</script>
<?php require __DIR__ . '/voterDash/includes/footer.php'; ?>
