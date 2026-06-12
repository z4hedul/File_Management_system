<?php
session_start();
include 'db.php';
include 'header.php';

// Security Check (Ensure only logged-in Admins can register new system accounts)
if (!isset($_SESSION['loggedin'])) { header("location: login.php"); exit; }
if ($_SESSION['role'] !== 'admin') {
    echo "<script>alert('Access Denied'); window.location.href='index.php';</script>";
    exit;
}

$status = $_GET['status'] ?? null;

// Fetch all active structural user groups for the UI dropdown menu
$active_groups = $conn->query("
    SELECT g.id, g.group_name, u.full_name AS leader_name 
    FROM user_groups g
    LEFT JOIN users u ON g.leader_id = u.id 
    ORDER BY g.group_name ASC
");

// POST Insertion Handler
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username     = trim($_POST['username']);
    $password     = trim($_POST['password']);
    $role         = $_POST['role'];
    $full_name    = trim($_POST['full_name']);
    $designation  = trim($_POST['designation']);
    $division     = trim($_POST['division']);   
    $employee_id  = trim($_POST['employee_id']);
    
    // Safely process group assignment entry (Convert empty selections to a clean SQL NULL)
   $group_id = !empty($_POST['group_id']) ? intval($_POST['group_id']) : null;

    if (!empty($username) && !empty($password)) {
        
        // Final Back-end Fallback Safety Check to completely block execution if username is duplicate
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            $error_msg = "Registration halted: The username '" . htmlspecialchars($username) . "' is already taken. Please choose another.";
            $check_stmt->close();
        } else {
            $check_stmt->close();

            // Securely hash password entry
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);

            // Prepared Statement parsing all eight attributes securely including group relations
            $insert_sql = "INSERT INTO users (username, password, role, full_name, designation, division, employee_id, group_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($insert_sql);
            $stmt->bind_param("sssssssi", $username, $hashed_password, $role, $full_name, $designation, $division, $employee_id, $group_id);
           
           if ($stmt->execute()) {
                header("Location: add_user.php?status=success");
                exit;
            } else {
                $error_msg = "Execution failed: " . htmlspecialchars($stmt->error);
            }
            $stmt->close();
        }
    } else {
        $error_msg = "Please fill out all fundamental baseline fields.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Settings - Add Internal User</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    
    <style>
        @media screen and (max-width: 1400px) {
            body {
                zoom: 90%;
                -moz-transform: scale(0.9);
                -moz-transform-origin: top center;
            }
            .g-3, .mb-3, .mb-4 {
                margin-bottom: 0.6rem !important;
                margin-top: 0.1rem !important;
            }
            .card-body {
                padding: 0.75rem !important;
            }
        }
    </style>
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
            <form method="post" action="add_user.php" id="registrationForm">
                
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
                    <div class="col-md-4">
                        <label class="form-label small text-uppercase fw-bold text-muted">Division</label>
                        <select name="division" class="form-select" required>
                            <option value="Investment">Investment</option>
                            <option value="SME">SME</option>
                            <option value="IMRD">IMRD</option>
                        </select>
                    </div>
                    
<div class="col-md-8">
    <label class="form-label small text-uppercase fw-bold text-muted">Assign Operating Group / Team</label>
    <select name="group_id" class="form-select">
        <option value="">Independent / No Assigned Group</option>
        <?php if ($active_groups && $active_groups->num_rows > 0): ?>
            <?php while($g_row = $active_groups->fetch_assoc()): ?>
                <option value="<?= $g_row['id'] ?>">
                    <?= htmlspecialchars($g_row['group_name']) ?> 
                    (Leader: <?= htmlspecialchars($g_row['leader_name'] ?? 'None') ?>)
                </option>
            <?php endwhile; ?>
        <?php endif; ?>
    </select>
</div>
                </div>

                <h5 class="text-secondary border-bottom pb-2 mb-3"><i class="fas fa-key me-1"></i> Access System Authentication</h5>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label small text-uppercase fw-bold text-muted">Account Username</label>
                        <input type="text" name="username" id="usernameInput" class="form-control" placeholder="Unique account login nickname" autocomplete="off" required>
                        <div id="usernameFeedback" class="form-text fw-bold small mt-1"></div>
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
                    <button type="submit" id="submitBtn" class="btn btn-primary px-4 shadow-sm fw-bold">Register User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const usernameInput = document.getElementById('usernameInput');
    const usernameFeedback = document.getElementById('usernameFeedback');
    const registrationForm = document.getElementById('registrationForm');
    const submitBtn = document.getElementById('submitBtn');
    
    let isUsernameValid = false;
    let timeout = null;

    usernameInput.addEventListener('input', function() {
        const username = usernameInput.value.trim();
        
        // Clear previous timeouts to prevent spamming queries
        clearTimeout(timeout);
        
        if (username === '') {
            usernameFeedback.innerHTML = '';
            usernameFeedback.className = 'form-text mt-1';
            submitBtn.disabled = false;
            isUsernameValid = false;
            return;
        }

        // Add a micro debounce buffer window (300ms) to track typing states comfortably
        usernameFeedback.innerHTML = '<span class="text-secondary"><i class="fas fa-spinner fa-spin me-1"></i> Checking database...</span>';
        
        timeout = setTimeout(() => {
            fetch(`check_username.php?username=${encodeURIComponent(username)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.available === true) {
                        usernameFeedback.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i> Username is available.</span>';
                        submitBtn.disabled = false;
                        isUsernameValid = true;
                    } else {
                        usernameFeedback.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i> Username is already taken! Registration locked.</span>';
                        submitBtn.disabled = true;
                        isUsernameValid = false;
                    }
                })
                .catch(err => {
                    usernameFeedback.innerHTML = '<span class="text-warning">Error processing validation stream.</span>';
                });
        }, 300);
    });

    // Enforce form submission prevention if username is caught invalid or taken
    registrationForm.addEventListener('submit', function(e) {
        if (usernameInput.value.trim() !== '' && !isUsernameValid) {
            e.preventDefault();
            alert('Cannot proceed with registration. Please input a unique, available username.');
            usernameInput.focus();
        }
    });
});
</script>

<?php
include 'footer.php';
?>
</body>
</html>