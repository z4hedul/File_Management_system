<?php
include 'db.php';
session_start();

if (!isset($_SESSION['loggedin']) || !isset($_GET['file_id']) || !isset($_GET['action'])) {
    header("Location: search.php");
    exit;
}

$file_id = intval($_GET['file_id']);
$action = trim($_GET['action']);
$current_user_username = $_SESSION['username'];

// Pull user context tracking information securely from active session parameters
$user_query = $conn->prepare("SELECT id, full_name FROM users WHERE username = ?");
$user_query->bind_param("s", $current_user_username);
$user_query->execute();
$user_res = $user_query->get_result()->fetch_assoc();
$user_query->close();

$user_id = $user_res['id'] ?? 0;
$user_full_name = $user_res['full_name'] ?? 'Unknown User';

if ($user_id === 0) {
    echo "<script>alert('Error: Active authenticated tracking session expired.'); window.location.href='search.php';</script>";
    exit;
}

if ($action === 'checkout') {
    // Grab action event state modification execution rule
    $stmt = $conn->prepare("UPDATE office_files SET is_checked_out = 1, checked_out_by_user_id = ?, updated_by = ? WHERE id = ?");
    $stmt->bind_param("isi", $user_id, $user_full_name, $file_id);
    $stmt->execute();
    $stmt->close();
} else if ($action === 'checkin') {
    // Return action event state release modification criteria execution rule
    $stmt = $conn->prepare("UPDATE office_files SET is_checked_out = 0, checked_out_by_user_id = NULL, updated_by = ? WHERE id = ?");
    $stmt->bind_param("si", $user_full_name, $file_id);
    $stmt->execute();
    $stmt->close();
}

header("Location: search.php");
exit;