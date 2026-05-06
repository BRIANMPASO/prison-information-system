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
    $row = mysqli_fetch_assoc(mysqli_query($connection, "SELECT full_name FROM staff WHERE id = $id"));
    $stmt = mysqli_prepare($connection, "DELETE FROM staff WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    log_action($connection, $username, "Deleted staff member: " . $row['full_name']);
    header("Location: staff.php"); exit();
}

// ── HANDLE: Add ───────────────────────────────────────────────────────────────
$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_staff'])) {
    $full_name   = trim($_POST['full_name']);
    $position    = trim($_POST['position']);
    $shift       = trim($_POST['shift']);
    $phone       = trim($_POST['phone']);
    $date_joined = $_POST['date_joined'];

    if ($full_name && $position && $shift && $phone && $date_joined) {
        $stmt = mysqli_prepare($connection,
            "INSERT INTO staff (full_name, position, shift, phone, date_joined) VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "sssss", $full_name, $position, $shift, $phone, $date_joined);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        log_action($connection, $username, "Added staff member: $full_name ($position)");
        $success_message = "Staff member added successfully!";
    } else {
        $error_message = "Please fill in all fields correctly.";
    }
}

// ── HANDLE: Edit ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_staff']) && $role === 'admin') {
    $id          = intval($_POST['edit_id']);
    $full_name   = trim($_POST['full_name']);
    $position    = trim($_POST['position']);
    $shift       = trim($_POST['shift']);
    $phone       = trim($_POST['phone']);
    $date_joined = $_POST['date_joined'];

    if ($full_name && $position && $shift && $phone && $date_joined) {
        $stmt = mysqli_prepare($connection,
            "UPDATE staff SET full_name=?, position=?, shift=?, phone=?, date_joined=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, "sssssi", $full_name, $position, $shift, $phone, $date_joined, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        log_action($connection, $username, "Edited staff member: $full_name");
        $success_message = "Staff member updated successfully!";
    } else {
        $error_message = "Please fill in all fields correctly.";
    }
}

// ── SEARCH ────────────────────────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$shift_filter = $_GET['shift'] ?? '';
$where        = "WHERE 1=1";

if ($search !== '') {
    $safe  = mysqli_real_escape_string($connection, $search);
    $where .= " AND (full_name LIKE '%$safe%' OR position LIKE '%$safe%' OR phone LIKE '%$safe%')";
}
if ($shift_filter !== '') {
    $safe_shift = mysqli_real_escape_string($connection, $shift_filter);
    $where     .= " AND shift = '$safe_shift'";
}

// ── PAGINATION ────────────────────────────────────────────────────────────────
$pager = paginate($connection, "SELECT COUNT(*) AS total FROM staff $where", 15);

// ── LOAD DATA ─────────────────────────────────────────────────────────────────
$staff_result = mysqli_query($connection,
    "SELECT * FROM staff $where ORDER BY id DESC
     LIMIT {$pager['per_page']} OFFSET {$pager['offset']}");

$edit_member = null;
if (isset($_GET['edit_id']) && $role === 'admin') {
    $eid = intval($_GET['edit_id']);
    $edit_member = mysqli_fetch_assoc(mysqli_query($connection, "SELECT * FROM staff WHERE id = $eid"));
}

$show_form = isset($_GET['action']) && $_GET['action'] === 'add';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <title>Staff – Maula Prison</title>
</head>
<body class="dashboard-body">

<?php $active_page = 'staff'; require_once 'nav.php'; ?>

<div class="dashboard-layout">
<main class="main-content">
        <h1> Staff</h1>
        <p class="page-subtitle">Manage all staff members at Maula Prison.</p>

        <?php if ($success_message): ?><div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div><?php endif; ?>
        <?php if ($error_message): ?><div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div><?php endif; ?>

        <!-- SEARCH BAR -->
        <form method="GET" action="staff.php" class="search-bar">
            <input type="text" name="search" placeholder="🔍 Search by name or position..." value="<?= htmlspecialchars($search) ?>">
            <select name="shift">
                <option value="">All Shifts</option>
                <option value="Morning"   <?= $shift_filter === 'Morning'   ? 'selected' : '' ?>>Morning</option>
                <option value="Afternoon" <?= $shift_filter === 'Afternoon' ? 'selected' : '' ?>>Afternoon</option>
                <option value="Night"     <?= $shift_filter === 'Night'     ? 'selected' : '' ?>>Night</option>
            </select>
            <button type="submit" class="submit-btn">Search</button>
            <?php if ($search || $shift_filter): ?>
                <a href="staff.php" class="action-btn" style="background:#888;">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($role === 'admin'): ?>
        <div style="margin-bottom:20px;">
            <a href="staff.php?action=<?= $show_form ? '' : 'add' ?>" class="action-btn">
                <?= $show_form ? '✖ Cancel' : ' Add Staff Member' ?>
            </a>
        </div>
        <?php endif; ?>

        <!-- ADD FORM -->
        <?php if ($show_form && $role === 'admin'): ?>
        <div class="form-card">
            <h2>Add New Staff Member</h2>
            <form action="staff.php?action=add" method="post">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" placeholder="e.g. James Banda" required>
                    </div>
                    <div class="form-group">
                        <label>Position</label>
                        <select name="position" required>
                            <option value="">-- Select --</option>
                            <option>Warden</option><option>Guard</option><option>Nurse</option>
                            <option>Counselor</option><option>Administrator</option><option>Cook</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Shift</label>
                        <select name="shift" required>
                            <option value="">-- Select --</option>
                            <option value="Morning">Morning (6am – 2pm)</option>
                            <option value="Afternoon">Afternoon (2pm – 10pm)</option>
                            <option value="Night">Night (10pm – 6am)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" placeholder="e.g. 0888123456" required>
                    </div>
                </div>
                <div class="form-group" style="max-width:260px;">
                    <label>Date Joined</label>
                    <input type="date" name="date_joined" required>
                </div>
                <button type="submit" name="add_staff" class="submit-btn">Save Staff Member</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- EDIT FORM -->
        <?php if ($edit_member && $role === 'admin'): ?>
        <div class="form-card" style="border-left:4px solid #f0a500;">
            <h2>✏️ Edit Staff Member</h2>
            <form action="staff.php" method="post">
                <input type="hidden" name="edit_id" value="<?= $edit_member['id'] ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" value="<?= htmlspecialchars($edit_member['full_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Position</label>
                        <select name="position" required>
                            <?php foreach (['Warden','Guard','Nurse','Counselor','Administrator','Cook'] as $pos): ?>
                                <option <?= $edit_member['position'] === $pos ? 'selected' : '' ?>><?= $pos ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Shift</label>
                        <select name="shift" required>
                            <?php foreach (['Morning','Afternoon','Night'] as $s): ?>
                                <option value="<?= $s ?>" <?= $edit_member['shift'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($edit_member['phone']) ?>" required>
                    </div>
                </div>
                <div class="form-group" style="max-width:260px;">
                    <label>Date Joined</label>
                    <input type="date" name="date_joined" value="<?= $edit_member['date_joined'] ?>" required>
                </div>
                <button type="submit" name="edit_staff" class="submit-btn">Update Staff Member</button>
                <a href="staff.php" style="margin-left:12px;color:#888;font-size:0.9rem;">Cancel</a>
            </form>
        </div>
        <?php endif; ?>

        <!-- TABLE -->
        <div class="table-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <span style="font-size:0.85rem;color:#888;">
                    Showing <?= mysqli_num_rows($staff_result) ?> of <?= $pager['total'] ?> records
                </span>
                <a href="export.php?table=staff&search=<?= urlencode($search) ?>&shift=<?= urlencode($shift_filter) ?>"
                   class="action-btn" style="font-size:0.8rem;padding:6px 14px;">⬇ Export CSV</a>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th><th>Full Name</th><th>Position</th><th>Shift</th>
                        <th>Phone</th><th>Date Joined</th>
                        <?php if ($role === 'admin'): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($staff_result) === 0): ?>
                        <tr><td colspan="7" style="text-align:center;color:#888;">No staff found.</td></tr>
                    <?php else: ?>
                        <?php while ($m = mysqli_fetch_assoc($staff_result)): ?>
                        <tr>
                            <td><?= $m['id'] ?></td>
                            <td><?= htmlspecialchars($m['full_name']) ?></td>
                            <td><?= htmlspecialchars($m['position']) ?></td>
                            <td>
                                <span class="badge <?= $m['shift'] === 'Night' ? 'badge-full' : 'badge-available' ?>">
                                    <?= htmlspecialchars($m['shift']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($m['phone']) ?></td>
                            <td><?= htmlspecialchars($m['date_joined']) ?></td>
                            <?php if ($role === 'admin'): ?>
                            <td class="actions-cell">
                                <a href="staff.php?edit_id=<?= $m['id'] ?>" class="edit-btn">✏️ Edit</a>
                                <a href="staff.php?delete_id=<?= $m['id'] ?>" class="delete-btn"
                                   onclick="return confirm('Delete <?= htmlspecialchars($m['full_name']) ?>?')">🗑 Delete</a>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php render_pagination($pager, 'staff.php', ['search' => $search, 'shift' => $shift_filter]); ?>
        </div>
    </main>
</div>
</body>
</html>
<?php mysqli_close($connection); ?>
