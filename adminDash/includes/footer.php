<?php
// Closes the layout opened in header.php
if (!defined('BASE_DIR')) {
    exit;
}
?>
    </main>
</div>

<!-- Logout confirmation modal -->
<div class="modal" id="logoutModal" onclick="if (event.target === this) closeLogout()">
    <div class="modal-content small" role="dialog" aria-labelledby="logoutTitle">
        <button type="button" class="modal-close" onclick="closeLogout()" aria-label="Close">&times;</button>
        <div class="modal-header">
            <h3 id="logoutTitle">Log Out</h3>
        </div>
        <p>Are you sure you want to log out of your account?</p>
        <div class="form-actions">
            <button type="button" class="btn btn-secondary" onclick="closeLogout()">Cancel</button>
            <form method="POST" action="<?php echo $root_url; ?>logout.php">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn"><i class="bx bx-log-out"></i> Yes, Log Out</button>
            </form>
        </div>
    </div>
</div>

<script>
// ---------- Logout confirmation modal ----------
function openLogout() {
    document.getElementById('logoutModal').classList.add('open');
}

function closeLogout() {
    document.getElementById('logoutModal').classList.remove('open');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeLogout();
});
</script>

<script>
// ---------- Year level dropdowns that follow the chosen department ----------
// A <select data-year-for="department"> only lists the year levels of the
// department picked in the <select id="department">.
const DEPT_YEAR_LEVELS = <?php echo json_encode($dept_year_levels, JSON_HEX_TAG | JSON_HEX_AMP); ?>;

document.querySelectorAll('select[data-year-for]').forEach(yearSelect => {
    const deptSelect = document.getElementById(yearSelect.dataset.yearFor);
    if (!deptSelect) return;

    deptSelect.addEventListener('change', () => {
        const current = yearSelect.value;
        const years = DEPT_YEAR_LEVELS[deptSelect.value];
        yearSelect.innerHTML = '';
        yearSelect.add(new Option(years ? 'Select year level' : 'Select department first', ''));
        (years || []).forEach(year => yearSelect.add(new Option(year, year, false, year === current)));
        yearSelect.dispatchEvent(new Event('change'));
    });
});

// A <select data-section-for="year"> only lists the sections of the chosen
// department and year level. It's required only when that year level has sections.
const DEPT_SECTIONS = <?php echo json_encode($dept_sections, JSON_HEX_TAG | JSON_HEX_AMP); ?>;

document.querySelectorAll('select[data-section-for]').forEach(sectionSelect => {
    const yearSelect = document.getElementById(sectionSelect.dataset.sectionFor);
    const deptSelect = document.getElementById(yearSelect.dataset.yearFor);

    function refresh() {
        const current = sectionSelect.value;
        const sections = (DEPT_SECTIONS[deptSelect.value] || {})[yearSelect.value];
        sectionSelect.innerHTML = '';
        if (!sections) {
            sectionSelect.add(new Option('Select year level first', ''));
        } else if (sections.length === 0) {
            sectionSelect.add(new Option('No sections', ''));
        } else {
            sectionSelect.add(new Option('Select section', ''));
            sections.forEach(section => sectionSelect.add(new Option(section, section, false, section === current)));
        }
        sectionSelect.required = !!(sections && sections.length);
    }

    yearSelect.addEventListener('change', refresh);
    refresh();
});
</script>
</body>
</html>
