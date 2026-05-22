<?php
session_start();
include 'db.php';

if (!isset($_SESSION['loggedin']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("location: index.php");
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id) { header("location: manage_users.php"); exit; }

$message = "";

// 1. FETCH EXPANDED USER DATA
$stmt = $conn->prepare("SELECT username, role, full_name, designation, employee_id FROM users WHERE id = ?");
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
    $new_emp_id      = trim($_POST['employee_id']);
    $new_pass        = $_POST['password'];

    // Conditional Execution: Update parameters based on password field status
    if (!empty($new_pass)) {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET username = ?, password = ?, role = ?, full_name = ?, designation = ?, employee_id = ? WHERE id = ?");
        $upd->bind_param("ssssssi", $new_user, $hashed, $new_role, $new_full_name, $new_designation, $new_emp_id, $id);
    } else {
        $upd = $conn->prepare("UPDATE users SET username = ?, role = ?, full_name = ?, designation = ?, employee_id = ? WHERE id = ?");
        $upd->bind_param("sssssi", $new_user, $new_role, $new_full_name, $new_designation, $new_emp_id, $id);
    }

    if ($upd->execute()) {
        $message = "<div class='alert alert-success alert-dismissible fade show shadow-sm' role='alert'>
                        <i class='fas fa-check-circle me-1'></i> User profile updated successfully!
                        <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                    </div>";
        
        // Refresh local runtime copy to map modifications instantly back onto HTML placeholders
        $user['username']    = $new_user;
        $user['role']        = $new_role;
        $user['full_name']   = $new_full_name;
        $user['designation'] = $new_designation;
        $user['employee_id'] = $new_emp_id;
    } else {
        $message = "<div class='alert alert-danger'><i class='fas fa-times-circle me-1'></i> Update failed: " . htmlspecialchars($conn->error) . "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Edit User Profile</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                    <option value="user" <?= ($user['role'] == 'user' || $user['role'] == 'user') ? 'selected' : '' ?>>Standard User Account</option>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>