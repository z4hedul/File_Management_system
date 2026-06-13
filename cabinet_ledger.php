<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
include 'header.php';

// Verify authentication state boundary
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

// Get filter parameters from URL query parameters
$selected_cabinet = isset($_GET['cabinet_no']) ? trim($_GET['cabinet_no']) : '';
$selected_division = isset($_GET['division']) ? trim($_GET['division']) : '';

// Fetch distinct active cabinet numbers with division-wise file counts
$cabinet_options = [];
try {
    $cab_query = "SELECT cabinet_name, division, COUNT(*) as file_count 
                  FROM office_files 
                  WHERE is_deleted = 0 AND cabinet_name IS NOT NULL AND cabinet_name != '' 
                  GROUP BY cabinet_name, division 
                  ORDER BY LENGTH(cabinet_name) ASC, cabinet_name ASC, division ASC";
    $cab_res = $conn->query($cab_query);
    if ($cab_res) {
        while ($c_row = $cab_res->fetch_assoc()) {
            $cab_name = $c_row['cabinet_name'];
            if (!isset($cabinet_options[$cab_name])) {
                $cabinet_options[$cab_name] = [
                    'total' => 0,
                    'divisions' => []
                ];
            }
            $cabinet_options[$cab_name]['divisions'][$c_row['division']] = $c_row['file_count'];
            $cabinet_options[$cab_name]['total'] += $c_row['file_count'];
        }
    }
} catch (mysqli_sql_exception $e) { }

// Fetch distinct active divisions to populate the filter dropdown dynamically
$division_options = [];
try {
    $div_query = "SELECT division, COUNT(*) as file_count 
                  FROM office_files 
                  WHERE is_deleted = 0 AND division IS NOT NULL AND division != '' 
                  GROUP BY division 
                  ORDER BY division ASC";
    $div_res = $conn->query($div_query);
    if ($div_res) {
        while ($d_row = $div_res->fetch_assoc()) {
            $division_options[] = [
                'name' => $d_row['division'],
                'count' => $d_row['file_count']
            ];
        }
    }
} catch (mysqli_sql_exception $e) { }

// Initialize empty array for records
$ledger_records = [];
$total_files_found = 0;

// CRITICAL: Only query the database and pull records IF the user has clicked "Filter" 
// (meaning at least one filter criterion is active)
if ($selected_cabinet !== '' || $selected_division !== '') {
    $where_clauses = ["is_deleted = 0"];
    if ($selected_cabinet !== '') {
        $where_clauses[] = "cabinet_name = '" . $conn->real_escape_string($selected_cabinet) . "'";
    }
    if ($selected_division !== '') {
        $where_clauses[] = "division = '" . $conn->real_escape_string($selected_division) . "'";
    }
    $where_sql = " WHERE " . implode(" AND ", $where_clauses);

    // Get total count first
    $count_query = "SELECT COUNT(*) as total FROM office_files" . $where_sql;
    $count_res = $conn->query($count_query);
    if ($count_res) {
        $total_files_found = $count_res->fetch_assoc()['total'];
    }

    $data_query = "SELECT * FROM office_files" . $where_sql . " ORDER BY LENGTH(cabinet_name) ASC, cabinet_name ASC, LENGTH(shelf_name) ASC, shelf_name ASC";
    $data_res = $conn->query($data_query);
    if ($data_res) {
        while ($row = $data_res->fetch_assoc()) {
            $ledger_records[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cabinet & Division Ledger</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">
    <style>
        .cabinet-badge { font-size: 0.9rem; font-weight: 700; padding: 6px 12px; border-radius: 6px; }
        .shelf-badge { font-size: 0.85rem; font-weight: 600; padding: 4px 10px; }
        .filter-stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 12px 20px;
            transition: all 0.3s ease;
        }
        .filter-stat-card i {
            font-size: 1.8rem;
            opacity: 0.8;
        }
        .option-with-count {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 5px;
        }
        .badge-count {
            background-color: #e9ecef;
            color: #495057;
            border-radius: 20px;
            padding: 2px 8px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .cabinet-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 5px;
        }
        .stat-item {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 2px 10px;
            font-size: 0.7rem;
        }
        .stat-item strong {
            color: #006a4e;
        }
    </style>
</head>
<body class="bg-light">
<div class="container-fluid px-4 py-4" style="max-width: 1500px;">

    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-3 shadow-sm border-0">
        <div>
            <h3 class="fw-bold text-dark mb-0"><i class="fas fa-warehouse text-primary me-2"></i>Cabinet Record Center</h3>
            <p class="text-muted small mb-0">Select filters and click "Filter" to retrieve system filing cabinet records.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-dark"><i class="fas fa-chart-line me-1"></i> Dashboard</a>
            <a href="add_record.php" class="btn btn-primary"><i class="fas fa-plus me-1"></i> New Record</a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <?php if ($selected_cabinet !== '' || $selected_division !== ''): ?>
            <div class="col-md-4">
                <div class="filter-stat-card shadow-sm">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-white-50 text-uppercase">Filter Applied</small>
                            <h4 class="mb-0 fw-bold mt-1"><?= htmlspecialchars($selected_cabinet ?: $selected_division) ?></h4>
                        </div>
                        <i class="fas fa-filter"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="filter-stat-card shadow-sm" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-white-50 text-uppercase">Total Files Found</small>
                            <h4 class="mb-0 fw-bold mt-1"><?= number_format($total_files_found) ?></h4>
                        </div>
                        <i class="fas fa-folder-open"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="filter-stat-card shadow-sm" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-white-50 text-uppercase">Displaying Records</small>
                            <h4 class="mb-0 fw-bold mt-1"><?= number_format(count($ledger_records)) ?></h4>
                        </div>
                        <i class="fas fa-database"></i>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="col-md-6">
                <div class="filter-stat-card shadow-sm" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-white-50 text-uppercase">Total Active Files</small>
                            <?php
                            $total_active_query = "SELECT COUNT(*) as total FROM office_files WHERE is_deleted = 0";
                            $total_active_res = $conn->query($total_active_query);
                            $total_active = $total_active_res ? $total_active_res->fetch_assoc()['total'] : 0;
                            ?>
                            <h4 class="mb-0 fw-bold mt-1"><?= number_format($total_active) ?></h4>
                        </div>
                        <i class="fas fa-archive"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="filter-stat-card shadow-sm" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-white-50 text-uppercase">Available Cabinets</small>
                            <h4 class="mb-0 fw-bold mt-1"><?= number_format(count($cabinet_options)) ?></h4>
                        </div>
                        <i class="fas fa-boxes"></i>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card shadow-sm border-0 mb-4 bg-white rounded-3">
        <div class="card-body p-3">
            <form method="GET" action="cabinet_ledger.php" id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label for="cabinet_no_select" class="form-label small fw-bold text-muted text-uppercase mb-1"><i class="fas fa-box me-1 text-primary"></i>Cabinet Selection</label>
                    <select name="cabinet_no" id="cabinet_no_select" class="form-select border-primary-subtle">
                        <option value="">-- All Cabinets --</option>
                        <?php foreach ($cabinet_options as $cab_name => $cab_data): ?>
                            <option value="<?= htmlspecialchars($cab_name) ?>" <?= ($selected_cabinet === $cab_name) ? 'selected' : '' ?>>
                                <div class="option-with-count">
                                    <div>
                                        <strong>Cabinet: <?= htmlspecialchars($cab_name) ?></strong>
                                        <span class="badge-count ms-2">Total: <?= number_format($cab_data['total']) ?> files</span>
                                    </div>
                                    <div class="cabinet-stats mt-1">
                                        <?php foreach ($cab_data['divisions'] as $div_name => $div_count): ?>
                                            <span class="stat-item">
                                                <i class="fas fa-layer-group"></i> <?= htmlspecialchars($div_name) ?>: <strong><?= number_format($div_count) ?></strong>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-5">
                    <label for="division_select" class="form-label small fw-bold text-muted text-uppercase mb-1"><i class="fas fa-layer-group me-1 text-success"></i>Division Selection</label>
                    <select name="division" id="division_select" class="form-select border-primary-subtle">
                        <option value="">-- All Divisions --</option>
                        <?php foreach ($division_options as $div): ?>
                            <option value="<?= htmlspecialchars($div['name']) ?>" <?= ($selected_division === $div['name']) ? 'selected' : '' ?>>
                                <div class="option-with-count">
                                    <span>Division: <?= htmlspecialchars($div['name']) ?></span>
                                    <span class="badge-count ms-2"><?= number_format($div['count']) ?> files</span>
                                </div>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="fas fa-filter me-1"></i> Filter</button>
                    <?php if ($selected_cabinet !== '' || $selected_division !== ''): ?>
                        <a href="cabinet_ledger.php" class="btn btn-outline-secondary" title="Reset All Filters"><i class="fas fa-undo"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0 bg-white rounded-3">
        <div class="card-body p-3">
            <div class="table-responsive">
                <table id="clientMemoryDataTable" class="table table-striped table-hover align-middle m-0 w-100">
                    <thead class="table-dark small text-uppercase">
                        <tr>
                            <th class="text-center" style="width: 12%;">Cabinet No</th>
                            <th class="text-center" style="width: 12%;">Shelf No</th>
                            <th class="text-center" style="width: 12%;">File No</th>
                            <th style="width: 28%;">Client Name</th>
                            <th style="width: 24%;">Branch / Zone</th>
                            <th style="width: 12%;">Division</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <?php if (empty($ledger_records)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 fw-bold text-muted">
                                    <i class="fas fa-info-circle me-1 text-primary"></i> Please choose a Cabinet or Division above and click "Filter" to view the ledger.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($ledger_records as $row): ?>
                                <tr>
                                    <td class="text-center">
                                        <span class="badge bg-primary text-white cabinet-badge shadow-sm">
                                            <i class="fas fa-box me-1"></i><?= htmlspecialchars($row['cabinet_name']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary-subtle text-dark border shelf-badge">
                                            <i class="fas fa-layer-group me-1 text-secondary"></i><?= htmlspecialchars($row['shelf_name']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center font-monospace fw-bold text-primary">
                                        <?= htmlspecialchars($row['file_no']) ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark mb-0" style="font-size: 0.95rem;">
                                            <?= htmlspecialchars($row['client']) ?>
                                        </div>
                                        <?php if (!empty($row['remarks'])): ?>
                                            <small class="text-muted d-block text-truncate font-monospace" style="max-width: 350px; font-size:0.75rem;">
                                                <strong>Note:</strong> <?= htmlspecialchars($row['remarks']) ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="mb-0 fw-semibold text-secondary">
                                            <i class="fas fa-code-branch text-info small me-1"></i><?= htmlspecialchars($row['branch_code']) ?> - <?= htmlspecialchars($row['branch_name']) ?>
                                        </div>
                                        <small class="text-muted font-monospace" style="font-size: 0.72rem;">
                                            <i class="fas fa-globe me-1"></i><?= htmlspecialchars($row['zone']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php 
                                            $div_val = trim($row['division']);
                                            $div_badge_class = ($div_val === 'Investment') ? 'bg-success' : (($div_val === 'SME') ? 'bg-warning text-dark' : 'bg-info text-dark');
                                        ?>
                                        <span class="badge <?= $div_badge_class ?> fw-bold px-2 py-1">
                                            <?= htmlspecialchars($row['division']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/jquery.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/jquery.dataTables.min.js"></script>
<script src="assets/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#clientMemoryDataTable').DataTable({
        "paging": true,
        "lengthMenu": [[10, 25, 50, 100], [10, 25, 50, 100]],
        "pageLength": 25,
        "ordering": true,
        "searching": true,
        "info": true,
        "language": {
            "search": "_INPUT_",
            "searchPlaceholder": "Search within results...",
            "lengthMenu": "Display _MENU_ records",
            "zeroRecords": "No matching filing units found inside active table rows."
        },
        "dom": "<'row mb-2'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6 text-end'f>>" +
               "<'row'<'col-sm-12'tr>>" +
               "<'row mt-2'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7 text-end'p>>"
    });
});
</script>
</body>
</html>