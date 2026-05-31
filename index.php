<?php
session_start();
include 'db.php';

// Verify authentication state boundary
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

// Get the active filtered status from the URL click action
$active_filter = $_GET['status_view'] ?? '';

// 1. Total Proposals Assigned (All active tracked files)
$q_assigned = "SELECT COUNT(*) AS total FROM proposal_assignments pa
               JOIN office_files o ON pa.file_id = o.id
               WHERE o.is_deleted = 0";
$res_assigned = $conn->query($q_assigned);
$total_assigned = $res_assigned ? $res_assigned->fetch_assoc()['total'] : 0;

// 2. Fetch aggregate status pipelines totals matrix block
$stages = [
    'pending'           => "Pending",
    'in_prep'           => "Proposal In Preparation",
    'office_note'       => "Office Note",
    'committee_memo'    => "Committee Memo",
    'committee_minutes' => "Committee Minutes",
    'board_memo'        => "Board Memo",
    'board_minutes'     => "Board Minutes",
    'approved'          => "Approval/Sanction",
    'declined'          => "Declined"
];

$counts = [];
foreach ($stages as $key => $status_value) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM proposal_assignments pa JOIN office_files o ON pa.file_id = o.id WHERE pa.proposal_status = ? AND o.is_deleted = 0");
    $stmt->bind_param('s', $status_value);
    $stmt->execute();
    $counts[$key] = intval($stmt->get_result()->fetch_assoc()['total']);
}

// Under Process tracking aggregate calculation
$total_processing = $counts['in_prep'] + $counts['office_note'] + $counts['committee_memo'] + $counts['committee_minutes'] + $counts['board_memo'] + $counts['board_minutes'];

// 3. Relational Query pulling Officer Full Name from users table
$matching_proposals = [];
if (!empty($active_filter)) {
    // Note: Adjust 'u.full_name' if your users table uses 'u.name' or 'u.username'
    $list_sql = "SELECT 
                    pa.assigned_date AS assigned_time, 
                    u.full_name AS officer_name, 
                    pa.proposal_status AS current_stage,
                    o.client AS client_name, 
                    o.branch_name AS branch, 
                    o.file_no AS file_number,
                    o.id AS file_rec_id
                 FROM proposal_assignments pa
                 JOIN office_files o ON pa.file_id = o.id
                 LEFT JOIN users u ON pa.user_id = u.id
                 WHERE pa.proposal_status = ? AND o.is_deleted = 0
                 ORDER BY pa.assigned_date DESC";
                 
    $list_stmt = $conn->prepare($list_sql);
    $list_stmt->bind_param('s', $active_filter);
    $list_stmt->execute();
    $matching_proposals = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Management Dashboard Matrix</title>
   <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .metric-card { transition: transform 0.2s ease, box-shadow 0.2s ease; border: none; }
        .metric-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1) !important; }
        .metric-card.active-view { box-shadow: 0 0 0 3px #0d6efd !important; }
        .icon-shape { width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; border-radius: 10px; }
        .border-purple { border-top-color: #6f42c1 !important; }
        .text-purple { color: #6f42c1 !important; }
        .client-link { text-decoration: none; color: #212529; transition: color 0.15s ease-in-out; }
        .client-link:hover { color: #0d6efd; text-decoration: underline; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark shadow mb-4">
    <div class="container main-container">
        <a class="navbar-brand d-flex align-items-center fw-bold" href="index.php">
            <img src="images/fsib_logo.jpg" alt="FSIB Logo" class="me-3 rounded bg-white p-1" style="height: 45px; width: auto;">
            <span>FILE MANAGEMENT SYSTEM</span>
        </a>
        <div class="ms-auto d-flex align-items-center">
            <span class="text-white me-3 small border-end pe-3">
                <i class="fas fa-user-circle me-1"></i>
                <span class="opacity-75"><?php echo strtoupper(htmlspecialchars($_SESSION['role'] ?? '')); ?>:</span>
                <strong class="text-warning"><?php echo strtoupper(htmlspecialchars($_SESSION['username'] ?? '')); ?></strong>
            </span>
            <a href="logout.php" class="btn btn-sm btn-logout shadow-sm">
                <i class="fas fa-sign-out-alt me-1"></i> LOGOUT
            </a>
        </div>
    </div>
</nav>

<div class="container main-container pb-5">
    
    <!-- Welcome Header Strip -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-0">System Operation Dashboard</h3>
            <p class="text-muted small mb-0">Welcome back, <span class="text-primary fw-semibold"><?php echo htmlspecialchars(strtoupper($_SESSION['username'] ?? '')); ?></span></p>
        </div>
        <div class="d-flex gap-2">
            <a href="add_record.php" class="btn btn-success shadow-sm"><i class="fas fa-plus me-1"></i> New File Record</a>
            <a href="sanction_report.php" class="btn btn-primary shadow-sm"><i class="fas fa-file-alt me-2"></i> View Full Report</a>
        </div>
    </div>

    <!-- Tier 1: Core Global Pillars Summary Cards -->
    <div class="row g-3 mb-5">
        <div class="col-md-3">
            <div class="card metric-card shadow-sm bg-white p-3 border-start border-primary border-4 h-100">
                <div class="d-flex align-items-center">
                    <div class="icon-shape bg-primary-subtle text-primary me-3"><i class="fas fa-folder-open fa-lg"></i></div>
                    <div>
                        <div class="text-secondary small text-uppercase font-monospace fw-bold">Total Assigned</div>
                        <h3 class="fw-bold mb-0 text-dark"><?php echo number_format($total_assigned); ?></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card metric-card shadow-sm bg-white p-3 border-start border-warning border-4 h-100">
                <div class="d-flex align-items-center">
                    <div class="icon-shape bg-warning-subtle text-warning me-3"><i class="fas fa-spinner fa-spin fa-lg"></i></div>
                    <div>
                        <div class="text-secondary small text-uppercase font-monospace fw-bold">Under Process</div>
                        <h3 class="fw-bold mb-0 text-dark"><?php echo number_format($total_processing); ?></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card metric-card shadow-sm bg-white p-3 border-start border-success border-4 h-100">
                <div class="d-flex align-items-center">
                    <div class="icon-shape bg-success-subtle text-success me-3"><i class="fas fa-check-circle fa-lg"></i></div>
                    <div>
                        <div class="text-secondary small text-uppercase font-monospace fw-bold">Approved / Sanction</div>
                        <h3 class="fw-bold mb-0 text-dark"><?php echo number_format($counts['approved']); ?></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card metric-card shadow-sm bg-white p-3 border-start border-danger border-4 h-100">
                <div class="d-flex align-items-center">
                    <div class="icon-shape bg-danger-subtle text-danger me-3"><i class="fas fa-times-circle fa-lg"></i></div>
                    <div>
                        <div class="text-secondary small text-uppercase font-monospace fw-bold">Declined / Rejected</div>
                        <h3 class="fw-bold mb-0 text-dark"><?php echo number_format($counts['declined']); ?></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tier 2: Granular Lifecycle Tracking Pipeline Components -->
    <h5 class="fw-semibold text-secondary mb-3"><i class="fas fa-layer-group me-2"></i> Detailed Tracking Components <span class="small text-muted fw-normal">(Click view to populate list below)</span></h5>
    
    <!-- First Row: Initial Stages -->
    <div class="row row-cols-1 row-cols-md-4 g-3 mb-3">
        <div class="col">
            <div class="card metric-card shadow-sm h-100 border-top border-secondary border-3 <?php echo ($active_filter === 'Pending') ? 'active-view' : ''; ?>">
                <div class="card-body p-3 d-flex flex-column justify-content-between">
                    <div>
                        <div class="text-muted font-monospace small text-uppercase">Pending/Hold</div>
                        <h4 class="fw-bold mt-2 mb-0"><?php echo $counts['pending']; ?></h4>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <i class="fas fa-pause-circle text-secondary fs-4"></i>
                        <a href="index.php?status_view=Pending" class="btn btn-sm btn-outline-secondary px-2 py-0">View <i class="fas fa-eye ms-1"></i></a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card metric-card shadow-sm h-100 border-top border-primary border-3 <?php echo ($active_filter === 'Proposal In Preparation') ? 'active-view' : ''; ?>">
                <div class="card-body p-3 d-flex flex-column justify-content-between">
                    <div>
                        <div class="text-muted font-monospace small text-uppercase">In Preparation</div>
                        <h4 class="fw-bold mt-2 mb-0"><?php echo $counts['in_prep']; ?></h4>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <i class="fas fa-edit text-primary fs-4"></i>
                        <a href="index.php?status_view=Proposal+In+Preparation" class="btn btn-sm btn-outline-primary px-2 py-0">View <i class="fas fa-eye ms-1"></i></a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card metric-card shadow-sm h-100 border-top border-info border-3 <?php echo ($active_filter === 'Office Note') ? 'active-view' : ''; ?>">
                <div class="card-body p-3 d-flex flex-column justify-content-between">
                    <div>
                        <div class="text-muted font-monospace small text-uppercase">Office Note</div>
                        <h4 class="fw-bold mt-2 mb-0"><?php echo $counts['office_note']; ?></h4>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <i class="far fa-sticky-note text-info fs-4"></i>
                        <a href="index.php?status_view=Office+Note" class="btn btn-sm btn-outline-info px-2 py-0">View <i class="fas fa-eye ms-1"></i></a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card metric-card shadow-sm h-100 border-top border-purple border-3 <?php echo ($active_filter === 'Committee Memo') ? 'active-view' : ''; ?>">
                <div class="card-body p-3 d-flex flex-column justify-content-between">
                    <div>
                        <div class="text-muted font-monospace small text-uppercase">Comm. Memo</div>
                        <h4 class="fw-bold mt-2 mb-0"><?php echo $counts['committee_memo']; ?></h4>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <i class="fas fa-users-cog text-purple fs-4"></i>
                        <a href="index.php?status_view=Committee+Memo" class="btn btn-sm btn-outline-purple px-2 py-0">View <i class="fas fa-eye ms-1"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Second Row: Advanced Stages -->
    <div class="row row-cols-1 row-cols-md-5 g-3 mb-5">
        <div class="col">
            <div class="card metric-card shadow-sm h-100 border-top border-warning border-3 <?php echo ($active_filter === 'Committee Minutes') ? 'active-view' : ''; ?>">
                <div class="card-body p-3 d-flex flex-column justify-content-between">
                    <div>
                        <div class="text-muted font-monospace small text-uppercase">Comm. Minutes</div>
                        <h4 class="fw-bold mt-2 mb-0"><?php echo $counts['committee_minutes']; ?></h4>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <i class="far fa-clock text-warning fs-4"></i>
                        <a href="index.php?status_view=Committee+Minutes" class="btn btn-sm btn-outline-warning px-2 py-0">View <i class="fas fa-eye ms-1"></i></a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card metric-card shadow-sm h-100 border-top border-success border-3 <?php echo ($active_filter === 'Board Memo') ? 'active-view' : ''; ?>">
                <div class="card-body p-3 d-flex flex-column justify-content-between">
                    <div>
                        <div class="text-muted font-monospace small text-uppercase">Board Memo</div>
                        <h4 class="fw-bold mt-2 mb-0"><?php echo $counts['board_memo']; ?></h4>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <i class="fas fa-id-card-alt text-success fs-4"></i>
                        <a href="index.php?status_view=Board+Memo" class="btn btn-sm btn-outline-success px-2 py-0">View <i class="fas fa-eye ms-1"></i></a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card metric-card shadow-sm h-100 border-top border-dark border-3 <?php echo ($active_filter === 'Board Minutes') ? 'active-view' : ''; ?>">
                <div class="card-body p-3 d-flex flex-column justify-content-between">
                    <div>
                        <div class="text-muted font-monospace small text-uppercase">Board Minutes</div>
                        <h4 class="fw-bold mt-2 mb-0"><?php echo $counts['board_minutes']; ?></h4>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <i class="fas fa-file-signature text-dark fs-4"></i>
                        <a href="index.php?status_view=Board+Minutes" class="btn btn-sm btn-outline-dark px-2 py-0">View <i class="fas fa-eye ms-1"></i></a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card metric-card shadow-sm h-100 border-top border-success border-3 <?php echo ($active_filter === 'Approval/Sanction') ? 'active-view' : ''; ?>">
                <div class="card-body p-3 d-flex flex-column justify-content-between">
                    <div>
                        <div class="text-muted font-monospace small text-uppercase">Approved</div>
                        <h4 class="fw-bold mt-2 mb-0"><?php echo $counts['approved']; ?></h4>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <i class="fas fa-check-double text-success fs-4"></i>
                        <a href="index.php?status_view=Approval%2FSanction" class="btn btn-sm btn-outline-success px-2 py-0">View <i class="fas fa-eye ms-1"></i></a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card metric-card shadow-sm h-100 border-top border-danger border-3 <?php echo ($active_filter === 'Declined') ? 'active-view' : ''; ?>">
                <div class="card-body p-3 d-flex flex-column justify-content-between">
                    <div>
                        <div class="text-muted font-monospace small text-uppercase">Declined</div>
                        <h4 class="fw-bold mt-2 mb-0"><?php echo $counts['declined']; ?></h4>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <i class="fas fa-ban text-danger fs-4"></i>
                        <a href="index.php?status_view=Declined" class="btn btn-sm btn-outline-danger px-2 py-0">View <i class="fas fa-eye ms-1"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- DYNAMIC DATA DISPLAY QUEUE TABLE -->
    <?php if (!empty($active_filter)): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                <h5 class="mb-0 fw-bold text-dark">
                    <i class="fas fa-list text-primary me-2"></i> File Queue: <span class="text-primary"><?php echo htmlspecialchars($active_filter); ?></span> 
                    <span class="badge bg-secondary ms-2 small" style="font-size:0.8rem;"><?php echo count($matching_proposals); ?> Found</span>
                </h5>
                <a href="index.php" class="btn btn-sm btn-light text-muted"><i class="fas fa-times me-1"></i> Close Queue</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-uppercase small" style="font-size:0.75rem;">
                        <tr>
                            <th class="ps-4" style="width: 22%;">Assigned Date &amp; Time</th>
                            <th style="width: 43%;">Client &amp; File Details</th>
                            <th style="width: 20%;">Dealing Officer</th>
                            <th style="width: 15%;">Proposal Status</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <?php if (!empty($matching_proposals)): ?>
                            <?php foreach ($matching_proposals as $row): ?>
                                <tr>
                                    <td class="ps-4 fw-semibold text-secondary">
                                        <i class="far fa-clock text-primary me-1"></i> 
                                        <?php echo (!empty($row['assigned_time'])) ? date('d-M-Y h:i A', strtotime($row['assigned_time'])) : 'Timestamp Not Set'; ?>
                                    </td>
                                    <td>
                                        <!-- Client Name is now the View Link to Open Details -->
                                        <div class="fw-bold mb-0" style="font-size:0.95rem;">
                                            <a href="more_details.php?id=<?php echo intval($row['file_rec_id']); ?>" class="client-link" title="Click to view full file records">
                                                <i class="fas fa-folder-open text-primary opacity-75 me-1" style="font-size: 0.85rem;"></i>
                                                <?php echo htmlspecialchars($row['client_name'] ?? 'N/A'); ?>
                                            </a>
                                        </div>
                                        <div class="text-muted d-flex gap-3 mt-1" style="font-size:0.8rem;">
                                            <span><i class="fas fa-code-branch me-1"></i>Branch: <strong><?php echo htmlspecialchars($row['branch'] ?? 'N/A'); ?></strong></span>
                                            <span class="border-start ps-3"><i class="far fa-file-alt me-1"></i>File No: <strong><?php echo htmlspecialchars($row['file_number'] ?? 'N/A'); ?></strong></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-2" style="width:28px; height:28px;">
                                                <i class="fas fa-user-tie text-secondary" style="font-size:0.75rem;"></i>
                                            </div>
                                            <span class="fw-medium text-dark">
                                                <?php echo !empty($row['officer_name']) ? htmlspecialchars($row['officer_name']) : 'System Administrator'; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill font-monospace px-2 py-1">
                                            <?php echo htmlspecialchars($row['current_stage']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block text-opacity-25"></i>
                                    No active files found currently in the "<?php echo htmlspecialchars($active_filter); ?>" status queue.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php include 'footer.php'; ?>
</body>
</html>