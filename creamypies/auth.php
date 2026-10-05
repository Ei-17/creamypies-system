<?php
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_ID']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function getUsername() {
    return isset($_SESSION['user_Username']) ? $_SESSION['user_Username'] : '';
}
?>
