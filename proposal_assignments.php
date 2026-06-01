<?php
session_start();
include 'db.php';
include 'header.php';
if (!isset($_SESSION['loggedin'])) {
    header("location: login.php");
    exit;
}

$current_user_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? 0; 
$user_role       = $_SESSION['role'] ?? ''; 
$action_msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'delete_assignment') {
    // Basic verification boundary check: only allow admins to run deletions
    if (isset($user_role) && $user_role === 'admin') {
        $delete_id = intval($_POST['assignment_id']);
        
        // Option A: Hard Delete (completely removes row from system log database)
        $delete_sql = "DELETE FROM proposal_assignments WHERE assignment_id = ?"; 
        
        /* // Option B: Soft Delete (Uncomment if your table features a status/is_deleted column layout)
        $delete_sql = "UPDATE proposal_assignments SET assignment_log_status = 'Deleted', assignment_remarks = 'Removed by Admin' WHERE assignment_id = ?";
        */
        
        $stmt = $conn->prepare($delete_sql);
        if ($stmt) {
            $stmt->bind_param("i", $delete_id);
            if ($stmt->execute()) {
                $action_msg = '<div class="alert alert-success alert-dismissible fade show shadow-sm border-start border-success border-4" role="alert">
                                <i class="fas fa-check-circle me-2"></i><strong>Success!</strong> Assignment mapping pipeline record was successfully purged from system ledger indices.
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                               </div>';
            } else {
                $action_msg = '<div class="alert alert-danger shadow-sm"><strong>Database Error:</strong> Could not complete record elimination routing metrics.</div>';
            }
            $stmt->close();
        }
        
        // Force refresh working counts parameters variables on local window layout
        echo "<script>window.location.href='proposal_assignments.php';</script>";
        exit;
    } else {
        $action_msg = '<div class="alert alert-danger shadow-sm"><strong>Access Denied:</strong> Only Authorized Corporate System Administrators possess ledger deletion clearing permissions.</div>';
    }
}

// ================= INLINE FORM PROCESSOR INTERFACE =================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_type'])) {
    $target_assignment_id = intval($_POST['assignment_id']);
    $associated_file_id   = intval($_POST['file_id'] ?? 0); 
    $new_status           = trim($_POST['proposal_status'] ?? '');
    $remarks              = trim($_POST['remarks'] ?? '');

    if ($target_assignment_id > 0) {
        
        // ADMIN REASSIGNMENT OVERRIDE
        if ($_POST['action_type'] === 'admin_reassign' && $user_role === 'admin') {
            $new_officer_id = intval($_POST['new_officer_id'] ?? 0);
            
            if ($new_officer_id > 0 && $associated_file_id > 0) {
                try {
                    $stmt1 = $conn->prepare("UPDATE office_files SET assigned_user_id = ? WHERE id = ?");
                    $stmt1->bind_param("ii", $new_officer_id, $associated_file_id);
                    $stmt1->execute();
                    $stmt1->close();

                    $stmt2 = $conn->prepare("UPDATE proposal_assignments SET user_id = ?, remarks = 'Reassigned by Admin' WHERE id = ?");
                    $stmt2->bind_param("ii", $new_officer_id, $target_assignment_id);
                    $stmt2->execute();
                    $stmt2->close();

                    $action_msg = "<div class='alert alert-success py-2'><i class='fas fa-user-shield me-1'></i> File successfully reassigned to the selected officer.</div>";
                } catch (mysqli_sql_exception $e) {
                    $action_msg = "<div class='alert alert-danger py-2'>Admin Action Error: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            }
        }

        if ($_POST['action_type'] === 'receive') {
            $new_status = "Proposal Received";
        }

        if (!empty($new_status) && $_POST['action_type'] !== 'admin_reassign') {
            try {
                $stmt = $conn->prepare("UPDATE proposal_assignments SET proposal_status = ?, remarks = ? WHERE id = ?");
                $stmt->bind_param("ssi", $new_status, $remarks, $target_assignment_id);
                $stmt->execute();
                $stmt->close();

                if ($new_status === 'Approval/Sanction' && $associated_file_id > 0) {
                    header("Location: add_facility.php?file_id=" . $associated_file_id);
                    exit;
                }

                $action_msg = "<div class='alert alert-success py-2'><i class='fas fa-check-circle me-1'></i> Pipeline updated successfully to: <strong>$new_status</strong></div>";
            } catch (mysqli_sql_exception $e) {
                $action_msg = "<div class='alert alert-danger py-2'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
}

// ================= STATS COUNTER CALCULATIONS ENGINE =================
// 1. Total registered users
$total_users_res = $conn->query("SELECT COUNT(id) AS total FROM users");
$total_users = $total_users_res->fetch_assoc()['total'] ?? 0;

// 2. Total active assignees (Excluding Sanctioned and Declined Proposals to keep stats accurate)
$active_assignees_res = $conn->query("SELECT COUNT(DISTINCT user_id) AS total FROM proposal_assignments WHERE user_id > 0 AND proposal_status NOT IN ('Approval/Sanction', 'Declined')");
$active_assignees = $active_assignees_res->fetch_assoc()['total'] ?? 0;

// 3. Total active assignments (Excluding Sanctioned and Declined)
$assigned_tasks_res = $conn->query("SELECT COUNT(id) AS total FROM proposal_assignments WHERE user_id > 0 AND proposal_status NOT IN ('Approval/Sanction', 'Declined')");
$assigned_tasks = $assigned_tasks_res->fetch_assoc()['total'] ?? 0;


// ================= WORKFORCE ALLOCATION MAPPER QUERIES =================
// FIX: Added condition to exclude 'Declined' along with 'Approval/Sanction' from active matrix
$assigned_map_query = "SELECT 
                            u.full_name, u.username, u.employee_id,
                            GROUP_CONCAT(DISTINCT f.client SEPARATOR '||') AS client_list
                       FROM proposal_assignments pa
                       INNER JOIN users u ON pa.user_id = u.id
                       INNER JOIN office_files f ON pa.file_id = f.id
                       WHERE f.is_deleted = 0 AND pa.proposal_status NOT IN ('Approval/Sanction', 'Declined')
                       GROUP BY u.id 
                       ORDER BY u.full_name ASC";
$assigned_map_res = $conn->query($assigned_map_query);

// FIX: Recalculate idle staff using the same logic
$unassigned_query = "SELECT id, full_name, username, employee_id FROM users 
                     WHERE id NOT IN (SELECT DISTINCT user_id FROM proposal_assignments WHERE user_id > 0 AND proposal_status NOT IN ('Approval/Sanction', 'Declined'))
                     ORDER BY full_name ASC";
$unassigned_res = $conn->query($unassigned_query);


// ================= FETCH AND REPORT FILTER LAYOUT ENGINE =================
$officer_id = $_GET['officer_id'] ?? '';

$officers_array = [];
$officers_list = $conn->query("SELECT id, full_name, username FROM users ORDER BY full_name ASC");
while($row_off = $officers_list->fetch_assoc()) {
    $officers_array[] = $row_off;
}

$query = "SELECT 
            pa.id AS assignment_id,
            pa.file_id,
            pa.user_id AS assigned_officer_id,
            pa.proposal_status AS assignment_log_status,
            pa.proposal_type,
            pa.remarks AS assignment_remarks,
            pa.assigned_date,
            f.client AS client_name,
            f.file_no,
            f.cabinet_name,
            f.shelf_name,
            f.branch_name,
            u.full_name AS officer_name,
            u.employee_id,
            u.username
          FROM proposal_assignments pa
          INNER JOIN office_files f ON pa.file_id = f.id
          INNER JOIN users u ON pa.user_id = u.id
          WHERE f.is_deleted = 0";

$params = [];
$types = "";

if (!empty($officer_id)) {
    $query .= " AND pa.user_id = ?";
    $params[] = intval($officer_id);
    $types .= "i";
}

$query .= " ORDER BY pa.assigned_date DESC";

$stmt = $conn->prepare($query);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$ledger_data = $stmt->get_result();
$total_records = $ledger_data->num_rows;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Proposal Dashboard Panel</title>
<link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body class="bg-light p-4">
<div class="container-fluid" style="max-width: 1600px;">

    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded shadow-sm">
        <div>
            <h3 class="mb-0 text-dark"><i class="fas fa-project-diagram text-primary me-2"></i>Proposal Workflow Management Board</h3>
            <small class="text-muted">Interactive action dashboard for managing corporate file pipelines</small>
        </div>
        <a href="index.php" class="btn btn-dark shadow-sm"><i class="fas fa-home me-1"></i> Dashboard</a>
    </div>

    <?= $action_msg ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm border-0 bg-white h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="bg-primary-subtle p-3 rounded text-primary me-3">
                        <i class="fas fa-users fa-2x"></i>
                    </div>
                    <div>
                        <h4 class="mb-0 fw-bold text-dark"><?= $total_users ?></h4>
                        <small class="text-muted text-uppercase fw-bold" style="font-size:0.75rem; letter-spacing: 0.5px;">Total Users Registered</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm border-0 bg-white h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="bg-warning-subtle p-3 rounded text-warning me-3">
                        <i class="fas fa-user-check fa-2x"></i>
                    </div>
                    <div>
                        <h4 class="mb-0 fw-bold text-dark"><?= $active_assignees ?></h4>
                        <small class="text-muted text-uppercase fw-bold" style="font-size:0.75rem; letter-spacing: 0.5px;">Active Assigned Officers</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm border-0 bg-white h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="bg-success-subtle p-3 rounded text-success me-3">
                        <i class="fas fa-folder-minus fa-2x"></i>
                    </div>
                    <div>
                        <h4 class="mb-0 fw-bold text-dark"><?= $assigned_tasks ?></h4>
                        <small class="text-muted text-uppercase fw-bold" style="font-size:0.75rem; letter-spacing: 0.5px;">Active Pipelines</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4 bg-white">
        <div class="card-header bg-dark text-white fw-bold d-flex justify-content-between align-items-center py-2" style="font-size:14px; cursor: pointer;" onclick="toggleWorkforcePanel()">
            <span><i class="fas fa-network-wired text-warning me-2"></i> Officer of Current Client and Ready to Take Assignments</span>
            <span id="panel-toggle-icon"><i class="fas fa-chevron-down"></i> Click to Expand</span>
        </div>
        <div id="workforce-panel-body" class="card-body bg-light-subtle d-none border-bottom">
            <div class="row g-4">
                
                <div class="col-md-7 border-end">
                    <h6 class="text-primary fw-bold mb-3"><i class="fas fa-user-tie me-1"></i> Assigned Officers & Current Client Load (In-Pipeline Only)</h6>
                    <div class="table-responsive" style="max-height: 250px; overflow-y: auto;">
                        <table class="table table-sm table-bordered table-hover bg-white m-0 small">
                            <thead class="table-secondary">
                                <tr>
                                    <th>Officer Info</th>
                                    <th>Assigned Managed Clients</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if($assigned_map_res->num_rows > 0): ?>
                                    <?php while($amap = $assigned_map_res->fetch_assoc()): ?>
                                    <tr>
                                        <td class="fw-bold text-dark" style="width:35%;">
                                            <?= htmlspecialchars(!empty($amap['full_name']) ? $amap['full_name'] : $amap['username']) ?>
                                            <div class="text-muted font-monospace" style="font-size:10px;">Emp ID: <?= htmlspecialchars($amap['employee_id'] ?? 'N/A') ?></div>
                                        </td>
                                        <td>
                                            <?php 
                                                $clients = explode('||', $amap['client_list']);
                                                foreach($clients as $c_item) {
                                                    echo '<span class="badge bg-primary-subtle text-primary border border-primary-subtle me-1 my-1 d-inline-block px-2 py-1">' . htmlspecialchars($c_item) . '</span>';
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
                                    <th>Available Employee Details</th>
                                    <th>Operational Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if($unassigned_res->num_rows > 0): ?>
                                    <?php while($un = $unassigned_res->fetch_assoc()): ?>
                                    <tr>
                                        <td class="fw-bold text-secondary">
                                            <?= htmlspecialchars(!empty($un['full_name']) ? $un['full_name'] : $un['username']) ?>
                                        </td>
                                        <td class="font-monospace text-muted" style="font-size:11px;">
                                            Emp ID: <?= htmlspecialchars($un['employee_id'] ?? 'N/A') ?>
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

    <div class="card shadow-sm border-0 mb-4 bg-white">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-9">
                    <label class="form-label small fw-bold text-muted text-uppercase">Filter Ledger Records By Dealing Officer</label>
                    <select name="officer_id" class="form-select form-select-lg border-primary">
                        <option value="">-- View All Active Assignments --</option>
                        <?php foreach($officers_array as $off): ?>
                            <option value="<?= $off['id'] ?>" <?= $officer_id == $off['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(!empty($off['full_name']) ? $off['full_name'] : $off['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm"><i class="fas fa-filter me-1"></i> Filter Table</button>
                    <a href="proposal_assignments.php" class="btn btn-lg btn-outline-secondary" title="Reset All Layout Filters"><i class="fas fa-undo"></i></a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0 bg-white">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle m-0">
                    <thead class="table-dark">
                        <tr>
                            <th class="ps-3" style="width: 12%;">Assigned Timestamp</th>
                            <th style="width: 25%;">Client & File Details</th>
                            <th style="width: 15%;">Storage Location</th>
                            <th style="width: 18%;">Assigned Dealing Officer</th>
                            <th style="width: 12%;">Proposal Status</th>
                            <th style="width: 18%;" class="text-center">Workflow Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($total_records > 0): ?>
                            <?php while($row = $ledger_data->fetch_assoc()): ?>
                            <?php 
                                $status_state = trim($row['assignment_log_status'] ?? ''); 
                                $has_permission = (intval($row['assigned_officer_id']) === intval($current_user_id) || $user_role === 'admin');
                            ?>
                            <tr>
                                <td class="ps-3 font-monospace small text-secondary">
                                    <?= date('d-M-Y h:i A', strtotime($row['assigned_date'])) ?>
                                </td>

                                <td>
                                    <div class="fw-bold text-dark mb-0" style="font-size: 1.05rem;"><?= htmlspecialchars($row['client_name']) ?></div>
                                    
                                    <div class="mt-1 mb-1">
                                        <span class="badge bg-info text-dark fw-bold border border-info-subtle shadow-sm" style="font-size: 0.75rem; padding: 3px 8px;">
                                            <i class="fas fa-layer-group me-1"></i><?= htmlspecialchars(!empty($row['proposal_type']) ? $row['proposal_type'] : 'N/A') ?>
                                        </span>
                                    </div>

                                    <small class="text-muted font-monospace d-block" style="font-size: 0.8rem;">File: <?= htmlspecialchars($row['file_no'] ?? 'N/A') ?></small>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle font-monospace" style="font-size: 0.65rem;"><?= htmlspecialchars($row['branch_name'] ?? 'N/A') ?></span>
                                </td>

                                <td>
                                    <span class="badge bg-light text-dark border p-1.5 mb-1 d-inline-block small">Cabinet: <?= htmlspecialchars($row['cabinet_name'] ?? 'N/A') ?></span><br>
                                    <span class="badge bg-light text-dark border p-1.5 d-inline-block small">Shelf: <?= htmlspecialchars($row['shelf_name'] ?? 'N/A') ?></span>
                                </td>

                                <td>
                                    <div class="fw-bold text-primary mb-0">
                                        <i class="fas fa-user-tie me-1"></i><?= htmlspecialchars(!empty($row['officer_name']) ? $row['officer_name'] : $row['username']) ?>
                                    </div>
                                    <small class="text-muted font-monospace small d-block">Emp ID: <?= htmlspecialchars($row['employee_id'] ?? 'N/A') ?></small>
                                    
                                    <?php if(intval($row['assigned_officer_id']) === intval($current_user_id)): ?>
                                        <span class="badge bg-success-subtle text-success p-1 rounded font-monospace" style="font-size:10px">You</span>
                                    <?php endif; ?>

                                    <?php if ($user_role === 'admin'): ?>
                                        <div class="mt-2 pt-1 border-top border-secondary-subtle">
                                            <form method="POST" class="m-0 d-flex gap-1 align-items-center">
                                                <input type="hidden" name="action_type" value="admin_reassign">
                                                <input type="hidden" name="assignment_id" value="<?= $row['assignment_id'] ?>">
                                                <input type="hidden" name="file_id" value="<?= $row['file_id'] ?>">
                                                
                                                <select name="new_officer_id" class="form-select form-select-sm border-danger text-danger bg-danger-subtle fw-bold" style="font-size: 11px; padding: 2px 5px;" onchange="if(confirm('Are you sure you want to transfer this client file to a different officer?')) this.form.submit();">
                                                    <option value="">-- Reassign Officer... --</option>
                                                    <?php foreach($officers_array as $opt_off): ?>
                                                        <?php if(intval($opt_off['id']) !== intval($row['assigned_officer_id'])): ?>
                                                            <option value="<?= $opt_off['id'] ?>">Move to: <?= htmlspecialchars($opt_off['full_name'] ?? $opt_off['username']) ?></option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php 
                                    switch($status_state) {
                                        case 'Proposal In Preparation':
                                            echo '<span class="badge bg-light text-dark border border-secondary px-2 py-1.5 rounded-pill w-100"><i class="fas fa-pen me-1"></i>In Prep</span>';
                                            break;
                                        case 'Proposal Received':
                                            echo '<span class="badge bg-primary-subtle text-primary border border-primary px-2 py-1.5 rounded-pill w-100"><i class="fas fa-hand-holding me-1"></i>Received</span>';
                                            break;
                                        case 'Pending':
                                            echo '<span class="badge bg-warning text-dark border border-warning px-2 py-1.5 rounded-pill w-100"><i class="fas fa-exclamation-triangle me-1"></i>Pending</span>';
                                            break;
                                        case 'Approval/Sanction':
                                            echo '<span class="badge bg-success border border-success px-2 py-1.5 rounded-pill w-100"><i class="fas fa-check-double me-1"></i>Sanctioned</span>';
                                            break;
                                        case 'Declined':
                                            echo '<span class="badge bg-danger text-white border border-danger px-2 py-1.5 rounded-pill w-100"><i class="fas fa-ban me-1"></i>Declined</span>';
                                            break;
                                        case 'Board Memo':
                                        case 'Board Minutes':
                                            echo '<span class="badge bg-danger-subtle text-danger border border-danger px-2 py-1.5 rounded-pill w-100"><i class="fas fa-gavel me-1"></i>' . htmlspecialchars($status_state) . '</span>';
                                            break;
                                        default:
                                            echo '<span class="badge bg-info-subtle text-info border border-info px-2 py-1.5 rounded-pill w-100"><i class="fas fa-file-alt me-1"></i>' . htmlspecialchars($status_state) . '</span>';
                                            break;
                                    }
                                    
                                    if (!empty($row['assignment_remarks'])) {
                                        echo '<div class="alert alert-warning p-1 mt-1 mb-0 small border-0 font-monospace" style="font-size:11px; line-height:1.2;"><strong>Note:</strong> ' . htmlspecialchars($row['assignment_remarks']) . '</div>';
                                    }
                                    ?>
                                </td>

                                <td class="text-center bg-light-subtle">
                                    <?php if ($has_permission): ?>
                                        
                                        <?php if ($status_state === 'Proposal In Preparation'): ?>
                                            <form method="POST" class="m-0">
                                                <input type="hidden" name="action_type" value="receive">
                                                <input type="hidden" name="assignment_id" value="<?= $row['assignment_id'] ?>">
                                                <input type="hidden" name="file_id" value="<?= $row['file_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold shadow-sm py-1.5">
                                                    <i class="fas fa-clipboard-check me-1"></i> Receive Proposal
                                                </button>
                                            </form>
                                        <?php elseif ($status_state === 'Approval/Sanction'): ?>
                                            <div class="d-flex flex-column gap-1">
                                                <a href="add_facility.php?file_id=<?= $row['file_id'] ?>" class="btn btn-sm btn-success w-100 fw-bold">
                                                    <i class="fas fa-plus-circle me-1"></i> Add Facility
                                                </a>
                                                <button type="button" class="btn btn-xs btn-link text-danger p-0 border-0 small text-decoration-none" style="font-size:11px;" onclick="showCorrectionRow(this)">
                                                    <i class="fas fa-undo"></i> Revert Mistake
                                                </button>
                                                
                                                <div class="correction-box d-none mt-1">
                                                    <form method="POST" class="m-0">
                                                        <input type="hidden" name="action_type" value="update_status">
                                                        <input type="hidden" name="assignment_id" value="<?= $row['assignment_id'] ?>">
                                                        <input type="hidden" name="file_id" value="<?= $row['file_id'] ?>">
                                                        <input type="hidden" name="proposal_status" value="Pending">
                                                        <input type="text" name="remarks" class="form-control form-control-sm border-danger mb-1" placeholder="Why revert?" required style="font-size:11px;">
                                                        <button type="submit" class="btn btn-danger btn-sm w-100 py-0" style="font-size:11px;">Confirm Revert</button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php elseif ($status_state === 'Declined'): ?>
                                            <button type="button" class="btn btn-xs btn-outline-secondary w-100 py-1" style="font-size:11px;" onclick="showCorrectionRow(this)">
                                                <i class="fas fa-undo"></i> Revert Status
                                            </button>
                                            <div class="correction-box d-none mt-1">
                                                <form method="POST" class="m-0">
                                                    <input type="hidden" name="action_type" value="update_status">
                                                    <input type="hidden" name="assignment_id" value="<?= $row['assignment_id'] ?>">
                                                    <input type="hidden" name="file_id" value="<?= $row['file_id'] ?>">
                                                    <input type="hidden" name="proposal_status" value="Pending">
                                                    <input type="text" name="remarks" class="form-control form-control-sm border-secondary mb-1" placeholder="Reason..." required style="font-size:11px;">
                                                    <button type="submit" class="btn btn-secondary btn-sm w-100 py-0" style="font-size:11px;">Revert to Pending</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <form method="POST" class="d-flex flex-column gap-1 m-0 workflow-status-form">
                                                <input type="hidden" name="action_type" value="update_status">
                                                <input type="hidden" name="assignment_id" value="<?= $row['assignment_id'] ?>">
                                                <input type="hidden" name="file_id" value="<?= $row['file_id'] ?>">
                                                
                                                <select name="proposal_status" class="form-select form-select-sm border-primary py-1 proposal-stage-selector" style="font-size: 0.8rem;">
                                                    <option value="">-- Change the Status --</option>
                                                    <option value="Pending" <?= $status_state === 'Pending' ? 'selected' : '' ?>>Pending / On Hold</option>
                                                    <option value="Committee Memo" <?= $status_state === 'Committee Memo' ? 'selected' : '' ?>>Committee Memo</option>
                                                    <option value="Committee Minutes" <?= $status_state === 'Committee Minutes' ? 'selected' : '' ?>>Committee Minutes</option>
                                                    <option value="Office Note" <?= $status_state === 'Office Note' ? 'selected' : '' ?>>Office Note</option>
                                                    <option value="Board Memo" <?= $status_state === 'Board Memo' ? 'selected' : '' ?>>Board Memo</option>
                                                    <option value="Board Minutes" <?= $status_state === 'Board Minutes' ? 'selected' : '' ?>>Board Minutes</option>
                                                    <option value="Approval/Sanction" <?= $status_state === 'Approval/Sanction' ? 'selected' : '' ?>>Approval/Sanction</option>
                                                    <option value="Declined" <?= $status_state === 'Declined' ? 'selected' : '' ?>>Declined / Rejected</option>
                                                </select>

                                                <div class="remarks-wrapper d-none">
                                                    <div class="input-group input-group-sm mt-1">
                                                        <input type="text" name="remarks" class="form-control border-warning dynamic-remarks-input" placeholder="Reason..." style="font-size:11px;" value="<?= htmlspecialchars($row['assignment_remarks'] ?? '') ?>">
                                                        <button class="btn btn-warning fw-bold text-dark" type="submit" style="font-size:11px;">Save</button>
                                                    </div>
                                                </div>
                                            </form>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <span class="text-muted small italic opacity-75"><i class="fas fa-user-slash me-1"></i> View Only</span>
                                    <?php endif; ?>
                                </td>
                                
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted p-5 bg-white">
                                    <i class="fas fa-folder-open fa-3x text-light mb-3"></i><br>
                                    No logged history found inside parameters.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.proposal-stage-selector').forEach(function (selectEl) {
        toggleRemarksInput(selectEl);
        selectEl.addEventListener('change', function () {
            if (this.value === 'Pending' || this.value === 'Declined') {
                toggleRemarksInput(this);
            } else {
                this.form.submit();
            }
        });
    });

    function toggleRemarksInput(element) {
        const form = element.closest('.workflow-status-form');
        if(!form) return;
        const remarksWrapper = form.querySelector('.remarks-wrapper');
        const remarksInput = form.querySelector('.dynamic-remarks-input');
        
        if (element.value === 'Pending' || element.value === 'Declined') {
            remarksWrapper.classList.remove('d-none');
            remarksInput.placeholder = element.value === 'Declined' ? "Reason for decline..." : "Reason for hold...";
            remarksInput.required = true;
            remarksInput.focus();
        } else {
            remarksWrapper.classList.add('d-none');
            remarksInput.required = false;
        }
    }
});

function showCorrectionRow(btn) {
    const box = btn.nextElementSibling;
    box.classList.toggle('d-none');
}

function toggleWorkforcePanel() {
    const pBody = document.getElementById('workforce-panel-body');
    const pIcon = document.getElementById('panel-toggle-icon');
    if(pBody.classList.contains('d-none')) {
        pBody.classList.remove('d-none');
        pIcon.innerHTML = '<i class="fas fa-chevron-up"></i> Click to Collapse';
    } else {
        pBody.classList.add('d-none');
        pIcon.innerHTML = '<i class="fas fa-chevron-down"></i> Click to Expand';
    }
}
</script>
<?php include 'footer.php'; ?>
</body>
</html>