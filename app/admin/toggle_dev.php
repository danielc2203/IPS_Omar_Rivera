<?php
// public_html/admin/toggle_dev.php
require_once '../config.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: dashboard.php');
    exit();
}

// Toggle Session var
if (!isset($_SESSION['dev_mode'])) {
    $_SESSION['dev_mode'] = true;
} else {
    $_SESSION['dev_mode'] = !$_SESSION['dev_mode'];
}

header('Location: dashboard.php');
exit();
