<?php
session_start();
include 'db.php';
include 'header.php';

if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$security_type = isset($_GET['security_type']) ? trim($_GET['security_type']) : '';

// Build WHERE clause
$where_clauses = [];
if ($client_id > 0) {
    $where_clauses[] = "cp.id = " . $client_id;
}
if (!empty($security_type)) {
    $where_clauses[] = "fs.security_type = '" . $conn->real_escape_string($security_type) . "'";
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Fetch security data - No collation issue here, but keep the backticks
$query = "SELECT 
            fs.*,
            ff.facility_type,
            ff.facility_group,
            ff.amount as facility_amount,
            ff.sanction_date,
            ff.sanction_letter_ref_no,
            ff.facility_as,
            cp.client_name,
            cp.client_code,
            cp.id as client_id,
            `of`.file_no,
            `of`.branch_name
          FROM facility_securities fs
          JOIN file_facilities ff ON fs.facility_id = ff.id
          LEFT JOIN office_files `of` ON ff.file_record_id = `of`.id
          LEFT JOIN client_profiles cp ON `of`.client_id = cp.id
          " . $where_sql . "
          ORDER BY fs.created_at DESC";

$securities = $conn->query($query);

if (!$securities) {
    die("Query error: " . $conn->error);
}

// Get summary
$summary_query = "SELECT 
                    COUNT(*) as total_securities,
                    SUM(fs.security_value) as total_value,
                    COUNT(DISTINCT fs.security_type) as total_types
                  FROM facility_securities fs
                  JOIN file_facilities ff ON fs.facility_id = ff.id
                  LEFT JOIN office_files `of` ON ff.file_record_id = `of`.id
                  LEFT JOIN client_profiles cp ON `of`.client_id = cp.id
                  " . $where_sql;
$summary_result = $conn->query($summary_query);
$summary = $summary_result ? $summary_result->fetch_assoc() : ['total_securities' => 0, 'total_value' => 0, 'total_types' => 0];

// Get security types for dropdown
$types_query = "SELECT DISTINCT security_type FROM facility_securities ORDER BY security_type";
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
    <title>Security Reports</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background: #f0f2f5 !important; font-family: 'Segoe UI', system-ui, sans-serif; }
        .report-card { border: none; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); overflow: hidden; }
        .stat-box { background: #fff; border-radius: 10px; padding: 20px; text-align: center; border: 1px solid #e9ecef; }
        .stat-box .number { font-size: 28px; font-weight: 700; color: #006a4e; }
        .stat-box .label { font-size: 0.7rem; text-transform: uppercase; color: #94a3b8; font-weight: 600; margin-top: 4px; }
        .security-item { border-left: 4px solid #ff9800; padding: 12px 16px; background: #fff; border-radius: 6px; margin-bottom: 10px; }
        .filter-section { background: #fff; padding: 20px; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
    </style>
</head>
<body class="bg-light p-4">

<div class="container" style="max-width: 1400px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="fas fa-shield-alt text-warning"></i> Security Reports</h4>
            <small class="text-muted">Comprehensive security details for all facilities</small>
        </div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-bold">Client</label>
                <select name="client_id" class="form-select">
                    <option value="">All Clients</option>
                    <?php while ($client = $clients_result->fetch_assoc()): ?>
                        <option value="<?php echo $client['id']; ?>" <?php echo ($client_id == $client['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($client['client_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Security Type</label>
                <select name="security_type" class="form-select">
                    <option value="">All Types</option>
                    <?php while ($type = $types_result->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($type['security_type']); ?>" <?php echo ($security_type == $type['security_type']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($type['security_type']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter"></i> Apply Filter</button>
            </div>
        </form>
        <?php if ($client_id > 0 || !empty($security_type)): ?>
            <div class="mt-3">
                <a href="security_reports.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-undo"></i> Clear Filters</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Statistics -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-box">
                <div class="number"><?php echo number_format($summary['total_securities'] ?? 0); ?></div>
                <div class="label">Total Security Records</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-box">
                <div class="number">BDT <?php echo number_format($summary['total_value'] ?? 0, 0); ?></div>
                <div class="label">Total Security Value</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-box">
                <div class="number"><?php echo number_format($summary['total_types'] ?? 0); ?></div>
                <div class="label">Security Types</div>
            </div>
        </div>
    </div>

    <!-- Security List -->
    <div class="report-card">
        <div class="card-header bg-white border-bottom">
            <h5 class="mb-0"><i class="fas fa-list"></i> Security Records</h5>
        </div>
        <div class="card-body">
            <?php if ($securities && $securities->num_rows > 0): ?>
                <?php while ($row = $securities->fetch_assoc()): ?>
                    <div class="security-item">
                        <div class="row align-items-center">
                            <div class="col-md-3">
                                <div class="fw-bold"><?php echo htmlspecialchars($row['client_name'] ?? 'N/A'); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($row['file_no'] ?? 'N/A'); ?></small>
                            </div>
                            <div class="col-md-2">
                                <span class="badge bg-info"><?php echo htmlspecialchars($row['security_type']); ?></span>
                            </div>
                            <div class="col-md-2">
                                <div class="fw-bold text-success">BDT <?php echo number_format($row['security_value'], 2); ?></div>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">
                                    <div><i class="fas fa-building"></i> <?php echo htmlspecialchars($row['facility_type']); ?></div>
                                    <div><i class="fas fa-tag"></i> <?php echo htmlspecialchars($row['facility_as'] ?? 'N/A'); ?></div>
                                </small>
                            </div>
                            <div class="col-md-2 text-end">
                                <a href="facility_details.php?id=<?php echo $row['facility_id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye"></i> View Facility
                                </a>
                            </div>
                        </div>
                        <?php if (!empty($row['security_description'])): ?>
                            <div class="mt-2 small text-muted">
                                <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($row['security_description']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-shield-alt" style="font-size: 48px; opacity: 0.3;"></i>
                    <p class="mt-3">No security records found</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>