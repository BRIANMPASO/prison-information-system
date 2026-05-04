<?php
session_start();

$error_message = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <title>Login – Maula Prison</title>
</head>
<body class="login-body">

<div class="login-card">

    <img src="icon/icon.png" alt="Maula Prison Logo" class="login-logo"
         onerror="this.style.display='none'">

    <h1>Maula Prison System</h1>
    <p>Enter your credentials to access the system</p>

    <?php if ($error_message): ?>
        <div class="login-error" id="flash">
            <?= htmlspecialchars($error_message) ?>
        </div>
        <script>
            setTimeout(function() {
                var el = document.getElementById('flash');
                el.style.opacity = '0';
                setTimeout(function() { el.style.display = 'none'; }, 1000);
            }, 3000);
        </script>
    <?php endif; ?>

    <form action="login.php" method="post">
        <input type="text"     name="username" placeholder="Username" required autofocus>
        <input type="password" name="password" placeholder="Password" minlength="6" required>
        <button type="submit">Login</button>
    </form>

    <p class="login-footer">Maula Prison Management System &copy; <?= date('Y') ?></p>
</div>

</body>
</html>
