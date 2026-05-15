<?php
include 'db.php';
$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// UPDATE: Added a subquery to count transfers for each file
$sql = "SELECT *, 
        (SELECT COUNT(*) FROM file_transfers WHERE file_id = office_files.id) as transfer_count FROM office_files WHERE is_deleted = 0 ORDER BY id DESC";

$result = $conn->query($sql);

while($row = $result->fetch_assoc()) {
    $file_id = $row['id'];
    $movements = $row['transfer_count']; // Access the count from the query
    
    // 1. Division Color Logic
    $division = $row['division'] ?? '';
    $clientName = htmlspecialchars($row['client'] ?? '');
    $textColor = (strtolower(trim($division)) === 'investment') ? '' : 'text-danger fw-bold';

    // 2. View Icon Logic
    // If movements > 0, we use green (success) and a tooltip with the count
    if ($movements > 0) {
        $viewBtnClass = "btn-success"; 
        $viewTitle = "View History ($movements movements)";
    } else {
        $viewBtnClass = "btn-dark"; 
        $viewTitle = "No History Found";
    }
    
    // Fetch attachments
    $attach_sql = "SELECT * FROM file_attachments WHERE file_record_id = $file_id";
    $attach_res = $conn->query($attach_sql);
    $attachments_html = ""; 

    while($file = $attach_res->fetch_assoc()) {
        $file_path = htmlspecialchars($file['file_path'] ?? '');
        $file_desc = htmlspecialchars($file['description'] ?? 'Download');
        $attachments_html .= "<a href='$file_path' target='_blank' class='badge bg-info text-decoration-none mb-1 d-block' onclick='event.stopPropagation();'>
        <i class='fas fa-paperclip'></i> $file_desc</a>";
    }

    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['client'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['branch_code'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['division'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['cabinet_name'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['shelf_name'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['file_no'] ?? '') . "</td>";
    echo "<td>" . (!empty($row['created_at']) ? date("d-m-Y", strtotime($row['created_at'])) : '') . "</td>";
    echo "<td>" . htmlspecialchars($row['remarks'] ?? '') . "</td>";
    echo "<td>" . $attachments_html . "</td>";

    // Actions Column
    echo "<td>";
    echo "<div class='d-flex gap-1'>"; // This creates a horizontal row with spacing
        if ($isAdmin) {
            echo "<a href='edit.php?id=$file_id' class='btn btn-sm btn-primary'><i class='fas fa-edit'></i></a>";
        }
        
        echo "<a href='transfer_file.php?id=$file_id' class='btn btn-sm btn-outline-primary' title='Transfer Division'><i class='fas fa-exchange-alt'></i></a>";
        
        echo "<a href='view_details.php?id=$file_id' class='btn btn-sm $viewBtnClass' title='$viewTitle'><i class='fas fa-eye'></i></a>";
        
        if ($isAdmin) {
            echo "<a href='delete.php?id=$file_id' class='btn btn-sm btn-danger' onclick='return confirm(\"Are you sure?\")'><i class='fas fa-trash'></i></a>";
        }
        echo "<a href='add_facility.php?id=$file_id' class='btn btn-sm btn-success' title='Add New Sanction'><i class='fas fa-plus-square'></i></a>"; 
    echo "</div>";
echo "</td>";
    
    echo "</tr>";
}
?>