<?php
include 'db.php';
session_start();
$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// Read server-side execution variables from DataTables
$draw = isset($_POST['draw']) ? intval($_POST['draw']) : 1;
$start = isset($_POST['start']) ? intval($_POST['start']) : 0;
$rowperpage = isset($_POST['length']) ? intval($_POST['length']) : 10;
$searchValue = isset($_POST['search']['value']) ? $conn->real_escape_string($_POST['search']['value']) : '';

// Direct sorting optimization parameters
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
        6 => 'o.id', // Safe fallback order index for aggregations
        7 => 'o.is_checked_out'
    ];
    $orderByColumn = $columnMap[$columnIndex] ?? 'o.id';
} else {
    $orderByColumn = 'o.id';
}

// 1. TOTAL RECORDS COUNT
$totalRecordsQuery = "SELECT COUNT(*) AS total FROM office_files WHERE is_deleted = 0";
$totalRecordsResult = $conn->query($totalRecordsQuery);
$totalRecords = $totalRecordsResult->fetch_assoc()['total'] ?? 0;

// 2. FILTERED RECORDS QUERY
$searchQuery = "";
if (!empty($searchValue)) {
    $searchQuery = " AND (o.client LIKE '%$searchValue%' 
                    OR o.file_no LIKE '%$searchValue%' 
                    OR o.branch_code LIKE '%$searchValue%' 
                    OR o.branch_name LIKE '%$searchValue%' 
                    OR o.division LIKE '%$searchValue%'
                    OR o.cabinet_name LIKE '%$searchValue%' 
                    OR o.shelf_name LIKE '%$searchValue%')";
}

$filterQuery = "SELECT COUNT(*) AS total FROM office_files o WHERE o.is_deleted = 0" . $searchQuery;
$filterResult = $conn->query($filterQuery);
$totalRecordwithFilter = $filterResult->fetch_assoc()['total'] ?? 0;

// 3. MASTER FETCH QUERY
$empQuery = "SELECT o.*, 
                    (SELECT MAX(f.sanction_date) FROM file_facilities f WHERE f.file_record_id = o.id) AS last_sanction_date,
                    (SELECT u.full_name FROM users u WHERE u.id = o.checked_out_by_user_id) AS cabinet_holder_name
             FROM office_files o
             WHERE o.is_deleted = 0" . $searchQuery . " 
             ORDER BY " . $orderByColumn . " " . $columnSortOrder . " 
             LIMIT " . $start . ", " . $rowperpage;

$result = $conn->query($empQuery);
$data = array();

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $file_id = intval($row['id']);
        $is_checked_out = isset($row['is_checked_out']) ? intval($row['is_checked_out']) : 0;
        $holder_name = !empty($row['cabinet_holder_name']) ? htmlspecialchars($row['cabinet_holder_name']) : 'Unknown User';

        // Interface UI Badges & Columns Generation
        $client_name_html = '<strong>' . htmlspecialchars($row['client'] ?? '') . '</strong>';
        
        $branch_details_html = '<div class="small text-dark fw-semibold">' . htmlspecialchars($row['branch_code'] ?? '') . '</div>' .
                               '<div class="text-muted" style="font-size: 0.72rem;">' . htmlspecialchars($row['branch_name'] ?? 'Unassigned') . '</div>';
        
        $sanction_date_html = !empty($row['last_sanction_date']) ? 
            '<span class="badge bg-light text-dark border"><i class="far fa-calendar-check text-success me-1"></i>' . date('d-M-Y', strtotime($row['last_sanction_date'])) . '</span>' : 
            '<span class="text-muted small italic">No Facilities</span>';

        $division_html = '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1" style="font-size:0.72rem;">' . htmlspecialchars($row['division'] ?? '') . '</span>';
        $cabinet_html = '<span class="badge bg-info text-white cabinet-badge shadow-sm">' . htmlspecialchars($row['cabinet_name'] ?? '') . '</span>';
        $shelf_html = '<span class="badge bg-primary text-white border shelf-badge">' . htmlspecialchars($row['shelf_name'] ?? '') . '</span>';
        $file_no_html = '<span class="badge text-bg-secondary">' . htmlspecialchars($row['file_no'] ?? '') . '</span>';

        // Cabinet Tracker Status Generation
        if ($is_checked_out === 1) {
            $cabinet_tracker_html = '<div class="mb-1"><span class="badge bg-danger text-white px-2 py-1 shadow-sm" style="font-size: 11px;">' .
                                    '<i class="fas fa-times-circle me-1"></i>Out of Cabinet' .
                                    '</span></div>' .
                                    '<div style="font-size:10px; font-weight:600;" class="text-muted"><i class="fas fa-user text-secondary me-1"></i>By: ' . $holder_name . '</div>';
                                    
            $toggle_movement_btn = "<a href='toggle_file_status.php?file_id=$file_id&action=checkin' class='btn btn-sm btn-success text-white fw-bold shadow-sm' title='Return file to Cabinet' onclick='return confirm(\"Return this client file back to the cabinet?\")'><i class='fas fa-undo me-1'></i>Return</a>";
        } else {
            $cabinet_tracker_html = '<div class="mb-1"><span class="badge bg-success text-white px-2 py-1 shadow-sm" style="font-size: 11px;">' .
                                    '<i class="fas fa-check-circle me-1"></i>In Cabinet' .
                                    '</span></div>';
                                    
            $toggle_movement_btn = "<a href='toggle_file_status.php?file_id=$file_id&action=checkout' class='btn btn-sm btn-outline-danger fw-bold shadow-sm' title='Grab file from Cabinet' onclick='return confirm(\"Grab this file under your log profile tracking?\")'><i class='fas fa-hand-holding me-1'></i>Grab</a>";
        }

        if (!empty($row['remarks'])) {
            $cabinet_tracker_html .= '<div class="mt-1 small text-muted text-truncate" style="max-width:180px; font-size:10px;">' . htmlspecialchars($row['remarks']) . '</div>';
        }

        // Setup dynamic elements for view button variants from your codebase patterns
        $viewBtnClass = ($is_checked_out === 1) ? 'btn-outline-secondary' : 'btn-outline-primary';
        $viewTitle = ($is_checked_out === 1) ? 'View Details (Checked Out)' : 'View Details';

        // Construct complete unified action column system matrix layout
        $actions_html = "<div class='d-flex gap-1 justify-content-center align-items-center flex-wrap'>";
        
        // NEW ADVANCED CONTROLS (Cabinet Tracker & History Telemetry Log Tracking Button)
        $actions_html .= $toggle_movement_btn;
        $actions_html .= "<button type='button' class='btn btn-sm btn-light border text-dark shadow-sm fw-bold view-history-log-btn' data-id='$file_id' title='View History Log'><i class='fas fa-history text-secondary me-1'></i>Log</button>";
        
        // YOUR EXACT EXISTING INTERFACE OPTIONS PRESERVED PRECISELY
        if ($isAdmin) {
            $actions_html .= "<a href='edit.php?id=$file_id' class='btn btn-sm btn-primary' title='Edit'><i class='fas fa-edit'></i></a>";
        }
        $actions_html .= "<a href='transfer_file.php?id=$file_id' class='btn btn-sm btn-outline-primary' title='Transfer Division'><i class='fas fa-exchange-alt'></i></a>";
        $actions_html .= "<a href='view_details.php?id=$file_id' class='btn btn-sm $viewBtnClass' title='$viewTitle'><i class='fas fa-eye'></i></a>";
        $actions_html .= "<a href='more_details.php?id=$file_id' class='btn btn-sm btn-success' title='More Details'>info</a>"; 
        $actions_html .= "<a href='assign_proposal.php?id=$file_id' class='btn btn-sm btn-info' title='Assign'><i class='fas fa-user-plus'></i> Assign</a>";
        
        if ($isAdmin) {
            $actions_html .= "<a href='delete.php?id=$file_id' class='btn btn-sm btn-danger' title='Delete' onclick='return confirm(\"Are you sure?\")'><i class='fas fa-trash'></i></a>";
        }
        
        $actions_html .= "</div>";

        $data[] = array(
            "client"             => $client_name_html,
            "branch_code"        => $branch_details_html,
            "division"           => $division_html,
            "cabinet_name"       => $cabinet_html,
            "shelf_name"         => $shelf_html,
            "file_no"            => $file_no_html,
            "last_sanction_date" => $sanction_date_html,
            "remarks"            => $cabinet_tracker_html,
            "actions"            => $actions_html
        );
    }
}

$response = array(
    "draw"                 => $draw,
    "iTotalRecords"        => $totalRecords,
    "iTotalDisplayRecords" => $totalRecordwithFilter,
    "aaData"               => $data
);

echo json_encode($response);
exit;