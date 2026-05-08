<?php
// Start session only once
session_start();

include 'db.php';

// Protection: Only allow the 'admin' role to see this page
// We check for $_SESSION['role'] because username can be anything, but role is what matters
if (!isset($_SESSION['loggedin']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("location: index.php");
    exit;
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_user = trim($_POST['username']);
    $new_pass = $_POST['password'];
    $new_role = $_POST['role']; // This matches the <select name="role">

    if (!empty($new_user) && !empty($new_pass) && !empty($new_role)) {
        $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);

        // Check if username already exists
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $new_user);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $message = "<div class='alert alert-danger'>Username already exists!</div>";
        } else {
            // Insert with Role column included
            $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $new_user, $hashed_pass, $new_role);
            
            if ($stmt->execute()) {
                $message = "<div class='alert alert-success'>User <b>" . htmlspecialchars($new_user) . "</b> created successfully as <b>$new_role</b>!</div>";
            } else {
                $message = "<div class='alert alert-danger'>Error: " . $conn->error . "</div>";
            }
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add User with Role</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light p-5">

<div class="container bg-white p-4 shadow rounded" style="max-width: 500px;">
    <h3 class="text-center">Create New User</h3>
    <hr>
    <?php echo $message; ?>
    
    <form method="POST">
        <div class="mb-3">
            <label class="form-label fw-bold">Username</label>
            <input type="text" name="username" class="form-control" required placeholder="Enter username">
        </div>
        <div class="mb-3">
            <label class="form-label fw-bold">Password</label>
            <input type="password" name="password" class="form-control" required placeholder="Enter password">
        </div>
        <div class="mb-3">
            <label class="form-label fw-bold">User Role</label>
            <select name="role" class="form-select" required>
                <option value="" disabled selected>Select a role...</option>
                <option value="user">User (View & Add only)</option>
                <option value="admin">Admin (Full Control)</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary w-100">Create Account</button>
        <div class="text-center mt-3">
            <a href="index.php" class="text-muted text-decoration-none small">← Back to Dashboard</a>
        </div>
    </form>
</div>

</body>
</html>