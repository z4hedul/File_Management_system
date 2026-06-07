<?php
session_start();
include 'db.php';
include 'header.php';
// 1. Security & Admin Check
if (!isset($_SESSION['loggedin'])) { header("location: login.php"); exit; }
if ($_SESSION['role'] !== 'admin') {
    echo "<script>alert('Access Denied'); window.location.href='index.php';</script>";
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : null; // The Main File ID
if (!$id) { header("location: index.php"); exit; }

// Fetch Main Client Info
$stmt = $conn->prepare("SELECT client, branch_name FROM office_files WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$main_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$main_data) { header("location: index.php"); exit; }

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
        
        header("Location: edit_facility.php?id=" . $id . "&status=deleted");
        exit;
    }
}

// 2. Fetch All Facilities for Initial View
$fac_stmt = $conn->prepare("SELECT * FROM file_facilities WHERE file_record_id = ? ORDER BY sanction_date DESC");
$fac_stmt->bind_param("i", $id);
$fac_stmt->execute();
$facilities = $fac_stmt->get_result();

// 3. Update / Upload Logic
// 3. Precision Update / Upload Logic (Sanction-by-Sanction tracking)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $upload_dir = "uploads/";
    if (!is_dir($upload_dir)) { 
        mkdir($upload_dir, 0777, true); 
    }

    $current_modifier_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? null;

    // Begin Database Transaction
    $conn->begin_transaction();

    try {
        // Collect all facility row IDs submitted from the form to know what to keep
        $submitted_facility_ids = [];

        if (isset($_POST['row_keys']) && is_array($_POST['row_keys'])) {
            
            // Prepared statements for precision row handling
            $update_sql = "UPDATE file_facilities SET 
                            sanction_letter_ref_no = ?, 
                            sanction_date = ?, 
                            facility_type = ?, 
                            amount = ?, 
                            comm_meet_no = ?, 
                            comm_meet_date = ?, 
                            board_meet_no = ?, 
                            board_meet_date = ?,
                            updated_by = ?
                           WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);

            $insert_sql = "INSERT INTO file_facilities 
                (file_record_id, user_id, updated_by, sanction_letter_ref_no, sanction_date, facility_type, amount, comm_meet_no, comm_meet_date, board_meet_no, board_meet_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);

            foreach ($_POST['row_keys'] as $index => $row_key) {
                $row_date = $_POST['f_dates'][$index] ?? '';
                if (empty($row_date)) continue;

                // Extract individual database table row ID (if it exists for this row)
                $existing_facility_id = !empty($_POST['facility_ids'][$index]) ? intval($_POST['facility_ids'][$index]) : null;

                $suffix = $_POST['f_ref_suffixes'][$index] ?? '';
                $full_ref_no = $sanction_ref_prefix . $suffix;

                $fac_type = $_POST['f_types'][$index] ?? 'Others';
                if ($fac_type === 'Others' && !empty($_POST['f_types_other'][$index])) {
                    $fac_type = $_POST['f_types_other'][$index];
                }

                $amount = floatval($_POST['f_amts'][$index] ?? 0);
                $comm_no = !empty($_POST['c_nos'][$index]) ? $_POST['c_nos'][$index] : null;
                $comm_date = !empty($_POST['c_dates'][$index]) ? $_POST['c_dates'][$index] : null;
                $board_no = !empty($_POST['b_nos'][$index]) ? $_POST['b_nos'][$index] : null;
                $board_date = !empty($_POST['b_dates'][$index]) ? $_POST['b_dates'][$index] : null;

                if ($existing_facility_id) {
                    // ---------------------------------------------------------------
                    // CASE A: EXISTING SANCTION ROW - Check if modifications happened
                    // ---------------------------------------------------------------
                    $fetch_current = $conn->prepare("SELECT * FROM file_facilities WHERE id = ?");
                    $fetch_current->bind_param("i", $existing_facility_id);
                    $fetch_current->execute();
                    $current_data = $fetch_current->get_result()->fetch_assoc();
                    $fetch_current->close();

                    if ($current_data) {
                        // Compare submitted inputs against current database states
                        $is_changed = (
                            $current_data['sanction_letter_ref_no'] !== $full_ref_no ||
                            $current_data['sanction_date'] !== $row_date ||
                            $current_data['facility_type'] !== $fac_type ||
                            floatval($current_data['amount']) !== $amount ||
                            $current_data['comm_meet_no'] !== $comm_no ||
                            $current_data['comm_meet_date'] !== $comm_date ||
                            $current_data['board_meet_no'] !== $board_no ||
                            $current_data['board_meet_date'] !== $board_date
                        );

                        if ($is_changed) {
                            // Update row details and assign the CURRENT admin modifier ID
                            $update_stmt->bind_param(
                                "sssdssssii", 
                                $full_ref_no, $row_date, $fac_type, $amount, 
                                $comm_no, $comm_date, $board_no, $board_date, 
                                $current_modifier_id, $existing_facility_id
                            );
                            $update_stmt->execute();
                        }
                        // Track this ID so it is preserved (not deleted)
                        $submitted_facility_ids[] = $existing_facility_id;
                    }
                } else {
                    // ---------------------------------------------------------------
                    // CASE B: NEW SANCTION ROW ADDED IN FORM
                    // ---------------------------------------------------------------
                    // Sanctioned By is set to current user, Updated By stays NULL initially
                    $null_updated_by = null; 
                    $insert_stmt->bind_param(
                        "iiisssdssss", 
                        $id, $current_modifier_id, $null_updated_by, $full_ref_no, $row_date, $fac_type, $amount, 
                        $comm_no, $comm_date, $board_no, $board_date
                    );
                    $insert_stmt->execute();
                    
                    // Track newly generated row ID
                    $submitted_facility_ids[] = $insert_stmt->insert_id;
                }

                // --- (Keep your existing Attachment processing code right here unchanged) ---
                // ...
            }
            
            $update_stmt->close();
            $insert_stmt->close();
        }

        // -------------------------------------------------------------------------
        // CLEANUP STEP: Delete only sanctions that the user manually removed from the form UI
        // -------------------------------------------------------------------------
        if (!empty($submitted_facility_ids)) {
            // Build placeholders for IDs to keep
            $placeholders = implode(',', array_fill(0, count($submitted_facility_ids), '?'));
            $delete_sql = "DELETE FROM file_facilities WHERE file_record_id = ? AND id NOT IN ($placeholders)";
            $delete_stmt = $conn->prepare($delete_sql);
            
            // Dynamic parameter mapping binding values sequence array
            $bind_params = array_merge([$id], $submitted_facility_ids);
            $types = "i" . str_repeat("i", count($submitted_facility_ids));
            $delete_stmt->bind_param($types, ...$bind_params);
            $delete_stmt->execute();
            $delete_stmt->close();
        } else {
            // If form was submitted completely cleared, drop all entries for file record id
            $delete_stmt = $conn->prepare("DELETE FROM file_facilities WHERE file_record_id = ?");
            $delete_stmt->bind_param("i", $id);
            $delete_stmt->execute();
            $delete_stmt->close();
        }

        $conn->commit();
        header("Location: edit_facility.php?id=" . $id . "&status=updated");
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        echo "Failed to process target modifications: " . htmlspecialchars($e->getMessage());
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Facilities - <?php echo htmlspecialchars($main_data['client'] ?? 'Unknown'); ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        .facility-card { border-left: 5px solid #0d6efd; background: #fff; margin-bottom: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        label { font-size: 0.75rem; text-transform: uppercase; color: #6c757d; font-weight: bold; }
        .input-group-text { background: #e7f1ff; border-color: #b8daff; color: #094b96; font-weight: 700; }
        .attachment-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 12px; }
        .btn-xs { padding: 0.25rem 0.4rem; font-size: 0.75rem; border-radius: 0.2rem; }
    </style>
</head>
<body class="bg-light p-4">

<div class="container" style="max-width: 1100px;">

    <?php if (isset($_GET['status'])): ?>
        <?php if ($_GET['status'] === 'updated'): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                <i class="fas fa-check-circle me-2"></i> <strong>Success!</strong> Facilities database configuration has been completely synchronized.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($_GET['status'] === 'deleted'): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                <i class="fas fa-trash-alt me-2"></i> <strong>Deleted!</strong> Selected document file attachment wiped from file records.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="post" action="edit_facility.php?id=<?php echo $id; ?>" enctype="multipart/form-data">
        
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded shadow-sm">
            <div>
                <h4 class="mb-0 text-primary"><i class="fas fa-edit"></i> Edit Pipeline Facilities Matrix</h4>
                <small class="text-muted">Client: <strong><?php echo htmlspecialchars($main_data['client']); ?></strong> (Branch: <?php echo htmlspecialchars($main_data['branch_name']); ?>)</small>
            </div>
            <div class="d-flex gap-2">
                <button type="button" id="add-new-row" class="btn btn-sm btn-success"><i class="fas fa-plus me-1"></i> Add Facility Row</button>
                <a href="index.php" class="btn btn-sm btn-outline-secondary" onclick="return confirm('Exit operational workbench? Unsaved inputs will drop out.');">
                    <i class="fas fa-home me-1"></i> Home
                </a>
            </div>
        </div>

        <div id="facility-editor">
            <?php 
            $rowIndex = 0; 
            if ($facilities && $facilities->num_rows > 0): 
                while($f = $facilities->fetch_assoc()): 
                    $current_row_date = $f['sanction_date'];
            ?>
                <div class="card facility-card">
                    <input type="hidden" name="row_keys[]" value="<?php echo $rowIndex; ?>">
                    <input type="hidden" name="facility_ids[]" value="<?php echo htmlspecialchars($f['id'] ?? ''); ?>">
                    
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
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="text-dark fw-bold small"><i class="fas fa-paperclip text-warning me-1"></i> Linked Files (Sanction Date: <?php echo date('d-m-Y', strtotime($current_row_date)); ?>)</div>
                                <button type="button" class="btn btn-xs btn-success add-custom-doc-btn" data-row-id="<?= $rowIndex ?>"><i class="fas fa-plus me-1"></i> Add Custom File Field</button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered m-0 bg-white align-middle" style="font-size:0.85rem;">
                                    <thead class="table-light text-muted">
                                        <tr>
                                            <th style="width:30%;">Document Label Name</th>
                                            <th style="width:40%;">Current File</th>
                                            <th style="width:30%;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="attachments-tbody-<?= $rowIndex ?>">
                                        <?php
                                        $uploaded_docs = [];
                                        $all_found_docs = [];

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
                                            $all_found_docs[] = $row_file['description'];
                                        }
                                        $stmt_att_fetch->close();

                                        $core_inputs = [
                                            'file_office_note'     => 'Office Note',
                                            'file_comm_memo'       => 'Committee Memo',
                                            'file_comm_minutes'    => 'Committee Minutes',
                                            'file_board_memo'      => 'Board Memo',
                                            'file_board_minutes'   => 'Board Minutes',
                                            'file_sanction_letter' => 'Sanction Letter'
                                        ];

                                        foreach($all_found_docs as $db_desc) {
                                            if(!in_array($db_desc, $core_inputs)) {
                                                $core_inputs['custom_stored_' . md5($db_desc)] = $db_desc;
                                            }
                                        }

                                        $custom_field_counter = 0;
                                        foreach ($core_inputs as $input_field => $label_desc):
                                            $has_file = isset($uploaded_docs[$label_desc]);
                                            $is_custom = (strpos($input_field, 'custom_stored_') === 0);
                                        ?>
                                            <tr>
                                                <td class="fw-bold text-secondary">
                                                    <?php if($is_custom): ?>
                                                        <input type="hidden" name="custom_doc_labels_<?= $rowIndex ?>[]" value="<?= htmlspecialchars($label_desc) ?>">
                                                        <span class="badge bg-info text-dark me-1">Custom</span><?= htmlspecialchars($label_desc); ?>
                                                    <?php else: ?>
                                                        <?php echo $label_desc; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($has_file): ?>
                                                        <span class="text-success small d-block text-truncate" style="max-width:280px;">
                                                            <i class="fas fa-check-circle me-1"></i><?php echo htmlspecialchars(basename($uploaded_docs[$label_desc]['path'])); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted small">No file attached</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-1">
                                                        <?php 
                                                            if($is_custom) {
                                                                $input_rendered_name = "custom_doc_file_" . $rowIndex . "_" . $custom_field_counter;
                                                                $custom_field_counter++;
                                                            } else {
                                                                $input_rendered_name = $input_field . '_' . $rowIndex;
                                                            }
                                                        ?>
                                                        <input type="file" name="<?= $input_rendered_name ?>" class="form-control form-control-sm" style="font-size:11px;">
                                                        <?php if ($has_file): ?>
                                                            <a href="<?php echo htmlspecialchars($uploaded_docs[$label_desc]['path']); ?>" target="_blank" class="btn btn-xs btn-outline-secondary px-2"><i class="fas fa-eye"></i></a>
                                                            <a href="edit_facility.php?id=<?php echo $id; ?>&action=delete_attachment&attach_id=<?php echo $uploaded_docs[$label_desc]['id']; ?>" class="btn btn-xs btn-outline-danger px-2" onclick="return confirm('Delete this attachment permanently?');"><i class="fas fa-trash"></i></a>
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
             <a href="index.php" class="btn btn-secondary shadow-sm" onclick="return confirm('Cancel adjustments and exit?');">Cancel</a>
             <button type="submit" class="btn btn-primary px-5 shadow-sm">Save All Changes</button>
        </div>
    </form>
</div>

<script src="style/js/bootstrap.bundle.min.js"></script>

<script>
    let rowIndexCount = <?php echo $rowIndex; ?>;

    // Add Dynamic Facility Rows
    document.getElementById('add-new-row').addEventListener('click', function() {
        const wrapper = document.getElementById('facility-editor');
        const card = document.createElement('div');
        card.className = 'card facility-card';
        card.innerHTML = `
            <input type="hidden" name="row_keys[]" value="${rowIndexCount}">
            <input type="hidden" name="facility_ids[]" value="">
            
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label>Sanction Letter Ref No</label>
                        <div class="input-group">
                            <span class="input-group-text">${<?php echo json_encode($sanction_ref_prefix); ?>}</span>
                            <input type="text" name="f_ref_suffixes[]" class="form-control form-control-sm" required>
                        </div>
                        <div class="mt-2">
                            <label>Sanction Date</label>
                            <input type="date" name="f_dates[]" class="form-control form-control-sm border-primary" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label>Facility Type</label>
                        <select name="f_types[]" class="form-control form-control-sm facility-type-select" required>
                            <option value="L/C (C2C)">L/C (C2C)</option>
                            <option value="L/C Limit">L/C Limit</option>
                            <option value="BG (C2C)">BG (C2C)</option>
                            <option value="BG (Limit)">BG (Limit)</option>
                            <option value="Others">Others</option>
                        </select>
                        <input type="text" name="f_types_other[]" class="form-control form-control-sm mt-2 facility-type-other" style="display:none;">
                        <div class="mt-2">
                            <label>Amount</label>
                            <input type="number" step="0.01" name="f_amts[]" class="form-control form-control-sm fw-bold" required>
                        </div>
                    </div>
                    <div class="col-md-3 border-start">
                        <div class="d-flex align-items-center mb-1">
                            <label class="text-info mb-0">Committee Meeting</label>
                            <button type="button" class="btn btn-sm btn-light ms-auto meet-toggle" data-target="comm-${rowIndexCount}">
                                <i class="fas fa-plus text-success"></i>
                            </button>
                        </div>
                        <div id="comm-${rowIndexCount}" style="display:none;">
                            <input type="text" name="c_nos[]" class="form-control form-control-sm mb-1" placeholder="Meet No">
                            <input type="date" name="c_dates[]" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div class="col-md-2 border-start">
                        <div class="d-flex align-items-center mb-1">
                            <label class="text-success mb-0">Board Meeting</label>
                            <button type="button" class="btn btn-sm btn-light ms-auto meet-toggle" data-target="board-${rowIndexCount}">
                                <i class="fas fa-plus text-success"></i>
                            </button>
                        </div>
                        <div id="board-${rowIndexCount}" style="display:none;">
                            <input type="text" name="b_nos[]" class="form-control form-control-sm mb-1" placeholder="Meet No">
                            <input type="date" name="b_dates[]" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div class="col-md-1 d-flex align-items-center justify-content-center border-start">
                        <button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fas fa-trash-alt"></i></button>
                    </div>
                </div>
                <div class="attachment-box mt-3">
                    <div class="text-muted small italic"><i class="fas fa-info-circle me-1"></i> Attachments can be managed here after setting a valid Sanction Date and saving changes.</div>
                </div>
            </div>
        `;
        wrapper.appendChild(card);
        rowIndexCount++;
    });

    // Add Dynamic Custom Document Fields
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('add-custom-doc-btn') || e.target.closest('.add-custom-doc-btn')) {
            const targets = e.target.classList.contains('add-custom-doc-btn') ? e.target : e.target.closest('.add-custom-doc-btn');
            const rowId = targets.getAttribute('data-row-id');
            const targetTbody = document.getElementById('attachments-tbody-' + rowId);
            
            if (targetTbody) {
                const customDocName = prompt("Enter your custom document label:");
                if (!customDocName || customDocName.trim() === "") return;

                const currentFieldsCount = targetTbody.querySelectorAll('tr').length;
                
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="fw-bold text-secondary">
                        <input type="hidden" name="custom_doc_labels_${rowId}[]" value="${customDocName.replace(/"/g, '&quot;')}">
                        <span class="badge bg-success text-white me-1">New</span>${customDocName}
                    </td>
                    <td><span class="text-warning small"><i class="fas fa-clock me-1"></i> Pending Upload</span></td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <input type="file" name="custom_doc_file_${rowId}_${currentFieldsCount}" class="form-control form-control-sm" style="font-size:11px;" required>
                            <button type="button" class="btn btn-xs btn-outline-danger px-2 remove-custom-row-ui"><i class="fas fa-times"></i></button>
                        </div>
                    </td>
                `;
                targetTbody.appendChild(tr);
            }
        }
        
        if(e.target.closest('.remove-custom-row-ui')) {
            e.target.closest('tr').remove();
        }
    });

    // Facility Type Selection Observer
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

    // Committee / Board Meeting Panel Toggles
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

    // Remove Facility Card Row
    document.addEventListener('click', function(e){
        if(e.target.closest('.remove-row')) {
            if(confirm('Are you sure you want to remove this facility record?')) {
                e.target.closest('.facility-card').remove();
            }
        }
    });
</script>
<?php include 'footer.php'; ?>
</body>
</html>