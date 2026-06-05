<?php
session_start();
include 'db.php';
include 'header.php';
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

$status_message = '';

// 1. Catch the direct Client ID from the URL link click
if (isset($_GET['file_id'])) {
    $preset_file_id = intval($_GET['file_id']);
} elseif (isset($_GET['id'])) {
    $preset_file_id = intval($_GET['id']);
} else {
    $preset_file_id = 0;
}

$preset_client_name = '';
$preset_file_no = '';
$preset_division = '';
$preset_branch_name = '';
$preset_cabinet_name = '';
$preset_shelf_name = '';

// 2. Fetch the client's information from office_files to show inside read-only fields
if ($preset_file_id > 0) {
    $client_stmt = $conn->prepare("SELECT client, file_no, division, branch_name, cabinet_name, shelf_name FROM office_files WHERE id = ? AND is_deleted = 0");
    $client_stmt->bind_param("i", $preset_file_id);
    $client_stmt->execute();
    $client_data = $client_stmt->get_result()->fetch_assoc();
    
    if ($client_data) {
        $preset_client_name  = $client_data['client'];
        $preset_file_no      = $client_data['file_no'];
        $preset_division     = $client_data['division'] ?? 'N/A';
        $preset_branch_name   = $client_data['branch_name'] ?? 'N/A';
        $preset_cabinet_name  = $client_data['cabinet_name'] ?? 'N/A';
        $preset_shelf_name    = $client_data['shelf_name'] ?? 'N/A';
    } else {
        $status_message = '<div class="alert alert-danger">Target client file record not found.</div>';
    }
} else {
    header("Location: index.php");
    exit;
}

// 3. Handle Assignment Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $file_id         = $preset_file_id; 
    $user_id         = intval($_POST['user_id'] ?? 0);
    $proposal_status = trim($_POST['proposal_status'] ?? 'Proposal In Preparation');
    
    // Arrays sent via the dynamic form elements
    $proposal_types       = $_POST['proposal_type'] ?? [];
    $proposal_types_other = $_POST['proposal_type_other'] ?? [];
    $proposal_amounts     = $_POST['proposal_amount'] ?? [];

    if ($file_id > 0 && $user_id > 0 && !empty($proposal_types)) {
        
        $conn->begin_transaction();

        try {
            // A. Prepare query to insert data across your exact table structure
            $log_stmt = $conn->prepare("INSERT INTO proposal_assignments (file_id, user_id, proposal_status, proposal_amount, proposal_type, assigned_date) VALUES (?, ?, ?, ?, ?, NOW())");
            
            // Loop over each dynamic entry pair entered by the operator
            for ($i = 0; $i < count($proposal_types); $i++) {
                $p_type = trim($proposal_types[$i]);
                $custom_type = trim($proposal_types_other[$i] ?? '');
                
                // Fallback translation condition logic check for handling 'Others' text strings
                if ($p_type === 'Others' && !empty($custom_type)) {
                    $p_type = $custom_type;
                }
                
                // Clear out formatting commas from money inputs prior to floating numeric validation
                $p_amount = trim(str_replace(',', '', $proposal_amounts[$i]));
                
                if (!empty($p_type)) {
                    $log_stmt->bind_param("iisss", $file_id, $user_id, $proposal_status, $p_amount, $p_type);
                    $log_stmt->execute();
                }
            }
            $log_stmt->close();

            // B. Only update assigned_user_id inside office_files
            $upd_stmt = $conn->prepare("UPDATE office_files SET assigned_user_id = ? WHERE id = ?");
            $upd_stmt->bind_param("ii", $user_id, $file_id);
            $upd_stmt->execute();
            $upd_stmt->close();

            $conn->commit();

            $status_message = '<div class="alert alert-success"><i class="fas fa-check-circle me-1"></i> All assignments logged and status updated successfully! Redirecting...</div>';
            header("refresh:2;url=proposal_assignments.php");
            
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $status_message = '<div class="alert alert-danger">Database Transaction Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } else {
        $status_message = '<div class="alert alert-warning">Please select a valid officer and fill in at least one proposal variant.</div>';
    }
}

// Fetch active system users for the assignment select box
$users_result = $conn->query("SELECT id, username, full_name, employee_id FROM users ORDER BY full_name ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assign Proposal Task</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body class="bg-light p-5">

<div class="container bg-white p-4 shadow rounded" style="max-width: 850px;">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-2">
        <h4 class="text-primary mb-0"><i class="fas fa-user-check me-2"></i> File Assignment Registry Form</h4>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Back</a>
    </div>

    <?php echo $status_message; ?>

    <form method="POST">
        
        <div class="card mb-4 border-secondary shadow-sm">
            <div class="card-header bg-secondary text-white fw-bold small">
                <i class="fas fa-archive text-warning me-1"></i> FILE STORAGE DATA (READ ONLY)
            </div>
            <div class="card-body bg-light">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-bold text-muted small mb-1">Client Name</label>
                        <input type="text" class="form-control bg-white text-dark fw-bold" value="<?php echo htmlspecialchars($preset_client_name); ?>" readonly disabled>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-muted small mb-1">Branch Name</label>
                        <input type="text" class="form-control bg-white" value="<?php echo htmlspecialchars($preset_branch_name); ?>" readonly disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-muted small mb-1">Cabinet No</label>
                        <input type="text" class="form-control bg-white" value="<?php echo htmlspecialchars($preset_cabinet_name); ?>" readonly disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-muted small mb-1">Shelf No</label>
                        <input type="text" class="form-control bg-white" value="<?php echo htmlspecialchars($preset_shelf_name); ?>" readonly disabled>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4 border-primary shadow-sm">
            <div class="card-header bg-primary text-white fw-bold small">
                <i class="fas fa-edit me-1"></i> PROPOSAL SPECIFICATIONS &amp; ASSIGNMENT DETAILS
            </div>
            <div class="card-body bg-white">
                <div class="row g-3">
                    
                    <div class="col-md-12">
                        <label class="form-label fw-bold text-primary small mb-1">Assign to Officer</label>
                        <select name="user_id" class="form-select border-primary form-select-lg" required>
                            <option value="">-- Click to Select Assignee --</option>
                            <?php while($user = $users_result->fetch_assoc()): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php 
                                    $display_name = !empty($user['full_name']) ? strtoupper($user['full_name']) : strtoupper($user['username']);
                                    echo htmlspecialchars($display_name . " (" . ($user['employee_id'] ?? 'No ID') . ")"); 
                                    ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="col-md-12">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label fw-bold text-muted small mb-0">Proposal Facilities Information</label>
                            <button type="button" class="btn btn-sm btn-success px-3 rounded-pill fw-semibold" id="add-proposal-node-btn">
                                <i class="fas fa-plus-circle me-1"></i> Add Facility Row
                            </button>
                        </div>

                        <div id="proposal-inputs-wrapper">
                            <div class="row g-2 proposal-data-row mb-3 pb-3 border-bottom align-items-start">
                                <div class="col-md-6">
                                    <label class="form-label small text-secondary font-monospace mb-1">Facility Type</label>
                                    <select name="proposal_type[]" class="form-control border-primary facility-type-select" required>
                                        <option value="">-- Select Facility Type --</option>
                                        <option value="L/C (C2C)">L/C (C2C)</option>
                                        <option value="L/C Limit">L/C Limit</option>
                                        <option value="BG (C2C)">BG (C2C)</option>
                                        <option value="BG (Limit)">BG (Limit)</option>
                                        <option value="BG(PG)">BG(PG)</option>
                                        <option value="BG(BB)">BG(BB)</option>
                                        <option value="BM(Hypo)">BM(Hypo)</option>
                                        <option value="BS(PSI)">BS(PSI)</option>
                                        <option value="BM(PIF)">BM(PIF)</option>
                                        <option value="Credit Card">Credit Card</option>
                                        <option value="Others">Others</option>
                                    </select>
                                    <input type="text" name="proposal_type_other[]" class="form-control border-primary facility-type-other mt-2" placeholder="Enter custom facility type" style="display:none;">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label small text-secondary font-monospace mb-1">Proposal Amount</label>
                                    <input type="number" step="0.01" name="proposal_amount[]" class="form-control border-primary font-monospace" placeholder="0.00" required>
                                </div>
                                <div class="col-md-1 text-center mt-4">
                                    <button type="button" class="btn btn-outline-danger btn-sm disabled opacity-25 w-100" style="padding: 7px 0;"><i class="fas fa-trash-alt"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-bold text-danger small mb-1">Select Workflow Status</label>
                        <select name="proposal_status" class="form-select border-danger bg-light-subtle fw-bold">
                            <option value="Proposal In Preparation">Proposal In Preparation</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="pt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-lg px-5 shadow">
                <i class="fas fa-save me-1"></i> Deploy &amp; Save Assignment Logs
            </button>
            <a href="index.php" class="btn btn-lg btn-light border">Cancel</a>
        </div>
    </form>
</div>

<?php include 'footer.php'; ?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const wrapper = document.getElementById("proposal-inputs-wrapper");
    const addBtn  = document.getElementById("add-proposal-node-btn");

    // Dynamic Element selection change tracking handler for tracking 'Others' state triggers
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('facility-type-select')) {
            const rowContext = e.target.closest('.proposal-data-row');
            const otherInput = rowContext.querySelector('.facility-type-other');
            if (e.target.value === 'Others') {
                otherInput.style.display = 'block';
                otherInput.required = true;
                otherInput.focus();
            } else {
                otherInput.style.display = 'none';
                otherInput.required = false;
                otherInput.value = '';
            }
        }
    });

    addBtn.addEventListener("click", function() {
        // Construct standard facility options dropdown rows mapping exactly to add_record fields
        const newRow = document.createElement("div");
        newRow.className = "row g-2 proposal-data-row mb-3 pb-3 border-bottom align-items-start";
        
        newRow.innerHTML = `
            <div class="col-md-6">
                <select name="proposal_type[]" class="form-control border-primary facility-type-select" required>
                    <option value="">-- Select Facility Type --</option>
                    <option value="L/C (C2C)">L/C (C2C)</option>
                    <option value="L/C Limit">L/C Limit</option>
                    <option value="BG (C2C)">BG (C2C)</option>
                    <option value="BG (Limit)">BG (Limit)</option>
                    <option value="BG(PG)">BG(PG)</option>
                    <option value="BG(BB)">BG(BB)</option>
                    <option value="BM(Hypo)">BM(Hypo)</option>
                    <option value="BS(PSI)">BS(PSI)</option>
                    <option value="BM(PIF)">BM(PIF)</option>
                    <option value="Credit Card">Credit Card</option>
                    <option value="Others">Others</option>
                </select>
                <input type="text" name="proposal_type_other[]" class="form-control border-primary facility-type-other mt-2" placeholder="Enter custom facility type" style="display:none;">
            </div>
            <div class="col-md-5">
                <input type="number" step="0.01" name="proposal_amount[]" class="form-control border-primary font-monospace" placeholder="0.00" required>
            </div>
            <div class="col-md-1 text-center">
                <button type="button" class="btn btn-danger btn-sm remove-facility-node w-100" style="padding: 7px 0;" title="Remove this record entry variant">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </div>
        `;
        
        wrapper.appendChild(newRow);
    });

    // Delegated Event Listener attached directly onto layout nodes to drop target elements cleanly
    wrapper.addEventListener("click", function(event) {
        if (event.target.closest(".remove-facility-node")) {
            const targetRow = event.target.closest(".proposal-data-row");
            if (targetRow) {
                targetRow.remove();
            }
        }
    });
});
</script>
</body>
</html>