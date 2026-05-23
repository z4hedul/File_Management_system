<?php
session_start();
include 'db.php'; // Your database connection file
include 'header.php'; // Your header file with Bootstrap and FontAwesome links
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 1. Fetch File Information from CORRECT TABLE: office_files
$stmt = $conn->prepare("SELECT * FROM office_files WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();

if (!$file) { 
    die("<div class='container mt-5'><div class='alert alert-danger'>
        <i class='fas fa-exclamation-circle'></i> Error: File ID #$id was not found in the 'office_files' table.
        <br><a href='index.php' class='btn btn-sm btn-secondary mt-2'>Return to Dashboard</a>
    </div></div>"); 
}

// 2. Fetch Transfer History (Using file_id as foreign key)
$history_stmt = $conn->prepare("SELECT * FROM file_transfers WHERE file_id = ? ORDER BY transfer_date DESC");
$history_stmt->bind_param("i", $id);
$history_stmt->execute();
$history = $history_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>File Details & History</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', sans-serif; }
        .timeline { border-left: 3px solid #1e3a8a; position: relative; padding-left: 30px; margin-left: 10px; }
        .timeline-item { position: relative; margin-bottom: 25px; padding: 15px; background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        .timeline-item::before { 
            content: ''; position: absolute; left: -38px; top: 20px; 
            width: 15px; height: 15px; background: #fbbf24; 
            border-radius: 50%; border: 3px solid white; box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }
        .info-label { font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700; letter-spacing: 0.5px; }
    </style>
</head>
<body>

<div class="container py-5">
    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white fw-bold">
                    <i class="fas fa-file-alt me-2"></i> File Information
                </div>
                <div class="card-body">
                    <p class="mb-1 info-label">Client Name</p>
                    <h5 class="fw-bold text-dark"><?php echo htmlspecialchars($file['client'] ?? 'N/A'); ?></h5>
                    <hr>
                    <p class="mb-1 info-label">Current Division</p>
                    <span class="badge bg-success mb-3"><?php echo htmlspecialchars($file['division'] ?? 'N/A'); ?></span>
                    
                    <p class="mb-1 info-label">File Number</p>
                    <p class="fw-bold text-dark"><?php echo htmlspecialchars($file['file_no'] ?? 'N/A'); ?></p>
                    
                    <p class="mb-1 info-label">Location (Cabinet/Shelf)</p>
                    <p class="text-dark">
                        <i class="fas fa-archive small"></i> <?php echo htmlspecialchars($file['cabinet_name'] ?? '-'); ?> / 
                        <i class="fas fa-layer-group small"></i> <?php echo htmlspecialchars($file['shelf_name'] ?? '-'); ?>
                    </p>
                </div>
                <div class="card-footer bg-white border-0">
                    <a href="index.php" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <h4 class="fw-bold text-primary mb-4"><i class="fas fa-route me-2"></i> Movement History</h4>
            
            <?php if ($history->num_rows > 0): ?>
                <div class="timeline">
                    <?php while($row = $history->fetch_assoc()): ?>
                        <div class="timeline-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-bold text-primary">
                                    <i class="fas fa-arrow-circle-right me-1"></i> To: <?php echo htmlspecialchars($row['to_division']); ?>
                                </span>
                                <small class="text-muted">
                                    <i class="far fa-clock me-1"></i> <?php echo date('d M Y, h:i A', strtotime($row['transfer_date'])); ?>
                                </small>
                            </div>
                            <p class="small mb-1 mt-2 text-dark">
                                From: <span class="text-muted fw-bold"><?php echo htmlspecialchars($row['from_division']); ?></span>
                            </p>
                            <?php if(!empty($row['remarks'])): ?>
                                <div class="bg-light p-2 rounded small text-secondary italic mt-2 border-start border-3 border-warning">
                                    <i class="fas fa-quote-left me-1 opacity-50"></i> <?php echo htmlspecialchars($row['remarks']); ?>
                                </div>
                            <?php endif; ?>
                            <div class="mt-2 text-end">
                                <small class="text-muted">Processed By: <strong><?php echo htmlspecialchars($row['transferred_by']); ?></strong></small>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-light border text-center py-4 shadow-sm">
                    <i class="fas fa-history fa-2x mb-3 text-muted"></i>
                    <p class="mb-0 text-muted">No movement history recorded yet.<br>This file is still in its original division.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
</body>
</html>