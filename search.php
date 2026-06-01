<?php 
session_start();
// 1. DATABASE CONNECTION MUST BE FIRST
include 'db.php'; 
include 'header.php';
// 2. LOGGED IN CHECK
if (!isset($_SESSION['loggedin'])) {
    header("Location: login.php");
    exit;
}

$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Management System</title>
    
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Modern Grid Card Styling */
        .dashboard-card {
            border: none;
            border-radius: 12px;
            transition: all 0.3s ease;
            background: #fff;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.08) !important;
        }
        .icon-shape {
            width: 48px;
            height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }
    </style>
</head>
<body>

<div class="container main-container pb-5">
    <div class="card table-card shadow-sm">
        <div class="card-header bg-white py-3 border-bottom">
            <div class="d-flex justify-content-between align-items-center w-100">
    <!-- Left Side: Title Heading -->
    <h5 class="mb-0 fw-bold text-primary">Master File Records</h5>
    
    <!-- Right Side: Action Button -->
    <a href="index.php" class="btn btn-dark shadow-sm rounded-3 px-3">
        <i class="fas fa-home me-1"></i> Dashboard
    </a>
</div>
        </div>
        
        <div class="card-body">
            <div class="table-responsive">
                <table id="filesTable" class="table align-middle w-100 m-0">
                    <thead>
                        <tr>
                            <th>Client Name</th>
                            <th>Branch Code</th>
                            <th>Division</th>
                            <th>Cabinet</th>
                            <th>Shelf</th>
                            <th>File No.</th>
                            <th>Created Date</th>
                            <th>Remarks</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php include 'fetch_data.php'; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/jquery-3.6.0.min.js"></script>
<script src="assets/js/jquery.dataTables.min.js"></script>
<script src="assets/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#filesTable').DataTable({
        "pageLength": 10,
        "responsive": true,
        "order": [[ 0, "asc" ]], 
        "language": {
            "search": "Search File:",
            "lengthMenu": "Show _MENU_"
        }
    });
});
</script>
<?php include 'footer.php'; ?>
</body>
</html>