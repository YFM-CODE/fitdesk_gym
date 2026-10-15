<?php
session_start();

if (isset($_SESSION['user_id']) || isset($_SESSION['user']['id']) || isset($_SESSION['username'])) {
    header('Location: modules/dashboard/index.php');
    exit;
}

header('Location: modules/auth/login.php');
exit;
