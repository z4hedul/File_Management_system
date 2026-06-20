<?php
session_start();
include 'db.php';
include 'header.php';

if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

$facility_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($facility_id <= 0) {
    $_SESSION['error'] = "No facility ID provided";
    header('Location: index.php');
    exit;
}

// Fetch facility details with client information
$query = "SELECT 
            ff.*,
            ft.facility_name,
            ft.facility_group as facility_group_name,
            fs.security_type,
            fs.security_value,
            fs.security_description,
            fs.created_at as security_created_at,
            `of`.client,
            `of`.file_no,
            `of`.branch_name,
            `of`.branch_code,
            `of`.division,
            `of`.zone,
            cp.client_name,
            cp.client_code,
            cp.id as client_id,
            u.full_name as created_by_name
          FROM file_facilities ff
          JOIN facilities_type ft ON ff.facility_type COLLATE utf8mb4_unicode_ci = ft.facility_name COLLATE utf8mb4_unicode_ci
          LEFT JOIN facility_securities fs ON ff.id = fs.facility_id
          LEFT JOIN office_files `of` ON ff.file_record_id = `of`.id
          LEFT JOIN client_profiles cp ON `of`.client_id = cp.id
          LEFT JOIN users u ON ff.user_id = u.id
          WHERE ff.id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $facility_id);
$stmt->execute();
$facility = $stmt->get_result()->fetch_assoc();

if (!$facility) {
    $_SESSION['error'] = "Facility not found";
    header('Location: index.php');
    exit;
}
$stmt->close();

// Fetch all security records for this facility
$sec_query = "SELECT * FROM facility_securities WHERE facility_id = ?";
$sec_stmt = $conn->prepare($sec_query);
$sec_stmt->bind_param("i", $facility_id);
$sec_stmt->execute();
$securities = $sec_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$sec_stmt->close();

// Fetch attachments for this facility
$att_query = "SELECT id, file_path, description, sanction_date FROM attachments WHERE facility_id = ? ORDER BY id ASC";
$att_stmt = $conn->prepare($att_query);
$att_stmt->bind_param("i", $facility_id);
$att_stmt->execute();
$attachments = $att_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$att_stmt->close();

$page_title = "Facility Details - " . htmlspecialchars($facility['facility_name']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
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
        
        .detail-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            overflow: hidden;
            background: #fff;
        }
        .detail-card .card-header {
            background: #fff;
            border-bottom: 2px solid #f0f2f5;
            padding: 16px 24px;
            font-weight: 600;
        }
        .detail-card .card-header i { 
            color: #006a4e; 
            margin-right: 10px; 
            width: 20px;
        }
        
        .info-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #f8f9fa;
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .label {
            font-weight: 600;
            color: #6c757d;
            width: 40%;
            font-size: 0.85rem;
        }
        .info-row .value {
            font-weight: 500;
            color: #1a1a2e;
            width: 60%;
            font-size: 0.95rem;
        }
        
        .security-item {
            background: #fff8f0;
            border-left: 4px solid #ff9800;
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 10px;
            border: 1px solid #f0e6d8;
        }
        .security-item .sec-type { 
            font-weight: 600; 
            color: #e65100; 
            font-size: 0.95rem;
        }
        .security-item .sec-value { 
            font-weight: 700; 
            color: #006a4e; 
            font-size: 1.05rem;
        }
        
        .attachment-item {
            background: #f8f9fa;
            padding: 10px 16px;
            border-radius: 6px;
            margin-bottom: 8px;
            border: 1px solid #e9ecef;
        }
        .attachment-item:hover {
            background: #f1f3f5;
        }
        
        .badge-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-status.funded { background: #28a745; color: #fff; }
        .badge-status.non-funded { background: #dc3545; color: #fff; }
        .badge-status.fresh { background: #17a2b8; color: #fff; }
        .badge-status.renewal { background: #ffc107; color: #000; }
        .badge-status.extension { background: #6f42c1; color: #fff; }
        .badge-status.enhancement { background: #fd7e14; color: #fff; }
        
        .btn-action {
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-action:hover {
            transform: translateY(-2px);
        }
        
        .stat-card {
            background: #fff;
            border-radius: 10px;
            padding: 15px 20px;
            text-align: center;
            border: 1px solid #e9ecef;
        }
        .stat-card .number {
            font-size: 24px;
            font-weight: 700;
            color: #006a4e;
        }
        .stat-card .label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #94a3b8;
            font-weight: 600;
            margin-top: 4px;
        }
        
        .action-buttons .btn {
            margin: 0 3px;
        }
    </style>
</head>
<body>

<div class="container-fluid px-4 py-3" style="max-width: 1200px;">
    
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h4><i class="fas fa-handshake"></i> Facility Details</h4>
                <div class="subtitle"><i class="fas fa-info-circle me-1"></i> View complete facility information including security and attachments</div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="edit_facility.php?id=<?php echo $facility_id; ?>&client_id=<?php echo $facility['client_id']; ?>" class="btn btn-warning btn-action">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <button class="btn btn-danger btn-action" onclick="deleteFacility(<?php echo $facility_id; ?>, <?php echo $facility['client_id'] ?? 0; ?>)">
                    <i class="fas fa-trash"></i> Delete
                </button>
                <a href="client_profile.php?id=<?php echo $facility['client_id'] ?? 0; ?>" class="btn btn-outline-secondary btn-action">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="number">BDT <?php echo number_format($facility['amount'], 0); ?></div>
                <div class="label">Facility Amount</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="number"><?php echo count($securities); ?></div>
                <div class="label">Security Records</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="number"><?php echo count($attachments); ?></div>
                <div class="label">Attachments</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="number">
                    <?php 
                    $total_security_value = array_sum(array_column($securities, 'security_value'));
                    echo 'BDT ' . number_format($total_security_value, 0);
                    ?>
                </div>
                <div class="label">Total Security</div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Left Column - Facility Information -->
        <div class="col-lg-7">
            <div class="detail-card mb-4">
                <div class="card-header">
                    <i class="fas fa-info-circle"></i> Facility Information
                </div>
                <div class="card-body p-4">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="label">Facility Name</div>
                                <div class="value"><strong><?php echo htmlspecialchars($facility['facility_name']); ?></strong></div>
                            </div>
                            <div class="info-row">
                                <div class="label">Facility Type</div>
                                <div class="value"><?php echo htmlspecialchars($facility['facility_type']); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="label">Facility Group</div>
                                <div class="value">
                                    <span class="badge-status <?php echo strtolower(str_replace('-', '', $facility['facility_group'] ?? 'funded')); ?>">
                                        <?php echo htmlspecialchars($facility['facility_group_name'] ?? $facility['facility_group'] ?? 'N/A'); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="label">Amount (BDT)</div>
                                <div class="value"><strong class="text-primary"><?php echo number_format($facility['amount'], 2); ?></strong></div>
                            </div>
                            <div class="info-row">
                                <div class="label">Facility As</div>
                                <div class="value">
                                    <span class="badge-status <?php 
                                        $facility_as = strtolower(str_replace(' ', '-', $facility['facility_as'] ?? 'fresh'));
                                        echo $facility_as;
                                    ?>">
                                        <?php echo htmlspecialchars($facility['facility_as'] ?? 'N/A'); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="label">Sanction Date</div>
                                <div class="value"><?php echo $facility['sanction_date'] ? date('d-m-Y', strtotime($facility['sanction_date'])) : 'N/A'; ?></div>
                            </div>
                            <div class="info-row">
                                <div class="label">Sanction Letter Ref</div>
                                <div class="value"><code class="bg-light p-1 px-2 rounded"><?php echo htmlspecialchars($facility['sanction_letter_ref_no'] ?? 'N/A'); ?></code></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="label">Client Name</div>
                                <div class="value">
                                    <a href="client_profile.php?id=<?php echo $facility['client_id']; ?>" class="text-primary fw-bold">
                                        <i class="fas fa-user me-1"></i>
                                        <?php echo htmlspecialchars($facility['client_name'] ?? $facility['client'] ?? 'N/A'); ?>
                                    </a>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="label">Client Code</div>
                                <div class="value"><?php echo htmlspecialchars($facility['client_code'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="label">File No</div>
                                <div class="value">
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($facility['file_no'] ?? 'N/A'); ?></span>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="label">Branch</div>
                                <div class="value">
                                    <i class="fas fa-building text-muted me-1"></i>
                                    <?php echo htmlspecialchars($facility['branch_name'] ?? 'N/A'); ?>
                                    <?php if (!empty($facility['branch_code'])): ?>
                                        <small class="text-muted">(<?php echo htmlspecialchars($facility['branch_code']); ?>)</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="label">Division</div>
                                <div class="value">
                                    <?php 
                                    $div_class = ($facility['division'] == 'Investment') ? 'bg-success' : (($facility['division'] == 'SME') ? 'bg-warning text-dark' : 'bg-info text-dark');
                                    ?>
                                    <span class="badge <?php echo $div_class; ?>">
                                        <?php echo htmlspecialchars($facility['division'] ?? 'N/A'); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="label">Zone</div>
                                <div class="value">
                                    <i class="fas fa-map-marker-alt text-muted me-1"></i>
                                    <?php echo htmlspecialchars($facility['zone'] ?? 'N/A'); ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="label">Created By</div>
                                <div class="value">
                                    <i class="fas fa-user-tie text-muted me-1"></i>
                                    <?php echo htmlspecialchars($facility['created_by_name'] ?? 'System'); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($facility['comm_meet_no']) || !empty($facility['board_meet_no']) || !empty($facility['power_delegation'])): ?>
                    <hr>
                    <div class="row">
                        <?php if (!empty($facility['comm_meet_no']) || !empty($facility['comm_meet_date'])): ?>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="label">Committee Meet No</div>
                                <div class="value"><?php echo htmlspecialchars($facility['comm_meet_no'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="label">Committee Meet Date</div>
                                <div class="value"><?php echo $facility['comm_meet_date'] ? date('d-m-Y', strtotime($facility['comm_meet_date'])) : 'N/A'; ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($facility['board_meet_no']) || !empty($facility['board_meet_date'])): ?>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="label">Board Meet No</div>
                                <div class="value"><?php echo htmlspecialchars($facility['board_meet_no'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="label">Board Meet Date</div>
                                <div class="value"><?php echo $facility['board_meet_date'] ? date('d-m-Y', strtotime($facility['board_meet_date'])) : 'N/A'; ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($facility['power_delegation'])): ?>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="label">Power Delegation</div>
                                <div class="value">
                                    <span class="badge bg-dark"><?php echo htmlspecialchars($facility['power_delegation']); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Security Details -->
            <div class="detail-card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-shield-alt text-warning"></i> Security Details</span>
                    <span class="badge bg-secondary"><?php echo count($securities); ?> records</span>
                </div>
                <div class="card-body p-4">
                    <?php if (empty($securities)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-shield-alt" style="font-size: 48px; opacity: 0.3;"></i>
                            <p class="mt-3">No security records found for this facility</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($securities as $index => $security): ?>
                            <div class="security-item">
                                <div class="row align-items-center">
                                    <div class="col-md-1">
                                        <span class="badge bg-secondary"><?php echo $index + 1; ?></span>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="sec-type"><i class="fas fa-lock"></i> <?php echo htmlspecialchars($security['security_type']); ?></div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="sec-value">BDT <?php echo number_format($security['security_value'], 2); ?></div>
                                    </div>
                                    <div class="col-md-5">
                                        <?php if (!empty($security['security_description'])): ?>
                                            <small class="text-muted d-block">
                                                <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($security['security_description']); ?>
                                            </small>
                                        <?php endif; ?>
                                        <small class="text-muted d-block">
                                            <i class="fas fa-clock"></i> Added: <?php echo date('d-m-Y H:i', strtotime($security['created_at'])); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column - Attachments & Quick Actions -->
        <div class="col-lg-5">
            <!-- Attachments -->
            <div class="detail-card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-paperclip text-success"></i> Attachments</span>
                    <span class="badge bg-secondary"><?php echo count($attachments); ?></span>
                </div>
                <div class="card-body p-4">
                    <?php if (empty($attachments)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-file" style="font-size: 48px; opacity: 0.3;"></i>
                            <p class="mt-3">No attachments found</p>
                            <small class="text-muted">Add attachments when editing the facility</small>
                        </div>
                    <?php else: ?>
                        <?php foreach ($attachments as $attachment): ?>
                            <div class="attachment-item d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-file-alt text-primary me-2"></i>
                                    <span class="small fw-semibold"><?php echo htmlspecialchars($attachment['description'] ?? 'Document'); ?></span>
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-calendar-alt me-1"></i>
                                        <?php echo $attachment['sanction_date'] ? date('d-m-Y', strtotime($attachment['sanction_date'])) : 'N/A'; ?>
                                    </small>
                                </div>
                                <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-download"></i> Download
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="detail-card">
                <div class="card-header">
                    <i class="fas fa-bolt text-warning"></i> Quick Actions
                </div>
                <div class="card-body p-4">
                    <div class="d-grid gap-2">
                        <a href="edit_facility.php?id=<?php echo $facility_id; ?>&client_id=<?php echo $facility['client_id']; ?>" class="btn btn-warning btn-action">
                            <i class="fas fa-edit"></i> Edit Facility
                        </a>
                        <a href="add_facility.php?file_id=<?php echo $facility['file_record_id']; ?>" class="btn btn-success btn-action">
                            <i class="fas fa-plus"></i> Add Another Facility
                        </a>
                        <a href="client_profile.php?id=<?php echo $facility['client_id'] ?? 0; ?>" class="btn btn-outline-primary btn-action">
                            <i class="fas fa-user"></i> View Client Profile
                        </a>
                        <a href="facility_reports.php" class="btn btn-outline-info btn-action">
                            <i class="fas fa-chart-bar"></i> View All Facilities
                        </a>
                        <button class="btn btn-outline-danger btn-action" onclick="deleteFacility(<?php echo $facility_id; ?>, <?php echo $facility['client_id'] ?? 0; ?>)">
                            <i class="fas fa-trash"></i> Delete Facility
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/jquery-3.6.0.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>

<script>
function deleteFacility(facilityId, clientId) {
    if (!confirm('Are you sure you want to delete this facility?\n\nThis action cannot be undone!')) {
        return;
    }
    
    if (!confirm('All security and attachment records will also be deleted. Continue?')) {
        return;
    }
    
    $.ajax({
        url: 'api/delete_facility.php',
        method: 'POST',
        data: JSON.stringify({
            facility_id: facilityId
        }),
        contentType: 'application/json',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert('✅ Facility deleted successfully!');
                window.location.href = 'client_profile.php?id=' + clientId;
            } else {
                alert('❌ Error: ' + response.error);
            }
        },
        error: function(xhr) {
            alert('❌ An error occurred. Please try again.');
            console.error(xhr);
        }
    });
}
</script>

<?php include 'footer.php'; ?>
</body>
</html>