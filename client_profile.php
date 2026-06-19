<?php
session_start();
include 'db.php';
include 'header.php';

// Verify authentication
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

// Get client ID from URL
$client_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// If no client ID, show list of clients
if ($client_id <= 0) {
    $clients_query = "SELECT cp.*, b.branch_name 
                      FROM client_profiles cp
                      LEFT JOIN branches b ON cp.branch_id = b.id
                      ORDER BY cp.client_name";
    $clients_result = $conn->query($clients_query);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Client Management</title>
        <link rel="stylesheet" href="assets/css/bootstrap.min.css">
        <link rel="stylesheet" href="assets/css/all.min.css">
        <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">
        <style>
            :root { --fsibl-green: #006a4e; --fsibl-gold: #ffc72c; }
            body { background: #f0f2f5 !important; font-family: 'Segoe UI', system-ui, sans-serif; }
            .page-header {
                background: linear-gradient(135deg, #006a4e 0%, #004d3a 100%);
                padding: 25px 30px;
                border-radius: 12px;
                margin-bottom: 25px;
                box-shadow: 0 4px 20px rgba(0,106,78,0.25);
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 15px;
            }
            .page-header h4 { color: #fff; margin: 0; font-weight: 700; }
            .page-header h4 i { color: #ffc72c; margin-right: 12px; }
            .page-header .subtitle { color: rgba(255,255,255,0.7); font-size: 0.85rem; margin-top: 4px; }
            .btn-add-client {
                background: #ffc72c;
                color: #006a4e;
                border: none;
                padding: 10px 25px;
                border-radius: 8px;
                font-weight: 700;
                transition: all 0.3s ease;
                box-shadow: 0 4px 15px rgba(255,199,44,0.3);
            }
            .btn-add-client:hover { background: #ffd95e; transform: translateY(-2px); color: #006a4e; }
            .card-shadow { border: none; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); overflow: hidden; }
            .card-shadow .card-header { background: #fff; border-bottom: 2px solid #f0f2f5; padding: 18px 24px; font-weight: 600; color: #1a1a2e; }
            .card-shadow .card-header i { color: #006a4e; margin-right: 10px; }
            .table-client { margin-bottom: 0; }
            .table-client thead th { background: #f8f9fa; color: #495057; font-size: 0.7rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; padding: 14px 16px; border-bottom: 2px solid #e9ecef; }
            .table-client tbody td { padding: 14px 16px; vertical-align: middle; border-bottom: 1px solid #f0f2f5; }
            .table-client tbody tr:hover { background: #f8f9fa; cursor: pointer; }
            .table-client .client-name { font-weight: 600; color: #1a1a2e; }
            .table-client .client-code { font-family: 'Courier New', monospace; font-size: 0.85rem; background: #f0f2f5; padding: 2px 10px; border-radius: 12px; display: inline-block; }
            .badge-branch { background: #e8f5e9; color: #2e7d32; padding: 4px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
            .badge-division { padding: 4px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
            .badge-division.investment { background: #e3f2fd; color: #0d47a1; }
            .badge-division.sme { background: #fff3e0; color: #e65100; }
            .badge-division.imrd { background: #f3e5f5; color: #4a148c; }
            .badge-division.default { background: #f5f5f5; color: #616161; }
            .btn-view-client { background: #006a4e; color: #fff; border: none; padding: 5px 16px; border-radius: 6px; font-size: 0.8rem; font-weight: 600; transition: all 0.3s ease; }
            .btn-view-client:hover { background: #004d3a; color: #fff; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,106,78,0.3); }
            .empty-state { text-align: center; padding: 60px 20px; }
            .empty-state i { font-size: 64px; color: #cbd5e1; margin-bottom: 20px; }
            .empty-state h5 { color: #475569; margin-bottom: 10px; }
            .empty-state p { color: #94a3b8; }
            @media (max-width: 768px) {
                .page-header { flex-direction: column; text-align: center; padding: 20px; }
            }
        </style>
    </head>
    <body>
        <div class="container-fluid px-4 py-4">
            <!-- Add this section to your index.php after the admin toolbar -->
<div class="row g-3 mb-4">
    <?php
    // Get dashboard stats
    $stats = [];
    $stats['total_files'] = $conn->query("SELECT COUNT(*) as total FROM office_files WHERE is_deleted = 0")->fetch_assoc()['total'];
    $stats['total_clients'] = $conn->query("SELECT COUNT(*) as total FROM client_profiles")->fetch_assoc()['total'];
    $stats['total_facilities'] = $conn->query("SELECT COUNT(*) as total FROM file_facilities")->fetch_assoc()['total'];
    $stats['total_assignments'] = $conn->query("SELECT COUNT(DISTINCT proposal_ref) as total FROM proposal_assignments")->fetch_assoc()['total'];
    ?>
    <div class="col-md-3">
        <div class="card metric-card metric-primary shadow-sm p-3 h-100">
            <div class="d-flex align-items-center">
                <div class="icon-shape icon-shape-primary me-3" style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #e3f2fd;">
                    <i class="fas fa-folder-open fa-lg text-primary"></i>
                </div>
                <div>
                    <div class="text-secondary small text-uppercase fw-semibold mb-1">Total Files</div>
                    <h3 class="fw-bold mb-0 text-dark"><?php echo number_format($stats['total_files']); ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card metric-card metric-success shadow-sm p-3 h-100">
            <div class="d-flex align-items-center">
                <div class="icon-shape icon-shape-success me-3" style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #e8f5e9;">
                    <i class="fas fa-users fa-lg text-success"></i>
                </div>
                <div>
                    <div class="text-secondary small text-uppercase fw-semibold mb-1">Total Clients</div>
                    <h3 class="fw-bold mb-0 text-dark"><?php echo number_format($stats['total_clients']); ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card metric-card metric-warning shadow-sm p-3 h-100">
            <div class="d-flex align-items-center">
                <div class="icon-shape icon-shape-warning me-3" style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #fff3e0;">
                    <i class="fas fa-handshake fa-lg text-warning"></i>
                </div>
                <div>
                    <div class="text-secondary small text-uppercase fw-semibold mb-1">Total Facilities</div>
                    <h3 class="fw-bold mb-0 text-dark"><?php echo number_format($stats['total_facilities']); ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card metric-card metric-info shadow-sm p-3 h-100">
            <div class="d-flex align-items-center">
                <div class="icon-shape icon-shape-info me-3" style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #e3f2fd;">
                    <i class="fas fa-tasks fa-lg text-info"></i>
                </div>
                <div>
                    <div class="text-secondary small text-uppercase fw-semibold mb-1">Assignments</div>
                    <h3 class="fw-bold mb-0 text-dark"><?php echo number_format($stats['total_assignments']); ?></h3>
                </div>
            </div>
        </div>
    </div>
</div>
            <div class="page-header">
                <div>
                    <h4><i class="fas fa-users"></i> Client Management</h4>
                    <div class="subtitle"><i class="fas fa-folder-open me-1"></i> Manage and view all client profiles</div>
                </div>
                <div class="d-flex gap-2">
                    <a href="add_client.php" class="btn-add-client"><i class="fas fa-plus-circle me-2"></i> Add New Client</a>
                    <a href="index.php" class="btn btn-light"><i class="fas fa-home"></i></a>
                </div>
            </div>
            
            <div class="card card-shadow">
                <div class="card-header">
                    <i class="fas fa-list"></i> Client Directory
                    <span class="badge bg-secondary ms-2"><?php echo $clients_result ? $clients_result->num_rows : 0; ?> Clients</span>
                </div>
                <div class="card-body p-0">
                    <?php if ($clients_result && $clients_result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table id="clientTable" class="table table-client table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 30%;">Client Name</th>
                                        <th style="width: 12%;">Code</th>
                                        <th style="width: 20%;">Branch</th>
                                        <th style="width: 15%;">Division</th>
                                        <th style="width: 15%;">Zone</th>
                                        <th style="width: 8%;" class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($client = $clients_result->fetch_assoc()): ?>
                                        <tr onclick="window.location.href='client_profile.php?id=<?php echo $client['id']; ?>'">
                                            <td>
                                                <div class="client-name">
                                                    <i class="fas fa-user-circle text-primary me-2"></i>
                                                    <?php echo htmlspecialchars($client['client_name']); ?>
                                                </div>
                                            </td>
                                            <td><span class="client-code"><?php echo htmlspecialchars($client['client_code'] ?? 'N/A'); ?></span></td>
                                            <td><span class="badge-branch"><i class="fas fa-building me-1"></i> <?php echo htmlspecialchars($client['branch_name'] ?? 'N/A'); ?></span></td>
                                            <td>
                                                <?php 
                                                    $division = strtolower($client['division'] ?? 'default');
                                                    $class = 'default';
                                                    if ($division == 'investment') $class = 'investment';
                                                    elseif ($division == 'sme') $class = 'sme';
                                                    elseif ($division == 'imrd') $class = 'imrd';
                                                ?>
                                                <span class="badge-division <?php echo $class; ?>"><?php echo htmlspecialchars($client['division'] ?? 'N/A'); ?></span>
                                            </td>
                                            <td><i class="fas fa-map-marker-alt text-muted me-1" style="font-size: 0.7rem;"></i> <?php echo htmlspecialchars($client['zone'] ?? 'N/A'); ?></td>
                                            <td class="text-center">
                                                <a href="client_profile.php?id=<?php echo $client['id']; ?>" class="btn-view-client" onclick="event.stopPropagation();">
                                                    <i class="fas fa-eye me-1"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-user-plus"></i>
                            <h5>No Clients Found</h5>
                            <p>Get started by adding your first client profile.</p>
                            <a href="add_client.php" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Add New Client</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <script src="assets/js/jquery-3.6.0.min.js"></script>
        <script src="assets/js/bootstrap.bundle.min.js"></script>
        <script src="assets/js/jquery.dataTables.min.js"></script>
        <script src="assets/js/dataTables.bootstrap5.min.js"></script>
        <script>
        $(document).ready(function() {
            <?php if ($clients_result && $clients_result->num_rows > 0): ?>
            $('#clientTable').DataTable({
                "pageLength": 10,
                "ordering": true,
                "searching": true,
                "info": true,
                "language": {
                    "search": "Search Clients:",
                    "lengthMenu": "Show _MENU_ clients",
                    "zeroRecords": "No matching clients found",
                    "info": "Showing _START_ to _END_ of _TOTAL_ clients",
                    "infoEmpty": "No clients available",
                    "infoFiltered": "(filtered from _MAX_ total clients)"
                },
                "columnDefs": [{ "orderable": false, "targets": 5 }]
            });
            <?php endif; ?>
        });
        </script>
        <?php include 'footer.php'; ?>
    </body>
    </html>
    <?php
    exit();
}

// ===== FETCH CLIENT DETAILS =====
$client_query = "SELECT 
                    cp.*,
                    b.branch_name,
                    b.branch_code,
                    b.zone as branch_zone
                 FROM client_profiles cp
                 LEFT JOIN branches b ON cp.branch_id = b.id
                 WHERE cp.id = ?";

$stmt = $conn->prepare($client_query);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();

if (!$client) {
    $_SESSION['error'] = "Client not found";
    header('Location: client_profile.php');
    exit();
}


// ===== FETCH OFFICE FILES - Only show files linked to this client =====
$files_query = "SELECT 
                    `of`.id,
                    `of`.file_no,
                    `of`.cabinet_name,
                    `of`.shelf_name,
                    `of`.branch_name,
                    `of`.branch_code,
                    `of`.division,
                    `of`.zone,
                    `of`.remarks,
                    `of`.created_at,
                    `of`.client,
                    `of`.client_id
                FROM office_files `of`
                WHERE `of`.client_id = ? AND `of`.is_deleted = 0
                ORDER BY `of`.file_no ASC";

$stmt = $conn->prepare($files_query);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$office_files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ===== FETCH FACILITIES =====
$facilities_query = "SELECT 
                        ff.*,
                        ft.facility_name,
                        ft.facility_group,
                        fs.security_type,
                        fs.security_value,
                        fs.security_description
                     FROM file_facilities ff
                     JOIN facilities_type ft ON ff.facility_type COLLATE utf8mb4_unicode_ci = ft.facility_name COLLATE utf8mb4_unicode_ci
                     LEFT JOIN facility_securities fs ON ff.id = fs.facility_id
                     LEFT JOIN office_files `of` ON ff.file_record_id = `of`.id
                     WHERE `of`.client_id = ?
                     ORDER BY ff.sanction_date DESC";

$stmt = $conn->prepare($facilities_query);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$facilities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ===== FETCH BRANCHES =====
$branches_query = "SELECT id, branch_code, branch_name, zone FROM branches ORDER BY branch_name";
$branches = $conn->query($branches_query)->fetch_all(MYSQLI_ASSOC);

$page_title = "Client Profile - " . htmlspecialchars($client['client_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        :root { --fsibl-green: #006a4e; --fsibl-gold: #ffc72c; --fsibl-dark: #1a1a2e; }
        body { background: #f0f2f5 !important; font-family: 'Segoe UI', system-ui, sans-serif; }
        
        .profile-header {
            background: linear-gradient(135deg, #006a4e 0%, #004d3a 100%);
            color: white;
            padding: 30px 35px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0,106,78,0.25);
            position: relative;
            overflow: hidden;
        }
        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255,199,44,0.05);
            border-radius: 50%;
        }
        .profile-header .avatar {
            width: 64px;
            height: 64px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            border: 3px solid rgba(255,255,255,0.2);
        }
        .profile-header .client-name { font-size: 1.8rem; font-weight: 700; margin-bottom: 4px; }
        .profile-header .client-meta { color: rgba(255,255,255,0.8); font-size: 0.9rem; }
        .profile-header .client-meta i { color: #ffc72c; margin-right: 6px; }
        .profile-header .action-buttons .btn {
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.15);
            color: #fff;
            transition: all 0.3s ease;
        }
        .profile-header .action-buttons .btn:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-2px);
        }
        
        .info-card {
            background: #fff;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 12px;
            border-left: 4px solid #006a4e;
            transition: all 0.3s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .info-card:hover { transform: translateX(5px); box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .info-card .label { font-size: 0.65rem; text-transform: uppercase; color: #94a3b8; font-weight: 700; letter-spacing: 0.5px; }
        .info-card .value { font-size: 0.95rem; font-weight: 500; color: #1a1a2e; margin-top: 3px; }
        
        .facility-item {
            background: #fff;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 12px;
            border-left: 4px solid #28a745;
            border: 1px solid #e9ecef;
            border-left-width: 4px;
            transition: all 0.3s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .facility-item.non-funded { border-left-color: #dc3545; }
        .facility-item:hover { transform: translateX(5px); box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .badge-funded { background: #28a745; color: #fff; padding: 4px 14px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; }
        .badge-non-funded { background: #dc3545; color: #fff; padding: 4px 14px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; }
        
        .stat-box {
            background: #fff;
            text-align: center;
            padding: 18px;
            border-radius: 10px;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .stat-box:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
        .stat-box .number { font-size: 28px; font-weight: 700; color: #006a4e; }
        .stat-box .label { font-size: 0.7rem; text-transform: uppercase; color: #94a3b8; font-weight: 600; margin-top: 4px; }
        
        .card-custom {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .card-custom .card-header {
            background: #fff;
            border-bottom: 2px solid #f0f2f5;
            padding: 16px 24px;
            font-weight: 600;
        }
        .card-custom .card-header i { color: #006a4e; margin-right: 10px; }
        
        .btn-sm-action { padding: 4px 10px; font-size: 0.75rem; border-radius: 6px; margin: 0 2px; transition: all 0.3s ease; }
        .btn-sm-action:hover { transform: translateY(-2px); }
        
        .breadcrumb-custom { background: transparent; padding: 0; margin-bottom: 20px; }
        .breadcrumb-custom .breadcrumb-item a { color: #006a4e; text-decoration: none; }
        .breadcrumb-custom .breadcrumb-item a:hover { color: #004d3a; text-decoration: underline; }
        .breadcrumb-custom .breadcrumb-item.active { color: #1a1a2e; font-weight: 600; }
        
        .loading-spinner {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .loading-spinner.show { display: flex; }
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #006a4e;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        .toast-container-custom {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 99999;
        }
        .toast-custom {
            padding: 15px 25px;
            border-radius: 10px;
            color: #fff;
            font-weight: 500;
            margin-bottom: 10px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            animation: slideIn 0.5s ease;
            min-width: 300px;
        }
        .toast-custom.success { background: #28a745; }
        .toast-custom.error { background: #dc3545; }
        .toast-custom.info { background: #17a2b8; }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @media (max-width: 768px) {
            .profile-header { padding: 20px; text-align: center; }
            .profile-header .avatar { margin: 0 auto 10px; }
            .profile-header .action-buttons { margin-top: 15px; }
            .stat-box .number { font-size: 22px; }
        }
        @media print {
            .no-print { display: none !important; }
            .profile-header { background: #006a4e !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .badge-funded, .badge-non-funded { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body>

<div class="loading-spinner" id="loadingSpinner"><div class="spinner"></div></div>
<div class="toast-container-custom" id="toastContainer"></div>




<div class="container-fluid px-4 py-3">

    <nav aria-label="breadcrumb" class="breadcrumb-custom">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php"><i class="fas fa-home"></i> Dashboard</a></li>
            <li class="breadcrumb-item"><a href="client_profile.php">Clients</a></li>
            <li class="breadcrumb-item active"><?php echo htmlspecialchars($client['client_name']); ?></li>
        </ol>
    </nav>

    <div class="profile-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="avatar me-3"><i class="fas fa-user-circle"></i></div>
                    <div>
                        <div class="client-name"><?php echo htmlspecialchars($client['client_name']); ?></div>
                        <div class="client-meta">
                            <span class="me-3"><i class="fas fa-code"></i> <?php echo htmlspecialchars($client['client_code'] ?? 'N/A'); ?></span>
                            <?php if ($client['branch_name']): ?>
                                <span class="me-3"><i class="fas fa-building"></i> <?php echo htmlspecialchars($client['branch_name']); ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-calendar-alt"></i> Since <?php echo date('d M Y', strtotime($client['created_at'] ?? 'now')); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <div class="action-buttons">
                    <button class="btn btn-sm" onclick="openEditModal()"><i class="fas fa-edit"></i> Edit</button>
                    <button class="btn btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                    <a href="client_profile.php" class="btn btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-box"><div class="number"><?php echo count($facilities); ?></div><div class="label">Total Facilities</div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-box"><div class="number"><?php echo count($office_files); ?></div><div class="label">Office Files</div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-box">
                <div class="number"><?php 
                    $funded = array_filter($facilities, function($f) { return $f['facility_group'] == 'Funded'; });
                    echo count($funded);
                ?></div>
                <div class="label">Funded</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-box">
                <div class="number"><?php 
                    $total_amount = array_sum(array_column($facilities, 'amount'));
                    echo number_format($total_amount, 0);
                ?></div>
                <div class="label">Total (BDT)</div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-4">
            <div class="card card-custom mb-4">
                <div class="card-header"><i class="fas fa-info-circle"></i> Basic Information</div>
                <div class="card-body">
                    <div class="info-card"><div class="label">Client Name</div><div class="value"><?php echo htmlspecialchars($client['client_name']); ?></div></div>
                    <div class="info-card"><div class="label">Client Code</div><div class="value"><?php echo htmlspecialchars($client['client_code'] ?? 'N/A'); ?></div></div>
                    <div class="info-card"><div class="label">Contact Person</div><div class="value"><?php echo htmlspecialchars($client['contact_person'] ?? 'N/A'); ?></div></div>
                    <div class="info-card"><div class="label">Phone</div><div class="value"><?php echo htmlspecialchars($client['phone'] ?? 'N/A'); ?></div></div>
                    <div class="info-card"><div class="label">Email</div><div class="value"><?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?></div></div>
                </div>
            </div>

            <div class="card card-custom mb-4">
                <div class="card-header"><i class="fas fa-building"></i> Branch Information</div>
                <div class="card-body">
                    <div class="info-card"><div class="label">Branch Name</div><div class="value"><?php echo htmlspecialchars($client['branch_name'] ?? 'N/A'); ?></div></div>
                    <div class="info-card"><div class="label">Branch Code</div><div class="value"><?php echo htmlspecialchars($client['branch_code'] ?? 'N/A'); ?></div></div>
                    <div class="info-card"><div class="label">Zone</div><div class="value"><?php echo htmlspecialchars($client['zone'] ?? 'N/A'); ?></div></div>
                    <div class="info-card"><div class="label">Division</div><div class="value"><?php echo htmlspecialchars($client['division'] ?? 'N/A'); ?></div></div>
                    <div class="info-card"><div class="label">Address</div><div class="value"><?php echo nl2br(htmlspecialchars($client['address'] ?? 'N/A')); ?></div></div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <!-- Facilities Section -->
           <div class="card card-custom mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-handshake"></i> Facilities</span>
        <button class="btn btn-sm btn-primary" onclick="addFacility(<?php echo $client['id']; ?>)">
            <i class="fas fa-plus"></i> Add Facility
        </button>
    </div>
                <div class="card-body">
                    <?php if (empty($facilities)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="fas fa-inbox" style="font-size: 48px;"></i>
                            <p class="mt-3">No facilities found</p>
                            <button class="btn btn-sm btn-primary" onclick="addFacility(<?php echo $client['id']; ?>)">
                                <i class="fas fa-plus"></i> Add First Facility
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($facilities as $facility): ?>
                            <div class="facility-item <?php echo strtolower(str_replace(' ', '-', $facility['facility_group'])); ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="d-flex align-items-center mb-1">
                                            <h6 class="mb-0 me-2"><strong><?php echo htmlspecialchars($facility['facility_name']); ?></strong></h6>
                                            <span class="<?php echo $facility['facility_group'] == 'Funded' ? 'badge-funded' : 'badge-non-funded'; ?>">
                                                <?php echo htmlspecialchars($facility['facility_group']); ?>
                                            </span>
                                        </div>
                                        <p class="mb-1">
                                            <strong>Amount:</strong> BDT <?php echo number_format($facility['amount'], 2); ?>
                                            <?php if ($facility['sanction_date']): ?>
                                                <span class="ms-3"><strong>Sanction:</strong> <?php echo date('d-m-Y', strtotime($facility['sanction_date'])); ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($facility['sanction_letter_ref_no']): ?>
                                            <small class="text-muted d-block"><i class="fas fa-file-alt"></i> Ref: <?php echo htmlspecialchars($facility['sanction_letter_ref_no']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($facility['security_type']): ?>
                                            <div class="mt-1">
                                                <span class="badge bg-info">Security: <?php echo htmlspecialchars($facility['security_type']); ?></span>
                                                <span class="badge bg-secondary">BDT <?php echo number_format($facility['security_value'], 2); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <a href="more_details.php?id=<?php echo $facility['id']; ?>" class="btn btn-sm btn-outline-primary btn-sm-action"><i class="fas fa-eye"></i></a>
                                        <a href="edit_facility.php?id=<?php echo $facility['id']; ?>" class="btn btn-sm btn-outline-success btn-sm-action"><i class="fas fa-edit"></i></a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

<!-- Cabinet/Office Files Section -->
<div class="card card-custom">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-archive"></i> Cabinet & Files</span>
        <div class="d-flex gap-2">
            <!-- Add New File Button -->
            <a href="add_record.php?client_id=<?php echo $client['id']; ?>" class="btn btn-success btn-sm">
                <i class="fas fa-plus-circle"></i> Add New File
            </a>
            <!-- Assign Existing File Button -->
            <button class="btn btn-warning btn-sm" onclick="openAssignFileModal(<?php echo $client['id']; ?>)">
                <i class="fas fa-link"></i> Assign Existing File
            </button>
        </div>
    </div>
    <div class="card-body">
        <!-- Files List -->
        <?php if (empty($office_files)): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-folder-open" style="font-size: 48px;"></i>
                <p class="mt-3">No files found for this client</p>
                <div class="d-flex gap-2 justify-content-center">
                    <a href="add_record.php?client_id=<?php echo $client['id']; ?>" class="btn btn-sm btn-success">
                        <i class="fas fa-plus"></i> Add New File
                    </a>
                    <button class="btn btn-sm btn-warning" onclick="openAssignFileModal(<?php echo $client['id']; ?>)">
                        <i class="fas fa-link"></i> Assign Existing File
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Client Name</th>
                            <th>Cabinet</th>
                            <th>Shelf</th>
                            <th>Branch</th>
                            <th>Division</th>
                            <th>Zone</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($office_files as $file): ?>
                            <tr>
                              <td><?php echo htmlspecialchars($file['client'] ?? 'N/A' ); ?></td>
                                <td>
                                    <i class="fas fa-archive text-warning"></i>
                                    <?php echo htmlspecialchars($file['cabinet_name'] ?? 'N/A'); ?>
                                </td>
                                <td><?php echo htmlspecialchars($file['shelf_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php 
                                    $branch_display = !empty($file['branch_name']) ? $file['branch_name'] : 'N/A';
                                    echo htmlspecialchars($branch_display); 
                                    ?>
                                    <?php if (!empty($file['branch_code'])): ?>
                                        <small class="text-muted">(<?php echo htmlspecialchars($file['branch_code']); ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $division_display = !empty($file['division']) ? $file['division'] : 'N/A';
                                    $div_badge_class = ($division_display === 'Investment') ? 'bg-success' : (($division_display === 'SME') ? 'bg-warning text-dark' : 'bg-info text-dark');
                                    ?>
                                    <span class="badge <?php echo $div_badge_class; ?>">
                                        <?php echo htmlspecialchars($division_display); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($file['zone'] ?? 'N/A'); ?></td>
                                <td>
                                    <a href='transfer_file.php?id=<?php echo $file['id']; ?>' class='btn btn-sm btn-outline-primary' title='Transfer Division'><i class='fas fa-exchange-alt'></i></a>
                                    <a href="view_details.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-outline-info btn-sm-action" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-outline-primary btn-sm-action" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
              <button class="btn btn-sm btn-outline-danger btn-sm-action" onclick="deleteFile(<?php echo $file['id']; ?>, <?php echo $client['id']; ?>)" title="Remove from Client (File will remain in master list)">
        <i class="fas fa-unlink"></i>
    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Assign Existing File Modal -->

<div class="modal fade" id="assignFileModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-link text-warning"></i> Assign Existing File to Client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    Select an existing file from the dropdown below to assign it to this client.
                    The file will be linked to <strong><?php echo htmlspecialchars($client['client_name']); ?></strong>
                    <br><small class="text-muted">Note: The original file name will be preserved.</small>
                </div>
                
                <form id="assignFileForm">
                    <input type="hidden" id="assign_client_id" value="<?php echo $client['id']; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Existing File <span class="text-danger">*</span></label>
                        <select name="file_id" id="file_select" class="form-control" required onchange="loadFileDetails(this.value)">
                            <option value="">-- Select File --</option>
                            <?php
                          
                    // FETCH UNASSIGNED FILES - Only files with NULL client_id
$unassigned_query = "SELECT 
                        id, 
                        file_no, 
                        client, 
                        cabinet_name, 
                        shelf_name, 
                        branch_name, 
                        branch_code, 
                        division, 
                        zone 
                      FROM office_files 
                      WHERE (client_id IS NULL OR client_id = 0) AND is_deleted = 0
                      ORDER BY client ASC, file_no ASC";

                            $unassigned_result = $conn->query($unassigned_query);
                            if ($unassigned_result && $unassigned_result->num_rows > 0):
                                while ($file = $unassigned_result->fetch_assoc()):
                            ?>
                                <option value="<?php echo $file['id']; ?>"
                                        data-cabinet="<?php echo htmlspecialchars($file['cabinet_name'] ?? ''); ?>"
                                        data-shelf="<?php echo htmlspecialchars($file['shelf_name'] ?? ''); ?>"
                                        data-branch-name="<?php echo htmlspecialchars($file['branch_name'] ?? ''); ?>"
                                        data-branch-code="<?php echo htmlspecialchars($file['branch_code'] ?? ''); ?>"
                                        data-division="<?php echo htmlspecialchars($file['division'] ?? ''); ?>"
                                        data-zone="<?php echo htmlspecialchars($file['zone'] ?? ''); ?>"
                                        data-client="<?php echo htmlspecialchars($file['client'] ?? ''); ?>">
                                    <?php 
                                    // Display the original file name (client column) first
                                    $display_client = !empty($file['client']) ? $file['client'] : 'Unnamed';
                                    echo htmlspecialchars($display_client); 
                                    ?>
                                </option>
                            <?php endwhile; ?>
                            <?php else: ?>
                                <option value="">No unassigned files found</option>
                            <?php endif; ?>
                        </select>
                        <small class="text-muted">Files are listed alphabetically by original file name.</small>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Original File Name</label>
                                <input type="text" id="file_client_name" class="form-control" readonly style="background: #f8f9fa; font-weight: 600; color: #006a4e;">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">File No</label>
                                <input type="text" id="file_file_no" class="form-control" readonly style="background: #f8f9fa; font-weight: 600; color: #006a4e;">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Cabinet</label>
                                <input type="text" id="file_cabinet" class="form-control" readonly style="background: #f8f9fa;">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Shelf</label>
                                <input type="text" id="file_shelf" class="form-control" readonly style="background: #f8f9fa;">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Branch</label>
                                <input type="text" id="file_branch" class="form-control" readonly style="background: #f8f9fa;">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Division</label>
                                <input type="text" id="file_division" class="form-control" readonly style="background: #f8f9fa;">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Zone</label>
                                <input type="text" id="file_zone" class="form-control" readonly style="background: #f8f9fa;">
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="assignFile()">
                    <i class="fas fa-link"></i> Assign File to Client
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Client Modal -->
<div class="modal fade" id="editClientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit text-primary"></i> Edit Client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editClientForm">
                    <input type="hidden" id="edit_client_id" value="<?php echo $client['id']; ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Client Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_client_name" name="client_name" value="<?php echo htmlspecialchars($client['client_name']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Client Code</label>
                                <input type="text" class="form-control" id="edit_client_code" name="client_code" value="<?php echo htmlspecialchars($client['client_code'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" id="edit_address" name="address" rows="2"><?php echo htmlspecialchars($client['address'] ?? ''); ?></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">City</label>
                                <input type="text" class="form-control" id="edit_city" name="city" value="<?php echo htmlspecialchars($client['city'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">State</label>
                                <input type="text" class="form-control" id="edit_state" name="state" value="<?php echo htmlspecialchars($client['state'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Zip Code</label>
                                <input type="text" class="form-control" id="edit_zip_code" name="zip_code" value="<?php echo htmlspecialchars($client['zip_code'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" class="form-control" id="edit_phone" name="phone" value="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" id="edit_email" name="email" value="<?php echo htmlspecialchars($client['email'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Contact Person</label>
                                <input type="text" class="form-control" id="edit_contact_person" name="contact_person" value="<?php echo htmlspecialchars($client['contact_person'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Branch <span class="text-danger">*</span></label>
                                <select class="form-control" id="edit_branch_id" name="branch_id">
                                    <option value="">Select Branch</option>
                                    <?php
                                    // Fetch branches for dropdown
                                    $branches_query = "SELECT id, branch_code, branch_name, zone FROM branches ORDER BY branch_name";
                                    $branches_result = $conn->query($branches_query);
                                    if ($branches_result && $branches_result->num_rows > 0):
                                        while ($branch = $branches_result->fetch_assoc()):
                                    ?>
                                        <option value="<?php echo $branch['id']; ?>" 
                                                data-zone="<?php echo htmlspecialchars(trim($branch['zone'])); ?>"
                                                <?php echo ($client['branch_id'] == $branch['id']) ? 'selected' : ''; ?>>
                                             <?php echo htmlspecialchars($branch['branch_code']); ?>-<?php echo htmlspecialchars($branch['branch_name']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                                <div class="mt-1">
                                    <a href="add_branch.php" target="_blank" class="text-decoration-none small text-primary fw-semibold">
                                        <i class="fas fa-plus-circle me-1"></i>Add branch manually
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Division</label>
                                <input type="text" class="form-control" id="edit_division" name="division" value="<?php echo htmlspecialchars($client['division'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Zone</label>
                                <input type="text" class="form-control" id="edit_zone" name="zone" value="<?php echo htmlspecialchars($client['zone'] ?? ''); ?>" readonly style="background: #f8f9fa;">
                                <small class="text-muted">
                                    <i class="fas fa-magic text-warning"></i> Zone will be auto-filled when you select a branch
                                </small>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="updateClient()">
                    <i class="fas fa-save"></i> Update Client
                </button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/jquery-3.6.0.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>

<script>
// Auto-populate zone when branch is selected in edit modal (using jQuery)
function autoPopulateZoneEdit() {
    const branchSelect = document.getElementById('edit_branch_id');
    const zoneInput = document.getElementById('edit_zone');
    
    if (!branchSelect || !zoneInput) return;
    
    const selectedOption = branchSelect.options[branchSelect.selectedIndex];
    
    if (selectedOption && selectedOption.value !== "") {
        const zone = selectedOption.getAttribute('data-zone') || "";
        zoneInput.value = zone.trim();
        
        // Add visual feedback
        if (zone.trim() !== "") {
            zoneInput.style.borderColor = '#28a745';
            zoneInput.style.backgroundColor = '#f0fff4';
        } else {
            zoneInput.style.borderColor = '#ffc107';
            zoneInput.style.backgroundColor = '#fff3e0';
        }
    } else {
        zoneInput.value = "";
        zoneInput.style.borderColor = '';
        zoneInput.style.backgroundColor = '';
    }
}

// When edit modal opens, trigger auto-populate if branch is already selected
$(document).ready(function() {
    // Bind change event to branch select using jQuery
    $(document).on('change', '#edit_branch_id', function() {
        autoPopulateZoneEdit();
    });
    
    // When edit modal is shown, auto-populate zone
    $(document).on('shown.bs.modal', '#editClientModal', function() {
        autoPopulateZoneEdit();
    });
});

function openEditModal() { 
    $('#editClientModal').modal('show'); 
}

function showToast(message, type = 'success') {
    const toast = $(`<div class="toast-custom ${type}"><i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} me-2"></i>${message}</div>`);
    $('#toastContainer').append(toast);
    setTimeout(() => { toast.fadeOut(500, function() { $(this).remove(); }); }, 3000);
}

function updateClient() {
    const data = {
        id: $('#edit_client_id').val(),
        client_name: $('#edit_client_name').val().trim(),
        client_code: $('#edit_client_code').val().trim(),
        address: $('#edit_address').val().trim(),
        city: $('#edit_city').val().trim(),
        state: $('#edit_state').val().trim(),
        zip_code: $('#edit_zip_code').val().trim(),
        phone: $('#edit_phone').val().trim(),
        email: $('#edit_email').val().trim(),
        contact_person: $('#edit_contact_person').val().trim(),
        branch_id: $('#edit_branch_id').val(),
        division: $('#edit_division').val().trim(),
        zone: $('#edit_zone').val().trim()
    };
    
    if (!data.client_name) { 
        showToast('Client name is required', 'error'); 
        return; 
    }
    
    $('#loadingSpinner').addClass('show');
    
    $.ajax({
        url: 'api/clients.php',
        method: 'PUT',
        data: JSON.stringify(data),
        contentType: 'application/json',
        success: function(response) {
            $('#loadingSpinner').removeClass('show');
            if (response.success) {
                $('#editClientModal').modal('hide');
                showToast('Client updated successfully!', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('Error: ' + response.error, 'error');
            }
        },
        error: function(xhr) {
            $('#loadingSpinner').removeClass('show');
            let errorMsg = 'An error occurred';
            try {
                const response = JSON.parse(xhr.responseText);
                if (response && response.error) {
                    errorMsg = response.error;
                }
            } catch(e) {
                errorMsg = xhr.status + ': ' + xhr.statusText;
            }
            showToast('Error: ' + errorMsg, 'error');
        }
    });
}

function addFacility(clientId) {
    window.location.href = 'add_facility.php?client_id=' + clientId;
}

// Delete file from client profile - Unlink from client
// Delete/Unlink file from client profile
function deleteFile(fileId, clientId) {
    if (!confirm('Are you sure you want to remove this file from the client profile?\n\nThe file will still be available in the master file list and can be reassigned later.')) {
        return;
    }
    $('#loadingSpinner').addClass('show');
    $.ajax({
        url: 'api/files.php?id=' + fileId,
        method: 'DELETE',
        success: function(response) {
            $('#loadingSpinner').removeClass('show');
            if (response.success) {
                showToast('File removed from client profile successfully!', 'success');
                // Remove the file row from the table
                const row = $(`button[onclick="deleteFile(${fileId}, ${clientId})"]`).closest('tr');
                row.fadeOut(500, function() {
                    $(this).remove();
                    // Update file count
                    updateFileCount();
                });
            } else {
                showToast('Error: ' + response.error, 'error');
            }
        },
        error: function(xhr) {
            $('#loadingSpinner').removeClass('show');
            showToast('An error occurred: ' + xhr.responseText, 'error');
        }
    });
}

// Update file count in stats
function updateFileCount() {
    const count = $('#filesContainer table tbody tr').length;
    $('#totalFiles').text(count);
}

// Update file count in stats
function updateFileCount() {
    const count = $('#filesContainer table tbody tr').length;
    $('#totalFiles').text(count);
}
// Open Assign File Modal
function openAssignFileModal(clientId) {
    $('#assign_client_id').val(clientId);
    $('#assignFileModal').modal('show');
    // Reset form
    $('#file_select').val('');
    $('#file_client_name').val('');
    $('#file_file_no').val('');
    $('#file_cabinet').val('');
    $('#file_shelf').val('');
    $('#file_branch').val('');
    $('#file_division').val('');
    $('#file_zone').val('');
}

// Load file details when selected from dropdown
// Load file details when selected from dropdown
function loadFileDetails(fileId) {
    if (!fileId) {
        $('#file_client_name').val('');
        $('#file_file_no').val('');
        $('#file_cabinet').val('');
        $('#file_shelf').val('');
        $('#file_branch').val('');
        $('#file_division').val('');
        $('#file_zone').val('');
        return;
    }
    
    const select = document.getElementById('file_select');
    const selectedOption = select.options[select.selectedIndex];
    
    // Get data from data attributes
    const client = selectedOption.getAttribute('data-client') || '';
    const cabinet = selectedOption.getAttribute('data-cabinet') || '';
    const shelf = selectedOption.getAttribute('data-shelf') || '';
    const branchName = selectedOption.getAttribute('data-branch-name') || '';
    const branchCode = selectedOption.getAttribute('data-branch-code') || '';
    const division = selectedOption.getAttribute('data-division') || '';
    const zone = selectedOption.getAttribute('data-zone') || '';
    const fileNo = selectedOption.getAttribute('data-file-no') || '';  // <-- Get from data attribute
    
    // Populate fields
    $('#file_client_name').val(client);
    $('#file_file_no').val(fileNo);  // <-- Now only the file number
    $('#file_cabinet').val(cabinet);
    $('#file_shelf').val(shelf);
    $('#file_branch').val(branchName + (branchCode ? ' (' + branchCode + ')' : ''));
    $('#file_division').val(division);
    $('#file_zone').val(zone);
}

// Assign file to client
function assignFile() {
    const fileId = $('#file_select').val();
    const clientId = $('#assign_client_id').val();
    
    if (!fileId) {
        alert('Please select a file to assign.');
        return;
    }
    
    if (!confirm('Are you sure you want to assign this file to the client?')) {
        return;
    }
    
    $('#loadingSpinner').addClass('show');
    
    $.ajax({
        url: 'api/assign_file.php',
        method: 'POST',
        data: JSON.stringify({
            file_id: parseInt(fileId),
            client_id: parseInt(clientId)
        }),
        contentType: 'application/json',
        dataType: 'json',
        success: function(response) {
            $('#loadingSpinner').removeClass('show');
            if (response.success) {
                $('#assignFileModal').modal('hide');
                showToast('File assigned successfully! Original file name: ' + response.file_name, 'success');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                showToast('Error: ' + response.error, 'error');
            }
        },
        error: function(xhr) {
            $('#loadingSpinner').removeClass('show');
            showToast('Error: ' + xhr.responseText, 'error');
        }
    });
}

// Assign file to client

function assignFile() {
    const fileId = $('#file_select').val();
    const clientId = $('#assign_client_id').val();
    
    if (!fileId) {
        alert('Please select a file to assign.');
        return;
    }
    
    if (!confirm('Are you sure you want to assign this file to the client?')) {
        return;
    }
    
    $('#loadingSpinner').addClass('show');
    
    // Prepare data
    const postData = {
        file_id: parseInt(fileId),
        client_id: parseInt(clientId)
    };
    
    console.log('Sending data:', postData); // Debug log
    
    $.ajax({
        url: 'api/assign_file.php',
        method: 'POST',
        data: JSON.stringify(postData),
        contentType: 'application/json',
        dataType: 'json',
        success: function(response) {
            $('#loadingSpinner').removeClass('show');
            console.log('Response:', response); // Debug log
            
            if (response.success) {
                $('#assignFileModal').modal('hide');
                showToast('File assigned successfully!', 'success');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                showToast('Error: ' + (response.error || 'Unknown error'), 'error');
            }
        },
        error: function(xhr, status, error) {
            $('#loadingSpinner').removeClass('show');
            console.error('AJAX Error:', xhr, status, error);
            console.error('Response Text:', xhr.responseText);
            
            let errorMsg = 'An error occurred';
            try {
                const response = JSON.parse(xhr.responseText);
                if (response && response.error) {
                    errorMsg = response.error;
                }
            } catch(e) {
                errorMsg = 'Server error: ' + xhr.status + ' ' + xhr.statusText;
            }
            showToast('Error: ' + errorMsg, 'error');
        }
    });
}
</script>

<?php include 'footer.php'; ?>
</body>
</html>