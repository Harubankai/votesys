<?php
$page = 'candidates';
$root_url = '../';
require __DIR__ . '/includes/init.php';

$by_position = candidates_by_position($candidates, $positions);

$candidate_photos = [];
foreach ($candidates as $candidate) {
    $candidate_photos[$candidate['id']] = photo_url($candidate, $root_url);
}

require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <p>
        <?php echo count($candidates); ?> candidate(s) running for
        <?php echo count($by_position); ?> position(s) in the <?php echo h($election_title); ?>
    </p>
    <?php if (!$voter['voted'] && $status === 'open'): ?>
        <a href="vote.php" class="btn"><i class="bx bx-check-square"></i> Cast your Vote</a>
    <?php endif; ?>
</div>

<?php if (empty($by_position)): ?>
    <section class="card">
        <p class="empty">No candidates have been announced yet. Please check back later.</p>
    </section>
<?php endif; ?>

<?php foreach ($by_position as $position => $list): ?>
    <section class="card">
        <div class="party-header">
            <h2>
                <?php echo h($position); ?>
                <span class="dept-count">(<?php echo count($list); ?> candidate(s))</span>
            </h2>
        </div>

        <div class="candidate-grid">
            <?php foreach ($list as $candidate): ?>
                <div class="candidate">
                    <div class="candidate-photo">
                        <?php if ($candidate_photos[$candidate['id']]): ?>
                            <img src="<?php echo h($candidate_photos[$candidate['id']]); ?>" alt="<?php echo h($candidate['name']); ?>">
                        <?php else: ?>
                            <?php echo h(initials($candidate['name'])); ?>
                        <?php endif; ?>
                    </div>
                    <h3><?php echo h($candidate['name']); ?></h3>
                    <p class="position"><?php echo h($candidate['partylist']); ?></p>
                    <p class="meta">
                        <?php echo h($candidate['department']); ?> · <?php echo h($candidate['year']); ?>
                        <?php if ($candidate['section'] !== ''): ?> · Section <?php echo h($candidate['section']); ?><?php endif; ?>
                    </p>
                    <button type="button" class="btn" onclick="openCandidate(<?php echo (int) $candidate['id']; ?>)">
                        <i class="bx bx-show"></i> View
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<!-- Candidate details modal -->
<div class="modal" id="candidateModal" onclick="if (event.target === this) closeCandidate()">
    <div class="modal-content">
        <button type="button" class="modal-close" onclick="closeCandidate()" aria-label="Close">&times;</button>
        <div class="modal-header">
            <div class="candidate-photo" id="mPhoto"></div>
            <h3 id="mName"></h3>
            <p class="position" id="mPosition"></p>
        </div>
        <dl class="details">
            <dt>Partylist</dt>    <dd id="mPartylist"></dd>
            <dt>Department</dt>   <dd id="mDepartment"></dd>
            <dt>Course</dt>       <dd id="mCourse"></dd>
            <dt>Year Level</dt>   <dd id="mYear"></dd>
            <dt>Section</dt>      <dd id="mSection"></dd>
            <dt>Achievements</dt> <dd id="mAchievements"></dd>
            <dt>Platform</dt>     <dd id="mPlatform"></dd>
        </dl>
    </div>
</div>

<script>
// ---------- Candidate details modal ----------
// Student IDs are left out; voters don't need them
const candidates = <?php
    $public = array_map(fn($c) => array_diff_key($c, ['student_id' => true]), $candidates);
    echo json_encode(array_column($public, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP);
?>;
const departments = <?php echo json_encode($departments, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const candidatePhotos = <?php echo json_encode($candidate_photos, JSON_HEX_TAG | JSON_HEX_AMP); ?>;

function openCandidate(id) {
    const c = candidates[id];
    if (!c) return;

    const photo = document.getElementById('mPhoto');
    photo.innerHTML = '';
    if (candidatePhotos[id]) {
        const img = document.createElement('img');
        img.src = candidatePhotos[id];
        img.alt = c.name;
        photo.appendChild(img);
    } else {
        photo.textContent = c.name.split(/\s+/).map(p => p[0]).join('').slice(0, 2).toUpperCase();
    }

    document.getElementById('mName').textContent = c.name;
    document.getElementById('mPosition').textContent = c.position;
    document.getElementById('mPartylist').textContent = c.partylist;
    document.getElementById('mDepartment').textContent = departments[c.department] || c.department;
    document.getElementById('mCourse').textContent = c.course;
    document.getElementById('mYear').textContent = c.year;
    document.getElementById('mSection').textContent = c.section || '—';
    document.getElementById('mAchievements').textContent = c.achievements || '—';
    document.getElementById('mPlatform').textContent = c.platform || '—';

    document.getElementById('candidateModal').classList.add('open');
}

function closeCandidate() {
    document.getElementById('candidateModal').classList.remove('open');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeCandidate();
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
