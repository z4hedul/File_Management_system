<?php
// Safety check: ensure session is active before outputting tags
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';

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
    
    <link rel="stylesheet" href="styles/bootstrap.min.css">
    <link rel="stylesheet" href="style/all.min.css">
    <link rel="stylesheet" href="style/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="style/style.css">
    
    <style>
        /* CRITICAL: Spacing fixes for Fixed elements */
        body {
            padding-top: 85px;    /* Prevents main content from slipping under top navbar */
            padding-bottom: 160px; /* Prevents main content from hiding behind bottom footer */
            background-color: #f8f9fa;
        }
        
        .navbar.fixed-top {
            z-index: 1030;
        }

        /* Dashboard Styles */
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

<nav class="navbar navbar-expand-lg navbar-dark shadow fixed-top" style="background-color: #2506d3; border-bottom: 3px solid #0ad8f3;">
    <div class="container main-container">
        <a class="navbar-brand d-flex align-items-center fw-bold" href="index.php">
            <img src="images/fsib_logo.jpg" alt="FSIB Logo" class="me-3 rounded bg-white p-1" style="height: 40px; width: auto;">
            <span style="letter-spacing: 0.5px; font-size: 1.1rem;">FILE MANAGEMENT SYSTEM</span>
        </a>
        <div class="ms-auto d-flex align-items-center">
            <?php if ($isAdmin): ?>
                <a href="trash.php" class="nav-link px-3 position-relative text-white me-3" title="Recycle Bin">
                    <i class="fas fa-trash-alt text-warning"></i>
                    <?php
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

            <span class="text-white me-3 small border-end pe-3 d-none d-sm-inline">
                <i class="fas fa-user-circle me-1"></i><strong class="text-warning"> WELCOME </strong> 
                <span class="opacity-75"><?php echo strtoupper(htmlspecialchars($_SESSION['role'])); ?>:</span> 
                <strong class="text-warning"><?php echo strtoupper(htmlspecialchars($_SESSION['username'])); ?></strong>
            </span>
                <a href="logout.php" class="btn btn-sm btn-danger shadow-sm fw-bold" style="font-size: 0.75rem;">
                <i class="fas fa-sign-out-alt me-1"></i> LOGOUT
            </a>
        </div>
    </div>
</nav>
<br><br>