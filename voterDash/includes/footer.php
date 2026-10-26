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
            <form method="POST" action="<?php echo $root_url; ?>voterDash/logout.php">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn"><i class="bx bx-log-out"></i> Yes, Log Out</button>
            </form>
        </div>
    </div>
</div>

<script>
// ---------- Logout confirmation modal ----------
function openLogout() {
    closeMenus();
    document.getElementById('logoutModal').classList.add('open');
}

function closeLogout() {
    document.getElementById('logoutModal').classList.remove('open');
}

// ---------- Top bar dropdowns (notifications, profile) ----------
function toggleMenu(id) {
    const menu = document.getElementById(id);
    const wasOpen = menu.classList.contains('open');
    closeMenus();
    if (!wasOpen) menu.classList.add('open');
}

function closeMenus() {
    document.querySelectorAll('.menu.open').forEach(menu => menu.classList.remove('open'));
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dropdown')) closeMenus();
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeLogout();
        closeMenus();
    }
});
</script>
</body>
</html>
