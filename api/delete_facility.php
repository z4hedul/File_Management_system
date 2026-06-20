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
            
            $facility_id = isset($data['facility_id']) ? (int)$data['facility_id'] : 0;
            
            if (empty($facility_id)) {
                sendResponse(['success' => false, 'error' => 'Facility ID is required'], 400);
            }
            
            // Start transaction
            $conn->begin_transaction();
            
            // 1. Delete security records
            $sec_stmt = $conn->prepare("DELETE FROM facility_securities WHERE facility_id = ?");
            $sec_stmt->bind_param("i", $facility_id);
            $sec_stmt->execute();
            $sec_stmt->close();
            
            // 2. Delete attachments
            $att_stmt = $conn->prepare("DELETE FROM attachments WHERE facility_id = ?");
            $att_stmt->bind_param("i", $facility_id);
            $att_stmt->execute();
            $att_stmt->close();
            
            // 3. Delete facility
            $fac_stmt = $conn->prepare("DELETE FROM file_facilities WHERE id = ?");
            $fac_stmt->bind_param("i", $facility_id);
            $fac_stmt->execute();
            $fac_stmt->close();
            
            $conn->commit();
            
            sendResponse(['success' => true, 'message' => 'Facility deleted successfully'], 200);
            
            break;
            
        default:
            sendResponse(['success' => false, 'error' => 'Method not allowed. Use POST.'], 405);
    }
} catch(Exception $e) {
    $conn->rollback();
    error_log("Delete Facility API Error: " . $e->getMessage());
    sendResponse(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
}

ob_end_flush();
?>