<?php
session_start();
include 'db.php';
include 'header.php';

if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

// Get filter parameters
$facility_group = isset($_GET['facility_group']) ? trim($_GET['facility_group']) : '';
$facility_type = isset($_GET['facility_type']) ? trim($_GET['facility_type']) : '';
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';

// Build WHERE clause
$where_clauses = [];
if (!empty($facility_group)) {
    $where_clauses[] = "ff.facility_group = '" . $conn->real_escape_string($facility_group) . "'";
}
if (!empty($facility_type)) {
    $where_clauses[] = "ff.facility_type = '" . $conn->real_escape_string($facility_type) . "'";
}
if ($client_id > 0) {
    $where_clauses[] = "cp.id = " . $client_id;
}
if (!empty($from_date) && !empty($to_date)) {
    $where_clauses[] = "ff.sanction_date BETWEEN '" . $conn->real_escape_string($from_date) . "' AND '" . $conn->real_escape_string($to_date) . "'";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Fetch facilities data
$query = "SELECT 
            ff.*,
            ft.facility_name,
            ft.facility_group as facility_group_name,
            cp.client_name,
            cp.client_code,
            cp.id as client_id,
            `of`.file_no,
            `of`.branch_name,
            `of`.division,
            `of`.zone,
            u.full_name as created_by_name,
            GROUP_CONCAT(fs.security_type SEPARATOR ', ') as security_types,
            GROUP_CONCAT(fs.security_value SEPARATOR ', ') as security_values
          FROM file_facilities ff
          JOIN facilities_type ft ON ff.facility_type COLLATE utf8mb4_unicode_ci = ft.facility_name COLLATE utf8mb4_unicode_ci
          LEFT JOIN office_files `of` ON ff.file_record_id = `of`.id
          LEFT JOIN client_profiles cp ON `of`.client_id = cp.id
          LEFT JOIN facility_securities fs ON ff.id = fs.facility_id
          LEFT JOIN users u ON ff.user_id = u.id
          " . $where_sql . "
          GROUP BY ff.id
          ORDER BY ff.sanction_date DESC";

$facilities = $conn->query($query);

if (!$facilities) {
    die("Query error: " . $conn->error);
}

// Get summary statistics
$summary_query = "SELECT 
                    COUNT(*) as total_facilities,
                    SUM(ff.amount) as total_amount,
                    COUNT(DISTINCT ff.facility_type) as total_types,
                    COUNT(DISTINCT ff.facility_group) as total_groups
                  FROM file_facilities ff
                  LEFT JOIN office_files `of` ON ff.file_record_id = `of`.id
                  LEFT JOIN client_profiles cp ON `of`.client_id = cp.id
                  " . $where_sql;
$summary_result = $conn->query($summary_query);
$summary = $summary_result ? $summary_result->fetch_assoc() : ['total_facilities' => 0, 'total_amount' => 0, 'total_types' => 0, 'total_groups' => 0];

// Get facility types for dropdown
$types_query = "SELECT DISTINCT facility_type FROM file_facilities ORDER BY facility_type";
$types_result = $conn->query($types_query);

// Get clients for dropdown
$clients_query = "SELECT id, client_name FROM client_profiles ORDER BY client_name";
$clients_result = $conn->query($clients_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facility Reports</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">
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
        
        .filter-section {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
        }
        .filter-section .filter-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #6c757d;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        .filter-section .form-control,
        .filter-section .form-select {
            border-radius: 8px;
            border: 1px solid #dee2e6;
            transition: all 0.3s ease;
        }
        .filter-section .form-control:focus,
        .filter-section .form-select:focus {
            border-color: #006a4e;
            box-shadow: 0 0 0 0.2rem rgba(0,106,78,0.15);
        }
        .btn-filter {
            background: #006a4e;
            color: #fff;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-filter:hover {
            background: #004d3a;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,106,78,0.3);
            color: #fff;
        }
        .btn-clear {
            background: #6c757d;
            color: #fff;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-clear:hover {
            background: #5a6268;
            transform: translateY(-2px);
            color: #fff;
        }
        
        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        }
        .stat-card .stat-icon {
            font-size: 28px;
            margin-bottom: 8px;
            display: block;
        }
        .stat-card .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: #006a4e;
        }
        .stat-card .stat-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #94a3b8;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-top: 5px;
        }
        
        .report-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            overflow: hidden;
            background: #fff;
        }
        .report-card .card-header {
            background: #fff;
            border-bottom: 2px solid #f0f2f5;
            padding: 16px 24px;
            font-weight: 600;
        }
        .report-card .card-header i { 
            color: #006a4e; 
            margin-right: 10px; 
        }
        
        .table-facilities thead th {
            background: #1a1a2e;
            color: #fff;
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            padding: 12px 16px;
            border: none;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .table-facilities tbody td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }
        .table-facilities tbody tr:hover {
            background: #f8f9fa;
        }
        
        .badge-group-funded { background: #28a745; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; }
        .badge-group-non-funded { background: #dc3545; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; }
        .badge-group-general { background: #6c757d; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; }
        
        .btn-sm-action {
            padding: 4px 10px;
            font-size: 0.75rem;
            border-radius: 6px;
            margin: 0 2px;
            transition: all 0.3s ease;
        }
        .btn-sm-action:hover {
            transform: translateY(-2px);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        .empty-state i {
            font-size: 64px;
            color: #cbd5e1;
            margin-bottom: 20px;
        }
        .empty-state h5 {
            color: #475569;
            margin-bottom: 10px;
        }
        .empty-state p {
            color: #94a3b8;
        }
        
        .filter-active-badge {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            margin: 2px 4px;
        }
        
        @media (max-width: 768px) {
            .filter-section .row > div {
                margin-bottom: 10px;
            }
            .stat-card .stat-number {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>

<div class="container-fluid px-4 py-3" style="max-width: 1600px;">
    
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h4><i class="fas fa-chart-bar"></i> Facility Reports</h4>
            <div class="subtitle"><i class="fas fa-file-alt me-1"></i> Comprehensive facility reports and statistics</div>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-light btn-action">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="client_profile.php" class="btn btn-outline-light btn-action">
                <i class="fas fa-users"></i> Clients
            </a>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-2">
                <div class="filter-label"><i class="fas fa-layer-group me-1"></i> Facility Group</div>
                <select name="facility_group" class="form-select">
                    <option value="">All Groups</option>
                    <option value="Funded" <?php echo ($facility_group == 'Funded') ? 'selected' : ''; ?>>Funded</option>
                    <option value="Non-Funded" <?php echo ($facility_group == 'Non-Funded') ? 'selected' : ''; ?>>Non-Funded</option>
                </select>
            </div>
            <div class="col-md-2">
                <div class="filter-label"><i class="fas fa-tag me-1"></i> Facility Type</div>
                <select name="facility_type" class="form-select">
                    <option value="">All Types</option>
                    <?php 
                    $types_result->data_seek(0);
                    while ($type = $types_result->fetch_assoc()): 
                    ?>
                        <option value="<?php echo htmlspecialchars($type['facility_type']); ?>" <?php echo ($facility_type == $type['facility_type']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($type['facility_type']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2">
                <div class="filter-label"><i class="fas fa-user me-1"></i> Client</div>
                <select name="client_id" class="form-select">
                    <option value="">All Clients</option>
                    <?php 
                    $clients_result->data_seek(0);
                    while ($client = $clients_result->fetch_assoc()): 
                    ?>
                        <option value="<?php echo $client['id']; ?>" <?php echo ($client_id == $client['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($client['client_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2">
                <div class="filter-label"><i class="fas fa-calendar-alt me-1"></i> From Date</div>
                <input type="date" name="from_date" class="form-control" value="<?php echo htmlspecialchars($from_date); ?>">
            </div>
            <div class="col-md-2">
                <div class="filter-label"><i class="fas fa-calendar-alt me-1"></i> To Date</div>
                <input type="date" name="to_date" class="form-control" value="<?php echo htmlspecialchars($to_date); ?>">
            </div>
            <div class="col-md-2">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn-filter w-100">
                        <i class="fas fa-filter me-1"></i> Apply Filter
                    </button>
                    <?php if (!empty($facility_group) || !empty($facility_type) || $client_id > 0 || !empty($from_date) || !empty($to_date)): ?>
                        <a href="facility_reports.php" class="btn-clear">
                            <i class="fas fa-undo"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
        
        <!-- Active Filters Display -->
        <?php if (!empty($facility_group) || !empty($facility_type) || $client_id > 0 || !empty($from_date) || !empty($to_date)): ?>
            <div class="mt-3 pt-3 border-top">
                <span class="small text-muted me-2">Active Filters:</span>
                <?php if (!empty($facility_group)): ?>
                    <span class="filter-active-badge"><i class="fas fa-layer-group me-1"></i> <?php echo htmlspecialchars($facility_group); ?></span>
                <?php endif; ?>
                <?php if (!empty($facility_type)): ?>
                    <span class="filter-active-badge"><i class="fas fa-tag me-1"></i> <?php echo htmlspecialchars($facility_type); ?></span>
                <?php endif; ?>
                <?php if ($client_id > 0): 
                    $client_name = '';
                    $clients_result->data_seek(0);
                    while ($c = $clients_result->fetch_assoc()) {
                        if ($c['id'] == $client_id) {
                            $client_name = $c['client_name'];
                            break;
                        }
                    }
                ?>
                    <span class="filter-active-badge"><i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($client_name); ?></span>
                <?php endif; ?>
                <?php if (!empty($from_date) && !empty($to_date)): ?>
                    <span class="filter-active-badge"><i class="fas fa-calendar-alt me-1"></i> <?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?></span>
                <?php endif; ?>
                <a href="facility_reports.php" class="text-danger small ms-2"><i class="fas fa-times"></i> Clear All</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Statistics -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <span class="stat-icon"><i class="fas fa-handshake text-primary"></i></span>
                <div class="stat-number"><?php echo number_format($summary['total_facilities'] ?? 0); ?></div>
                <div class="stat-label">Total Facilities</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <span class="stat-icon"><i class="fas fa-money-bill-wave text-success"></i></span>
                <div class="stat-number">BDT <?php echo number_format($summary['total_amount'] ?? 0, 0); ?></div>
                <div class="stat-label">Total Amount</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <span class="stat-icon"><i class="fas fa-tags text-info"></i></span>
                <div class="stat-number"><?php echo number_format($summary['total_types'] ?? 0); ?></div>
                <div class="stat-label">Facility Types</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <span class="stat-icon"><i class="fas fa-layer-group text-warning"></i></span>
                <div class="stat-number"><?php echo number_format($summary['total_groups'] ?? 0); ?></div>
                <div class="stat-label">Facility Groups</div>
            </div>
        </div>
    </div>

    <!-- Facilities Table -->
    <div class="report-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list"></i> Facility List</span>
            <span class="badge bg-secondary"><?php echo $facilities ? $facilities->num_rows : 0; ?> records</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-facilities table-hover align-middle mb-0" id="facilityReportTable">
                    <thead>
                        <tr>
                            <th style="width: 18%;">Client</th>
                            <th style="width: 15%;">Facility Type</th>
                            <th style="width: 12%;">Group</th>
                            <th style="width: 12%;">Amount (BDT)</th>
                            <th style="width: 10%;">Facility As</th>
                            <th style="width: 12%;">Sanction Date</th>
                            <th style="width: 10%;">Security</th>
                            <th style="width: 11%;" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($facilities && $facilities->num_rows > 0): ?>
                            <?php while ($row = $facilities->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <a href="client_profile.php?id=<?php echo $row['client_id']; ?>" class="text-dark fw-semibold text-decoration-none hover-primary">
                                            <i class="fas fa-user-circle text-primary me-1"></i>
                                            <?php echo htmlspecialchars($row['client_name'] ?? 'N/A'); ?>
                                        </a>
                                        <?php if (!empty($row['file_no'])): ?>
                                            <div class="small text-muted"><i class="fas fa-file me-1"></i> <?php echo htmlspecialchars($row['file_no']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['facility_name']); ?></strong>
                                        <div class="small text-muted"><?php echo htmlspecialchars($row['facility_type']); ?></div>
                                    </td>
                                    <td>
                                        <?php 
                                        $group_class = 'badge-group-general';
                                        if ($row['facility_group'] == 'Funded') {
                                            $group_class = 'badge-group-funded';
                                        } elseif ($row['facility_group'] == 'Non-Funded') {
                                            $group_class = 'badge-group-non-funded';
                                        }
                                        ?>
                                        <span class="<?php echo $group_class; ?>">
                                            <?php echo htmlspecialchars($row['facility_group'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td class="fw-bold text-primary"><?php echo number_format($row['amount'], 2); ?></td>
                                    <td>
                                        <?php 
                                        $as_class = 'badge bg-secondary';
                                        if ($row['facility_as'] == 'Fresh') $as_class = 'badge bg-success';
                                        elseif ($row['facility_as'] == 'Renewal') $as_class = 'badge bg-warning text-dark';
                                        elseif ($row['facility_as'] == 'Time Extension') $as_class = 'badge bg-info';
                                        elseif ($row['facility_as'] == 'Renewal with Enhancement') $as_class = 'badge bg-primary';
                                        ?>
                                        <span class="<?php echo $as_class; ?>"><?php echo htmlspecialchars($row['facility_as'] ?? 'N/A'); ?></span>
                                    </td>
                                    <td><?php echo $row['sanction_date'] ? date('d-m-Y', strtotime($row['sanction_date'])) : 'N/A'; ?></td>
                                    <td>
                                        <?php if (!empty($row['security_types'])): ?>
                                            <span class="badge bg-info text-white">
                                                <i class="fas fa-shield-alt me-1"></i>
                                                <?php echo count(explode(',', $row['security_types'])); ?> records
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="facility_details.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary btn-sm-action" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="edit_facility.php?id=<?php echo $row['id']; ?>&client_id=<?php echo $row['client_id']; ?>" class="btn btn-sm btn-outline-warning btn-sm-action" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button class="btn btn-sm btn-outline-danger btn-sm-action" onclick="deleteFacility(<?php echo $row['id']; ?>, <?php echo $row['client_id']; ?>)" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <h5>No Facilities Found</h5>
                                        <p>No facilities match your filter criteria. Try adjusting your filters or add a new facility.</p>
                                        <a href="add_record.php" class="btn btn-primary btn-sm">
                                            <i class="fas fa-plus"></i> Add New Facility
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/jquery-3.6.0.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/jquery.dataTables.min.js"></script>
<script src="assets/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#facilityReportTable').DataTable({
        "pageLength": 25,
        "ordering": true,
        "searching": true,
        "info": true,
        "language": {
            "search": "Search:",
            "lengthMenu": "Show _MENU_ facilities",
            "zeroRecords": "No matching facilities found",
            "info": "Showing _START_ to _END_ of _TOTAL_ facilities",
            "infoEmpty": "No facilities available",
            "infoFiltered": "(filtered from _MAX_ total facilities)"
        },
        "columnDefs": [
            { "orderable": false, "targets": 7 }
        ]
    });
});

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
                location.reload();
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