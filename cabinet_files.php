<?php
session_start();
include 'db.php';
include 'header.php';

if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

// Get client ID from URL
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

// Fetch all clients for dropdown
$clients_query = "SELECT id, client_name, client_code FROM client_profiles ORDER BY client_name";
$clients_result = $conn->query($clients_query);
$clients = [];
if ($clients_result) {
    while ($row = $clients_result->fetch_assoc()) {
        $clients[] = $row;
    }
}

// If client_id is provided, fetch client details
$selected_client = null;
if ($client_id > 0) {
    $client_stmt = $conn->prepare("SELECT * FROM client_profiles WHERE id = ?");
    $client_stmt->bind_param("i", $client_id);
    $client_stmt->execute();
    $selected_client = $client_stmt->get_result()->fetch_assoc();
    $client_stmt->close();
}

// Handle form submission for adding new file
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_file'])) {
    $selected_client_id = (int)$_POST['client_id'];
    $cabinet_name = trim($_POST['cabinet_name']);
    $shelf_name = trim($_POST['shelf_name']);
    $file_no = trim($_POST['file_no']);
    $branch_name = trim($_POST['branch_name']);
    $branch_code = trim($_POST['branch_code']);
    $division = trim($_POST['division']);
    $zone = trim($_POST['zone']);
    $remarks = trim($_POST['remarks']);
    
    if (empty($selected_client_id) || empty($cabinet_name) || empty($shelf_name) || empty($file_no)) {
        $message = '<div class="alert alert-danger">Please fill all required fields.</div>';
    } else {
        // Get client name
        $client_stmt = $conn->prepare("SELECT client_name FROM client_profiles WHERE id = ?");
        $client_stmt->bind_param("i", $selected_client_id);
        $client_stmt->execute();
        $client_data = $client_stmt->get_result()->fetch_assoc();
        $client_name = $client_data['client_name'] ?? '';
        $client_stmt->close();
        
        // Insert into office_files
        $stmt = $conn->prepare("INSERT INTO office_files 
            (client_id, client, cabinet_name, shelf_name, file_no, branch_name, branch_code, division, zone, remarks) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssssssss", 
            $selected_client_id, 
            $client_name,
            $cabinet_name, 
            $shelf_name, 
            $file_no, 
            $branch_name, 
            $branch_code, 
            $division, 
            $zone, 
            $remarks
        );
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">File added successfully! <a href="cabinet_files.php?client_id=' . $selected_client_id . '">View Files</a></div>';
            // Clear form fields
            $_POST = [];
        } else {
            $message = '<div class="alert alert-danger">Error: ' . $stmt->error . '</div>';
        }
        $stmt->close();
    }
}

// Fetch office files for selected client
$office_files = [];
if ($client_id > 0) {
    $files_query = "SELECT 
                        `of`.*
                    FROM office_files `of`
                    WHERE `of`.client_id = ? AND `of`.is_deleted = 0
                    ORDER BY `of`.file_no ASC";
    $stmt = $conn->prepare($files_query);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $office_files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get client info for the selected client
$client_info = null;
if ($client_id > 0) {
    $info_stmt = $conn->prepare("SELECT * FROM client_profiles WHERE id = ?");
    $info_stmt->bind_param("i", $client_id);
    $info_stmt->execute();
    $client_info = $info_stmt->get_result()->fetch_assoc();
    $info_stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cabinet & Files Management</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        :root {
            --fsibl-green: #006a4e;
            --fsibl-gold: #ffc72c;
        }
        body { background: #f0f2f5 !important; font-family: 'Segoe UI', system-ui, sans-serif; }
        
        .page-header {
            background: linear-gradient(135deg, #006a4e 0%, #004d3a 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0,106,78,0.25);
        }
        .page-header h4 { margin: 0; font-weight: 700; }
        .page-header h4 i { color: #ffc72c; margin-right: 12px; }
        .page-header .subtitle { color: rgba(255,255,255,0.7); font-size: 0.85rem; margin-top: 4px; }
        
        .card-custom {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .card-custom .card-header {
            background: #fff;
            border-bottom: 2px solid #f0f2f5;
            padding: 16px 24px;
            font-weight: 600;
        }
        .card-custom .card-header i { color: #006a4e; margin-right: 10px; }
        
        .btn-sm-action { padding: 4px 10px; font-size: 0.75rem; border-radius: 6px; margin: 0 2px; transition: all 0.3s ease; }
        .btn-sm-action:hover { transform: translateY(-2px); }
        
        .client-select-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 2px dashed #006a4e;
            border-radius: 12px;
            padding: 20px;
        }
        
        .file-info-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            border-left: 4px solid #006a4e;
        }
        
        .file-info-box .label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #6c757d;
            font-weight: 600;
        }
        .file-info-box .value {
            font-size: 0.95rem;
            font-weight: 600;
            color: #1a1a2e;
        }
        
        .badge-division {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-division.investment { background: #e3f2fd; color: #0d47a1; }
        .badge-division.sme { background: #fff3e0; color: #e65100; }
        .badge-division.imrd { background: #f3e5f5; color: #4a148c; }
        .badge-division.default { background: #f5f5f5; color: #616161; }
        
        .btn-add-file {
            background: #28a745;
            color: #fff;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-add-file:hover { background: #1e7e34; transform: translateY(-2px); color: #fff; }
        
        .btn-back-client {
            background: #006a4e;
            color: #fff;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-back-client:hover { background: #004d3a; transform: translateY(-2px); color: #fff; }
    </style>
</head>
<body>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h4><i class="fas fa-archive"></i> Cabinet & Files Management</h4>
                <div class="subtitle"><i class="fas fa-folder-open me-1"></i> Manage cabinet files and their locations</div>
            </div>
            <div>
                <?php if ($client_id > 0): ?>
                    <a href="client_profile.php?id=<?php echo $client_id; ?>" class="btn-back-client">
                        <i class="fas fa-arrow-left"></i> Back to Client Profile
                    </a>
                <?php endif; ?>
                <a href="index.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Select Client Section -->
    <div class="card card-custom mb-4">
        <div class="card-header">
            <i class="fas fa-user"></i> Select Client
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-8">
                    <form method="GET" action="cabinet_files.php" class="d-flex gap-2">
                        <select name="client_id" class="form-select" onchange="this.form.submit()" style="max-width: 400px;">
                            <option value="">-- Select Client --</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?php echo $client['id']; ?>" <?php echo ($client_id == $client['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($client['client_name']); ?> (<?php echo htmlspecialchars($client['client_code'] ?? 'N/A'); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <noscript>
                            <button type="submit" class="btn btn-primary btn-sm">Go</button>
                        </noscript>
                    </form>
                </div>
                <div class="col-md-4 text-end">
                    <?php if ($client_id > 0 && $client_info): ?>
                        <span class="badge bg-success fs-6 p-2">
                            <i class="fas fa-check-circle me-1"></i> 
                            <?php echo htmlspecialchars($client_info['client_name']); ?>
                            <?php if (!empty($client_info['client_code'])): ?>
                                (<?php echo htmlspecialchars($client_info['client_code']); ?>)
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($client_id > 0 && $client_info): ?>
        <!-- Client Information Summary -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="file-info-box">
                    <div class="label">Client Name</div>
                    <div class="value"><?php echo htmlspecialchars($client_info['client_name']); ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="file-info-box">
                    <div class="label">Client Code</div>
                    <div class="value"><?php echo htmlspecialchars($client_info['client_code'] ?? 'N/A'); ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="file-info-box">
                    <div class="label">Total Files</div>
                    <div class="value"><?php echo count($office_files); ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="file-info-box">
                    <div class="label">Division</div>
                    <div class="value"><?php echo htmlspecialchars($client_info['division'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>

        <!-- Add New File Section -->
        <div class="card card-custom mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-plus-circle text-success"></i> Add New File</span>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#addFileForm" aria-expanded="true">
                    <i class="fas fa-chevron-down"></i> Toggle Form
                </button>
            </div>
            <div class="card-body collapse show" id="addFileForm">
                <?php echo $message; ?>
                
                <form method="POST" action="cabinet_files.php?client_id=<?php echo $client_id; ?>">
                    <input type="hidden" name="add_file" value="1">
                    <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
                    
                    <div class="row g-3">
                        <!-- Left Column -->
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Cabinet No <span class="text-danger">*</span></label>
                                <input type="text" name="cabinet_name" class="form-control" placeholder="e.g., 36" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Shelf No <span class="text-danger">*</span></label>
                                <input type="text" name="shelf_name" class="form-control" placeholder="e.g., 1,2,3,4" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">File No <span class="text-danger">*</span></label>
                                <input type="text" name="file_no" class="form-control" placeholder="e.g., 001" required>
                                <small class="text-muted">Enter the file number (e.g., 001, 002, etc.)</small>
                            </div>
                        </div>
                        
                        <!-- Right Column -->
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Branch Name</label>
                                <input type="text" name="branch_name" class="form-control" placeholder="e.g., Dilkusha Branch" value="<?php echo htmlspecialchars($client_info['branch_name'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Branch Code</label>
                                <input type="text" name="branch_code" class="form-control" placeholder="e.g., 101" value="<?php echo htmlspecialchars($client_info['branch_code'] ?? ''); ?>">
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Division</label>
                                        <select name="division" class="form-control">
                                            <option value="Investment" <?php echo ($client_info['division'] == 'Investment') ? 'selected' : ''; ?>>Investment</option>
                                            <option value="SME" <?php echo ($client_info['division'] == 'SME') ? 'selected' : ''; ?>>SME</option>
                                            <option value="IMRD" <?php echo ($client_info['division'] == 'IMRD') ? 'selected' : ''; ?>>IMRD</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Zone</label>
                                        <input type="text" name="zone" class="form-control" placeholder="e.g., Dhaka North Zone" value="<?php echo htmlspecialchars($client_info['zone'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn-add-file">
                            <i class="fas fa-save"></i> Save File
                        </button>
                        <button type="reset" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Files List Section -->
        <div class="card card-custom">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-list"></i> Files for <?php echo htmlspecialchars($client_info['client_name']); ?>
                    <span class="badge bg-secondary ms-2"><?php echo count($office_files); ?> Files</span>
                </span>
                <a href="add_record.php?client_id=<?php echo $client_id; ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-plus-circle"></i> Add via Full Form
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($office_files)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-folder-open" style="font-size: 48px;"></i>
                        <p class="mt-3">No files found for this client</p>
                        <p class="small">Use the form above to add the first file.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>File No</th>
                                    <th>Client</th>
                                    <th>Cabinet</th>
                                    <th>Shelf</th>
                                    <th>Branch</th>
                                    <th>Division</th>
                                    <th>Zone</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                           
<tbody>
    <?php foreach ($office_files as $file): ?>
        <tr>
            <td>
                <strong>
                    <i class="fas fa-file text-primary me-1"></i>
                    <?php 
                    // Display the original file name from the 'client' column
                    // This is the original name from office_files, not the client profile name
                    $display_name = !empty($file['client']) ? $file['client'] : 'Unnamed File';
                    echo htmlspecialchars($display_name); 
                    ?>
                    - <?php echo htmlspecialchars($file['file_no'] ?? 'N/A'); ?>
                </strong>
            </td>
            <td>
                <?php 
                // Show the original file name (from office_files.client)
                echo htmlspecialchars($file['client'] ?? 'N/A'); 
                ?>
            </td>
            <td>
                <i class="fas fa-archive text-warning"></i>
                <?php echo htmlspecialchars($file['cabinet_name'] ?? 'N/A'); ?>
            </td>
            <td><?php echo htmlspecialchars($file['shelf_name'] ?? 'N/A'); ?></td>
            <td>
                <?php 
                $branch_display = !empty($file['branch_name']) ? $file['branch_name'] : 'N/A';
                echo htmlspecialchars($branch_display); 
                ?>
                <?php if (!empty($file['branch_code'])): ?>
                    <small class="text-muted">(<?php echo htmlspecialchars($file['branch_code']); ?>)</small>
                <?php endif; ?>
            </td>
            <td>
                <?php 
                $division_display = !empty($file['division']) ? $file['division'] : 'N/A';
                $div_badge_class = ($division_display === 'Investment') ? 'bg-success' : (($division_display === 'SME') ? 'bg-warning text-dark' : 'bg-info text-dark');
                ?>
                <span class="badge <?php echo $div_badge_class; ?>">
                    <?php echo htmlspecialchars($division_display); ?>
                </span>
            </td>
            <td><?php echo htmlspecialchars($file['zone'] ?? 'N/A'); ?></td>
            <td>
                <a href="view_details.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-outline-info btn-sm-action" title="View Details">
                    <i class="fas fa-eye"></i>
                </a>
                <a href="edit_file.php?id=<?php echo $file['id']; ?>&client_id=<?php echo $client['id']; ?>" class="btn btn-sm btn-outline-primary btn-sm-action" title="Edit">
                    <i class="fas fa-edit"></i>
                </a>
                <button class="btn btn-sm btn-outline-danger btn-sm-action" onclick="deleteFile(<?php echo $file['id']; ?>, <?php echo $client['id']; ?>)" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
    <?php endforeach; ?>
</tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
    <?php elseif ($client_id > 0 && !$client_info): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> Client not found. Please select a valid client.
        </div>
    <?php else: ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-archive" style="font-size: 64px; color: #cbd5e1;"></i>
            <h5 class="mt-3">Select a Client</h5>
            <p>Please select a client from the dropdown above to manage their cabinet files.</p>
            <a href="client_profile.php" class="btn btn-primary">
                <i class="fas fa-users"></i> Go to Client Management
            </a>
        </div>
    <?php endif; ?>
</div>

<script src="assets/js/jquery-3.6.0.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>

<script>
function deleteFile(fileId, clientId) {
    if (!confirm('Are you sure you want to delete this file?')) return;
    
    $.ajax({
        url: 'api/files.php?id=' + fileId,
        method: 'DELETE',
        success: function(response) {
            if (response.success) {
                alert('File deleted successfully!');
                window.location.href = 'cabinet_files.php?client_id=' + clientId;
            } else {
                alert('Error: ' + response.error);
            }
        },
        error: function() {
            alert('An error occurred');
        }
    });
}

</script>

<?php include 'footer.php'; ?>
</body>
</html>