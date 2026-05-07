<?php
// nav.php — included at the top of every page after session check
// Required variables set by the including page:
//   $username    — logged-in username
//   $role        — 'admin' | 'staff' | 'receptionist'
//   $active_page — e.g. 'dashboard', 'prisoners', 'visitors' …

// Count pending prisoner submissions for the admin badge
$pending_count = 0;
if ($role === 'admin' && isset($connection)) {
    $pc = mysqli_fetch_assoc(mysqli_query($connection,
        "SELECT COUNT(*) AS total FROM pending_prisoners WHERE status = 'pending'"));
    $pending_count = $pc['total'] ?? 0;
}

// Count prisoners releasing within 7 days for admin alert
$near_release_nav = 0;
if ($role === 'admin' && isset($connection)) {
    $nr = mysqli_fetch_assoc(mysqli_query($connection,
        "SELECT COUNT(*) AS total FROM prisoners
         WHERE status = 'active'
         AND release_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
    ));
    $near_release_nav = intval($nr['total'] ?? 0);
}
?>
<!-- ══ TOP NAVBAR ══════════════════════════════════════════════════════════ -->
<nav class="navbar">

    <!-- ROW 1: Brand + user info -->
    <div class="navbar-row1">
        <div class="navbar-brand">
            <img src="icon.png" alt="Logo" class="nav-logo">
             Maula Prison System
        </div>
        <div class="navbar-user">
            <span id="live-clock" class="nav-clock"></span>
            <span>👤 <?= htmlspecialchars($username) ?></span>
            <span class="role-badge"><?= htmlspecialchars(ucfirst($role)) ?></span>
            <a href="logout.php" class="logout-btn"
               onclick="return confirm('Are you sure you want to logout?')">Logout</a>
            <!-- Hamburger (mobile only) -->
            <button class="hamburger" id="hamburger-btn" aria-label="Toggle menu">☰</button>
        </div>
    </div>

    <!-- ROW 2: Horizontal menu links -->
    <div class="navbar-menu" id="navbar-menu">
        
        <ul class="nav-links">

            <li class="<?= ($active_page === 'dashboard') ? 'nav-active' : '' ?>">
                <a href="dashboard.php"> Dashboard</a>
            </li>

            <?php if (in_array($role, ['admin', 'staff'])): ?>
            <li class="<?= ($active_page === 'prisoners') ? 'nav-active' : '' ?>">
                <a href="prisoners.php"> Prisoners
                    <?php if ($role === 'admin' && $near_release_nav > 0): ?>
                        <span class="nav-badge" style="background:#e67e22;" title="Releasing within 7 days">🔔<?= $near_release_nav ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="<?= ($active_page === 'cells') ? 'nav-active' : '' ?>">
                <a href="cells.php">Cells</a>
            </li>
            <li class="<?= ($active_page === 'staff') ? 'nav-active' : '' ?>">
                <a href="staff.php"> Staff</a>
            </li>
            <?php endif; ?>

            <!-- Visitors: all roles can see it -->
            <li class="<?= ($active_page === 'visitors') ? 'nav-active' : '' ?>">
                <a href="visitors.php">🧑‍🤝‍🧑 Visitors</a>
            </li>

            <!-- Receptionist: submit prisoner link -->
            <?php if ($role === 'receptionist'): ?>
            <li class="<?= ($active_page === 'submit_prisoner') ? 'nav-active' : '' ?>">
                <a href="submit_prisoner.php"> Submit Prisoner</a>
            </li>
            <?php endif; ?>

            <!-- Admin-only links -->
            <?php if ($role === 'admin'): ?>
            <li class="<?= ($active_page === 'reports') ? 'nav-active' : '' ?>">
                <a href="reports.php"> Reports</a>
            </li>
            <li class="<?= ($active_page === 'pending_approvals') ? 'nav-active' : '' ?>">
                <a href="pending_approvals.php">
                    ✅ Pending Approvals
                    <?php if ($pending_count > 0): ?>
                        <span class="nav-badge"><?= $pending_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="<?= ($active_page === 'users') ? 'nav-active' : '' ?>">
                <a href="users.php">⚙️ User Management</a>
            </li>
            <?php endif; ?>

        </ul>
        
    </div>

</nav>

<!-- Live clock + hamburger toggle script -->
<script>
// Live clock
function updateClock() {
    var now  = new Date();
    var days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    var mons = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var h = String(now.getHours()).padStart(2,'0');
    var m = String(now.getMinutes()).padStart(2,'0');
    var s = String(now.getSeconds()).padStart(2,'0');
    var el = document.getElementById('live-clock');
    if (el) el.textContent =
        days[now.getDay()] + ', ' + now.getDate() + ' ' + mons[now.getMonth()] +
        ' ' + now.getFullYear() + '  ' + h + ':' + m + ':' + s;
}
updateClock();
setInterval(updateClock, 1000);

// Hamburger toggle
document.getElementById('hamburger-btn').addEventListener('click', function() {
    var menu = document.getElementById('navbar-menu');
    menu.classList.toggle('nav-open');
});
</script>
