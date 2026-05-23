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
    $proposal_amount = trim($_POST['proposal_amount'] ?? '');
    $proposal_type   = trim($_POST['proposal_type'] ?? '');

    if ($file_id > 0 && $user_id > 0) {
        
        $conn->begin_transaction();

        try {
            // A. Insert the full proposal spec details into the history log ledger table
            $log_stmt = $conn->prepare("INSERT INTO proposal_assignments (file_id, user_id, proposal_status, proposal_amount, proposal_type) VALUES (?, ?, ?, ?, ?)");
            $log_stmt->bind_param("iisss", $file_id, $user_id, $proposal_status, $proposal_amount, $proposal_type);
            $log_stmt->execute();
            $log_stmt->close();

            // B. FIX: Only update assigned_user_id inside office_files (Removed non-existent proposal_status column)
            $upd_stmt = $conn->prepare("UPDATE office_files SET assigned_user_id = ? WHERE id = ?");
            $upd_stmt->bind_param("ii", $user_id, $file_id);
            $upd_stmt->execute();
            $upd_stmt->close();

            $conn->commit();

            $status_message = '<div class="alert alert-success"><i class="fas fa-check-circle me-1"></i> Assignment logged and status updated successfully! Redirecting...</div>';
            header("refresh:2;url=proposal_assignments.php");
            
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $status_message = '<div class="alert alert-danger">Database Transaction Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } else {
        $status_message = '<div class="alert alert-warning">Please select a valid officer to assign this task.</div>';
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

<div class="container bg-white p-4 shadow rounded" style="max-width: 800px;">
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
                <i class="fas fa-edit me-1"></i> PROPOSAL SPECIFICATIONS & ASSIGNMENT DETAILS
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

                    <div class="col-md-6">
                        <label class="form-label fw-bold text-muted small mb-1">Proposal Type / Facility Mode</label>
                        <input type="text" name="proposal_type" class="form-control border-primary" placeholder="e.g., Term Loan, Working Capital" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold text-muted small mb-1">Proposal Amount Assessed</label>
                        <input type="text" name="proposal_amount" class="form-control border-primary font-monospace" placeholder="e.g., 5,000,000" required>
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
                <i class="fas fa-save me-1"></i> Deploy & Save Assignment Logs
            </button>
            <a href="index.php" class="btn btn-lg btn-light border">Cancel</a>
        </div>
    </form>
</div>
<?php
include 'footer.php';
?>
</body>
</html>