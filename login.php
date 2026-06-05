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
        $_SESSION['full_name'] = $user_data['full_name'];
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
     <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        body { background-color: #f8f9fa; display: flex; align-items: center; height: 100vh; }
        .login-container { max-width: 500px; margin: auto; }
    </style>
</head>
<body>

<div class="container login-container">
    <div class="card shadow login-card">
    <div class="card-body p-5 text-center">
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
                <button type="submit" class="btn btn-primary w-100 mb-3">Login</button>
                
                <div class="text-center mt-4 pt-2 border-top border-light">
    <div class="d-inline-block p-2 rounded-2 bg-light border border-light-subtle text-start animate fade-in" style="max-width: 100%;">
        <small class="d-block text-dark font-sans-serif" style="font-size: 0.82rem; line-height: 1.4;">
            <i class="fas fa-info-circle text-primary me-1 fw-bold fs-6"></i>
            <strong class="text-danger border-bottom border-danger-subtle pb-0.5" style="letter-spacing: -0.1px;">Forgot your password?</strong> 
            <span class="text-muted ms-1">Please contact your System Administrator to request an account recovery reset.</span>
        </small>
    </div>
</div>
            </form>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
</body>
</html>