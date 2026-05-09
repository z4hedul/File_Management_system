<?php 
session_start(); 

if(!isset($_SESSION['loggedin'])) { 
    header("location: login.php"); 
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

    <style>
        body { background-color: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .main-container { max-width: 1300px; margin: 0 auto; }

        .table-card { 
            border-radius: 12px; 
            border: none; 
            box-shadow: 0 8px 30px rgba(0,0,0,0.12); 
            background: #fff;
            overflow: hidden;
        }

        /* --- FORCED CELL COLORING --- */
        
        /* 1. Header Cells */
        #filesTable thead th {
            background-color: #1e3a8a !important; 
            color: #ffffff !important;
            text-transform: uppercase;
            font-size: 0.85rem;
            padding: 15px !important;
            border: 1px solid #1e40af !important; /* Defined cell borders */
            text-align: center;
        }

        /* 2. Odd Row Cells (White) */
        #filesTable tbody tr:nth-child(odd) td {
            background-color: #ffffff !important;
            border: 1px solid #e2e8f0 !important; /* Subtle grid lines */
        }

        /* 3. Even Row Cells (Smart Soft Blue) */
        #filesTable tbody tr:nth-child(even) td {
            background-color: #f4f3f7 !important; 
            border: 1px solid #e2e8f0 !important;
        }

        /* 4. Hover Effect on Cells */
        #filesTable tbody tr:hover td {
            background-color: #b9f0f1 !important; /* Slightly darker on hover */
            cursor: pointer;
        }

        /* 5. Specific Column Styling (Optional) */
        #filesTable td:nth-child(3) { /* Client Name Column */
            font-weight: 600;
            color: #1e3a8a;
            text-transform: capitalize;
        }

        .navbar { background: #1e3a8a; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark shadow mb-4">
    <div class="container main-container">
        <a class="navbar-brand fw-bold" href="index.php"><i class="fas fa-folder-open me-2"></i>FILE MANAGEMENT SYSTEM</a>
        <div class="ms-auto d-flex align-items-center">
            <span class="text-white me-3 small">Admin: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></span>
            <a href="logout.php" class="btn btn-sm btn-outline-light">Logout</a>
        </div>
    </div>
</nav>

<div class="container main-container pb-5">
    <div class="card table-card">
        <div class="card-header bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold text-primary">File Records</h5>
                <div class="d-flex gap-2">
                    <?php if($isAdmin): ?>
                        <a href="add_user.php" class="btn btn-primary btn-sm"><i class="fas fa-user-plus"></i> New User</a>
                        <a href="manage_users.php" class="btn btn-dark btn-sm"><i class="fas fa-users"></i> User List</a>
                    <?php endif; ?>
                    <a href="add_record.php" class="btn btn-success btn-sm"><i class="fas fa-plus"></i> New File Record</a>
                </div>
            </div>
        </div>
        
        <div class="card-body">
            <div class="table-responsive">
                <table id="filesTable" class="table align-middle w-100 m-0">
                    <thead>
                        <tr>
                            <th>Branch</th>
                            <th>Division</th>
                            <th>Client Name</th>
                            <th>Cabinet</th>
                            <th>Shelf</th>
                            <th>File No.</th>
                            <th>Sanction Date</th>
                            <th>Remarks</th>
                            <th>Attachments</th>
                            <?php if($isAdmin): ?>
                                <th class="text-center">Actions</th>
                            <?php endif; ?>
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
        "language": {
            "search": "Quick Filter:",
            "lengthMenu": "Show _MENU_"
        }
    });
});
</script>
<?php include 'footer.php'; ?>
</body>
</html>