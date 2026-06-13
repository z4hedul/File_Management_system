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

$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';
$assignment_date_condition = '';
$sanction_date_condition = '';
if (!empty($from_date) && !empty($to_date)) {
    $safe_from = $conn->real_escape_string($from_date);
    $safe_to = $conn->real_escape_string($to_date);
    $assignment_date_condition = " AND pa.assigned_date BETWEEN '{$safe_from} 00:00:00' AND '{$safe_to} 23:59:59' ";
    $sanction_date_condition = " AND ff.sanction_date BETWEEN '{$safe_from}' AND '{$safe_to}' ";
}

// ================= ADMIN DELETE ASSIGNMENT HANDLER =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'delete_assignment') {
    if (isset($user_role) && $user_role === 'admin') {
        $delete_ids_str = $_POST['assignment_ids'] ?? '';
        
        if (!empty($delete_ids_str)) {
            $id_array = array_map('intval', explode(',', $delete_ids_str));
            $id_placeholder = implode(',', $id_array);
            $delete_sql = "DELETE FROM proposal_assignments WHERE id IN ($id_placeholder)"; 
            
            try {
                if ($conn->query($delete_sql)) {
                    $action_msg = '<div class="alert alert-success alert-dismissible fade show shadow-sm border-start border-success border-4" role="alert">
                                    <i class="fas fa-trash-alt me-2"></i><strong>Success!</strong> Assignment record group was successfully removed.
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                   </div>';
                }
            } catch (Exception $e) {
                $action_msg = '<div class="alert alert-danger alert-dismissible fade show shadow-sm border-start border-danger border-4" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i><strong>Database Error:</strong> Unable to process cleanup.
                               <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                              </div>';
            }
        }
    }
}

// ================= WORKFLOW ENGINE STATUS UPDATE HANDLER =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $assignment_ids_str = $_POST['assignment_ids'] ?? '';
    $proposal_ref       = trim($_POST['proposal_ref'] ?? '');
    $new_status         = $_POST['proposal_status'] ?? '';
    $remarks            = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

    if ((!empty($assignment_ids_str) || !empty($proposal_ref)) && !empty($new_status)) {
        $id_list = array_map('intval', array_filter(explode(',', $assignment_ids_str)));
        $placeholders = !empty($id_list) ? implode(',', $id_list) : '';

        // Security check for standard users (can only update their own assigned proposals)
        $can_proceed = true;
        if ($user_role !== 'admin') {
            if (!empty($proposal_ref)) {
                $chk_sql = "SELECT COUNT(*) as cnt FROM proposal_assignments WHERE proposal_ref = ? AND user_id != ?";
                $chk_stmt = $conn->prepare($chk_sql);
                $chk_stmt->bind_param("si", $proposal_ref, $current_user_id);
            } else {
                $chk_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM proposal_assignments WHERE id IN ($placeholders) AND user_id != ?");
                $chk_stmt->bind_param("i", $current_user_id);
            }
            $chk_stmt->execute();
            if ($chk_stmt->get_result()->fetch_assoc()['cnt'] > 0) {
                $can_proceed = false;
            }
            $chk_stmt->close();
        }

        if (!$can_proceed) {
            $action_msg = '<div class="alert alert-danger alert-dismissible fade show shadow-sm border-start border-danger border-4" role="alert">
                            <i class="fas fa-ban me-2"></i><strong>Access Denied:</strong> You can only update states for records explicitly dispatched to your profile row.
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                           </div>';
        } else {
            if (!empty($proposal_ref)) {
                $update_sql = "UPDATE proposal_assignments SET proposal_status = ?, remarks = ? WHERE proposal_ref = ?";
                if ($user_role !== 'admin') {
                    $update_sql .= " AND user_id = ?";
                }
                $stmt = $conn->prepare($update_sql);
                if ($user_role !== 'admin') {
                    $stmt->bind_param("sssi", $new_status, $remarks, $proposal_ref, $current_user_id);
                } else {
                    $stmt->bind_param("sss", $new_status, $remarks, $proposal_ref);
                }
            } else {
                $update_sql = "UPDATE proposal_assignments SET proposal_status = ?, remarks = ? WHERE id IN ($placeholders)";
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param("ss", $new_status, $remarks);
            }
            
            if ($stmt->execute()) {
                $action_msg = '<div class="alert alert-success alert-dismissible fade show shadow-sm border-start border-success border-4" role="alert">
                                <i class="fas fa-check-circle me-2"></i><strong>Workflow Updated:</strong> Target records transitioned to status: <span class="badge bg-primary">' . htmlspecialchars($new_status) . '</span>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                               </div>';
            } else {
                $action_msg = '<div class="alert alert-danger alert-dismissible fade show shadow-sm border-start border-danger border-4" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><strong>Execution Failure:</strong> State workflow configuration update process broke down.
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                               </div>';
            }
            $stmt->close();
        }
    }
}

// ================= SYSTEM DATA ENGINE QUERY GENERATION =================
$overdue_approval_count = 0;
$overdue_approval_amount = 0;
$overdue_approval_list = [];
$overdue_sql = "
    SELECT 
        pa.proposal_ref,
        o.client,
        o.branch_name,
        o.file_no,
        o.division,
        MAX(ff.sanction_date) AS sanction_date,
        MAX(ff.sanction_letter_ref_no) AS sanction_ref,
        SUM(pa.proposal_amount) AS proposal_total,
        DATEDIFF(CURDATE(), MAX(ff.sanction_date)) AS days_since_sanction
    FROM proposal_assignments pa
    JOIN office_files o ON pa.file_id = o.id
    JOIN file_facilities ff ON ff.file_record_id = o.id
    WHERE o.is_deleted = 0
      AND pa.proposal_status = 'Approval/Sanction'
      AND ff.sanction_date <= DATE_SUB(CURDATE(), INTERVAL 15 DAY)
      {$sanction_date_condition}
    GROUP BY pa.proposal_ref, o.client, o.branch_name, o.file_no, o.division
    ORDER BY MAX(ff.sanction_date) ASC
";
$overdue_res = $conn->query($overdue_sql);
if ($overdue_res && $overdue_res->num_rows > 0) {
    while ($overdue_row = $overdue_res->fetch_assoc()) {
        $overdue_approval_list[] = $overdue_row;
        $overdue_approval_count++;
        $overdue_approval_amount += floatval($overdue_row['proposal_total'] ?? 0);
    }
}

// ================= FIXED MAIN QUERY - EXCLUDE OLD APPROVAL/SANCTION =================
$query = "SELECT 
            pa.id,
            pa.proposal_ref,
            pa.proposal_status,
            pa.remarks,
            pa.assigned_date,
            o.client,
            o.file_no,
            o.branch_name,
            o.division,
            o.id AS file_rec_id,
            u.full_name AS processing_officer,
            pa.proposal_type,
            pa.proposal_amount,
            pa.facility_group
          FROM proposal_assignments pa
          JOIN office_files o ON pa.file_id = o.id
          LEFT JOIN users u ON pa.user_id = u.id
          WHERE o.is_deleted = 0 ";

// Apply scope visibility filtering conditions
if ($user_role !== 'admin') {
    $query .= " AND pa.user_id = " . intval($current_user_id);
}

if (!empty($assignment_date_condition)) {
    $query .= $assignment_date_condition;
}

// ===== FIX: Exclude Approval/Sanction records older than 15 days =====
$query .= " AND NOT (pa.proposal_status = 'Approval/Sanction' 
            AND EXISTS (SELECT 1 FROM file_facilities ff 
                        WHERE ff.file_record_id = o.id 
                        AND ff.sanction_date <= DATE_SUB(CURDATE(), INTERVAL 15 DAY)))";

$query .= " ORDER BY pa.assigned_date DESC";

$assignments_res = $conn->query($query);

// Group assignments by proposal_ref for display
$grouped_assignments = [];
if ($assignments_res && $assignments_res->num_rows > 0) {
    while ($row = $assignments_res->fetch_assoc()) {
        $ref = $row['proposal_ref'];
        if (!isset($grouped_assignments[$ref])) {
            $grouped_assignments[$ref] = [
                'proposal_ref' => $row['proposal_ref'],
                'proposal_status' => $row['proposal_status'],
                'remarks' => $row['remarks'],
                'client' => $row['client'],
                'file_no' => $row['file_no'],
                'branch_name' => $row['branch_name'],
                'division' => $row['division'],
                'file_rec_id' => $row['file_rec_id'],
                'processing_officer' => $row['processing_officer'],
                'assigned_date' => $row['assigned_date'],
                'facilities' => [],
                'composite_ids' => []
            ];
        }
        $grouped_assignments[$ref]['facilities'][] = [
            'type' => $row['proposal_type'],
            'amount' => floatval($row['proposal_amount']),
            'group' => $row['facility_group']
        ];
        $grouped_assignments[$ref]['composite_ids'][] = $row['id'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proposal Tracking Center</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; }
        .card { border-radius: 12px; border: none; }
        .client-header { font-size: 1.05rem; color: #1e293b; }
        .facility-item { font-size: 0.82rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 4px 8px; }
        .table-responsive-custom { width: 100%; overflow-x: auto; }
        .table-responsive-custom table { min-width: 1100px; }
        .text-line-through {
            text-decoration: line-through;
            opacity: 0.7;
        }
    </style>
</head>
<body class="bg-light">
<div class="container-fluid py-4" style="max-width:1500px;">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-1">Proposal Processing Ledger</h3>
            <p class="text-muted small mb-0">Track, update, and manage processing pipelines isolated by unique Proposal Reference numbers.</p>
        </div>
        <a href="index.php" class="btn btn-dark btn-sm rounded-pill px-3"><i class="fas fa-home me-1"></i> Control Panel</a>
    </div>

    <?= $action_msg; ?>

    <div class="card shadow-sm border-0 mb-4 bg-white">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
            <div>
                <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-hourglass-half text-warning me-2"></i>Approval Due After 15 Days</h5>
                <div class="text-muted small">Sanctioned proposals older than 15 days, optionally limited by the selected sanction date range.</div>
            </div>
            <form method="GET" class="d-flex gap-2 align-items-end flex-wrap">
                <div>
                    <label class="form-label small fw-bold text-secondary mb-1">From Date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($from_date) ?>">
                </div>
                <div>
                    <label class="form-label small fw-bold text-secondary mb-1">To Date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($to_date) ?>">
                </div>
                <div class="d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-dark px-3"><i class="fas fa-filter me-1"></i> Filter</button>
                    <?php if (!empty($from_date) || !empty($to_date)): ?>
                        <a href="proposal_assignments.php" class="btn btn-sm btn-outline-secondary px-3"><i class="fas fa-undo me-1"></i> Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <div class="card-body p-3 p-md-4">
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-secondary small text-uppercase fw-bold">Overdue Approval Refs</div>
                            <div class="fs-3 fw-bold text-dark font-monospace"><?= number_format($overdue_approval_count) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-secondary small text-uppercase fw-bold">Total Proposal Amount</div>
                            <div class="fs-3 fw-bold text-success font-monospace">BDT <?= number_format($overdue_approval_amount, 2) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-secondary small text-uppercase fw-bold">Threshold</div>
                            <div class="fs-6 fw-bold text-dark">Older than 15 days from sanction date</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-responsive-custom mb-4">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-warning text-uppercase small" style="font-size:0.75rem;">
                        <tr>
                            <th class="ps-4">Client</th>
                            <th>Branch</th>
                            <th>Proposal Ref</th>
                            <th>Sanction Ref</th>
                            <th>Sanction Date</th>
                            <th class="text-center">Days Old</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($overdue_approval_list)): ?>
                            <?php foreach ($overdue_approval_list as $overdue_row): ?>
                                <tr>
                                    <td class="ps-4 fw-semibold"><?= htmlspecialchars($overdue_row['client']) ?></td>
                                    <td><?= htmlspecialchars($overdue_row['branch_name'] ?? 'N/A') ?></td>
                                    <td><span class="badge bg-dark font-monospace"><?= htmlspecialchars($overdue_row['proposal_ref']) ?></span></td>
                                    <td class="font-monospace text-secondary"><?= htmlspecialchars($overdue_row['sanction_ref'] ?? 'N/A') ?></td>
                                    <td><?= !empty($overdue_row['sanction_date']) ? date('d-M-Y', strtotime($overdue_row['sanction_date'])) : 'N/A' ?></td>
                                    <td class="text-center fw-bold text-danger font-monospace"><?= number_format($overdue_row['days_since_sanction'] ?? 0) ?> </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No sanctioned proposals older than 15 days were found for the selected range.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm bg-white">
        <div class="card-body p-0">
            <div class="table-responsive-custom">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark text-uppercase small" style="font-size:0.75rem;">
                        <tr>
                            <th class="ps-4" style="width: 25%;">Client & Profile Context</th>
                            <th style="width: 32%;">Combined Allocation Facilities</th>
                            <th style="width: 15%;">Reference String</th>
                            <th style="width: 13%;">Officer Assigned</th>
                            <th class="text-center" style="width: 15%;">Workflow Routing Engine</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($grouped_assignments)): ?>
                            <?php foreach ($grouped_assignments as $row): ?>
                                <?php 
                                $total_calculated_sum = 0;
                                foreach ($row['facilities'] as $fac) {
                                    $total_calculated_sum += $fac['amount'];
                                }
                                $composite_ids_str = implode(',', $row['composite_ids']);
                                ?>
                                <tr>
                                    <td class="ps-4 py-3">
                                        <div class="fw-bold client-header mb-1">
                                            <a href="more_details.php?id=<?= $row['file_rec_id']; ?>" class="text-decoration-none text-dark hover-primary">
                                                <i class="fas fa-folder-open text-warning me-2"></i><?= htmlspecialchars($row['client']); ?>
                                            </a>
                                        </div>
                                        <div class="text-muted small font-monospace d-flex flex-wrap gap-2">
                                            <span>Branch: <strong><?= htmlspecialchars($row['branch_name']); ?></strong></span>|
                                            <span>File No: <strong><?= htmlspecialchars($row['file_no']); ?></strong></span>|
                                            <span>Division: <strong><?= htmlspecialchars($row['division']); ?></strong></span>
                                        </div>
                                        <div class="text-muted small mt-1">
                                            <i class="far fa-calendar-alt me-1"></i>Assigned: <?= date('d-M-Y h:i A', strtotime($row['assigned_date'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column gap-2">
                                            <?php foreach ($row['facilities'] as $fac): ?>
                                                <div class="facility-item d-flex justify-content-between align-items-center">
                                                    <span class="fw-semibold text-secondary"><i class="fas fa-layer-group text-info me-1"></i><?= htmlspecialchars($fac['type']); ?></span>
                                                    <span class="font-monospace fw-bold text-dark">BDT <?= number_format($fac['amount'], 2); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="d-flex justify-content-between align-items-center px-2 pt-1 border-top small font-monospace">
                                                <span class="text-muted text-uppercase fw-bold" style="font-size:11px;">Total Structural Load:</span>
                                                <span class="badge bg-success font-monospace text-white fw-bold">BDT <?= number_format($total_calculated_sum, 2); ?></span>
                                            </div>
                                        </div>
                                        <?php if (!empty($row['remarks'])): ?>
                                            <div class="text-muted border-start border-3 border-warning ps-2 mt-2 small italic" style="font-size: 0.78rem;">
                                                <i class="far fa-comment-alt text-warning me-1"></i><?= htmlspecialchars($row['remarks']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-dark font-monospace text-warning px-2 py-1 shadow-sm" style="font-size: 0.8rem;">
                                            <i class="fas fa-hashtag me-1"></i><?= htmlspecialchars($row['proposal_ref']); ?>
                                        </span>
                                     </td>
                                    <td>
                                        <div class="d-flex align-items-center text-dark">
                                            <i class="fas fa-user-tie text-secondary me-2"></i>
                                            <span class="small fw-medium"><?= htmlspecialchars($row['processing_officer'] ?? 'System Core'); ?></span>
                                        </div>
                                     </td>
                                    <td class="text-center pe-4">
                                        <?php if ($row['proposal_status'] === 'Proposal In Preparation'): ?>
                                            <form method="POST" class="d-inline-block w-100">
                                                <input type="hidden" name="assignment_ids" value="<?= htmlspecialchars($composite_ids_str); ?>">
                                                <input type="hidden" name="proposal_ref" value="<?= htmlspecialchars($row['proposal_ref']); ?>">
                                                <input type="hidden" name="update_status" value="1">
                                                <input type="hidden" name="proposal_status" value="Proposal Received">
                                                <button type="submit" class="btn btn-sm btn-success w-100 fw-bold font-monospace shadow-sm" style="font-size:11px; padding: 6px 4px;">
                                                    <i class="fas fa-file-import me-1"></i> Receive Proposal
                                                </button>
                                            </form>
                                        <?php elseif ($row['proposal_status'] === 'Approval/Sanction' && $user_role !== 'admin'): ?>
                                            <div class="pt-2 border-top border-light mt-2 d-flex justify-content-end">
                                                <a href="add_facility.php?file_id=<?= intval($row['file_rec_id']); ?>" class="btn btn-sm btn-success fw-bold font-monospace shadow-sm w-100" style="font-size:11px; padding: 6px 4px;">
                                                    <i class="fas fa-plus-circle me-1"></i> Add Facility
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <form method="POST" class="workflow-status-form d-inline-block w-100">
                                                <input type="hidden" name="assignment_ids" value="<?= htmlspecialchars($composite_ids_str); ?>">
                                                <input type="hidden" name="proposal_ref" value="<?= htmlspecialchars($row['proposal_ref']); ?>">
                                                <input type="hidden" name="update_status" value="1">
                                                
                                                <select name="proposal_status" 
                                                        class="form-select form-select-sm border-primary fw-semibold text-primary font-monospace shadow-sm workflow-status-select" 
                                                        data-file-id="<?= $row['file_rec_id']; ?>" 
                                                        onchange="handleStatusChange(this)" 
                                                        style="font-size:0.78rem;">
                                                    <?php
                                                    $stages = [
                                                        "Proposal Received", "Pending", "Committee Memo", "Committee Minutes", "Office Note", "Board Memo", 
                                                        "Board Minutes", "Approval/Sanction", "Declined"
                                                    ];
                                                    foreach ($stages as $stage) {
                                                        $selected = ($row['proposal_status'] === $stage) ? 'selected' : '';
                                                        echo '<option value="' . htmlspecialchars($stage) . '" ' . $selected . '>' . htmlspecialchars($stage) . '</option>';
                                                    }
                                                    ?>
                                                </select>
                                                
                                                <div class="remarks-wrapper d-none mt-2">
                                                    <input type="text" name="remarks" class="form-control form-control-sm dynamic-remarks-input border-warning" placeholder="Provide context...">
                                                    <button type="submit" class="btn btn-xs btn-warning w-100 mt-1 btn-sm font-monospace text-dark fw-bold" style="font-size:10px;">Reason Submit</button>
                                                </div>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($row['proposal_status'] === 'Approval/Sanction' && $user_role === 'admin'): ?>
                                            <div class="pt-2 border-top border-light mt-2 d-flex flex-column gap-2">
                                                <a href="add_facility.php?file_id=<?= intval($row['file_rec_id']); ?>" class="btn btn-sm btn-success fw-bold font-monospace shadow-sm w-100" style="font-size:11px; padding: 6px 4px;">
                                                    <i class="fas fa-plus-circle me-1"></i> Add Facility
                                                </a>
                                                <div class="text-muted small font-monospace text-center">Admin can keep editing or revoke this status above.</div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (isset($user_role) && $user_role === 'admin'): ?>
                                            <div class="pt-2 border-top border-light mt-2 d-flex justify-content-end">
                                                <form method="POST" onsubmit="return confirm('Confirm permanent deletion of this referenced proposal parameter track?');">
                                                    <input type="hidden" name="action_type" value="delete_assignment">
                                                    <input type="hidden" name="assignment_ids" value="<?= htmlspecialchars($composite_ids_str); ?>">
                                                    <button type="submit" class="btn btn-link btn-sm text-danger p-0 m-0 text-decoration-none small" style="font-size:11px;">
                                                        <i class="fas fa-trash-alt me-1"></i>Delete Assignment
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted p-5 bg-white">
                                    <i class="fas fa-folder-open fa-3x text-light mb-3"></i><br>
                                    No active records found. Approval/Sanction records older than 15 days are archived.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
function handleStatusChange(element) {
    const statusValue = element.value;
    toggleRemarksInput(element);
}

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
        if (element.value !== "") {
            form.submit();
        }
    }
}
</script>
</body>
</html>