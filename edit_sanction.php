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

$sanction_ref_prefix = 'FSIB/HO/INVT/';

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
                    // Check if custom "Others" value is provided
                    if ($type === 'Others' && isset($_POST['f_types_other'][$k]) && !empty($_POST['f_types_other'][$k])) {
                        $type = $_POST['f_types_other'][$k];
                    }
                    
                    $sql = "INSERT INTO file_facilities 
                            (file_record_id, facility_type, amount, sanction_date, sanction_letter_ref_no, comm_meet_no, comm_meet_date, board_meet_no, board_meet_date) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $ref_suffix = trim($_POST['f_ref_suffixes'][$k] ?? '');
                    $full_ref = $sanction_ref_prefix . $ref_suffix;
                    $ins = $conn->prepare($sql);
                    $ins->bind_param("isdssssss", 
                        $id, 
                        $type, 
                        $_POST['f_amts'][$k], 
                        $_POST['f_dates'][$k],
                        $full_ref,
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
        .ref-prefix-text,
        .input-group-text {
            background: #e7f1ff;
            border-color: #b8daff;
            color: #094b96;
            font-weight: 700;
        }
        .ref-prefix-text {
            display: inline-block;
            margin-bottom: 0.25rem;
            padding: 0.5rem 0.75rem;
            border-radius: 0.5rem;
        }
        .ref-suffix-input {
            min-width: 180px;
        }
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
            <?php $rowIndex = 0; ?>
            <?php if ($facilities->num_rows > 0): ?>
                <?php while($f = $facilities->fetch_assoc()): $rowIndex++; ?>
                <div class="card mb-3 shadow-sm facility-card">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label>Sanction Letter Ref No</label>
                                <?php
                                    $existing_ref = $f['sanction_letter_ref_no'] ?? '';
                                    $ref_suffix = $existing_ref;
                                    if (strpos($existing_ref, $sanction_ref_prefix) === 0) {
                                        $ref_suffix = substr($existing_ref, strlen($sanction_ref_prefix));
                                    }
                                ?>
                                <div class="input-group">
                                    <span class="input-group-text"><?php echo $sanction_ref_prefix; ?></span>
                                    <input type="text" name="f_ref_suffixes[]" value="<?php echo htmlspecialchars($ref_suffix); ?>" class="form-control form-control-sm ref-suffix-input" placeholder="Reference suffix" required>
                                </div>
                                <div class="mt-2">
                                    <label>Sanction Date</label>
                                    <input type="date" name="f_dates[]" value="<?php echo $f['sanction_date']; ?>" class="form-control form-control-sm border-primary" required>
                                </div>
                                <div class="mt-2">
                                    <label>Facility Type</label>
                                    <select name="f_types[]" class="form-control form-control-sm facility-type-select" required>
                                        <option value="">-- Select Facility Type --</option>
                                        <option value="L/C (C2C)" <?php echo ($f['facility_type'] === 'L/C (C2C)') ? 'selected' : ''; ?>>L/C (C2C)</option>
                                        <option value="L/C Limit" <?php echo ($f['facility_type'] === 'L/C Limit') ? 'selected' : ''; ?>>L/C Limit</option>
                                        <option value="BG (C2C)" <?php echo ($f['facility_type'] === 'BG (C2C)') ? 'selected' : ''; ?>>BG (C2C)</option>
                                        <option value="BG (Limit)" <?php echo ($f['facility_type'] === 'BG (Limit)') ? 'selected' : ''; ?>>BG (Limit)</option>
                                        <option value="BG(PG)" <?php echo ($f['facility_type'] === 'BG(PG)') ? 'selected' : ''; ?>>BG(PG)</option>
                                        <option value="BG(BB)" <?php echo ($f['facility_type'] === 'BG(BB)') ? 'selected' : ''; ?>>BG(BB)</option>
                                        <option value="BM(Hypo)" <?php echo ($f['facility_type'] === 'BM(Hypo)') ? 'selected' : ''; ?>>BM(Hypo)</option>
                                        <option value="BS(PSI)" <?php echo ($f['facility_type'] === 'BS(PSI)') ? 'selected' : ''; ?>>BS(PSI)</option>
                                        <option value="BM(PIF)" <?php echo ($f['facility_type'] === 'BM(PIF)') ? 'selected' : ''; ?>>BM(PIF)</option>
                                        <option value="Credit Card" <?php echo ($f['facility_type'] === 'Credit Card') ? 'selected' : ''; ?>>Credit Card</option>
                                        <option value="Others" <?php echo !in_array($f['facility_type'], ['L/C (C2C)', 'L/C Limit', 'BG (C2C)', 'BG (Limit)', 'BG(PG)', 'BG(BB)', 'BM(Hypo)', 'BS(PSI)', 'BM(PIF)', 'Credit Card']) ? 'selected' : ''; ?>>Others</option>
                                    </select>
                                    <input type="text" name="f_types_other[]" class="form-control form-control-sm mt-2 facility-type-other" value="<?php echo !in_array($f['facility_type'], ['L/C (C2C)', 'L/C Limit', 'BG (C2C)', 'BG (Limit)', 'BG(PG)', 'BG(BB)', 'BM(Hypo)', 'BS(PSI)', 'BM(PIF)']) ? htmlspecialchars($f['facility_type']) : ''; ?>" placeholder="Enter custom facility type" style="display:<?php echo !in_array($f['facility_type'], ['L/C (C2C)', 'L/C Limit', 'BG (C2C)', 'BG (Limit)', 'BG(PG)', 'BG(BB)', 'BM(Hypo)', 'BS(PSI)', 'BM(PIF)']) ? 'block' : 'none'; ?>;">
                                </div>
                                <div class="mt-2">
                                    <label>Amount</label>
                                    <input type="number" step="0.01" name="f_amts[]" value="<?php echo $f['amount']; ?>" class="form-control form-control-sm fw-bold" required>
                                </div>
                            </div>

                            <?php
                                $comm_enabled = (!empty($f['comm_meet_no']) || !empty($f['comm_meet_date']));
                                $board_enabled = (!empty($f['board_meet_no']) || !empty($f['board_meet_date']));
                            ?>

                            <div class="col-md-4 border-start">
                                <div class="d-flex align-items-center">
                                    <label class="text-info mb-0">Investment Committee Meeting</label>
                                    <button type="button" class="btn btn-sm ms-2 <?php echo $comm_enabled ? 'btn-danger' : 'btn-success'; ?> meet-toggle" data-target="comm-<?php echo $rowIndex; ?>">
                                        <i class="fas <?php echo $comm_enabled ? 'fa-minus' : 'fa-plus'; ?>"></i>
                                    </button>
                                </div>
                                <div id="comm-<?php echo $rowIndex; ?>" class="mt-2" style="display:<?php echo $comm_enabled ? 'block' : 'none'; ?>;">
                                    <input type="text" name="c_nos[]" value="<?php echo htmlspecialchars($f['comm_meet_no'] ?? ''); ?>" class="form-control form-control-sm mb-2" placeholder="Meet No">
                                    <input type="date" name="c_dates[]" value="<?php echo $f['comm_meet_date'] ?? ''; ?>" class="form-control form-control-sm">
                                </div>
                            </div>

                            <div class="col-md-4 border-start">
                                <div class="d-flex align-items-center">
                                    <label class="text-success mb-0">Board Meeting</label>
                                    <button type="button" class="btn btn-sm ms-2 <?php echo $board_enabled ? 'btn-danger' : 'btn-success'; ?> meet-toggle" data-target="board-<?php echo $rowIndex; ?>">
                                        <i class="fas <?php echo $board_enabled ? 'fa-minus' : 'fa-plus'; ?>"></i>
                                    </button>
                                </div>
                                <div id="board-<?php echo $rowIndex; ?>" class="mt-2" style="display:<?php echo $board_enabled ? 'block' : 'none'; ?>;">
                                    <input type="text" name="b_nos[]" value="<?php echo htmlspecialchars($f['board_meet_no'] ?? ''); ?>" class="form-control form-control-sm mb-2" placeholder="Meet No">
                                    <input type="date" name="b_dates[]" value="<?php echo $f['board_meet_date'] ?? ''; ?>" class="form-control form-control-sm">
                                </div>
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
    // Handle facility type selection change
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('facility-type-select')) {
            const otherInput = e.target.closest('.col-md-3').querySelector('.facility-type-other');
            if (e.target.value === 'Others') {
                otherInput.style.display = 'block';
                otherInput.required = true;
            } else {
                otherInput.style.display = 'none';
                otherInput.required = false;
                otherInput.value = '';
            }
        }
    });

    // Handle meeting toggles (delegated)
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.meet-toggle');
        if (!btn) return;
        const targetId = btn.getAttribute('data-target');
        let group = null;
        if (targetId) {
            group = document.getElementById(targetId);
        } else {
            // find nearest group in same column
            group = btn.closest('.d-flex').nextElementSibling;
        }
        if (!group) return;
        const isVisible = group.style.display !== 'none';
        if (!isVisible) {
            group.style.display = 'block';
            btn.classList.remove('btn-success');
            btn.classList.add('btn-danger');
            btn.innerHTML = '<i class="fas fa-minus"></i>';
        } else {
            // clear inputs inside group
            group.querySelectorAll('input').forEach(i => i.value = '');
            group.style.display = 'none';
            btn.classList.remove('btn-danger');
            btn.classList.add('btn-success');
            btn.innerHTML = '<i class="fas fa-plus"></i>';
        }
    });

    // JavaScript to add new blank sanction cards
    document.getElementById('add-new-row').onclick = function() {
        let container = document.getElementById('facility-editor');
        let div = document.createElement('div');
        div.className = 'card mb-3 shadow-sm facility-card animate__animated animate__fadeIn';
        div.innerHTML = `
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label>Sanction Letter Ref No</label>
                        <div class="input-group">
                            <span class="input-group-text"><?php echo $sanction_ref_prefix; ?></span>
                            <input type="text" name="f_ref_suffixes[]" class="form-control form-control-sm" placeholder="Reference suffix" required>
                        </div>
                        <div class="mt-2">
                            <label>Sanction Date</label>
                            <input type="date" name="f_dates[]" class="form-control form-control-sm border-primary" required>
                        </div>
                        <div class="mt-2">
                            <label>Facility Type</label>
                            <select name="f_types[]" class="form-control form-control-sm facility-type-select" required>
                                <option value="">-- Select Facility Type --</option>
                                <option value="L/C (C2C)">L/C (C2C)</option>
                                <option value="L/C Limit">L/C Limit</option>
                                <option value="BG (C2C)">BG (C2C)</option>
                                <option value="BG (Limit)">BG (Limit)</option>
                                <option value="BG(PG)">BG(PG)</option>
                                <option value="BG(BB)">BG(BB)</option>
                                <option value="BM(Hypo)">BM(Hypo)</option>
                                <option value="BS(PSI)">BS(PSI)</option>
                                <option value="BM(PIF)">BM(PIF)</option>
                                <option value="Credit Card">Credit Card</option>
                                <option value="Others">Others</option>
                            </select>
                            <input type="text" name="f_types_other[]" class="form-control form-control-sm mt-2 facility-type-other" placeholder="Enter custom facility type" style="display:none;">
                        </div>
                        <div class="mt-2">
                            <label>Amount</label>
                            <input type="number" step="0.01" name="f_amts[]" class="form-control form-control-sm fw-bold" placeholder="0.00" required>
                        </div>
                    </div>
                    <div class="col-md-4 border-start">
                        <div class="d-flex align-items-center">
                            <label class="text-info mb-0">Investment Committee</label>
                            <button type="button" class="btn btn-sm btn-success ms-2 meet-toggle"><i class="fas fa-plus"></i></button>
                        </div>
                        <div class="comm-group mt-2" style="display:none;">
                            <input type="text" name="c_nos[]" class="form-control form-control-sm mb-2" placeholder="Meet No">
                            <input type="date" name="c_dates[]" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div class="col-md-4 border-start">
                        <div class="d-flex align-items-center">
                            <label class="text-success mb-0">Board Meeting</label>
                            <button type="button" class="btn btn-sm btn-success ms-2 meet-toggle"><i class="fas fa-plus"></i></button>
                        </div>
                        <div class="board-group mt-2" style="display:none;">
                            <input type="text" name="b_nos[]" class="form-control form-control-sm mb-2" placeholder="Meet No">
                            <input type="date" name="b_dates[]" class="form-control form-control-sm">
                        </div>
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