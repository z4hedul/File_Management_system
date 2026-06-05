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
    <<meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        /* Style adjustments imported from the ledger template */
        .cabinet-badge { font-size: 0.9rem; font-weight: 700; padding: 6px 12px; border-radius: 6px; }
        .shelf-badge { font-size: 0.85rem; font-weight: 600; padding: 4px 10px; }
    </style>
</head>
<body>

<div class="container main-container pb-5" style="max-width: 1600px;">
    <div class="card table-card shadow-sm border-0 bg-white rounded-3">
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
        
        <div class="card-body p-3">
            <div class="table-responsive">
                <table id="filesTable" class="table table-striped table-hover align-middle m-0 w-100">
                    <thead class="table-dark small text-uppercase">
                        <tr>
                            <th style="width: 22%;">Client Name</th>
                            <th style="width: 20%;">Branch / Domain Region</th>
                            <th class="text-center" style="width: 10%;">Division</th>
                            <th class="text-center" style="width: 10%;">Cabinet</th>
                            <th class="text-center" style="width: 10%;">Shelf</th>
                            <th class="text-center" style="width: 8%;">File No.</th>
                            <th class="text-center" style="width: 10%;">Last Sanction Date</th>
                            <th>Remarks</th>
                            <th class="text-center" style="width: 10%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="small">
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
        "order": [], 
        "columns": [
            { "data": "client" },
            { "data": "branch_code" },
            { "data": "division", "className": "text-center" },
            { "data": "cabinet_name", "className": "text-center" },
            { "data": "shelf_name", "className": "text-center" },
            { "data": "file_no", "className": "text-center font-monospace fw-bold text-primary" },
            { "data": "last_sanction_date", "className": "text-center" },
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