<?php
session_start();
require_once 'helpers.php';

if (!isset($_SESSION['username'])) { header("Location: index.php"); exit(); }

$username = $_SESSION['username'];
$role     = $_SESSION['role'];

$connection = mysqli_connect('localhost', 'root', '', 'prison_db');
if (!$connection) die("Database connection failed: " . mysqli_connect_error());

// ── HANDLE: Delete ─────────────────────────────────────────────────────────
if (isset($_GET['delete_id']) && $role === 'admin') {
    $id = intval($_GET['delete_id']);
    // Block delete if cell still has active prisoners
    $check = mysqli_fetch_assoc(mysqli_query($connection,
        "SELECT COUNT(*) AS total FROM prisoners WHERE cell_id = $id AND status = 'active'"));
    if ($check['total'] > 0) {
        $error_message = "Cannot delete this cell — it still has {$check['total']} active prisoner(s) inside. Release or reassign them first.";
    } else {
        $row = mysqli_fetch_assoc(mysqli_query($connection, "SELECT cell_number FROM cells WHERE id = $id"));
        $stmt = mysqli_prepare($connection, "DELETE FROM cells WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        log_action($connection, $username, "Deleted cell: " . $row['cell_number']);
        header("Location: cells.php"); exit();
    }
}

// ── HANDLE: Add ───────────────────────────────────────────────────────────────
$success_message = '';
if (!isset($error_message)) $error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_cell'])) {
    $cell_number = trim($_POST['cell_number']);
    $capacity    = intval($_POST['capacity']);
    $block       = trim($_POST['block']);

    if ($cell_number && $capacity > 0 && $block) {
        $stmt = mysqli_prepare($connection,
            "INSERT INTO cells (cell_number, capacity, block) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "sis", $cell_number, $capacity, $block);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        log_action($connection, $username, "Added cell: $cell_number ($block)");
        $success_message = "Cell added successfully!";
    } else {
        $error_message = "Please fill in all fields correctly.";
    }
}

// ── HANDLE: Edit ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_cell']) && $role === 'admin') {
    $id          = intval($_POST['edit_id']);
    $cell_number = trim($_POST['cell_number']);
    $capacity    = intval($_POST['capacity']);
    $block       = trim($_POST['block']);

    if ($cell_number && $capacity > 0 && $block) {
        $stmt = mysqli_prepare($connection,
            "UPDATE cells SET cell_number=?, capacity=?, block=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, "sisi", $cell_number, $capacity, $block, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        log_action($connection, $username, "Edited cell: $cell_number");
        $success_message = "Cell updated successfully!";
    } else {
        $error_message = "Please fill in all fields correctly.";
    }
}

// ── SEARCH ────────────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$where  = "WHERE 1=1";
if ($search !== '') {
    $safe  = mysqli_real_escape_string($connection, $search);
    $where .= " AND (c.cell_number LIKE '%$safe%' OR c.block LIKE '%$safe%')";
}

// ── PAGINATION ────────────────────────────────────────────────────────────────
$pager = paginate($connection,
    "SELECT COUNT(*) AS total FROM cells c $where", 15);

// ── LOAD DATA ─────────────────────────────────────────────────────────────────
$cells_result = mysqli_query($connection,
    "SELECT c.*, COUNT(p.id) AS prisoner_count
     FROM cells c
     LEFT JOIN prisoners p ON c.id = p.cell_id AND p.status = 'active'
     $where
     GROUP BY c.id
     ORDER BY c.cell_number ASC
     LIMIT {$pager['per_page']} OFFSET {$pager['offset']}");

$edit_cell = null;
if (isset($_GET['edit_id']) && $role === 'admin') {
    $eid = intval($_GET['edit_id']);
    $edit_cell = mysqli_fetch_assoc(mysqli_query($connection, "SELECT * FROM cells WHERE id = $eid"));
}

$show_form = isset($_GET['action']) && $_GET['action'] === 'add';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <title>Cells – Maula Prison</title>
</head>
<body class="dashboard-body">

<?php $active_page = 'cells'; require_once 'nav.php'; ?>

<div class="dashboard-layout">
<main class="main-content">
        <h1> Cells</h1>
        <p class="page-subtitle">View and manage all prison cells at Maula Prison.</p>

        <?php if ($success_message): ?><div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div><?php endif; ?>
        <?php if ($error_message): ?><div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div><?php endif; ?>

        <!-- SEARCH BAR -->
        <form method="GET" action="cells.php" class="search-bar">
            <input type="text" name="search" placeholder="🔍 Search by cell number or block..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="submit-btn">Search</button>
            <?php if ($search): ?>
                <a href="cells.php" class="action-btn" style="background:#888;">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($role === 'admin'): ?>
        <div style="margin-bottom:20px;">
            <a href="cells.php?action=<?= $show_form ? '' : 'add' ?>" class="action-btn">
                <?= $show_form ? '✖ Cancel' : ' Add Cell' ?>
            </a>
        </div>
        <?php endif; ?>

        <!-- ADD FORM -->
        <?php if ($show_form && $role === 'admin'): ?>
        <div class="form-card">
            <h2>Add New Cell</h2>
            <form action="cells.php?action=add" method="post">
                <div class="form-row">
                    <div class="form-group">
                        <label>Cell Number</label>
                        <input type="text" name="cell_number" placeholder="e.g. A-101" required>
                    </div>
                    <div class="form-group">
                        <label>Block / Wing</label>
                        <input type="text" name="block" placeholder="e.g. Block A" required>
                    </div>
                </div>
                <div class="form-group" style="max-width:200px;">
                    <label>Capacity</label>
                    <input type="number" name="capacity" min="1" placeholder="e.g. 4" required>
                </div>
                <button type="submit" name="add_cell" class="submit-btn">Save Cell</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- EDIT FORM -->
        <?php if ($edit_cell && $role === 'admin'): ?>
        <div class="form-card" style="border-left:4px solid #f0a500;">
            <h2>✏️ Edit Cell</h2>
            <form action="cells.php" method="post">
                <input type="hidden" name="edit_id" value="<?= $edit_cell['id'] ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label>Cell Number</label>
                        <input type="text" name="cell_number" value="<?= htmlspecialchars($edit_cell['cell_number']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Block / Wing</label>
                        <input type="text" name="block" value="<?= htmlspecialchars($edit_cell['block']) ?>" required>
                    </div>
                </div>
                <div class="form-group" style="max-width:200px;">
                    <label>Capacity</label>
                    <input type="number" name="capacity" min="1" value="<?= $edit_cell['capacity'] ?>" required>
                </div>
                <button type="submit" name="edit_cell" class="submit-btn">Update Cell</button>
                <a href="cells.php" style="margin-left:12px;color:#888;font-size:0.9rem;">Cancel</a>
            </form>
        </div>
        <?php endif; ?>

        <!-- TABLE -->
        <div class="table-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <span style="font-size:0.85rem;color:#888;">
                    Showing <?= mysqli_num_rows($cells_result) ?> of <?= $pager['total'] ?> cells
                </span>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th><th>Cell Number</th><th>Block</th><th>Capacity</th>
                        <th>Occupants</th><th>Status</th>
                        <?php if ($role === 'admin'): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($cells_result) === 0): ?>
                        <tr><td colspan="7" style="text-align:center;color:#888;">No cells found.</td></tr>
                    <?php else: ?>
                        <?php while ($cell = mysqli_fetch_assoc($cells_result)): ?>
                        <?php
                            $occupants    = $cell['prisoner_count'];
                            $cap          = $cell['capacity'];
                            $is_full      = $occupants >= $cap;
                            $status_label = $is_full ? 'Full' : 'Available';
                            $status_class = $is_full ? 'badge-full' : 'badge-available';
                        ?>
                        <tr>
                            <td><?= $cell['id'] ?></td>
                            <td><?= htmlspecialchars($cell['cell_number']) ?></td>
                            <td><?= htmlspecialchars($cell['block']) ?></td>
                            <td><?= $cap ?></td>
                            <td><?= $occupants ?> / <?= $cap ?></td>
                            <td><span class="badge <?= $status_class ?>"><?= $status_label ?></span></td>
                            <?php if ($role === 'admin'): ?>
                            <td class="actions-cell">
                                <a href="cells.php?edit_id=<?= $cell['id'] ?>" class="edit-btn">✏️ Edit</a>
                                <a href="cells.php?delete_id=<?= $cell['id'] ?>" class="delete-btn"
                                   onclick="return confirm('Delete cell <?= htmlspecialchars($cell['cell_number']) ?>?')">🗑 Delete</a>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php render_pagination($pager, 'cells.php', ['search' => $search]); ?>
        </div>
    </main>
</div>
</body>
</html>
<?php mysqli_close($connection); ?>
