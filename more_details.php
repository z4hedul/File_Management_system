<?php
session_start();
include 'db.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: index.php");
    exit;
}

$id = $_GET['id'] ?? null;
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$facility_filter = trim($_GET['facility_filter'] ?? '');

if (!$id) { header("location: index.php"); exit; }

if (!empty($from_date)) {
    $d = DateTime::createFromFormat('Y-m-d', $from_date);
    $from_date = $d ? $d->format('Y-m-d') : '';
}
if (!empty($to_date)) {
    $d = DateTime::createFromFormat('Y-m-d', $to_date);
    $to_date = $d ? $d->format('Y-m-d') : '';
}

// 1. Fetch Main File Data
$stmt = $conn->prepare("SELECT * FROM office_files WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) { echo "Record not found."; exit; }

// 2. Fetch Facilities with optional date and type filters
$fac_stmt = $conn->prepare(
    "SELECT * FROM file_facilities
     WHERE file_record_id = ?
       AND (? = '' OR sanction_date >= ?)
       AND (? = '' OR sanction_date <= ?)
       AND (? = '' OR facility_type LIKE CONCAT('%', ?, '%')) ORDER BY sanction_date DESC"
);
$fac_stmt->bind_param(
    "issssss",
    $id,
    $from_date,
    $from_date,
    $to_date,
    $to_date,
    $facility_filter,
    $facility_filter
);
$fac_stmt->execute();
$facilities = $fac_stmt->get_result();
$fac_rows = $facilities->fetch_all(MYSQLI_ASSOC);

$type_totals = [];
$group_totals = ['BG/LC' => 0, 'PIF/HYPO' => 0, 'Other' => 0];
$total_all = 0;
$year_groups = [];

foreach ($fac_rows as $row) {
    $amount = floatval($row['amount'] ?? 0);
    $total_all += $amount;
    $type = strtoupper(trim($row['facility_type'] ?? '')) ?: 'UNKNOWN';
    $type_totals[$type] = ($type_totals[$type] ?? 0) + $amount;

    if (in_array($type, ['BG', 'LC'], true)) {
        $group_totals['BG/LC'] += $amount;
    } elseif (in_array($type, ['PIF', 'HYPO'], true)) {
        $group_totals['PIF/HYPO'] += $amount;
    } else {
        $group_totals['Other'] += $amount;
    }

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

krsort($year_groups);

// 3. Fetch Attachments
$at_stmt = $conn->prepare("SELECT * FROM file_attachments WHERE file_record_id = ?");
$at_stmt->bind_param("i", $id);
$at_stmt->execute();
$attachments = $at_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Details - <?php echo htmlspecialchars($data['client'] ?? ''); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .sanction-header { background-color: #e9ecef; font-weight: bold; padding: 8px 15px; border-left: 5px solid #0d6efd; margin-top: 20px; }
        .info-label { background-color: #f8f9fa; font-weight: bold; width: 25%; }
        .year-panel { display: none; }
        .year-panel.active { display: block; }
        .year-tabs .nav-link { cursor: pointer; }
        @media print { .no-print { display: none; } }
    </style>
</head>

<body class="bg-light p-4">

<div class="container mt-3 no-print">
    <?php if (isset($_GET['status']) && $_GET['status'] === 'updated'): ?>
        <div class="alert alert-success shadow-sm border-0 d-flex align-items-center justify-content-between fade show" role="alert">
            <div>
                <i class="fas fa-check-circle me-2 fs-5"></i> 
                <strong>Changes Saved!</strong> The record has been updated. 
            </div>
            <div class="btn-group">
                <a href="index.php" class="btn btn-sm btn-outline-dark">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="add_facility.php?id=<?php echo $id; ?>" class="btn btn-sm btn-success">
                    <i class="fas fa-plus"></i> Add Another
                </a>
                <button type="button" class="btn-close ms-2" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['status']) && $_GET['status'] === 'added'): ?>
        <div class="alert alert-info shadow-sm border-0 d-flex align-items-center justify-content-between fade show" role="alert">
            <div>
                <i class="fas fa-info-circle me-2 fs-5"></i> 
                <strong>Facility Added!</strong> New data recorded successfully.
            </div>
            <div class="btn-group">
                <a href="index.php" class="btn btn-sm btn-outline-info">
                    <i class="fas fa-home"></i> Home
                </a>
                <button type="button" class="btn-close ms-2" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="container bg-white shadow rounded p-4">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-2">
        <h4 class="fw-bold">
            <span class="text-secondary">
                <i class="fas fa-file-alt"></i> Detailed Record:
            </span> 
            <br>
            <label class="form-label">Name of the Client: </label>
            <span class="text-primary text-uppercase">
                <?php echo htmlspecialchars($data['client'] ?? ''); ?>
            </span>
        </h4>
        <div class="no-print">
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print"></i> Print</button>
            <a href="index.php" class="btn btn-secondary btn-sm">Home</a>
        </div>
    </div>

    <table class="table table-bordered mb-4">
        <tr>
            <td class="info-label">Branch</td>
            <td><?php echo htmlspecialchars(($data['branch_code'] ?? '') . " - " . ($data['branch_name'] ?? '')); ?></td>
            <td class="info-label">File No</td>
            <td><?php echo htmlspecialchars($data['file_no'] ?? ''); ?></td>
        </tr>
    </table>

    <form method="get" action="more_details.php" class="row g-3 mb-4">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($id); ?>">
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
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-filter"></i> Apply</button>
        </div>
    </form>

    <?php if ($from_date || $to_date || $facility_filter): ?>
        <div class="alert alert-info shadow-sm border-0 mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <strong>Filter active:</strong>
                    <?php
                        $labelParts = [];
                        if ($from_date) { $labelParts[] = 'From ' . date('d.m.Y', strtotime($from_date)); }
                        if ($to_date) { $labelParts[] = 'To ' . date('d.m.Y', strtotime($to_date)); }
                        if ($facility_filter) { $labelParts[] = 'Facility contains "' . htmlspecialchars($facility_filter) . '"'; }
                        echo implode(' | ', $labelParts);
                    ?>
                </div>
                <a href="more_details.php?id=<?php echo urlencode($id); ?>" class="btn btn-sm btn-outline-secondary">Clear Filter</a>
            </div>
        </div>
    <?php endif; ?>

    <div class="row row-cols-1 row-cols-md-4 g-3 mb-4">
        <?php if (!empty($type_totals)): ?>
            <?php foreach ($type_totals as $type => $amount): ?>
                <div class="col">
                    <div class="card border-primary shadow-sm h-100">
                        <div class="card-body p-3">
                            <div class="text-secondary small mb-2"><?php echo htmlspecialchars($type); ?></div>
                            <div class="fs-5 fw-bold"><?php echo number_format($amount, 2); ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="col">
            <div class="card border-dark shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="text-secondary small mb-2">Total</div>
                    <div class="fs-5 fw-bold"><?php echo number_format($total_all, 2); ?></div>
                </div>
            </div>
        </div>
    </div>

    <h5 class="text-secondary border-bottom pb-1 mb-2"><i class="fas fa-layer-group"></i> Sanction & Facility History</h5>

    <?php if (!empty($year_groups)): ?>
        <ul class="nav nav-pills year-tabs gap-2 mb-4">
            <?php $yearIndex = 0; foreach ($year_groups as $year => $group): ?>
                <li class="nav-item">
                    <button type="button" class="nav-link <?php echo $yearIndex === 0 ? 'active' : ''; ?>" data-year="<?php echo htmlspecialchars($year); ?>">
                        <?php echo htmlspecialchars($year); ?>
                        <span class="badge bg-light text-dark ms-1"><?php echo count($group['rows']); ?></span>
                    </button>
                </li>
            <?php $yearIndex++; endforeach; ?>
        </ul>

        <?php $panelIndex = 0; foreach ($year_groups as $year => $group): ?>
            <div class="year-panel <?php echo $panelIndex === 0 ? 'active' : ''; ?>" data-year-panel="<?php echo htmlspecialchars($year); ?>">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center bg-white border-bottom p-3 mb-3 rounded-top shadow-sm">
                    <div>
                        <h6 class="mb-1 text-primary"><i class="fas fa-calendar-alt me-2"></i> Year <?php echo htmlspecialchars($year); ?></h6>
                        <div class="text-muted small">Click a year tab to view that year’s sanction details.</div>
                    </div>
                    <div class="text-md-end mt-2 mt-md-0">
                        <span class="badge bg-success fs-6">Year Total: <?php echo number_format($group['total'], 2); ?></span>
                    </div>
                </div>

                <?php
                    $rowsByDate = [];
                    foreach ($group['rows'] as $row) {
                        $dateKey = !empty($row['sanction_date']) ? date('d.m.Y', strtotime($row['sanction_date'])) : 'N/A';
                        $rowsByDate[$dateKey][] = $row;
                    }
                ?>

                <?php foreach ($rowsByDate as $dateKey => $rows): ?>
                    <?php 
                    $subTotal = 0; 
                    
                    // FIXED LOGIC: Use $rows[0] because it holds the data array for this specific group entry
                    $b_date_raw = $rows[0]['board_meet_date'] ?? '';
                    if (!empty($b_date_raw) && $b_date_raw !== '0000-00-00' && $b_date_raw !== '1970-01-01' && strtotime($b_date_raw) > 0) {
                        $display_board_date = date("d.m.Y", strtotime($b_date_raw));
                    } else {
                        $display_board_date = 'N/A';
                    }

                    $c_date_raw = $rows[0]['comm_meet_date'] ?? '';
                    if (!empty($c_date_raw) && $c_date_raw !== '0000-00-00' && $c_date_raw !== '1970-01-01' && strtotime($c_date_raw) > 0) {
                        $display_comm_date = date("d.m.Y", strtotime($c_date_raw));
                    } else {
                        $display_comm_date = 'N/A';
                    }
                    ?>
                    <div class="sanction-header d-flex justify-content-between align-items-center bg-light border p-3 mt-4 rounded-top shadow-sm">
                        <div class="fw-bold text-dark">
                            <i class="fas fa-calendar-check text-primary me-2"></i>
                            <span class="badge bg-primary text-white me-2">Ref: <?php echo htmlspecialchars($rows[0]['sanction_letter_ref_no'] ?? 'N/A'); ?></span>
                            <span class="text-muted">Sanction Date:</span> <?php echo htmlspecialchars($dateKey); ?>
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

                    <table class="table table-hover border mb-0">
                        <thead class="table-white">
                            <tr class="small text-uppercase">
                                <th style="width: 70%;">Facility Type</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php $subTotal += floatval($row['amount'] ?? 0); ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['facility_type']); ?></td>
                                    <td class="text-end"><?php echo number_format($row['amount'] ?? 0, 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-secondary">
                                <td class="text-end fw-bold">Sub-Total:</td>
                                <td class="text-end fw-bold text-primary"><?php echo number_format($subTotal, 2); ?></td>
                            </tr>
                        </tbody>
                    </table>
                <?php endforeach; ?>

                <div class="row row-cols-1 row-cols-md-3 g-2 mt-3 p-3 bg-light rounded-bottom">
                    <?php foreach ($group['type_totals'] as $type => $amount): ?>
                        <div class="col">
                            <div class="p-3 bg-white border rounded">
                                <div class="text-muted small"><?php echo htmlspecialchars($type); ?></div>
                                <div class="fs-5 fw-bold"><?php echo number_format($amount, 2); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php $panelIndex++; endforeach; ?>
    <?php else: ?>
        <div class="alert alert-light border text-center mt-3">No facility records found for this client.</div>
    <?php endif; ?>

    <div class="bg-info text-white p-2 mt-4 text-end rounded shadow-sm">
        <span class="me-3 text-uppercase small opacity-75">Aggregate Grant Total:</span>
        <strong class="fs-5"><?php echo number_format($total_all ?? 0, 2); ?></strong>
    </div>

    <div class="mt-4 pt-3 border-top d-flex gap-2 justify-content-end no-print">
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <a href="edit_sanction.php?id=<?php echo $id; ?>" class="btn btn-warning btn-m btn-hover-custom shadow-sm fw-bold px-3">
                <i class="fas fa-pen-nib me-1"></i> Update Sanction/Meeting
            </a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-secondary btn-m">Home</a>
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
        button.addEventListener('click', function() {
            activateYear(this.dataset.year);
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