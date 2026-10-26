<?php
require __DIR__ . '/adminDash/includes/init.php';

$page = 'dashboard';
$root_url = '';
require_admin($root_url);   // admins only; also checks the CSRF token of every POST

// ---------------------------------------------------------------
// Voting stats per department
// ---------------------------------------------------------------
$dept_stats = [];
$total_voted = 0;
foreach ($voters_by_dept as $code => $list) {
    $voted = count(array_filter($list, fn($v) => $v['voted']));
    $total = count($list);
    $dept_stats[$code] = [
        'voted'   => $voted,
        'total'   => $total,
        'percent' => $total > 0 ? round($voted / $total * 100) : 0,
    ];
    $total_voted += $voted;
}
$total_voters = count($voters);
$turnout = $total_voters > 0 ? round($total_voted / $total_voters * 100) : 0;

require __DIR__ . '/adminDash/includes/header.php';
?>
<section class="card">
    <h2><i class="bx bx-time-five"></i> Voting Countdown</h2>
    <p class="countdown-label" id="countdownLabel">Loading…</p>

    <div class="countdown">
        <div class="countdown-box"><strong id="cdHours">00</strong><span>Hours</span></div>
        <div class="countdown-box"><strong id="cdMinutes">00</strong><span>Minutes</span></div>
        <div class="countdown-box"><strong id="cdSeconds">00</strong><span>Seconds</span></div>
    </div>

    <?php if ($settings['start'] !== '' && $settings['end'] !== ''): ?>
        <p class="countdown-schedule">
            Schedule:
            <?php echo h(date('F j, Y g:i A', strtotime($settings['start']))); ?>
            –
            <?php echo h(date('F j, Y g:i A', strtotime($settings['end']))); ?>
        </p>
    <?php endif; ?>
</section>

<div class="stats">
    <div class="stat"><span><i class="bx bx-group"></i> Total Voters</span><strong><?php echo $total_voters; ?></strong></div>
    <div class="stat"><span><i class="bx bx-check-circle"></i> Already Voted</span><strong><?php echo $total_voted; ?></strong></div>
    <div class="stat"><span><i class="bx bx-time"></i> Not Yet Voted</span><strong><?php echo $total_voters - $total_voted; ?></strong></div>
    <div class="stat"><span><i class="bx bx-pie-chart-alt-2"></i> Turnout</span><strong><?php echo $turnout; ?>%</strong></div>
</div>

<section class="card">
    <h2><i class="bx bx-bar-chart-alt-2"></i> Students Who Already Voted per Department</h2>
    <?php foreach ($departments as $code => $dept_name): ?>
        <div class="bar-row">
            <div class="bar-label">
                <strong><?php echo h($dept_name); ?></strong>
                <span>
                    <?php echo $dept_stats[$code]['voted']; ?> / <?php echo $dept_stats[$code]['total']; ?>
                    (<?php echo $dept_stats[$code]['percent']; ?>%)
                </span>
            </div>
            <div class="bar-track">
                <div class="bar-fill" style="width: <?php echo $dept_stats[$code]['percent']; ?>%"></div>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<script>
// ---------- Dashboard countdown ----------
(function () {
    const label = document.getElementById('countdownLabel');
    const start = <?php echo json_encode($settings['start'], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const end = <?php echo json_encode($settings['end'], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const hoursEl = document.getElementById('cdHours');
    const minutesEl = document.getElementById('cdMinutes');
    const secondsEl = document.getElementById('cdSeconds');

    if (!start || !end) {
        label.textContent = 'The voting schedule has not been set yet. Go to Settings to set it.';
        return;
    }

    const startTime = new Date(start).getTime();
    const endTime = new Date(end).getTime();
    const pad = n => String(n).padStart(2, '0');

    function show(ms) {
        const total = Math.max(0, Math.floor(ms / 1000));
        hoursEl.textContent = pad(Math.floor(total / 3600));
        minutesEl.textContent = pad(Math.floor((total % 3600) / 60));
        secondsEl.textContent = pad(total % 60);
    }

    function tick() {
        const now = Date.now();
        if (now < startTime) {
            label.textContent = 'Voting has not started yet. Time until voting starts:';
            show(startTime - now);
        } else if (now < endTime) {
            label.textContent = 'Voting is ongoing. Time left before voting ends:';
            show(endTime - now);
        } else {
            label.textContent = 'Voting has ended.';
            show(0);
            clearInterval(timer);
        }
    }

    tick();
    const timer = setInterval(tick, 1000);
})();
</script>
<?php require __DIR__ . '/adminDash/includes/footer.php'; ?>
