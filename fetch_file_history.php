<?php
include 'db.php';
session_start();

if (!isset($_SESSION['loggedin']) || !isset($_GET['file_id'])) {
    echo '<p class="text-danger small text-center mb-0">Unauthorized or incorrect context request signature attributes passed.</p>';
    exit;
}

$file_id = intval($_GET['file_id']);

// Pull structural details directly using fallback references matching your schema architecture definition map 
$file_stmt = $conn->prepare("SELECT client, file_no, is_checked_out, updated_by, updated_at, created_at, created_by FROM office_files WHERE id = ?");
$file_stmt->bind_param("i", $file_id);
$file_stmt->execute();
$file = $file_stmt->get_result()->fetch_assoc();
$file_stmt->close();

if (!$file) {
    echo '<p class="text-muted small text-center mb-0">No matching system archival configuration metadata maps loaded.</p>';
    exit;
}
?>

<div class="card bg-light border p-3 mb-3">
    <div class="row text-dark small">
        <div class="col-sm-6 mb-1"><strong>Client Name:</strong> <?php echo htmlspecialchars($file['client']); ?></div>
        <div class="col-sm-6 mb-0"><strong>Creation Baseline:</strong> <?php echo date('d-M-Y h:i A', strtotime($file['created_at'])); ?></div>
    </div>
</div>

<h6 class="fw-bold text-dark border-bottom pb-2 mb-3 small"><i class="fas fa-route text-primary me-1"></i>Current Telemetry Node Tracking Parameters</h6>
<div class="table-responsive">
    <table class="table table-bordered table-sm align-middle mb-0 text-center small" style="font-size:11px;">
        <thead class="table-light text-muted">
            <tr>
                <th>Historical Log Operations State</th>
                <th>User Account Profile Authority</th>
                <th>System Operations Timestamp</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <?php if (intval($file['is_checked_out']) === 1): ?>
                        <span class="badge bg-danger text-white px-2 py-0.5"><i class="fas fa-sign-out-alt me-1"></i>File Grabbed / Checked-Out</span>
                    <?php else: ?>
                        <span class="badge bg-success text-white px-2 py-0.5"><i class="fas fa-sign-in-alt me-1"></i>Returned / Active In Cabinet</span>
                    <?php endif; ?>
                </td>
                <td class="fw-bold text-secondary"><?php echo htmlspecialchars(!empty($file['updated_by']) ? $file['updated_by'] : 'Initial Initialization Node'); ?></td>
                <td class="text-muted"><?php echo !empty($file['updated_at']) ? date('d-M-Y h:i A', strtotime($file['updated_at'])) : date('d-M-Y h:i A', strtotime($file['created_at'])); ?></td>
            </tr>
        </tbody>
    </table>
</div>