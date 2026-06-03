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
    // Sanitizing dates safely using your active database link
    $safe_from = $conn->real_escape_string($from_date);
    $safe_to = $conn->real_escape_string($to_date);
    
    // Proposals filter condition matching pa.assigned_date
    $proposal_date_condition = " AND pa.assigned_date BETWEEN '{$safe_from} 00:00:00' AND '{$safe_to} 23:59:59' ";
    
    // Facilities/Sanctions filter condition matching ff.sanction_date
    $facility_date_condition = " AND ff.sanction_date BETWEEN '{$safe_from}' AND '{$safe_to}' ";
}

/**
 * 1. PROPOSAL METRICS SUMMARY (Date Filtered)
 */
$q_proposals = "
    SELECT 
        COUNT(pa.id) AS total_proposals,
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
        COUNT(ff.id) AS total_sanctions,
        SUM(ff.amount) AS total_sanction_amt
    FROM file_facilities ff
    JOIN office_files o ON ff.file_record_id = o.id
    WHERE o.is_deleted = 0 {$facility_date_condition}
";
$res_sanc = $conn->query($q_sanctions);
$sanc_metrics = $res_sanc ? $res_sanc->fetch_assoc() : ['total_sanctions' => 0, 'total_sanction_amt' => 0];

/**
 * 3. COMMITTEE MEETING SANCTIONS SUMMARY (Date Filtered)
 * Now includes DISTINCT file count to represent unique Sanction cases
 */
$q_committee = "
    SELECT 
        COUNT(DISTINCT ff.file_record_id) AS total_sanctions,
        COUNT(ff.id) AS total_facilities,
        SUM(ff.amount) AS total_amt
    FROM file_facilities ff
    JOIN office_files o ON ff.file_record_id = o.id
    WHERE o.is_deleted = 0 
      AND ff.comm_meet_no IS NOT NULL 
      AND TRIM(ff.comm_meet_no) != ''
      {$facility_date_condition}
";
$res_comm = $conn->query($q_committee);
$comm_metrics = $res_comm ? $res_comm->fetch_assoc() : ['total_sanctions' => 0, 'total_facilities' => 0, 'total_amt' => 0];

/**
 * 4. BOARD MEETING SANCTIONS SUMMARY (Date Filtered)
 * Now includes DISTINCT file count to represent unique Sanction cases
 */
$q_board = "
    SELECT 
        COUNT(DISTINCT ff.file_record_id) AS total_sanctions,
        COUNT(ff.id) AS total_facilities,
        SUM(ff.amount) AS total_amt
    FROM file_facilities ff
    JOIN office_files o ON ff.file_record_id = o.id
    WHERE o.is_deleted = 0 
      AND ff.board_meet_no IS NOT NULL 
      AND TRIM(ff.board_meet_no) != ''
      {$facility_date_condition}
";
$res_board = $conn->query($q_board);
$board_metrics = $res_board ? $res_board->fetch_assoc() : ['total_sanctions' => 0, 'total_facilities' => 0, 'total_amt' => 0];

/**
 * 5. BREAKDOWN BY FACILITY TYPE (Date Filtered)
 */
$q_facility_types = "
    SELECT 
        ff.facility_type,
        COUNT(ff.id) AS facility_count,
        SUM(ff.amount) AS total_facility_amt
    FROM file_facilities ff
    JOIN office_files o ON ff.file_record_id = o.id
    WHERE o.is_deleted = 0 {$facility_date_condition}
    GROUP BY ff.facility_type
    ORDER BY total_facility_amt DESC
";
$facility_types_summary = $conn->query($q_facility_types);

/**
 * 6. DETAILED LEDGER LISTING ALL SANCTIONS & FACILITIES (Date Filtered)
 */
$q_detailed_ledger = "
    SELECT 
        o.client AS client_name,
        o.branch_name AS branch,
        o.zone AS zone,
        o.division AS division,
        o.file_no AS file_number,
        ff.facility_type,
        ff.amount AS facility_amount,
        ff.sanction_date,
        ff.sanction_letter_ref_no,
        ff.comm_meet_no,
        ff.board_meet_no
    FROM file_facilities ff
    JOIN office_files o ON ff.file_record_id = o.id
    WHERE o.is_deleted = 0 {$facility_date_condition}
    ORDER BY ff.sanction_date DESC, ff.id DESC
";
$ledger_list = $conn->query($q_detailed_ledger);
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
        .report-card { border: none; border-radius: 12px; }
        .metric-value { font-size: 1.75rem; font-weight: 700; color: #212529; }
        .metric-label { font-size: 0.75rem; text-transform: uppercase; font-weight: 600; color: #6c757d; letter-spacing: 0.5px; }
        .border-purple { border-color: #6f42c1 !important; }
        .bg-purple { background-color: #6f42c1 !important; color: white; }
        .text-purple { color: #6f42c1 !important; }
        
        /* STRICT BLACK AND WHITE PRINT STYLES */
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
            .table-striped tbody tr:nth-of-type(odd) td {
                background-color: #f9f9f9 !important;
            }

            .text-primary, .text-success, .text-danger, .text-purple, .metric-value, .text-secondary {
                color: #000000 !important;
            }
            .badge {
                border: 1px solid #000000 !important;
                background: transparent !important;
                color: #000000 !important;
                text-shadow: none !important;
                padding: 2px 4px !important;
            }
            .alert-info {
                border: 1px solid #000000 !important;
                background: #fdfdfd !important;
                color: #000000 !important;
            }
        }
    </style>
</head>
<body class="bg-light">
<div class="container my-5">
    
    <!-- Top Header Control Panel -->
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <div>
            <h3 class="fw-bold text-dark m-0"><i class="fas fa-file-invoice-dollar text-primary me-2"></i>Executive Sanctions &amp; Facilities Report</h3>
            <p class="text-muted small m-0">Live aggregated statistics and comprehensive facility type ledger verification.</p>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-dark shadow-sm"><i class="fas fa-print me-1"></i> Print Report</button>
            <a href="index.php" class="btn btn-primary shadow-sm"><i class="fas fa-tachometer-alt me-1"></i> Dashboard</a>
        </div>
    </div>

    <!-- DATE RANGE FILTER TOOLBAR CONTROL BLOCK -->
    <div class="card shadow-sm border-0 mb-4 bg-white no-print">
        <div class="card-body p-3">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-secondary"><i class="far fa-calendar-alt me-1 text-primary"></i>From Date (Date A)</label>
                    <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-secondary"><i class="far fa-calendar-alt me-1 text-success"></i>To Date (Date B)</label>
                    <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>" required>
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
        </div>
    </div>

    <!-- VISUAL INDICATOR FOR ACTIVE DATE RANGES WHEN PRINTING -->
    <?php if (!empty($from_date) && !empty($to_date)): ?>
        <div class="alert alert-info border-0 shadow-sm d-flex justify-content-between align-items-center mb-4">
            <span>
                <i class="fas fa-info-circle me-2"></i> Currently displaying metrics scoped from 
                <strong><?= date('d-M-Y', strtotime($from_date)) ?></strong> to 
                <strong><?= date('d-M-Y', strtotime($to_date)) ?></strong>.
            </span>
            <span class="badge bg-info text-white font-monospace no-print">Date Filter Active</span>
        </div>
    <?php else: ?>
        <div class="d-none d-print-block alert alert-light border mb-4">
            <strong>Sanctions &amp; Facilities Ledger Report:</strong> Comprehensive Master Listing (All-Time Historical Overview)
        </div>
    <?php endif; ?>

    <!-- MAIN CORE METRICS MATRIX GRID -->
    <div class="row g-3 mb-4">
        <!-- Proposals Count -->
        <div class="col-md-3 col-sm-6">
            <div class="card report-card shadow-sm bg-white p-3 border-start border-primary border-4 h-100">
                <div class="metric-label">No. of Proposals</div>
                <div class="metric-value font-monospace"><?= number_format($prop_metrics['total_proposals']) ?></div>
                <div class="small text-muted">Total matching proposals</div>
            </div>
        </div>
        <!-- Proposals Total Value -->
        <div class="col-md-3 col-sm-6">
            <div class="card report-card shadow-sm bg-white p-3 border-start border-info border-4 h-100">
                <div class="metric-label">Amount of Proposal</div>
                <div class="metric-value font-monospace text-truncate" style="font-size:1.35rem; padding-top: 5px;">BDT <?= number_format($prop_metrics['total_proposal_amt'] ?? 0, 2) ?></div>
                <div class="small text-muted">Pipeline value tracking</div>
            </div>
        </div>
        <!-- Sanctions Count -->
        <div class="col-md-3 col-sm-6">
            <div class="card report-card shadow-sm bg-white p-3 border-start border-success border-4 h-100">
                <div class="metric-label">No. of Sanctions</div>
                <div class="metric-value font-monospace text-success"><?= number_format($sanc_metrics['total_sanctions']) ?></div>
                <div class="small text-muted">Approved facilities timeline</div>
            </div>
        </div>
        <!-- Sanctions Total Value -->
        <div class="col-md-3 col-sm-6">
            <div class="card report-card shadow-sm bg-white p-3 border-start border-warning border-4 h-100">
                <div class="metric-label">Amount of Sanction</div>
                <div class="metric-value font-monospace text-dark text-truncate" style="font-size:1.35rem; padding-top: 5px;">BDT <?= number_format($sanc_metrics['total_sanction_amt'] ?? 0, 2) ?></div>
                <div class="small text-muted">Total sanctioned volume</div>
            </div>
        </div>
    </div>

    <!-- UPDATED: COMMITTEE MEETING & BOARD MEETING SUB-METRICS METRIC ROW -->
    <div class="row g-3 mb-4">
        <!-- Committee Summary Row -->
        <div class="col-md-6">
            <div class="card report-card shadow-sm border-0 bg-white">
                <div class="card-body p-4 border-top border-4 border-purple rounded-top">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold text-dark m-0"><i class="fas fa-users text-purple me-2"></i>Committee Meeting Sanctions</h6>
                        <div class="d-flex gap-1">
                            <span class="badge bg-light text-dark border font-monospace"><?= number_format($comm_metrics['total_sanctions']) ?> Sanctions</span>
                            <span class="badge bg-purple font-monospace"><?= number_format($comm_metrics['total_facilities']) ?> Facilities</span>
                        </div>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex align-items-baseline justify-content-between">
                        <span class="text-secondary small fw-bold font-monospace">AGGREGATE AMOUNT:</span>
                        <h4 class="fw-bold text-dark font-monospace mb-0">BDT <?= number_format($comm_metrics['total_amt'] ?? 0, 2) ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Board Summary Row -->
        <div class="col-md-6">
            <div class="card report-card shadow-sm border-0 bg-white">
                <div class="card-body p-4 border-top border-4 border-dark rounded-top">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold text-dark m-0"><i class="fas fa-gavel text-dark me-2"></i>Board Meeting Sanctions</h6>
                        <div class="d-flex gap-1">
                            <span class="badge bg-light text-dark border font-monospace"><?= number_format($board_metrics['total_sanctions']) ?> Sanctions</span>
                            <span class="badge bg-dark text-white font-monospace"><?= number_format($board_metrics['total_facilities']) ?> Facilities</span>
                        </div>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex align-items-baseline justify-content-between">
                        <span class="text-secondary small fw-bold font-monospace">AGGREGATE AMOUNT:</span>
                        <h4 class="fw-bold text-dark font-monospace mb-0">BDT <?= number_format($board_metrics['total_amt'] ?? 0, 2) ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FACILITY TYPE AGGREGATED BREAKDOWN SECTION -->
    <div class="card shadow-sm border-0 bg-white mb-4">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-layer-group text-info me-2"></i>Facility Type Distribution Summary</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle mb-0">
                <thead class="table-light text-uppercase font-monospace small">
                    <tr>
                        <th class="ps-4">Facility Type</th>
                        <th class="text-center">No. of Active Facilities</th>
                        <th class="text-end pe-4">Total Facility Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($facility_types_summary && $facility_types_summary->num_rows > 0): ?>
                        <?php while($ft = $facility_types_summary->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4 fw-bold text-secondary"><?= htmlspecialchars($ft['facility_type'] ?: 'Unspecified') ?></td>
                                <td class="text-center font-monospace"><?= number_format($ft['facility_count']) ?></td>
                                <td class="text-end pe-4 fw-bold font-monospace text-primary">BDT <?= number_format($ft['total_facility_amt'], 2) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted py-3">No active facility records tracked within selected range.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- COMPREHENSIVE COMPLIANCE AUDIT LEDGER -->
    <div class="card shadow-sm border-0 bg-white">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-list-alt text-success me-2"></i>Detailed Facility Ledger</h5>
            <span class="text-muted small">Generated on: <strong><?= date('d-M-Y h:i A') ?></strong></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle mb-0 small">
                <thead class="table-light text-uppercase font-monospace" style="font-size:0.75rem;">
                    <tr>
                        <th class="ps-4">Client / File Details</th>
                        <th>Branch &amp; Zone</th>
                        <th>Facility Specifics</th>
                        <th>Meetings Reference</th>
                        <th class="text-end pe-4">Facility Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($ledger_list && $ledger_list->num_rows > 0): ?>
                        <?php while($row = $ledger_list->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['client_name']) ?></div>
                                    <div class="text-muted" style="font-size: 11px;">
                                        File No: <strong><?= htmlspecialchars($row['file_number'] ?? 'N/A') ?></strong> | 
                                        Division: <span class="badge bg-light text-dark border px-1"><?= htmlspecialchars($row['division']) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($row['branch'] ?? 'N/A') ?></div>
                                    <span class="text-muted small font-monospace" style="font-size: 11px;"><i class="fas fa-map-marker-alt text-danger me-1"></i><?= htmlspecialchars($row['zone'] ?? 'N/A') ?></span>
                                </td>
                                <td>
                                    <div class="fw-bold text-secondary"><?= htmlspecialchars($row['facility_type'] ?: 'N/A') ?></div>
                                    <div class="text-muted font-monospace" style="font-size: 11px;">
                                        Ref: <?= htmlspecialchars($row['sanction_letter_ref_no'] ?: 'N/A') ?><br>
                                        Date: <?= $row['sanction_date'] ? date('d-M-Y', strtotime($row['sanction_date'])) : 'N/A' ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-size: 11px;">
                                        <i class="fas fa-users text-purple me-1"></i> Comm Meet: <strong><?= htmlspecialchars($row['comm_meet_no'] ?: 'N/A') ?></strong>
                                    </div>
                                    <div style="font-size: 11px;">
                                        <i class="fas fa-gavel text-dark me-1"></i> Board Meet: <strong><?= htmlspecialchars($row['board_meet_no'] ?: 'N/A') ?></strong>
                                    </div>
                                </td>
                                <td class="text-end pe-4 fw-bold font-monospace text-success" style="font-size:0.9rem;">
                                    BDT <?= number_format($row['facility_amount'], 2) ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                <i class="fas fa-folder-open fa-2x mb-2 text-black-50 d-block"></i> No matching facility data found within selected range.
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