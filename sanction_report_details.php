<?php
session_start();
include 'db.php';
include 'header.php';

if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';
$filter_type = isset($_GET['filter_type']) ? $_GET['filter_type'] : '';
$filter_value = isset($_GET['filter_value']) ? $_GET['filter_value'] : '';
$funding_group = isset($_GET['funding_group']) ? $_GET['funding_group'] : '';

$facility_date_condition = "";
if (!empty($from_date) && !empty($to_date)) {
    $safe_from = $conn->real_escape_string($from_date);
    $safe_to = $conn->real_escape_string($to_date);
    $facility_date_condition = " AND ff.sanction_date BETWEEN '{$safe_from}' AND '{$safe_to}' ";
}

// Build the details query based on filter type
$details_query = "
    SELECT 
        o.client AS client_name,
        o.file_no,
        o.branch_name,
        o.division,
        ff.facility_type,
        ff.facility_as,
        ff.amount AS facility_amount,
        ff.sanction_date,
        ff.sanction_letter_ref_no,
        ff.comm_meet_no,
        ff.board_meet_no,
        ff.power_delegation
    FROM file_facilities ff
    JOIN office_files o ON ff.file_record_id = o.id
    WHERE o.is_deleted = 0 {$facility_date_condition}
";

if ($filter_type == 'md_power') {
    $details_query .= " AND ff.power_delegation = 'MD'";
    $title = "MD Power Sanction Details";
    $badge_color = "success";
} elseif ($filter_type == 'board_power') {
    $details_query .= " AND ff.power_delegation = 'Board'";
    $title = "Board Power Sanction Details";
    $badge_color = "dark";
} elseif ($filter_type == 'committee') {
    $details_query .= " AND ff.comm_meet_no IS NOT NULL AND TRIM(ff.comm_meet_no) != ''";
    $title = "Committee Meeting Sanction Details";
    $badge_color = "purple";
} elseif ($filter_type == 'funded_type' && !empty($filter_value)) {
    $details_query .= " AND LOWER(TRIM(COALESCE(ff.facility_group, ''))) = 'funded' AND ff.facility_type = '" . $conn->real_escape_string($filter_value) . "'";
    $title = "Funded Facility Type Details: " . htmlspecialchars($filter_value);
    $badge_color = "success";
} elseif ($filter_type == 'non_funded_type' && !empty($filter_value)) {
    $details_query .= " AND (LOWER(TRIM(COALESCE(ff.facility_group, ''))) != 'funded' OR ff.facility_group IS NULL OR ff.facility_group = '') AND ff.facility_type = '" . $conn->real_escape_string($filter_value) . "'";
    $title = "Non-funded Facility Type Details: " . htmlspecialchars($filter_value);
    $badge_color = "secondary";
} elseif ($filter_type == 'funded_as' && !empty($filter_value)) {
    $details_query .= " AND LOWER(TRIM(COALESCE(ff.facility_group, ''))) = 'funded' AND ff.facility_as = '" . $conn->real_escape_string($filter_value) . "'";
    $title = "Funded Facility As Details: " . htmlspecialchars($filter_value);
    $badge_color = "success";
} elseif ($filter_type == 'non_funded_as' && !empty($filter_value)) {
    $details_query .= " AND (LOWER(TRIM(COALESCE(ff.facility_group, ''))) != 'funded' OR ff.facility_group IS NULL OR ff.facility_group = '') AND ff.facility_as = '" . $conn->real_escape_string($filter_value) . "'";
    $title = "Non-funded Facility As Details: " . htmlspecialchars($filter_value);
    $badge_color = "secondary";
}

$details_query .= " ORDER BY ff.sanction_date DESC";
$details_result = $conn->query($details_query);

$total_amount = 0;
$total_count = 0;
$records = [];
if ($details_result && $details_result->num_rows > 0) {
    while ($row = $details_result->fetch_assoc()) {
        $total_amount += floatval($row['facility_amount']);
        $total_count++;
        $records[] = $row;
    }
}

// Build the back URL with preserved filters
$back_url = "sanction_report.php";
$query_params = [];
if (!empty($from_date)) $query_params['from_date'] = $from_date;
if (!empty($to_date)) $query_params['to_date'] = $to_date;
if (!empty($query_params)) {
    $back_url .= "?" . http_build_query($query_params);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background: #f4f6f9; }
        .card { border-radius: 12px; border: none; }
        .summary-stat { border: 1px solid #e6ebf2; border-radius: 16px; background: #fff; }
        .badge-purple { background-color: #6f42c1; color: white; }
    </style>
</head>
<body class="bg-light">
<div class="container my-5">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark m-0"><i class="fas fa-chart-line text-primary me-2"></i><?php echo $title; ?></h3>
            <p class="text-muted small m-0">Detailed breakdown of sanction records</p>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-dark shadow-sm"><i class="fas fa-print me-1"></i> Print</button>
            <a href="<?php echo $back_url; ?>" class="btn btn-primary shadow-sm"><i class="fas fa-arrow-left me-1"></i> Back to Report</a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="summary-stat p-3 h-100 border-start border-<?php echo $badge_color; ?> border-4">
                <div class="metric-label text-secondary small text-uppercase fw-bold">Total Sanction Refs</div>
                <div class="metric-value font-monospace fs-2 fw-bold"><?php echo number_format($total_count); ?></div>
                <div class="small text-muted">Records found for this filter</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="summary-stat p-3 h-100 border-start border-<?php echo $badge_color; ?> border-4">
                <div class="metric-label text-secondary small text-uppercase fw-bold">Total Amount</div>
                <div class="metric-value font-monospace fs-2 fw-bold text-<?php echo $badge_color; ?>">BDT <?php echo number_format($total_amount, 2); ?></div>
                <div class="small text-muted">Aggregated sanction amount</div>
            </div>
        </div>
    </div>

    <!-- Date Range Info -->
    <?php if (!empty($from_date) && !empty($to_date)): ?>
        <div class="alert alert-info border-0 shadow-sm mb-4">
            <i class="fas fa-calendar-alt me-2"></i>
            Displaying records from <strong><?php echo date('d-M-Y', strtotime($from_date)); ?></strong> to <strong><?php echo date('d-M-Y', strtotime($to_date)); ?></strong>
        </div>
    <?php endif; ?>

    <!-- Detailed Records Table -->
    <div class="card shadow-sm border-0 bg-white">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-list-alt text-primary me-2"></i>Sanction Records</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle mb-0">
                <thead class="table-dark small text-uppercase" style="font-size:0.75rem;">
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Client Name</th>
                        <th>File No</th>
                        <th>Branch</th>
                        <th>Division</th>
                        <th>Facility Type</th>
                        <th>Facility As</th>
                        <th>Approval By</th>
                        <th>Sanction Ref</th>
                        <th>Sanction Date</th>
                        <th class="text-end pe-4">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($records)): ?>
                        <?php $sl = 1; foreach ($records as $record): ?>
                            <tr>
                                <td class="ps-4 fw-bold text-muted"><?php echo $sl++; ?></td>
                                <td class="fw-semibold">
                                    <a href="more_details.php?id=<?php echo $record['file_no']; ?>" class="text-decoration-none">
                                        <?php echo htmlspecialchars($record['client_name']); ?>
                                    </a>
                                </td>
                                <td><span class="badge bg-light text-dark font-monospace"><?php echo htmlspecialchars($record['file_no']); ?></span></td>
                                <td><?php echo htmlspecialchars($record['branch_name']); ?></td>
                                <td><?php echo htmlspecialchars($record['division']); ?></td>
                                <td><span class="badge bg-info-subtle text-info"><?php echo htmlspecialchars($record['facility_type']); ?></span></td>
                                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($record['facility_as']); ?></span></td>
                                <td>
                                    <?php if ($record['power_delegation'] == 'MD'): ?>
                                        <span class="badge bg-success">MD</span>
                                    <?php elseif ($record['power_delegation'] == 'Board'): ?>
                                        <span class="badge bg-dark">Board</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="font-monospace small"><?php echo htmlspecialchars($record['sanction_letter_ref_no']); ?></td>
                                <td class="font-monospace small"><?php echo date('d-M-Y', strtotime($record['sanction_date'])); ?></td>
                                <td class="text-end pe-4 fw-bold text-success font-monospace">BDT <?php echo number_format($record['facility_amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-secondary fw-bold">
                            <td colspan="10" class="text-end pe-3">GRAND TOTAL:</td>
                            <td class="text-end pe-4 font-monospace">BDT <?php echo number_format($total_amount, 2); ?></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted py-5">
                                <i class="fas fa-folder-open fa-3x mb-3 d-block text-muted opacity-50"></i>
                                No records found for the selected filter.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
             </table>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>