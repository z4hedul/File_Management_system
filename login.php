<?php
 session_start();
include 'db.php';

// FIX: Initialize the variable with an empty string
$error = ""; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // ... your existing login logic here ...
    // ... if login fails, $error will get a value ...
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Search for the user
    $sql = "SELECT id, username, password, role FROM users WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
// ... inside your login.php where you check the password ...

$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc(); // <--- Make sure this variable name is $user

    if (password_verify($password, $user['password'])) {
        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];
        
        // This is the line we added for manage_users.php
        $_SESSION['user_id']  = $user['id']; 
        
        header("location: index.php");
        exit;
    } else {
        $error = "Invalid password.";
    }
} else {
    $error = "User not found.";
}
}
?>
<style>
.card {
    border-top: 5px solid #1e3a8a; /* Bank Blue top border */
}
.btn-primary {
    background-color: #1e3a8a;
    border: none;
}
.btn-primary:hover {
    background-color: #152a61;
}
</style>

<!DOCTYPE html>
<html>
<head>
    <title>Login - File Management System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background-color: #f8f9fa; display: flex; align-items: center; height: 100vh; }
        .login-container { max-width: 500px; margin: auto; }
    </style>
</head>
<body>

<div class="container login-container">
    <div class="card shadow login-card">
    <div class="card-body p-5 text-center">
        <!-- Logo from the images folder -->
        <div class="mb-4">
            <img src="images/fsib_logo.jpg" alt="FSIB Logo" style="max-height: 120px; width: auto;">
        </div>

        <h3 class="mb-4 text-primary fw-bold">Investment User Login</h3>
        
        <?php if($error): ?>
            <div class="alert alert-danger text-start small"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST" action="" class="text-start">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Login</button>
            </form>
        </div>
    </div>
</div>

</body>
</html>