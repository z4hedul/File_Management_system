<?php
// ============================================================
// 1. START OUTPUT BUFFERING
// ============================================================
ob_start();

// ============================================================
// 2. INCLUDE DATABASE CONNECTION
// ============================================================
require_once '../db.php';

// ============================================================
// 3. SET HEADERS FOR API
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// ============================================================
// 4. FUNCTION TO SEND JSON RESPONSE
// ============================================================
function sendResponse($data, $status = 200) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ============================================================
// 5. HANDLE POST REQUEST
// ============================================================
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch($method) {
        case 'POST':
            $raw_data = file_get_contents('php://input');
            $data = json_decode($raw_data, true);
            
            if (!$data) {
                $data = $_POST;
            }
            
            if (empty($data['file_id']) || empty($data['client_id'])) {
                sendResponse(['success' => false, 'error' => 'File ID and Client ID are required'], 400);
            }
            
            $file_id = (int)$data['file_id'];
            $client_id = (int)$data['client_id'];
            
            // Check if file exists and get current client name
            $check_stmt = $conn->prepare("SELECT id, client_id, client FROM office_files WHERE id = ? AND is_deleted = 0");
            $check_stmt->bind_param("i", $file_id);
            $check_stmt->execute();
            $file_result = $check_stmt->get_result();
            
            if ($file_result->num_rows === 0) {
                sendResponse(['success' => false, 'error' => 'File not found'], 404);
            }
            
            $file_data = $file_result->fetch_assoc();
            
            // Check if file is already assigned
            if (!empty($file_data['client_id'])) {
                sendResponse(['success' => false, 'error' => 'This file is already assigned to a client'], 400);
            }
            
            // Check if client exists
            $client_stmt = $conn->prepare("SELECT client_name FROM client_profiles WHERE id = ?");
            $client_stmt->bind_param("i", $client_id);
            $client_stmt->execute();
            $client_result = $client_stmt->get_result();
            
            if ($client_result->num_rows === 0) {
                sendResponse(['success' => false, 'error' => 'Client not found'], 404);
            }
            
            // IMPORTANT: Keep the original client name, only update client_id
            // DO NOT update the 'client' column - keep the original file name
            $update_stmt = $conn->prepare("UPDATE office_files SET client_id = ? WHERE id = ?");
            $update_stmt->bind_param("ii", $client_id, $file_id);
            
            if ($update_stmt->execute()) {
                sendResponse(['success' => true, 'message' => 'File assigned successfully', 'file_name' => $file_data['client']]);
            } else {
                sendResponse(['success' => false, 'error' => 'Database error: ' . $update_stmt->error], 500);
            }
            
            break;
            
        default:
            sendResponse(['success' => false, 'error' => 'Method not allowed. Use POST.'], 405);
    }
} catch(Exception $e) {
    error_log("Assign File API Error: " . $e->getMessage());
    sendResponse(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
}

ob_end_flush();
?>