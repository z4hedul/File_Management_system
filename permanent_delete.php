<?php
session_start();
include 'db.php';

// 1. SECURITY CHECK: Only logged-in Admins should be able to delete forever
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'admin') {
    die("<div style='color:red; font-family:sans-serif; padding:20px;'>
            <strong>Access Denied:</strong> You do not have permission to permanently delete records.
         </div>");
}

// 2. VALIDATE THE ID
if (isset($_GET['id'])) {
    $id = intval($_GET['id']);

    // Start a Transaction (Optional but safer)
    $conn->begin_transaction();

    try {
        // First: Delete related transfer history (Foreign Key cleanup)
        // If your database has "ON DELETE CASCADE", this happens automatically,
        // but manually doing it prevents SQL errors.
        $stmt_history = $conn->prepare("DELETE FROM file_transfers WHERE file_id = ?");
        $stmt_history->bind_param("i", $id);
        $stmt_history->execute();

        // Second: Delete related attachments if any
        $stmt_attach = $conn->prepare("DELETE FROM file_attachments WHERE file_record_id = ?");
        $stmt_attach->bind_param("i", $id);
        $stmt_attach->execute();

        // Finally: Delete the main record from office_files
        $stmt_main = $conn->prepare("DELETE FROM office_files WHERE id = ?");
        $stmt_main->bind_param("i", $id);
        $stmt_main->execute();

        $conn->commit();
        
        // Redirect back to trash with success message
        header("Location: trash.php?msg=deleted_forever");
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        echo "Error deleting record: " . $e->getMessage();
    }
} else {
    header("Location: trash.php");
    exit;
}
?>