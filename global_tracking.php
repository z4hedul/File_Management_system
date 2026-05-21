<?php
session_start();
include 'db.php';

if (!isset($_SESSION['loggedin'])) { header("Location: login.php"); exit; }

// Fetch ALL files, with branch details and assigned officer names
$query = "SELECT o.id, o.client, o.file_no, o.proposal_status, 
                 b.branch_code, b.branch_name, 
                 u.username AS officer_name 
          FROM office_files o
          LEFT JOIN branches b ON o.branch_id = b.id  -- Adjust table/column names to match your DB
          LEFT JOIN users u ON o.assigned_user_id = u.id
          WHERE o.is_deleted = 0 
          ORDER BY o.id DESC";

$result = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Global File Tracking Board</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light p-5">
<div class="container bg-white p-4 shadow rounded">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
        <div>
            <h4 class="text-primary mb-1"><i class="fas fa-globe me-2"></i> Central File Progress Monitor</h4>
            <small class="text-muted">Real-time status tracking for all clients and branches.</small>
        </div>
        <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-home me-1"></i> Dashboard</a>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-bordered align-middle">
            <thead class="table-dark small text-uppercase">
                <tr>
                    <th>Branch</th>
                    <th>Client Name / File No</th>
                    <th>Assigned Officer</th>
                    <th class="text-center">Current Stage Status</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <span class="fw-bold text-dark"><?php echo htmlspecialchars($row['branch_code'] ?? 'N/A'); ?></span>
                            <br><small class="text-muted"><?php echo htmlspecialchars($row['branch_name'] ?? ''); ?></small>
                        </td>
                        <td>
                            <span class="fw-bold text-primary"><?php echo htmlspecialchars($row['client']); ?></span>
                            <br><small class="font-monospace text-secondary">File: <?php echo htmlspecialchars($row['file_no']); ?></small>
                        </td>
                        <td>
                            <?php echo !empty($row['officer_name']) ? '<i class="fas fa-user-tie text-secondary me-1"></i>' . htmlspecialchars(struppercase($row['officer_name'])) : '<span class="text-danger italic">Unassigned</span>'; ?>
                        </td>
                        <td class="text-center">
                            <?php 
                            $status = $row['proposal_status'] ?? 'Assigned';
                            $badge_class = 'bg-secondary';
                            if ($status === 'Sanction') { $badge_class = 'bg-success text-white'; }
                            elseif ($status === 'Assigned') { $badge_class = 'bg-warning text-dark'; }
                            else { $badge_class = 'bg-info text-dark'; }
                            ?>
                            <span class="badge <?php echo $badge_class; ?> p-2 fs-6 text-uppercase font-monospace">
                                <?php echo htmlspecialchars($status); ?>
                            </span>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>