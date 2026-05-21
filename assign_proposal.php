<?php
session_start();
include 'db.php';

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

// 2. Fetch the client's information to show inside the form inputs
if ($preset_file_id > 0) {
    $client_stmt = $conn->prepare("SELECT client, file_no, division FROM office_files WHERE id = ? AND is_deleted = 0");
    $client_stmt->bind_param("i", $preset_file_id);
    $client_stmt->execute();
    $client_data = $client_stmt->get_result()->fetch_assoc();
    
    if ($client_data) {
        $preset_client_name = $client_data['client'];
        $preset_file_no = $client_data['file_no'];
        $preset_division = $client_data['division'] ?? 'N/A';
    } else {
        $status_message = '<div class="alert alert-danger">Target client file record not found.</div>';
    }
} else {
    // If someone accesses this page without clicking an assign link from a client row
    header("Location: index.php");
    exit;
}

// 3. Handle Assignment Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $file_id = $preset_file_id; // Use the locked URL ID safely
    $user_id = intval($_POST['user_id'] ?? 0);

    if ($file_id > 0 && $user_id > 0) {
        $stmt = $conn->prepare("UPDATE office_files SET assigned_user_id = ?, proposal_status = 'Proposal In Preparation' WHERE id = ?");
        $stmt->bind_param("ii", $user_id, $file_id);
        
        if ($stmt->execute()) {
            $status_message = '<div class="alert alert-success"><i class="fas fa-check-circle me-1"></i> Officer assigned successfully! Redirecting to tracking board...</div>';
            header("refresh:2;url=proposal_assignments.php");
        } else {
            $status_message = '<div class="alert alert-danger">Error saving assignment to database.</div>';
        }
    } else {
        $status_message = '<div class="alert alert-warning">Please select a valid officer to assign this task.</div>';
    }
}

// Fetch active system users to populate the assignment dropdown
$users_query = "SELECT id, username, role FROM users ORDER BY username ASC";
$users_result = $conn->query($users_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assign Proposal Task</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light p-5">

<div class="container bg-white p-4 shadow rounded" style="max-width: 750px;">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-2">
        <h4 class="text-primary mb-0"><i class="fas fa-user-check me-2"></i> Assign Proposal Task</h4>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Back</a>
    </div>

    <?php echo $status_message; ?>

    <form method="POST">
        
        <div class="card mb-4 border-secondary shadow-sm">
            <div class="card-header bg-secondary text-white fw-bold small">
                <i class="fas fa-folder text-warning me-1"></i> AUTO-FILLED CLIENT DETAILS
            </div>
            <div class="card-body bg-light">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label fw-bold text-muted small mb-1">Client Name</label>
                        <input type="text" class="form-control bg-white" value="<?php echo htmlspecialchars($preset_client_name); ?>" readonly disabled>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label fw-bold text-muted small mb-1">File Number</label>
                        <input type="text" class="form-control bg-white font-monospace" value="<?php echo htmlspecialchars($preset_file_no); ?>" readonly disabled>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label fw-bold text-muted small mb-1">Division Branch</label>
                        <input type="text" class="form-control bg-white" value="<?php echo htmlspecialchars($preset_division); ?>" readonly disabled>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label fw-bold text-primary"><i class="fas fa-user-tie me-1"></i> Assign to Officer / Relationship Manager</label>
            <select name="user_id" class="form-select border-primary form-select-lg" required>
                <option value="">-- Click to Select Assignee --</option>
                <?php while($user = $users_result->fetch_assoc()): ?>
                    <option value="<?php echo $user['id']; ?>">
                        <?php echo htmlspecialchars(strtoupper($user['username']) . " (" . ucfirst($user['role']) . ")"); ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <div class="form-text">This selected user will be responsible for gathering information and preparing the business proposal.</div>
        </div>

        <div class="pt-3 border-top d-flex gap-2">
            <button type="submit" class="btn btn-primary px-5 shadow">
                <i class="fas fa-check me-1"></i> Confirm & Save Assignment
            </button>
            <a href="proposal_assignments.php" class="btn btn-light border">Cancel</a>
        </div>
    </form>
</div>

</body>
</html>