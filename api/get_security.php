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
header('Access-Control-Allow-Methods: POST, GET');
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
// 5. HANDLE REQUEST
// ============================================================
$method = $_SERVER['REQUEST_METHOD'];

try {
    // Handle both POST and GET
    if ($method === 'POST') {
        $raw_data = file_get_contents('php://input');
        $data = json_decode($raw_data, true);
        if (!$data) {
            $data = $_POST;
        }
    } else if ($method === 'GET') {
        $data = $_GET;
    } else {
        sendResponse(['success' => false, 'error' => 'Method not allowed. Use POST or GET.'], 405);
    }
    
    $client_id = isset($data['client_id']) ? (int)$data['client_id'] : 0;
    $facility_type = isset($data['facility_type']) ? trim($data['facility_type']) : '';
    
    if (empty($client_id)) {
        sendResponse(['success' => false, 'error' => 'Client ID is required'], 400);
    }
    
    if (empty($facility_type)) {
        sendResponse(['success' => false, 'error' => 'Facility type is required'], 400);
    }
    
    // Get file_record_id for this client
    $file_stmt = $conn->prepare("SELECT id FROM office_files WHERE client_id = ? AND is_deleted = 0 ORDER BY id DESC LIMIT 1");
    $file_stmt->bind_param("i", $client_id);
    $file_stmt->execute();
    $file_result = $file_stmt->get_result();
    $file_record = $file_result->fetch_assoc();
    $file_stmt->close();
    
    if (!$file_record) {
        sendResponse(['success' => true, 'data' => [], 'message' => 'No file record found for this client'], 200);
    }
    
    $file_record_id = $file_record['id'];
    
    // Get the latest facility with security for this facility type
    // REMOVED: ff.is_deleted = 0 (this column doesn't exist in file_facilities)
    $query = "SELECT 
                ff.id as facility_id,
                ff.facility_type,
                ff.amount,
                ff.sanction_date,
                ff.sanction_letter_ref_no,
                fs.security_type,
                fs.security_value,
                fs.security_description
              FROM file_facilities ff
              LEFT JOIN facility_securities fs ON ff.id = fs.facility_id
              WHERE ff.file_record_id = ? 
              AND ff.facility_type = ?
              ORDER BY ff.sanction_date DESC
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $file_record_id, $facility_type);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $security_data = [];
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['security_type'])) {
            $security_data[] = [
                'facility_id' => $row['facility_id'],
                'facility_type' => $row['facility_type'],
                'amount' => $row['amount'],
                'sanction_date' => $row['sanction_date'],
                'sanction_letter_ref_no' => $row['sanction_letter_ref_no'],
                'security_type' => $row['security_type'],
                'security_value' => $row['security_value'],
                'security_description' => $row['security_description']
            ];
        }
    }
    $stmt->close();
    
    sendResponse([
        'success' => true,
        'data' => $security_data,
        'message' => count($security_data) . ' security records found'
    ], 200);
    
} catch(Exception $e) {
    error_log("Get Security API Error: " . $e->getMessage());
    sendResponse([
        'success' => false, 
        'error' => 'Server error: ' . $e->getMessage(),
        'data' => []
    ], 500);
}

ob_end_flush();
?>