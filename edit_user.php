<?php
session_start();
include 'db.php';
include 'header.php';
if (!isset($_SESSION['loggedin']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("location: index.php");
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id) { header("location: manage_users.php"); exit; }

$message = "";

// Pull structural operating groups for user assignment
$active_groups = $conn->query("
    SELECT g.id, g.group_name, u.full_name AS leader_name 
    FROM user_groups g
    LEFT JOIN users u ON g.leader_id = u.id 
    ORDER BY g.group_name ASC
");

// 1. FETCH EXPANDED USER DATA (Added group_id mapping field)
$stmt = $conn->prepare("SELECT username, role, full_name, designation, division, employee_id, group_id FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) { header("location: manage_users.php"); exit; }

// 2. POST UPDATE PIPELINE
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_user        = trim($_POST['username']);
    $new_role        = $_POST['role'];
    $new_full_name   = trim($_POST['full_name']);
    $new_designation = trim($_POST['designation']);
    $new_division    = trim($_POST['division']);
    $new_emp_id      = trim($_POST['employee_id']);
    $new_pass        = $_POST['password'];
    
    // Safely structure group data entry mapping (Converts empty choice string to explicit SQL NULL)
   $new_group_id = !empty($_POST['group_id']) ? intval($_POST['group_id']) : null;

if (!empty($new_pass)) {
    $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
    $upd = $conn->prepare("UPDATE users SET username = ?, password = ?, role = ?, full_name = ?, designation = ?, division = ?, employee_id = ?, group_id = ? WHERE id = ?");
    $upd->bind_param("sssssssii", $new_user, $hashed, $new_role, $new_full_name, $new_designation, $new_division, $new_emp_id, $new_group_id, $id);
} else {
    $upd = $conn->prepare("UPDATE users SET username = ?, role = ?, full_name = ?, designation = ?, division = ?, employee_id = ?, group_id = ? WHERE id = ?");
    $upd->bind_param("ssssssii", $new_user, $new_role, $new_full_name, $new_designation, $new_division, $new_emp_id, $new_group_id, $id);
}

if ($upd->execute()) {
    // ... setup alert updates ...
    $user['group_id'] = $new_group_id; // Update local runtime state array
} else {
        $message = "<div class='alert alert-danger'><i class='fas fa-times-circle me-1'></i> Update failed: " . htmlspecialchars($conn->error) . "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit User Profile</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    
    <style>
        @media screen and (max-width: 1400px) {
            body {
                zoom: 90%;
                -moz-transform: scale(0.9);
                -moz-transform-origin: top center;
            }
            .g-3, .mb-3, .mb-4, .row {
                margin-bottom: 0.6rem !important;
                margin-top: 0.1rem !important;
            }
            .container {
                padding: 0.75rem !important;
            }
        }
    </style>
</head>
<body class="bg-light p-5">
<div class="container bg-white p-4 shadow rounded" style="max-width: 600px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0 text-dark"><i class="fas fa-user-edit text-primary me-2"></i>Edit Profile</h3>
        <a href="index.php" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Exit editor page? Changes might be discarded.');"><i class="fas fa-home"></i> Home</a>
    </div>
    <hr>
    
    <?= $message ?>
    
    <form method="POST">
        
        <h5 class="text-secondary mb-3 small fw-bold text-uppercase border-bottom pb-1"><i class="fas fa-id-card me-1"></i> HR Employee Particulars</h5>
        
        <div class="mb-3">
            <label class="fw-bold small text-muted">Full Legal Name</label>
            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
        </div>

        <div class="row mb-3 g-3">
            <div class="col-md-6">
                <label class="fw-bold small text-muted">Employee ID / Code</label>
                <input type="text" name="employee_id" class="form-control" value="<?= htmlspecialchars($user['employee_id'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
                <label class="fw-bold small text-muted">Designation / Title</label>
                <input type="text" name="designation" class="form-control" value="<?= htmlspecialchars($user['designation'] ?? '') ?>" required>
            </div>
            <div class="col-md-4">
                <label class="fw-bold small text-muted">Division</label>
                <select name="division" class="form-select" required>
                    <option value="Investment" <?= $user['division'] == 'Investment' ? 'selected' : '' ?>>Investment</option>
                    <option value="SME" <?= $user['division'] == 'SME' ? 'selected' : '' ?>>SME</option>
                    <option value="IMRD" <?= $user['division'] == 'IMRD' ? 'selected' : '' ?>>IMRD</option>
                </select>
            </div>
            
          <div class="mb-3">
    <label class="fw-bold small text-muted">Operating Group / Team Assignment</label>
    <select name="group_id" class="form-select">
        <option value="">Independent / No Assigned Group</option>
        <?php if ($active_groups && $active_groups->num_rows > 0): ?>
            <?php while($g_row = $active_groups->fetch_assoc()): ?>
                <option value="<?= $g_row['id'] ?>" <?= ($user['group_id'] == $g_row['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($g_row['group_name']) ?> 
                    (Leader: <?= htmlspecialchars($g_row['leader_name'] ?? 'None') ?>)
                </option>
            <?php endwhile; ?>
        <?php endif; ?>
    </select>
</div>

        <h5 class="text-secondary mt-4 mb-3 small fw-bold text-uppercase border-bottom pb-1"><i class="fas fa-shield-alt me-1"></i> System Access Settings</h5>

        <div class="row mb-3 g-3">
            <div class="col-md-6">
                <label class="fw-bold small text-muted">Account Login Username</label>
                <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="fw-bold small text-muted">System Level Role</label>
                <select name="role" class="form-select">
                    <option value="user" <?= $user['role'] == 'user' ? 'selected' : '' ?>>Standard User Account</option>
                    <option value="admin" <?= $user['role'] == 'admin' ? 'selected' : '' ?>>Administrative Manager</option>
                </select>
            </div>
        </div>

        <div class="mb-3">
            <label class="fw-bold small text-muted">Override Administrative Password</label>
            <input type="password" name="password" class="form-control" placeholder="Leave blank to preserve present server key">
        </div>

        <div class="d-grid gap-2 mt-4 pt-2 border-top">
            <button type="submit" class="btn btn-primary fw-bold py-2 shadow-sm"><i class="fas fa-save me-1"></i> Save Changes</button>
            <div class="row g-2 mt-1">
                <div class="col-6">
                    <a href="manage_users.php" class="btn btn-sm btn-outline-secondary w-100">User Master List</a>
                </div>
                <div class="col-6">
                    <a href="index.php" class="btn btn-sm btn-outline-dark w-100">Dashboard Layout</a>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include 'footer.php'; ?>
</body>
</html>