<?php
session_start();
include 'db.php';

// Fetch only deleted files
$sql = "SELECT * FROM office_files WHERE is_deleted = 1";
$result = $conn->query($sql);

// Handle Restore Action
if(isset($_GET['restore_id'])) {
    $rid = intval($_GET['restore_id']);
    $conn->query("UPDATE office_files SET is_deleted = 0 WHERE id = $rid");
    header("Location: trash.php?msg=restored");
    exit;
}
?>
<?php if(isset($_GET['msg']) && $_GET['msg'] == 'deleted_forever'): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i> 
        The record has been <strong>permanently removed</strong> from the database.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<!DOCTYPE html>
<html>
<head>
    <title>Recycle Bin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><i class="fas fa-trash-alt text-danger me-2"></i>Recycle Bin</h3>
        <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Client</th>
                        <th>File No</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $row['client']; ?></td>
                                <td><?php echo $row['file_no']; ?></td>
                                <td>
                                    <a href="trash.php?restore_id=<?php echo $row['id']; ?>" 
                                       class="btn btn-sm btn-success">
                                       <i class="fas fa-undo me-1"></i> Restore
                                    </a>
                                    <a href="permanent_delete.php?id=<?php echo $row['id']; ?>" 
                                       class="btn btn-sm btn-outline-danger" 
                                       onclick="return confirm('Careful! This cannot be undone.')">
                                       Delete Forever
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="3" class="text-center py-4 text-muted">Trash is empty.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>