<?php
// ============================================================
// DEFINE THE buildStageUrl FUNCTION FIRST (BEFORE ANYTHING ELSE)
// ============================================================
function buildStageUrl($stage_name, $from, $to) {
    $url = "index.php?status_view=" . urlencode($stage_name) . "&show_queue=1";
    if (!empty($from) && !empty($to)) {
        $url .= "&from_date=" . urlencode($from) . "&to_date=" . urlencode($to);
    }
    return $url;
}
// ============================================================

session_start();
include 'db.php';
include 'header.php';
$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// Verify authentication state boundary
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

/**
 * FETCH FULL NAME DYNAMICALLY
 */
$display_name = $_SESSION['username'] ?? 'User';
if (isset($_SESSION['username'])) {
    $user_stmt = $conn->prepare("SELECT full_name FROM users WHERE username = ?");
    $user_stmt->bind_param("s", $_SESSION['username']);
    $user_stmt->execute();
    $user_res = $user_stmt->get_result()->fetch_assoc();
    
    if (!empty($user_res['full_name'])) {
        $display_name = $user_res['full_name'];
        $_SESSION['full_name'] = $user_res['full_name'];
    }
    $user_stmt->close();
}

// EXTRACT DATE FILTERS FOR PIPELINE TRACKING SYSTEM
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';

// Establish date filtering conditions based on sanction_date
$date_condition = ""; 
if (!empty($from_date) && !empty($to_date)) {
    $safe_from = $conn->real_escape_string($from_date);
    $safe_to = $conn->real_escape_string($to_date);
    $date_condition = " AND ff.sanction_date BETWEEN '{$safe_from} 00:00:00' AND '{$safe_to} 23:59:59' ";
}

// Get the active filtered status from the URL click action
$active_filter = $_GET['status_view'] ?? '';
$show_queue = isset($_GET['show_queue']) && $_GET['show_queue'] == '1';

// 1. Total Proposals Assigned (Unique files tracked)
$q_assigned = "SELECT COUNT(DISTINCT pa.proposal_ref) AS total FROM proposal_assignments pa
               JOIN office_files o ON pa.file_id = o.id
               WHERE o.is_deleted = 0";
$res_assigned = $conn->query($q_assigned);
$total_assigned = $res_assigned ? $res_assigned->fetch_assoc()['total'] : 0;

// 2. Fetch aggregate status pipelines totals matrix block
$stages = [
    'proposal_received' => "Proposal Received",
    'pending'           => "Pending",
    'in_prep'           => "Proposal In Preparation",
    'office_note'       => "Office Note",
    'committee_memo'    => "Committee Memo",
    'committee_minutes' => "Committee Minutes",
    'board_memo'        => "Board Memo",
    'board_minutes'     => "Board Minutes",
    'declined'          => "Declined",
    'approved'          => "Approval/Sanction",
];

$counts = [];
foreach ($stages as $key => $status_value) {
    if ($key === 'approved') {
        if (!empty($date_condition)) {
            $sql = "SELECT COUNT(DISTINCT pa.proposal_ref) AS total 
                    FROM proposal_assignments pa 
                    JOIN office_files o ON pa.file_id = o.id 
                    LEFT JOIN file_facilities ff ON o.id = ff.file_record_id
                    WHERE pa.proposal_status = ? AND o.is_deleted = 0 {$date_condition}";
            $stmt = $conn->prepare($sql);
        } else {
            $sql = "SELECT COUNT(DISTINCT pa.proposal_ref) AS total 
                    FROM proposal_assignments pa 
                    JOIN office_files o ON pa.file_id = o.id 
                    INNER JOIN file_facilities ff ON o.id = ff.file_record_id
                    WHERE pa.proposal_status = ? AND o.is_deleted = 0 
                    AND ff.sanction_date >= DATE_SUB(CURDATE(), INTERVAL 15 DAY)";
            $stmt = $conn->prepare($sql);
        }
    } else {
        $sql = "SELECT COUNT(DISTINCT pa.proposal_ref) AS total 
                FROM proposal_assignments pa 
                JOIN office_files o ON pa.file_id = o.id 
                WHERE pa.proposal_status = ? AND o.is_deleted = 0";
        $stmt = $conn->prepare($sql);
    }
    
    $stmt->bind_param('s', $status_value);
    $stmt->execute();
    $counts[$key] = intval($stmt->get_result()->fetch_assoc()['total']);
    $stmt->close(); 
}

$total_processing = $counts['proposal_received'] + $counts['in_prep'] + $counts['office_note'] + $counts['committee_memo'] + $counts['committee_minutes'] + $counts['board_memo'] + $counts['board_minutes'];

// FIX #1: Only fetch matching proposals when show_queue is true - FIXED GROUPING
$matching_proposals = [];
if ($show_queue && !empty($active_filter)) {
    // Fetch all assignments first without grouping
    $list_sql = "SELECT 
                    pa.id,
                    pa.proposal_ref,
                    pa.proposal_status,
                    pa.remarks,
                    pa.assigned_date AS assigned_time,
                    o.client AS client_name,
                    o.file_no AS file_no,
                    o.branch_name AS branch_name,
                    o.division,
                    o.id AS file_rec_id,
                    u.full_name AS officer_name,
                    pa.proposal_type,
                    pa.proposal_amount,
                    ff.sanction_letter_ref_no,
                    ff.sanction_date,
                    ff.board_meet_no,
                    ff.comm_meet_no
                 FROM proposal_assignments pa
                 JOIN office_files o ON pa.file_id = o.id
                 LEFT JOIN users u ON pa.user_id = u.id
                 LEFT JOIN file_facilities ff ON o.id = ff.file_record_id
                 WHERE o.is_deleted = 0
                 AND pa.proposal_status = ?";
    
    if ($active_filter === 'Approval/Sanction' && empty($from_date) && empty($to_date)) {
        $list_sql .= " AND ff.sanction_date >= DATE_SUB(CURDATE(), INTERVAL 15 DAY)";
    } elseif ($active_filter === 'Approval/Sanction' && !empty($from_date) && !empty($to_date)) {
        $list_sql .= " AND ff.sanction_date BETWEEN '{$safe_from} 00:00:00' AND '{$safe_to} 23:59:59'";
    }
    
    $list_sql .= " ORDER BY pa.proposal_ref, pa.assigned_date DESC";
    
    $list_stmt = $conn->prepare($list_sql);
    $list_stmt->bind_param('s', $active_filter);
    $list_stmt->execute();
    $all_results = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $list_stmt->close();
    
    // Group by proposal_ref in PHP
    $grouped_temp = [];
    foreach ($all_results as $row) {
        $ref = $row['proposal_ref'];
        if (!isset($grouped_temp[$ref])) {
            $grouped_temp[$ref] = [
                'proposal_ref' => $row['proposal_ref'],
                'proposal_status' => $row['proposal_status'],
                'assigned_time' => $row['assigned_time'],
                'client_name' => $row['client_name'],
                'file_no' => $row['file_no'],
                'branch_name' => $row['branch_name'],
                'division' => $row['division'],
                'file_rec_id' => $row['file_rec_id'],
                'officer_name' => $row['officer_name'],
                'sanction_letter_ref_no' => $row['sanction_letter_ref_no'],
                'sanction_date' => $row['sanction_date'],
                'board_meet_no' => $row['board_meet_no'],
                'comm_meet_no' => $row['comm_meet_no'],
                'remarks' => $row['remarks'],
                'facilities' => []
            ];
        }
        // Add facility to the proposal
        $grouped_temp[$ref]['facilities'][] = [
            'type' => $row['proposal_type'],
            'amount' => floatval($row['proposal_amount'])
        ];
    }
    
    // Convert to indexed array
    $matching_proposals = array_values($grouped_temp);
}

// FIX #2: Improved workforce query to correctly show active tasks and client names
$unified_workforce_res = $conn->query("
    SELECT 
        u.id AS user_id,
        u.full_name,
        u.username,
        u.employee_id,
        g.group_name,
        g.leader_id,
        (SELECT leader.full_name FROM users leader WHERE leader.id = g.leader_id) AS leader_name,
        COUNT(DISTINCT CASE 
            WHEN o.is_deleted = 0 
            AND pa.proposal_status NOT IN ('Approval/Sanction', 'Declined') 
            AND pa.proposal_status IS NOT NULL
            THEN pa.proposal_ref 
        END) AS active_count,
        GROUP_CONCAT(DISTINCT 
            CASE 
                WHEN o.is_deleted = 0 
                AND pa.proposal_status NOT IN ('Approval/Sanction', 'Declined')
                AND o.client IS NOT NULL
                THEN CONCAT(o.client, '|', pa.proposal_status)
            END 
            ORDER BY pa.assigned_date DESC
            SEPARATOR '||'
        ) AS client_with_status
    FROM users u
    INNER JOIN user_groups g ON u.group_id = g.id
    LEFT JOIN proposal_assignments pa ON u.id = pa.user_id
    LEFT JOIN office_files o ON pa.file_id = o.id AND o.is_deleted = 0
    WHERE u.is_locked = 0 OR u.is_locked IS NULL
    GROUP BY u.id, u.full_name, u.username, u.employee_id, g.group_name, g.leader_id
    ORDER BY g.group_name ASC, active_count DESC, u.full_name ASC
");

$workforce_hierarchy = [];
if ($unified_workforce_res && $unified_workforce_res->num_rows > 0) {
    while ($row = $unified_workforce_res->fetch_assoc()) {
        $g_name = $row['group_name'];
        if (!isset($workforce_hierarchy[$g_name])) {
            $workforce_hierarchy[$g_name] = [
                'leader' => $row['leader_name'] ?? 'None Assigned',
                'leader_id' => $row['leader_id'],
                'roster' => []
            ];
        }
        $client_list = [];
        if (!empty($row['client_with_status'])) {
            $items = explode('||', $row['client_with_status']);
            foreach ($items as $item) {
                $parts = explode('|', $item);
                if (count($parts) >= 2 && !empty($parts[0])) {
                    $client_list[] = [
                        'name' => $parts[0],
                        'status' => $parts[1]
                    ];
                } elseif (!empty($item)) {
                    $client_list[] = [
                        'name' => $item,
                        'status' => 'Unknown'
                    ];
                }
            }
        }
        $row['client_details'] = $client_list;
        $workforce_hierarchy[$g_name]['roster'][] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Management Dashboard</title>
    <link class="subStyle" rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
    :root {
        --primary-color: #2c3e50;
        --secondary-color: #34495e;
        --success-color: #27ae60;
        --danger-color: #e74c3c;
        --warning-color: #f39c12;
        --info-color: #3498db;
        --light-bg: #f8f9fa;
        --dark-bg: #2c3e50;
        --border-radius: 12px;
        --box-shadow: 0 2px 4px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.03);
        --box-shadow-hover: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.02);
    }
    
    body {
        background-color: #f0f2f5 !important;
        font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
    }
    
    /* Admin Toolbar - Professional Design */
    .admin-toolbar-wrapper {
        margin-bottom: 1.75rem;
    }
    
    .admin-toolbar-card {
        background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
        border-radius: var(--border-radius);
        box-shadow: var(--box-shadow);
        overflow: hidden;
    }
    
    .admin-toolbar-header {
        background: rgba(0, 0, 0, 0.15);
        padding: 10px 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 0.8px;
        color: #ffd700;
        text-transform: uppercase;
    }
    
    .admin-toolbar-body {
        padding: 14px 20px;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
    }
    
    .admin-toolbar-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 7px 16px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s ease;
        background: rgba(255, 255, 255, 0.12);
        color: #fff;
        border: 1px solid rgba(255, 255, 255, 0.15);
    }
    
    .admin-toolbar-btn i {
        font-size: 13px;
    }
    
    .admin-toolbar-btn:hover {
        transform: translateY(-1px);
        text-decoration: none;
        background: rgba(255, 255, 255, 0.2);
        color: #fff;
    }
    
    .admin-btn-primary { background: linear-gradient(135deg, #0d6efd, #0b5ed7); }
    .admin-btn-info { background: linear-gradient(135deg, #0dcaf0, #0bb5d8); color: #000; }
    .admin-btn-purple { background: linear-gradient(135deg, #6f42c1, #5e37a6); }
    .admin-btn-success { background: linear-gradient(135deg, #198754, #157347); }
    .admin-btn-warning { background: linear-gradient(135deg, #ffc107, #e0a800); color: #000; }
    
    /* Metric Cards - Professional Design */
    .metric-card {
        border: none;
        border-radius: var(--border-radius);
        background: #fff;
        transition: all 0.25s ease;
        box-shadow: var(--box-shadow);
    }
    
    .metric-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--box-shadow-hover);
    }
    
    .metric-primary { border-left: 4px solid var(--primary-color) !important; }
    .metric-warning { border-left: 4px solid var(--warning-color) !important; }
    .metric-success { border-left: 4px solid var(--success-color) !important; }
    .metric-danger { border-left: 4px solid var(--danger-color) !important; }
    
    .icon-shape-primary {
        background: linear-gradient(135deg, #e3f2fd, #bbdefb);
        color: var(--primary-color);
    }
    
    .icon-shape-warning {
        background: linear-gradient(135deg, #fff3e0, #ffe0b2);
        color: var(--warning-color);
    }
    
    .icon-shape-success {
        background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
        color: var(--success-color);
    }
    
    .icon-shape-danger {
        background: linear-gradient(135deg, #ffebee, #ffcdd2);
        color: var(--danger-color);
    }
    
    /* Date Filter Card */
    .date-filter-card {
        background: #fff;
        border-radius: var(--border-radius);
        padding: 8px;
        box-shadow: var(--box-shadow);
    }
    
    /* Professional Stage Cards */
    .stages-grid {
        display: grid;
        grid-template-columns: repeat(10, 1fr);
        gap: 12px;
    }
    
    .stage-card {
        background: #fff;
        border-radius: 16px;
        padding: 16px 8px;
        text-align: center;
        transition: all 0.3s ease;
        border: 2px solid #e9ecef;
        cursor: pointer;
        text-decoration: none;
        display: block;
    }
    
    .stage-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.15);
    }
    
    .stage-card.active {
        border-color: var(--primary-color);
        background: linear-gradient(135deg, #f8f9fa 0%, #fff 100%);
        box-shadow: 0 4px 12px rgba(44, 62, 80, 0.15);
    }
    
    .stage-icon {
        width: 48px;
        height: 48px;
        margin: 0 auto 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 24px;
        font-size: 1.3rem;
        transition: all 0.3s ease;
    }
    
    .stage-count {
        font-size: 1.6rem;
        font-weight: 800;
        line-height: 1.2;
        margin-bottom: 5px;
        color: #2c3e50;
    }
    
    .stage-label {
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #6c757d;
    }
    
    /* Stage Color Variants */
    .stage-primary .stage-icon { background: rgba(44, 62, 80, 0.1); color: #2c3e50; }
    .stage-info .stage-icon { background: rgba(52, 152, 219, 0.1); color: #3498db; }
    .stage-warning .stage-icon { background: rgba(243, 156, 18, 0.1); color: #f39c12; }
    .stage-purple .stage-icon { background: rgba(111, 66, 193, 0.1); color: #6f42c1; }
    .stage-orange .stage-icon { background: rgba(253, 126, 20, 0.1); color: #fd7e14; }
    .stage-teal .stage-icon { background: rgba(32, 201, 151, 0.1); color: #20c997; }
    .stage-success .stage-icon { background: rgba(39, 174, 96, 0.1); color: #27ae60; }
    .stage-dark .stage-icon { background: rgba(52, 58, 64, 0.1); color: #343a40; }
    .stage-danger .stage-icon { background: rgba(231, 76, 60, 0.1); color: #e74c3c; }
    
    @media (max-width: 1200px) {
        .stages-grid { grid-template-columns: repeat(5, 1fr); }
    }
    
    @media (max-width: 768px) {
        .stages-grid { grid-template-columns: repeat(3, 1fr); }
        .stage-count { font-size: 1.3rem; }
        .stage-icon { width: 40px; height: 40px; font-size: 1.1rem; }
    }
    
    .group-card {
        border-radius: 10px;
        overflow: hidden;
        margin-bottom: 20px;
        border: 1px solid #e9ecef;
        box-shadow: var(--box-shadow);
    }
    
    .group-card-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        padding: 10px 16px;
        color: #fff;
    }
    </style>
</head>
<body class="bg-light">
<div class="container main-container app-page-wrapper pb-5" style="max-width: 1600px;">
    
    <!-- SYSTEM ADMIN COMPACT TOOLBAR -->
   <?php if ($isAdmin): ?>
<div class="admin-toolbar-wrapper mb-4">
    <div class="admin-toolbar-card">
        <div class="admin-toolbar-header">
            <i class="fas fa-shield-alt me-2"></i>
            <span>Administrator Control Panel</span>
        </div>
        <div class="admin-toolbar-body">
            <a href="add_user.php" class="admin-toolbar-btn admin-btn-primary">
                <i class="fas fa-user-plus"></i>
                <span>Add User</span>
            </a>
            <a href="manage_users.php" class="admin-toolbar-btn admin-btn-primary">
                <i class="fas fa-users-cog"></i>
                <span>Manage Users</span>
            </a>
            <a href="manage_groups.php" class="admin-toolbar-btn admin-btn-purple">
                <i class="fas fa-object-group"></i>
                <span>Manage Groups</span>
            </a>
            <a href="manage_facilities.php" class="admin-toolbar-btn admin-btn-success">
                <i class="fas fa-building"></i>
                <span>Facility Types</span>
            </a>
            <a href="user_performance_report.php" class="admin-toolbar-btn admin-btn-info">
                <i class="fas fa-chart-line"></i>
                <span>Performance Report</span>
            </a>
            <a href="run_backup.php" class="admin-toolbar-btn admin-btn-warning">
                <i class="fas fa-database"></i>
                <span>Backup Database</span>
            </a>
            <a href="client_profile.php" class="admin-toolbar-btn admin-btn-warning">
                <i class="fas fa-database"></i>
                <span>Client Profile</span>
            </a>
            <a href="cabinet_files.php" class="admin-toolbar-btn admin-btn-warning">
                <i class="fas fa-database"></i>
                <span>cabinet_files</span>
            </a>
        </div>
    </div>
</div>
<?php endif; ?>





   <div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card metric-card metric-primary shadow-sm p-3 h-100">
            <div class="d-flex align-items-center">
                <div class="icon-shape icon-shape-primary me-3" style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-folder-open fa-lg"></i>
                </div>
                <div>
                    <div class="text-secondary small text-uppercase fw-semibold mb-1">Total Assigned</div>
                    <h3 class="fw-bold mb-0 text-dark"><?php echo number_format($total_assigned); ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card metric-card metric-warning shadow-sm p-3 h-100">
            <div class="d-flex align-items-center">
                <div class="icon-shape icon-shape-warning me-3" style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-spinner fa-pulse fa-lg"></i>
                </div>
                <div>
                    <div class="text-secondary small text-uppercase fw-semibold mb-1">Under Process</div>
                    <h3 class="fw-bold mb-0 text-dark"><?php echo number_format($total_processing); ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card metric-card metric-success shadow-sm p-3 h-100">
            <div class="d-flex align-items-center">
                <div class="icon-shape icon-shape-success me-3" style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-check-circle fa-lg"></i>
                </div>
                <div>
                    <div class="text-secondary small text-uppercase fw-semibold mb-1">Approved / Sanction</div>
                    <h3 class="fw-bold mb-0 text-dark"><?php echo number_format($counts['approved']); ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card metric-card metric-danger shadow-sm p-3 h-100">
            <div class="d-flex align-items-center">
                <div class="icon-shape icon-shape-danger me-3" style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-times-circle fa-lg"></i>
                </div>
                <div>
                    <div class="text-secondary small text-uppercase fw-semibold mb-1">Declined / Rejected</div>
                    <h3 class="fw-bold mb-0 text-dark"><?php echo number_format($counts['declined']); ?></h3>
                </div>
            </div>
        </div>
    </div>
</div>

    <div class="card shadow-sm border-0 mb-5 bg-white rounded-3">
    <div class="card-header bg-white border-0 pt-4 px-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <h5 class="fw-semibold text-secondary m-0">
                <i class="fas fa-chart-line me-2 text-primary"></i> Proposal Tracker
                <?php if(empty($from_date)): ?>
                    <span class="small text-muted fw-normal fs-6">(Last 15 days - Sanction Date)</span>
                <?php else: ?>
                    <span class="badge bg-info text-white font-monospace">Custom Range Applied</span>
                <?php endif; ?>
            </h5>
        
        <div class="date-filter-card">
                <form method="GET" class="d-flex gap-2 align-items-center m-0">
                <?php if(!empty($active_filter)): ?>
                    <input type="hidden" name="status_view" value="<?= htmlspecialchars($active_filter) ?>">
                <?php endif; ?>
                <div class="col-auto">
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($from_date) ?>" required>
                </div>
                <div class="col-auto text-muted font-monospace text-uppercase" style="font-size:10px;">to</div>
                <div class="col-auto">
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($to_date) ?>" required>
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-dark px-2"><i class="fas fa-filter"></i></button>
                    <?php if(!empty($from_date) || !empty($to_date)): ?>
                        <a href="index.php" class="btn btn-sm btn-outline-secondary px-2"><i class="fas fa-undo"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

  <div class="card-body p-4">
    <div class="stages-grid">
        <!-- On Board -->
        <a href="<?= buildStageUrl('Proposal In Preparation', $from_date, $to_date) ?>" class="stage-card stage-primary <?php echo ($active_filter === 'Proposal In Preparation') ? 'active' : ''; ?>">
            <div class="stage-icon"><i class="fas fa-rocket"></i></div>
            <div class="stage-count"><?php echo $counts['in_prep']; ?></div>
            <div class="stage-label">On Board</div>
        </a>
        
        <!-- Prop Received -->
        <a href="<?= buildStageUrl('Proposal Received', $from_date, $to_date) ?>" class="stage-card stage-info <?php echo ($active_filter === 'Proposal Received') ? 'active' : ''; ?>">
            <div class="stage-icon"><i class="fas fa-inbox"></i></div>
            <div class="stage-count"><?php echo $counts['proposal_received']; ?></div>
            <div class="stage-label">Received</div>
        </a>
        
        <!-- Pending -->
        <a href="<?= buildStageUrl('Pending', $from_date, $to_date) ?>" class="stage-card stage-warning <?php echo ($active_filter === 'Pending') ? 'active' : ''; ?>">
            <div class="stage-icon"><i class="fas fa-hourglass-half"></i></div>
            <div class="stage-count"><?php echo $counts['pending']; ?></div>
            <div class="stage-label">Pending</div>
        </a>
        
        <!-- Committee Memo -->
        <a href="<?= buildStageUrl('Committee Memo', $from_date, $to_date) ?>" class="stage-card stage-purple <?php echo ($active_filter === 'Committee Memo') ? 'active' : ''; ?>">
            <div class="stage-icon"><i class="fas fa-file-alt"></i></div>
            <div class="stage-count"><?php echo $counts['committee_memo']; ?></div>
            <div class="stage-label">Comm. Memo</div>
        </a>
        
        <!-- Committee Minutes -->
        <a href="<?= buildStageUrl('Committee Minutes', $from_date, $to_date) ?>" class="stage-card stage-orange <?php echo ($active_filter === 'Committee Minutes') ? 'active' : ''; ?>">
            <div class="stage-icon"><i class="fas fa-clock"></i></div>
            <div class="stage-count"><?php echo $counts['committee_minutes']; ?></div>
            <div class="stage-label">Comm. Min</div>
        </a>
        
        <!-- Office Note -->
        <a href="<?= buildStageUrl('Office Note', $from_date, $to_date) ?>" class="stage-card stage-teal <?php echo ($active_filter === 'Office Note') ? 'active' : ''; ?>">
            <div class="stage-icon"><i class="fas fa-sticky-note"></i></div>
            <div class="stage-count"><?php echo $counts['office_note']; ?></div>
            <div class="stage-label">Office Note</div>
        </a>
        
        <!-- Board Memo -->
        <a href="<?= buildStageUrl('Board Memo', $from_date, $to_date) ?>" class="stage-card stage-success <?php echo ($active_filter === 'Board Memo') ? 'active' : ''; ?>">
            <div class="stage-icon"><i class="fas fa-chalkboard"></i></div>
            <div class="stage-count"><?php echo $counts['board_memo']; ?></div>
            <div class="stage-label">Board Memo</div>
        </a>
        
        <!-- Board Minutes -->
        <a href="<?= buildStageUrl('Board Minutes', $from_date, $to_date) ?>" class="stage-card stage-dark <?php echo ($active_filter === 'Board Minutes') ? 'active' : ''; ?>">
            <div class="stage-icon"><i class="fas fa-calendar-check"></i></div>
            <div class="stage-count"><?php echo $counts['board_minutes']; ?></div>
            <div class="stage-label">Board Min</div>
        </a>
        
        <!-- Declined -->
        <a href="<?= buildStageUrl('Declined', $from_date, $to_date) ?>" class="stage-card stage-danger <?php echo ($active_filter === 'Declined') ? 'active' : ''; ?>">
            <div class="stage-icon"><i class="fas fa-times-circle"></i></div>
            <div class="stage-count"><?php echo $counts['declined']; ?></div>
            <div class="stage-label">Declined</div>
        </a>
        
        <!-- Approved -->
        <a href="<?= buildStageUrl('Approval/Sanction', $from_date, $to_date) ?>" class="stage-card stage-success <?php echo ($active_filter === 'Approval/Sanction') ? 'active' : ''; ?>">
            <div class="stage-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stage-count"><?php echo $counts['approved']; ?></div>
            <div class="stage-label">Approved</div>
        </a>
    </div>
</div>
</div>

    <?php
    $queue_title = !empty($active_filter) ? $active_filter : 'All Proposal Assignments';
    $queue_close_url = 'index.php';
    if (!empty($from_date) && !empty($to_date)) {
        $queue_close_url .= '?from_date=' . urlencode($from_date) . '&to_date=' . urlencode($to_date);
    }
    ?>

    <!-- Update Queue section to only show when a filter is selected -->
<?php if ($show_queue && !empty($active_filter)): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
        <h5 class="mb-0 fw-bold text-dark">
            <i class="fas fa-list text-primary me-2"></i> File Queue: <span class="text-primary"><?php echo htmlspecialchars($active_filter); ?></span> 
            <span class="badge bg-secondary ms-2 small" style="font-size:0.8rem;"><?php echo count($matching_proposals); ?> Proposals Found</span>
        </h5>
        <a href="index.php" class="btn btn-sm btn-light text-muted"><i class="fas fa-times me-1"></i> Close Queue</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light text-uppercase small" style="font-size:0.75rem;">
                <tr>
                    <th class="ps-4" style="width: 18%;">Assigned Date &amp; Time</th>
                    <th style="width: 27%;">Client &amp; Profile Context</th>
                    <th style="width: 28%;">Facility Details / Sanction Info</th>
                    <th style="width: 15%;">Reference String</th>
                    <th style="width: 12%;">Officer Assigned</th>
                </tr>
            </thead>
            <tbody class="small">
                <?php if (!empty($matching_proposals)): ?>
                    <?php foreach ($matching_proposals as $proposal): 
                        $total_proposal_amount = 0;
                        foreach ($proposal['facilities'] as $fac) {
                            $total_proposal_amount += $fac['amount'];
                        }
                    ?>
                        <tr>
                            <td class="ps-4 fw-semibold text-secondary" style="vertical-align: middle;">
                                <i class="far fa-clock text-primary me-1"></i>
                                <?php echo (!empty($proposal['assigned_time'])) ? date('d-M-Y h:i A', strtotime($proposal['assigned_time'])) : 'Timestamp Not Set'; ?>
                            </td>
                            <td style="vertical-align: middle;">
                                <div class="fw-bold client-header mb-1">
                                    <a href="more_details.php?id=<?php echo intval($proposal['file_rec_id']); ?>" class="text-decoration-none text-dark hover-primary">
                                        <i class="fas fa-folder-open text-warning me-2"></i><?php echo htmlspecialchars($proposal['client_name'] ?? 'N/A'); ?>
                                    </a>
                                </div>
                                <div class="text-muted small font-monospace d-flex flex-wrap gap-2">
                                    <?php if ($active_filter === 'Approval/Sanction' && !empty($proposal['sanction_letter_ref_no'])): ?>
                                        <span><i class="fas fa-file-signature text-success me-1"></i>Sanction Ref: <strong class="text-success"><?php echo htmlspecialchars($proposal['sanction_letter_ref_no']); ?></strong></span>
                                        <?php if (!empty($proposal['sanction_date'])): ?>
                                            <span class="mx-1">|</span>
                                            <span><i class="fas fa-calendar-check text-success me-1"></i>Date: <strong><?php echo date('d-M-Y', strtotime($proposal['sanction_date'])); ?></strong></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span><i class="fas fa-code-branch text-info me-1"></i>Branch: <strong><?php echo htmlspecialchars($proposal['branch_name'] ?? 'N/A'); ?></strong></span>
                                        <span class="mx-1">|</span>
                                        <span><i class="fas fa-layer-group text-purple me-1"></i>Div: <strong class="text-purple"><?php echo htmlspecialchars($proposal['division'] ?? 'N/A'); ?></strong></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($active_filter === 'Approval/Sanction' && (!empty($proposal['board_meet_no']) || !empty($proposal['comm_meet_no']))): ?>
                                    <div class="text-muted small font-monospace mt-1">
                                        <?php if (!empty($proposal['board_meet_no'])): ?>
                                            <span><i class="fas fa-chalkboard-user me-1"></i>Board: <?php echo htmlspecialchars($proposal['board_meet_no'] ?? 'N/A'); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($proposal['comm_meet_no'])): ?>
                                            <?php if (!empty($proposal['board_meet_no'])): ?> <span class="mx-1">|</span> <?php endif; ?>
                                            <span><i class="fas fa-users me-1"></i>Committee: <?php echo htmlspecialchars($proposal['comm_meet_no'] ?? 'N/A'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="vertical-align: middle;">
                                <?php if ($active_filter === 'Approval/Sanction'): ?>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold text-success">
                                            <i class="fas fa-check-circle text-success me-1"></i>
                                            Sanctioned Amount: BDT <?php echo number_format($total_proposal_amount, 2); ?>
                                        </span>
                                        <div class="mt-2">
                                            <?php foreach ($proposal['facilities'] as $fac): ?>
                                                <div class="small text-muted mt-1">
                                                    <i class="fas fa-tag me-1"></i>
                                                    <?php echo htmlspecialchars($fac['type']); ?>: BDT <?php echo number_format($fac['amount'], 2); ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex flex-column">
                                        <div class="fw-semibold text-secondary mb-2">
                                            <i class="fas fa-layer-group text-info me-1"></i>
                                            Total Amount: BDT <?php echo number_format($total_proposal_amount, 2); ?>
                                        </div>
                                        <div class="small text-muted">
                                            <?php foreach ($proposal['facilities'] as $fac): ?>
                                                <div class="mt-1">
                                                    <i class="fas fa-tag me-1"></i>
                                                    <?php echo htmlspecialchars($fac['type']); ?>: BDT <?php echo number_format($fac['amount'], 2); ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="vertical-align: middle;">
                                <?php if ($active_filter === 'Approval/Sanction' && !empty($proposal['sanction_letter_ref_no'])): ?>
                                    <span class="badge bg-success font-monospace text-white px-2 py-1 shadow-sm" style="font-size: 0.75rem;">
                                        <i class="fas fa-file-contract me-1"></i><?php echo htmlspecialchars($proposal['sanction_letter_ref_no']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-dark font-monospace text-warning px-2 py-1 shadow-sm" style="font-size: 0.75rem;">
                                        <i class="fas fa-hashtag me-1"></i><?php echo htmlspecialchars($proposal['proposal_ref'] ?? 'N/A'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="vertical-align: middle;">
                                <div class="d-flex align-items-center">
                                    <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                        <i class="fas fa-user-tie text-secondary" style="font-size: 0.8rem;"></i>
                                    </div>
                                    <div>
                                        <span class="fw-medium text-dark"><?php echo !empty($proposal['officer_name']) ? htmlspecialchars($proposal['officer_name']) : 'System Core'; ?></span>
                                        <div class="small text-muted">
                                            <?php echo ($active_filter === 'Approval/Sanction') ? 'Sanctioned' : 'Assigned'; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            <i class="fas fa-inbox fa-2x mb-2 d-block text-opacity-25"></i> 
                            No proposal assignments found for <?php echo htmlspecialchars($active_filter); ?>.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

    <!-- Workforce Panel -->
<div class="card shadow-sm border-0 mb-4 bg-white">
    <div class="card-header bg-dark text-white fw-bold d-flex justify-content-between align-items-center py-2" style="font-size:14px; cursor: pointer;" onclick="toggleWorkforcePanel()">
        <span><i class="fas fa-network-wired text-warning me-2"></i> Officers & Current Assignments</span>
        <span id="panel-toggle-icon"><i class="fas fa-chevron-down"></i> Click to Expand</span>
    </div>
    <div id="workforce-panel-body" class="card-body bg-light-subtle d-none border-bottom">
        <?php if (!empty($workforce_hierarchy)): ?>
            <?php foreach ($workforce_hierarchy as $group_title => $g_data): ?>
                <div class="card mb-4 border shadow-sm border-light bg-white">
                    <div class="card-header group-card-header py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span class="fw-bold text-white fs-6">
                            <i class="fas fa-users text-warning me-2"></i> Group: <?= htmlspecialchars($group_title) ?>
                        </span>
                        <span class="badge bg-light text-dark font-monospace border shadow-sm px-3 py-1.5" style="font-size:12px;">
                            <i class="fas fa-user-shield me-1 text-primary"></i> Team Leader: <?= htmlspecialchars($g_data['leader']) ?>
                        </span>
                    </div>
                   
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover mb-0 small align-middle m-0">
                            <thead class="table-light text-muted" style="font-size:12px;">
                                <tr>
                                    <th class="ps-3" style="width:30%;">Dealing Officer</th>
                                    <th class="text-center" style="width:15%;">Active Load</th>
                                    <th style="width:55%;">Currently Assigned Clients</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $has_members = false;
                                foreach ($g_data['roster'] as $staff):
                                    if ($staff['full_name'] === $g_data['leader']) {
                                        continue;
                                    }
                                    $has_members = true;
                                ?>
                                    <tr>
                                        <td class="ps-3 py-2">
                                            <div class="fw-bold text-dark"><?= htmlspecialchars(!empty($staff['full_name']) ? $staff['full_name'] : $staff['username']) ?></div>
                                            <div class="text-muted font-monospace" style="font-size:10px;">ID: <?= htmlspecialchars($staff['employee_id'] ?? 'N/A') ?></div>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($staff['active_count'] > 0): ?>
                                                <span class="badge bg-warning text-dark rounded-pill px-3 py-1 font-monospace fw-bold">
                                                    <i class="fas fa-tasks me-1"></i><?= $staff['active_count'] ?> Files
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success text-white rounded-pill px-3 py-1 font-monospace fw-bold">
                                                    <i class="fas fa-check-circle me-1"></i>0 Files
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            if (!empty($staff['client_details'])) {
                                                foreach($staff['client_details'] as $client_item) {
                                                    $status_class = ($client_item['status'] == 'Pending') ? 'warning' : 'info';
                                                    echo '<div class="d-inline-block me-1 my-1">';
                                                    echo '<span class="badge bg-' . $status_class . '-subtle text-' . $status_class . ' border border-' . $status_class . '-subtle px-2 py-1 shadow-sm" style="font-size:11px;">';
                                                    echo '<i class="fas fa-building me-1"></i>' . htmlspecialchars($client_item['name']);
                                                    echo '<span class="ms-1 badge bg-light text-dark">' . htmlspecialchars($client_item['status']) . '</span>';
                                                    echo '</span></div>';
                                                }
                                            } else {
                                                echo '<span class="text-success font-monospace small"><i class="fas fa-circle-check text-success me-1"></i>Ready for Assignment</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-center text-muted py-5 bg-white border rounded shadow-sm">
                <i class="fas fa-users-slash fa-3x mb-3 text-black-50 opacity-25"></i>
                <p class="mb-0 fw-semibold">No operational group parameters or workforce logs found.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>

<?php include 'footer.php'; ?>
<script>
function toggleWorkforcePanel() {
    const pBody = document.getElementById('workforce-panel-body');
    const pIcon = document.getElementById('panel-toggle-icon');
    if (pBody.classList.contains('d-none')) {
        pBody.classList.remove('d-none');
        pIcon.innerHTML = '<i class="fas fa-chevron-up"></i> Click to Collapse';
    } else {
        pBody.classList.add('d-none');
        pIcon.innerHTML = '<i class="fas fa-chevron-down"></i> Click to Expand';
    }
}
</script>
</body>
</html>