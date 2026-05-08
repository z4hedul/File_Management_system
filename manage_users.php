<?php
session_start();
include 'db.php';

// 1. Protection: Only Admin
if (!isset($_SESSION['loggedin']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("location: index.php");
    exit;
}

$msg = "";

// 2. HANDLE DELETE ACTION
if (isset($_GET['delete_id'])) {
    $del_id = $_GET['delete_id'];
    
    // FIX FOR LINE 16: Use null coalescing (??) to prevent "Undefined array key"
    $current_admin_id = $_SESSION['user_id'] ?? null; 

    if ($current_admin_id && $del_id == $current_admin_id) {
        $msg = "<div class='alert alert-warning'>You cannot delete your own admin account while logged in.</div>";
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $del_id);
        if ($stmt->execute()) {
            $msg = "<div class='alert alert-success'>User deleted successfully!</div>";
        }
        $stmt->close();
    }
}

// 3. FETCH ALL USERS
$users = $conn->query("SELECT id, username, role FROM users ORDER BY id DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Users</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light p-5">

<div class="container bg-white p-4 shadow rounded">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><i class="fas fa-users-cog"></i> User Management</h3>
        <div>
            <a href="index.php" class="btn btn-dark"><i class="fas fa-home"></i> Dashboard</a>
            <a href="add_user.php" class="btn btn-success"><i class="fas fa-user-plus"></i> Add New User</a>
        </div>
    </div>

    <?= $msg ?>

    <table class="table table-hover align-middle">
        <thead class="table-secondary">
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Role</th>
                <th class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while($u = $users->fetch_assoc()): ?>
            <tr>
                <td><?= $u['id'] ?></td>
                <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                <td>
                    <span class="badge <?= $u['role'] == 'admin' ? 'bg-danger' : 'bg-primary' ?>">
                        <?= strtoupper($u['role']) ?>
                    </span>
                </td>
                <td class="text-center">
                    <div class="btn-group">
                        <a href="edit_user.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-warning">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        
                        <?php 
                        // Safety Check: Don't show delete button for the current logged-in user
                        $my_session_id = $_SESSION['user_id'] ?? 0;
                        if ($u['id'] != $my_session_id): 
                        ?>
                            <a href="manage_users.php?delete_id=<?= $u['id'] ?>" 
                               class="btn btn-sm btn-danger" 
                               onclick="return confirm('Are you sure you want to delete this user?')">
                                <i class="fas fa-trash"></i> Delete
                            </a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>