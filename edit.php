<?php
session_start();
include 'db.php';


if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

$file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

if ($file_id <= 0) {
    header('Location: index.php');
    exit;
}

// Fetch file details
$stmt = $conn->prepare("SELECT * FROM office_files WHERE id = ? AND is_deleted = 0");
$stmt->bind_param("i", $file_id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();

if (!$file) {
    $_SESSION['error'] = "File not found";
    header('Location: index.php');
    exit();
}

// Fetch branches for dropdown
$branches_list = [];
$branches_query = "SELECT id, branch_code, branch_name, zone FROM branches ORDER BY branch_name ASC";
$branches_result = $conn->query($branches_query);
if ($branches_result && $branches_result->num_rows > 0) {
    while ($row = $branches_result->fetch_assoc()) {
        $branches_list[] = $row;
    }
}

// If client_id is not provided, try to get it from the file
if ($client_id <= 0 && !empty($file['client_id'])) {
    $client_id = $file['client_id'];
}

// Fetch client details if client_id exists
$client_name = '';
$client_branch_name = '';
$client_branch_code = '';
$client_division = '';
$client_zone = '';

if ($client_id > 0) {
    $client_stmt = $conn->prepare("SELECT 
                                    cp.client_name,
                                    cp.division,
                                    cp.zone,
                                    b.branch_name,
                                    b.branch_code
                                   FROM client_profiles cp
                                   LEFT JOIN branches b ON cp.branch_id = b.id
                                   WHERE cp.id = ?");
    $client_stmt->bind_param("i", $client_id);
    $client_stmt->execute();
    $client_result = $client_stmt->get_result();
    if ($client_row = $client_result->fetch_assoc()) {
        $client_name = $client_row['client_name'];
        $client_division = $client_row['division'] ?? '';
        $client_zone = $client_row['zone'] ?? '';
        $client_branch_name = $client_row['branch_name'] ?? '';
        $client_branch_code = $client_row['branch_code'] ?? '';
    }
    $client_stmt->close();
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cabinet_name = trim($_POST['cabinet_name']);
    $shelf_name = trim($_POST['shelf_name']);
    $file_no = trim($_POST['file_no']);
    $client_name_input = trim($_POST['client_name']);
    $branch_name = trim($_POST['branch_name']);
    $branch_code = trim($_POST['branch_code']);
    $division = trim($_POST['division']);
    $zone = trim($_POST['zone']);
    $remarks = trim($_POST['remarks']);
    
    // Combine client name with file no for the client column
    $client_column_value = $client_name_input . ' - ' . $file_no;
    
    $update_stmt = $conn->prepare("UPDATE office_files SET 
        cabinet_name = ?, 
        shelf_name = ?, 
        file_no = ?, 
        client = ?,
        branch_name = ?, 
        branch_code = ?, 
        division = ?, 
        zone = ?, 
        remarks = ?
        WHERE id = ?");
    $update_stmt->bind_param("sssssssssi", 
        $cabinet_name, 
        $shelf_name, 
        $file_no, 
        $client_column_value,
        $branch_name, 
        $branch_code, 
        $division, 
        $zone, 
        $remarks,
        $file_id
    );
    
    if ($update_stmt->execute()) {
        $_SESSION['success'] = "File updated successfully!";
        if ($client_id > 0) {
            header("Location: client_profile.php?id=" . $client_id . "&status=updated");
        } else {
            header("Location: search.php");
        }
        exit();
    } else {
        $message = '<div class="alert alert-danger">Error: ' . $update_stmt->error . '</div>';
    }
}

// Get the client name part from the file (without the file no)
$client_name_part = $file['client'] ?? '';
if (!empty($client_name_part) && !empty($file['file_no'])) {
    // Remove the file no suffix if it exists
    $suffix = ' - ' . $file['file_no'];
    if (substr($client_name_part, -strlen($suffix)) == $suffix) {
        $client_name_part = substr($client_name_part, 0, -strlen($suffix));
    }
}
include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit File</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        .file-preview {
            background: #f0f7ff;
            border: 2px dashed #006a4e;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            margin-bottom: 20px;
        }
        .file-preview .label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: 600;
        }
        .file-preview .value {
            font-size: 18px;
            font-weight: 700;
            color: #006a4e;
        }
        .file-preview .value .file-name-display {
            background: #e8f5e9;
            padding: 5px 15px;
            border-radius: 6px;
            display: inline-block;
        }
        .form-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid #e9ecef;
        }
        .form-section h6 {
            color: #006a4e;
            font-weight: 600;
            margin-bottom: 12px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 8px;
        }
        .form-section h6 i {
            margin-right: 8px;
        }
        .client-info-box {
            background: #e8f5e9;
            border: 1px solid #c8e6c9;
            border-radius: 8px;
            padding: 12px 15px;
        }
        .client-info-box .label {
            font-size: 11px;
            color: #2e7d32;
            text-transform: uppercase;
            font-weight: 600;
        }
        .client-info-box .value {
            font-size: 14px;
            font-weight: 500;
            color: #1b5e20;
        }
        .zone-auto-fill {
            background-color: #f8f9fa;
        }
        .zone-auto-fill:focus {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body class="bg-light p-5">
    <div class="container" style="max-width: 850px;">
        <div class="card shadow border-0">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0"><i class="fas fa-edit"></i> Edit File</h4>
                <?php if ($client_id > 0 && !empty($client_name)): ?>
                    <small class="text-white-50">Editing file for: <strong><?php echo htmlspecialchars($client_name); ?></strong></small>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php echo $message; ?>
                
                <?php if ($client_id > 0 && !empty($client_name)): ?>
                    <!-- Client Information Preview -->
                    <div class="client-info-box mb-4">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="label">Client Name</div>
                                <div class="value"><?php echo htmlspecialchars($client_name); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="label">Current File No</div>
                                <div class="value"><?php echo htmlspecialchars($file['file_no'] ?? 'N/A'); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="form-section">
                        <h6><i class="fas fa-info-circle text-primary"></i> File Information</h6>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">File Name <span class="text-danger">*</span></label>
                                    <input type="text" name="client_name" class="form-control" 
                                           value="<?php echo htmlspecialchars($client_name_part ?: $file['client'] ?? ''); ?>" 
                                           placeholder="Enter client name" required>
                                    <small class="text-muted">This will be saved as: <strong id="file_name_preview"><?php echo htmlspecialchars(($client_name_part ?: $file['client'] ?? '') . ' - ' . ($file['file_no'] ?? '')); ?></strong></small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">File No <span class="text-danger">*</span></label>
                                    <input type="text" name="file_no" class="form-control" 
                                           value="<?php echo htmlspecialchars($file['file_no'] ?? ''); ?>" 
                                           placeholder="e.g., 001" required>
                                    <small class="text-muted">File number for this record</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Cabinet No <span class="text-danger">*</span></label>
                                    <input type="text" name="cabinet_name" class="form-control" 
                                           value="<?php echo htmlspecialchars($file['cabinet_name'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Shelf No <span class="text-danger">*</span></label>
                                    <input type="text" name="shelf_name" class="form-control" 
                                           value="<?php echo htmlspecialchars($file['shelf_name'] ?? ''); ?>" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h6><i class="fas fa-building text-warning"></i> Branch & Location</h6>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Select Branch <span class="text-danger">*</span></label>
                                    <select name="branch_name" id="branch_select" class="form-select" required onchange="autoPopulateBranchDetails(this)">
                                        <option value="">-- Select Branch --</option>
                                        <?php foreach ($branches_list as $branch): ?>
                                            <?php 
                                                $selected = ($branch['branch_name'] == $file['branch_name']) ? 'selected' : '';
                                            ?>
                                            <option value="<?php echo htmlspecialchars($branch['branch_name']); ?>" 
                                                    data-code="<?php echo htmlspecialchars($branch['branch_code']); ?>"
                                                    data-zone="<?php echo htmlspecialchars($branch['zone']); ?>"
                                                    <?php echo $selected; ?>>
                                                <?php echo htmlspecialchars($branch['branch_code']); ?> - <?php echo htmlspecialchars($branch['branch_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="mt-1">
                                        <a href="add_branch.php" target="_blank" class="text-decoration-none small text-primary fw-semibold">
                                            <i class="fas fa-plus-circle me-1"></i>Add branch manually
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Branch Code</label>
                                    <input type="text" name="branch_code" id="branch_code" class="form-control" 
                                           value="<?php echo htmlspecialchars($file['branch_code'] ?? ''); ?>" readonly>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Division <span class="text-danger">*</span></label>
                                    <select name="division" class="form-control" required>
                                        <option value="Investment" <?php echo ($file['division'] == 'Investment') ? 'selected' : ''; ?>>Investment</option>
                                        <option value="SME" <?php echo ($file['division'] == 'SME') ? 'selected' : ''; ?>>SME</option>
                                        <option value="IMRD" <?php echo ($file['division'] == 'IMRD') ? 'selected' : ''; ?>>IMRD</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Zone <span class="text-danger">*</span></label>
                                    <input type="text" name="zone" id="zone_input" class="form-control zone-auto-fill" 
                                           value="<?php echo htmlspecialchars($file['zone'] ?? ''); ?>" 
                                           placeholder="Auto-filled from branch" readonly>
                                    <small class="text-muted">
                                        <i class="fas fa-magic text-warning"></i> Zone will be auto-filled when you select a branch
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2"><?php echo htmlspecialchars($file['remarks'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update File</button>
                        <?php if ($client_id > 0): ?>
                            <a href="client_profile.php?id=<?php echo $client_id; ?>" class="btn btn-secondary">Cancel</a>
                        <?php else: ?>
                            <a href="search.php" class="btn btn-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    // Auto-populate branch code and zone when branch is selected
    function autoPopulateBranchDetails(selectElement) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        const branchCodeInput = document.getElementById('branch_code');
        const zoneInput = document.getElementById('zone_input');
        const clientNameInput = document.querySelector('input[name="client_name"]');
        const fileNoInput = document.querySelector('input[name="file_no"]');
        const fileNamePreview = document.getElementById('file_name_preview');
        
        if (selectedOption && selectedOption.value !== "") {
            const branchCode = selectedOption.getAttribute('data-code') || '';
            const zone = selectedOption.getAttribute('data-zone') || '';
            
            branchCodeInput.value = branchCode;
            zoneInput.value = zone;
            
            // Add visual feedback for zone
            if (zone.trim() !== "") {
                zoneInput.style.borderColor = '#28a745';
                zoneInput.style.backgroundColor = '#f0fff4';
            } else {
                zoneInput.style.borderColor = '#ffc107';
                zoneInput.style.backgroundColor = '#fff3e0';
            }
        } else {
            branchCodeInput.value = '';
            zoneInput.value = '';
            zoneInput.style.borderColor = '';
            zoneInput.style.backgroundColor = '';
        }
        
        // Update file name preview
        updateFileNamePreview(clientNameInput, fileNoInput, fileNamePreview);
    }
    
    // Update file name preview when client name or file no changes
    function updateFileNamePreview(clientNameInput, fileNoInput, previewElement) {
        if (!clientNameInput || !fileNoInput || !previewElement) {
            // Try to get elements if not passed
            clientNameInput = clientNameInput || document.querySelector('input[name="client_name"]');
            fileNoInput = fileNoInput || document.querySelector('input[name="file_no"]');
            previewElement = previewElement || document.getElementById('file_name_preview');
        }
        
        if (clientNameInput && fileNoInput && previewElement) {
            const clientName = clientNameInput.value.trim() || 'Client';
            const fileNo = fileNoInput.value.trim() || '001';
            previewElement.textContent = clientName + ' - ' + fileNo;
        }
    }
    
    // Event listeners for real-time preview update
    document.addEventListener('DOMContentLoaded', function() {
        const clientNameInput = document.querySelector('input[name="client_name"]');
        const fileNoInput = document.querySelector('input[name="file_no"]');
        const fileNamePreview = document.getElementById('file_name_preview');
        
        if (clientNameInput) {
            clientNameInput.addEventListener('input', function() {
                updateFileNamePreview(clientNameInput, fileNoInput, fileNamePreview);
            });
        }
        
        if (fileNoInput) {
            fileNoInput.addEventListener('input', function() {
                updateFileNamePreview(clientNameInput, fileNoInput, fileNamePreview);
            });
        }
        
        // If branch is pre-selected, trigger auto-populate
        const branchSelect = document.getElementById('branch_select');
        if (branchSelect && branchSelect.value) {
            autoPopulateBranchDetails(branchSelect);
        }
    });
    </script>
    
    <?php include 'footer.php'; ?>
</body>
</html>