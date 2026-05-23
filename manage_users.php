<?php
session_start();
include 'db.php';
include 'header.php';
// 1. Protection: Only Admin
if (!isset($_SESSION['loggedin']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("location: index.php");
    exit;
}

$msg = "";

// 2. HANDLE DELETE ACTION
if (isset($_GET['delete_id'])) {
    $del_id = intval($_GET['delete_id']);
    $current_admin_id = $_SESSION['user_id'] ?? null; 

    if ($current_admin_id && $del_id == $current_admin_id) {
        $msg = "<div class='alert alert-warning alert-dismissible fade show shadow-sm' role='alert'>
                    <i class='fas fa-exclamation-triangle me-1'></i> You cannot delete your own admin account while logged in.
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                </div>";
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $del_id);
        if ($stmt->execute()) {
            $msg = "<div class='alert alert-success alert-dismissible fade show shadow-sm' role='alert'>
                        <i class='fas fa-check-circle me-1'></i> User account successfully deleted.
                        <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                    </div>";
        }
        $stmt->close();
    }
}

// 3. FETCH ALL USERS WITH UPDATED PROFILE FIELDS
$users = $conn->query("SELECT id, username, role, full_name, designation, employee_id FROM users ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Manage Users</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body class="bg-light p-5">

<div class="container bg-white p-4 shadow rounded">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0 text-dark"><i class="fas fa-users-cog text-primary me-2"></i>User Management Dashboard</h3>
        <div>
            <a href="index.php" class="btn btn-dark"><i class="fas fa-home me-1"></i> Dashboard</a>
            <a href="add_user.php" class="btn btn-success"><i class="fas fa-user-plus me-1"></i> Add New User</a>
        </div>
    </div>

    <?= $msg ?>

    <div class="table-responsive">
        <table class="table table-hover align-middle shadow-sm border">
            <thead class="table-dark">
                <tr>
                    <th style="width: 8%;">Emp ID</th>
                    <th style="width: 25%;">Full Name</th>
                    <th style="width: 20%;">Designation</th>
                    <th style="width: 17%;">Username</th>
                    <th style="width: 12%;">System Role</th>
                    <th style="width: 18%;" class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users && $users->num_rows > 0): ?>
                    <?php while($u = $users->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <span class="fw-bold text-secondary">
                                <?= !empty($u['employee_id']) ? htmlspecialchars($u['employee_id']) : '<em class="text-muted small">N/A</em>' ?>
                            </span>
                        </td>
                        
                        <td>
                            <span class="text-dark fw-bold">
                                <?= !empty($u['full_name']) ? htmlspecialchars($u['full_name']) : '<em class="text-muted font-monospace">No Profile Name</em>' ?>
                            </span>
                        </td>
                        
                        <td>
                            <span class="text-muted small">
                                <?= !empty($u['designation']) ? htmlspecialchars($u['designation']) : '<em class="text-muted">Unassigned</em>' ?>
                            </span>
                        </td>
                        
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($u['username']) ?></span></td>
                        
                        <td>
                            <?php if ($u['role'] === 'admin'): ?>
                                <span class="badge bg-danger px-2 py-1"><i class="fas fa-user-shield me-1"></i>ADMIN</span>
                            <?php else: ?>
                                <span class="badge bg-primary px-2 py-1"><i class="fas fa-user me-1"></i>USER</span>
                            <?php endif; ?>
                        </td>
                        
                        <td class="text-center">
                            <div class="d-flex justify-content-center gap-1">
                                <a href="edit_user.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-warning px-2">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                
                                <?php 
                                $my_session_id = $_SESSION['user_id'] ?? 0;
                                if ($u['id'] != $my_session_id): 
                                ?>
                                    <a href="manage_users.php?delete_id=<?= $u['id'] ?>" 
                                       class="btn btn-sm btn-danger px-2" 
                                       onclick="return confirm('Are you sure you want to completely remove account (<?= htmlspecialchars($u['username']) ?>)? This action cannot be reversed.');">
                                        <i class="fas fa-trash-alt"></i> Delete
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-light text-muted px-2" disabled title="Current Account Active">
                                        <i class="fas fa-lock"></i> Locked
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted p-4">No system accounts exist inside your deployment framework.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="style/js/bootstrap.bundle.min.js"></script>
<?php include 'footer.php'; ?>
</body>
</html>