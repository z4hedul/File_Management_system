<?php 
session_start();
include 'db.php'; 
include 'header.php';

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
    </style>
</head>
<body>

<div class="container main-container pb-5">
    <div class="card table-card shadow-sm">
        <div class="card-header bg-white py-3 border-bottom-0 shadow-sm rounded-top-3">
    <div class="d-flex justify-content-between align-items-center w-100 px-2">
        
        <div class="d-flex align-items-center">
            <div class="icon-shape bg-primary-subtle text-primary rounded-3 me-3 d-inline-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                <i class="fas fa-folder-open fs-5"></i>
            </div>
            <div>
                <h5 class="mb-0 fw-bold text-dark" style="font-family: 'Segoe UI', system-ui, sans-serif; letter-spacing: -0.3px;">
                    Master File Records
                </h5>
                <small class="text-muted d-none d-sm-block" style="font-size: 0.78rem;">Manage and track active office files</small>
            </div>
        </div>
        
        <div class="d-flex gap-2 align-items-center">
            <a href="add_record.php" class="btn btn-primary shadow-sm rounded-3 px-3 py-2 fw-semibold d-flex align-items-center" style="font-size: 0.88rem; transition: all 0.2s ease;">
                <i class="fas fa-plus-circle me-2 fs-6"></i> New File Record
            </a>
            
            <a href="index.php" class="btn btn-outline-secondary rounded-3 px-3 py-2 fw-medium d-flex align-items-center" style="font-size: 0.88rem;">
                <i class="fas fa-home me-2"></i> Dashboard
            </a>
        </div>

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
                            <th>Last Sanction Date</th>
                            <th>Remarks</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
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
        "processing": true,
        "serverSide": true,
        "ajax": {
            "url": "fetch_data.php",
            "type": "POST"
        },
        "pageLength": 10,
        "responsive": true,
        "order": [], // Let the server side query decide the default initial sorting (Newest first)
        "columns": [
            { "data": "client" },
            { "data": "branch_code" },
            { "data": "division" },
            { "data": "cabinet_name" },
            { "data": "shelf_name" },
            { "data": "file_no" },
            { "data": "last_sanction_date" },
            { "data": "remarks" },
            { "data": "actions", "orderable": false, "className": "text-center" }
        ],
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