<?php
session_start();
include 'db.php';

// 1. Security & Admin Check
if (!isset($_SESSION['loggedin'])) { header("location: login.php"); exit; }
if ($_SESSION['role'] !== 'admin') {
    echo "<script>alert('Access Denied'); window.location.href='index.php';</script>";
    exit;
}

$id = $_GET['id'] ?? null; // The Main File ID
if (!$id) { header("location: index.php"); exit; }

// 2. Fetch Main Client Info
$stmt = $conn->prepare("SELECT client, file_no FROM office_files WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$main_data = $stmt->get_result()->fetch_assoc();

// 3. Fetch All Facilities (including their specific meeting info)
$fac_stmt = $conn->prepare("SELECT * FROM file_facilities WHERE file_record_id = ? ORDER BY sanction_date DESC");
$fac_stmt->bind_param("i", $id);
$fac_stmt->execute();
$facilities = $fac_stmt->get_result();

// 4. Update Logic
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn->begin_transaction();
    try {
        // Clear old facilities for this file to sync new data
        $conn->query("DELETE FROM file_facilities WHERE file_record_id = $id");
        
        if (isset($_POST['f_types'])) {
            foreach ($_POST['f_types'] as $k => $type) {
                if (!empty($type)) {
                    $sql = "INSERT INTO file_facilities 
                            (file_record_id, facility_type, amount, sanction_date, comm_meet_no, comm_meet_date, board_meet_no, board_meet_date) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $ins = $conn->prepare($sql);
                    $ins->bind_param("isdsssss", 
                        $id, 
                        $type, 
                        $_POST['f_amts'][$k], 
                        $_POST['f_dates'][$k],
                        $_POST['c_nos'][$k],
                        $_POST['c_dates'][$k],
                        $_POST['b_nos'][$k],
                        $_POST['b_dates'][$k]
                    );
                    $ins->execute();
                }
            }
        }
        $conn->commit();
        header("Location: more_details.php?id=$id&status=updated");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        die("Error updating records: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Edit Sanctions - <?php echo htmlspecialchars($main_data['client']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .facility-card { border-left: 5px solid #0d6efd; background: #fff; transition: 0.3s; }
        .facility-card:hover { border-left-color: #ffc107; }
        label { font-size: 0.75rem; text-transform: uppercase; color: #6c757d; font-weight: bold; }
    </style>
</head>
<body class="bg-light p-4">

<div class="container" style="max-width: 1000px;">
    <form method="POST">
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded shadow-sm">
            <div>
                <h4 class="mb-0 text-primary"><i class="fas fa-edit"></i> Edit Sanctions & Meetings</h4>
                <small class="text-muted">Client: <strong><?php echo htmlspecialchars($main_data['client']); ?></strong></small>
            </div>
        </div>

        <div id="facility-editor">
            <?php if ($facilities->num_rows > 0): ?>
                <?php while($f = $facilities->fetch_assoc()): ?>
                <div class="card mb-3 shadow-sm facility-card">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label>Sanction Date</label>
                                <input type="date" name="f_dates[]" value="<?php echo $f['sanction_date']; ?>" class="form-control form-control-sm border-primary" required>
                                <div class="mt-2">
                                    <label>Facility Type</label>
                                    <input type="text" name="f_types[]" value="<?php echo htmlspecialchars($f['facility_type']); ?>" class="form-control form-control-sm" required>
                                </div>
                                <div class="mt-2">
                                    <label>Amount</label>
                                    <input type="number" step="0.01" name="f_amts[]" value="<?php echo $f['amount']; ?>" class="form-control form-control-sm fw-bold" required>
                                </div>
                            </div>

                            <div class="col-md-4 border-start">
                                <label class="text-info">Investment Committee Meeting</label>
                                <input type="text" name="c_nos[]" value="<?php echo htmlspecialchars($f['comm_meet_no'] ?? ''); ?>" class="form-control form-control-sm mb-2" placeholder="Meet No">
                                <input type="date" name="c_dates[]" value="<?php echo $f['comm_meet_date'] ?? ''; ?>" class="form-control form-control-sm">
                            </div>

                            <div class="col-md-4 border-start">
                                <label class="text-success">Board Meeting</label>
                                <input type="text" name="b_nos[]" value="<?php echo htmlspecialchars($f['board_meet_no'] ?? ''); ?>" class="form-control form-control-sm mb-2" placeholder="Meet No">
                                <input type="date" name="b_dates[]" value="<?php echo $f['board_meet_date'] ?? ''; ?>" class="form-control form-control-sm">
                            </div>

                            <div class="col-md-1 d-flex align-items-center justify-content-center">
                                <button type="button" class="btn btn-outline-danger remove-row"><i class="fas fa-trash-alt"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>

        <div class="text-center mt-4">
            <button type="button" id="add-new-row" class="btn btn-outline-success">
                <i class="fas fa-plus-circle"></i> Add Another Sanction Entry
            </button>
        </div>

        <div class="mt-5 pt-3 border-top d-flex gap-2 justify-content-end">
             <a href="index.php" class="btn btn-secondary shadow-sm">Cencel</a>
             <button type="submit" class="btn btn-primary px-5 shadow-sm">Save All Changes</button>
        </div>
    </form>
</div>

<script>
    // JavaScript to add new blank sanction cards
    document.getElementById('add-new-row').onclick = function() {
        let container = document.getElementById('facility-editor');
        let div = document.createElement('div');
        div.className = 'card mb-3 shadow-sm facility-card animate__animated animate__fadeIn';
        div.innerHTML = `
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label>Sanction Date</label>
                        <input type="date" name="f_dates[]" class="form-control form-control-sm border-primary" required>
                        <div class="mt-2">
                            <label>Facility Type</label>
                            <input type="text" name="f_types[]" class="form-control form-control-sm" placeholder="Type" required>
                        </div>
                        <div class="mt-2">
                            <label>Amount</label>
                            <input type="number" step="0.01" name="f_amts[]" class="form-control form-control-sm fw-bold" placeholder="0.00" required>
                        </div>
                    </div>
                    <div class="col-md-4 border-start">
                        <label class="text-info">Investment Committee</label>
                        <input type="text" name="c_nos[]" value="<?php echo htmlspecialchars($f['comm_meet_no'] ?? ''); ?>" class="form-control form-control-sm mb-2" placeholder="Meet No">
                        <input type="date" name="c_dates[]" value="<?php echo $f['comm_meet_date'] ?? ''; ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4 border-start">
                        <label class="text-success">Board Meeting</label>
                        <input type="text" name="b_nos[]" value="<?php echo htmlspecialchars($f['board_meet_no'] ?? ''); ?>" class="form-control form-control-sm mb-2" placeholder="Meet No">
                        <input type="date" name="b_dates[]" value="<?php echo $f['board_meet_date'] ?? ''; ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-1 d-flex align-items-center justify-content-center">
                        <button type="button" class="btn btn-outline-danger remove-row"><i class="fas fa-trash-alt"></i></button>
                    </div>
                </div>
            </div>`;
        container.appendChild(div);
    };

    document.addEventListener('click', function(e){
        if(e.target.closest('.remove-row')) {
            if(confirm('Are you sure you want to remove this sanction and its meeting info?')) {
                e.target.closest('.facility-card').remove();
            }
        }
    });
</script>
</body>
</html>