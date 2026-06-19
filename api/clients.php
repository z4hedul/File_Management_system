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
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
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
// 5. GET REQUEST METHOD
// ============================================================
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch($method) {
        // ============================================================
        // GET - Fetch client(s)
        // ============================================================
        case 'GET':
            if (isset($_GET['id'])) {
                // Get single client with branch details
                $query = "SELECT 
                            cp.*,
                            b.branch_name,
                            b.branch_code,
                            b.zone as branch_zone
                         FROM client_profiles cp
                         LEFT JOIN branches b ON cp.branch_id = b.id
                         WHERE cp.id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $_GET['id']);
                $stmt->execute();
                $result = $stmt->get_result();
                $client = $result->fetch_assoc();
                
                if (!$client) {
                    sendResponse(['success' => false, 'error' => 'Client not found'], 404);
                }
                
                sendResponse(['success' => true, 'data' => $client]);
            } else {
                // Get all clients with branch details
                $query = "SELECT 
                            cp.*,
                            b.branch_name,
                            b.branch_code,
                            b.zone as branch_zone,
                            COUNT(of.id) as file_count
                         FROM client_profiles cp
                         LEFT JOIN branches b ON cp.branch_id = b.id
                         LEFT JOIN office_files of ON cp.id = of.client_id AND of.is_deleted = 0
                         GROUP BY cp.id
                         ORDER BY cp.client_name ASC";
                $result = $conn->query($query);
                if (!$result) {
                    sendResponse(['success' => false, 'error' => $conn->error], 500);
                }
                $clients = $result->fetch_all(MYSQLI_ASSOC);
                sendResponse(['success' => true, 'data' => $clients]);
            }
            break;
            
        // ============================================================
        // POST - Add new client
        // ============================================================
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Validate required fields
            if (empty($data['client_name'])) {
                sendResponse(['success' => false, 'error' => 'Client name is required'], 400);
            }
            
            // Generate client code if not provided
            if (empty($data['client_code'])) {
                $code_query = "SELECT client_code FROM client_profiles WHERE client_code LIKE 'C%' ORDER BY id DESC LIMIT 1";
                $code_result = $conn->query($code_query);
                if ($code_result && $code_result->num_rows > 0) {
                    $last_code = $code_result->fetch_assoc()['client_code'];
                    $num = intval(substr($last_code, 1));
                    $new_num = $num + 1;
                } else {
                    $new_num = 1;
                }
                $data['client_code'] = 'C' . str_pad($new_num, 3, '0', STR_PAD_LEFT);
            }
            
            // Prepare values with null handling
            $client_name = $data['client_name'];
            $client_code = $data['client_code'];
            $address = isset($data['address']) && $data['address'] !== '' ? $data['address'] : null;
            $city = isset($data['city']) && $data['city'] !== '' ? $data['city'] : null;
            $state = isset($data['state']) && $data['state'] !== '' ? $data['state'] : null;
            $zip_code = isset($data['zip_code']) && $data['zip_code'] !== '' ? $data['zip_code'] : null;
            $phone = isset($data['phone']) && $data['phone'] !== '' ? $data['phone'] : null;
            $email = isset($data['email']) && $data['email'] !== '' ? $data['email'] : null;
            $contact_person = isset($data['contact_person']) && $data['contact_person'] !== '' ? $data['contact_person'] : null;
            $branch_id = isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null ? (int)$data['branch_id'] : null;
            $division = isset($data['division']) && $data['division'] !== '' ? $data['division'] : 'Investment';
            $zone = isset($data['zone']) && $data['zone'] !== '' ? $data['zone'] : null;
            
            $query = "INSERT INTO client_profiles 
                      (client_name, client_code, address, city, state, zip_code, 
                       phone, email, contact_person, branch_id, division, zone) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param(
                "sssssssssiss",
                $client_name,
                $client_code,
                $address,
                $city,
                $state,
                $zip_code,
                $phone,
                $email,
                $contact_person,
                $branch_id,
                $division,
                $zone
            );
    if ($stmt->execute()) {
        $new_id = $conn->insert_id;
        sendResponse([
            'success' => true, 
            'message' => 'Client added successfully', 
            'id' => $new_id,
            'client_code' => $client_code  // Make sure this is included
        ], 201);
    } else {
        sendResponse(['success' => false, 'error' => $stmt->error], 500);
    }
    break;
            
        // ============================================================
        // PUT - Update client
        // ============================================================
        case 'PUT':
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['id'])) {
                sendResponse(['success' => false, 'error' => 'Client ID required'], 400);
            }
            
            // Prepare values with null handling
            $id = (int)$data['id'];
            $client_name = $data['client_name'];
            $client_code = isset($data['client_code']) && $data['client_code'] !== '' ? $data['client_code'] : null;
            $address = isset($data['address']) && $data['address'] !== '' ? $data['address'] : null;
            $city = isset($data['city']) && $data['city'] !== '' ? $data['city'] : null;
            $state = isset($data['state']) && $data['state'] !== '' ? $data['state'] : null;
            $zip_code = isset($data['zip_code']) && $data['zip_code'] !== '' ? $data['zip_code'] : null;
            $phone = isset($data['phone']) && $data['phone'] !== '' ? $data['phone'] : null;
            $email = isset($data['email']) && $data['email'] !== '' ? $data['email'] : null;
            $contact_person = isset($data['contact_person']) && $data['contact_person'] !== '' ? $data['contact_person'] : null;
            $branch_id = isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null ? (int)$data['branch_id'] : null;
            $division = isset($data['division']) && $data['division'] !== '' ? $data['division'] : 'Investment';
            $zone = isset($data['zone']) && $data['zone'] !== '' ? $data['zone'] : null;
            
            $query = "UPDATE client_profiles SET 
                      client_name = ?,
                      client_code = ?,
                      address = ?,
                      city = ?,
                      state = ?,
                      zip_code = ?,
                      phone = ?,
                      email = ?,
                      contact_person = ?,
                      branch_id = ?,
                      division = ?,
                      zone = ?
                      WHERE id = ?";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param(
                "sssssssssissi",
                $client_name,
                $client_code,
                $address,
                $city,
                $state,
                $zip_code,
                $phone,
                $email,
                $contact_person,
                $branch_id,
                $division,
                $zone,
                $id
            );
            
            if ($stmt->execute()) {
                sendResponse(['success' => true, 'message' => 'Client updated successfully']);
            } else {
                sendResponse(['success' => false, 'error' => $stmt->error], 500);
            }
            break;
            
        // ============================================================
        // DELETE - Delete client
        // ============================================================
        case 'DELETE':
            if (!isset($_GET['id'])) {
                sendResponse(['success' => false, 'error' => 'Client ID required'], 400);
            }
            
            $id = (int)$_GET['id'];
            
            // Check if client has related records before deleting
            $check_query = "SELECT COUNT(*) as count FROM office_files WHERE client_id = ? AND is_deleted = 0";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("i", $id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result()->fetch_assoc();
            
            if ($check_result['count'] > 0) {
                sendResponse(['success' => false, 'error' => 'Cannot delete client with existing files. Please reassign or delete the files first.'], 400);
            }
            
            $query = "DELETE FROM client_profiles WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                sendResponse(['success' => true, 'message' => 'Client deleted successfully']);
            } else {
                sendResponse(['success' => false, 'error' => $stmt->error], 500);
            }
            break;
            
        default:
            sendResponse(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch(PDOException $e) {
    sendResponse(['success' => false, 'error' => $e->getMessage()], 500);
} catch(Exception $e) {
    sendResponse(['success' => false, 'error' => $e->getMessage()], 500);
}

// ============================================================
// 6. FLUSH OUTPUT BUFFER
// ============================================================
ob_end_flush();
?>