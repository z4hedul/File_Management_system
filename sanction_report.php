<?php
session_start();
include 'db.php';

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

$countQuery = "SELECT COUNT(*) AS total
               FROM file_facilities ff
               JOIN office_files o ON ff.file_record_id = o.id
               WHERE o.is_deleted = 0
                 AND (? = '' OR ff.sanction_date >= ?)
                 AND (? = '' OR ff.sanction_date <= ?)
                 AND (? = '' OR ff.facility_type LIKE CONCAT('%', ?, '%'))";

// add year filter conditionally
$yearCondition = '';
$yearParamValue = null;
if ($selected_year !== '') {
    if (strtolower($selected_year) === 'unknown') {
        $yearCondition = " AND (ff.sanction_date IS NULL OR ff.sanction_date = '' OR ff.sanction_date = '0000-00-00')";
    } elseif (is_numeric($selected_year)) {
        $yearCondition = " AND YEAR(ff.sanction_date) = ?";
        $yearParamValue = intval($selected_year);
    }
}

$countQuery .= $yearCondition;

$countStmt = $conn->prepare($countQuery);

// dynamic binding
$count_types = 'ssssss';
$count_values = [$from_date, $from_date, $to_date, $to_date, $facility_filter, $facility_filter];
if ($yearParamValue !== null) { $count_types .= 'i'; $count_values[] = $yearParamValue; }

if ($count_values) {
    $refs = [];
    foreach ($count_values as $k => $v) $refs[$k] = &$count_values[$k];
    array_unshift($refs, $count_types);
    call_user_func_array([$countStmt, 'bind_param'], $refs);
}

$countStmt->execute();
$countResult = $countStmt->get_result()->fetch_assoc();
$total_count = intval($countResult['total'] ?? 0);

// Build overall totals across all years for the active filters (this is what appears in the top summary cards)
$globalTotals = [];
$global_total_all = 0;
$global_type_totals = [];
$globalQuery = "SELECT COALESCE(NULLIF(UPPER(TRIM(ff.facility_type)), ''), 'UNKNOWN') AS facility_type, SUM(ff.amount) AS total_amt
                FROM file_facilities ff
                JOIN office_files o ON ff.file_record_id = o.id
                WHERE o.is_deleted = 0
                  AND (? = '' OR ff.sanction_date >= ?)
                  AND (? = '' OR ff.sanction_date <= ?)
                  AND (? = '' OR ff.facility_type LIKE CONCAT('%', ?, '%'))
                GROUP BY facility_type";
$globalStmt = $conn->prepare($globalQuery);
$globalStmt->bind_param('ssssss', $from_date, $from_date, $to_date, $to_date, $facility_filter, $facility_filter);
$globalStmt->execute();
$globalRes = $globalStmt->get_result();
while ($row = $globalRes->fetch_assoc()) {
    $type = $row['facility_type'];
    $amt = floatval($row['total_amt'] ?? 0);
    $global_type_totals[$type] = $amt;
    $global_total_all += $amt;
}

// Pagination settings
$per_page = 25;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;
$total_pages = max(1, (int)ceil($total_count / max(1, $per_page)));

// Build a year list from the database (grouped) so tabs include every year present
$available_years = [];
 $yearsQuery = "SELECT COALESCE(YEAR(ff.sanction_date), 0) AS yr, COUNT(*) AS row_count, SUM(ff.amount) AS total
                             FROM file_facilities ff
                             JOIN office_files o ON ff.file_record_id = o.id
                             WHERE o.is_deleted = 0
                                 AND (? = '' OR ff.sanction_date >= ?)
                                 AND (? = '' OR ff.sanction_date <= ?)
                                 AND (? = '' OR ff.facility_type LIKE CONCAT('%', ?, '%'))
                             GROUP BY yr
                             ORDER BY yr DESC";
$yearsStmt = $conn->prepare($yearsQuery);
$yearsStmt->bind_param('ssssss', $from_date, $from_date, $to_date, $to_date, $facility_filter, $facility_filter);
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
// ensure order newest-first
krsort($available_years);

$query = "SELECT ff.*, o.branch_code, o.branch_name, o.division, o.client, o.file_no
                    FROM file_facilities ff
                    JOIN office_files o ON ff.file_record_id = o.id
                    WHERE o.is_deleted = 0
                        AND (? = '' OR ff.sanction_date >= ?)
                        AND (? = '' OR ff.sanction_date <= ?)
                        AND (? = '' OR ff.facility_type LIKE CONCAT('%', ?, '%'))";

$query .= $yearCondition;

$query .= "\n          ORDER BY ff.sanction_date DESC\n          LIMIT ?, ?";

$stmt = $conn->prepare($query);

// dynamic bind for main query
$main_types = 'ssssss';
$main_values = [$from_date, $from_date, $to_date, $to_date, $facility_filter, $facility_filter];
if ($yearParamValue !== null) { $main_types .= 'i'; $main_values[] = $yearParamValue; }
$main_types .= 'ii';
$main_values[] = $offset;
$main_values[] = $per_page;

$refs = [];
foreach ($main_values as $k => $v) $refs[$k] = &$main_values[$k];
array_unshift($refs, $main_types);
call_user_func_array([$stmt, 'bind_param'], $refs);

$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);

$total_all = 0;
$type_totals = [];
$year_groups = [];

foreach ($rows as $row) {
    $amount = floatval($row['amount'] ?? 0);
    $total_all += $amount;
    $type = strtoupper(trim($row['facility_type'] ?? '')) ?: 'UNKNOWN';
    $type_totals[$type] = ($type_totals[$type] ?? 0) + $amount;

    $year = !empty($row['sanction_date']) ? date('Y', strtotime($row['sanction_date'])) : 'Unknown';
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

// Show newest years first in the UI
krsort($year_groups);

// Debug output: set ?debug=1 in the URL to dump year lists and samples
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
    if (isset($allRows)) {
        echo "\nSAMPLE ALL ROWS (first 30):\n";
        print_r(array_slice($allRows, 0, 30));
    }
    echo '</pre></div></div>';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sanction Facility Report</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style/style.css">
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
<nav class="navbar navbar-expand-lg navbar-dark shadow mb-4">
    <div class="container main-container">
        <a class="navbar-brand d-flex align-items-center fw-bold" href="index.php">
            <img src="images/fsib_logo.jpg" alt="FSIB Logo" class="me-3 rounded bg-white p-1" style="height: 45px; width: auto;">
            <span>FILE MANAGEMENT SYSTEM</span>
        </a>
        <div class="ms-auto d-flex align-items-center">
            <span class="text-white me-3 small border-end pe-3">
                <i class="fas fa-user-circle me-1"></i>
                <span class="opacity-75"><?php echo strtoupper(htmlspecialchars($_SESSION['role'] ?? '')); ?>:</span>
                <strong class="text-warning"><?php echo strtoupper(htmlspecialchars($_SESSION['username'] ?? '')); ?></strong>
            </span>
            <a href="logout.php" class="btn btn-sm btn-logout shadow-sm">
                <i class="fas fa-sign-out-alt me-1"></i> LOGOUT
            </a>
        </div>
    </div>
</nav>

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
                    <input type="text" name="facility_filter" class="form-control form-control-sm" placeholder="e.g. BG, LC, PIF, HYPO" value="<?php echo htmlspecialchars($facility_filter); ?>">
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
                                if ($from_date) {
                                    $labelParts[] = 'From ' . date('d.m.Y', strtotime($from_date));
                                }
                                if ($to_date) {
                                    $labelParts[] = 'To ' . date('d.m.Y', strtotime($to_date));
                                }
                                if ($facility_filter) {
                                    $labelParts[] = 'Facility contains "' . htmlspecialchars($facility_filter) . '"';
                                }
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
                                <?php $firstRef = htmlspecialchars($dateRows[0]['sanction_letter_ref_no'] ?? 'N/A'); ?>
                                <div class="sanction-header d-flex justify-content-between align-items-center bg-light border p-3 mt-3 rounded-top shadow-sm">
                                    <div class="fw-bold text-dark">
                                        <i class="fas fa-calendar-check text-primary me-2"></i>
                                        <span class="badge bg-primary text-white me-2">Ref: <?php echo $firstRef; ?></span>
                                        <span class="text-muted">Sanction Date:</span> <?php echo htmlspecialchars($dateKey); ?>
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
                                            <th>Sanction Ref</th>
                                            <th>Record</th>
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
                                                <td><?php echo htmlspecialchars($row['sanction_letter_ref_no'] ?? 'N/A'); ?></td>
                                                <td><a href="view_details.php?id=<?php echo intval($row['file_record_id']); ?>" class="btn btn-sm btn-outline-primary">View</a></td>
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
                    <script type="application/json" class="year-meta" data-year="<?php echo htmlspecialchars($y); ?>">{
                        "current_rows": <?php echo count($g['rows']); ?>
                    }</script>
                <?php $pIdx++; endforeach; ?>
            <?php else: ?>
                <div class="alert alert-light border text-center mt-3">No sanction records found for the selected filter.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

        </div>
        </div>
        </div>

            <script>
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
                        // always navigate so the server returns the year's paginated rows on first click
                        window.location.assign(url);
                    });
                });

                // handle the 'Load this year' links inside panels
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

        </body>
        </html>