<?php
session_start();
include 'db.php';

// Auth Guard
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

// Get the logged-in user's ID from the session
$logged_user_id = intval($_SESSION['user_id'] ?? 0);
$status_message = '';

// --- ACTION 1: HANDLE "RECEIVE TASK" CLICK ---
if (isset($_GET['action']) && $_GET['action'] === 'receive') {
    $file_id = intval($_GET['file_id'] ?? 0);
    if ($file_id > 0) {
        // Set initial operational status when received
        $stmt = $conn->prepare("UPDATE office_files SET proposal_status = 'Office Note' WHERE id = ? AND assigned_user_id = ?");
        $stmt->bind_param("ii", $file_id, $logged_user_id);
        if ($stmt->execute()) {
            $status_message = '<div class="alert alert-success py-2">Task Received! You can now update its status stage below.</div>';
        }
    }
}


// --- ACTION 2: HANDLE STATUS DROPDOWN UPDATE WITH SANCTION ROUTING ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_status'])) {
    $file_id = intval($_POST['file_id'] ?? 0);
    $new_status = trim($_POST['proposal_status'] ?? '');
    
    $allowed_stages = ['Office Note', 'Committee Memo', 'Committee Minutes', 'Board Memo', 'Board Minutes', 'Sanction'];
    
    if ($file_id > 0 && in_array($new_status, $allowed_stages)) {
        $stmt = $conn->prepare("UPDATE office_files SET proposal_status = ? WHERE id = ? AND assigned_user_id = ?");
        $stmt->bind_param("sii", $new_status, $file_id, $logged_user_id);
        
        if ($stmt->execute()) {
            if ($new_status === 'Sanction') {
                // AUTOMATIC REDIRECT: Go straight to the facility and uploads page with client context
                header("Location: add_facility.php?id=" . $file_id);
                exit;
            } else {
                $status_message = '<div class="alert alert-success py-2">Workflow stage updated to ' . $new_status . '</div>';
            }
        }
    }
}

// --- FETCH ONLY THE CLIENTS ASSIGNED TO THIS LOGGED-IN OFFICER ---
$query = "SELECT id, client, cabinet_name, shelf_name, proposal_status 
          FROM office_files 
          WHERE is_deleted = 0 AND assigned_user_id = ? 
          ORDER BY id DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $logged_user_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Assigned Proposals</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light p-5">

<div class="container bg-white p-4 shadow rounded">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
        <div>
            <h4 class="text-primary mb-1"><i class="fas fa-folder-open text-warning me-2"></i> My Proposal Workbench</h4>
            <small class="text-muted">Logged in Officer Workspace: Viewing your exclusive file assignments.</small>
        </div>
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-home me-1"></i> Main Dashboard</a>
    </div>

    <?php echo $status_message; ?>

    <div class="table-responsive">
        <table class="table table-striped table-bordered table-hover align-middle">
            <thead class="table-dark small text-uppercase">
                <tr>
                    <th>Client Name</th>
                    <th>Cabinet No.</th>
                    <th>Shelf No.</th>
                    <th class="text-center" style="width: 320px;">Proposal Workflow Stage Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <?php $current_status = $row['proposal_status'] ?? 'Assigned'; ?>
                        <tr>
                            <td class="fw-bold text-secondary"><?php echo htmlspecialchars($row['client']); ?></td>
                            <td><code><?php echo htmlspecialchars($row['cabinet_name']); ?></code></td>
                            <td><?php echo htmlspecialchars($row['shelf_name'] ?? 'N/A'); ?></td>
                            
                            <td class="text-center">
                                <?php if ($current_status === 'Assigned' || empty($current_status) || $current_status === 'Proposal In Preparation'): ?>
                                    <a href="proposal_assignments.php?action=receive&file_id=<?php echo $row['id']; ?>" class="btn btn-success btn-sm w-100 fw-bold">
                                        <i class="fas fa-hand-holding me-1"></i> Receive Task </a>
                                <?php else: ?>
                                    <form method="POST" class="d-flex gap-2 align-items-center m-0">
                                        <input type="hidden" name="file_id" value="<?php echo $row['id']; ?>">
                                        <input type="hidden" name="update_status" value="1">
                                        
                                        <select name="proposal_status" class="form-select form-select-sm border-primary fw-bold" onchange="this.form.submit()">
                                            <?php
                                            $stages = ['Office Note', 'Committee Memo', 'Committee Minutes', 'Board Memo', 'Board Minutes', 'Sanction'];
                                            foreach ($stages as $stage) {
                                                $selected = ($current_status === $stage) ? 'selected' : '';
                                                echo "<option value='$stage' $selected>$stage</option>";
                                            }
                                            ?>
                                        </select>
                                        <span class="badge bg-primary p-2"><i class="fas fa-sync-alt fa-spin"></i></span>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="text-center py-4 text-muted">
                            <i class="fas fa-inbox fa-2x mb-2 d-block text-secondary"></i>
                            No client proposals have been assigned to your account yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>