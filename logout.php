<?php
session_start();
session_destroy(); // Wipes all session data
header("Location: index.php");
exit();
?>