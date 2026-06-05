<?php
session_start();
include 'db.php';
include 'header.php';

// Security Check: Only logged-in users can change their password
if (!isset($_SESSION['loggedin'])) {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['username'];
$error_msg = null;
$success_msg = null;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $current_password = trim($_POST['current_password']);
    $new_password     = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    if (!empty($current_password) && !empty($new_password) && !empty($confirm_password)) {
        if ($new_password !== $confirm_password) {
            $error_msg = "New passwords do not match.";
        } else {
            // 1. Fetch current password hash from database to verify identities
            $stmt = $conn->prepare("SELECT password FROM users WHERE username = ? LIMIT 1");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->bind_result($hashed_password);
            $stmt->fetch();
            $stmt->close();

            // 2. Verify match before applying encryption modifications
            if (password_verify($current_password, $hashed_password)) {
                $new_hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                
                $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
                $update_stmt->bind_param("ss", $new_hashed_password, $username);
                
                if ($update_stmt->execute()) {
                    $success_msg = "Password updated successfully.";
                } else {
                    $error_msg = "Error updating database entry.";
                }
                $update_stmt->close();
            } else {
                $error_msg = "The current password you entered is incorrect.";
            }
        }
    } else {
        $error_msg = "Please fill out all required fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Account Settings - Change Password</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body class="bg-light p-5">
<div class="container" style="max-width: 500px;">

    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
            <i class="fas fa-check-circle me-2"></i> <strong>Success!</strong> <?= $success_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <script>setTimeout(() => { window.location.href='index.php'; }, 2000);</script>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="alert alert-danger shadow-sm" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i> <?= $error_msg ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-dark text-white p-3 d-flex justify-content-between align-items-center">
            <span class="fw-bold"><i class="fas fa-key me-2 text-warning"></i> Update Account Password</span>
            <a href="index.php" class="btn btn-sm btn-outline-light"><i class="fas fa-home"></i></a>
        </div>
        <div class="card-body p-4 bg-white">
            <form method="post" action="change_password.php">
                <div class="mb-3">
                    <label class="form-label small text-uppercase fw-bold text-muted">Current Password</label>
                    <input type="password" name="current_password" class="form-control" placeholder="Enter current password" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-uppercase fw-bold text-muted">New Password</label>
                    <input type="password" name="new_password" class="form-control" placeholder="Minimum 6 characters recommended" required>
                </div>
                <div class="mb-4">
                    <label class="form-label small text-uppercase fw-bold text-muted">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" placeholder="Repeat new password" required>
                </div>
                <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary px-4 shadow-sm fw-bold">Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
</body>
</html>