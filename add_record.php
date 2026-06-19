<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';


if (!isset($_SESSION['loggedin'])) {
    header("location: login.php");
    exit;
}

$message = "";
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$client_name = '';
$client_branch_name = '';
$client_branch_code = '';
$client_division = '';
$client_zone = '';

// If client_id is provided, fetch all client details
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

// Fetch active branches configuration from the branches master table
$branches_list = [];
try {
    $branches_query = "SELECT branch_code, branch_name, zone FROM branches ORDER BY branch_code ASC";
    $branches_result = $conn->query($branches_query);
    if ($branches_result && $branches_result->num_rows > 0) {
        while ($row = $branches_result->fetch_assoc()) {
            $branches_list[] = $row;
        }
    }
} catch (mysqli_sql_exception $e) {
    $message = "<div class='alert alert-danger'>Table Configuration Error: Make sure the 'branches' table is created.</div>";
}

// Generate next file number for the client
$next_file_no = 1;
if ($client_id > 0) {
    $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM office_files WHERE client_id = ? AND is_deleted = 0");
    $count_stmt->bind_param("i", $client_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    if ($count_row = $count_result->fetch_assoc()) {
        $next_file_no = $count_row['total'] + 1;
    }
    $count_stmt->close();
}

// Format file number with leading zeros
$formatted_file_no = str_pad($next_file_no, 3, '0', STR_PAD_LEFT);

// Generate the full file name (Client Name - File No)
$full_file_name = '';
if (!empty($client_name)) {
    $full_file_name = $client_name . ' - ' . $formatted_file_no;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- BRANCH SPLIT LOGIC ---
    $branch_info = $_POST['branch_info'] ?? '';
    $parts = explode('-', $branch_info);
    
    $branch_code = isset($parts[0]) ? trim($parts[0]) : '';
    $branch_name = isset($parts[1]) ? trim($parts[1]) : '';

    $division = $_POST['division'] ?? '';
    $zone     = $_POST['zone'] ?? '';
    $cabinet  = $_POST['cabinet_name'] ?? '';
    $shelf    = $_POST['shelf_name'] ?? '';
    $file_no  = $_POST['file_no'] ?? '';
    $remarks  = $_POST['remarks'] ?? '';
    
    // Get client_id from hidden field
    $client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
    
    // Get the client name from form or fetch it
    $client_name_for_db = '';
    if ($client_id > 0) {
        // Fetch client name
        $find_client = $conn->prepare("SELECT client_name FROM client_profiles WHERE id = ?");
        $find_client->bind_param("i", $client_id);
        $find_client->execute();
        $find_result = $find_client->get_result();
        if ($find_row = $find_result->fetch_assoc()) {
            $client_name_for_db = $find_row['client_name'] . ' - ' . $file_no;
        }
        $find_client->close();
    } else {
        // If no client_id, use the client name from form
        $client_name_for_db = isset($_POST['client']) ? $_POST['client'] : '';
    }

    // Insert statement with all client details
    $stmt = $conn->prepare("INSERT INTO office_files 
        (branch_code, branch_name, division, zone, client, cabinet_name, shelf_name, file_no, remarks, client_id) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param("sssssssssi", 
        $branch_code, $branch_name, $division, $zone, 
        $client_name_for_db, $cabinet, $shelf, $file_no, $remarks, $client_id
    );

    if ($stmt->execute()) {
        $last_id = $conn->insert_id;
        // If client_id was provided, redirect back to client profile
        if ($client_id > 0) {
            header("Location: client_profile.php?id=" . $client_id . "&status=file_added");
        } else {
            $message = "<div class='alert alert-success'>Record saved successfully! <a href='search.php'>View Table</a></div>";
        }
        exit();
    } else {
        $message = "<div class='alert alert-danger'>Error: " . $conn->error . "</div>";
    }
}
include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add New File Record</title>
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
        .badge-file-name {
            background: #006a4e;
            color: #fff;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
        }
    </style>
</head>
<body class="bg-light p-5">

<div class="container" style="max-width: 850px;">
    <div class="card shadow border-0">
        <div class="card-header bg-primary text-white text-center p-3">
            <h4 class="mb-0"><i class="fas fa-plus-circle"></i> Add New File Record</h4>
            <?php if ($client_id > 0 && !empty($client_name)): ?>
                <small class="text-white-50">Adding file for: <strong><?php echo htmlspecialchars($client_name); ?></strong></small>
            <?php endif; ?>
        </div>
        <div class="card-body p-4">
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
                            <div class="label">File Number</div>
                            <div class="value"><?php echo $formatted_file_no; ?></div>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-4">
                            <div class="label">Branch</div>
                            <div class="value"><?php echo htmlspecialchars($client_branch_name ?: 'N/A'); ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="label">Division</div>
                            <div class="value"><?php echo htmlspecialchars($client_division ?: 'N/A'); ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="label">Zone</div>
                            <div class="value"><?php echo htmlspecialchars($client_zone ?: 'N/A'); ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- File Name Preview -->
                <div class="file-preview">
                    <div class="label">File Name (will be saved in system)</div>
                    <div class="value">
                        <span class="file-name-display">
                            <i class="fas fa-file"></i> 
                            <?php echo htmlspecialchars($full_file_name); ?>
                        </span>
                    </div>
                    <small class="text-muted">
                        <i class="fas fa-info-circle"></i> 
                        The file will be saved as: <strong><?php echo htmlspecialchars($full_file_name); ?></strong>
                    </small>
                </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" id="addForm">
                <?php if ($client_id > 0): ?>
                    <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
                <?php endif; ?>
                
                <div class="form-section">
                    <h6><i class="fas fa-info-circle text-primary"></i> File Information</h6>
                    
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label class="form-label fw-bold">File Name <span class="text-danger">*</span></label>
                                <?php if ($client_id > 0 && !empty($client_name)): ?>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($full_file_name); ?>" disabled readonly style="background: #f8f9fa; font-weight: 600; color: #006a4e;">
                                    <small class="text-muted text-success">
                                        <i class="fas fa-check-circle"></i> 
                                        File name will be: <strong><?php echo htmlspecialchars($full_file_name); ?></strong>
                                        (Client Name - File No)
                                    </small>
                                <?php else: ?>
                                    <input type="text" name="client" class="form-control" placeholder="Enter file name (e.g., Client Name - 001)" required>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Cabinet No <span class="text-danger">*</span></label>
                            <input type="text" name="cabinet_name" class="form-control" placeholder="e.g 36" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Shelf No <span class="text-danger">*</span></label>
                            <input type="text" name="shelf_name" class="form-control" placeholder="e.g 1,2,3,4" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">File No <span class="text-danger">*</span></label>
                            <?php if ($client_id > 0): ?>
                                <input type="text" name="file_no" class="form-control" value="<?php echo $formatted_file_no; ?>" readonly style="background: #f8f9fa; font-weight: 600; color: #006a4e;">
                                <small class="text-muted">Auto-generated for this client</small>
                            <?php else: ?>
                                <input type="text" name="file_no" class="form-control" placeholder="Serial no of cabinet" required>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h6><i class="fas fa-building text-warning"></i> Branch & Location</h6>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Select Branch <span class="text-danger">*</span></label>
                            <select name="branch_info" id="branch_info_select" class="form-select" required onchange="autoPopulateZone(this)">
                                <option value="">-- Select Branch --</option>
                                <?php foreach ($branches_list as $branch): ?>
                                    <?php 
                                        $value_string = htmlspecialchars($branch['branch_code'] . '-' . $branch['branch_name']);
                                        $display_string = htmlspecialchars($branch['branch_code'] . ' - ' . $branch['branch_name']);
                                    ?>
                                    <option value="<?= $value_string ?>" data-zone="<?= htmlspecialchars(trim($branch['zone'])) ?>" 
                                        <?php if ($client_branch_code == $branch['branch_code']): ?>selected<?php endif; ?>>
                                        <?= $display_string ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="mt-1">
                                <a href="add_branch.php" target="_blank" class="text-decoration-none small text-primary fw-semibold">
                                    <i class="fas fa-plus-circle me-1"></i>Add branch manually
                                </a>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Division <span class="text-danger">*</span></label>
                            <select name="division" class="form-select" required>
                                <option value="Investment" <?php echo ($client_division == 'Investment') ? 'selected' : ''; ?>>Investment</option>
                                <option value="SME" <?php echo ($client_division == 'SME') ? 'selected' : ''; ?>>SME</option>
                                <option value="IMRD" <?php echo ($client_division == 'IMRD') ? 'selected' : ''; ?>>IMRD</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Zone <span class="text-danger">*</span></label>
                            <select name="zone" id="zone_select" class="form-select" required>
                                <option value="">-- Select Zone --</option>
                                <option value="Head Office" <?php echo ($client_zone == 'Head Office') ? 'selected' : ''; ?>>Head Office</option>
                                <option value="Dhaka North Zone" <?php echo ($client_zone == 'Dhaka North Zone') ? 'selected' : ''; ?>>Dhaka North Zone</option>
                                <option value="Dhaka South Zone" <?php echo ($client_zone == 'Dhaka South Zone') ? 'selected' : ''; ?>>Dhaka South Zone</option>
                                <option value="Chattagram North Zone" <?php echo ($client_zone == 'Chattagram North Zone') ? 'selected' : ''; ?>>Chattagram North Zone</option>
                                <option value="Chattagram South Zone" <?php echo ($client_zone == 'Chattagram South Zone') ? 'selected' : ''; ?>>Chattagram South Zone</option>
                                <option value="Rajshahi Zone" <?php echo ($client_zone == 'Rajshahi Zone') ? 'selected' : ''; ?>>Rajshahi Zone</option>
                                <option value="Khulna Zone" <?php echo ($client_zone == 'Khulna Zone') ? 'selected' : ''; ?>>Khulna Zone</option>
                                <option value="Barishal Zone" <?php echo ($client_zone == 'Barishal Zone') ? 'selected' : ''; ?>>Barishal Zone</option>
                                <option value="Cumilla Zone" <?php echo ($client_zone == 'Cumilla Zone') ? 'selected' : ''; ?>>Cumilla Zone</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">Remarks (Add start Documents date)</label>
                    <textarea name="remarks" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary px-5"><i class="fas fa-save"></i> Save File</button>
                    <?php if ($client_id > 0): ?>
                        <a href="client_profile.php?id=<?php echo $client_id; ?>" class="btn btn-outline-secondary px-4">Cancel</a>
                    <?php else: ?>
                        <a href="index.php" class="btn btn-outline-secondary px-4">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/**
 * Auto-populate Zone from Branch selection
 */
function autoPopulateZone(selectElement) {
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const zoneSelect = document.getElementById('zone_select');
    
    const dynamicOption = document.getElementById('temp_dynamic_zone_opt');
    if (dynamicOption) {
        dynamicOption.remove();
    }

    if (selectedOption && selectedOption.value !== "") {
        const rawTargetZone = selectedOption.getAttribute('data-zone') || "";
        const targetZone = rawTargetZone.trim();
        
        if (targetZone !== "") {
            let matchFound = false;
            const normalizedTarget = targetZone.toLowerCase();

            for (let i = 0; i < zoneSelect.options.length; i++) {
                if (zoneSelect.options[i].value.toLowerCase().trim() === normalizedTarget) {
                    zoneSelect.selectedIndex = i;
                    matchFound = true;
                    break;
                }
            }

            if (!matchFound) {
                const newOpt = document.createElement('option');
                newOpt.id = 'temp_dynamic_zone_opt';
                newOpt.value = targetZone;
                newOpt.textContent = targetZone;
                newOpt.selected = true;
                zoneSelect.appendChild(newOpt);
            }
        } else {
            zoneSelect.value = "";
        }
    } else {
        zoneSelect.value = "";
    }
}

// If branch is pre-selected from client profile, trigger zone auto-populate
document.addEventListener('DOMContentLoaded', function() {
    const branchSelect = document.getElementById('branch_info_select');
    if (branchSelect && branchSelect.value) {
        autoPopulateZone(branchSelect);
    }
});
</script>

<?php
include 'footer.php';
?>
</body>
</html>