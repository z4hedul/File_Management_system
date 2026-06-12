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
                } else {
                    $action_msg = '<div class="alert alert-danger shadow-sm"><strong>Database Error:</strong> Could not complete batch deletion.</div>';
                }
            } catch (mysqli_sql_exception $e) {
                $action_msg = "<div class='alert alert-danger shadow-sm'><strong>Database Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    } else {
        $action_msg = '<div class="alert alert-danger shadow-sm"><strong>Access Denied:</strong> Authorized Corporate System Administrators only.</div>';
    }
}

// ================= INLINE FORM PROCESSOR INTERFACE =================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_type']) && $_POST['action_type'] !== 'delete_assignment') {
    $target_assignment_ids = $_POST['assignment_ids'] ?? ''; 
    $associated_file_id   = intval($_POST['file_id'] ?? 0); 
    $new_status           = trim($_POST['proposal_status'] ?? '');
    $remarks              = trim($_POST['remarks'] ?? '');

    if (!empty($target_assignment_ids) && $associated_file_id > 0) {
        $id_array = array_map('intval', explode(',', $target_assignment_ids));
        $id_placeholder = implode(',', $id_array);

        if ($_POST['action_type'] === 'admin_reassign' && $user_role === 'admin') {
            $new_officer_id = intval($_POST['new_officer_id'] ?? 0);
            if ($new_officer_id > 0) {
                try {
                    $stmt1 = $conn->prepare("UPDATE office_files SET assigned_user_id = ? WHERE id = ?");
                    $stmt1->bind_param("ii", $new_officer_id, $associated_file_id);
                    $stmt1->execute();
                    $stmt1->close();

                    $query2 = "UPDATE proposal_assignments SET user_id = ?, remarks = 'Reassigned by Admin' WHERE id IN ($id_placeholder)";
                    $stmt2 = $conn->prepare($query2);
                    $stmt2->bind_param("i", $new_officer_id);
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
                $query = "UPDATE proposal_assignments SET proposal_status = ?, remarks = ? WHERE id IN ($id_placeholder)";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ss", $new_status, $remarks);
                $stmt->execute();
                $stmt->close();

                if ($new_status === 'Approval/Sanction') {
                    header("Location: add_facility.php?file_id=" . $associated_file_id);
                    exit;
                }

                $action_msg = "<div class='alert alert-success py-2'><i class='fas fa-check-circle me-1'></i> Pipelines updated successfully to: <strong>$new_status</strong></div>";
            } catch (mysqli_sql_exception $e) {
                $action_msg = "<div class='alert alert-danger py-2'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
}

// Filter Layout Parameters
$officer_id = $_GET['officer_id'] ?? '';
$from_date  = $_GET['from_date'] ?? '';
$to_date    = $_GET['to_date'] ?? '';

$officers_array = [];
$officers_list = $conn->query("SELECT id, full_name, username FROM users ORDER BY full_name ASC");
while($row_off = $officers_list->fetch_assoc()) {
    $officers_array[] = $row_off;
}

// ================= MASTER LEDGER QUERY =================
$query = "SELECT 
            GROUP_CONCAT(pa.id) AS assignment_ids,
            pa.file_id,
            pa.user_id AS assigned_officer_id,
            pa.proposal_status AS assignment_log_status,
            GROUP_CONCAT(IFNULL(pa.proposal_type, 'N/A') SEPARATOR '||') AS combined_proposal_types,
            GROUP_CONCAT(IFNULL(pa.proposal_amount, 0) SEPARATOR '||') AS combined_proposal_amounts,
            SUM(pa.proposal_amount) AS overall_total_amount,
            pa.remarks AS assignment_remarks,
            MAX(pa.assigned_date) AS assigned_date,
            f.client AS client_name,
            f.file_no,
            f.cabinet_name,
            f.shelf_name,
            f.branch_name,
            u.full_name AS officer_name,
            u.employee_id,
            u.username,
            (SELECT MAX(sanction_date) FROM file_facilities WHERE file_id = pa.file_id) AS official_sanction_date,
            (SELECT sanction_letter_ref_no FROM file_facilities WHERE file_id = pa.file_id ORDER BY id DESC LIMIT 1) AS official_sanction_ref
          FROM proposal_assignments pa
          INNER JOIN office_files f ON pa.file_id = f.id
          INNER JOIN users u ON pa.user_id = u.id
          WHERE f.is_deleted = 0";

$params = [];
$types = "";

// Apply Active Officer Filters if Chosen
if (!empty($officer_id)) {
    $query .= " AND pa.user_id = ?";
    $params[] = intval($officer_id);
    $types .= "i";
}

// ================= DYNAMIC DATE CASTING CORRECTION =================
if (!empty($from_date) && !empty($to_date)) {
    $query .= " AND DATE(pa.assigned_date) BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
    $types .= "ss";
} else {
    $query .= " AND NOT (
        pa.proposal_status = 'Approval/Sanction' 
        AND COALESCE(
            (SELECT MAX(sanction_date) FROM file_facilities WHERE file_id = pa.file_id), 
            pa.assigned_date
        ) < NOW() - INTERVAL 7 DAY
    )";
}

$query .= " GROUP BY pa.file_id, pa.user_id, pa.proposal_status ORDER BY assigned_date DESC";

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

    <div class="card shadow-sm border-0 mb-4 bg-white">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted text-uppercase">Filter By Dealing Officer</label>
                    <select name="officer_id" class="form-select border-primary">
                        <option value="">-- View All Active Assignments --</option>
                        <?php foreach($officers_array as $off): ?>
                            <option value="<?= $off['id'] ?>" <?= $officer_id == $off['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(!empty($off['full_name']) ? $off['full_name'] : $off['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">From Date</label>
                    <input type="date" name="from_date" class="form-control border-primary" value="<?= htmlspecialchars($from_date) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">To Date</label>
                    <input type="date" name="to_date" class="form-control border-primary" value="<?= htmlspecialchars($to_date) ?>">
                </div>

                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 fw-bold shadow-sm"><i class="fas fa-filter me-1"></i> Apply Filters</button>
                    <a href="proposal_assignments.php" class="btn btn-outline-secondary" title="Reset Filters"><i class="fas fa-undo"></i></a>
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
                            <th style="width: 28%;">Client & Combined Facility Details</th>
                            <th style="width: 14%;">Storage Location</th>
                            <th style="width: 18%;">Assigned Dealing Officer</th>
                            <th style="width: 14%;">Proposal Status</th>
                            <th style="width: 14%;" class="text-center">Workflow Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($total_records > 0): ?>
                            <?php while($row = $ledger_data->fetch_assoc()): ?>
                            <?php 
                                $status_state = trim($row['assignment_log_status'] ?? ''); 
                                $has_permission = (intval($row['assigned_officer_id']) === intval($current_user_id) || $user_role === 'admin');
                                
                                $arr_types = explode('||', $row['combined_proposal_types'] ?? '');
                                $arr_amounts = explode('||', $row['combined_proposal_amounts'] ?? '');
                            ?>
                            <tr>
                                <td class="ps-3 font-monospace small text-secondary">
                                    <?= !empty($row['assigned_date']) ? date('d-M-Y h:i A', strtotime($row['assigned_date'])) : 'N/A' ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark mb-1" style="font-size: 1.05rem;"><?= htmlspecialchars($row['client_name']) ?></div>
                                    
                                    <div class="d-flex flex-column gap-1 mb-2">
                                        <?php foreach($arr_types as $index => $type): ?>
                                            <?php $amt = $arr_amounts[$index] ?? 0; ?>
                                            <div class="d-inline-flex align-items-center gap-2 flex-wrap">
                                                <span class="badge bg-info text-dark fw-bold border border-info-subtle shadow-sm" style="font-size: 0.75rem; padding: 3px 8px;">
                                                    <i class="fas fa-layer-group me-1"></i><?= htmlspecialchars($type) ?>
                                                </span>
                                                <span class="fw-bold text-dark font-monospace" style="font-size: 0.85rem;">
                                                    $<?= number_format((float)$amt, 2) ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="pt-1 border-top border-light mb-2 d-flex align-items-center gap-1">
                                        <span class="text-uppercase fw-bold text-muted font-monospace" style="font-size: 0.7rem; letter-spacing:0.5px;">Group Total:</span>
                                        <span class="badge bg-success text-white fw-bold font-monospace" style="font-size: 0.85rem; padding: 2px 7px;">
                                            $<?= number_format((float)($row['overall_total_amount'] ?? 0), 2) ?>
                                        </span>
                                    </div>

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
                                                <input type="hidden" name="assignment_ids" value="<?= htmlspecialchars($row['assignment_ids'] ?? '') ?>">
                                                <input type="hidden" name="file_id" value="<?= $row['file_id'] ?>">
                                                
                                                <select name="new_officer_id" class="form-select form-select-sm border-danger text-danger bg-danger-subtle fw-bold" style="font-size: 11px; padding: 2px 5px;" onchange="if(confirm('Are you sure you want to reassign all combined proposals for this client?')) this.form.submit();">
                                                    <option value="">-- Reassign Group... --</option>
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
                                            
                                            // Render custom sanction parameters matching the corrected schema column name
                                            if (!empty($row['official_sanction_date']) || !empty($row['official_sanction_ref'])) {
                                                echo '<div class="mt-2 p-2 bg-success-subtle text-success rounded border border-success-subtle text-start shadow-sm" style="font-size: 11px; line-height: 1.4;">';
                                                if (!empty($row['official_sanction_date'])) {
                                                    echo '<div class="d-flex justify-content-between"><strong>Date:</strong> <span class="font-monospace fw-bold">' . date('d-M-Y', strtotime($row['official_sanction_date'])) . '</span></div>';
                                                }
                                                if (!empty($row['official_sanction_ref'])) {
                                                    echo '<div class="d-flex justify-content-between align-items-start gap-1"><strong>Ref:</strong> <span class="text-break font-monospace fw-bold text-end">' . htmlspecialchars($row['official_sanction_ref']) . '</span></div>';
                                                }
                                                echo '</div>';
                                            }
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
                                        echo '<div class="alert alert-warning p-1 mt-1 mb-0 small border-0 font-monospace" style="font-size:11px; line-height:1.2;"><strong>Notes:</strong> ' . htmlspecialchars($row['assignment_remarks']) . '</div>';
                                    }
                                    ?>
                                </td>

                                <td class="text-center bg-light-subtle">
                                    <div class="d-flex flex-column gap-1">
                                    <?php if ($has_permission): ?>
                                        
                                        <?php if ($status_state === 'Proposal In Preparation'): ?>
                                            <form method="POST" class="m-0">
                                                <input type="hidden" name="action_type" value="receive">
                                                <input type="hidden" name="assignment_ids" value="<?= htmlspecialchars($row['assignment_ids'] ?? '') ?>">
                                                <input type="hidden" name="file_id" value="<?= $row['file_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold shadow-sm py-1.5">
                                                    <i class="fas fa-clipboard-check me-1"></i> Receive Group
                                                </button>
                                            </form>
                                        <?php elseif ($status_state === 'Approval/Sanction'): ?>
                                            <a href="add_facility.php?file_id=<?= $row['file_id'] ?>" class="btn btn-sm btn-success w-100 fw-bold">
                                                <i class="fas fa-plus-circle me-1"></i> Add Facility
                                            </a>
                                            <button type="button" class="btn btn-xs btn-link text-danger p-0 border-0 small text-decoration-none" style="font-size:11px;" onclick="showCorrectionRow(this)">
                                                <i class="fas fa-undo"></i> Revert Mistake
                                            </button>
                                            
                                            <div class="correction-box d-none mt-1">
                                                <form method="POST" class="m-0">
                                                    <input type="hidden" name="action_type" value="update_status">
                                                    <input type="hidden" name="assignment_ids" value="<?= htmlspecialchars($row['assignment_ids'] ?? '') ?>">
                                                    <input type="hidden" name="file_id" value="<?= $row['file_id'] ?>">
                                                    <input type="hidden" name="proposal_status" value="Pending">
                                                    <input type="text" name="remarks" class="form-control form-control-sm border-danger mb-1" placeholder="Why revert?" required style="font-size:11px;">
                                                    <button type="submit" class="btn btn-danger btn-sm w-100 py-0" style="font-size:11px;">Confirm Revert</button>
                                                </form>
                                            </div>
                                        <?php elseif ($status_state === 'Declined'): ?>
                                            <button type="button" class="btn btn-xs btn-outline-secondary w-100 py-1" style="font-size:11px;" onclick="showCorrectionRow(this)">
                                                <i class="fas fa-undo"></i> Revert Status
                                            </button>
                                            <div class="correction-box d-none mt-1">
                                                <form method="POST" class="m-0">
                                                    <input type="hidden" name="action_type" value="update_status">
                                                    <input type="hidden" name="assignment_ids" value="<?= htmlspecialchars($row['assignment_ids'] ?? '') ?>">
                                                    <input type="hidden" name="file_id" value="<?= $row['file_id'] ?>">
                                                    <input type="hidden" name="proposal_status" value="Pending">
                                                    <input type="text" name="remarks" class="form-control form-control-sm border-secondary mb-1" placeholder="Reason..." required style="font-size:11px;">
                                                    <button type="submit" class="btn btn-secondary btn-sm w-100 py-0" style="font-size:11px;">Revert to Pending</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <form method="POST" class="d-flex flex-column gap-1 m-0 workflow-status-form">
                                                <input type="hidden" name="action_type" value="update_status">
                                                <input type="hidden" name="assignment_ids" value="<?= htmlspecialchars($row['assignment_ids'] ?? '') ?>">
                                                <input type="hidden" name="file_id" value="<?= $row['file_id'] ?>">
                                                
                                                <select name="proposal_status" class="form-select form-select-sm border-primary py-1 proposal-stage-selector" style="font-size: 0.8rem;" onchange="toggleRemarksInput(this)">
                                                    <option value="">-- Change Status --</option>
                                                    <option value="Pending">Pending / On Hold</option>
                                                    <option value="Committee Memo">Committee Memo</option>
                                                    <option value="Committee Minutes">Committee Minutes</option>
                                                    <option value="Office Note">Office Note</option>
                                                    <option value="Board Memo">Board Memo</option>
                                                    <option value="Board Minutes">Board Minutes</option>
                                                    <option value="Approval/Sanction">Approval/Sanction</option>
                                                    <option value="Declined">Declined / Rejected</option>
                                                </select>

                                                <div class="remarks-wrapper d-none">
                                                    <div class="input-group input-group-sm mt-1">
                                                        <input type="text" name="remarks" class="form-control border-warning dynamic-remarks-input" placeholder="Reason..." style="font-size:11px;">
                                                        <button class="btn btn-warning fw-bold text-dark" type="submit" style="font-size:11px;">Save</button>
                                                    </div>
                                                </div>
                                            </form>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <span class="text-muted small italic opacity-75"><i class="fas fa-user-slash me-1"></i> View Only</span>
                                    <?php endif; ?>

                                    <?php if ($user_role === 'admin'): ?>
                                        <form method="POST" class="m-0 mt-1" onsubmit="return confirm('CRITICAL SECURITY ACTION:\n\nAre you sure you want to permanently delete this assignment entry group from the corporate tracking boards? This cannot be undone.');">
                                            <input type="hidden" name="action_type" value="delete_assignment">
                                            <input type="hidden" name="assignment_ids" value="<?= htmlspecialchars($row['assignment_ids'] ?? '') ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger w-100 py-1" style="font-size: 11px;">
                                                <i class="fas fa-trash-alt me-1"></i> Remove Assignment
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted p-5 bg-white">
                                    <i class="fas fa-folder-open fa-3x text-light mb-3"></i><br>
                                    No records found matching current parameters.
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
function showCorrectionRow(btn) {
    const box = btn.nextElementSibling || btn.parentElement.querySelector('.correction-box');
    if(box) box.classList.toggle('d-none');
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
        if(element.value !== "") form.submit();
    }
}
</script>
</body>
</html>