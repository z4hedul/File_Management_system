<?php
// ============================================================
// 1. START OUTPUT BUFFERING AND ERROR HANDLING
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start();

include 'db.php';
session_start();
$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// ============================================================
// 2. FUNCTION TO SEND JSON RESPONSE
// ============================================================
function sendJsonResponse($data) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ============================================================
// 3. CHECK DATABASE CONNECTION
// ============================================================
if (!isset($conn) || $conn->connect_error) {
    sendJsonResponse([
        "draw" => intval($_POST['draw'] ?? 1),
        "iTotalRecords" => 0,
        "iTotalDisplayRecords" => 0,
        "aaData" => [],
        "error" => "Database connection failed"
    ]);
}

try {
    $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 1;
    $start = isset($_POST['start']) ? intval($_POST['start']) : 0;
    $rowperpage = isset($_POST['length']) ? intval($_POST['length']) : 10;
    $searchValue = isset($_POST['search']['value']) ? $conn->real_escape_string($_POST['search']['value']) : '';

    $columnSortOrder = (isset($_POST['order'][0]['dir']) && $_POST['order'][0]['dir'] === 'asc') ? 'ASC' : 'DESC';

    if (isset($_POST['order'][0]['column'])) {
        $columnIndex = intval($_POST['order'][0]['column']);
        $columnMap = [
            0 => 'o.client',
            1 => 'o.branch_code',
            2 => 'o.division',
            3 => 'o.cabinet_name',
            4 => 'o.shelf_name',
            5 => 'o.file_no',
            6 => 'ff.last_sanction_date',
            7 => 'o.remarks'
        ];
        $orderByColumn = $columnMap[$columnIndex] ?? 'o.id';
    } else {
        $orderByColumn = 'o.id';
        $columnSortOrder = 'DESC';
    }

    // 1. Get total record count overall
    $totalRecordsQuery = "SELECT COUNT(*) AS allcount FROM office_files WHERE is_deleted = 0";
    $totalRecordsResult = $conn->query($totalRecordsQuery);
    if (!$totalRecordsResult) {
        throw new Exception("Error getting total records: " . $conn->error);
    }
    $totalRecordsRow = $totalRecordsResult->fetch_assoc();
    $totalRecords = $totalRecordsRow['allcount'] ?? 0;

    // 2. Build search filter constraints
    $searchQuery = "";
    if ($searchValue != '') {
        $searchQuery = " AND (o.client LIKE '%".$searchValue."%' OR 
                              o.branch_code LIKE '%".$searchValue."%' OR 
                              o.branch_name LIKE '%".$searchValue."%' OR 
                              o.zone LIKE '%".$searchValue."%' OR 
                              o.division LIKE '%".$searchValue."%' OR 
                              o.file_no LIKE '%".$searchValue."%' OR
                              o.remarks LIKE '%".$searchValue."%') ";
    }

    // 3. Get total filtered record count
    $totalWithFilterQuery = "SELECT COUNT(*) AS allcount FROM office_files o WHERE o.is_deleted = 0".$searchQuery;
    $totalWithFilterResult = $conn->query($totalWithFilterQuery);
    if (!$totalWithFilterResult) {
        throw new Exception("Error getting filtered count: " . $conn->error);
    }
    $totalWithFilterRow = $totalWithFilterResult->fetch_assoc();
    $totalRecordwithFilter = $totalWithFilterRow['allcount'] ?? 0;

    // 4. Fetch the data - Make sure to include client_id
    // In fetch_data.php, update the query to show all files
// In fetch_data.php, update the query to show all files (not just linked ones)
// Remove any condition that filters by client_id

$empQuery = "SELECT o.*, 
                    COALESCE(ft.transfer_count, 0) AS transfer_count, 
                    ff.last_sanction_date
             FROM office_files o
             LEFT JOIN (
                 SELECT file_id, COUNT(*) AS transfer_count FROM file_transfers GROUP BY file_id
             ) ft ON o.id = ft.file_id
             LEFT JOIN (
                 SELECT file_record_id, MAX(sanction_date) AS last_sanction_date FROM file_facilities GROUP BY file_record_id
             ) ff ON o.id = ff.file_record_id
             WHERE o.is_deleted = 0 ".$searchQuery." 
             ORDER BY ".$orderByColumn." ".$columnSortOrder." 
             LIMIT ".intval($start).", ".intval($rowperpage);

    $empRecords = $conn->query($empQuery);
    if (!$empRecords) {
        throw new Exception("Error fetching records: " . $conn->error);
    }

    $data = array();

    while ($row = $empRecords->fetch_assoc()) {
        $file_id = $row['id'];
        $movements = $row['transfer_count'] ?? 0;
        $is_assigned = !empty($row['client_id']) && $row['client_id'] > 0;
        
        // View history tooltip setup
        if ($movements > 0) {
            $viewBtnClass = "btn-success"; 
            $viewTitle = "View History ($movements movements)";
        } else {
            $viewBtnClass = "btn-dark"; 
            $viewTitle = "No History Found";
        }

        // Build Last Sanction Date formatting logic
        $sanction_date_html = "<span class='text-muted small'>No Sanction Date</span>";
        if (!empty($row['last_sanction_date']) && $row['last_sanction_date'] !== '0000-00-00') {
            $sanction_date_html = "<span class='badge bg-success-subtle text-success border border-success-subtle px-2 py-1'>" . 
                                  "<i class='far fa-calendar-check me-1'></i>" . date("d-m-Y", strtotime($row['last_sanction_date'])) . 
                                  "</span>";
        }

        // Compile actions markup - WITH ASSIGN BUTTON
        $actions_html = "<div class='d-flex gap-1 justify-content-center flex-wrap'>";
        
        if ($isAdmin) {
            $actions_html .= "<a href='edit.php?id=$file_id' class='btn btn-sm btn-primary' title='Edit'><i class='fas fa-edit'></i></a>";
        }
        
        $actions_html .= "<a href='transfer_file.php?id=$file_id' class='btn btn-sm btn-outline-primary' title='Transfer Division'><i class='fas fa-exchange-alt'></i></a>";
        $actions_html .= "<a href='view_details.php?id=$file_id' class='btn btn-sm $viewBtnClass' title='$viewTitle'><i class='fas fa-eye'></i></a>";
        $actions_html .= "<a href='more_details.php?id=$file_id' class='btn btn-sm btn-success' title='More Details'><i class='fas fa-info-circle'></i></a>"; 
        $actions_html .= "<a href='assign_proposal.php?id=" . intval($row['id']) . "' class='btn btn-sm btn-info' title='Assign Proposal'><i class='fas fa-user-plus'></i></a>";
        
        // NEW: Assign to Client button
if (!$is_assigned) {
    $client_name = addslashes($row['client'] ?? '');
    $file_no = addslashes($row['file_no'] ?? '');
    $actions_html .= "<button class='btn btn-sm btn-warning assign-file-btn' 
                        title='Assign to Client' 
                        data-file-id='$file_id' 
                        data-file-name='$client_name' 
                        data-file-no='$file_no' 
                        style='background: #ffc72c; color: #006a4e; border: none; padding: 4px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; cursor: pointer;'>";
    $actions_html .= "<i class='fas fa-link'></i> Assign";
    $actions_html .= "</button>";
} else {
    $actions_html .= "<span class='badge bg-success' title='Already assigned to a client'><i class='fas fa-check'></i> Linked</span>";
}
        
        if ($isAdmin) {
            $actions_html .= "<a href='delete.php?id=$file_id' class='btn btn-sm btn-danger' onclick='return confirm(\"Are you sure?\")' title='Delete'><i class='fas fa-trash'></i></a>";
        }
        $actions_html .= "</div>";

        // Safe HTML escaping
        $client_name = htmlspecialchars($row['client'] ?? '');
        $branch_code = htmlspecialchars($row['branch_code'] ?? '');
        $branch_name = htmlspecialchars($row['branch_name'] ?? '');
        $zone = htmlspecialchars($row['zone'] ?? '');
        $division = htmlspecialchars($row['division'] ?? '');
        $cabinet_name = htmlspecialchars($row['cabinet_name'] ?? '');
        $shelf_name = htmlspecialchars($row['shelf_name'] ?? '');
        $file_no = htmlspecialchars($row['file_no'] ?? '');
        $remarks = htmlspecialchars($row['remarks'] ?? '');

        // Build HTML for each column
        $client_name_html = '<div class="fw-bold text-dark mb-0" style="font-size: 0.95rem;">' . $client_name . '</div>';
        
        $branch_details_html = '<div class="mb-0 fw-semibold text-secondary"><i class="fas fa-code-branch text-info small me-1"></i>' . $branch_code . ' - ' . $branch_name . '</div>' .
                               '<small class="text-muted font-monospace" style="font-size: 0.72rem;"><i class="fas fa-globe me-1"></i>' . $zone . '</small>';

        $div_badge_class = ($division === 'Investment') ? 'bg-success' : (($division === 'SME') ? 'bg-warning text-dark' : 'bg-info text-dark');
        $division_html = '<span class="badge ' . $div_badge_class . ' fw-bold px-2 py-1">' . $division . '</span>';

        $cabinet_html = '<span class="badge bg-info text-white cabinet-badge shadow-sm">' . $cabinet_name . '</span>';
        
        $shelf_html = '<span class="badge bg-primary text-white border shelf-badge">' . $shelf_name . '</span>';
        
        $file_no_html = '<span class="badge text-bg-secondary">' . $file_no . '</span>';

        $remarks_html = !empty($remarks) ? '<small class="text-muted d-block text-truncate font-monospace" style="max-width: 250px; font-size:0.75rem;"><strong>Note:</strong> ' . $remarks . '</small>' : '';

        // Format array output
        $data[] = array(
            "client"             => $client_name_html,
            "branch_code"        => $branch_details_html,
            "division"           => $division_html,
            "cabinet_name"       => $cabinet_html,
            "shelf_name"         => $shelf_html,
            "file_no"            => $file_no_html,
            "last_sanction_date" => $sanction_date_html,
            "remarks"            => $remarks_html,
            "actions"            => $actions_html
        );
    }

    // Send back JSON payload
    $response = array(
        "draw" => intval($draw),
        "iTotalRecords" => intval($totalRecords),
        "iTotalDisplayRecords" => intval($totalRecordwithFilter),
        "aaData" => $data
    );

    sendJsonResponse($response);

} catch (Exception $e) {
    sendJsonResponse([
        "draw" => intval($_POST['draw'] ?? 1),
        "iTotalRecords" => 0,
        "iTotalDisplayRecords" => 0,
        "aaData" => [],
        "error" => $e->getMessage()
    ]);
}

while (ob_get_level()) {
    ob_end_clean();
}
?>