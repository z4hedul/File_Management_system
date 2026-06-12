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
        .cabinet-badge { font-size: 0.75rem; padding: 6px 10px; border-radius: 6px; }
        .shelf-badge { font-size: 0.75rem; padding: 6px 10px; border-radius: 6px; background-color: #f8f9fa; color: #333; }
    </style>
</head>
<body class="bg-light">

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card dashboard-card shadow-sm p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="mb-0 fw-bold text-dark" style="font-family: 'Segoe UI', system-ui, sans-serif; letter-spacing: -0.3px;">
                            Master File Records
                        </h5>
                        <small class="text-muted d-none d-sm-block" style="font-size: 0.78rem;">Manage and track active office files</small>
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
                <hr class="text-black-50 opacity-10">
                <div class="table-responsive">
                    <table id="filesTable" class="table table-striped table-hover align-middle w-100 border">
                        <thead class="table-dark small">
                            <tr>
                                <th>Client Profile Name</th>
                                <th>Branch Code / Name</th>
                                <th class="text-center" style="width: 10%;">Division</th>
                                <th class="text-center" style="width: 10%;">Cabinet</th>
                                <th class="text-center" style="width: 10%;">Shelf</th>
                                <th class="text-center" style="width: 8%;">File No.</th>
                                <th class="text-center" style="width: 10%;">Last Sanction Date</th>
                                <th class="text-center" style="width: 10%;">file position</th>
                                <th class="text-left" style="width: 20%;">Action</th>
                            </tr>
                        </thead>
                        <tbody class="small">
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="trackerHistoryModal" tabindex="-1" aria-labelledby="trackerHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold" id="trackerHistoryModalLabel"><i class="fas fa-history text-warning me-2"></i>File Movement Telemetry Audit Log</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" style="max-height: 450px; overflow-y: auto;">
                <div id="modalLogContent">
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary spinner-border-sm" role="status"></div>
                        <span class="ms-2 text-muted small">Loading movement logs registry...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-sm btn-secondary font-weight-bold px-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/jquery-3.6.0.min.js"></script>
<script src="assets/js/jquery.dataTables.min.js"></script>
<script src="assets/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    var table = $('#filesTable').DataTable({
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
        ]
    });

    // Dynamic ajax click listener handler routing trigger for logging operations parameters
    $('#filesTable').on('click', '.view-history-log-btn', function() {
        var fileId = $(this).data('id');
        $('#modalLogContent').html('<div class="text-center py-3"><div class="spinner-border text-primary spinner-border-sm"></div><span class="ms-2 text-muted small">Fetching history data logs...</span></div>');
        $('#trackerHistoryModal').modal('show');
        
        $.ajax({
            url: 'fetch_file_history.php',
            type: 'GET',
            data: { file_id: fileId },
            success: function(response) {
                $('#modalLogContent').html(response);
            },
            error: function() {
                $('#modalLogContent').html('<div class="alert alert-danger p-2 small text-center mb-0"><i class="fas fa-exclamation-triangle me-1"></i>Could not sync audit records logs data matrix. Try again.</div>');
            }
        });
    });
});
</script>
<?php include 'footer.php'; ?>
</body>
</html>