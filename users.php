<?php
session_start();
require_once 'helpers.php';

if (!isset($_SESSION['username'])) { header("Location: index.php"); exit(); }
if ($_SESSION['role'] !== 'admin') { header("Location: dashboard.php"); exit(); }

$username = $_SESSION['username'];
$role     = $_SESSION['role'];

$connection = mysqli_connect('localhost', 'root', '', 'prison_db');
if (!$connection) die("Database connection failed: " . mysqli_connect_error());

// ── HANDLE: Delete ────────────────────────────────────────────────────────────
if (isset($_GET['delete_id'])) {
    $id = intval($_GET['delete_id']);

    // Don't allow deleting your own account
    if ($id == $_SESSION['user_id']) {
        $_SESSION['flash_error'] = "You cannot delete your own account.";
        header("Location: users.php"); exit();
    }

    $row = mysqli_fetch_assoc(mysqli_query($connection, "SELECT username FROM users WHERE id = $id"));
    $stmt = mysqli_prepare($connection, "DELETE FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    log_action($connection, $username, "Deleted user account: " . $row['username']);
    header("Location: users.php"); exit();
}

// ── HANDLE: Add ───────────────────────────────────────────────────────────────
$success_message = '';
$error_message   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $new_username = trim($_POST['new_username']);
    $new_password = $_POST['new_password'];
    $new_role     = $_POST['new_role'];

    if ($new_username && strlen($new_password) >= 6 && $new_role) {

        // Check the username is not already taken
        $check = mysqli_prepare($connection, "SELECT id FROM users WHERE username = ?");
        mysqli_stmt_bind_param($check, "s", $new_username);
        mysqli_stmt_execute($check);
        mysqli_stmt_store_result($check);

        if (mysqli_stmt_num_rows($check) > 0) {
            $error_message = "That username is already taken. Please choose another.";
        } else {
            // Hash the password before saving it — never save plain text passwords!
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);

            $stmt = mysqli_prepare($connection,
                "INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "sss", $new_username, $hashed, $new_role);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            log_action($connection, $username, "Created user account: $new_username ($new_role)");
            $success_message = "User '$new_username' created successfully!";
        }

        mysqli_stmt_close($check);

    } else {
        $error_message = "Please fill in all fields. Password must be at least 6 characters.";
    }
}

// ── SEARCH ────────────────────────────────────────────────────────────────────
$search      = trim($_GET['search'] ?? '');
$role_filter = $_GET['role_filter'] ?? '';
$where       = "WHERE 1=1";

if ($search !== '') {
    $safe  = mysqli_real_escape_string($connection, $search);
    $where .= " AND username LIKE '%$safe%'";
}
if ($role_filter !== '') {
    $safe_role = mysqli_real_escape_string($connection, $role_filter);
    $where    .= " AND role = '$safe_role'";
}

// ── PAGINATION ────────────────────────────────────────────────────────────────
$pager = paginate($connection, "SELECT COUNT(*) AS total FROM users $where", 15);

// ── LOAD DATA ─────────────────────────────────────────────────────────────────
$users_result = mysqli_query($connection,
    "SELECT id, username, role FROM users $where ORDER BY id ASC
     LIMIT {$pager['per_page']} OFFSET {$pager['offset']}");

$show_form = isset($_GET['action']) && $_GET['action'] === 'add';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <title>User Management – Maula Prison</title>
</head>
<body class="dashboard-body">

<?php $active_page = 'users'; require_once 'nav.php'; ?>

<div class="dashboard-layout">
<main class="main-content">
        <h1>⚙️ User Management</h1>
        <p class="page-subtitle">Create and manage system login accounts for Maula Prison.</p>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <!-- SEARCH BAR -->
        <form method="GET" action="users.php" class="search-bar">
            <input type="text" name="search" placeholder="🔍 Search by username..."
                   value="<?= htmlspecialchars($search) ?>">
            <select name="role_filter">
                <option value="">All Roles</option>
                <option value="admin" <?= $role_filter === 'admin' ? 'selected' : '' ?>>Admin</option>
                <option value="staff" <?= $role_filter === 'staff' ? 'selected' : '' ?>>Staff</option>
            </select>
            <button type="submit" class="submit-btn">Search</button>
            <?php if ($search || $role_filter): ?>
                <a href="users.php" class="action-btn" style="background:#888;">Clear</a>
            <?php endif; ?>
        </form>

        <!-- ADD BUTTON -->
        <div style="margin-bottom:20px;">
            <a href="users.php?action=<?= $show_form ? '' : 'add' ?>" class="action-btn">
                <?= $show_form ? '✖ Cancel' : '➕ Add New User' ?>
            </a>
        </div>

        <!-- ADD FORM -->
        <?php if ($show_form): ?>
        <div class="form-card">
            <h2>Create New User</h2>
            <form action="users.php?action=add" method="post">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="new_username" placeholder="e.g. officer_james" required>
                </div>
                <div class="form-group">
                    <label>Password (minimum 6 characters)</label>
                    <input type="password" name="new_password" minlength="6" placeholder="Enter password" required>
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="new_role" required>
                        <option value="">-- Select Role --</option>
                        <option value="admin">Admin — full access to everything</option>
                        <option value="staff">Staff — can manage prisoners, cells, staff, visitors</option>
                        <option value="receptionist">Receptionist — can log visits and submit prisoners for approval</option>
                    </select>
                </div>
                <button type="submit" name="add_user" class="submit-btn">Create User</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- USERS TABLE -->
        <div class="table-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <span style="font-size:0.85rem;color:#888;">
                    Showing <?= mysqli_num_rows($users_result) ?> of <?= $pager['total'] ?> users
                </span>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($users_result) === 0): ?>
                        <tr><td colspan="4" style="text-align:center;color:#888;">No users found.</td></tr>
                    <?php else: ?>
                        <?php while ($user = mysqli_fetch_assoc($users_result)): ?>
                        <tr>
                            <td><?= $user['id'] ?></td>
                            <td>
                                <?= htmlspecialchars($user['username']) ?>
                                <!-- Mark the currently logged-in user -->
                                <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                    <span style="font-size:0.75rem;color:#888;">(you)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $user['role'] === 'admin' ? 'badge-full' : 'badge-available' ?>">
                                    <?= ucfirst(htmlspecialchars($user['role'])) ?>
                                </span>
                            </td>
                            <td>
                                <!-- Cannot delete your own account -->
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <a href="users.php?delete_id=<?= $user['id'] ?>"
                                       class="delete-btn"
                                       onclick="return confirm('Delete user <?= htmlspecialchars($user['username']) ?>?')">
                                       🗑 Delete
                                    </a>
                                <?php else: ?>
                                    <span style="color:#aaa;font-size:0.85rem;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php render_pagination($pager, 'users.php', ['search' => $search, 'role_filter' => $role_filter]); ?>
        </div>

    </main>
</div>
</body>
</html>
<?php mysqli_close($connection); ?>
