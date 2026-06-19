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

// Extract date filters from URL parameters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';

// Create SQL conditions for dates
$proposal_date_condition = "";
$facility_date_condition = "";

if (!empty($from_date) && !empty($to_date)) {
    $safe_from = $conn->real_escape_string($from_date);
    $safe_to = $conn->real_escape_string($to_date);
    $proposal_date_condition = " AND pa.assigned_date BETWEEN '{$safe_from} 00:00:00' AND '{$safe_to} 23:59:59' ";
    $facility_date_condition = " AND ff.sanction_date BETWEEN '{$safe_from}' AND '{$safe_to}' ";
}

/**
 * 1. PROPOSAL METRICS SUMMARY (Date Filtered)
 */
$q_proposals = "
    SELECT 
        COUNT(DISTINCT pa.proposal_ref) AS total_proposals,
        SUM(pa.proposal_amount) AS total_proposal_amt
    FROM proposal_assignments pa
    JOIN office_files o ON pa.file_id = o.id
    WHERE o.is_deleted = 0 {$proposal_date_condition}
";
$res_prop = $conn->query($q_proposals);
$prop_metrics = $res_prop ? $res_prop->fetch_assoc() : ['total_proposals' => 0, 'total_proposal_amt' => 0];

/**
 * 2. SANCTION METRICS SUMMARY (Date Filtered)
 */
$q_sanctions = "
    SELECT 
        COUNT(DISTINCT ff.sanction_letter_ref_no) AS total_sanctions,
        SUM(ff.amount) AS total_sanction_amt
    FROM file_facilities ff
    JOIN office_files o ON ff.file_record_id = o.id
    WHERE o.is_deleted = 0 {$facility_date_condition}
";
$res_sanc = $conn->query($q_sanctions);
$sanc_metrics = $res_sanc ? $res_sanc->fetch_assoc() : ['total_sanctions' => 0, 'total_sanction_amt' => 0];

/**
 * 3. COMMITTEE MEETING SANCTIONS SUMMARY (Date Filtered)
 */
$q_committee = "
    SELECT 
        COUNT(DISTINCT ff.sanction_letter_ref_no) AS total_sanctions,
        SUM(ff.amount) AS total_amt
    FROM file_facilities ff
    JOIN office_files o ON ff.file_record_id = o.id
    WHERE o.is_deleted = 0 
      AND ff.comm_meet_no IS NOT NULL 
      AND TRIM(ff.comm_meet_no) != ''
      {$facility_date_condition}
";
$res_comm = $conn->query($q_committee);
$comm_metrics = $res_comm ? $res_comm->fetch_assoc() : ['total_sanctions' => 0, 'total_amt' => 0];

/**
 * 4. BOARD MEETING SANCTIONS SUMMARY (Date Filtered)
 */
$q_board = "
    SELECT 
        COUNT(DISTINCT ff.sanction_letter_ref_no) AS total_sanctions,
        SUM(ff.amount) AS total_amt
    FROM file_facilities ff
    JOIN office_files o ON ff.file_record_id = o.id
    WHERE o.is_deleted = 0 
      AND ff.board_meet_no IS NOT NULL 
      AND TRIM(ff.board_meet_no) != ''
      {$facility_date_condition}
";
$res_board = $conn->query($q_board);
$board_metrics = $res_board ? $res_board->fetch_assoc() : ['total_sanctions' => 0, 'total_amt' => 0];

/**
 * 4.5. MANAGING DIRECTOR (MD) POWER SANCTION SUMMARY (Date Filtered)
 */
$q_md_power = "
    SELECT 
        COUNT(DISTINCT ff.sanction_letter_ref_no) AS total_sanctions,
        SUM(ff.amount) AS total_amt
    FROM file_facilities ff
    JOIN office_files o ON ff.file_record_id = o.id
    WHERE o.is_deleted = 0 
      AND ff.power_delegation = 'MD'
      AND ff.power_delegation IS NOT NULL
      {$facility_date_condition}
";
$res_md = $conn->query($q_md_power);
$md_metrics = $res_md ? $res_md->fetch_assoc() : ['total_sanctions' => 0, 'total_amt' => 0];

/**
 * 4.6. BOARD POWER SANCTION SUMMARY (Date Filtered)
 */
$q_board_power = "
    SELECT 
        COUNT(DISTINCT ff.sanction_letter_ref_no) AS total_sanctions,
        SUM(ff.amount) AS total_amt
    FROM file_facilities ff
    JOIN office_files o ON ff.file_record_id = o.id
    WHERE o.is_deleted = 0 
      AND ff.power_delegation = 'Board'
      AND ff.power_delegation IS NOT NULL
      {$facility_date_condition}
";
$res_board_power = $conn->query($q_board_power);
$board_power_metrics = $res_board_power ? $res_board_power->fetch_assoc() : ['total_sanctions' => 0, 'total_amt' => 0];

$summary_scope_label = (!empty($from_date) && !empty($to_date))
    ? ('From Date ' . date('d-M-Y', strtotime($from_date)) . ' to Date ' . date('d-M-Y', strtotime($to_date)))
    : 'All Available Dates';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proposal & Sanction Summary Report</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        :root {
            --panel-bg: #ffffff;
            --panel-border: #e6ebf2;
            --accent: #0d6efd;
            --accent-soft: #eef4ff;
            --success-soft: #eefaf2;
            --warning-soft: #fff7e6;
            --dark-soft: #f4f6f8;
        }

        body { background: #f4f6f9; }
        .report-card { border: none; border-radius: 12px; }
        .metric-value { font-size: 1.75rem; font-weight: 700; color: #212529; }
        .metric-label { font-size: 0.75rem; text-transform: uppercase; font-weight: 600; color: #6c757d; letter-spacing: 0.5px; }
        .border-purple { border-color: #6f42c1 !important; }
        .bg-purple { background-color: #6f42c1 !important; color: white; }
        .text-purple { color: #6f42c1 !important; }
        .summary-hero {
            background: linear-gradient(135deg, #ffffff 0%, #f8fbff 100%);
            border: 1px solid var(--panel-border);
            border-radius: 18px;
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.05);
        }
        .summary-stat {
            border: 1px solid var(--panel-border);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .summary-stat:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(15, 23, 42, 0.07); }
        .summary-chip {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            border-radius: 999px;
            padding: .35rem .75rem;
            background: var(--accent-soft);
            color: #0b4db3;
            font-weight: 700;
            font-size: .78rem;
        }
        .summary-table thead th {
            background: #0f172a;
            color: #fff;
            border-color: #0f172a;
            font-size: .76rem;
            letter-spacing: .4px;
        }
        .section-panel {
            border: 1px solid var(--panel-border);
            border-radius: 16px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
        }
        .section-panel .card-header {
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            border-bottom: 1px solid var(--panel-border);
        }
        .clickable-stat, .clickable-facility, .clickable-facility-as {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .clickable-stat:hover, .clickable-facility:hover, .clickable-facility-as:hover {
            background-color: #f8f9fa;
            transform: translateY(-2px);
        }
        
        @media print {
            .no-print, .btn, form, header, footer, nav, .btn-primary, .btn-outline-dark { 
                display: none !important; 
            }
            body, html { 
                background: #ffffff !important; 
                color: #000000 !important; 
                font-size: 11pt !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .container { 
                max-width: 100% !important; 
                width: 100% !important; 
                padding: 0 !important; 
                margin: 0 !important; 
            }
            .card { 
                border: 1px solid #000000 !important; 
                box-shadow: none !important; 
                background: transparent !important;
                border-radius: 4px !important;
                margin-bottom: 15px !important;
                page-break-inside: avoid !important;
            }
            .card-header {
                background: #f0f0f0 !important;
                color: #000000 !important;
                border-bottom: 1px solid #000000 !important;
                font-weight: bold !important;
            }
            .row {
                display: flex !important;
                flex-flow: row wrap !important;
            }
            .col-md-3 { width: 25% !important; float: left !important; }
            .col-md-6 { width: 50% !important; float: left !important; }
            .report-card {
                border: 1px solid #000000 !important;
                border-left: 6px solid #000000 !important;
                background: #ffffff !important;
            }
            .table { 
                width: 100% !important;
                border-collapse: collapse !important; 
                color: #000000 !important;
            }
            .table th {
                background-color: #f2f2f2 !important;
                color: #000000 !important;
                border: 1px solid #000000 !important;
                font-weight: bold !important;
            }
            .table td {
                border: 1px solid #e0e0e0 !important;
                background: transparent !important;
            }
        }
    </style>
</head>
<body class="bg-light">
<div class="container my-5">
    <!-- Top Header Control Panel -->
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <div>
            <h3 class="fw-bold text-dark m-0"><i class="fas fa-file-invoice-dollar text-primary me-2"></i>Sanctions &amp; Proposal Summary Report</h3>
            <p class="text-muted small m-0">Professional summary scoped by proposal reference and sanction reference numbers</p>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-dark shadow-sm"><i class="fas fa-print me-1"></i> Print Report</button>
            <a href="index.php" class="btn btn-primary shadow-sm"><i class="fas fa-tachometer-alt me-1"></i> Dashboard</a>
            <a href="details_sanction_report.php" class="btn btn-info toolbar-btn text-white"><i class="fas fa-chart-line"></i> <span>Details Report</span></a>
        </div>
    </div>
    
    <!-- DATE RANGE FILTER TOOLBAR CONTROL BLOCK -->
    <div class="card shadow-sm border-0 mb-4 bg-white no-print">
        <div class="card-body p-3">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-secondary"><i class="far fa-calendar-alt me-1 text-primary"></i>From Date (Date A)</label>
                    <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-secondary"><i class="far fa-calendar-alt me-1 text-success"></i>To Date (Date B)</label>
                    <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-dark flex-grow-1 shadow-sm">
                        <i class="fas fa-filter me-1"></i> Filter Date Wise
                    </button>
                    <?php if (!empty($from_date) || !empty($to_date)): ?>
                        <a href="sanction_report.php" class="btn btn-outline-secondary" title="Clear Filters">
                            <i class="fas fa-undo"></i> Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>
            <!-- Active Filters Indicator -->
<?php if (!empty($from_date) && !empty($to_date)): ?>
    <div class="alert alert-info border-0 shadow-sm mt-3 mb-0">
        <i class="fas fa-filter me-2"></i>
        <strong>Active Filter:</strong> 
        From <strong><?php echo date('d-M-Y', strtotime($from_date)); ?></strong> to <strong><?php echo date('d-M-Y', strtotime($to_date)); ?></strong>
        <a href="sanction_report.php" class="float-end text-decoration-none">× Clear</a>
    </div>
<?php endif; ?>
        </div>
    </div>
    
    <!-- VISUAL INDICATOR FOR ACTIVE DATE RANGES -->
    <?php if (!empty($from_date) && !empty($to_date)): ?>
        <div class="summary-hero p-3 mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="summary-chip mb-2"><i class="fas fa-calendar-alt"></i> Date Filter Active</div>
                <div class="fw-semibold text-dark">
                    Displaying records from <strong><?= date('d-M-Y', strtotime($from_date)) ?></strong> to <strong><?= date('d-M-Y', strtotime($to_date)) ?></strong>
                </div>
            </div>
            <div class="text-muted small font-monospace"><?= htmlspecialchars($summary_scope_label) ?></div>
        </div>
    <?php else: ?>
        <div class="d-none d-print-block alert alert-light border mb-4">
            <strong>Sanctions &amp; Facilities Ledger Report:</strong> Comprehensive Master Listing (All-Time Historical Overview)
        </div>
    <?php endif; ?>
    
    <!-- MAIN CORE METRICS MATRIX GRID -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="summary-stat p-3 h-100 border-start border-primary border-4">
                <div class="metric-label">No. of Proposal</div>
                <div class="metric-value font-monospace"><?= number_format($prop_metrics['total_proposals']) ?></div>
                <div class="small text-muted">Received From Branch</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="summary-stat p-3 h-100 border-start border-info border-4">
                <div class="metric-label">Amount of Proposal</div>
                <div class="metric-value font-monospace text-truncate" style="font-size:1.35rem;">BDT <?= number_format($prop_metrics['total_proposal_amt'] ?? 0, 2) ?></div>
                <div class="small text-muted">Total Proposal Amount</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="summary-stat p-3 h-100 border-start border-success border-4">
                <div class="metric-label">No. of Sanction</div>
                <div class="metric-value font-monospace text-success"><?= number_format($sanc_metrics['total_sanctions']) ?></div>
                <div class="small text-muted">By Head Office</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="summary-stat p-3 h-100 border-start border-warning border-4">
                <div class="metric-label">Amount of Sanction</div>
                <div class="metric-value font-monospace text-dark text-truncate" style="font-size:1.35rem;">BDT <?= number_format($sanc_metrics['total_sanction_amt'] ?? 0, 2) ?></div>
                <div class="small text-muted">Total Sanctioned Amount</div>
            </div>
        </div>
    </div>
    
    <!-- SUMMARY MATRIX - CLICKABLE CARDS -->
<div class="row g-3 mb-4">
    <!-- Committee Meeting Recommendation -->
    <div class="col-md-4">
        <div class="section-panel h-100 clickable-stat" data-filter-type="committee" style="cursor: pointer;">
            <div class="card-body p-4 border-top border-4 border-purple rounded-top">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold text-dark m-0"><i class="fas fa-users text-purple me-2"></i>Committee Meeting</h6>
                    <span class="badge bg-light text-dark border font-monospace"><?= number_format($comm_metrics['total_sanctions']) ?> Sanction Refs</span>
                </div>
                <hr class="my-2">
                <div class="d-flex align-items-baseline justify-content-between">
                    <span class="text-secondary small fw-bold font-monospace">AGGREGATE AMOUNT:</span>
                    <h4 class="fw-bold text-dark font-monospace mb-0">BDT <?= number_format($comm_metrics['total_amt'] ?? 0, 2) ?></h4>
                </div>
                <div class="small text-muted mt-2">
                    <i class="fas fa-info-circle me-1"></i> Click to view details
                </div>
            </div>
        </div>
    </div>
    
    <!-- Managing Director Power Sanction -->
    <div class="col-md-4">
        <div class="section-panel h-100 clickable-stat" data-filter-type="md_power" style="cursor: pointer;">
            <div class="card-body p-4 border-top border-4 border-success rounded-top">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold text-dark m-0"><i class="fas fa-user-tie text-success me-2"></i>MD Power Sanction</h6>
                    <span class="badge bg-light text-dark border font-monospace"><?= number_format($md_metrics['total_sanctions']) ?> Sanction Refs</span>
                </div>
                <hr class="my-2">
                <div class="d-flex align-items-baseline justify-content-between">
                    <span class="text-secondary small fw-bold font-monospace">AGGREGATE AMOUNT:</span>
                    <h4 class="fw-bold text-dark font-monospace mb-0">BDT <?= number_format($md_metrics['total_amt'] ?? 0, 2) ?></h4>
                </div>
                <div class="small text-muted mt-2">
                    <i class="fas fa-info-circle me-1"></i> Click to view details
                </div>
            </div>
        </div>
    </div>
    
    <!-- Board Power Sanction -->
    <div class="col-md-4">
        <div class="section-panel h-100 clickable-stat" data-filter-type="board_power" style="cursor: pointer;">
            <div class="card-body p-4 border-top border-4 border-dark rounded-top">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold text-dark m-0"><i class="fas fa-gavel text-dark me-2"></i>Board Power Sanction</h6>
                    <span class="badge bg-light text-dark border font-monospace"><?= number_format($board_power_metrics['total_sanctions']) ?> Sanction Refs</span>
                </div>
                <hr class="my-2">
                <div class="d-flex align-items-baseline justify-content-between">
                    <span class="text-secondary small fw-bold font-monospace">AGGREGATE AMOUNT:</span>
                    <h4 class="fw-bold text-dark font-monospace mb-0">BDT <?= number_format($board_power_metrics['total_amt'] ?? 0, 2) ?></h4>
                </div>
                <div class="small text-muted mt-2">
                    <i class="fas fa-info-circle me-1"></i> Click to view details
                </div>
            </div>
        </div>
    </div>
</div>
    
    <!-- FUNDING BREAKDOWN TABLES (Combined with Facility As) -->
    <div class="card shadow-sm border-0 bg-white mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-layer-group text-info me-2"></i>Funding Summary by Facility Type &amp; Facility As</h5>
            <span class="summary-chip"><i class="fas fa-filter"></i> <?= htmlspecialchars($summary_scope_label) ?></span>
        </div>
        <div class="card-body p-3 p-md-4">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="section-panel h-100">
                        <div class="card-header py-3 bg-light">
                            <div class="fw-bold text-success"><i class="fas fa-check-circle me-2"></i>Funded</div>
                            <div class="small text-muted">Sanction amount grouped by facility type and facility as category - Click any row to view details</div>
                        </div>
                        <div class="table-responsive">
                            <table class="table summary-table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-3">Facility Type</th>
                                        <th class="ps-2">Facility As</th>
                                        <th class="text-center">No. of Sanction Refs</th>
                                        <th class="text-end pe-3">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // Query for Funded
                                    $q_combined_funded = "
                                        SELECT 
                                            COALESCE(NULLIF(TRIM(ff.facility_type), ''), 'Unspecified') AS facility_type,
                                            COALESCE(NULLIF(TRIM(ff.facility_as), ''), 'Unspecified') AS facility_as,
                                            COUNT(DISTINCT ff.sanction_letter_ref_no) AS sanction_ref_count,
                                            SUM(ff.amount) AS total_facility_amt
                                        FROM file_facilities ff
                                        JOIN office_files o ON ff.file_record_id = o.id
                                        WHERE o.is_deleted = 0 
                                          AND LOWER(TRIM(COALESCE(ff.facility_group, ''))) = 'funded'
                                          {$facility_date_condition}
                                        GROUP BY facility_type, facility_as
                                        ORDER BY total_facility_amt DESC
                                    ";
                                    $combined_funded_res = $conn->query($q_combined_funded);
                                    
                                    $funded_total_amount = 0;
                                    $funded_total_count = 0;
                                    if ($combined_funded_res && $combined_funded_res->num_rows > 0): 
                                        while ($ft = $combined_funded_res->fetch_assoc()): 
                                            $funded_total_amount += $ft['total_facility_amt'];
                                            $funded_total_count += $ft['sanction_ref_count'];
                                    ?>
                                        <tr class="clickable-facility" data-funding-group="funded" data-facility-type="<?= htmlspecialchars($ft['facility_type']) ?>" style="cursor: pointer;">
                                            <td class="ps-3 fw-semibold text-secondary"><?= htmlspecialchars($ft['facility_type']) ?> <i class="fas fa-external-link-alt text-muted ms-1" style="font-size: 10px;"></i></td>
                                            <td class="ps-2"><span class="badge bg-light text-dark border"><?= htmlspecialchars($ft['facility_as']) ?></span></td>
                                            <td class="text-center font-monospace"><?= number_format($ft['sanction_ref_count']) ?></td>
                                            <td class="text-end pe-3 fw-bold font-monospace text-success">BDT <?= number_format($ft['total_facility_amt'], 2) ?></td>
                                        </tr>
                                    <?php 
                                        endwhile; 
                                    else:
                                    ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">No funded records found for the selected range.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <?php if ($funded_total_count > 0): ?>
                                <tfoot class="table-secondary">
                                    <tr>
                                        <td class="ps-3 fw-bold text-dark" colspan="2">TOTAL (Funded)</td>
                                        <td class="text-center fw-bold text-dark font-monospace"><?= number_format($funded_total_count) ?></td>
                                        <td class="text-end pe-3 fw-bold text-dark font-monospace">BDT <?= number_format($funded_total_amount, 2) ?></td>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="section-panel h-100">
                        <div class="card-header py-3 bg-light">
                            <div class="fw-bold text-dark"><i class="fas fa-ban me-2"></i>Non funded</div>
                            <div class="small text-muted">Sanction amount grouped by facility type and facility as category - Click any row to view details</div>
                        </div>
                        <div class="table-responsive">
                            <table class="table summary-table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-3">Facility Type</th>
                                        <th class="ps-2">Facility As</th>
                                        <th class="text-center">No. of Sanction Refs</th>
                                        <th class="text-end pe-3">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // Query for Non-funded
                                    $q_combined_non_funded = "
                                        SELECT 
                                            COALESCE(NULLIF(TRIM(ff.facility_type), ''), 'Unspecified') AS facility_type,
                                            COALESCE(NULLIF(TRIM(ff.facility_as), ''), 'Unspecified') AS facility_as,
                                            COUNT(DISTINCT ff.sanction_letter_ref_no) AS sanction_ref_count,
                                            SUM(ff.amount) AS total_facility_amt
                                        FROM file_facilities ff
                                        JOIN office_files o ON ff.file_record_id = o.id
                                        WHERE o.is_deleted = 0 
                                          AND (LOWER(TRIM(COALESCE(ff.facility_group, ''))) != 'funded' 
                                               OR ff.facility_group IS NULL 
                                               OR ff.facility_group = '')
                                          {$facility_date_condition}
                                        GROUP BY facility_type, facility_as
                                        ORDER BY total_facility_amt DESC
                                    ";
                                    $combined_non_funded_res = $conn->query($q_combined_non_funded);
                                    
                                    $non_funded_total_amount = 0;
                                    $non_funded_total_count = 0;
                                    if ($combined_non_funded_res && $combined_non_funded_res->num_rows > 0): 
                                        while ($ft = $combined_non_funded_res->fetch_assoc()): 
                                            $non_funded_total_amount += $ft['total_facility_amt'];
                                            $non_funded_total_count += $ft['sanction_ref_count'];
                                    ?>
                                        <tr class="clickable-facility" data-funding-group="non_funded" data-facility-type="<?= htmlspecialchars($ft['facility_type']) ?>" style="cursor: pointer;">
                                            <td class="ps-3 fw-semibold text-secondary"><?= htmlspecialchars($ft['facility_type']) ?> <i class="fas fa-external-link-alt text-muted ms-1" style="font-size: 10px;"></i></td>
                                            <td class="ps-2"><span class="badge bg-light text-dark border"><?= htmlspecialchars($ft['facility_as']) ?></span></td>
                                            <td class="text-center font-monospace"><?= number_format($ft['sanction_ref_count']) ?></td>
                                            <td class="text-end pe-3 fw-bold font-monospace text-primary">BDT <?= number_format($ft['total_facility_amt'], 2) ?></td>
                                        </tr>
                                    <?php 
                                        endwhile; 
                                    else:
                                    ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">No non-funded records found for the selected range.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <?php if ($non_funded_total_count > 0): ?>
                                <tfoot class="table-secondary">
                                    <tr>
                                        <td class="ps-3 fw-bold text-dark" colspan="2">TOTAL (Non funded)</td>
                                        <td class="text-center fw-bold text-dark font-monospace"><?= number_format($non_funded_total_count) ?></td>
                                        <td class="text-end pe-3 fw-bold text-dark font-monospace">BDT <?= number_format($non_funded_total_amount, 2) ?></td>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Make summary cards clickable
document.querySelectorAll('.clickable-stat').forEach(element => {
    element.addEventListener('click', function() {
        const filterType = this.dataset.filterType;
        const fromDate = '<?php echo $from_date; ?>';
        const toDate = '<?php echo $to_date; ?>';
        window.location.href = `sanction_report_details.php?filter_type=${filterType}&from_date=${fromDate}&to_date=${toDate}`;
    });
});

// Make facility type rows clickable
document.querySelectorAll('.clickable-facility').forEach(element => {
    element.addEventListener('click', function(e) {
        // Prevent click if clicking on the external link icon specifically
        if (e.target.classList && e.target.classList.contains('fa-external-link-alt')) {
            return;
        }
        const fundingGroup = this.dataset.fundingGroup;
        const facilityType = this.dataset.facilityType;
        const fromDate = '<?php echo $from_date; ?>';
        const toDate = '<?php echo $to_date; ?>';
        window.location.href = `sanction_report_details.php?filter_type=${fundingGroup}_type&filter_value=${encodeURIComponent(facilityType)}&from_date=${fromDate}&to_date=${toDate}`;
    });
});
</script>

<?php include 'footer.php'; ?>
</body>
</html>