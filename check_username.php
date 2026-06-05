<?php
session_start();
include 'db.php';

// Security validation check
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$username = isset($_GET['username']) ? trim($_GET['username']) : '';

$response = ['available' => true];

if ($username !== '') {
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        $response['available'] = false;
    }
    $stmt->close();
}

header('Content-Type: application/json');
echo json_encode($response);
exit;