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

// Fetch Main Client Info
$stmt = $conn->prepare("SELECT client, file_no FROM office_files WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$main_data = $stmt->get_result()->fetch_assoc();

$sanction_ref_prefix = 'FSIB/HO/INVT/';

// --- FILE CONTROLLER PIPELINE (DELETION CHECKS) ---
if (isset($_GET['action']) && $_GET['action'] === 'delete_attachment' && isset($_GET['attach_id'])) {
    $delete_id = intval($_GET['attach_id']);
    
    $find_sql = "SELECT file_path FROM attachments WHERE id = ? AND file_record_id = ?";
    $stmt_find = $conn->prepare($find_sql);
    $stmt_find->bind_param("ii", $delete_id, $id);
    $stmt_find->execute();
    $file_to_del = $stmt_find->get_result()->fetch_assoc();
    $stmt_find->close();
    
    if ($file_to_del) {
        if (file_exists($file_to_del['file_path'])) {
            @unlink($file_to_del['file_path']);
        }
        $del_sql = "DELETE FROM attachments WHERE id = ?";
        $stmt_del = $conn->prepare($del_sql);
        $stmt_del->bind_param("i", $delete_id);
        $stmt_del->execute();
        $stmt_del->close();
        
        header("Location: edit_sanction.php?id=" . $id . "&status=deleted");
        exit;
    }
}

// 2. Fetch All Facilities
$fac_stmt = $conn->prepare("SELECT * FROM file_facilities WHERE file_record_id = ? ORDER BY sanction_date DESC");
$fac_stmt->bind_param("i", $id);
$fac_stmt->execute();
$facilities = $fac_stmt->get_result();

// 3. Update / Upload Logic
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $upload_dir = "uploads/";
    if (!is_dir($upload_dir)) { 
        mkdir($upload_dir, 0777, true); 
    }

    if (isset($_POST['f_dates'])) {
        foreach ($_POST['f_dates'] as $index => $row_date) {
            if (empty($row_date)) continue;

            $document_map = [
                'file_office_note'     => 'Office Note',
                'file_comm_memo'       => 'Committee Memo',
                'file_comm_minutes'    => 'Committee Minutes',
                'file_board_memo'      => 'Board Memo',
                'file_board_minutes'   => 'Board Minutes',
                'file_sanction_letter' => 'Sanction Letter'
            ];

            foreach ($document_map as $input_prefix => $description) {
                $input_array_name = $input_prefix . '_' . $index;

                if (!empty($_FILES[$input_array_name]['name'])) {
                    $safe_filename = time() . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "_", basename($_FILES[$input_array_name]['name']));
                    $target_filepath = $upload_dir . $safe_filename;
                    
                    if (move_uploaded_file($_FILES[$input_array_name]['tmp_name'], $target_filepath)) {
                        $check_sql = "SELECT id, file_path FROM attachments WHERE file_record_id = ? AND sanction_date = ? AND description = ?";
                        $chk_stmt = $conn->prepare($check_sql);
                        $chk_stmt->bind_param("iss", $id, $row_date, $description);
                        $chk_stmt->execute();
                        $existing_file = $chk_stmt->get_result()->fetch_assoc();
                        $chk_stmt->close();
                        
                        if ($existing_file) {
                            if (file_exists($existing_file['file_path'])) {
                                @unlink($existing_file['file_path']);
                            }
                            $update_file_sql = "UPDATE attachments SET file_path = ? WHERE id = ?";
                            $up_f_stmt = $conn->prepare($update_file_sql);
                            $up_f_stmt->bind_param("si", $target_filepath, $existing_file['id']);
                            $up_f_stmt->execute();
                            $up_f_stmt->close();
                        } else {
                            $insert_file_sql = "INSERT INTO attachments (file_record_id, sanction_date, file_path, description) VALUES (?, ?, ?, ?)";
                            $ins_f_stmt = $conn->prepare($insert_file_sql);
                            $ins_f_stmt->bind_param("isss", $id, $row_date, $target_filepath, $description);
                            $ins_f_stmt->execute();
                            $ins_f_stmt->close();
                        }
                    }
                }
            }
        }
    }

    header("Location: edit_sanction.php?id=" . $id . "&status=updated");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Edit Sanctions - <?php echo htmlspecialchars($main_data['client']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .facility-card { border-left: 5px solid #0d6efd; background: #fff; margin-bottom: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        label { font-size: 0.75rem; text-transform: uppercase; color: #6c757d; font-weight: bold; }
        .input-group-text { background: #e7f1ff; border-color: #b8daff; color: #094b96; font-weight: 700; }
        .attachment-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 12px; }
    </style>
</head>
<body class="bg-light p-4">

<div class="container" style="max-width: 1100px;">

    <?php if (isset($_GET['status'])): ?>
        <?php if ($_GET['status'] === 'updated'): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                <i class="fas fa-check-circle me-2"></i> <strong>Success!</strong> All operational parameters and regional file modifications have been saved.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($_GET['status'] === 'deleted'): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                <i class="fas fa-trash-alt me-2"></i> <strong>Deleted!</strong> The requested documentation attachment was completely purged from server storage.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="post" action="edit_sanction.php?id=<?php echo $id; ?>" enctype="multipart/form-data">
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded shadow-sm">
            <div>
                <h4 class="mb-0 text-primary"><i class="fas fa-edit"></i> Edit Sanctions & Dynamic Attachments</h4>
                <small class="text-muted">Client: <strong><?php echo htmlspecialchars($main_data['client']); ?></strong></small>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-sm btn-outline-secondary" onclick="return confirm('Are you sure you want to exit? Any unsaved form modifications on this pipeline will be lost.');">
                    <i class="fas fa-home me-1"></i> Home
                </a>
                <button type="button" id="add-new-row" class="btn btn-sm btn-success"><i class="fas fa-plus me-1"></i> Add Facility</button>
            </div>
        </div>

        <div id="facility-editor">
            <?php 
            $rowIndex = 0; 
            if ($facilities->num_rows > 0): 
                while($f = $facilities->fetch_assoc()): 
                    $current_row_date = $f['sanction_date'];
            ?>
                <div class="card facility-card">
                    <div class="card-body">
                        <div class="row g-3 mb-3">
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
                                    <input type="text" name="f_ref_suffixes[]" value="<?php echo htmlspecialchars($ref_suffix); ?>" class="form-control form-control-sm" required>
                                </div>
                                <div class="mt-2">
                                    <label>Sanction Date</label>
                                    <input type="date" name="f_dates[]" value="<?php echo $current_row_date; ?>" class="form-control form-control-sm border-primary" required>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <label>Facility Type</label>
                                <select name="f_types[]" class="form-control form-control-sm facility-type-select" required>
                                    <option value="L/C (C2C)" <?php echo ($f['facility_type'] === 'L/C (C2C)') ? 'selected' : ''; ?>>L/C (C2C)</option>
                                    <option value="L/C Limit" <?php echo ($f['facility_type'] === 'L/C Limit') ? 'selected' : ''; ?>>L/C Limit</option>
                                    <option value="BG (C2C)" <?php echo ($f['facility_type'] === 'BG (C2C)') ? 'selected' : ''; ?>>BG (C2C)</option>
                                    <option value="BG (Limit)" <?php echo ($f['facility_type'] === 'BG (Limit)') ? 'selected' : ''; ?>>BG (Limit)</option>
                                    <option value="Others" <?php echo !in_array($f['facility_type'], ['L/C (C2C)', 'L/C Limit', 'BG (C2C)', 'BG (Limit)']) ? 'selected' : ''; ?>>Others</option>
                                </select>
                                <input type="text" name="f_types_other[]" class="form-control form-control-sm mt-2 facility-type-other" value="<?php echo !in_array($f['facility_type'], ['L/C (C2C)', 'L/C Limit', 'BG (C2C)', 'BG (Limit)']) ? htmlspecialchars($f['facility_type']) : ''; ?>" style="display:<?php echo !in_array($f['facility_type'], ['L/C (C2C)', 'L/C Limit', 'BG (C2C)', 'BG (Limit)']) ? 'block' : 'none'; ?>;">
                                
                                <div class="mt-2">
                                    <label>Amount</label>
                                    <input type="number" step="0.01" name="f_amts[]" value="<?php echo $f['amount']; ?>" class="form-control form-control-sm fw-bold" required>
                                </div>
                            </div>

                            <?php
                                $comm_enabled = (!empty($f['comm_meet_no']) || !empty($f['comm_meet_date']));
                                $board_enabled = (!empty($f['board_meet_no']) || !empty($f['board_meet_date']));
                            ?>

                            <div class="col-md-3 border-start">
                                <div class="d-flex align-items-center mb-1">
                                    <label class="text-info mb-0">Committee Meeting</label>
                                    <button type="button" class="btn btn-sm btn-light ms-auto meet-toggle" data-target="comm-<?php echo $rowIndex; ?>">
                                        <i class="fas <?php echo $comm_enabled ? 'fa-minus text-danger' : 'fa-plus text-success'; ?>"></i>
                                    </button>
                                </div>
                                <div id="comm-<?php echo $rowIndex; ?>" style="display:<?php echo $comm_enabled ? 'block' : 'none'; ?>;">
                                    <input type="text" name="c_nos[]" value="<?php echo htmlspecialchars($f['comm_meet_no'] ?? ''); ?>" class="form-control form-control-sm mb-1" placeholder="Meet No">
                                    <input type="date" name="c_dates[]" value="<?php echo $f['comm_meet_date'] ?? ''; ?>" class="form-control form-control-sm">
                                </div>
                            </div>

                            <div class="col-md-2 border-start">
                                <div class="d-flex align-items-center mb-1">
                                    <label class="text-success mb-0">Board Meeting</label>
                                    <button type="button" class="btn btn-sm btn-light ms-auto meet-toggle" data-target="board-<?php echo $rowIndex; ?>">
                                        <i class="fas <?php echo $board_enabled ? 'fa-minus text-danger' : 'fa-plus text-success'; ?>"></i>
                                    </button>
                                </div>
                                <div id="board-<?php echo $rowIndex; ?>" style="display:<?php echo $board_enabled ? 'block' : 'none'; ?>;">
                                    <input type="text" name="b_nos[]" value="<?php echo htmlspecialchars($f['board_meet_no'] ?? ''); ?>" class="form-control form-control-sm mb-1" placeholder="Meet No">
                                    <input type="date" name="b_dates[]" value="<?php echo $f['board_meet_date'] ?? ''; ?>" class="form-control form-control-sm">
                                </div>
                            </div>

                            <div class="col-md-1 d-flex align-items-center justify-content-center border-start">
                                <button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fas fa-trash-alt"></i></button>
                            </div>
                        </div>

                        <div class="attachment-box mt-3">
                            <div class="text-dark fw-bold mb-2 small"><i class="fas fa-paperclip text-warning me-1"></i> Attachments Linked to Date: <?php echo date('d-m-Y', strtotime($current_row_date)); ?></div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered m-0 bg-white align-middle" style="font-size:0.85rem;">
                                    <thead>
                                        <tr class="table-light text-muted">
                                            <th style="width:25%;">Doc Type</th>
                                            <th style="width:45%;">Current File</th>
                                            <th style="width:30%;">Upload / Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $uploaded_docs = [];
                                        $fetch_att_sql = "SELECT id, description, file_path FROM attachments WHERE file_record_id = ? AND sanction_date = ?";
                                        $stmt_att_fetch = $conn->prepare($fetch_att_sql);
                                        $stmt_att_fetch->bind_param("is", $id, $current_row_date);
                                        $stmt_att_fetch->execute();
                                        $att_res = $stmt_att_fetch->get_result();
                                        while ($row_file = $att_res->fetch_assoc()) {
                                            $uploaded_docs[$row_file['description']] = [
                                                'id'   => $row_file['id'],
                                                'path' => $row_file['file_path']
                                            ];
                                        }
                                        $stmt_att_fetch->close();

                                        $form_inputs = [
                                            'file_office_note'     => 'Office Note',
                                            'file_comm_memo'       => 'Committee Memo',
                                            'file_comm_minutes'    => 'Committee Minutes',
                                            'file_board_memo'      => 'Board Memo',
                                            'file_board_minutes'   => 'Board Minutes',
                                            'file_sanction_letter' => 'Sanction Letter'
                                        ];

                                        foreach ($form_inputs as $input_field => $label_desc):
                                            $has_file = isset($uploaded_docs[$label_desc]);
                                        ?>
                                            <tr>
                                                <td class="fw-bold text-secondary"><?php echo $label_desc; ?></td>
                                                <td>
                                                    <?php if ($has_file): ?>
                                                        <span class="text-success small d-block text-truncate" style="max-width:280px;">
                                                            <i class="fas fa-check-circle me-1"></i><?php echo htmlspecialchars(basename($uploaded_docs[$label_desc]['path'])); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted italic small">No file attached</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-1">
                                                        <input type="file" name="<?php echo $input_field . '_' . $rowIndex; ?>" class="form-control form-control-sm" style="font-size:11px;">
                                                        <?php if ($has_file): ?>
                                                            <a href="<?php echo htmlspecialchars($uploaded_docs[$label_desc]['path']); ?>" target="_blank" class="btn btn-xs btn-outline-secondary px-2"><i class="fas fa-eye"></i></a>
                                                            <a href="edit_sanction.php?id=<?php echo $id; ?>&action=delete_attachment&attach_id=<?php echo $uploaded_docs[$label_desc]['id']; ?>" class="btn btn-xs btn-outline-danger px-2" onclick="return confirm('Delete this document?');"><i class="fas fa-trash"></i></a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>
            <?php 
                $rowIndex++;
                endwhile; 
            endif; 
            ?>
        </div>

        <div class="mt-4 pt-3 border-top d-flex gap-2 justify-content-end">
             <a href="index.php" class="btn btn-secondary shadow-sm" onclick="return confirm('Cancel adjustments and head back? Unsaved edits will drop out.');">Cancel</a>
             <button type="submit" class="btn btn-primary px-5 shadow-sm">Save All Changes</button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
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

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.meet-toggle');
        if (!btn) return;
        const targetId = btn.getAttribute('data-target');
        const group = document.getElementById(targetId);
        if (!group) return;
        
        if (group.style.display === 'none') {
            group.style.display = 'block';
            btn.innerHTML = '<i class="fas fa-minus text-danger"></i>';
        } else {
            group.querySelectorAll('input').forEach(i => i.value = '');
            group.style.display = 'none';
            btn.innerHTML = '<i class="fas fa-plus text-success"></i>';
        }
    });

    document.addEventListener('click', function(e){
        if(e.target.closest('.remove-row')) {
            if(confirm('Are you sure you want to remove this sanction record?')) {
                e.target.closest('.facility-card').remove();
            }
        }
    });
</script>
</body>
</html>