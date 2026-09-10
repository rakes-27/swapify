<?php
/**
 * Swapify Admin — Logout
 * Clears the admin session and redirects to login.
 */
session_start();

unset($_SESSION['admin_id']);
unset($_SESSION['admin_email']);
session_regenerate_id(true);

header('Location: login.php');
exit;