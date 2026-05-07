<?php
session_start();
require_once 'helpers.php';

if (!isset($_SESSION['username'])) { header("Location: index.php"); exit(); }

$username = $_SESSION['username'];
$role     = $_SESSION['role'];

$connection = mysqli_connect('localhost', 'root', '', 'prison_db');
if (!$connection) die("Database connection failed: " . mysqli_connect_error());

// ── HANDLE: Delete ────────────────────────────────────────────────────────────
if (isset($_GET['delete_id']) && $role === 'admin') {
    $id  = intval($_GET['delete_id']);
    $row = mysqli_fetch_assoc(mysqli_query($connection, "SELECT full_name FROM prisoners WHERE id = $id"));
    $stmt = mysqli_prepare($connection, "DELETE FROM prisoners WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    log_action($connection, $username, "Deleted prisoner: " . $row['full_name']);
    header("Location: prisoners.php"); exit();
}

// ── HANDLE: Release ───────────────────────────────────────────────────────────
if (isset($_GET['release_id']) && $role === 'admin') {
    $id    = intval($_GET['release_id']);
    $today = date('Y-m-d');
    $stmt  = mysqli_prepare($connection,
        "UPDATE prisoners SET status = 'released', release_date = ?, cell_id = NULL WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $today, $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $row = mysqli_fetch_assoc(mysqli_query($connection, "SELECT full_name FROM prisoners WHERE id = $id"));
    log_action($connection, $username, "Released prisoner: " . $row['full_name']);
    header("Location: prisoners.php"); exit();
}

// ── HANDLE: Add ───────────────────────────────────────────────────────────────
$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_prisoner'])) {
    $full_name       = trim($_POST['full_name']);
    $crime           = trim($_POST['crime']);
    $sentence_months = intval($_POST['sentence']);
    $cell_id         = intval($_POST['cell_id']);
    $date_entered    = $_POST['date_entered'];

    $today = date('Y-m-d');

    if ($full_name && $crime && $sentence_months > 0 && $cell_id > 0 && $date_entered) {
        if ($date_entered > $today) {
            $error_message = "Date entered cannot be in the future.";
        } else {
            $cap = mysqli_fetch_assoc(mysqli_query($connection,
                "SELECT c.capacity, COUNT(p.id) AS occupants
                 FROM cells c LEFT JOIN prisoners p ON c.id = p.cell_id AND p.status = 'active'
                 WHERE c.id = $cell_id GROUP BY c.id"));
            if ($cap && $cap['occupants'] >= $cap['capacity']) {
                $error_message = "That cell is full. Please choose a different cell.";
            } else {
                // Calculate release date using months
                $expected_release = date('Y-m-d', strtotime($date_entered . ' +' . $sentence_months . ' months'));

                $stmt = mysqli_prepare($connection,
                    "INSERT INTO prisoners (full_name, crime, sentence_months, cell_id, date_entered, release_date, status)
                     VALUES (?, ?, ?, ?, ?, ?, 'active')");
                mysqli_stmt_bind_param($stmt, "ssiiss", $full_name, $crime, $sentence_months, $cell_id, $date_entered, $expected_release);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                log_action($connection, $username, "Added prisoner: $full_name (Expected release: $expected_release)");
                $success_message = "Prisoner added successfully! Expected release date: $expected_release";
            }
        }
    } else {
        $error_message = "Please fill in all fields correctly. Sentence must be at least 1 month.";
    }
}

// ── HANDLE: Edit ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_prisoner']) && $role === 'admin') {
    $id              = intval($_POST['edit_id']);
    $full_name       = trim($_POST['full_name']);
    $crime           = trim($_POST['crime']);
    $sentence_months = intval($_POST['sentence']);
    $cell_id         = intval($_POST['cell_id']);
    $date_entered    = $_POST['date_entered'];

    if ($full_name && $crime && $sentence_months > 0 && $cell_id > 0 && $date_entered) {
        // Recalculate release date using months
        $expected_release = date('Y-m-d', strtotime($date_entered . ' +' . $sentence_months . ' months'));

        $stmt = mysqli_prepare($connection,
            "UPDATE prisoners SET full_name=?, crime=?, sentence_months=?, cell_id=?, date_entered=?, release_date=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, "ssiissi", $full_name, $crime, $sentence_months, $cell_id, $date_entered, $expected_release, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        log_action($connection, $username, "Edited prisoner: $full_name (Expected release: $expected_release)");
        $success_message = "Prisoner updated successfully! Expected release date: $expected_release";
    } else {
        $error_message = "Please fill in all fields correctly.";
    }
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
function format_sentence_p($months) {
    if ($months < 12) return "$months month" . ($months > 1 ? 's' : '');
    $years  = intdiv($months, 12);
    $remain = $months % 12;
    $label  = "$years yr" . ($years > 1 ? 's' : '');
    if ($remain > 0) $label .= " $remain mo";
    return $label;
}

// ── SEARCH + FILTER ───────────────────────────────────────────────────────────
$search        = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'active';
$where         = "WHERE 1=1";

if ($search !== '') {
    $safe  = mysqli_real_escape_string($connection, $search);
    $where .= " AND (p.full_name LIKE '%$safe%' OR p.crime LIKE '%$safe%')";
}
$where .= $status_filter === 'released' ? " AND p.status = 'released'" : " AND p.status = 'active'";

// ── PAGINATION ────────────────────────────────────────────────────────────────
$pager = paginate($connection, "SELECT COUNT(*) AS total FROM prisoners p $where", 15);

// ── LOAD DATA ─────────────────────────────────────────────────────────────────
$prisoners_result = mysqli_query($connection,
    "SELECT p.*, c.cell_number FROM prisoners p
     LEFT JOIN cells c ON p.cell_id = c.id
     $where ORDER BY p.id DESC
     LIMIT {$pager['per_page']} OFFSET {$pager['offset']}");

$cells_result = mysqli_query($connection, "SELECT id, cell_number FROM cells ORDER BY cell_number ASC");
$cells_array  = [];
while ($c = mysqli_fetch_assoc($cells_result)) $cells_array[] = $c;

$edit_prisoner = null;
if (isset($_GET['edit_id']) && $role === 'admin') {
    $eid = intval($_GET['edit_id']);
    $edit_prisoner = mysqli_fetch_assoc(mysqli_query($connection, "SELECT * FROM prisoners WHERE id = $eid"));
}

$show_form = isset($_GET['action']) && $_GET['action'] === 'add';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <title>Prisoners – Maula Prison</title>
</head>
<body class="dashboard-body">

<?php $active_page = 'prisoners'; require_once 'nav.php'; ?>

<div class="dashboard-layout">
<main class="main-content">
        <h1>🔒 Prisoners</h1>
        <p class="page-subtitle">Manage all prisoners at Maula Prison.</p>

        <?php if ($success_message): ?><div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div><?php endif; ?>
        <?php if ($error_message): ?><div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div><?php endif; ?>

        <!-- SEARCH BAR -->
        <form method="GET" action="prisoners.php" class="search-bar">
            <input type="text" name="search" placeholder="🔍 Search by name or crime..." value="<?= htmlspecialchars($search) ?>">
            <select name="status">
                <option value="active"   <?= $status_filter === 'active'   ? 'selected' : '' ?>>Active</option>
                <option value="released" <?= $status_filter === 'released' ? 'selected' : '' ?>>Released</option>
            </select>
            <button type="submit" class="submit-btn">Search</button>
            <?php if ($search || $status_filter !== 'active'): ?>
                <a href="prisoners.php" class="action-btn" style="background:#888;">Clear</a>
            <?php endif; ?>
        </form>

        <div style="margin-bottom:20px;">
            <a href="prisoners.php?action=<?= $show_form ? '' : 'add' ?>" class="action-btn">
                <?= $show_form ? '✖ Cancel' : '➕ Add Prisoner' ?>
            </a>
        </div>

        <!-- ADD FORM -->
        <?php if ($show_form): ?>
        <div class="form-card">
            <h2>Add New Prisoner</h2>
            <form action="prisoners.php?action=add" method="post">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" placeholder="e.g. John Doe" required>
                    </div>
                    <div class="form-group">
                        <label>Crime Committed</label>
                        <input type="text" name="crime" placeholder="e.g. Armed Robbery" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Sentence (Months)</label>
                        <input type="number" name="sentence" min="1" placeholder="e.g. 6 = 6 months, 60 = 5 years" required>
                        <small style="color:#888; font-size:0.78rem;">Enter total months</small>
                    </div>
                    <div class="form-group">
                        <label>Assign to Cell</label>
                        <select name="cell_id" required>
                            <option value="">-- Select a Cell --</option>
                            <?php foreach ($cells_array as $cell): ?>
                                <option value="<?= $cell['id'] ?>">Cell <?= htmlspecialchars($cell['cell_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group" style="max-width:260px;">
                    <label>Date Entered</label>
                    <input type="date" name="date_entered" max="<?= date('Y-m-d') ?>" required>
                    <small style="color:#888; font-size:0.78rem;">Cannot be a future date</small>
                </div>
                <button type="submit" name="add_prisoner" class="submit-btn">Save Prisoner</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- EDIT FORM -->
        <?php if ($edit_prisoner): ?>
        <div class="form-card" style="border-left:4px solid #f0a500;">
            <h2>✏️ Edit Prisoner</h2>
            <form action="prisoners.php" method="post">
                <input type="hidden" name="edit_id" value="<?= $edit_prisoner['id'] ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" value="<?= htmlspecialchars($edit_prisoner['full_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Crime Committed</label>
                        <input type="text" name="crime" value="<?= htmlspecialchars($edit_prisoner['crime']) ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Sentence (Months)</label>
                        <input type="number" name="sentence" min="1" value="<?= $edit_prisoner['sentence_months'] ?>" required>
                        <small style="color:#888; font-size:0.78rem;">Enter total months — e.g. 6 = 6 months, 24 = 2 years</small>
                    </div>
                    <div class="form-group">
                        <label>Cell</label>
                        <select name="cell_id" required>
                            <?php foreach ($cells_array as $cell): ?>
                                <option value="<?= $cell['id'] ?>" <?= $cell['id'] == $edit_prisoner['cell_id'] ? 'selected' : '' ?>>
                                    Cell <?= htmlspecialchars($cell['cell_number']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group" style="max-width:260px;">
                    <label>Date Entered</label>
                    <input type="date" name="date_entered" value="<?= $edit_prisoner['date_entered'] ?>" max="<?= date('Y-m-d') ?>" required>
                    <small style="color:#888; font-size:0.78rem;">Cannot be a future date</small>
                </div>
                <button type="submit" name="edit_prisoner" class="submit-btn">Update Prisoner</button>
                <a href="prisoners.php" style="margin-left:12px;color:#888;font-size:0.9rem;">Cancel</a>
            </form>
        </div>
        <?php endif; ?>

        <!-- TABLE -->
        <div class="table-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <span style="font-size:0.85rem;color:#888;">
                    Showing <?= mysqli_num_rows($prisoners_result) ?> of <?= $pager['total'] ?> records
                </span>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th><th>Full Name</th><th>Crime</th><th>Sentence</th>
                        <th>Cell</th><th>Date Entered</th><th>Expected Release</th><th>Status</th>
                        <?php if ($role === 'admin'): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($prisoners_result) === 0): ?>
                        <tr><td colspan="8" style="text-align:center;color:#888;">No prisoners found.</td></tr>
                    <?php else: ?>
                        <?php while ($p = mysqli_fetch_assoc($prisoners_result)): ?>
                        <tr>
                            <td><?= $p['id'] ?></td>
                            <td><?= htmlspecialchars($p['full_name']) ?></td>
                            <td><?= htmlspecialchars($p['crime']) ?></td>
                            <td><?= format_sentence_p($p['sentence_months']) ?></td>
                            <td><?= $p['cell_number'] ? 'Cell '.htmlspecialchars($p['cell_number']) : '—' ?></td>
                            <td><?= htmlspecialchars($p['date_entered']) ?></td>
                            <td>
                                <?php if ($p['release_date']): ?>
                                    <?php
                                        // Check if the release date is within the next 30 days
                                        $days_left = (strtotime($p['release_date']) - time()) / 86400;
                                    ?>
                                    <?php if ($days_left <= 30 && $days_left >= 0): ?>
                                        <!-- Highlight in orange if releasing soon -->
                                        <span style="color:#b8860b; font-weight:bold;">
                                            📅 <?= htmlspecialchars($p['release_date']) ?>
                                            <br><small>(<?= ceil($days_left) ?> days left)</small>
                                        </span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($p['release_date']) ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $p['status'] === 'active' ? 'badge-available' : 'badge-released' ?>">
                                    <?= ucfirst($p['status']) ?>
                                </span>
                            </td>
                            <?php if ($role === 'admin'): ?>
                            <td class="actions-cell">
                                <?php if ($p['status'] === 'active'): ?>
                                    <a href="prisoners.php?edit_id=<?= $p['id'] ?>" class="edit-btn">✏️</a>
                                    <a href="prisoners.php?release_id=<?= $p['id'] ?>" class="release-btn"
                                       onclick="return confirm('Release <?= htmlspecialchars($p['full_name']) ?>?')">🔓</a>
                                <?php endif; ?>
                                <a href="prisoners.php?delete_id=<?= $p['id'] ?>" class="delete-btn"
                                   onclick="return confirm('Delete this record?')">🗑</a>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php render_pagination($pager, 'prisoners.php', ['search' => $search, 'status' => $status_filter]); ?>
        </div>
    </main>
</div>
</body>
</html>
<?php mysqli_close($connection); ?>