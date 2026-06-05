<?php
session_start();

// 1. Security boundary check: Only allow logged-in administrators to run a manual backup
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    die("Access Denied: Only administrators can trigger manual database snapshots.");
}

// 2. Redirect to the main engine file while safely passing the secret authorization token
// Make sure this matches the token you defined in your cron_backup.php file
$secret_token = "MySuperSecureToken123!"; 

header("Location: cron_backup.php?token=" . urlencode($secret_token));
exit;