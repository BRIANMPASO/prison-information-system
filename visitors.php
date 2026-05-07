<?php
session_start();
require_once 'helpers.php';

if (!isset($_SESSION['username'])) { header("Location: index.php"); exit(); }

$username = $_SESSION['username'];
$role     = $_SESSION['role'];

$connection = mysqli_connect('localhost', 'root', '', 'prison_db');
if (!$connection) die("Database connection failed: " . mysqli_connect_error());

// ── HANDLE: Delete a visit ────────────────────────────────────────────────────
if (isset($_GET['delete_id']) && $role === 'admin') {
    $id = intval($_GET['delete_id']);
    $stmt = mysqli_prepare($connection, "DELETE FROM visitors WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    log_action($connection, $username, "Deleted a visitor record (ID: $id)");
    header("Location: visitors.php");
    exit();
}

// ── HANDLE: Approve or Deny a visit (admin only) ──────────────────────────────
if (isset($_GET['approve_id']) && in_array($role, ['admin', 'staff'])) {
    $id  = intval($_GET['approve_id']);
    $now = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($connection,
        "UPDATE visitors SET status='Approved', reviewed_by=?, reviewed_at=? WHERE id=?"
    );
    mysqli_stmt_bind_param($stmt, "ssi", $username, $now, $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    log_action($connection, $username, "Approved visitor request (ID: $id)");
    header("Location: visitors.php");
    exit();
}

if (isset($_GET['deny_id']) && in_array($role, ['admin', 'staff'])) {
    $id  = intval($_GET['deny_id']);
    $now = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($connection,
        "UPDATE visitors SET status='Denied', reviewed_by=?, reviewed_at=? WHERE id=?"
    );
    mysqli_stmt_bind_param($stmt, "ssi", $username, $now, $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    log_action($connection, $username, "Denied visitor request (ID: $id)");
    header("Location: visitors.php");
    exit();
}

// ── HANDLE: Add a new visit ───────────────────────────────────────────────────
$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_visit'])) {
    $visitor_name = trim($_POST['visitor_name']);
    $prisoner_id  = intval($_POST['prisoner_id']);
    $visit_date   = $_POST['visit_date'];
    $visit_time   = $_POST['visit_time'];
    $purpose      = trim($_POST['purpose']);

    if ($visitor_name && $prisoner_id > 0 && $visit_date && $visit_time && $purpose) {
        $stmt = mysqli_prepare($connection,
            "INSERT INTO visitors (visitor_name, prisoner_id, visit_date, visit_time, purpose, status)
             VALUES (?, ?, ?, ?, ?, 'Pending')"
        );
        mysqli_stmt_bind_param($stmt, "sisss", $visitor_name, $prisoner_id, $visit_date, $visit_time, $purpose);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        log_action($connection, $username, "Logged visit request from $visitor_name");
        $success_message = "Visit request added successfully! Status is Pending until approved.";
    } else {
        $error_message = "Please fill in all fields correctly.";
    }
}

// ── SEARCH ────────────────────────────────────────────────────────────────────
// Get search values from the URL (e.g. visitors.php?search=john&status=Pending)
$search        = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';

// Build the WHERE part of the SQL query based on what the user searched
$where = "WHERE 1=1";

if ($search !== '') {
    $safe  = mysqli_real_escape_string($connection, $search);
    $where .= " AND (v.visitor_name LIKE '%$safe%' OR p.full_name LIKE '%$safe%')";
}

if ($status_filter !== '') {
    $safe_status = mysqli_real_escape_string($connection, $status_filter);
    $where      .= " AND v.status = '$safe_status'";
}

// ── PAGINATION ────────────────────────────────────────────────────────────────
$pager = paginate($connection,
    "SELECT COUNT(*) AS total FROM visitors v
     LEFT JOIN prisoners p ON v.prisoner_id = p.id
     $where",
    15
);

// ── LOAD: All visits with prisoner name ───────────────────────────────────────
$visits_result = mysqli_query($connection,
    "SELECT v.*, p.full_name AS prisoner_name
     FROM visitors v
     LEFT JOIN prisoners p ON v.prisoner_id = p.id
     $where
     ORDER BY v.visit_date DESC, v.visit_time DESC
     LIMIT {$pager['per_page']} OFFSET {$pager['offset']}"
);

// ── LOAD: Active prisoners for the dropdown ───────────────────────────────────
$prisoners_result = mysqli_query($connection,
    "SELECT id, full_name FROM prisoners WHERE status = 'active' ORDER BY full_name ASC"
);

// Should we show the Add form?
$show_form = isset($_GET['action']) && $_GET['action'] === 'add';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <title>Visitors – Maula Prison</title>
</head>
<body class="dashboard-body">

<?php $active_page = 'visitors'; require_once 'nav.php'; ?>

<!-- MAIN LAYOUT -->
<div class="dashboard-layout">

    <!-- MAIN CONTENT -->
    <main class="main-content">
        <h1>Visitors</h1>
        <p class="page-subtitle">Log and manage all prisoner visit requests at Maula Prison.</p>

        <!-- Success / Error messages -->
        <?php if ($success_message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <!-- SEARCH BAR -->
        <form method="GET" action="visitors.php" class="search-bar">
          <fieldset ><legend>Quick search</legend>

            <input type="text" name="search"   placeholder="🔍 Search by visitor or prisoner name..."
                   value="<?= htmlspecialchars($search) ?>">

            <select name="status">
                <option value="">All Statuses</option>
                <option value="Pending"  <?= $status_filter === 'Pending'  ? 'selected' : '' ?>>Pending</option>
                <option value="Approved" <?= $status_filter === 'Approved' ? 'selected' : '' ?>>Approved</option>
                <option value="Denied"   <?= $status_filter === 'Denied'   ? 'selected' : '' ?>>Denied</option>
            </select>

            <button type="submit" class="submit-btn">Search</button>

            <?php if ($search || $status_filter): ?>
                <a href="visitors.php" class="action-btn" style="background:#888;">Clear</a>
            <?php endif; ?>
            </fieldset>
        </form>

        <!-- ADD VISIT BUTTON -->
        <div style="margin-bottom: 20px;">
            <a href="visitors.php?action=<?= $show_form ? '' : 'add' ?>" class="action-btn">
                <?= $show_form ? '✖ Cancel' : ' Log New Visit' ?>
            </a>
        </div>

        <!-- ADD VISIT FORM -->
        <?php if ($show_form): ?>
        <div class="form-card">
            <h2>Log New Visit Request</h2>
            <form action="visitors.php?action=add" method="post">

                <div class="form-row">
                    <div class="form-group">
                        <label>Visitor Full Name</label>
                        <input type="text" name="visitor_name" placeholder="e.g. Agnes Phiri" required>
                    </div>
                    <div class="form-group">
                        <label>Prisoner to Visit</label>
                        <select name="prisoner_id" required>
                            <option value="">-- Select Prisoner --</option>
                            <?php while ($prisoner = mysqli_fetch_assoc($prisoners_result)): ?>
                                <option value="<?= $prisoner['id'] ?>">
                                    <?= htmlspecialchars($prisoner['full_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Visit Date</label>
                        <input type="date" name="visit_date" required>
                    </div>
                    <div class="form-group">
                        <label>Visit Time</label>
                        <input type="time" name="visit_time" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Purpose of Visit</label>
                    <select name="purpose" required>
                        <option value="">-- Select Purpose --</option>
                        <option value="Family Visit">Family Visit</option>
                        <option value="Legal Counsel">Legal Counsel</option>
                        <option value="Medical Visit">Medical Visit</option>
                        <option value="Religious Visit">Religious Visit</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <button type="submit" name="add_visit" class="submit-btn">Save Visit Request</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- VISITS TABLE -->
        <div class="table-card">

            <!-- Record count -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <span style="font-size:0.85rem; color:#888;">
                    Showing <?= mysqli_num_rows($visits_result) ?> of <?= $pager['total'] ?> records
                </span>
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Visitor Name</th>
                        <th>Prisoner</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Purpose</th>
                        <th>Status</th>
                        <th>Reviewed By</th>
                        <th>Reviewed At</th>
                        <?php if (in_array($role, ['admin', 'staff'])): ?>
                            <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($visits_result) === 0): ?>
                        <tr>
                            <td colspan="10" style="text-align:center; color:#888;">No visit records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php while ($visit = mysqli_fetch_assoc($visits_result)): ?>

                        <?php
                            // Pick a badge colour based on the visit status
                            if ($visit['status'] === 'Approved') {
                                $badge_class = 'badge-available'; // green
                            } elseif ($visit['status'] === 'Denied') {
                                $badge_class = 'badge-full';      // red
                            } else {
                                $badge_class = 'badge-pending';   // orange
                            }
                        ?>

                        <tr>
                            <td><?= $visit['id'] ?></td>
                            <td><?= htmlspecialchars($visit['visitor_name']) ?></td>
                            <td><?= htmlspecialchars($visit['prisoner_name'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($visit['visit_date']) ?></td>
                            <td><?= date('H:i', strtotime($visit['visit_time'])) ?></td>
                            <td><?= htmlspecialchars($visit['purpose']) ?></td>
                            <td>
                                <span class="badge <?= $badge_class ?>">
                                    <?= htmlspecialchars($visit['status']) ?>
                                </span>
                            </td>
                            <td style="font-size:0.85rem; color:#555;">
                                <?= $visit['reviewed_by'] ? htmlspecialchars($visit['reviewed_by']) : '—' ?>
                            </td>
                            <td style="font-size:0.82rem; color:#888;">
                                <?= $visit['reviewed_at']
                                    ? date('d M Y, H:i', strtotime($visit['reviewed_at']))
                                    : '—' ?>
                            </td>

                            <?php if (in_array($role, ['admin', 'staff'])): ?>
                            <td class="actions-cell">
                                <?php if ($visit['status'] === 'Pending'): ?>
                                    <a href="visitors.php?approve_id=<?= $visit['id'] ?>"
                                       class="edit-btn"
                                       onclick="return confirm('Approve this visit?')">✅ Approve</a>
                                    <a href="visitors.php?deny_id=<?= $visit['id'] ?>"
                                       class="release-btn"
                                       onclick="return confirm('Deny this visit?')">❌ Deny</a>
                                <?php endif; ?>
                                <?php if ($role === 'admin'): ?>
                                <a href="visitors.php?delete_id=<?= $visit['id'] ?>"
                                   class="delete-btn"
                                   onclick="return confirm('Delete this visit record?')">🗑</a>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>

                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- PAGINATION -->
            <?php render_pagination($pager, 'visitors.php', ['search' => $search, 'status' => $status_filter]); ?>

        </div>

    </main>
</div>

</body>
</html>
<?php mysqli_close($connection); ?>
