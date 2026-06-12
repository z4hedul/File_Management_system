<?php
session_start();
include 'db.php';

if (!isset($_SESSION['loggedin'])) {
    die('<div class="p-3 text-danger small">Session expired. Please log in again.</div>');
}

$file_id = isset($_GET['file_id']) ? intval($_GET['file_id']) : 0;
if ($file_id <= 0) {
    die('<div class="p-3 text-warning small">Invalid request parameter.</div>');
}

// 1. Fetch File Metadata Context Summary
$file_stmt = $conn->prepare("SELECT client, file_no FROM office_files WHERE id = ?");
$file_stmt->bind_param("i", $file_id);
$file_stmt->execute();
$file_info = $file_stmt->get_result()->fetch_assoc();
$file_stmt->close();

if (!$file_info) {
    die('<div class="p-3 text-muted small">File profile not located.</div>');
}

// 2. Pull movement log history records from your tracking table
$log_stmt = $conn->prepare("
    SELECT l.action_type, l.action_timestamp, u.full_name, u.username
    FROM file_movement_log l
    JOIN users u ON l.user_id = u.id
    WHERE l.file_id = ?
    ORDER BY l.action_timestamp DESC
");
$log_stmt->bind_param("i", $file_id);
$log_stmt->execute();
$logs_res = $log_stmt->get_result();
?>

<div class="bg-light p-3 border-bottom">
    <div class="fw-bold text-dark text-truncate small" style="font-size:13px;"><?= htmlspecialchars($file_info['client']) ?></div>
    <div class="text-muted font-monospace small" style="font-size:10px;">File Code: <?= htmlspecialchars($file_info['file_no']) ?></div>
</div>

<div style="max-height: 300px; overflow-y: auto;" class="p-2">
    <?php if ($logs_res && $logs_res->num_rows > 0): ?>
        <div class="list-group list-group-flush small">
            <?php while($log = $logs_res->fetch_assoc()): ?>
                <?php 
                  $isOut = ($log['action_type'] === 'checkout');
                  $color = $isOut ? 'text-danger' : 'text-success';
                  $icon  = $isOut ? 'fa-sign-out-alt' : 'fa-sign-in-alt';
                  $text  = $isOut ? 'Grabbed File' : 'Returned File';
                ?>
                <div class="list-group-item px-2 py-1.5 border-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold <?= $color ?>" style="font-size:11px;">
                            <i class="fas <?= $icon ?> me-1"></i><?= $text ?>
                        </span>
                        <span class="text-muted font-monospace" style="font-size:10px;">
                            <?= date('d-M-Y h:i A', strtotime($log['action_timestamp'])) ?>
                        </span>
                    </div>
                    <div class="text-secondary ps-3 font-monospace" style="font-size:11px;">
                        Operator: <?= htmlspecialchars($log['full_name'] ?: $log['username']) ?>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="text-center py-4 text-muted small">
            <i class="fas fa-history d-block mb-1 opacity-50"></i> No previous physical movement logs found for this file.
        </div>
    <?php endif; ?>
</div>
<?php 
$log_stmt->close();
?>