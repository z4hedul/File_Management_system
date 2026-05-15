<?php
session_start();
include 'db.php';

if (!isset($_SESSION['loggedin'])) {
    header("location: index.php");
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id) { header("location: index.php"); exit; }

// 1. Fetch Main File Data (including Meeting Info)
$stmt = $conn->prepare("SELECT * FROM office_files WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) { echo "Record not found."; exit; }

// 2. Fetch All Facilities grouped by Sanction Date
$fac_stmt = $conn->prepare("SELECT * FROM file_facilities WHERE file_record_id = ? ORDER BY sanction_date DESC");
$fac_stmt->bind_param("i", $id);
$fac_stmt->execute();
$facilities = $fac_stmt->get_result();

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
        @media print { .no-print { display: none; } }
    </style>
</head>

<body class="bg-light p-4">
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
<div class="container bg-white shadow rounded p-4";>
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-2">
        <h4 class="fw-bold">
    <span class="text-secondary">
        <i class="fas fa-file-alt"></i> Detailed Record:
    </span> 
    
    <span class="text-primary text-uppercase">
        <?php echo htmlspecialchars($data['client'] ?? ''); ?>
    </span>
</h4>

        <div class="no-print">
<?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
        <a href="edit_sanction.php?id=<?php echo $id; ?>" class="btn btn-warning btn-sm">
            <i class="fas fa-pen"></i> Update Sanction/Meeting
        </a>
    <?php endif; ?>

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
   
    <h5 class="text-secondary border-bottom pb-1 mb-2"><i class="fas fa-layer-group"></i> Sanction & Facility History</h5>

<?php 
$current_date = "";
$total_all = 0;
$sub_total = 0;

if ($facilities->num_rows > 0):
    // 1. Fetch all records into an array first
    $fac_rows = $facilities->fetch_all(MYSQLI_ASSOC);
    
    // 2. DEFINE THE VARIABLE HERE to stop the Warning
    $total_rows = count($fac_rows); 

    foreach ($fac_rows as $index => $f): 
        $total_all += $f['amount'] ?? 0;
        $f_date = !empty($f['sanction_date']) ? date("d.m.Y", strtotime($f['sanction_date'])) : 'N/A';
        
        // Handle Sub-total display when date changes
        if ($current_date !== "" && $current_date !== $f_date):
?>
            <tr class="table-secondary">
                <td class="text-end fw-bold">Sub-Total:</td>
                <td class="text-end fw-bold text-primary"><?php echo number_format($sub_total, 2); ?></td>
            </tr>
            </tbody></table>
<?php 
            $sub_total = 0;
        endif;

        // Handle Header display for new date
        if ($current_date !== $f_date):
            $current_date = $f_date;
?>
            <div class="sanction-header d-flex justify-content-between align-items-center bg-light border p-3 mt-4 rounded-top shadow-sm">
    <div class="fw-bold text-dark">
        <i class="fas fa-calendar-check text-primary me-2"></i>
        Sanction Date: <?php echo $current_date; ?>
    </div>
    
    <div class="text-end">
        <div class="small">
            <strong>Invet. Committee Meeting No & (Date):</strong> <?php echo htmlspecialchars($f['comm_meet_no'] ?? 'N/A'); ?> 
            <span class="text-muted">(<?php echo !empty($f['comm_meet_date']) ? date("d.m.Y", strtotime($f['comm_meet_date'])) : 'N/A'; ?>)</span>
        </div>
        <div class="small">
            <strong>Board Meeting No & (Date):</strong> <?php echo htmlspecialchars($f['board_meet_no'] ?? 'N/A'); ?> 
            <span class="text-muted">(<?php echo !empty($f['board_meet_date']) ? date("d.m.Y", strtotime($f['board_meet_date'])) : 'N/A'; ?>)</span>
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
<?php 
        endif;

        $sub_total += $f['amount'] ?? 0;
?>
                <tr>
                    <td><?php echo htmlspecialchars($f['facility_type']); ?></td>
                    <td class="text-end"><?php echo number_format($f['amount'] ?? 0, 2); ?></td>
                </tr>
<?php 
        // 3. Use the variable to check for the last row
        if ($index === $total_rows - 1): 
?>
            <tr class="table-secondary">
                <td class="text-end fw-bold">Sub-Total:</td>
                <td class="text-end fw-bold text-primary"><?php echo number_format($sub_total, 2); ?></td>
            </tr>
<?php
        endif;

    endforeach;
    echo "</tbody></table>"; 
else:
?>
    <div class="alert alert-light border text-center mt-3">No facility records found for this client.</div>
<?php endif; ?>

<div class="bg-info text-white p-2 mt-4 text-end rounded shadow-sm">
    <span class="me-3 text-uppercase small opacity-75">Aggregate Grant Total:</span>
    <strong class="fs-5"><?php echo number_format($total_all ?? 0, 2); ?></strong>
</div>
</body>
</html>