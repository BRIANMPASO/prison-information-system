<?php 
session_start();

$connection = mysqli_connect('localhost', 'root', '', 'prison_db');
if (!$connection) {
    die("Database connection failed: " . mysqli_connect_error());
}

$password = $_POST['password'];
$username = $_POST['username'];

// FIXED: Use prepared statement to prevent SQL injection

$query = "SELECT * FROM users WHERE username = ?";
$stmt = mysqli_prepare($connection, $query);
mysqli_stmt_bind_param($stmt, "s", $username);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);
    if (password_verify($password, $row['password'])) {
        $_SESSION['user_id']  = $row['id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['role']     = $row['role'];
        header("Location: dashboard.php");
        exit();
    } else {
        $_SESSION['flash_error'] = 'Invalid username or password.';
        header("Location: index.php");
        exit();
    }
} else {
    $_SESSION['flash_error'] = 'Invalid username or password.';
    header("Location: index.php");
    exit();
}

mysqli_stmt_close($stmt);
mysqli_close($connection);
?>