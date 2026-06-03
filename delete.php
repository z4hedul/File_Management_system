<?php
// ... session check remains the same ...
include 'db.php';

if(isset($_GET['id'])) {
    $id = intval($_GET['id']);
    // We UPDATE the status to 1 instead of DELETING
    $sql = "UPDATE office_files SET is_deleted = 1 WHERE id = $id";

    if ($conn->query($sql) === TRUE) {
        header("Location: search.php?msg=moved_to_trash");
        exit;
    } else {
        echo "Error: " . $conn->error;
    }
}
?>