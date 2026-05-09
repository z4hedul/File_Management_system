<?php
include 'db.php';
$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

$sql = "SELECT * FROM office_files";
$result = $conn->query($sql);

while($row = $result->fetch_assoc()) {
    $file_id = $row['id'];
    
    // Fetch attachments
$attach_sql = "SELECT * FROM file_attachments WHERE file_record_id = $file_id";
$attach_res = $conn->query($attach_sql);
$attachments_html = ""; 

while($file = $attach_res->fetch_assoc()) {
    $file_path = htmlspecialchars($file['file_path'] ?? '');
    $file_desc = htmlspecialchars($file['description'] ?? 'Download');
    
    // We add 'onclick' to stop the event from bubbling up to the DataTable row
    $attachments_html .= "<a href='$file_path' target='_blank' class='badge bg-info text-decoration-none mb-1 d-block' onclick='event.stopPropagation();'>
                            <i class='fas fa-paperclip'></i> $file_desc
                          </a>";
}

    // LINE 24 START:
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['client'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['branch_name'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['division'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['cabinet_name'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['shelf_name'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['file_no'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['sanctioned_date'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($row['remarks'] ?? '') . "</td>";
    echo "<td>" . $attachments_html . "</td>"; // THIS MUST EXIST

    if ($isAdmin) {
        echo "<td>
                <a href='edit.php?id=$file_id' class='btn btn-sm btn-primary'><i class='fas fa-edit'></i></a>
                <a href='delete.php?id=$file_id' class='btn btn-sm btn-danger' onclick='return confirm(\"Are you sure?\")'><i class='fas fa-trash'></i></a>
              </td>";
    }
    echo "</tr>";
}
?>