<?php
include 'db.php';
session_start();
$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// Read server-side execution variables from DataTables
$draw = isset($_POST['draw']) ? intval($_POST['draw']) : 1;
$start = isset($_POST['start']) ? intval($_POST['start']) : 0;
$rowperpage = isset($_POST['length']) ? intval($_POST['length']) : 10;
$searchValue = isset($_POST['search']['value']) ? $conn->real_escape_string($_POST['search']['value']) : '';

// FIXED: Adjust sorting parameters to prioritize the master entry auto-increment ID (o.id)
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
    // Default fallback order when no specific column header has been clicked: show newest files first!
    $orderByColumn = 'o.id';
    $columnSortOrder = 'DESC';
}

// 1. Get total record count overall
$totalRecordsQuery = "SELECT COUNT(*) AS allcount FROM office_files WHERE is_deleted = 0";
$totalRecordsResult = $conn->query($totalRecordsQuery);
$totalRecordsRow = $totalRecordsResult->fetch_assoc();
$totalRecords = $totalRecordsRow['allcount'];

// 2. Build search filter constraints
$searchQuery = " ";
if ($searchValue != '') {
    $searchQuery = " AND (o.client LIKE '%".$searchValue."%' OR 
                          o.branch_code LIKE '%".$searchValue."%' OR 
                          o.division LIKE '%".$searchValue."%' OR 
                          o.file_no LIKE '%".$searchValue."%' OR
                          o.remarks LIKE '%".$searchValue."%') ";
}

// 3. Get total filtered record count matching the search query
$totalWithFilterQuery = "SELECT COUNT(*) AS allcount FROM office_files o WHERE o.is_deleted = 0".$searchQuery;
$totalWithFilterResult = $conn->query($totalWithFilterQuery);
$totalWithFilterRow = $totalWithFilterResult->fetch_assoc();
$totalRecordwithFilter = $totalWithFilterRow['allcount'];

// 4. Fetch the specific page partition (10 rows only)
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
             LIMIT ".$start.", ".$rowperpage;

$empRecords = $conn->query($empQuery);
$data = array();

while ($row = $empRecords->fetch_assoc()) {
    $file_id = $row['id'];
    $movements = $row['transfer_count'];
    
    // View history tooltip setup
    if ($movements > 0) {
        $viewBtnClass = "btn-success"; 
        $viewTitle = "View History ($movements movements)";
    } else {
        $viewBtnClass = "btn-dark"; 
        $viewTitle = "No History Found";
    }

    // Build Last Sanction Date formatting logic
    $sanction_date_html = "<span class='text-muted small'>No Record</span>";
    if (!empty($row['last_sanction_date'])) {
        $sanction_date_html = "<span class='badge bg-success-subtle text-success border border-success-subtle px-2 py-1'>" . 
                              "<i class='far fa-calendar-check me-1'></i>" . date("d-m-Y", strtotime($row['last_sanction_date'])) . 
                              "</span>";
    }

    // Compile actions markup row layout 
    $actions_html = "<div class='d-flex gap-1 justify-content-center'>";
    if ($isAdmin) {
        $actions_html .= "<a href='edit.php?id=$file_id' class='btn btn-sm btn-primary'><i class='fas fa-edit'></i></a>";
    }
    $actions_html .= "<a href='transfer_file.php?id=$file_id' class='btn btn-sm btn-outline-primary' title='Transfer Division'><i class='fas fa-exchange-alt'></i></a>";
    $actions_html .= "<a href='view_details.php?id=$file_id' class='btn btn-sm $viewBtnClass' title='$viewTitle'><i class='fas fa-eye'></i></a>";
    $actions_html .= "<a href='more_details.php?id=$file_id' class='btn btn-sm btn-success' title='More Details'>info</a>"; 
    $actions_html .= "<a href='assign_proposal.php?id=" . intval($row['id']) . "' class='btn btn-sm btn-warning'><i class='fas fa-user-plus'></i> Assign</a>";
    if ($isAdmin) {
        $actions_html .= "<a href='delete.php?id=$file_id' class='btn btn-sm btn-danger' onclick='return confirm(\"Are you sure?\")'><i class='fas fa-trash'></i></a>";
    }
    $actions_html .= "</div>";

    // Format array output to match DataTables structure
    $data[] = array(
        "client"             => htmlspecialchars($row['client'] ?? ''),
        "branch_code"        => htmlspecialchars($row['branch_code'] ?? ''),
        "division"           => htmlspecialchars($row['division'] ?? ''),
        "cabinet_name"       => htmlspecialchars($row['cabinet_name'] ?? ''),
        "shelf_name"         => htmlspecialchars($row['shelf_name'] ?? ''),
        "file_no"            => htmlspecialchars($row['file_no'] ?? ''),
        "last_sanction_date" => $sanction_date_html,
        "remarks"            => htmlspecialchars($row['remarks'] ?? ''),
        "actions"            => $actions_html
    );
}

// Send back JSON payload
$response = array(
    "draw" => intval($draw),
    "iTotalRecords" => $totalRecords,
    "iTotalDisplayRecords" => $totalRecordwithFilter,
    "aaData" => $data
);

header('Content-Type: application/json');
echo json_encode($response);
exit;
?>