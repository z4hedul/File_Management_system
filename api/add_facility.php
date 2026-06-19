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
            // Get data from POST (for file uploads)
            $client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
            $facility_type = isset($_POST['facility_type']) ? $_POST['facility_type'] : '';
            $facility_group = isset($_POST['facility_group']) ? $_POST['facility_group'] : 'General';
            $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
            $security_type = isset($_POST['security_type']) && $_POST['security_type'] !== '' ? $_POST['security_type'] : null;
            $security_value = isset($_POST['security_value']) && $_POST['security_value'] !== '' ? (float)$_POST['security_value'] : null;
            $security_description = isset($_POST['security_description']) && $_POST['security_description'] !== '' ? $_POST['security_description'] : null;
            $sanction_date = isset($_POST['sanction_date']) && $_POST['sanction_date'] !== '' ? $_POST['sanction_date'] : null;
            $sanction_letter_ref_no = isset($_POST['sanction_letter_ref_no']) && $_POST['sanction_letter_ref_no'] !== '' ? $_POST['sanction_letter_ref_no'] : null;
            $comm_meet_no = isset($_POST['comm_meet_no']) && $_POST['comm_meet_no'] !== '' ? $_POST['comm_meet_no'] : null;
            $comm_meet_date = isset($_POST['comm_meet_date']) && $_POST['comm_meet_date'] !== '' ? $_POST['comm_meet_date'] : null;
            $board_meet_no = isset($_POST['board_meet_no']) && $_POST['board_meet_no'] !== '' ? $_POST['board_meet_no'] : null;
            $board_meet_date = isset($_POST['board_meet_date']) && $_POST['board_meet_date'] !== '' ? $_POST['board_meet_date'] : null;
            $facility_as = isset($_POST['facility_as']) && $_POST['facility_as'] !== '' ? $_POST['facility_as'] : null;
            $power_delegation = isset($_POST['power_delegation']) && $_POST['power_delegation'] !== '' ? $_POST['power_delegation'] : null;
            
            // Validation
            if (empty($client_id)) {
                sendResponse(['success' => false, 'error' => 'Client ID is required'], 400);
            }
            
            if (empty($facility_type)) {
                sendResponse(['success' => false, 'error' => 'Facility type is required'], 400);
            }
            
            if ($amount <= 0) {
                sendResponse(['success' => false, 'error' => 'Valid amount is required'], 400);
            }
            
            $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
            
            // ============================================================
            // GET OR CREATE FILE RECORD FOR THIS CLIENT
            // ============================================================
            
            $client_stmt = $conn->prepare("SELECT client_name, branch_id, division, zone FROM client_profiles WHERE id = ?");
            $client_stmt->bind_param("i", $client_id);
            $client_stmt->execute();
            $client_result = $client_stmt->get_result();
            $client_data = $client_result->fetch_assoc();
            $client_stmt->close();
            
            if (!$client_data) {
                sendResponse(['success' => false, 'error' => 'Client not found'], 404);
            }
            
            $file_stmt = $conn->prepare("SELECT id FROM office_files WHERE client_id = ? AND is_deleted = 0 ORDER BY id DESC LIMIT 1");
            $file_stmt->bind_param("i", $client_id);
            $file_stmt->execute();
            $file_result = $file_stmt->get_result();
            $file_record = $file_result->fetch_assoc();
            $file_stmt->close();
            
            if (!$file_record) {
                $branch_name = '';
                $branch_code = '';
                if (!empty($client_data['branch_id'])) {
                    $branch_stmt = $conn->prepare("SELECT branch_name, branch_code FROM branches WHERE id = ?");
                    $branch_stmt->bind_param("i", $client_data['branch_id']);
                    $branch_stmt->execute();
                    $branch_result = $branch_stmt->get_result();
                    if ($branch_row = $branch_result->fetch_assoc()) {
                        $branch_name = $branch_row['branch_name'];
                        $branch_code = $branch_row['branch_code'];
                    }
                    $branch_stmt->close();
                }
                
                $file_no_stmt = $conn->prepare("SELECT COUNT(*) as total FROM office_files WHERE client_id = ? AND is_deleted = 0");
                $file_no_stmt->bind_param("i", $client_id);
                $file_no_stmt->execute();
                $file_no_result = $file_no_stmt->get_result();
                $file_no_count = $file_no_result->fetch_assoc()['total'] ?? 0;
                $file_no_stmt->close();
                
                $next_file_no = str_pad($file_no_count + 1, 3, '0', STR_PAD_LEFT);
                $file_name = $client_data['client_name'] . ' - ' . $next_file_no;
                
                $insert_file = $conn->prepare("INSERT INTO office_files 
                    (client_id, client, branch_name, branch_code, division, zone, file_no, cabinet_name, shelf_name) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $cabinet_name = 'Auto';
                $shelf_name = 'Auto';
                $insert_file->bind_param("issssssss", 
                    $client_id,
                    $file_name,
                    $branch_name,
                    $branch_code,
                    $client_data['division'],
                    $client_data['zone'],
                    $next_file_no,
                    $cabinet_name,
                    $shelf_name
                );
                
                if (!$insert_file->execute()) {
                    sendResponse(['success' => false, 'error' => 'Failed to create file record: ' . $insert_file->error], 500);
                }
                
                $file_record_id = $conn->insert_id;
                $insert_file->close();
            } else {
                $file_record_id = $file_record['id'];
            }
            
            // ============================================================
            // INSERT FACILITY
            // ============================================================
            
            $query = "INSERT INTO file_facilities 
                      (file_record_id, user_id, facility_type, facility_group, amount, 
                       sanction_date, sanction_letter_ref_no, comm_meet_no, comm_meet_date, 
                       board_meet_no, board_meet_date, facility_as, power_delegation) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("iisssssssssss", 
                $file_record_id,
                $user_id,
                $facility_type,
                $facility_group,
                $amount,
                $sanction_date,
                $sanction_letter_ref_no,
                $comm_meet_no,
                $comm_meet_date,
                $board_meet_no,
                $board_meet_date,
                $facility_as,
                $power_delegation
            );
            
            if (!$stmt->execute()) {
                sendResponse(['success' => false, 'error' => 'Failed to insert facility: ' . $stmt->error], 500);
            }
            
            $facility_id = $conn->insert_id;
            $stmt->close();
            
            // ============================================================
            // HANDLE SECURITY
            // ============================================================
            
            if (!empty($security_type) && $security_value !== null) {
                $check_security = $conn->prepare("SELECT id FROM facility_securities WHERE facility_id = ?");
                $check_security->bind_param("i", $facility_id);
                $check_security->execute();
                $check_result = $check_security->get_result();
                $security_exists = $check_result->num_rows > 0;
                $check_security->close();
                
                if ($security_exists) {
                    $sec_query = "UPDATE facility_securities SET 
                                  security_type = ?,
                                  security_value = ?,
                                  security_description = ?
                                  WHERE facility_id = ?";
                    $sec_stmt = $conn->prepare($sec_query);
                    $sec_stmt->bind_param("sdsi", 
                        $security_type,
                        $security_value,
                        $security_description,
                        $facility_id
                    );
                } else {
                    $sec_query = "INSERT INTO facility_securities 
                                  (facility_id, security_type, security_value, security_description) 
                                  VALUES (?, ?, ?, ?)";
                    $sec_stmt = $conn->prepare($sec_query);
                    $sec_stmt->bind_param("isds", 
                        $facility_id,
                        $security_type,
                        $security_value,
                        $security_description
                    );
                }
                
                if (!$sec_stmt->execute()) {
                    sendResponse(['success' => false, 'error' => 'Failed to save security: ' . $sec_stmt->error], 500);
                }
                $sec_stmt->close();
            }
            
            // ============================================================
            // HANDLE FILE ATTACHMENTS
            // ============================================================
            
            $upload_dir = "uploads/";
            if (!is_dir($upload_dir)) { 
                mkdir($upload_dir, 0777, true); 
            }
            
            $uploaded_count = 0;
            if (isset($_FILES['doc_file'])) {
                $doc_types = isset($_POST['doc_type']) ? $_POST['doc_type'] : [];
                $doc_descriptions = isset($_POST['doc_description']) ? $_POST['doc_description'] : [];
                $doc_files = $_FILES['doc_file'];
                
                foreach ($doc_files['name'] as $index => $name) {
                    if (!empty($name) && $doc_files['error'][$index] === UPLOAD_ERR_OK) {
                        $ext = pathinfo($name, PATHINFO_EXTENSION);
                        $doc_type = isset($doc_types[$index]) ? $doc_types[$index] : 'Other';
                        $doc_desc = isset($doc_descriptions[$index]) && !empty($doc_descriptions[$index]) 
                                    ? $doc_descriptions[$index] 
                                    : $doc_type;
                        
                        $client_name_clean = preg_replace("/[^a-zA-Z0-9_-]/", "_", $client_data['client_name']);
                        $raw_filename = $client_name_clean . '-' . $doc_type . '-' . date('Y-m-d');
                        $clean_filename = preg_replace("/[^a-zA-Z0-9_-]/", "_", $raw_filename);
                        $final_name = time() . '_' . $index . '_' . $clean_filename . '.' . $ext;
                        $target_filepath = $upload_dir . $final_name;
                        
                        if (move_uploaded_file($doc_files['tmp_name'][$index], $target_filepath)) {
                            $attach_sql = "INSERT INTO attachments (file_record_id, sanction_date, file_path, description) VALUES (?, ?, ?, ?)";
                            $attach_stmt = $conn->prepare($attach_sql);
                            $attach_stmt->bind_param("isss", $file_record_id, $sanction_date, $target_filepath, $doc_desc);
                            $attach_stmt->execute();
                            $attach_stmt->close();
                            $uploaded_count++;
                        }
                    }
                }
            }
            
            sendResponse([
                'success' => true, 
                'message' => 'Facility added successfully with ' . $uploaded_count . ' attachments',
                'facility_id' => $facility_id,
                'file_record_id' => $file_record_id,
                'attachments_uploaded' => $uploaded_count
            ], 201);
            
            break;
            
        default:
            sendResponse(['success' => false, 'error' => 'Method not allowed. Use POST.'], 405);
    }
} catch(Exception $e) {
    error_log("Add Facility API Error: " . $e->getMessage());
    sendResponse(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
}

ob_end_flush();
?>