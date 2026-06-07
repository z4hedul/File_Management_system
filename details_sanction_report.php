<?php
session_start();
include 'db.php';
include 'header.php';
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$facility_filter = trim($_GET['facility_filter'] ?? '');
$selected_year = isset($_GET['year']) ? trim($_GET['year']) : '';

if (!empty($from_date)) {
    $d = DateTime::createFromFormat('Y-m-d', $from_date);
    $from_date = $d ? $d->format('Y-m-d') : '';
}
if (!empty($to_date)) {
    $d = DateTime::createFromFormat('Y-m-d', $to_date);
    $to_date = $d ? $d->format('Y-m-d') : '';
}

// =========================================================================
// NEW CONFIGURATION: Fetch Distinct Facility Types for our Select Dropdown
// =========================================================================
$dropdown_facilities = [];
$facilityListQuery = "SELECT DISTINCT TRIM(facility_type) AS f_type 
                      FROM file_facilities 
                      WHERE facility_type IS NOT NULL AND TRIM(facility_type) != '' 
                      ORDER BY TRIM(facility_type) ASC";
$facilityListRes = $conn->query($facilityListQuery);
if ($facilityListRes) {
    while ($f_row = $facilityListRes->fetch_assoc()) {
        $dropdown_facilities[] = $f_row['f_type'];
    }
}

// =========================================================================
// BUILD PARAMETERS ARRAYS DYNAMICALLY TO AVOID BIND COUNT CRASHES
// =========================================================================
$where_clauses = ["o.is_deleted = 0"];
$base_types = "";
$base_values = [];

if (!empty($from_date)) {
    $where_clauses[] = "ff.sanction_date >= ?";
    $base_types .= "s";
    $base_values[] = $from_date . " 00:00:00";
}
if (!empty($to_date)) {
    $where_clauses[] = "ff.sanction_date <= ?";
    $base_types .= "s";
    $base_values[] = $to_date . " 23:59:59";
}
if (!empty($facility_filter)) {
    $where_clauses[] = "ff.facility_type LIKE CONCAT('%', ?, '%')";
    $base_types .= "s";
    $base_values[] = $facility_filter;
}

if ($selected_year !== '') {
    if (strtolower($selected_year) === 'unknown') {
        $where_clauses[] = "(ff.sanction_date IS NULL OR ff.sanction_date = '' OR ff.sanction_date = '0000-00-00')";
    } elseif (is_numeric($selected_year)) {
        $where_clauses[] = "YEAR(ff.sanction_date) = ?";
        $base_types .= "i";
        $base_values[] = intval($selected_year);
    }
}

$where_string = " WHERE " . implode(" AND ", $where_clauses);

// 1. CALCULATE TOTAL COUNT FOR PAGINATION
$countQuery = "SELECT COUNT(*) AS total
               FROM file_facilities ff
               JOIN office_files o ON ff.file_record_id = o.id" . $where_string;

$countStmt = $conn->prepare($countQuery);
if (!empty($base_types)) {
    $countStmt->bind_param($base_types, ...$base_values);
}
$countStmt->execute();
$countResult = $countStmt->get_result()->fetch_assoc();
$total_count = intval($countResult['total'] ?? 0);
$countStmt->close();

// 2. BUILD OVERALL TOTALS ACROSS ALL YEARS FOR ACTIVE FILTERS
$globalTotals = [];
$global_total_all = 0;
$global_type_totals = [];
$globalQuery = "SELECT COALESCE(NULLIF(UPPER(TRIM(ff.facility_type)), ''), 'UNKNOWN') AS fac_type, SUM(ff.amount) AS total_amt
                FROM file_facilities ff
                JOIN office_files o ON ff.file_record_id = o.id" . $where_string . " GROUP BY fac_type";

$globalStmt = $conn->prepare($globalQuery);
if (!empty($base_types)) {
    $globalStmt->bind_param($base_types, ...$base_values);
}
$globalStmt->execute();
$globalRes = $globalStmt->get_result();
while ($row = $globalRes->fetch_assoc()) {
    $type = $row['fac_type'];
    $amt = floatval($row['total_amt'] ?? 0);
    $global_type_totals[$type] = $amt;
    $global_total_all += $amt;
}
$globalStmt->close();

// Pagination settings
$per_page = 25;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;
$total_pages = max(1, (int)ceil($total_count / max(1, $per_page)));

// 3. BUILD A YEAR SUMMARY LIST SIDEBAR
$available_years = [];
$yearsQuery = "SELECT COALESCE(YEAR(ff.sanction_date), 0) AS yr, COUNT(*) AS row_count, SUM(ff.amount) AS total
               FROM file_facilities ff
               JOIN office_files o ON ff.file_record_id = o.id" . $where_string . " GROUP BY yr ORDER BY yr DESC";

// Re-compile variables without specific year restrictions for the dynamic year tab sidebar totals
$year_summary_clauses = ["o.is_deleted = 0"];
$year_summary_types = "";
$year_summary_values = [];
if (!empty($from_date)) { $year_summary_clauses[] = "ff.sanction_date >= ?"; $year_summary_types .= "s"; $year_summary_values[] = $from_date . " 00:00:00"; }
if (!empty($to_date)) { $year_summary_clauses[] = "ff.sanction_date <= ?"; $year_summary_types .= "s"; $year_summary_values[] = $to_date . " 23:59:59"; }
if (!empty($facility_filter)) { $year_summary_clauses[] = "ff.facility_type LIKE CONCAT('%', ?, '%')"; $year_summary_types .= "s"; $year_summary_values[] = $facility_filter; }
$year_summary_where = " WHERE " . implode(" AND ", $year_summary_clauses);
$yearsQueryActual = "SELECT COALESCE(YEAR(ff.sanction_date), 0) AS yr, COUNT(*) AS row_count, SUM(ff.amount) AS total
                     FROM file_facilities ff
                     JOIN office_files o ON ff.file_record_id = o.id" . $year_summary_where . " GROUP BY yr ORDER BY yr DESC";

$yearsStmt = $conn->prepare($yearsQueryActual);
if (!empty($year_summary_types)) {
    $yearsStmt->bind_param($year_summary_types, ...$year_summary_values);
}
$yearsStmt->execute();
$yearsRes = $yearsStmt->get_result();
while ($r = $yearsRes->fetch_assoc()) {
    $yr = intval($r['yr']) === 0 ? 'Unknown' : (string)intval($r['yr']);
    $available_years[$yr] = [
        'rows' => intval($r['row_count']),
        'total' => floatval($r['total'] ?? 0),
        'type_totals' => [],
    ];
}
$yearsStmt->close();
krsort($available_years);

// =========================================================================
// 4. MAIN DE-DUPLICATED DUAL-USER QUERY (WITH LIMIT AND OFFSET)
// =========================================================================
$query = "SELECT ff.*, o.branch_code, o.branch_name, o.division, o.client, o.file_no,
                 u1.full_name AS sanctioned_by_user,
                 u2.full_name AS updated_by_user
          FROM file_facilities ff
          JOIN office_files o ON ff.file_record_id = o.id
          LEFT JOIN users u1 ON ff.user_id = u1.id
          LEFT JOIN users u2 ON ff.updated_by = u2.id" . $where_string;

$query .= " ORDER BY ff.sanction_date DESC, ff.id DESC LIMIT ? OFFSET ?";

$stmt = $conn->prepare($query);

// Pack parameter typing map dynamically with pagination limits safely
$main_types = $base_types . "ii";
$main_values = array_merge($base_values, [$per_page, $offset]);

$stmt->bind_param($main_types, ...$main_values);
$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_all = 0;
$type_totals = [];
$year_groups = [];

foreach ($rows as $row) {
    $amount = floatval($row['amount'] ?? 0);
    $total_all += $amount;
    $type = strtoupper(trim($row['facility_type'] ?? '')) ?: 'UNKNOWN';
    $type_totals[$type] = ($type_totals[$type] ?? 0) + $amount;

    $year = !empty($row['sanction_date']) && $row['sanction_date'] !== '0000-00-00' ? date('Y', strtotime($row['sanction_date'])) : 'Unknown';
    if (!isset($year_groups[$year])) {
        $year_groups[$year] = [
            'rows' => [],
            'total' => 0,
            'type_totals' => [],
        ];
    }
    $year_groups[$year]['rows'][] = $row;
    $year_groups[$year]['total'] += $amount;
    $year_groups[$year]['type_totals'][$type] = ($year_groups[$year]['type_totals'][$type] ?? 0) + $amount;
}

krsort($year_groups);

// Debug output code block container
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    echo '<div class="container mt-3"><div class="card p-3 mb-3"><pre style="white-space:pre-wrap; background:#f8f9fa; padding:12px; border:1px solid #e0e0e0;">';
    echo "AVAILABLE_YEARS:\n";
    if (!empty($available_years)) {
        print_r($available_years);
    } else {
        echo "(no available_years computed)\n";
    }
    echo "\nYEAR_GROUPS (current page):\n";
    print_r($year_groups);
    echo '</pre></div></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sanction Facility Report</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link class="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">
    <style>
        .report-header { background-color: #f8f9fa; border-left: 4px solid #0d6efd; }
        .table-fixed { table-layout: fixed; word-wrap: break-word; }
        .summary-card { min-height: 130px; }
        .year-panel { display: none; }
        .year-panel.active { display: block; }
        .year-tabs .nav-link { cursor: pointer; }
    </style>
</head>
<body>
<div class="container main-container pb-5">
    <div class="card shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-chart-line me-2"></i> Sanction Facility Report</h5>
                <small class="text-muted">View facility type and sanction amount across all clients in a selected date range.</small>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-home me-1"></i> Dashboard</a>
                <a href="add_record.php" class="btn btn-success btn-sm"><i class="fas fa-plus me-1"></i> New File Record</a>
            </div>
        </div>
        <div class="card-body">
            <form method="get" class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label">From Date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($from_date); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">To Date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($to_date); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Facility Type</label>
                    <select name="facility_filter" class="form-select form-select-sm">
                        <option value="">-- All Facility Types --</option>
                        <?php foreach ($dropdown_facilities as $f_opt): ?>
                            <option value="<?php echo htmlspecialchars($f_opt); ?>" <?php echo ($facility_filter === $f_opt) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(strtoupper($f_opt)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-filter me-1"></i> Apply Filter</button>
                </div>
            </form>

            <?php if ($from_date || $to_date || $facility_filter): ?>
                <div class="alert alert-info shadow-sm border-0 mb-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>Active filter:</strong>
                            <?php
                                $labelParts = [];
                                if ($from_date) { $labelParts[] = 'From ' . date('d.m.Y', strtotime($from_date)); }
                                if ($to_date) { $labelParts[] = 'To ' . date('d.m.Y', strtotime($to_date)); }
                                if ($facility_filter) { $labelParts[] = 'Facility: "' . htmlspecialchars($facility_filter) . '"'; }
                                echo implode(' | ', $labelParts);
                            ?>
                        </div>
                        <a href="sanction_report.php" class="btn btn-sm btn-outline-secondary">Clear Filter</a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mb-3">
                <h6 class="mb-2 fw-semibold">Overall totals for all years</h6>
            </div>
            <div class="row row-cols-1 row-cols-md-4 g-3 mb-4">
                <?php if (!empty($global_type_totals)): ?>
                    <?php foreach ($global_type_totals as $t => $amt): ?>
                        <div class="col">
                            <div class="card border-primary summary-card shadow-sm h-100">
                                <div class="card-body p-3">
                                    <div class="text-secondary small mb-2"><?php echo htmlspecialchars($t); ?></div>
                                    <div class="fs-5 fw-bold"><?php echo number_format($amt, 2); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="col">
                    <div class="card border-dark summary-card shadow-sm h-100">
                        <div class="card-body p-3">
                            <div class="text-secondary small mb-2">Total</div>
                            <div class="fs-5 fw-bold"><?php echo number_format($global_total_all, 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($available_years)): ?>
                <ul class="nav nav-pills year-tabs gap-2 mb-4">
                    <?php $yIdx = 0; foreach ($available_years as $y => $ainfo): ?>
                        <?php $isActiveTab = ($selected_year !== '' && $selected_year === (string)$y) || ($selected_year === '' && $yIdx === 0); ?>
                        <li class="nav-item">
                            <button type="button" class="nav-link <?php echo $isActiveTab ? 'active' : ''; ?>" data-year="<?php echo htmlspecialchars($y); ?>">
                                <?php echo htmlspecialchars($y); ?> <span class="badge bg-light text-dark ms-1"><?php echo intval($ainfo['rows']); ?></span>
                            </button>
                        </li>
                    <?php $yIdx++; endforeach; ?>
                </ul>

                <?php $pIdx = 0; foreach ($available_years as $y => $ainfo): ?>
                    <?php $g = $year_groups[$y] ?? ['rows' => [], 'total' => 0, 'type_totals' => []]; ?>
                    <?php $isActivePanel = ($selected_year !== '' && $selected_year === (string)$y) || ($selected_year === '' && $pIdx === 0); ?>
                    <div class="year-panel <?php echo $isActivePanel ? 'active' : ''; ?>" data-year-panel="<?php echo htmlspecialchars($y); ?>">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <h6 class="mb-0 fw-bold text-primary">Year <?php echo htmlspecialchars($y); ?></h6>
                                <small class="text-muted">Total: <?php echo number_format($g['total'], 2); ?></small>
                            </div>
                        </div>

                        <?php
                            $rowsByDate = [];
                            foreach ($g['rows'] as $r) {
                                $dateKey = !empty($r['sanction_date']) ? date('d.m.Y', strtotime($r['sanction_date'])) : 'N/A';
                                $rowsByDate[$dateKey][] = $r;
                            }
                        ?>

                        <?php if (!empty($g['rows'])): ?>
                            <?php foreach ($rowsByDate as $dateKey => $dateRows): ?>
    <?php 
    // Parse individual sanction numbers safely from the first grouped array element
    $firstRef = htmlspecialchars($dateRows[0]['sanction_letter_ref_no'] ?? 'N/A'); 
    
    // =========================================================================
    // CORRECT FIX: Parse and declare meeting dates for this loop element group
    // =========================================================================
    $c_date_raw = $dateRows[0]['comm_meet_date'] ?? '';
    if (!empty($c_date_raw) && $c_date_raw !== '0000-00-00' && $c_date_raw !== '1970-01-01' && strtotime($c_date_raw) > 0) {
        $display_comm_date = date("d.m.Y", strtotime($c_date_raw));
    } else {
        $display_comm_date = 'N/A';
    }

    $b_date_raw = $dateRows[0]['board_meet_date'] ?? '';
    if (!empty($b_date_raw) && $b_date_raw !== '0000-00-00' && $b_date_raw !== '1970-01-01' && strtotime($b_date_raw) > 0) {
        $display_board_date = date("d.m.Y", strtotime($b_date_raw));
    } else {
        $display_board_date = 'N/A';
    }
    
    // Fetch user profile fallback who created this record
    $sanctioned_by_user = htmlspecialchars($dateRows[0]['sanctioned_by_user'] ?? 'System / Legacy');
    ?>

   <div class="sanction-header d-flex justify-content-between align-items-center bg-light border p-3 mt-4 rounded-top shadow-sm">
    <div class="fw-bold text-dark">
        <i class="fas fa-calendar-check text-primary me-2"></i>
        <span class="badge bg-primary text-white me-2">Approval No: <?php echo htmlspecialchars($rows[0]['sanction_letter_ref_no'] ?? 'N/A'); ?></span>
        <span class="text-muted">Sanction Date:</span> <?php echo htmlspecialchars($dateKey); ?>
        
        <div class="small mt-1 fw-normal text-muted d-flex align-items-center gap-3">
            <div>
                <i class="fas fa-user-check me-1 text-success" style="font-size: 0.8rem;"></i> 
                Sanctioned By: <strong class="text-dark"><?php echo htmlspecialchars($rows[0]['sanctioned_by_user'] ?? 'System / Legacy'); ?></strong>
            </div>
            
            <?php if (!empty($rows[0]['updated_by_user'])): ?>
                <div class="border-start ps-3">
                    <i class="fas fa-user-edit me-1 text-warning" style="font-size: 0.8rem;"></i> 
                    Updated By: <strong class="text-dark"><?php echo htmlspecialchars($rows[0]['updated_by_user']); ?></strong>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="text-end">
        <div class="small">
            <strong>Invest. Committee Meeting No &amp; (Date):</strong> <?php echo htmlspecialchars($rows[0]['comm_meet_no'] ?: 'N/A'); ?>
            <span class="text-muted">(<?php echo $display_comm_date; ?>)</span>
        </div>
        <div class="small">
            <strong>Board Meeting No &amp; (Date):</strong> <?php echo htmlspecialchars($rows[0]['board_meet_no'] ?: 'N/A'); ?>
            <span class="text-muted">(<?php echo $display_board_date; ?>)</span>
        </div>
    </div>
</div>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover table-fixed align-middle mb-3">
                                        <thead class="table-light small text-uppercase">
                                            <tr>
                                                <th>Client</th>
                                                <th>Branch</th>
                                                <th>Division</th>
                                                <th>Facility Type</th>
                                                <th class="text-end">Amount</th>
                                                <th>Comm. Meet</th>
                                                <th>Board Meet</th>
                                                <th>Details</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $sub = 0; foreach ($dateRows as $row): $sub += floatval($row['amount'] ?? 0); ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($row['client'] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($row['branch_code'] . ' - ' . $row['branch_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['division'] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($row['facility_type'] ?? ''); ?></td>
                                                    <td class="text-end"><?php echo number_format($row['amount'] ?? 0, 2); ?></td>
                                                    <td><?php echo htmlspecialchars($row['comm_meet_no'] ?? 'N/A'); ?><?php echo !empty($row['comm_meet_date']) ? '<br><small class="text-muted">' . date('d-m-Y', strtotime($row['comm_meet_date'])) . '</small>' : ''; ?></td>
                                                    <td><?php echo htmlspecialchars($row['board_meet_no'] ?? 'N/A'); ?><?php echo !empty($row['board_meet_date']) ? '<br><small class="text-muted">' . date('d-m-Y', strtotime($row['board_meet_date'])) . '</small>' : ''; ?></td>
                                                    <td><a href="more_details.php?id=<?php echo intval($row['file_record_id']); ?>" class="btn btn-sm btn-outline-primary">Details</a></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr class="table-secondary">
                                                <td class="text-end fw-bold" colspan="4">Sub-Total:</td>
                                                <td class="text-end fw-bold text-primary"><?php echo number_format($sub, 2); ?></td>
                                                <td colspan="3"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-light border mt-3">No records for this year appear on the current page. <a href="#" class="ms-1 view-year-link" data-year="<?php echo htmlspecialchars($y); ?>">Load this year (page 1)</a></div>
                        <?php endif; ?>

                        <div class="row row-cols-1 row-cols-md-4 g-2 mt-3 p-3 bg-light rounded-bottom">
                            <?php foreach ($g['type_totals'] as $t => $amt): ?>
                                <div class="col">
                                    <div class="p-3 bg-white border rounded">
                                        <div class="text-muted small"><?php echo htmlspecialchars($t); ?></div>
                                        <div class="fs-6 fw-bold"><?php echo number_format($amt, 2); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <script type="application/json" class="year-meta" data-year="<?php echo htmlspecialchars($y); ?>">{ "current_rows": <?php echo count($g['rows']); ?> }</script>
                <?php $pIdx++; endforeach; ?>
            <?php else: ?>
                <div class="alert alert-light border text-center mt-3">No sanction records found for the selected filter.</div>
            <?php endif; ?>
        </div> </div> </div> <script>
    function activateYear(year) {
        var target = document.querySelector('.year-tabs [data-year="' + year + '"]');
        var panel = document.querySelector('.year-panel[data-year-panel="' + year + '"]');
        if (!target || !panel) {
            var firstTab = document.querySelector('.year-tabs [data-year]');
            if (!firstTab) return;
            year = firstTab.dataset.year;
            target = firstTab;
            panel = document.querySelector('.year-panel[data-year-panel="' + year + '"]');
        }
        document.querySelectorAll('.year-tabs [data-year]').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.year === year);
        });
        document.querySelectorAll('.year-panel').forEach(function(panelItem) {
            panelItem.classList.toggle('active', panelItem.dataset.yearPanel === year);
        });
        if (history.replaceState) {
            history.replaceState(null, '', '#' + encodeURIComponent(year));
        } else {
            window.location.hash = encodeURIComponent(year);
        }
    }

    document.querySelectorAll('.year-tabs [data-year]').forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var year = this.dataset.year;
            var params = new URLSearchParams(window.location.search);
            params.set('year', year);
            params.set('page', '1');
            var url = window.location.pathname + '?' + params.toString();
            window.location.assign(url);
        });
    });

    document.querySelectorAll('.view-year-link').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var year = this.dataset.year;
            var params = new URLSearchParams(window.location.search);
            params.set('year', year);
            params.set('page', '1');
            window.location.search = params.toString();
        });
    });

    window.addEventListener('DOMContentLoaded', function() {
        var hashYear = window.location.hash ? decodeURIComponent(window.location.hash.substring(1)) : '';
        if (hashYear) {
            activateYear(hashYear);
        }
    });
</script>

<?php include 'footer.php'; ?>
</body>
</html>