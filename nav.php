<?php

$pending_count = 0;
if ($role === 'admin' && isset($connection)) {
    $pc = mysqli_fetch_assoc(mysqli_query($connection,
        "SELECT COUNT(*) AS total FROM pending_prisoners WHERE status = 'pending'"));
    $pending_count = $pc['total'] ?? 0;
}


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

<nav class="navbar">


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


            <?php if ($role === 'receptionist'): ?>
            <li class="<?= ($active_page === 'submit_prisoner') ? 'nav-active' : '' ?>">
                <a href="submit_prisoner.php"> Submit Prisoner</a>
            </li>
            <?php endif; ?>


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
