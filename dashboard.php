<?php
session_start();
require_once 'helpers.php';

if (!isset($_SESSION['username'])) { header("Location: index.php"); exit(); }

$username = $_SESSION['username'];
$role     = $_SESSION['role'];

$connection = mysqli_connect('localhost', 'root', '', 'prison_db');
if (!$connection) die("Database connection failed: " . mysqli_connect_error());

$active_page = 'dashboard';

// ── REAL STATS ────────────────────────────────────────────────────────────────
$total_prisoners = mysqli_fetch_assoc(mysqli_query($connection,
    "SELECT COUNT(*) AS total FROM prisoners WHERE status = 'active'"))['total'];

$occupied_cells = mysqli_fetch_assoc(mysqli_query($connection,
    "SELECT COUNT(DISTINCT cell_id) AS total FROM prisoners WHERE status = 'active'"))['total'];

$total_staff = mysqli_fetch_assoc(mysqli_query($connection,
    "SELECT COUNT(*) AS total FROM staff"))['total'];

$releases_this_month = mysqli_fetch_assoc(mysqli_query($connection,
    "SELECT COUNT(*) AS total FROM prisoners
     WHERE status = 'active'
     AND MONTH(release_date) = MONTH(CURDATE())
     AND YEAR(release_date) = YEAR(CURDATE())"))['total'];

$upcoming_result = mysqli_query($connection,
    "SELECT full_name, release_date,
            DATEDIFF(release_date, CURDATE()) AS days_left
     FROM prisoners
     WHERE status = 'active'
     AND release_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     ORDER BY release_date ASC
     LIMIT 5");

// Near-release alert count (within 7 days) — for admin banner
$near_release_count = 0;
if ($role === 'admin') {
    $nr = mysqli_fetch_assoc(mysqli_query($connection,
        "SELECT COUNT(*) AS total FROM prisoners
         WHERE status = 'active'
         AND release_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
    ));
    $near_release_count = intval($nr['total']);
}

$logs_result = mysqli_query($connection,
    "SELECT * FROM logs ORDER BY created_at DESC LIMIT 8");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <title>Dashboard – Maula Prison</title>
</head>
<body class="dashboard-body">

<?php require_once 'nav.php'; ?>

<div class="dashboard-layout">
<main class="main-content">

    <h1>Welcome back, <?= htmlspecialchars($username) ?>!</h1>
    <p class="page-subtitle">Here is today's overview of Maula Prison.</p>

    <!-- Near-release notification for admin -->
    <?php if ($role === 'admin' && $near_release_count > 0): ?>
        <div class="alert alert-error" style="display:flex; align-items:center; gap:10px;">
            🔔 <strong>Release Alert:</strong>&nbsp;
            <?= $near_release_count ?> prisoner<?= $near_release_count > 1 ? 's are' : ' is' ?> due for release within the next 7 days.
            <a href="prisoners.php" style="margin-left:auto; font-size:0.85rem; color:#c0392b; font-weight:bold; text-decoration:underline;">View Prisoners →</a>
        </div>
    <?php endif; ?>

    <!-- STATS CARDS -->
    <div class="stats-grid">
        <div class="stat-card">
        
            <div class="stat-info">
                <span class="stat-number"><?= $total_prisoners ?></span>
                <span class="stat-label">Active Prisoners</span>
            </div>
        </div>
        <div class="stat-card">
        
            <div class="stat-info">
                <span class="stat-number"><?= $occupied_cells ?></span>
                <span class="stat-label">Occupied Cells</span>
            </div>
        </div>
        <div class="stat-card">
        
            <div class="stat-info">
                <span class="stat-number"><?= $total_staff ?></span>
                <span class="stat-label">Staff Members</span>
            </div>
        </div>
        <div class="stat-card">
            
            <div class="stat-info">
                <span class="stat-number"><?= $releases_this_month ?></span>
                <span class="stat-label">Releases This Month</span>
            </div>
        </div>
    </div>

   

    <!-- UPCOMING RELEASES -->
    <?php if (mysqli_num_rows($upcoming_result) > 0): ?>
    <div style="margin-bottom:32px;">
       <hr> <h2 style="font-size:1.1rem; margin-bottom:14px; color:#333;">⚠️ Upcoming Releases </h2><hr>
        <div class="table-card">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Prisoner Name</th>
                        <th>Expected Release Date</th>
                        <th>Days Remaining</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = mysqli_fetch_assoc($upcoming_result)): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['full_name']) ?></td>
                        <td><?= htmlspecialchars($row['release_date']) ?></td>
                        <td>
                            <?php if ($row['days_left'] <= 7): ?>
                                <span style="color:#c0392b; font-weight:bold;"><?= $row['days_left'] ?> days</span>
                            <?php else: ?>
                                <span style="color:#b8860b; font-weight:bold;"> <?= $row['days_left'] ?> days</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- RECENT ACTIVITY LOG -->
    <div>
      <hr>  <h2 style="font-size:1.1rem; margin-bottom:14px; color:#333;">🕐 Recent Activity</h2><hr>
        <div class="table-card">
            <table class="data-table">
                <thead>
                    <tr><th>Time</th><th>User</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($logs_result) === 0): ?>
                        <tr><td colspan="3" style="text-align:center;color:#888;">No activity yet.</td></tr>
                    <?php else: ?>
                        <?php while ($log = mysqli_fetch_assoc($logs_result)): ?>
                        <tr>
                            <td style="color:#888;font-size:0.82rem;">
                                <?= date('d M Y, H:i', strtotime($log['created_at'])) ?>
                            </td>
                            <td><?= htmlspecialchars($log['done_by']) ?></td>
                            <td><?= htmlspecialchars($log['action']) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
</div>

</body>
</html>
<?php mysqli_close($connection); ?>