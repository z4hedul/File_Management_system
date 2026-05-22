<?php
session_start();
include 'db.php';

// Security Check (Ensure only logged-in Admins can register new system accounts)
if (!isset($_SESSION['loggedin'])) { header("location: login.php"); exit; }
if ($_SESSION['role'] !== 'admin') {
    echo "<script>alert('Access Denied'); window.location.href='index.php';</script>";
    exit;
}

$status = $_GET['status'] ?? null;

// POST Insertion Handler
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username     = trim($_POST['username']);
    $password     = trim($_POST['password']);
    $role         = $_POST['role'];
    $full_name    = trim($_POST['full_name']);
    $designation  = trim($_POST['designation']);
    $employee_id  = trim($_POST['employee_id']);

    if (!empty($username) && !empty($password)) {
        // Securely hash password entry
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);

        // Prepared Statement parsing all six attributes securely
        $insert_sql = "INSERT INTO users (username, password, role, full_name, designation, employee_id) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_sql);
        $stmt->bind_param("ssssss", $username, $hashed_password, $role, $full_name, $designation, $employee_id);

        if ($stmt->execute()) {
            header("Location: add_user.php?status=success");
            exit;
        } else {
            $error_msg = "Execution failed: " . htmlspecialchars($stmt->error);
        }
        $stmt->close();
    } else {
        $error_msg = "Please fill out all fundamental baseline fields.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>System Settings - Add Internal User</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light p-5">

<div class="container" style="max-width: 650px;">

    <?php if ($status === 'success'): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
            <i class="fas fa-user-check me-2"></i> <strong>Registration Successful!</strong> The profile record has been added to the master database.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php echo "<script>setTimeout(() => { window.location.href='index.php'; }, 2000);</script>"; ?>
    <?php endif; ?>

    <?php if (isset($error_msg)): ?>
        <div class="alert alert-danger shadow-sm" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error_msg; ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-dark text-white p-3 d-flex justify-content-between align-items-center">
            <span class="fw-bold"><i class="fas fa-user-plus me-2 text-warning"></i> Add New Employee Account</span>
            <a href="index.php" class="btn btn-sm btn-outline-light" onclick="return confirm('Exit page form details?');"><i class="fas fa-home"></i></a>
        </div>
        <div class="card-body p-4 bg-white">
            <form method="post" action="add_user.php">
                
                <h5 class="text-secondary border-bottom pb-2 mb-3"><i class="fas fa-id-card me-1"></i> Profile Parameters</h5>
                
                <div class="row g-3 mb-4">
                    <div class="col-md-12">
                        <label class="form-label small text-uppercase fw-bold text-muted">Full Legal Name</label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Johnathan Doe" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-uppercase fw-bold text-muted">Employee ID / Code</label>
                        <input type="text" name="employee_id" class="form-control" placeholder="Last 4 digits PF" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-uppercase fw-bold text-muted">Designation</label>
                        <input type="text" name="designation" class="form-control" placeholder="e.g. Officer, SO, SPO, AVP, SVP" required>
                    </div>
                </div>

                <h5 class="text-secondary border-bottom pb-2 mb-3"><i class="fas fa-key me-1"></i> Access System Authentication</h5>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label small text-uppercase fw-bold text-muted">Account Username</label>
                        <input type="text" name="username" class="form-control" placeholder="Unique account login nickname" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-uppercase fw-bold text-muted">Account System Role</label>
                        <select name="role" class="form-select" required>
                            <option value="user" selected>Standard User Account</option>
                            <option value="admin">Administrative Manager</option>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label small text-uppercase fw-bold text-muted">Secure Password Access</label>
                        <input type="password" name="password" class="form-control" placeholder="Enter clean alphanumeric safety string" required>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                    <a href="index.php" class="btn btn-secondary" onclick="return confirm('Cancel registration process?');">Cancel</a>
                    <button type="submit" class="btn btn-primary px-4 shadow-sm fw-bold">Register User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>