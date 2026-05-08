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

// FETCH USER DATA
$stmt = $conn->prepare("SELECT username, role FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_user = trim($_POST['username']);
    $new_pass = $_POST['password'];
    $new_role = $_POST['role'];

    if (!empty($new_pass)) {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET username = ?, password = ?, role = ? WHERE id = ?");
        $upd->bind_param("sssi", $new_user, $hashed, $new_role, $id);
    } else {
        $upd = $conn->prepare("UPDATE users SET username = ?, role = ? WHERE id = ?");
        $upd->bind_param("ssi", $new_user, $new_role, $id);
    }

    if ($upd->execute()) {
        $message = "<div class='alert alert-success shadow-sm'>User updated successfully!</div>";
        $user['username'] = $new_user;
        $user['role'] = $new_role;
    } else {
        $message = "<div class='alert alert-danger'>Update failed: " . $conn->error . "</div>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit User</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light p-5">
<div class="container bg-white p-4 shadow rounded" style="max-width: 500px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0">Edit User</h3>
        <a href="index.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-home"></i> Home</a>
    </div>
    <hr>
    
    <?= $message ?>
    
    <form method="POST">
        <div class="mb-3">
            <label class="fw-bold small">Username</label>
            <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
        </div>
        <div class="mb-3">
            <label class="fw-bold small">Role</label>
            <select name="role" class="form-select">
                <option value="user" <?= $user['role'] == 'user' ? 'selected' : '' ?>>User</option>
                <option value="admin" <?= $user['role'] == 'admin' ? 'selected' : '' ?>>Admin</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="fw-bold small">Reset Password</label>
            <input type="password" name="password" class="form-control" placeholder="Leave blank to keep old password">
        </div>
        <div class="d-grid gap-2 mt-4">
            <button type="submit" class="btn btn-primary">Update User Info</button>
            <div class="row g-2">
                <div class="col-6">
                    <a href="manage_users.php" class="btn btn-secondary w-100">User List</a>
                </div>
                <div class="col-6">
                    <a href="index.php" class="btn btn-dark w-100">Dashboard</a>
                </div>
            </div>
        </div>
    </form>
</div>
</body>
</html>