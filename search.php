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

    <div class="row g-3 mb-4 row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 justify-content-start">
        
        <div class="col">
            <div class="card h-100 shadow-sm dashboard-card">
                <div class="card-body text-center p-3 d-flex flex-column justify-content-between">
                    <div>
                        <div class="icon-shape bg-success-subtle text-success mb-2">
                            <i class="fas fa-file-medical fa-lg"></i>
                        </div>
                        <h6 class="card-title fw-bold mb-1 small text-dark">New File</h6>
                        <p class="text-muted mb-3" style="font-size: 0.75rem;">Create database record.</p>
                    </div>
                    <a href="add_record.php" class="btn btn-sm btn-success w-100 fw-bold rounded-3">Add Record</a>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="card h-100 shadow-sm dashboard-card">
                <div class="card-body text-center p-3 d-flex flex-column justify-content-between">
                    <div>
                        <div class="icon-shape bg-warning-subtle text-warning mb-2">
                            <i class="fas fa-file-signature fa-lg"></i>
                        </div>
                        <h6 class="card-title fw-bold mb-1 small text-dark">Proposal</h6>
                        <p class="text-muted mb-3" style="font-size: 0.75rem;">Manage case proposals.</p>
                    </div>
                    <a href="proposal_assignments.php" class="btn btn-sm btn-warning text-dark w-100 fw-bold rounded-3">Open</a>
                </div>
            </div>
        </div>

        <div class="col">
            <div class="card h-100 shadow-sm dashboard-card">
                <div class="card-body text-center p-3 d-flex flex-column justify-content-between">
                    <div>
                        <div class="icon-shape bg-info-subtle text-info mb-2">
                            <i class="fas fa-chart-pie fa-lg"></i>
                        </div>
                        <h6 class="card-title fw-bold mb-1 small text-dark">Reports</h6>
                        <p class="text-muted mb-3" style="font-size: 0.75rem;">Sanction metrics engine.</p>
                    </div>
                    <a href="sanction_report.php" class="btn btn-sm btn-info text-white w-100 fw-bold rounded-3">View Report</a>
                </div>
            </div>
        </div>

        <?php if($isAdmin): ?>
            <div class="col">
                <div class="card h-100 shadow-sm dashboard-card">
                    <div class="card-body text-center p-3 d-flex flex-column justify-content-between">
                        <div>
                            <div class="icon-shape bg-danger-subtle text-danger mb-2">
                                <i class="fas fa-user-plus fa-lg"></i>
                            </div>
                            <h6 class="card-title fw-bold mb-1 small text-dark">New User</h6>
                            <p class="text-muted mb-3" style="font-size: 0.75rem;">Add legal staff profile.</p>
                        </div>
                        <a href="add_user.php" class="btn btn-sm btn-danger w-100 fw-bold rounded-3">Create</a>
                    </div>
                </div>
            </div>

            <div class="col">
                <div class="card h-100 shadow-sm dashboard-card">
                    <div class="card-body text-center p-3 d-flex flex-column justify-content-between">
                        <div>
                            <div class="icon-shape bg-dark-subtle text-dark mb-2">
                                <i class="fas fa-users-cog fa-lg"></i>
                            </div>
                            <h6 class="card-title fw-bold mb-1 small text-dark">User List</h6>
                            <p class="text-muted mb-3" style="font-size: 0.75rem;">Manage corporate accounts.</p>
                        </div>
                        <a href="manage_users.php" class="btn btn-sm btn-dark w-100 fw-bold rounded-3">Manage</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
    
    <div class="card table-card shadow-sm">
        <div class="card-header bg-white py-3 border-bottom">
            <div class="d-flex align-items-center">
                <img src="images/fsib_logo.jpg" alt="Logo" class="me-2" style="height: 30px;">
                <h5 class="mb-0 fw-bold text-primary">Master File Records Pipeline</h5>
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

<script src="style/js/jquery-3.6.0.min.js"></script>
<script src="style/js/jquery.dataTables.min.js"></script>
<script src="style/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#filesTable').DataTable({
        "pageLength": 10,
        "responsive": true,
        "order": [[ 0, "desc" ]], 
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