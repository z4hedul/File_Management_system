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
header('Access-Control-Allow-Methods: DELETE');
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
// 5. HANDLE DELETE REQUEST
// ============================================================
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch($method) {
        case 'DELETE':
            if (!isset($_GET['id'])) {
                sendResponse(['success' => false, 'error' => 'File ID required'], 400);
            }
            
            $id = (int)$_GET['id'];
            
            // IMPORTANT: Only unlink from client (set client_id = NULL)
            // DO NOT change the 'client' column - keep the original file name
            $query = "UPDATE office_files SET client_id = NULL WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                sendResponse(['success' => true, 'message' => 'File unlinked from client successfully']);
            } else {
                sendResponse(['success' => false, 'error' => $stmt->error], 500);
            }
            break;
            
        default:
            sendResponse(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch(Exception $e) {
    sendResponse(['success' => false, 'error' => $e->getMessage()], 500);
}

ob_end_flush();
?>