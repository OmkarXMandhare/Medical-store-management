<?php
session_start();

// Redirect users based on their role
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {

    if ($_SESSION['role'] === 'owner') {
        header('Location: owner/dashboard.php');
        exit;
    }

    if ($_SESSION['role'] === 'employee') {
        header('Location: employee/dashboard.php');
        exit;
    }
}

// Redirect unauthenticated users to login
header('Location: login.php');
exit;
?>
