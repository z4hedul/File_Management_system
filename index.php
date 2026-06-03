<?php
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
 * This ensures that even if $_SESSION['full_name'] is not explicitly set 
 * during login, the system will dynamically pull it from the database table.
 */
$display_name = $_SESSION['username'] ?? 'User';
if (isset($_SESSION['username'])) {
    $user_stmt = $conn->prepare("SELECT full_name FROM users WHERE username = ?");
    $user_stmt->bind_param("s", $_SESSION['username']);
    $user_stmt->execute();
    $user_res = $user_stmt->get_result()->fetch_assoc();
    
    if (!empty($user_res['full_name'])) {
        $display_name = $user_res['full_name'];
        $_SESSION['full_name'] = $user_res['full_name']; // Save to session for optimization
    }
    $user_stmt->close();
}

// Get the active filtered status from the URL click action
$active_filter = $_GET['status_view'] ?? '';

// 1. Total Proposals Assigned (Unique files tracked)
$q_assigned = "SELECT COUNT(DISTINCT pa.file_id) AS total FROM proposal_assignments pa
               JOIN office_files o ON pa.file_id = o.id
               WHERE o.is_deleted = 0";
$res_assigned = $conn->query($q_assigned);
$total_assigned = $res_assigned ? $res_assigned->fetch_assoc()['total'] : 0;

// 2. Fetch aggregate status pipelines totals matrix block (Updated to count UNIQUE files)
$stages = [
    'proposal_received' => "Proposal Received",
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
    // Using COUNT(DISTINCT pa.file_id) prevents multiple facilities under a single file from duplicating counts
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT pa.file_id) AS total FROM proposal_assignments pa JOIN office_files o ON pa.file_id = o.id WHERE pa.proposal_status = ? AND o.is_deleted = 0");
    $stmt->bind_param('s', $status_value);
    $stmt->execute();
    $counts[$key] = intval($stmt->get_result()->fetch_assoc()['total']);
    $stmt->close(); 
}

// FIXED: Included $counts['proposal_received'] into your system processing math equation
$total_processing = $counts['proposal_received'] + $counts['in_prep'] + $counts['office_note'] + $counts['committee_memo'] + $counts['committee_minutes'] + $counts['board_memo'] + $counts['board_minutes'];

// 3. Relational Query pulling Officer Full Name from users table
$matching_proposals = [];
if (!empty($active_filter)) {
    $list_sql = "SELECT 
                    pa.assigned_date AS assigned_time, 
                    u.full_name AS officer_name, 
                    pa.proposal_status AS current_stage,
                    pa.proposal_type AS proposal_type,
                    pa.proposal_amount AS proposal_amount,
                    pa.remarks AS assignment_remarks,
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
    $list_stmt->close(); 
}

// =========================================================================
// WORKFORCE METRICS QUERIES FOR LOWER COLLAPSIBLE MATRIX PANEL
// =========================================================================
$assigned_map_res = $conn->query("
    SELECT u.full_name, u.username, u.employee_id, 
           GROUP_CONCAT(DISTINCT o.client SEPARATOR '||') AS client_list, 
           COUNT(DISTINCT pa.file_id) as active_count 
    FROM proposal_assignments pa 
    INNER JOIN users u ON pa.user_id = u.id 
    INNER JOIN office_files o ON pa.file_id = o.id 
    WHERE o.is_deleted = 0 AND pa.proposal_status NOT IN ('Approval/Sanction', 'Declined') 
    GROUP BY u.id 
    ORDER BY active_count DESC
"); 

$unassigned_res = $conn->query("
    SELECT id, full_name, username, employee_id 
    FROM users 
    WHERE id NOT IN (
        SELECT DISTINCT user_id 
        FROM proposal_assignments 
        WHERE user_id > 0 AND proposal_status NOT IN ('Approval/Sanction', 'Declined')
    ) 
    ORDER BY full_name ASC
");
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

        .toolbar-action-btn {
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s ease !important;
            border-radius: 8px !important;
            font-size: 0.85rem !important;
        }
        .toolbar-action-btn:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12) !important;
        }
        .toolbar-action-btn i {
            font-size: 0.95rem;
        }
    </style>
</head>
<body class="bg-light">
<div class="container main-container pb-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="fw-bold text-dark mb-0">File Management Dashboard</h3>
            <p class="text-muted small mb-0">Welcome back, <span class="text-primary fw-semibold"><?php echo htmlspecialchars(ucwords(strtolower($display_name))); ?></span></p>
        </div>
        <div class="card shadow-sm border-0 mb-4 bg-white rounded-3">
            <div class="card-body p-3 d-flex flex-wrap gap-2 justify-content-start align-items-center">
                
                <a href="search.php" class="btn btn-outline-primary d-inline-flex align-items-center gap-2 fw-semibold px-3 py-2 border-2 toolbar-action-btn">
                    <i class="fas fa-search"></i>
                    <span>Search File</span>
                </a>
                <a href="add_record.php" class="btn btn-success d-inline-flex align-items-center gap-2 fw-semibold px-3 py-2 shadow-sm toolbar-action-btn">
                    <i class="fas fa-folder-plus"></i>
                    <span>New File Record</span>
                </a>

                <a href="proposal_assignments.php" class="btn btn-warning text-dark d-inline-flex align-items-center gap-2 fw-semibold px-3 py-2 shadow-sm toolbar-action-btn">
                    <i class="fas fa-file-signature"></i>
                    <span>Proposal Assign</span>
                </a>
                <a href="sanction_report.php" class="btn btn-info text-white d-inline-flex align-items-center gap-2 fw-semibold px-3 py-2 shadow-sm toolbar-action-btn">
                    <i class="fas fa-chart-line"></i>
                    <span>Sanction Report</span>
                </a>

                <?php if ($isAdmin): ?>
                    <div class="vr mx-1 my-auto bg-secondary opacity-25 d-none d-lg-block" style="height: 28px; width: 2px;"></div>
                    <a href="add_user.php" class="btn btn-outline-danger d-inline-flex align-items-center gap-2 fw-semibold px-3 py-2 border-2 toolbar-action-btn">
                        <i class="fas fa-user-plus"></i>
                        <span>Add User</span>
                    </a>
                    <a href="manage_users.php" class="btn btn-dark d-inline-flex align-items-center gap-2 fw-semibold px-3 py-2 shadow-sm toolbar-action-btn">
                        <i class="fas fa-users-cog"></i>
                        <span>Manage Users</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
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

    <h5 class="fw-semibold text-secondary mb-3">
        <i class="fas fa-layer-group me-2"></i> Pipeline Stage Tracker 
        <span class="small text-muted fw-normal">(Select a stage circle to filter the records table)</span>
    </h5>

    <div class="card shadow-sm border-0 mb-5 bg-white rounded-3">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap gap-4 justify-content-between align-items-center text-center">
                
                <a href="index.php?status_view=Proposal+Received" class="pipeline-node-link <?php echo ($active_filter === 'Proposal Received') ? 'node-active' : ''; ?>">
                    <div class="progress-circle-wrapper border-primary-subtle">
                        <div class="progress-circle-inner bg-primary-subtle text-primary">
                            <span class="fw-bold fs-3"><?php echo $counts['proposal_received']; ?></span>
                        </div>
                    </div>
                    <div class="node-label text-primary font-monospace mt-2">Prop Received</div>
                </a>

                <a href="index.php?status_view=Pending" class="pipeline-node-link <?php echo ($active_filter === 'Pending') ? 'node-active' : ''; ?>">
                    <div class="progress-circle-wrapper border-secondary">
                        <div class="progress-circle-inner bg-secondary-subtle text-secondary">
                            <span class="fw-bold fs-3"><?php echo $counts['pending']; ?></span>
                        </div>
                    </div>
                    <div class="node-label text-secondary font-monospace mt-2">Pending</div>
                </a>

                <a href="index.php?status_view=Proposal+In+Preparation" class="pipeline-node-link <?php echo ($active_filter === 'Proposal In Preparation') ? 'node-active' : ''; ?>">
                    <div class="progress-circle-wrapper border-primary">
                        <div class="progress-circle-inner bg-light text-primary">
                            <span class="fw-bold fs-3"><?php echo $counts['in_prep']; ?></span>
                        </div>
                    </div>
                    <div class="node-label text-primary font-monospace mt-2">In Prep</div>
                </a>

                <a href="index.php?status_view=Office+Note" class="pipeline-node-link <?php echo ($active_filter === 'Office Note') ? 'node-active' : ''; ?>">
                    <div class="progress-circle-wrapper border-info">
                        <div class="progress-circle-inner bg-info-subtle text-info">
                            <span class="fw-bold fs-3"><?php echo $counts['office_note']; ?></span>
                        </div>
                    </div>
                    <div class="node-label text-info font-monospace mt-2">Office Note</div>
                </a>

                <a href="index.php?status_view=Committee+Memo" class="pipeline-node-link <?php echo ($active_filter === 'Committee Memo') ? 'node-active' : ''; ?>">
                    <div class="progress-circle-wrapper" style="border-color: #6f42c1 !important;">
                        <div class="progress-circle-inner text-purple" style="background-color: #e0cffc !important; color: #6f42c1 !important;">
                            <span class="fw-bold fs-3"><?php echo $counts['committee_memo']; ?></span>
                        </div>
                    </div>
                    <div class="node-label font-monospace mt-2" style="color: #6f42c1;">Comm. Memo</div>
                </a>

                <a href="index.php?status_view=Committee+Minutes" class="pipeline-node-link <?php echo ($active_filter === 'Committee Minutes') ? 'node-active' : ''; ?>">
                    <div class="progress-circle-wrapper border-warning">
                        <div class="progress-circle-inner bg-warning-subtle text-warning">
                            <span class="fw-bold fs-3"><?php echo $counts['committee_minutes']; ?></span>
                        </div>
                    </div>
                    <div class="node-label text-warning font-monospace mt-2">Comm. Min</div>
                </a>

                <a href="index.php?status_view=Board+Memo" class="pipeline-node-link <?php echo ($active_filter === 'Board Memo') ? 'node-active' : ''; ?>">
                    <div class="progress-circle-wrapper border-success">
                        <div class="progress-circle-inner bg-success-subtle text-success">
                            <span class="fw-bold fs-3"><?php echo $counts['board_memo']; ?></span>
                        </div>
                    </div>
                    <div class="node-label text-success font-monospace mt-2">Board Memo</div>
                </a>

                <a href="index.php?status_view=Board+Minutes" class="pipeline-node-link <?php echo ($active_filter === 'Board Minutes') ? 'node-active' : ''; ?>">
                    <div class="progress-circle-wrapper border-dark">
                        <div class="progress-circle-inner bg-dark-subtle text-dark">
                            <span class="fw-bold fs-3"><?php echo $counts['board_minutes']; ?></span>
                        </div>
                    </div>
                    <div class="node-label text-dark font-monospace mt-2">Board Min</div>
                </a>
                <a href="index.php?status_view=Declined" class="pipeline-node-link <?php echo ($active_filter === 'Declined') ? 'node-active' : ''; ?>">
                    <div class="progress-circle-wrapper border-danger">
                        <div class="progress-circle-inner text-white bg-danger">
                            <span class="fw-bold fs-3"><?php echo $counts['declined']; ?></span>
                        </div>
                    </div>
                    <div class="node-label text-danger font-monospace fw-bold mt-2">Declined</div>
                </a>
                <a href="index.php?status_view=Approval%2FSanction" class="pipeline-node-link <?php echo ($active_filter === 'Approval/Sanction') ? 'node-active' : ''; ?>">
                    <div class="progress-circle-wrapper border-success-double">
                        <div class="progress-circle-inner text-white bg-success">
                            <span class="fw-bold fs-3"><?php echo $counts['approved']; ?></span>
                        </div>
                    </div>
                    <div class="node-label text-success font-monospace fw-bold mt-2">Approved</div>
                </a>
            </div>
        </div>
    </div>

    <style>
        .pipeline-node-link {
            text-decoration: none !important;
            display: flex;
            flex-direction: column;
            align-items: center;
            transition: transform 0.2s ease;
            flex: 1 1 90px;
            min-width: 85px;
        }
        .pipeline-node-link:hover { transform: scale(1.08); }
        .progress-circle-wrapper {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            border: 4px solid #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3px;
            background: #fff;
            transition: box-shadow 0.2s ease;
        }
        .border-success-double { border: 4px double #198754 !important; }
        .progress-circle-inner { width: 100%; height: 100%; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .node-label { font-size: 0.72rem; letter-spacing: -0.3px; font-weight: 500; }
        .node-active .progress-circle-wrapper { box-shadow: 0 0 0 3px #fff, 0 0 0 6px #0d6efd !important; }
        .node-active .node-label { font-weight: 700 !important; text-decoration: underline; }
    </style>

    <?php if (!empty($active_filter)): ?>
    <?php
    // --- STEP 1: GROUP SEPARATE ROW FACILITIES BY FILE/CLIENT DYNAMICALLY ---
    $grouped_proposals = [];
    if (!empty($matching_proposals)) {
        foreach ($matching_proposals as $row) {
            $file_id = $row['file_rec_id'];
            
            if (!isset($grouped_proposals[$file_id])) {
                // Initialize the structural base row for the client group
                $grouped_proposals[$file_id] = $row;
                $grouped_proposals[$file_id]['facilities'] = [];
                $grouped_proposals[$file_id]['group_total_amount'] = 0;
            }
            
            // Push each individual facility detail into a combined list for this file/client
            $grouped_proposals[$file_id]['facilities'][] = [
                'type'   => !empty($row['proposal_type']) ? $row['proposal_type'] : 'N/A',
                'amount' => floatval($row['proposal_amount'] ?? 0)
            ];
            
            // Add up the group total
            $grouped_proposals[$file_id]['group_total_amount'] += floatval($row['proposal_amount'] ?? 0);
        }
    }
    ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
            <h5 class="mb-0 fw-bold text-dark">
                <i class="fas fa-list text-primary me-2"></i> File Queue: <span class="text-primary"><?php echo htmlspecialchars($active_filter); ?></span> 
                <span class="badge bg-secondary ms-2 small" style="font-size:0.8rem;"><?php echo count($grouped_proposals); ?> Clients Found</span>
            </h5>
            <a href="index.php" class="btn btn-sm btn-light text-muted"><i class="fas fa-times me-1"></i> Close Queue</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-uppercase small" style="font-size:0.75rem;">
                    <tr>
                        <th class="ps-4" style="width: 22%;">Assigned Date &amp; Time</th>
                        <th style="width: 43%;">Client &amp; Combined Facility Details</th>
                        <th style="width: 20%;">Dealing Officer</th>
                        <th style="width: 15%;">Proposal Status</th>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php if (!empty($grouped_proposals)): ?>
                        <?php foreach ($grouped_proposals as $row): ?>
                            <tr>
                                <td class="ps-4 fw-semibold text-secondary">
                                    <i class="far fa-clock text-primary me-1"></i> 
                                    <?php echo (!empty($row['assigned_time'])) ? date('d-M-Y h:i A', strtotime($row['assigned_time'])) : 'Timestamp Not Set'; ?>
                                </td>
                                <td>
                                    <div class="fw-bold mb-1" style="font-size:0.95rem;">
                                        <a href="more_details.php?id=<?php echo intval($row['file_rec_id']); ?>" class="client-link" title="Click to view full file records">
                                            <i class="fas fa-folder-open text-primary opacity-75 me-1" style="font-size: 0.85rem;"></i>
                                            <?php echo htmlspecialchars($row['client_name'] ?? 'N/A'); ?>
                                        </a>
                                    </div>

                                    <div class="d-flex flex-column gap-1 mb-2 mt-1">
                                        <?php foreach ($row['facilities'] as $fac): ?>
                                            <div class="d-inline-flex align-items-center gap-2 flex-wrap">
                                                <span class="badge bg-info text-dark fw-bold border border-info-subtle shadow-sm" style="font-size: 0.75rem; padding: 2px 6px;">
                                                    <i class="fas fa-layer-group me-1"></i><?= htmlspecialchars($fac['type']) ?>
                                                </span>
                                                <span class="fw-bold text-dark font-monospace" style="font-size: 0.85rem;">
                                                    BDT <?php echo number_format($fac['amount'], 2); ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="pt-1 border-top border-light mb-1 d-flex align-items-center gap-1">
                                        <span class="text-uppercase fw-bold text-muted font-monospace" style="font-size: 0.7rem; letter-spacing:0.5px;">Group Total:</span>
                                        <span class="badge bg-success text-white fw-bold font-monospace" style="font-size: 0.8rem; padding: 2px 7px;">
                                            BDT <?php echo number_format($row['group_total_amount'], 2); ?>
                                        </span>
                                    </div>

                                    <div class="text-muted d-flex flex-wrap gap-3 mt-1" style="font-size:0.8rem;">
                                        <span><i class="fas fa-code-branch me-1"></i>Branch: <strong><?php echo htmlspecialchars($row['branch'] ?? 'N/A'); ?></strong></span>
                                        
                                    </div>

                                    <?php if (!empty($row['assignment_remarks'])): ?>
                                        <div class="text-muted border-start border-3 ps-2 mt-1 small" style="font-style: italic;">
                                            <i class="far fa-comment-alt me-1"></i><?= htmlspecialchars($row['assignment_remarks']) ?>
                                        </div>
                                    <?php endif; ?>
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

    <div class="card shadow-sm border-0 mb-4 bg-white">
        <div class="card-header bg-dark text-white fw-bold d-flex justify-content-between align-items-center py-2" style="font-size:14px; cursor: pointer;" onclick="toggleWorkforcePanel()">
            <span><i class="fas fa-network-wired text-warning me-2"></i> Officer of Current Client and Ready to Take Assignments</span>
            <span id="panel-toggle-icon"><i class="fas fa-chevron-down"></i> Click to Expand</span>
        </div>
        <div id="workforce-panel-body" class="card-body bg-light-subtle d-none border-bottom">
            <div class="row g-4">
                
                <div class="col-md-7 border-end">
                    <h6 class="text-primary fw-bold mb-3"><i class="fas fa-user-tie me-1"></i> Assigned Officers &amp; Current Client Load (In-Pipeline Only)</h6>
                    <div class="table-responsive" style="max-height: 250px; overflow-y: auto;">
                        <table class="table table-sm table-bordered table-hover bg-white m-0 small">
                            <thead class="table-secondary">
                                <tr>
                                    <th style="width:35%;">Officer Info</th>
                                    <th>Assigned Managed Clients</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(isset($assigned_map_res) && $assigned_map_res->num_rows > 0): ?>
                                    <?php while($amap = $assigned_map_res->fetch_assoc()): ?>
                                    <tr>
                                        <td class="fw-bold text-dark">
                                            <?= htmlspecialchars(!empty($amap['full_name']) ? $amap['full_name'] : $amap['username']) ?>
                                            <div class="text-muted font-monospace" style="font-size:10px;">Emp ID: <?= htmlspecialchars($amap['employee_id'] ?? 'N/A') ?></div>
                                        </td>
                                        <td>
                                            <?php 
                                                $clients = explode('||', $amap['client_list']);
                                                foreach($clients as $c_item) {
                                                    if(!empty(trim($c_item))) {
                                                        echo '<span class="badge bg-primary-subtle text-primary border border-primary-subtle me-1 my-1 d-inline-block px-2 py-1">' . htmlspecialchars($c_item) . '</span>';
                                                    }
                                                }
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="2" class="text-center text-muted py-3">No active pipeline loads tracked in current parameters.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="col-md-5">
                    <h6 class="text-danger fw-bold mb-3"><i class="fas fa-user-clock me-1"></i> Available Staff (No Active Assignments)</h6>
                    <div class="table-responsive" style="max-height: 250px; overflow-y: auto;">
                        <table class="table table-sm table-bordered table-hover bg-white m-0 small">
                            <thead class="table-secondary">
                                <tr>
                                    <th style="width:45%;">Available Employee Details</th>
                                    <th style="width:25%;">Employee ID</th>
                                    <th style="width:30%; text-align:center;">Operational Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(isset($unassigned_res) && $unassigned_res->num_rows > 0): ?>
                                    <?php while($un = $unassigned_res->fetch_assoc()): ?>
                                    <tr>
                                        <td class="fw-bold text-secondary">
                                            <?= htmlspecialchars(!empty($un['full_name']) ? $un['full_name'] : $un['username']) ?>
                                        </td>
                                        <td class="font-monospace text-muted" style="font-size:11px;">
                                            <?= htmlspecialchars($un['employee_id'] ?? 'N/A') ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-0.5"><i class="fas fa-circle-check me-1"></i>Ready / Idle</span>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="text-center text-muted py-3">All registered system users have active workloads deployed!</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
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