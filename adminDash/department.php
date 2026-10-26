<?php
// All voters in one department: department.php?dept=DCS
require __DIR__ . '/includes/init.php';

$page = 'voters';
$root_url = '../';
require_admin($root_url);   // admins only; also checks the CSRF token of every POST

$code = strtoupper(trim((string) ($_GET['dept'] ?? '')));
if (!isset($departments[$code])) {
    header('Location: voters.php');
    exit;
}

// ---------------------------------------------------------------
// Delete a voter (only one who hasn't voted yet: a cast ballot can't be
// traced back to its voter, so deleting them would throw off the counts)
// ---------------------------------------------------------------
$delete_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_voter'])) {
    $voter_id = trim($_POST['voter_id'] ?? '');
    $target = null;
    foreach ($voters_by_dept[$code] as $v) {
        if ($v['voter_id'] === $voter_id) {
            $target = $v;
            break;
        }
    }

    if ($target === null) {
        $delete_error = 'That voter no longer exists.';
    } elseif ($target['voted']) {
        $delete_error = "{$target['name']} has already voted and can't be deleted.";
    } else {
        $delete = $db->prepare('DELETE FROM voters WHERE voter_id = ? AND department_code = ? AND has_voted = 0');
        $delete->execute([$voter_id, $code]);
        if ($delete->rowCount() === 1) {
            header('Location: department.php?dept=' . urlencode($code) . '&deleted=' . urlencode($target['name']));
            exit;
        }
        $delete_error = "{$target['name']} could not be deleted. They may have just voted.";
    }
}

$page_title = $departments[$code];

// Sorted by year level, then section, then name (one class after another)
$dept_voters = sort_by_class($voters_by_dept[$code], $dept_year_levels[$code]);

$total = count($dept_voters);
$voted = count(array_filter($dept_voters, fn($v) => $v['voted']));
$turnout = $total > 0 ? round($voted / $total * 100) : 0;

require __DIR__ . '/includes/header.php';
?>
<?php if ($delete_error !== ''): ?>
    <p class="alert error"><?php echo h($delete_error); ?></p>
<?php elseif (!empty($_GET['deleted'])): ?>
    <p class="alert success"><?php echo h($_GET['deleted']); ?> was deleted from the voter list.</p>
<?php endif; ?>

<div class="toolbar">
    <a href="voters.php" class="back-link"><i class="bx bx-arrow-back"></i> Back to Voters</a>
    <?php if ($total > 0): ?>
        <a href="export_department.php?dept=<?php echo urlencode($code); ?>" class="btn">
            <i class="bx bx-download"></i> Download List (Excel)
        </a>
    <?php endif; ?>
</div>

<div class="stats">
    <div class="stat"><span><i class="bx bx-group"></i> Total Voters</span><strong><?php echo $total; ?></strong></div>
    <div class="stat"><span><i class="bx bx-check-circle"></i> Already Voted</span><strong><?php echo $voted; ?></strong></div>
    <div class="stat"><span><i class="bx bx-time"></i> Not Yet Voted</span><strong><?php echo $total - $voted; ?></strong></div>
    <div class="stat"><span><i class="bx bx-pie-chart-alt-2"></i> Turnout</span><strong><?php echo $turnout; ?>%</strong></div>
</div>

<section class="card">
    <?php if ($total === 0): ?>
        <p class="empty">No voters registered in this department yet.</p>
    <?php else: ?>
        <div class="filters">
            <input type="search" id="voterSearch" placeholder="Search by name, Student ID or Voter's ID" aria-label="Search voters">
            <select id="statusFilter" aria-label="Filter by status">
                <option value="">All statuses</option>
                <option value="voted">Voted</option>
                <option value="pending">Not yet voted</option>
            </select>
            <select id="classFilter" aria-label="Filter by year level and section">
                <option value="">All year levels &amp; sections</option>
                <?php foreach ($dept_year_levels[$code] as $year): ?>
                    <optgroup label="<?php echo h($year); ?>">
                        <option value="<?php echo h($year . '|'); ?>">All <?php echo h($year); ?></option>
                        <?php foreach ($dept_sections[$code][$year] ?? [] as $section): ?>
                            <option value="<?php echo h($year . '|' . $section); ?>"><?php echo h($year); ?> – Section <?php echo h($section); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Voter's ID</th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Year Level</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="voterRows">
                    <?php foreach ($dept_voters as $i => $voter): ?>
                        <tr data-search="<?php echo h(strtolower($voter['name'] . ' ' . ($voter['student_id'] ?? '') . ' ' . $voter['voter_id'])); ?>"
                            data-status="<?php echo $voter['voted'] ? 'voted' : 'pending'; ?>"
                            data-year="<?php echo h($voter['year']); ?>"
                            data-section="<?php echo h($voter['section']); ?>">
                            <td><?php echo $i + 1; ?></td>
                            <td class="voter-id"><?php echo h($voter['voter_id']); ?></td>
                            <td><?php echo h($voter['student_id'] ?? '—'); ?></td>
                            <td><?php echo h($voter['name']); ?></td>
                            <td><?php echo h($voter['year']); ?></td>
                            <td>
                                <?php if ($voter['voted']): ?>
                                    <span class="badge voted">Voted</span>
                                <?php else: ?>
                                    <span class="badge pending">Not yet voted</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-secondary btn-small"
                                        data-id="<?php echo h($voter['voter_id']); ?>"
                                        data-name="<?php echo h($voter['name']); ?>"
                                        onclick="openDeleteVoter(this.dataset.id, this.dataset.name)"
                                        <?php if ($voter['voted']): ?>disabled title="Already voted: can't be deleted"<?php endif; ?>>
                                    <i class="bx bx-trash"></i> Delete
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="table-note" id="resultCount">Showing all <?php echo $total; ?> voters.</p>
    <?php endif; ?>
</section>

<!-- Delete voter confirmation modal -->
<div class="modal" id="deleteVoterModal" onclick="if (event.target === this) closeDeleteVoter()">
    <div class="modal-content small">
        <button type="button" class="modal-close" onclick="closeDeleteVoter()" aria-label="Close">&times;</button>
        <div class="modal-header">
            <h3>Delete Voter</h3>
        </div>
        <p>
            Delete <strong id="deleteVoterName"></strong> (<span class="voter-id" id="deleteVoterId"></span>)
            from the voter list? Their Voter's ID will stop working. This can't be undone.
        </p>
        <form method="POST" action="department.php?dept=<?php echo urlencode($code); ?>" class="form-actions">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="voter_id" id="deleteVoterInput">
            <button type="button" class="btn btn-secondary" onclick="closeDeleteVoter()">Cancel</button>
            <button type="submit" name="delete_voter" class="btn"><i class="bx bx-trash"></i> Delete</button>
        </form>
    </div>
</div>

<script>
// ---------- Delete voter confirmation ----------
function openDeleteVoter(id, name) {
    document.getElementById('deleteVoterInput').value = id;
    document.getElementById('deleteVoterId').textContent = id;
    document.getElementById('deleteVoterName').textContent = name;
    document.getElementById('deleteVoterModal').classList.add('open');
}

function closeDeleteVoter() {
    document.getElementById('deleteVoterModal').classList.remove('open');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeDeleteVoter();
});
</script>

<?php if ($total > 0): ?>
<script>
// ---------- Search, status and year level / section filters ----------
(function () {
    const search = document.getElementById('voterSearch');
    const status = document.getElementById('statusFilter');
    const classFilter = document.getElementById('classFilter');
    const rows = document.querySelectorAll('#voterRows tr');
    const count = document.getElementById('resultCount');

    function filter() {
        const term = search.value.trim().toLowerCase();
        // "1st Year|" = whole year level, "1st Year|A" = one section
        const [year, section] = classFilter.value ? classFilter.value.split('|') : ['', ''];
        let shown = 0;
        rows.forEach(row => {
            const match = row.dataset.search.includes(term)
                && (!status.value || row.dataset.status === status.value)
                && (!year || row.dataset.year === year)
                && (!section || row.dataset.section === section);
            row.hidden = !match;
            if (match) shown++;
        });
        count.textContent = shown === rows.length
            ? `Showing all ${rows.length} voters.`
            : `Showing ${shown} of ${rows.length} voters.`;
    }

    search.addEventListener('input', filter);
    status.addEventListener('change', filter);
    classFilter.addEventListener('change', filter);
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
