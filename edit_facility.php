<?php
session_start();
ob_start();
include 'db.php';
include 'header.php';

if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    echo "<script>alert('Access Denied'); window.location.href='index.php';</script>";
    exit;
}

// ============================================================
// GET THE FACILITY ID FROM URL
// ============================================================
$facility_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($facility_id <= 0) {
    header('Location: index.php');
    exit;
}

// ============================================================
// FIRST, GET THE FACILITY DETAILS TO FIND THE FILE_RECORD_ID
// ============================================================
$facility_stmt = $conn->prepare("SELECT file_record_id, facility_type, amount, sanction_date, sanction_letter_ref_no, 
                                  comm_meet_no, comm_meet_date, board_meet_no, board_meet_date, 
                                  facility_as, power_delegation FROM file_facilities WHERE id = ?");
$facility_stmt->bind_param('i', $facility_id);
$facility_stmt->execute();
$facility_result = $facility_stmt->get_result();
$facility_data = $facility_result->fetch_assoc();
$facility_stmt->close();

if (!$facility_data) {
    $_SESSION['error'] = "Facility not found with ID: " . $facility_id;
    header('Location: index.php');
    exit;
}

// Use the file_record_id for the rest of the operations
$file_record_id = $facility_data['file_record_id'];

// ============================================================
// FETCH MAIN FILE DATA USING THE FILE_RECORD_ID
// ============================================================
$stmt = $conn->prepare('SELECT id, client, branch_name, file_no, client_id FROM office_files WHERE id = ? AND is_deleted = 0');
$stmt->bind_param('i', $file_record_id);
$stmt->execute();
$main_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$main_data) {
    $_SESSION['error'] = "File record not found";
    header('Location: index.php');
    exit;
}

// Get client_id for redirect
$client_id = $main_data['client_id'] ?? 0;

$facility_options = [];
$lookup_res = $conn->query('SELECT facility_name AS facility_type, facility_group FROM facilities_type WHERE is_active = 1 ORDER BY facility_group ASC, facility_name ASC');
if ($lookup_res) {
    while ($row = $lookup_res->fetch_assoc()) {
        $facility_options[] = $row;
    }
}
$facility_option_names = array_column($facility_options, 'facility_type');
$facility_options_html = renderFacilityOptions($facility_options, '');
$facility_as_options = ['Fresh', 'Renewal', 'Time Extension', 'Renewal with Enhancement'];

$sanction_ref_prefix = 'FSIB/HO/INVT/';
$status_message = '';

if (isset($_GET['status'])) {
    if ($_GET['status'] === 'updated') {
        $status_message = '<div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert"><i class="fas fa-check-circle me-2"></i> <strong>Success!</strong> Facilities database configuration has been completely synchronized.<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    } elseif ($_GET['status'] === 'deleted') {
        $status_message = '<div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert"><i class="fas fa-trash-alt me-2"></i> <strong>Deleted!</strong> Selected document file attachment wiped from file records.<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'delete_attachment' && isset($_GET['attach_id'])) {
    $delete_id = intval($_GET['attach_id']);
    $find_sql = 'SELECT file_path FROM attachments WHERE id = ? AND file_record_id = ?';
    $stmt_find = $conn->prepare($find_sql);
    $stmt_find->bind_param('ii', $delete_id, $file_record_id);
    $stmt_find->execute();
    $file_to_del = $stmt_find->get_result()->fetch_assoc();
    $stmt_find->close();

    if ($file_to_del) {
        if (file_exists($file_to_del['file_path'])) {
            @unlink($file_to_del['file_path']);
        }
        $del_sql = 'DELETE FROM attachments WHERE id = ?';
        $stmt_del = $conn->prepare($del_sql);
        $stmt_del->bind_param('i', $delete_id);
        $stmt_del->execute();
        $stmt_del->close();

        header('Location: edit_facility.php?id=' . $facility_id . '&status=deleted');
        exit;
    }
}

// Handle POST - Update facilities
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_modifier_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? null;
    $submitted_facility_ids = [];

    $conn->begin_transaction();
    try {
        if (isset($_POST['row_keys']) && is_array($_POST['row_keys'])) {
            $update_sql = 'UPDATE file_facilities SET sanction_letter_ref_no = ?, sanction_date = ?, facility_type = ?, facility_as = ?, amount = ?, comm_meet_no = ?, comm_meet_date = ?, board_meet_no = ?, board_meet_date = ?, updated_by = ? WHERE id = ?';
            $update_stmt = $conn->prepare($update_sql);

            $insert_sql = 'INSERT INTO file_facilities (file_record_id, user_id, updated_by, sanction_letter_ref_no, sanction_date, facility_type, facility_as, amount, comm_meet_no, comm_meet_date, board_meet_no, board_meet_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $insert_stmt = $conn->prepare($insert_sql);

            foreach ($_POST['row_keys'] as $index => $row_key) {
                $row_date = trim($_POST['f_dates'][$index] ?? '');
                if ($row_date === '') {
                    continue;
                }

                $existing_facility_id = !empty($_POST['facility_ids'][$index]) ? intval($_POST['facility_ids'][$index]) : null;
                $suffix = trim($_POST['f_ref_suffixes'][$index] ?? '');
                $full_ref_no = $sanction_ref_prefix . $suffix;

                $fac_type = trim($_POST['f_types'][$index] ?? 'Others');
                if ($fac_type === 'Others' && !empty($_POST['f_types_other'][$index])) {
                    $fac_type = trim($_POST['f_types_other'][$index]);
                }

                $facility_as = trim($_POST['f_as'][$index] ?? '');

                $amount = floatval($_POST['f_amts'][$index] ?? 0);
                $comm_no = trim($_POST['c_nos'][$index] ?? '');
                $comm_date = trim($_POST['c_dates'][$index] ?? '');
                $board_no = trim($_POST['b_nos'][$index] ?? '');
                $board_date = trim($_POST['b_dates'][$index] ?? '');

                if ($existing_facility_id) {
                    $fetch_current = $conn->prepare('SELECT sanction_letter_ref_no, sanction_date, facility_type, facility_as, amount, comm_meet_no, comm_meet_date, board_meet_no, board_meet_date FROM file_facilities WHERE id = ?');
                    $fetch_current->bind_param('i', $existing_facility_id);
                    $fetch_current->execute();
                    $current_data = $fetch_current->get_result()->fetch_assoc();
                    $fetch_current->close();

                    if ($current_data) {
                        $is_changed = (
                            ($current_data['sanction_letter_ref_no'] ?? '') !== $full_ref_no ||
                            ($current_data['sanction_date'] ?? '') !== $row_date ||
                            ($current_data['facility_type'] ?? '') !== $fac_type ||
                            ($current_data['facility_as'] ?? '') !== $facility_as ||
                            floatval($current_data['amount'] ?? 0) !== $amount ||
                            ($current_data['comm_meet_no'] ?? '') !== $comm_no ||
                            ($current_data['comm_meet_date'] ?? '') !== $comm_date ||
                            ($current_data['board_meet_no'] ?? '') !== $board_no ||
                            ($current_data['board_meet_date'] ?? '') !== $board_date
                        );

                        if ($is_changed) {
                            $update_stmt->bind_param('ssssdssssii', $full_ref_no, $row_date, $fac_type, $facility_as, $amount, $comm_no, $comm_date, $board_no, $board_date, $current_modifier_id, $existing_facility_id);
                            $update_stmt->execute();
                        }

                        $submitted_facility_ids[] = $existing_facility_id;
                    }
                } else {
                    $null_updated_by = null;
                    $insert_stmt->bind_param('iiissssdssss', $file_record_id, $current_modifier_id, $null_updated_by, $full_ref_no, $row_date, $fac_type, $facility_as, $amount, $comm_no, $comm_date, $board_no, $board_date);
                    $insert_stmt->execute();
                    $submitted_facility_ids[] = $insert_stmt->insert_id;
                }
            }

            $update_stmt->close();
            $insert_stmt->close();
        }

        if (!empty($submitted_facility_ids)) {
            $placeholders = implode(',', array_fill(0, count($submitted_facility_ids), '?'));
            $delete_sql = "DELETE FROM file_facilities WHERE file_record_id = ? AND id NOT IN ($placeholders)";
            $delete_stmt = $conn->prepare($delete_sql);
            $bind_params = array_merge([$file_record_id], $submitted_facility_ids);
            $types = 'i' . str_repeat('i', count($submitted_facility_ids));
            $delete_stmt->bind_param($types, ...$bind_params);
            $delete_stmt->execute();
            $delete_stmt->close();
        } else {
            $delete_stmt = $conn->prepare('DELETE FROM file_facilities WHERE file_record_id = ?');
            $delete_stmt->bind_param('i', $file_record_id);
            $delete_stmt->execute();
            $delete_stmt->close();
        }

        $conn->commit();
        header('Location: edit_facility.php?id=' . $facility_id . '&status=updated');
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        die('Failed to process target modifications: ' . htmlspecialchars($e->getMessage()));
    }
}

// Fetch all facilities for this file_record_id
$fac_stmt = $conn->prepare('SELECT * FROM file_facilities WHERE file_record_id = ? ORDER BY sanction_date DESC, sanction_letter_ref_no DESC, id ASC');
$fac_stmt->bind_param('i', $file_record_id);
$fac_stmt->execute();
$facilities_res = $fac_stmt->get_result();
$fac_stmt->close();

$facility_groups = [];
while ($facility_row = $facilities_res->fetch_assoc()) {
    $group_key = trim((string)($facility_row['sanction_letter_ref_no'] ?? '')) . '|' . trim((string)($facility_row['sanction_date'] ?? ''));
    if (!isset($facility_groups[$group_key])) {
        $facility_groups[$group_key] = [
            'sanction_letter_ref_no' => $facility_row['sanction_letter_ref_no'] ?? '',
            'sanction_date' => $facility_row['sanction_date'] ?? '',
            'rows' => []
        ];
    }
    $facility_groups[$group_key]['rows'][] = $facility_row;
}

function renderFacilityOptions(array $facility_options, string $selectedValue): string
{
    $html = '<option value="">-- Select Facility Type --</option>';
    foreach ($facility_options as $opt) {
        $type = htmlspecialchars($opt['facility_type'], ENT_QUOTES, 'UTF-8');
        $group = htmlspecialchars($opt['facility_group'], ENT_QUOTES, 'UTF-8');
        $selected = ($selectedValue === $opt['facility_type']) ? ' selected' : '';
        $html .= '<option value="' . $type . '"' . $selected . '>' . $type . ' (' . $group . ')</option>';
    }
    return $html;
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Facilities - <?php echo htmlspecialchars($main_data['client'] ?? 'Unknown'); ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background: #f4f6f9; }
        .batch-card { border: 0; border-radius: 14px; box-shadow: 0 4px 18px rgba(0,0,0,0.05); overflow: hidden; }
        .batch-header { background: #fff; border-bottom: 1px solid #eef2f7; }
        .table-responsive-custom { width: 100%; overflow-x: auto; }
        .table-responsive-custom table { min-width: 1180px; }
        .attachment-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 10px; padding: 12px; }
        .btn-xs { padding: 0.25rem 0.4rem; font-size: 0.75rem; border-radius: 0.2rem; }
        .facility-group { margin-bottom: 20px; }
    </style>
</head>
<body class="p-4">
<div class="container-fluid" style="max-width: 1500px;">
    <?php echo $status_message; ?>

    <form method="post" action="edit_facility.php?id=<?php echo $facility_id; ?>">
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded shadow-sm">
            <div>
                <h4 class="mb-0 text-primary"><i class="fas fa-edit"></i> Edit Facilities</h4>
                <small class="text-muted">Client: <strong><?php echo htmlspecialchars($main_data['client']); ?></strong></small>
                <br><small class="text-muted">File: <strong><?php echo htmlspecialchars($main_data['file_no'] ?? 'N/A'); ?></strong></small>
            </div>
            <div class="d-flex gap-2">
                <a href="client_profile.php?id=<?php echo $client_id; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-user me-1"></i> Client Profile</a>
                <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-home me-1"></i> Home</a>
            </div>
        </div>

        <?php if (!empty($facility_groups)): ?>
            <?php $groupIndex = 0; ?>
            <?php foreach ($facility_groups as $group): ?>
                <?php
                $group_ref = $group['sanction_letter_ref_no'] ?? '';
                $group_date = $group['sanction_date'] ?? '';
                $group_date_label = !empty($group_date) ? date('d-m-Y', strtotime($group_date)) : 'N/A';
                $attachment_tbody_id = 'attachments-tbody-' . $groupIndex;

                $uploaded_docs = [];
                if (!empty($group_date)) {
                    $att_stmt = $conn->prepare('SELECT id, description, file_path FROM attachments WHERE file_record_id = ? AND sanction_date = ? ORDER BY id ASC');
                    $att_stmt->bind_param('is', $file_record_id, $group_date);
                    $att_stmt->execute();
                    $att_res = $att_stmt->get_result();
                    while ($doc = $att_res->fetch_assoc()) {
                        $uploaded_docs[$doc['description']] = $doc;
                    }
                    $att_stmt->close();
                }

                $core_inputs = [
                    'file_branch_proposal' => 'Branch Proposal',
                    'file_comm_memo' => 'Committee Memo',
                    'file_comm_minutes' => 'Committee Minutes',
                    'file_office_note' => 'Office Note',
                    'file_board_memo' => 'Board Memo',
                    'file_board_minutes' => 'Board Minutes',
                    'file_sanction_letter' => 'Sanction Letter'
                ];

                foreach (array_keys($uploaded_docs) as $desc) {
                    if (!in_array($desc, $core_inputs, true)) {
                        $core_inputs['custom_' . md5($desc)] = $desc;
                    }
                }
                ?>
                <div class="card batch-card facility-group" data-group-index="<?php echo $groupIndex; ?>">
                    <div class="card-header batch-header py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <div class="fw-bold text-dark">
                                <i class="fas fa-file-signature text-primary me-2"></i>
                                Sanction Ref: <span class="text-primary batch-ref-display"><?php echo htmlspecialchars($group_ref ?: 'N/A'); ?></span>
                            </div>
                            <div class="small text-muted font-monospace d-flex flex-wrap align-items-center gap-2 mt-2">
                                <span class="d-inline-flex align-items-center gap-2">
                                    Ref No:
                                    <span class="input-group input-group-sm" style="width:auto; min-width: 300px;">
                                        <span class="input-group-text"><?php echo $sanction_ref_prefix; ?></span>
                                        <input type="text" class="form-control form-control-sm batch-ref-suffix" value="<?php echo htmlspecialchars(preg_replace('/^' . preg_quote($sanction_ref_prefix, '/') . '/', '', $group_ref)); ?>" data-group-index="<?php echo $groupIndex; ?>">
                                    </span>
                                </span>
                                <span class="d-inline-flex align-items-center gap-2">
                                    Date:
                                    <input type="date" class="form-control form-control-sm batch-sanction-date" value="<?php echo htmlspecialchars($group['sanction_date'] ?? ''); ?>" data-group-index="<?php echo $groupIndex; ?>" style="width: auto;">
                                    <span class="badge bg-light text-dark border batch-date-display"><?php echo htmlspecialchars($group_date_label); ?></span>
                                </span>
                                <span class="ms-2">Facilities: <strong class="batch-facility-count"><?php echo count($group['rows']); ?></strong></span>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-success add-facility-row" data-group-index="<?php echo $groupIndex; ?>">
                            <i class="fas fa-plus me-1"></i> Add Facility
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive-custom">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-dark text-uppercase small" style="font-size:0.75rem;">
                                    <tr>
                                        <th class="ps-4" style="width: 26%;">Facility Type</th>
                                        <th style="width: 14%;">Facility As</th>
                                        <th style="width: 12%;">Amount</th>
                                        <th style="width: 18%;">Committee Meeting</th>
                                        <th style="width: 18%;">Board Meeting</th>
                                        <th style="width: 14%;">Reference String</th>
                                        <th class="text-center" style="width: 12%;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="group-rows-<?php echo $groupIndex; ?>">
                                    <?php foreach ($group['rows'] as $rowIndex => $f): ?>
                                        <?php
                                        $existing_ref = $f['sanction_letter_ref_no'] ?? '';
                                        $ref_suffix = $existing_ref;
                                        if (strpos($existing_ref, $sanction_ref_prefix) === 0) {
                                            $ref_suffix = substr($existing_ref, strlen($sanction_ref_prefix));
                                        }
                                        $comm_enabled = (!empty($f['comm_meet_no']) || !empty($f['comm_meet_date']));
                                        $board_enabled = (!empty($f['board_meet_no']) || !empty($f['board_meet_date']));
                                        $is_known_facility = in_array($f['facility_type'], $facility_option_names, true);
                                        $selected_facility_as = trim((string)($f['facility_as'] ?? ''));
                                        ?>
                                        <tr>
                                            <td class="ps-4 py-3">
                                                <input type="hidden" name="row_keys[]" value="<?php echo $groupIndex . '_' . $rowIndex; ?>">
                                                <input type="hidden" name="facility_ids[]" value="<?php echo htmlspecialchars($f['id'] ?? ''); ?>">
                                                <input type="hidden" name="f_ref_suffixes[]" value="<?php echo htmlspecialchars($ref_suffix); ?>">
                                                <input type="hidden" name="f_dates[]" value="<?php echo htmlspecialchars($group_date); ?>">

                                                <select name="f_types[]" class="form-select form-select-sm facility-type-select" required>
                                                    <?php echo renderFacilityOptions($facility_options, (string)($f['facility_type'] ?? '')); ?>
                                                    <option value="Others" <?php echo !$is_known_facility ? 'selected' : ''; ?>>Others</option>
                                                </select>
                                                <input type="text" name="f_types_other[]" class="form-control form-control-sm mt-2 facility-type-other" value="<?php echo !$is_known_facility ? htmlspecialchars($f['facility_type']) : ''; ?>" style="display:<?php echo !$is_known_facility ? 'block' : 'none'; ?>;">
                                            </td>
                                            <td>
                                                <select name="f_as[]" class="form-select form-select-sm" required>
                                                    <option value="">-- Select Facility As --</option>
                                                    <?php foreach ($facility_as_options as $facility_as_option): ?>
                                                        <option value="<?php echo htmlspecialchars($facility_as_option, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($selected_facility_as === $facility_as_option) ? 'selected' : ''; ?>><?php echo htmlspecialchars($facility_as_option); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="number" step="0.01" name="f_amts[]" value="<?php echo htmlspecialchars($f['amount'] ?? 0); ?>" class="form-control form-control-sm fw-bold" required>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-light mb-2 meet-toggle" data-target="comm-<?php echo $groupIndex . '_' . $rowIndex; ?>">
                                                    <i class="fas <?php echo $comm_enabled ? 'fa-minus text-danger' : 'fa-plus text-success'; ?>"></i>
                                                </button>
                                                <div id="comm-<?php echo $groupIndex . '_' . $rowIndex; ?>" style="display:<?php echo $comm_enabled ? 'block' : 'none'; ?>;">
                                                    <input type="text" name="c_nos[]" value="<?php echo htmlspecialchars($f['comm_meet_no'] ?? ''); ?>" class="form-control form-control-sm mb-1" placeholder="Meet No">
                                                    <input type="date" name="c_dates[]" value="<?php echo htmlspecialchars($f['comm_meet_date'] ?? ''); ?>" class="form-control form-control-sm">
                                                </div>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-light mb-2 meet-toggle" data-target="board-<?php echo $groupIndex . '_' . $rowIndex; ?>">
                                                    <i class="fas <?php echo $board_enabled ? 'fa-minus text-danger' : 'fa-plus text-success'; ?>"></i>
                                                </button>
                                                <div id="board-<?php echo $groupIndex . '_' . $rowIndex; ?>" style="display:<?php echo $board_enabled ? 'block' : 'none'; ?>;">
                                                    <input type="text" name="b_nos[]" value="<?php echo htmlspecialchars($f['board_meet_no'] ?? ''); ?>" class="form-control form-control-sm mb-1" placeholder="Meet No">
                                                    <input type="date" name="b_dates[]" value="<?php echo htmlspecialchars($f['board_meet_date'] ?? ''); ?>" class="form-control form-control-sm">
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-dark font-monospace text-warning px-2 py-1 shadow-sm" style="font-size:0.8rem;">
                                                    <i class="fas fa-hashtag me-1"></i><?php echo htmlspecialchars($group_ref ?: $existing_ref); ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fas fa-trash-alt"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="attachment-box m-3 mt-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="text-dark fw-bold small"><i class="fas fa-paperclip text-warning me-1"></i> Linked Files for <?php echo htmlspecialchars($group_date_label); ?></div>
                                <span class="small text-muted">Attachments shown once for this batch</span>
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
                                    <tbody id="<?php echo $attachment_tbody_id; ?>">
                                        <?php foreach ($core_inputs as $input_field => $label_desc): ?>
                                            <?php $doc = $uploaded_docs[$label_desc] ?? null; ?>
                                            <tr>
                                                <td class="fw-bold text-secondary">
                                                    <?php if (strpos($input_field, 'custom_') === 0): ?>
                                                        <span class="badge bg-info text-dark me-1">Custom</span><?php echo htmlspecialchars($label_desc); ?>
                                                    <?php else: ?>
                                                        <?php echo htmlspecialchars($label_desc); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($doc): ?>
                                                        <span class="text-success small d-block text-truncate" style="max-width:280px;">
                                                            <i class="fas fa-check-circle me-1"></i><?php echo htmlspecialchars(basename($doc['file_path'])); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted small">No file attached</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-1">
                                                        <input type="file" name="<?php echo htmlspecialchars($input_field); ?>_<?php echo $groupIndex; ?>" class="form-control form-control-sm" style="font-size:11px;">
                                                        <?php if ($doc): ?>
                                                            <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn btn-xs btn-outline-secondary px-2"><i class="fas fa-eye"></i></a>
                                                            <a href="edit_facility.php?id=<?php echo $facility_id; ?>&action=delete_attachment&attach_id=<?php echo intval($doc['id']); ?>" class="btn btn-xs btn-outline-danger px-2" onclick="return confirm('Delete this attachment permanently?');"><i class="fas fa-trash"></i></a>
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
                <?php $groupIndex++; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-info shadow-sm">No facility records found for this file.</div>
        <?php endif; ?>

        <div class="mt-4 pt-3 border-top d-flex gap-2 justify-content-end">
            <a href="client_profile.php?id=<?php echo $client_id; ?>" class="btn btn-secondary shadow-sm">Back to Client Profile</a>
            <button type="submit" class="btn btn-primary px-5 shadow-sm">Save All Changes</button>
        </div>
    </form>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('change', function(e) {
    if (!e.target.classList.contains('facility-type-select')) return;
    const cell = e.target.closest('td') || e.target.parentElement;
    const otherInput = cell ? cell.querySelector('.facility-type-other') : null;
    if (!otherInput) return;

    if (e.target.value === 'Others') {
        otherInput.style.display = 'block';
        otherInput.required = true;
    } else {
        otherInput.style.display = 'none';
        otherInput.required = false;
        otherInput.value = '';
    }
});

document.addEventListener('click', function(e) {
    const addFacilityBtn = e.target.closest('.add-facility-row');
    if (addFacilityBtn) {
        const groupIndex = addFacilityBtn.getAttribute('data-group-index');
        const groupCard = addFacilityBtn.closest('.batch-card');
        const suffixField = groupCard ? groupCard.querySelector('.batch-ref-suffix') : null;
        const dateField = groupCard ? groupCard.querySelector('.batch-sanction-date') : null;
        const groupDate = dateField ? dateField.value : '';
        const groupRef = '<?php echo $sanction_ref_prefix; ?>' + (suffixField ? suffixField.value.trim() : '');
        const tbody = document.querySelector('#group-rows-' + groupIndex);
        if (!tbody) return;

        const rowCount = tbody.querySelectorAll('tr').length;
        const refSuffix = suffixField ? suffixField.value.trim() : '';
        const facilityRow = document.createElement('tr');
        facilityRow.innerHTML = `
            <td class="ps-4 py-3">
                <input type="hidden" name="row_keys[]" value="new_${groupIndex}_${rowCount}">
                <input type="hidden" name="facility_ids[]" value="">
                <input type="hidden" name="f_ref_suffixes[]" value="${refSuffix.replace(/"/g, '&quot;')}">
                <input type="hidden" name="f_dates[]" value="${groupDate.replace(/"/g, '&quot;')}">
                <select name="f_types[]" class="form-select form-select-sm facility-type-select" required>
                    ${<?php echo json_encode($facility_options_html); ?>}
                    <option value="Others">Others</option>
                </select>
                <input type="text" name="f_types_other[]" class="form-control form-control-sm mt-2 facility-type-other" style="display:none;">
            </td>
            <td>
                <select name="f_as[]" class="form-select form-select-sm" required>
                    <option value="">-- Select Facility As --</option>
                    <?php foreach ($facility_as_options as $facility_as_option): ?>
                        <option value="<?php echo htmlspecialchars($facility_as_option, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($facility_as_option); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="number" step="0.01" name="f_amts[]" class="form-control form-control-sm fw-bold" required></td>
            <td>
                <button type="button" class="btn btn-sm btn-light mb-2 meet-toggle" data-target="comm-new_${groupIndex}_${rowCount}"><i class="fas fa-plus text-success"></i></button>
                <div id="comm-new_${groupIndex}_${rowCount}" style="display:none;">
                    <input type="text" name="c_nos[]" class="form-control form-control-sm mb-1" placeholder="Meet No">
                    <input type="date" name="c_dates[]" class="form-control form-control-sm">
                </div>
            </td>
            <td>
                <button type="button" class="btn btn-sm btn-light mb-2 meet-toggle" data-target="board-new_${groupIndex}_${rowCount}"><i class="fas fa-plus text-success"></i></button>
                <div id="board-new_${groupIndex}_${rowCount}" style="display:none;">
                    <input type="text" name="b_nos[]" class="form-control form-control-sm mb-1" placeholder="Meet No">
                    <input type="date" name="b_dates[]" class="form-control form-control-sm">
                </div>
            </td>
            <td><span class="badge bg-dark font-monospace text-warning px-2 py-1 shadow-sm" style="font-size:0.8rem;"><i class="fas fa-hashtag me-1"></i>${groupRef || 'N/A'}</span></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fas fa-trash-alt"></i></button></td>
        `;
        tbody.appendChild(facilityRow);
        return;
    }

    const meetBtn = e.target.closest('.meet-toggle');
    if (meetBtn) {
        const targetId = meetBtn.getAttribute('data-target');
        const group = document.getElementById(targetId);
        if (!group) return;
        if (group.style.display === 'none') {
            group.style.display = 'block';
            meetBtn.innerHTML = '<i class="fas fa-minus text-danger"></i>';
        } else {
            group.querySelectorAll('input').forEach(function(input) { input.value = ''; });
            group.style.display = 'none';
            meetBtn.innerHTML = '<i class="fas fa-plus text-success"></i>';
        }
        return;
    }

    if (e.target.closest('.remove-row')) {
        if (confirm('Are you sure you want to remove this facility record?')) {
            e.target.closest('tr').remove();
        }
    }
});

document.addEventListener('input', function(e) {
    if (!e.target.classList.contains('batch-ref-suffix') && !e.target.classList.contains('batch-sanction-date')) {
        return;
    }

    const groupIndex = e.target.getAttribute('data-group-index');
    const groupCard = document.querySelector('.batch-card[data-group-index="' + groupIndex + '"]');
    if (!groupCard) return;

    const suffixField = groupCard.querySelector('.batch-ref-suffix');
    const dateField = groupCard.querySelector('.batch-sanction-date');
    const refValue = '<?php echo $sanction_ref_prefix; ?>' + (suffixField ? suffixField.value.trim() : '');
    const dateValue = dateField ? dateField.value : '';

    groupCard.querySelectorAll('input[name="f_ref_suffixes[]"]').forEach(function(input) {
        input.value = suffixField ? suffixField.value.trim() : '';
    });
    groupCard.querySelectorAll('input[name="f_dates[]"]').forEach(function(input) {
        input.value = dateValue;
    });

    const refBadge = groupCard.querySelector('.batch-ref-display');
    if (refBadge && e.target.classList.contains('batch-ref-suffix')) {
        refBadge.textContent = refValue;
    }

    const dateLabel = groupCard.querySelector('.batch-sanction-date + .batch-date-display');
    if (dateLabel && e.target.classList.contains('batch-sanction-date')) {
        dateLabel.textContent = dateValue ? dateValue.split('-').reverse().join('-') : 'N/A';
    }
});

document.addEventListener('submit', function(e) {
    if (e.target.tagName !== 'FORM') return;
    e.target.querySelectorAll('.batch-card').forEach(function(groupCard) {
        const suffixField = groupCard.querySelector('.batch-ref-suffix');
        const dateField = groupCard.querySelector('.batch-sanction-date');
        if (!suffixField || !dateField) return;

        const suffixValue = suffixField.value.trim();
        const dateValue = dateField.value;

        groupCard.querySelectorAll('input[name="f_ref_suffixes[]"]').forEach(function(input) {
            input.value = suffixValue;
        });
        groupCard.querySelectorAll('input[name="f_dates[]"]').forEach(function(input) {
            input.value = dateValue;
        });
    });
}, true);
</script>
<?php include 'footer.php'; ?>
</body>
</html>