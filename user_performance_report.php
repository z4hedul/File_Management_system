<?php
session_start();
include 'db.php';
include 'header.php';

if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

// Get filter parameters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';
$selected_user = isset($_GET['user_id']) ? intval($_GET['user_id']) : '';

// Build date condition
$date_condition = "";
if (!empty($from_date) && !empty($to_date)) {
    $safe_from = $conn->real_escape_string($from_date);
    $safe_to = $conn->real_escape_string($to_date);
    $date_condition = " AND pa.assigned_date BETWEEN '{$safe_from} 00:00:00' AND '{$safe_to} 23:59:59' ";
}

// Fetch all users for dropdown
$users_list = [];
$users_query = $conn->query("SELECT id, full_name, username, employee_id, designation FROM users WHERE role != 'admin' ORDER BY full_name ASC");
if ($users_query && $users_query->num_rows > 0) {
    while ($user = $users_query->fetch_assoc()) {
        $users_list[] = $user;
    }
}

// Main query to get user performance data
$query = "
    SELECT 
        u.id AS user_id,
        u.full_name,
        u.username,
        u.employee_id,
        u.designation,
        COUNT(DISTINCT pa.proposal_ref) AS total_proposals,
        COUNT(DISTINCT pa.file_id) AS total_files_handled,
        COUNT(DISTINCT CASE WHEN pa.proposal_status = 'Approval/Sanction' THEN pa.proposal_ref END) AS approved_count,
        COUNT(DISTINCT CASE WHEN pa.proposal_status = 'Declined' THEN pa.proposal_ref END) AS declined_count,
        COUNT(DISTINCT CASE WHEN pa.proposal_status NOT IN ('Approval/Sanction', 'Declined') OR pa.proposal_status IS NULL THEN pa.proposal_ref END) AS in_process_count,
        COALESCE(SUM(pa.proposal_amount), 0) AS total_proposal_amount,
        COALESCE(SUM(CASE WHEN pa.proposal_status = 'Approval/Sanction' THEN pa.proposal_amount ELSE 0 END), 0) AS total_approved_amount,
        COALESCE(SUM(CASE WHEN pa.proposal_status = 'Declined' THEN pa.proposal_amount ELSE 0 END), 0) AS total_declined_amount,
        GROUP_CONCAT(DISTINCT o.client ORDER BY o.client SEPARATOR '||') AS client_list
    FROM users u
    LEFT JOIN proposal_assignments pa ON u.id = pa.user_id
    LEFT JOIN office_files o ON pa.file_id = o.id AND o.is_deleted = 0
    WHERE u.role != 'admin'
    {$date_condition}
";

if (!empty($selected_user)) {
    $query .= " AND u.id = {$selected_user}";
}

$query .= " GROUP BY u.id ORDER BY total_proposals DESC, u.full_name ASC";

$result = $conn->query($query);

// For detailed view when a user is selected - GROUPED BY PROPOSAL_REF
$detailed_records = [];
if (!empty($selected_user)) {
    // First fetch all assignments for the user
    $detail_query = "
        SELECT 
            pa.id,
            pa.proposal_ref,
            pa.proposal_status,
            pa.proposal_type,
            COALESCE(pa.proposal_amount, 0) AS proposal_amount,
            pa.assigned_date,
            o.client,
            o.file_no,
            o.branch_name,
            o.division,
            o.id AS file_id,
            ff.sanction_letter_ref_no,
            ff.sanction_date,
            ff.power_delegation,
            ff.facility_group
        FROM proposal_assignments pa
        JOIN office_files o ON pa.file_id = o.id
        LEFT JOIN file_facilities ff ON o.id = ff.file_record_id
        WHERE pa.user_id = {$selected_user} AND o.is_deleted = 0
        {$date_condition}
        ORDER BY pa.proposal_ref, pa.assigned_date DESC
    ";
    $detail_result = $conn->query($detail_query);
    
    // Group by proposal_ref
    $grouped_temp = [];
    if ($detail_result && $detail_result->num_rows > 0) {
        while ($row = $detail_result->fetch_assoc()) {
            $ref = $row['proposal_ref'];
            if (!isset($grouped_temp[$ref])) {
                $grouped_temp[$ref] = [
                    'proposal_ref' => $row['proposal_ref'],
                    'proposal_status' => $row['proposal_status'],
                    'assigned_date' => $row['assigned_date'],
                    'client' => $row['client'],
                    'file_no' => $row['file_no'],
                    'branch_name' => $row['branch_name'],
                    'division' => $row['division'],
                    'file_id' => $row['file_id'],
                    'sanction_letter_ref_no' => $row['sanction_letter_ref_no'],
                    'sanction_date' => $row['sanction_date'],
                    'power_delegation' => $row['power_delegation'],
                    'facilities' => [],
                    'total_amount' => 0
                ];
            }
            // Add facility to the proposal
            $grouped_temp[$ref]['facilities'][] = [
                'type' => $row['proposal_type'],
                'amount' => floatval($row['proposal_amount']),
                'group' => $row['facility_group']
            ];
            $grouped_temp[$ref]['total_amount'] += floatval($row['proposal_amount']);
        }
    }
    $detailed_records = array_values($grouped_temp);
}

$summary_scope_label = (!empty($from_date) && !empty($to_date))
    ? date('d-M-Y', strtotime($from_date)) . ' to ' . date('d-M-Y', strtotime($to_date))
    : 'All Time';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Performance Report</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background: #f4f6f9; }
        .card { border-radius: 12px; border: none; }
        .summary-stat {
            border: 1px solid #e6ebf2;
            border-radius: 16px;
            background: #fff;
            transition: transform .15s ease;
        }
        .summary-stat:hover { transform: translateY(-2px); }
        .stat-value { font-size: 1.8rem; font-weight: 700; }
        .stat-label { font-size: 0.7rem; text-transform: uppercase; font-weight: 600; color: #6c757d; letter-spacing: 0.5px; }
        .user-row { cursor: pointer; transition: all 0.2s ease; }
        .user-row:hover { background-color: #f8f9fa; transform: translateX(5px); }
        .user-row.active { background-color: #e3f2fd; border-left: 4px solid #0d6efd; }
        .facility-item { font-size: 0.75rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 3px 6px; margin-bottom: 3px; }
        .table-detail th { background: #0f172a; color: #fff; font-size: 0.75rem; }
    </style>
</head>
<body class="bg-light">
<div class="container my-5">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark m-0"><i class="fas fa-chart-line text-primary me-2"></i>User Performance Report</h3>
            <p class="text-muted small m-0">Track client dealing and proposal performance by user</p>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-dark shadow-sm"><i class="fas fa-print me-1"></i> Print</button>
            <a href="index.php" class="btn btn-primary shadow-sm"><i class="fas fa-tachometer-alt me-1"></i> Dashboard</a>
        </div>
    </div>

    <!-- Date Range Filter -->
    <div class="card shadow-sm border-0 mb-4 bg-white">
        <div class="card-body p-3">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-secondary"><i class="far fa-calendar-alt me-1 text-primary"></i>From Date</label>
                    <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-secondary"><i class="far fa-calendar-alt me-1 text-success"></i>To Date</label>
                    <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-secondary"><i class="fas fa-user me-1 text-info"></i>Filter by User</label>
                    <select name="user_id" class="form-select">
                        <option value="">-- All Users --</option>
                        <?php foreach ($users_list as $user): ?>
                            <option value="<?= $user['id'] ?>" <?= ($selected_user == $user['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user['full_name']) ?> (<?= htmlspecialchars($user['employee_id']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-dark flex-grow-1 shadow-sm">
                        <i class="fas fa-filter me-1"></i> Apply
                    </button>
                    <?php if (!empty($from_date) || !empty($to_date) || !empty($selected_user)): ?>
                        <a href="user_performance_report.php" class="btn btn-outline-secondary" title="Clear Filters">
                            <i class="fas fa-undo"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Date Range Indicator -->
    <?php if (!empty($from_date) && !empty($to_date)): ?>
        <div class="alert alert-info border-0 shadow-sm mb-4">
            <i class="fas fa-calendar-alt me-2"></i>
            Displaying records from <strong><?= date('d-M-Y', strtotime($from_date)) ?></strong> to <strong><?= date('d-M-Y', strtotime($to_date)) ?></strong>
        </div>
    <?php endif; ?>

    <!-- Users Summary Table -->
    <div class="card shadow-sm border-0 bg-white mb-4">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-users text-primary me-2"></i>User Performance Summary</h5>
            <small class="text-muted">Click on any user row to view detailed assignment history</small>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark small text-uppercase" style="font-size:0.7rem;">
                    <tr>
                        <th class="ps-4">User Name</th>
                        <th>Designation</th>
                        <th class="text-center">Total Proposals</th>
                        <th class="text-center">Files Handled</th>
                        <th class="text-center">In Process</th>
                        <th class="text-center">Approved</th>
                        <th class="text-center">Declined</th>
                        <th class="text-end pe-4">Total Amount (BDT)</th>
                        <th class="text-end pe-4">Approved Amount (BDT)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $grand_total_proposals = 0;
                    $grand_total_amount = 0;
                    $grand_approved_amount = 0;
                    if ($result && $result->num_rows > 0): 
                        while ($row = $result->fetch_assoc()):
                            $grand_total_proposals += intval($row['total_proposals']);
                            $grand_total_amount += floatval($row['total_proposal_amount']);
                            $grand_approved_amount += floatval($row['total_approved_amount']);
                    ?>
                        <tr class="user-row <?= ($selected_user == $row['user_id']) ? 'active' : '' ?>" 
                            data-user-id="<?= $row['user_id'] ?>" 
                            data-user-name="<?= htmlspecialchars($row['full_name']) ?>"
                            style="cursor: pointer;">
                            <td class="ps-4">
                                <div class="fw-bold text-dark"><?= htmlspecialchars($row['full_name']) ?></div>
                                <div class="text-muted small">ID: <?= htmlspecialchars($row['employee_id']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($row['designation'] ?? 'N/A') ?></td>
                            <td class="text-center fw-bold"><?= number_format(intval($row['total_proposals'])) ?></td>
                            <td class="text-center"><?= number_format(intval($row['total_files_handled'])) ?></td>
                            <td class="text-center"><span class="badge bg-warning text-dark"><?= number_format(intval($row['in_process_count'])) ?></span></td>
                            <td class="text-center"><span class="badge bg-success"><?= number_format(intval($row['approved_count'])) ?></span></td>
                            <td class="text-center"><span class="badge bg-danger"><?= number_format(intval($row['declined_count'])) ?></span></td>
                            <td class="text-end pe-4 fw-bold font-monospace">BDT <?= number_format(floatval($row['total_proposal_amount']), 2) ?></td>
                            <td class="text-end pe-4 fw-bold text-success font-monospace">BDT <?= number_format(floatval($row['total_approved_amount']), 2) ?></td>
                        </tr>
                    <?php endwhile; ?>
                        <tr class="table-secondary fw-bold">
                            <td class="ps-4">GRAND TOTAL</td>
                            <td>-</td>
                            <td class="text-center"><?= number_format($grand_total_proposals) ?></td>
                            <td class="text-center">-</td>
                            <td class="text-center">-</td>
                            <td class="text-center">-</td>
                            <td class="text-center">-</td>
                            <td class="text-end pe-4">BDT <?= number_format($grand_total_amount, 2) ?></td>
                            <td class="text-end pe-4">BDT <?= number_format($grand_approved_amount, 2) ?></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                No records found for the selected criteria.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Detailed Assignments Section (shown when a user is selected) - GROUPED BY PROPOSAL -->
    <?php if (!empty($selected_user) && !empty($detailed_records)): 
        $user_name = '';
        foreach ($users_list as $u) {
            if ($u['id'] == $selected_user) {
                $user_name = $u['full_name'];
                break;
            }
        }
    ?>
    <div class="card shadow-sm border-0 bg-white">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-dark">
                <i class="fas fa-list-alt text-primary me-2"></i>
                Detailed Assignments for: <?= htmlspecialchars($user_name) ?>
            </h5>
            <span class="badge bg-secondary"><?= count($detailed_records) ?> Proposals</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle mb-0">
                <thead class="table-dark small text-uppercase" style="font-size:0.7rem;">
                    <tr>
                        <th class="ps-4">Assigned Date</th>
                        <th>Client Name</th>
                        <th>Branch</th>
                        <th>Division</th>
                        <th>Proposal Ref</th>
                        <th>Facilities / Sanction Info</th>
                        <th class="text-end">Total Amount</th>
                        <th>Status</th>
                        <th>Approval By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $detail_total_amount = 0;
                    foreach ($detailed_records as $record): 
                        $detail_total_amount += floatval($record['total_amount']);
                    ?>
                        <tr>
                            <td class="ps-4 font-monospace small"><?= date('d-M-Y h:i A', strtotime($record['assigned_date'])) ?></td>
                            <td class="fw-semibold">
                                <a href="more_details.php?id=<?= $record['file_id'] ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($record['client']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($record['branch_name']) ?></td>
                            <td><?= htmlspecialchars($record['division']) ?></td>
                            <td class="font-monospace small"><?= htmlspecialchars($record['proposal_ref']) ?></td>
                            <td>
                                <div class="d-flex flex-column">
                                    <?php foreach ($record['facilities'] as $fac): ?>
                                        <div class="facility-item d-flex justify-content-between">
                                            <span class="text-muted"><?= htmlspecialchars($fac['type']) ?></span>
                                            <span class="fw-bold">BDT <?= number_format($fac['amount'], 2) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!empty($record['sanction_letter_ref_no'])): ?>
                                        <div class="small text-success mt-1">
                                            <i class="fas fa-file-contract me-1"></i>Sanction: <?= htmlspecialchars($record['sanction_letter_ref_no']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-end fw-bold font-monospace">BDT <?= number_format(floatval($record['total_amount']), 2) ?></td>
                            <td>
                                <?php if ($record['proposal_status'] == 'Approval/Sanction'): ?>
                                    <span class="badge bg-success">Approved</span>
                                <?php elseif ($record['proposal_status'] == 'Declined'): ?>
                                    <span class="badge bg-danger">Declined</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><?= htmlspecialchars($record['proposal_status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($record['power_delegation'] == 'MD'): ?>
                                    <span class="badge bg-success">MD</span>
                                <?php elseif ($record['power_delegation'] == 'Board'): ?>
                                    <span class="badge bg-dark">Board</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">N/A</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-secondary fw-bold">
                        <td colspan="7" class="text-end pe-3">GRAND TOTAL:</td>
                        <td class="text-end fw-bold font-monospace">BDT <?= number_format($detail_total_amount, 2) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php elseif (!empty($selected_user) && empty($detailed_records)): ?>
        <div class="card shadow-sm border-0 bg-white">
            <div class="card-body text-center text-muted py-5">
                <i class="fas fa-folder-open fa-3x mb-3 text-muted opacity-50"></i>
                <p>No assignment records found for this user in the selected date range.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Make user rows clickable to filter by that user
document.querySelectorAll('.user-row').forEach(row => {
    row.addEventListener('click', function() {
        const userId = this.dataset.userId;
        const fromDate = '<?= $from_date ?>';
        const toDate = '<?= $to_date ?>';
        
        let url = `user_performance_report.php?user_id=${userId}`;
        if (fromDate) url += `&from_date=${fromDate}`;
        if (toDate) url += `&to_date=${toDate}`;
        
        window.location.href = url;
    });
});
</script>

<?php include 'footer.php'; ?>
</body>
</html>