<?php
session_start();
require_once 'helpers.php';

if (!isset($_SESSION['username'])) { header("Location: index.php"); exit(); }

// Only admins can see reports
if ($_SESSION['role'] !== 'admin') { header("Location: dashboard.php"); exit(); }

$username = $_SESSION['username'];
$role     = $_SESSION['role'];

$connection = mysqli_connect('localhost', 'root', '', 'prison_db');
if (!$connection) die("Database connection failed: " . mysqli_connect_error());

// ── LOAD ALL REPORT NUMBERS ───────────────────────────────────────────────────

// Total active prisoners
$total_prisoners = mysqli_fetch_assoc(mysqli_query($connection,
    "SELECT COUNT(*) AS total FROM prisoners WHERE status = 'active'"))['total'];

// Total cells
$total_cells = mysqli_fetch_assoc(mysqli_query($connection,
    "SELECT COUNT(*) AS total FROM cells"))['total'];

// Full cells (active occupants >= capacity)
$full_cells = mysqli_fetch_assoc(mysqli_query($connection,
    "SELECT COUNT(*) AS total FROM cells c
     WHERE (SELECT COUNT(*) FROM prisoners p WHERE p.cell_id = c.id AND p.status = 'active') >= c.capacity"))['total'];

// Total staff
$total_staff = mysqli_fetch_assoc(mysqli_query($connection,
    "SELECT COUNT(*) AS total FROM staff"))['total'];

// Prisoners added this month
$new_this_month = mysqli_fetch_assoc(mysqli_query($connection,
    "SELECT COUNT(*) AS total FROM prisoners
     WHERE MONTH(date_entered) = MONTH(CURDATE())
     AND YEAR(date_entered) = YEAR(CURDATE())"))['total'];

// Released this month
$released_this_month = mysqli_fetch_assoc(mysqli_query($connection,
    "SELECT COUNT(*) AS total FROM prisoners
     WHERE status = 'released'
     AND MONTH(release_date) = MONTH(CURDATE())
     AND YEAR(release_date) = YEAR(CURDATE())"))['total'];

// Pending visitor requests
$pending_visits = mysqli_fetch_assoc(mysqli_query($connection,
    "SELECT COUNT(*) AS total FROM visitors WHERE status = 'Pending'"))['total'];

// Top 5 crimes
$crime_result = mysqli_query($connection,
    "SELECT crime, COUNT(*) AS total FROM prisoners
     WHERE status = 'active'
     GROUP BY crime ORDER BY total DESC LIMIT 5");

// Staff per shift
$shift_result = mysqli_query($connection,
    "SELECT shift, COUNT(*) AS total FROM staff GROUP BY shift ORDER BY total DESC");

// Most occupied cells
$cell_result = mysqli_query($connection,
    "SELECT c.cell_number, c.capacity, COUNT(p.id) AS occupants
     FROM cells c
     LEFT JOIN prisoners p ON c.id = p.cell_id AND p.status = 'active'
     GROUP BY c.id ORDER BY occupants DESC LIMIT 5");

// Recently added prisoners
$recent_prisoners = mysqli_query($connection,
    "SELECT full_name, crime, date_entered FROM prisoners ORDER BY id DESC LIMIT 5");

// Recent visits
$recent_visits = mysqli_query($connection,
    "SELECT v.visitor_name, p.full_name AS prisoner_name, v.visit_date, v.status
     FROM visitors v
     LEFT JOIN prisoners p ON v.prisoner_id = p.id
     ORDER BY v.id DESC LIMIT 5");

// Upcoming releases in next 60 days
$upcoming_releases = mysqli_query($connection,
    "SELECT full_name, date_entered, sentence_months, release_date,
            DATEDIFF(release_date, CURDATE()) AS days_left
     FROM prisoners
     WHERE status = 'active'
     AND release_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
     ORDER BY release_date ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <title>Reports – Maula Prison</title>

    <!-- Print styles: hide sidebar and buttons when printing -->
    <style>
        @media print {
            .sidebar, .navbar, .no-print { display: none !important; }
            .main-content { margin: 0; padding: 0; }
            body { background: white; }
        }
    </style>
</head>
<body class="dashboard-body">

<?php $active_page = 'reports'; require_once 'nav.php'; ?>

<div class="dashboard-layout">
<main class="main-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
            <h1> Reports</h1>

            <!-- Print and Export buttons -->
            <div class="no-print" style="display:flex; gap:10px;">
                <button onclick="window.print()" class="action-btn"> Print Report</button>
                
            </div>
        </div>
        <p class="page-subtitle">Full overview of Maula Prison — generated on <?= date('d M Y') ?>.</p>

        <!-- SUMMARY STAT CARDS -->
        <div class="stats-grid" style="margin-bottom:36px;">
            <div class="stat-card">
            
                <div class="stat-info">
                    <span class="stat-number"><?= $total_prisoners ?></span>
                    <span class="stat-label">Active Prisoners</span>
                </div>
            </div>
            <div class="stat-card">
                
                <div class="stat-info">
                    <span class="stat-number"><?= $total_cells ?></span>
                    <span class="stat-label">Total Cells</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <span class="stat-number"><?= $full_cells ?></span>
                    <span class="stat-label">Full Cells</span>
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
                    <span class="stat-number"><?= $new_this_month ?></span>
                    <span class="stat-label">New This Month</span>
                </div>
            </div>
            <div class="stat-card">
                <
                <div class="stat-info">
                    <span class="stat-number"><?= $released_this_month ?></span>
                    <span class="stat-label">Released This Month</span>
                </div>
            </div>
            <div class="stat-card">
                
                <div class="stat-info">
                    <span class="stat-number"><?= $pending_visits ?></span>
                    <span class="stat-label">Pending Visits</span>
                </div>
            </div>
        </div>

        <!-- REPORT TABLES (2 column grid) -->
        <div class="reports-grid">

            <!-- Top Crimes -->
            <div class="table-card">
                <h2 style="font-size:1rem; margin-bottom:14px; color:#333;">🔍 Top Crimes</h2>
                <table class="data-table">
                    <thead><tr><th>Crime</th><th>Count</th></tr></thead>
                    <tbody>
                        <?php if (mysqli_num_rows($crime_result) === 0): ?>
                            <tr><td colspan="2" style="text-align:center;color:#888;">No data yet.</td></tr>
                        <?php else: ?>
                            <?php while ($row = mysqli_fetch_assoc($crime_result)): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['crime']) ?></td>
                                <td><?= $row['total'] ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>



            <!-- Most Occupied Cells -->
            <div class="table-card">
                <h2 style="font-size:1rem; margin-bottom:14px; color:#333;"> Most Occupied Cells</h2>
                <table class="data-table">
                    <thead><tr><th>Cell</th><th>Occupants</th><th>Capacity</th></tr></thead>
                    <tbody>
                        <?php if (mysqli_num_rows($cell_result) === 0): ?>
                            <tr><td colspan="3" style="text-align:center;color:#888;">No data yet.</td></tr>
                        <?php else: ?>
                            <?php while ($row = mysqli_fetch_assoc($cell_result)): ?>
                            <tr>
                                <td>Cell <?= htmlspecialchars($row['cell_number']) ?></td>
                                <td><?= $row['occupants'] ?></td>
                                <td><?= $row['capacity'] ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recently Added Prisoners -->
            <div class="table-card">
                <h2 style="font-size:1rem; margin-bottom:14px; color:#333;">🆕 Recently Added Prisoners</h2>
                <table class="data-table">
                    <thead><tr><th>Name</th><th>Crime</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php if (mysqli_num_rows($recent_prisoners) === 0): ?>
                            <tr><td colspan="3" style="text-align:center;color:#888;">No data yet.</td></tr>
                        <?php else: ?>
                            <?php while ($row = mysqli_fetch_assoc($recent_prisoners)): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['full_name']) ?></td>
                                <td><?= htmlspecialchars($row['crime']) ?></td>
                                <td><?= htmlspecialchars($row['date_entered']) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Visitor Requests -->
            <div class="table-card">
                <h2 style="font-size:1rem; margin-bottom:14px; color:#333;">Recent Visitor Requests</h2>
                <table class="data-table">
                    <thead><tr><th>Visitor</th><th>Prisoner</th><th>Date</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if (mysqli_num_rows($recent_visits) === 0): ?>
                            <tr><td colspan="4" style="text-align:center;color:#888;">No data yet.</td></tr>
                        <?php else: ?>
                            <?php while ($row = mysqli_fetch_assoc($recent_visits)): ?>
                            <?php
                                if ($row['status'] === 'Approved')     $cls = 'badge-available';
                                elseif ($row['status'] === 'Denied')   $cls = 'badge-full';
                                else                                    $cls = 'badge-pending';
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($row['visitor_name']) ?></td>
                                <td><?= htmlspecialchars($row['prisoner_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($row['visit_date']) ?></td>
                                <td><span class="badge <?= $cls ?>"><?= $row['status'] ?></span></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div><!-- end reports-grid -->

        <!-- UPCOMING RELEASES — full width table below the grid -->
        <div class="table-card" style="margin-top:24px;">
            <h2 style="font-size:1rem; margin-bottom:14px; color:#333;">Upcoming Releases </h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Prisoner Name</th>
                        <th>Date Entered</th>
                        <th>Sentence</th>
                        <th>Expected Release</th>
                        <th>Days Remaining</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($upcoming_releases) === 0): ?>
                        <tr>
                            <td colspan="5" style="text-align:center;color:#888;">
                                No prisoners are due for release in the next 60 days.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php while ($row = mysqli_fetch_assoc($upcoming_releases)): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['full_name']) ?></td>
                            <td><?= htmlspecialchars($row['date_entered']) ?></td>
                            <td><?= format_sentence($row['sentence_months']) ?></td>
                            <td><?= htmlspecialchars($row['release_date']) ?></td>
                            <td>
                                <?php if ($row['days_left'] <= 7): ?>
                                    <span style="color:#c0392b; font-weight:bold;"> <?= $row['days_left'] ?> days</span>
                                <?php else: ?>
                                    <span style="color:#b8860b; font-weight:bold;"> <?= $row['days_left'] ?> days</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>
</div>

</body>
</html>
<?php mysqli_close($connection); ?>