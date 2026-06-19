<?php
// Safety check: ensure session is active before outputting tags
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';

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
        /* UNIFIED GLOBAL BODY SETTINGS */
        body {
            padding-top: 85px !important;
            padding-bottom: 160px !important;
            background-color: #f4f7f6 !important;
        }
        
        /* FSIBL BRAND NAVIGATION INTERFACE */
        .fsibl-navbar {
            background: linear-gradient(135deg, #006a4e 0%, #00523c 100%) !important;
            box-shadow: 0 4px 12px rgba(0, 106, 78, 0.15) !important;
            border-bottom: 3px solid #ffc72c !important;
            padding: 0.6rem 1.5rem !important;
        }

        .fsibl-brand-title {
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #ffffff !important;
            text-transform: uppercase;
        }

        .fsibl-brand-subtitle {
            font-size: 0.65rem;
            font-weight: 600;
            color: #ffc72c !important;
            letter-spacing: 1px;
            display: block;
            text-transform: uppercase;
            margin-top: -2px;
        }

        .fsibl-navbar .nav-link {
            color: rgba(255, 255, 255, 0.85) !important;
            font-weight: 600;
            font-size: 0.85rem;
            letter-spacing: 0.3px;
            padding: 0.5rem 1rem !important;
            border-radius: 4px;
            transition: all 0.25s ease-in-out;
        }

        .fsibl-navbar .nav-link:hover, 
        .fsibl-navbar .nav-item.active .nav-link {
            color: #ffffff !important;
            background-color: rgba(255, 255, 255, 0.1) !important;
        }

        .fsibl-user-badge {
            background-color: rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 30px;
            padding: 0.4rem 1.2rem;
            font-size: 0.8rem;
        }

        .fsibl-user-badge .role-tag {
            color: #ffc72c !important;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .fsibl-btn-logout {
            background-color: #e0ae22 !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            font-size: 0.75rem !important;
            border: none !important;
            padding: 0.45rem 1rem !important;
            border-radius: 20px !important;
            transition: all 0.2s ease;
        }

        .fsibl-btn-logout:hover {
            background-color: #e0ae22 !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .app-page-wrapper {
            margin-top: 20px !important;
            display: block;
            position: relative;
        }

        @media (max-width: 991.98px) {
            body { padding-top: 135px !important; }
            .fsibl-navbar { padding: 0.5rem 1rem !important; }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark fixed-top fsibl-navbar">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center me-4" href="index.php">
            <div class="me-3 text-center bg-white rounded-circle d-flex align-items-center justify-content-center overflow-hidden" style="width: 55px; height: 55px; box-shadow: 0 2px 8px rgba(0,0,0,0.15);">
                <img src="images/fsib_logo.png" alt="FSIBL Logo" style="width: 70%; height: 80%; object-fit: cover;" onerror="this.onerror=null; this.parentElement.innerHTML='<i class=\'fas fa-file-invoice text-success\' style=\'font-size: 1.8rem; color: #006a4e !important;\'></i>'">
            </div>
            <div>
                <span class="fsibl-brand-title" style="font-size: 1.4rem;">FSIB PLC</span>
                <span class="fsibl-brand-subtitle" style="font-size: 0.75rem;">File Management System</span>
            </div>
        </a>
        
        <button class="navbar-brand-toggler navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#fsiblMainNavbar" aria-controls="fsiblMainNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon" style="font-size: 0.85rem;"></span>
        </button>

        <div class="collapse navbar-collapse" id="fsiblMainNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-1">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">
                        <i class="fas fa-th-large me-1 small"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="proposal_assignments.php">
                        <i class="fas fa-tasks me-1 small"></i> Tasks & Assignments
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="sanction_report.php">
                        <i class="fas fa-chart-line me-1 small text-warning"></i> Reports
                    </a>
                </li>
                <li class="nav-item ms-lg-2 d-flex align-items-center">
                    <a class="nav-link" href="add_record.php">
                        <i class="fas fa-folder-plus text-warning"></i> 
                        <span>New File</span>
                    </a>
                </li>
                <li class="nav-item ms-lg-2 d-flex align-items-center">
                    <a class="nav-link" href="cabinet_ledger.php">
                        <i class="fas fa-warehouse"></i> 
                        <span>Cabinet Ledger</span>
                    </a>
                </li>
                <li class="nav-item ms-lg-2 d-flex align-items-center">
                    <a class="nav-link text-white rounded-pill px-3 py-1 shadow-sm d-inline-flex align-items-center gap-2" href="search.php" style="background-color: #006a4e; font-size: 13px; font-weight: 500; transition: all 0.2s ease;">
                        <i class="fas fa-search text-warning small"></i> 
                        <span>Search File</span>
                    </a>
                </li>
            </ul>

            <div class="d-flex align-items-center flex-wrap gap-2">
                <?php if ($isAdmin): ?>
                    <a href="trash.php" class="nav-link px-3 position-relative text-white me-2" title="Recycle Bin" style="background-color: rgba(255,255,255,0.08); border-radius: 50%; width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center;">
                        <i class="fas fa-trash-alt text-warning"></i>
                        <?php
                            $t_sql = "SELECT COUNT(*) as total FROM office_files WHERE is_deleted = 1";
                            $t_res = $conn->query($t_sql);
                            $t_count = ($t_res) ? $t_res->fetch_assoc()['total'] : 0;
                            if($t_count > 0): 
                        ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger shadow-sm" style="font-size: 0.6rem; border: 1px solid #006a4e; padding: 0.25em 0.4em;">
                                <?php echo $t_count; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>

                <div class="d-inline-flex align-items-center gap-2 bg-black bg-opacity-25 border border-white border-opacity-10 rounded-pill p-1 pe-3">
                    <div class="text-white small ps-3 py-1 d-none d-sm-inline-block">
                        <i class="far fa-user-circle me-1 opacity-75"></i> 
                        <span class="opacity-75">Welcome</span>
                        <span class="role-tag" style="color: #ffc72c; font-weight: 700; letter-spacing: 0.5px;"><?php echo strtoupper(htmlspecialchars($_SESSION['role'])); ?></span>: 
                        <strong class="text-white"><?php echo strtoupper(htmlspecialchars($_SESSION['username'])); ?></strong>
                    </div>

                    <a href="change_password.php" class="btn p-0 text-white opacity-75 opacity-100-hover" title="Change Password" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; background-color: rgba(255,255,255,0.15);">
                        <i class="fas fa-key" style="font-size: 11px; color: #ffc72c;"></i>
                    </a>
                </div>

                <a href="logout.php" class="btn fsibl-btn-logout d-inline-flex align-items-center gap-1 shadow-sm ms-1">
                    <i class="fas fa-sign-out-alt"></i> <span>LOGOUT</span>
                </a>
            </div>
        </div>
    </div>
</nav>
<br>
<div class="w-100 mb-2"></div>