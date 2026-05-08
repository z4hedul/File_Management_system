<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("location: login.php");
    exit;
}

include 'db.php'; // Your DB connection logic

if(isset($_GET['id'])) {
    $id = $_GET['id'];
    $sql = "DELETE FROM office_files WHERE id = $id";

    if ($conn->query($sql) === TRUE) {
        header("Location: index.php"); // Redirect back to your main table
    } else {
        echo "Error deleting record: " . $conn->error;
    }
}
?>