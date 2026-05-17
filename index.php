<?php 
session_start();
// 1. DATABASE CONNECTION MUST BE FIRST
include 'db.php'; 

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
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="style/style.css">

</head>
<body>
<!-- <img src="images/fsib_logo.jpg" class="watermark-bg" alt="FSIB Watermark" style="width: 500px;"> -->
<nav class="navbar navbar-expand-lg navbar-dark shadow mb-4">
    <div class="container main-container">
        <a class="navbar-brand d-flex align-items-center fw-bold" href="index.php">
    <!-- Logo added here -->
    <img src="images/fsib_logo.jpg" alt="FSIB Logo" class="me-3 rounded bg-white p-1" style="height: 45px; width: auto;">
    <span>FILE MANAGEMENT SYSTEM</span>
</a>
        <div class="ms-auto d-flex align-items-center">
    
    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
        <a href="trash.php" class="nav-link px-3 position-relative text-white me-3" title="Recycle Bin">
            <i class="fas fa-trash-alt text-warning"></i>
            <?php
                // Count items currently in the trash
                $t_sql = "SELECT COUNT(*) as total FROM office_files WHERE is_deleted = 1";
                $t_res = $conn->query($t_sql);
                $t_count = ($t_res) ? $t_res->fetch_assoc()['total'] : 0;
                
                if($t_count > 0): 
            ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem; border: 1px solid white;">
                    <?php echo $t_count; ?>
                </span>
            <?php endif; ?>
        </a>
    <?php endif; ?>

    <span class="text-white me-3 small border-end pe-3">
        <i class="fas fa-user-circle me-1"></i> 
        <span class="opacity-75"><?php echo strtoupper(htmlspecialchars($_SESSION['role'])); ?>:</span> 
        <strong class="text-warning"><?php echo strtoupper(htmlspecialchars($_SESSION['username'])); ?></strong>
    </span>

    <a href="logout.php" class="btn btn-sm btn-logout shadow-sm">
        <i class="fas fa-sign-out-alt me-1"></i> LOGOUT
    </a>
</div>
</nav>

<div class="container main-container pb-5">
    <div class="card table-card">
        <div class="card-header bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
    <img src="images/fsib_logo.jpg" alt="Logo" class="me-2" style="height: 30px;">
    <h5 class="mb-0 fw-bold text-primary">File Records</h5>
</div>
                <div class="d-flex gap-2">
                    <?php if($isAdmin): ?>
                        <a href="add_user.php" class="btn btn-primary btn-sm"><i class="fas fa-user-plus"></i> New User</a>
                        <a href="manage_users.php" class="btn btn-dark btn-sm"><i class="fas fa-users"></i> User List</a>
                    <?php endif; ?>
                    <a href="sanction_report.php" class="btn btn-info btn-sm"><i class="fas fa-chart-line"></i> Sanction Report</a>
                    <a href="add_record.php" class="btn btn-success btn-sm"><i class="fas fa-plus"></i> New File Record</a>
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
                            <th>Created Date</th>
                            <th>Remarks</th>
                            <th>Attachments</th>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#filesTable').DataTable({
        "pageLength": 10,
        "responsive": true,
        // ADDED: Sort by the first column (0) in descending order (desc)
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