<?php
session_start();
require_once 'helpers.php';

if (!isset($_SESSION['username'])) { header("Location: index.php"); exit(); }

// Only receptionists can access this page
if ($_SESSION['role'] !== 'receptionist') {
    header("Location: dashboard.php");
    exit();
}

$username = $_SESSION['username'];
$role     = $_SESSION['role'];

$connection = mysqli_connect('localhost', 'root', '', 'prison_db');
if (!$connection) die("Database connection failed: " . mysqli_connect_error());

$active_page = 'submit_prisoner';

// ── CAPACITY CHECK ────────────────────────────────────────────────────────────
$capacity_info = mysqli_fetch_assoc(mysqli_query($connection,
    "SELECT
        (SELECT COALESCE(SUM(capacity),0) FROM cells) AS total_capacity,
        (SELECT COUNT(*) FROM prisoners WHERE status = 'active') AS total_active"
));
$total_capacity = intval($capacity_info['total_capacity']);
$total_active   = intval($capacity_info['total_active']);
$prison_full    = ($total_capacity > 0 && $total_active >= $total_capacity);

// ── LOAD AVAILABLE CELLS (only cells with space) ──────────────────────────────
$cells_result = mysqli_query($connection,
    "SELECT c.id, c.cell_number, c.block, c.capacity,
            COUNT(p.id) AS occupants
     FROM cells c
     LEFT JOIN prisoners p ON c.id = p.cell_id AND p.status = 'active'
     GROUP BY c.id
     HAVING occupants < c.capacity
     ORDER BY c.cell_number ASC"
);
$available_cells = [];
while ($c = mysqli_fetch_assoc($cells_result)) $available_cells[] = $c;

// ── HANDLE FORM SUBMISSION ────────────────────────────────────────────────────
$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_prisoner'])) {
    $full_name       = trim($_POST['full_name']);
    $crime           = trim($_POST['crime']);
    $sentence_months = intval($_POST['sentence_months']);
    $cell_id         = intval($_POST['cell_id']);
    $date_entered    = $_POST['date_entered'];

    $today = date('Y-m-d');

    if ($full_name && $crime && $sentence_months > 0 && $cell_id > 0 && $date_entered) {

        if ($date_entered > $today) {
            $error_message = "Date entered cannot be in the future.";

        } elseif ($prison_full) {
            $error_message = "Cannot submit — prison is at full capacity ($total_active/$total_capacity beds).";

        } else {
            // Double-check the chosen cell is still not full
            $cell_check = mysqli_fetch_assoc(mysqli_query($connection,
                "SELECT c.capacity, COUNT(p.id) AS occupants, c.cell_number
                 FROM cells c
                 LEFT JOIN prisoners p ON c.id = p.cell_id AND p.status = 'active'
                 WHERE c.id = $cell_id GROUP BY c.id"
            ));

            if (!$cell_check) {
                $error_message = "Selected cell does not exist. Please choose another.";
            } elseif ($cell_check['occupants'] >= $cell_check['capacity']) {
                $error_message = "Cell {$cell_check['cell_number']} is now full. Please select a different cell.";
            } else {
                $stmt = mysqli_prepare($connection,
                    "INSERT INTO pending_prisoners (full_name, crime, sentence_months, cell_id, date_entered, submitted_by)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                mysqli_stmt_bind_param($stmt, "ssiiss",
                    $full_name, $crime, $sentence_months, $cell_id, $date_entered, $username
                );
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                log_action($connection, $username,
                    "Submitted prisoner for approval: $full_name | " . format_sentence($sentence_months) . " | Cell {$cell_check['cell_number']}"
                );
                $success_message = "Prisoner '$full_name' submitted for Cell {$cell_check['cell_number']} — awaiting admin approval.";

                // Refresh available cells after submission
                $cells_result2 = mysqli_query($connection,
                    "SELECT c.id, c.cell_number, c.block, c.capacity,
                            COUNT(p.id) AS occupants
                     FROM cells c
                     LEFT JOIN prisoners p ON c.id = p.cell_id AND p.status = 'active'
                     GROUP BY c.id
                     HAVING occupants < c.capacity
                     ORDER BY c.cell_number ASC"
                );
                $available_cells = [];
                while ($c = mysqli_fetch_assoc($cells_result2)) $available_cells[] = $c;
            }
        }
    } else {
        $error_message = "Please fill in all fields correctly. Sentence must be at least 1 month.";
    }
}

// ── LOAD THIS RECEPTIONIST'S RECENT SUBMISSIONS ───────────────────────────────
$my_submissions = mysqli_query($connection,
    "SELECT pp.*, c.cell_number
     FROM pending_prisoners pp
     LEFT JOIN cells c ON pp.cell_id = c.id
     WHERE pp.submitted_by = '" . mysqli_real_escape_string($connection, $username) . "'
     ORDER BY pp.submitted_at DESC
     LIMIT 20"
);

$today_val = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <title>Submit Prisoner – Maula Prison</title>
</head>
<body class="dashboard-body">

<?php require_once 'nav.php'; ?>

<div class="dashboard-layout">
<main class="main-content">

    <h1>📋 Submit Prisoner for Approval</h1>
    <p class="page-subtitle">Fill in the prisoner details below. An admin will review and approve your submission.</p>

    <!-- Prison capacity info -->
    <?php if ($prison_full): ?>
        <div class="alert alert-error">
            ⛔ <strong>Prison is at full capacity</strong> (<?= $total_active ?>/<?= $total_capacity ?> beds) — submissions cannot be made until a prisoner is released.
        </div>
    <?php elseif ($total_capacity > 0): ?>
        <div class="alert alert-success" style="font-size:0.9rem;">
            🏠 Prison occupancy: <strong><?= $total_active ?>/<?= $total_capacity ?></strong> beds occupied.
            <?= count($available_cells) ?> cell(s) with space available.
        </div>
    <?php endif; ?>

    <!-- Flash messages -->
    <?php if ($success_message): ?>
        <div class="alert alert-success" id="flash-msg"><?= htmlspecialchars($success_message) ?></div>
        <script>
            setTimeout(function() {
                var el = document.getElementById('flash-msg');
                el.style.opacity = '0';
                setTimeout(function() { el.style.display = 'none'; }, 1000);
            }, 3000);
        </script>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="alert alert-error" id="flash-msg"><?= htmlspecialchars($error_message) ?></div>
        <script>
            setTimeout(function() {
                var el = document.getElementById('flash-msg');
                el.style.opacity = '0';
                setTimeout(function() { el.style.display = 'none'; }, 1000);
            }, 3000);
        </script>
    <?php endif; ?>

    <!-- SUBMISSION FORM -->
    <?php if (!$prison_full): ?>
    <div class="form-card">
        <h2>New Prisoner Details</h2>
        <form method="POST" action="submit_prisoner.php">

            <div class="form-row">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" placeholder="e.g. John Banda" required>
                </div>
                <div class="form-group">
                    <label>Crime / Offence</label>
                    <input type="text" name="crime" placeholder="e.g. Armed Robbery" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Sentence (Months)</label>
                    <input type="number" name="sentence_months" min="1" max="1200"
                           placeholder="e.g. 6 for 6 months, 60 for 5 years" required>
                    <small style="color:#888; font-size:0.78rem;">Enter total months — e.g. 6 = 6 months, 24 = 2 years</small>
                </div>
                <div class="form-group">
                    <label>Date Entered Prison</label>
                    <input type="date" name="date_entered" max="<?= $today_val ?>" required>
                    <small style="color:#888; font-size:0.78rem;">Cannot be a future date</small>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Assign Cell</label>
                    <?php if (empty($available_cells)): ?>
                        <select name="cell_id" disabled>
                            <option value="">— No cells with available space —</option>
                        </select>
                        <small style="color:#c0392b; font-size:0.78rem;">All cells are currently full.</small>
                    <?php else: ?>
                        <select name="cell_id" required>
                            <option value="">— Select an Available Cell —</option>
                            <?php foreach ($available_cells as $cell): ?>
                                <option value="<?= $cell['id'] ?>">
                                    Cell <?= htmlspecialchars($cell['cell_number']) ?>
                                    (<?= htmlspecialchars($cell['block']) ?>)
                                    — <?= $cell['occupants'] ?>/<?= $cell['capacity'] ?> occupied
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit" name="submit_prisoner" class="submit-btn"
                <?= empty($available_cells) ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : '' ?>>
                📤 Submit for Approval
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- MY RECENT SUBMISSIONS -->
    <h2 style="font-size:1.1rem; margin-bottom:14px; color:#333;">My Recent Submissions</h2>
    <div class="table-card">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Full Name</th>
                    <th>Crime</th>
                    <th>Sentence</th>
                    <th>Cell Requested</th>
                    <th>Date Entered</th>
                    <th>Submitted At</th>
                    <th>Status</th>
                    <th>Reviewed By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($my_submissions) === 0): ?>
                    <tr>
                        <td colspan="9" style="text-align:center; color:#888;">
                            You have not submitted any prisoners yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php while ($row = mysqli_fetch_assoc($my_submissions)): ?>
                    <?php
                        if ($row['status'] === 'approved')      $bc = 'badge-approved';
                        elseif ($row['status'] === 'rejected')  $bc = 'badge-rejected';
                        else                                     $bc = 'badge-pending';
                    ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td><?= htmlspecialchars($row['full_name']) ?></td>
                        <td><?= htmlspecialchars($row['crime']) ?></td>
                        <td><?= format_sentence($row['sentence_months']) ?></td>
                        <td>
                            <?= $row['cell_number']
                                ? 'Cell ' . htmlspecialchars($row['cell_number'])
                                : '<span style="color:#888;">—</span>' ?>
                        </td>
                        <td><?= htmlspecialchars($row['date_entered']) ?></td>
                        <td style="font-size:0.82rem; color:#888;">
                            <?= date('d M Y, H:i', strtotime($row['submitted_at'])) ?>
                        </td>
                        <td>
                            <span class="badge <?= $bc ?>">
                                <?= ucfirst($row['status']) ?>
                            </span>
                        </td>
                        <td style="color:#888; font-size:0.85rem;">
                            <?= $row['reviewed_by'] ? htmlspecialchars($row['reviewed_by']) : '—' ?>
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
