<?php
session_start();
include 'db.php';
include 'header.php';

if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

$session_user_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? null;

// ================= FLEXIBLE PARAMETER FALLBACK DETECTION =================
if (isset($_GET['file_id'])) {
    $id = intval($_GET['file_id']);
} elseif (isset($_GET['id'])) {
    $id = intval($_GET['id']);
} else {
    $id = 0;
}

if ($id <= 0) {
    header("Location: index.php");
    exit;
}

// Fetch master record information using our parsed identifier variable
$stmt = $conn->prepare("SELECT client, file_no FROM office_files WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$main_data = $stmt->get_result()->fetch_assoc();

if (!$main_data) {
    header("Location: index.php");
    exit;
}

// Fetch Dynamic Lookup options from lookup database table for select menu dropdown engine
$facility_options = [];
$lookup_res = $conn->query("SELECT facility_name AS facility_type, facility_group FROM facilities_type WHERE is_active = 1 ORDER BY facility_group ASC, facility_name ASC");
if ($lookup_res && $lookup_res->num_rows > 0) {
    while ($row = $lookup_res->fetch_assoc()) {
        $facility_options[] = $row;
    }
}

$facility_as_options = ['Fresh', 'Renewal', 'Time Extension', 'Renewal with Enhancement'];
$power_delegation_options = ['Board', 'MD'];

function renderFacilityAsOptions(array $options, string $selectedValue = ''): string
{
    $html = '<option value="">-- Select Facility As --</option>';
    foreach ($options as $option) {
        $selected = ($selectedValue === $option) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($option, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($option) . '</option>';
    }
    return $html;
}

function renderPowerDelegationOptions(array $options, string $selectedValue = ''): string
{
    $html = '<option value="">-- Select Approval By --</option>';
    foreach ($options as $option) {
        $selected = ($selectedValue === $option) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($option, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($option) . '</option>';
    }
    return $html;
}

$client_name = $main_data['client'] ?? 'Unknown_Client';
$sanction_ref_prefix = 'FSIB/HO/INVT/';
$status_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Shared Data for this specific entry session
    $f_date = $_POST['sanction_date'];
    $ref_suffix = trim($_POST['sanction_letter_ref_no_suffix'] ?? '');
    $s_ref   = $sanction_ref_prefix . $ref_suffix;

    $c_no   = !empty($_POST['comm_meet_no']) ? $_POST['comm_meet_no'] : null;
    $c_date = !empty($_POST['comm_meet_date']) ? $_POST['comm_meet_date'] : null;
    $b_no   = !empty($_POST['board_meet_no']) ? $_POST['board_meet_no'] : null;
    $b_date = !empty($_POST['board_meet_date']) ? $_POST['board_meet_date'] : null;

    $last_facility_id = null;

    // Start transaction safety layer
    $conn->begin_transaction();

    try {
        // 1. Insert Facilities First
        if (isset($_POST['facility_types'])) {
            foreach ($_POST['facility_types'] as $key => $type) {
                if (!empty($type)) {
                    $amt = $_POST['sanction_amounts'][$key];
                    $facility_as = trim($_POST['facility_as'][$key] ?? '');
                    $power_delegation = trim($_POST['power_delegation'][$key] ?? '');
                    $facility_group = 'General';

                    // Handle "Others" logic
                    if ($type === 'Others') {
                        $type = trim($_POST['facility_types_other'][$key] ?? '');
                        $facility_group = trim($_POST['facility_groups_other'][$key] ?? '');
                        
                        // Logic to insert into facilities_type table if not exists...
                        $chk_lookup = $conn->prepare("SELECT id FROM facilities_type WHERE facility_name = ?");
                        $chk_lookup->bind_param("s", $type);
                        $chk_lookup->execute();
                        if ($chk_lookup->get_result()->num_rows === 0 && !empty($type)) {
                            $ins_lookup = $conn->prepare("INSERT INTO facilities_type (facility_name, facility_group, is_active) VALUES (?, ?, 1)");
                            $ins_lookup->bind_param("ss", $type, $facility_group);
                            $ins_lookup->execute();
                            $ins_lookup->close();
                        }
                        $chk_lookup->close();
                    } else {
                        // Fetch group from options
                        foreach ($facility_options as $opt) {
                            if ($opt['facility_type'] === $type) {
                                $facility_group = $opt['facility_group'];
                                break;
                            }
                        }
                    }
                    
                    // PREPARE THE INSERT with power_delegation column
                    $ins_stmt = $conn->prepare("INSERT INTO file_facilities 
                        (file_record_id, user_id, facility_type, facility_as, facility_group, amount, 
                         sanction_date, sanction_letter_ref_no, comm_meet_no, comm_meet_date, 
                         board_meet_no, board_meet_date, power_delegation) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    
                    $ins_stmt->bind_param("iisssdsssssss", $id, $session_user_id, $type, 
                                          $facility_as, $facility_group, $amt, $f_date, $s_ref, 
                                          $c_no, $c_date, $b_no, $b_date, $power_delegation);
                    $ins_stmt->execute();
                    $ins_stmt->close();
                }
            }
        }

        // 2. Handle File Uploads (Standard Workflow + Custom Ad-Hoc Attachments)
        $upload_dir = "uploads/";
        if (!is_dir($upload_dir)) { 
            mkdir($upload_dir, 0777, true); 
        }

        // Helper closure engine payload execution task function
        $process_upload = function($file_array, $description) use ($id, $f_date, $client_name, $upload_dir, $conn) {
            if (!empty($file_array['name'])) {
                $ext = pathinfo($file_array['name'], PATHINFO_EXTENSION);
                $raw_filename = $client_name . '-' . $description . '-' . $f_date;
                $clean_filename = preg_replace("/[^a-zA-Z0-9_-]/", "_", $raw_filename);
                $final_name = time() . '_' . $clean_filename . '.' . $ext;
                $target_filepath = $upload_dir . $final_name;
                
                if (move_uploaded_file($file_array['tmp_name'], $target_filepath)) {
                    $attach_sql = "INSERT INTO attachments (file_record_id, sanction_date, file_path, description) VALUES (?, ?, ?, ?)";
                    $attach_stmt = $conn->prepare($attach_sql);
                    $attach_stmt->bind_param("isss", $id, $f_date, $target_filepath, $description);
                    $attach_stmt->execute();
                    $attach_stmt->close();
                }
            }
        };

        $document_map = [
            'file_branch_proposal' => 'Branch Proposal',
            'file_comm_memo'       => 'Committee Memo',
            'file_comm_minutes'    => 'Committee Minutes',
            'file_office_note'     => 'Office Note',
            'file_board_memo'      => 'Board Memo',
            'file_board_minutes'   => 'Board Minutes',
            'file_sanction_letter' => 'Sanction Letter'
        ];

        foreach ($document_map as $input_name => $description) {
            if (isset($_FILES[$input_name])) {
                $process_upload($_FILES[$input_name], $description);
            }
        }

        if (isset($_FILES['custom_attachments']) && isset($_POST['custom_descriptions'])) {
            foreach ($_FILES['custom_attachments']['name'] as $index => $name) {
                if (!empty($name)) {
                    $custom_desc = trim($_POST['custom_descriptions'][$index]);
                    if (empty($custom_desc)) {
                        $custom_desc = "Additional Document " . ($index + 1);
                    }
                    $custom_file_block = [
                        'name'     => $_FILES['custom_attachments']['name'][$index],
                        'type'     => $_FILES['custom_attachments']['type'][$index],
                        'tmp_name' => $_FILES['custom_attachments']['tmp_name'][$index],
                        'error'    => $_FILES['custom_attachments']['error'][$index],
                        'size'     => $_FILES['custom_attachments']['size'][$index]
                    ];
                    $process_upload($custom_file_block, $custom_desc);
                }
            }
        }

        // Commit operations if everything executes error-free
        echo '<script>window.location.href = "more_details.php?id=' . $id . '&status=added";</script>';
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $status_message = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}
?>
<style>
    .btn-hover-custom {
        transition: all 0.3s ease;
        border: none;
        border-radius: 5px;
    }
    .btn-hover-custom:hover {
        background-color: #ffca2c;
        color: #000;
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(255, 193, 7, 0.4);
    }
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
    .btn-hover-custom:hover i {
        animation: pulse 1s infinite;
    }
    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.2); }
        100% { transform: scale(1); }
    }
    /* Amount to Words Display Styles */
    .amount-word-display {
        font-size: 0.7rem;
        background: #e8f5e9;
        padding: 5px 8px;
        border-radius: 6px;
        margin-top: 5px;
        color: #2e7d32;
        font-weight: 500;
        border-left: 3px solid #4caf50;
    }
    .amount-word-display i {
        margin-right: 4px;
        color: #4caf50;
        font-size: 0.7rem;
    }
    .amount-input-wrapper {
        position: relative;
    }
</style>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Sanction & Meetings</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body class="bg-light p-5">

<div class="container bg-white p-4 shadow rounded" style="max-width: 950px;">
    <h4 class="text-primary mb-4"><i class="fas fa-file-signature"></i> Add New Sanction & Approval</h4>
    
    <?php echo $status_message; ?>

    <div class="alert alert-secondary py-2">
        <strong>Client:</strong> <?php echo htmlspecialchars($main_data['client'] ?? 'N/A'); ?> | <strong>File:</strong> <?php echo htmlspecialchars($main_data['file_no'] ?? 'N/A'); ?>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <div class="card mb-4 border-primary shadow-sm">
            <div class="card-header bg-primary text-white fw-bold small">APPROVAL & DATE DETAILS</div>
            <div class="card-body bg-light">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Sanction Letter Ref No</label>
                        <div class="input-group">
                            <span class="input-group-text"><?php echo $sanction_ref_prefix; ?></span>
                            <input type="text" name="sanction_letter_ref_no_suffix" class="form-control border-primary ref-suffix-input" placeholder="Enter reference suffix" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Sanction Date</label>
                        <input type="date" name="sanction_date" class="form-control border-primary" required>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex align-items-center">
                            <label class="form-label fw-bold mb-0">Comm. Meet No & Date</label>
                            <button type="button" id="comm-toggle" class="btn btn-sm btn-success ms-2" title="Enable/Disable Comm. Meeting"><i class="fas fa-plus"></i></button>
                        </div>
                        <div id="comm-meet-group" style="display:none;" class="mt-2">
                            <input type="text" id="comm_meet_no" name="comm_meet_no" class="form-control mb-1" placeholder="Meet No">
                            <input type="date" id="comm_meet_date" name="comm_meet_date" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex align-items-center">
                            <label class="form-label fw-bold mb-0">Board Meet No & Date</label>
                            <button type="button" id="board-toggle" class="btn btn-sm btn-success ms-2" title="Enable/Disable Board Meeting"><i class="fas fa-plus"></i></button>
                        </div>
                        <div id="board-meet-group" style="display:none;" class="mt-2">
                            <input type="text" id="board_meet_no" name="board_meet_no" class="form-control mb-1" placeholder="Meet No">
                            <input type="date" id="board_meet_date" name="board_meet_date" class="form-control">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4 border-success shadow-sm">
            <div class="card-header bg-success text-white fw-bold small d-flex justify-content-between align-items-center">
                <span><i class="fas fa-paperclip me-1"></i> REQUIRED WORKFLOW DOCUMENT ATTACHMENTS</span>
                <button type="button" class="btn btn-light btn-sm fw-bold text-success" id="add-more-attachments" title="Add Custom Document Row">
                    <i class="fas fa-plus-circle"></i> Add More Docs
                </button>
            </div>
            <div class="card-body bg-light">
                <div class="row g-3" id="static-attachments-container">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-secondary">Branch Proposal</label>
                        <input type="file" name="file_branch_proposal" class="form-control border-success">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-secondary">Committee Memo</label>
                        <input type="file" name="file_comm_memo" class="form-control border-success">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-secondary">Committee Minutes</label>
                        <input type="file" name="file_comm_minutes" class="form-control border-success">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-secondary">Office Note</label>
                        <input type="file" name="file_office_note" class="form-control border-success">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-secondary">Board Memo</label>
                        <input type="file" name="file_board_memo" class="form-control border-success">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-secondary">Board Minutes</label>
                        <input type="file" name="file_board_minutes" class="form-control border-success">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-secondary">Sanction Letter Document</label>
                        <input type="file" name="file_sanction_letter" class="form-control border-success">
                    </div>
                </div>
                <div id="dynamic-attachments-container" class="mt-2"></div>
            </div>
        </div>

        <h6 class="fw-bold mb-3"><i class="fas fa-list"></i> Approved Facilities</h6>
        <div id="facility-container">
            <div class="row g-2 mb-3 facility-row border-bottom pb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-bold">Facility Type</label>
                    <select name="facility_types[]" class="form-control facility-type-select" required>
                        <option value="">-- Select Facility Type --</option>
                        <?php foreach ($facility_options as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt['facility_type'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($opt['facility_type']); ?> (<?php echo htmlspecialchars($opt['facility_group']); ?>)
                            </option>
                        <?php endforeach; ?>
                        <option value="Others">Others</option>
                    </select>
                    
                    <div class="custom-override-group mt-2" style="display:none;">
                        <input type="text" name="facility_types_other[]" class="form-control facility-type-other mb-2" placeholder="Enter custom facility type">
                        <input type="text" name="facility_groups_other[]" class="form-control facility-group-other" placeholder="Enter custom facility group (e.g. Funded, Non-Funded)">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Facility As</label>
                    <select name="facility_as[]" class="form-control" required>
                        <?php echo renderFacilityAsOptions($facility_as_options); ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Approval By</label>
                    <select name="power_delegation[]" class="form-control" required>
                        <?php echo renderPowerDelegationOptions($power_delegation_options); ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Amount (BDT)</label>
                    <div class="amount-input-wrapper">
                        <input type="number" step="0.01" name="sanction_amounts[]" class="form-control amount-input" placeholder="0.00" required>
                        <div class="amount-word-display" style="display:none;">
                            <i class="fas fa-language"></i> <span class="amount-words">Zero Taka Only</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="button" class="btn btn-success w-100" id="add-more"><i class="fas fa-plus"></i></button>
                </div>
            </div>
        </div>

        <div class="mt-4 pt-3 border-top">
            <button type="submit" class="btn btn-primary px-5 shadow">Save All Entry</button>
            <a href="more_details.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">Details</a>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="edit_facility.php?id=<?php echo $id; ?>" class="btn btn-warning btn-m btn-hover-custom shadow-sm fw-bold px-3">
                <i class="fas fa-pen-nib me-1"></i> Update Facility</a>
            <?php endif; ?>
            <a href="index.php" class="btn btn-secondary btn-m">Home</a>
        </div>
    </form>
</div>

<script>
// Amount to Words Conversion Function
function numberToWords(num) {
    if (num === 0 || isNaN(num)) return 'Zero';
    
    const ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
                  'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
                  'Seventeen', 'Eighteen', 'Nineteen'];
    const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    
    function convertLessThanThousand(n) {
        if (n === 0) return '';
        if (n < 20) return ones[n] + ' ';
        if (n < 100) return tens[Math.floor(n / 10)] + ' ' + (n % 10 !== 0 ? ones[n % 10] + ' ' : '');
        return ones[Math.floor(n / 100)] + ' Hundred ' + (n % 100 !== 0 ? convertLessThanThousand(n % 100) : '');
    }
    
    let amount = Math.floor(num);
    let decimal = Math.round((num - amount) * 100);
    
    let result = '';
    
    // Convert crores (10 million)
    if (amount >= 10000000) {
        result += convertLessThanThousand(Math.floor(amount / 10000000)) + 'Crore ';
        amount %= 10000000;
    }
    // Convert lakhs (100,000)
    if (amount >= 100000) {
        result += convertLessThanThousand(Math.floor(amount / 100000)) + 'Lakh ';
        amount %= 100000;
    }
    // Convert thousands
    if (amount >= 1000) {
        result += convertLessThanThousand(Math.floor(amount / 1000)) + 'Thousand ';
        amount %= 1000;
    }
    // Convert hundreds
    if (amount >= 100) {
        result += convertLessThanThousand(Math.floor(amount / 100)) + 'Hundred ';
        amount %= 100;
    }
    // Convert remaining
    if (amount > 0) {
        result += convertLessThanThousand(amount);
    }
    
    result = result.trim();
    result += ' Taka';
    
    // Add decimal part
    if (decimal > 0) {
        let decimalWords = '';
        if (decimal < 20) {
            decimalWords = ones[decimal];
        } else {
            decimalWords = tens[Math.floor(decimal / 10)] + (decimal % 10 !== 0 ? ' ' + ones[decimal % 10] : '');
        }
        result += ' and ' + decimalWords + ' Paisa';
    } else {
        result += ' Only';
    }
    
    return result;
}

// Function to update amount words for a specific row
function updateAmountWords(inputElement) {
    const row = inputElement.closest('.facility-row');
    const amountDisplay = row.querySelector('.amount-word-display');
    const amountWordsSpan = row.querySelector('.amount-words');
    let amount = parseFloat(inputElement.value) || 0;
    
    if (amount > 0) {
        const words = numberToWords(amount);
        amountWordsSpan.textContent = words;
        amountDisplay.style.display = 'block';
    } else {
        amountDisplay.style.display = 'none';
    }
}

// Attach event listeners to all amount inputs
function attachAmountListeners() {
    document.querySelectorAll('.amount-input').forEach(input => {
        input.removeEventListener('input', function() {});
        input.addEventListener('input', function() {
            updateAmountWords(this);
        });
        if (input.value && parseFloat(input.value) > 0) {
            updateAmountWords(input);
        }
    });
}

// Global variable containing standard dropdown options to allow javascript replication loops
const facilityOptionsHtml = `<?php 
    $html = '<option value="">-- Select Facility Type --</option>';
    foreach ($facility_options as $opt) {
        $html .= '<option value="' . htmlspecialchars($opt['facility_type'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($opt['facility_type']) . ' (' . htmlspecialchars($opt['facility_group']) . ')</option>';
    }
    $html .= '<option value="Others">Others</option>';
    echo addslashes($html);
?>`;

const facilityAsOptionsHtml = `<?php echo addslashes(renderFacilityAsOptions($facility_as_options)); ?>`;
const powerDelegationOptionsHtml = `<?php echo addslashes(renderPowerDelegationOptions($power_delegation_options)); ?>`;

// Handles custom selection toggles for facility structural options
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('facility-type-select')) {
        const row = e.target.closest('.facility-row');
        const overrideGroup = row.querySelector('.custom-override-group');
        const typeOther = row.querySelector('.facility-type-other');
        const groupOther = row.querySelector('.facility-group-other');
        
        if (e.target.value === 'Others') {
            overrideGroup.style.display = 'block';
            if (typeOther) typeOther.required = true;
            if (groupOther) groupOther.required = true;
        } else {
            overrideGroup.style.display = 'none';
            if (typeOther) typeOther.required = false;
            if (groupOther) groupOther.required = false;
            if (typeOther) typeOther.value = '';
            if (groupOther) groupOther.value = '';
        }
    }
});

// Committee and Board configuration toggles
const commToggle = document.getElementById('comm-toggle');
const commGroup = document.getElementById('comm-meet-group');
commToggle && commToggle.addEventListener('click', function() {
    const isVisible = commGroup.style.display !== 'none';
    if (!isVisible) {
        commGroup.style.display = 'block';
        commToggle.classList.remove('btn-success');
        commToggle.classList.add('btn-danger');
        commToggle.innerHTML = '<i class="fas fa-minus"></i>';
    } else {
        commGroup.style.display = 'none';
        document.getElementById('comm_meet_no').value = '';
        document.getElementById('comm_meet_date').value = '';
        commToggle.classList.remove('btn-danger');
        commToggle.classList.add('btn-success');
        commToggle.innerHTML = '<i class="fas fa-plus"></i>';
    }
});

const boardToggle = document.getElementById('board-toggle');
const boardGroup = document.getElementById('board-meet-group');
boardToggle && boardToggle.addEventListener('click', function() {
    const isVisible = boardGroup.style.display !== 'none';
    if (!isVisible) {
        boardGroup.style.display = 'block';
        boardToggle.classList.remove('btn-success');
        boardToggle.classList.add('btn-danger');
        boardToggle.innerHTML = '<i class="fas fa-minus"></i>';
    } else {
        boardGroup.style.display = 'none';
        document.getElementById('board_meet_no').value = '';
        document.getElementById('board_meet_date').value = '';
        boardToggle.classList.remove('btn-danger');
        boardToggle.classList.add('btn-success');
        boardToggle.innerHTML = '<i class="fas fa-plus"></i>';
    }
});

// Facility duplication array handler
document.getElementById('add-more').onclick = function() {
    let container = document.getElementById('facility-container');
    let newRow = document.createElement('div');
    newRow.className = 'row g-2 mb-3 facility-row border-bottom pb-3';
    
    newRow.innerHTML = `
        <div class="col-md-4">
            <select name="facility_types[]" class="form-control facility-type-select" required>
                ${facilityOptionsHtml}
            </select>
            <div class="custom-override-group mt-2" style="display:none;">
                <input type="text" name="facility_types_other[]" class="form-control facility-type-other mb-2" placeholder="Enter custom facility type">
                <input type="text" name="facility_groups_other[]" class="form-control facility-group-other" placeholder="Enter custom facility group">
            </div>
        </div>
        <div class="col-md-2">
            <select name="facility_as[]" class="form-control" required>
                ${facilityAsOptionsHtml}
            </select>
        </div>
        <div class="col-md-2">
            <select name="power_delegation[]" class="form-control" required>
                ${powerDelegationOptionsHtml}
            </select>
        </div>
        <div class="col-md-3">
            <div class="amount-input-wrapper">
                <input type="number" step="0.01" name="sanction_amounts[]" class="form-control amount-input" placeholder="0.00" required>
                <div class="amount-word-display" style="display:none;">
                    <i class="fas fa-language"></i> <span class="amount-words">Zero Taka Only</span>
                </div>
            </div>
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-danger w-100 remove-row"><i class="fas fa-minus"></i></button>
        </div>`;
    container.appendChild(newRow);
    
    const newAmountInput = newRow.querySelector('.amount-input');
    if (newAmountInput) {
        newAmountInput.addEventListener('input', function() {
            updateAmountWords(this);
        });
    }
};

// Dynamic Attachment Logic Injection Engine
document.getElementById('add-more-attachments').onclick = function() {
    let container = document.getElementById('dynamic-attachments-container');
    let newRow = document.createElement('div');
    newRow.className = 'row g-3 mb-2 pt-2 border-top alignment-row';
    newRow.innerHTML = `
        <div class="col-md-5">
            <label class="form-label small fw-bold text-success">Custom Document Description</label>
            <input type="text" name="custom_descriptions[]" class="form-control border-success" placeholder="e.g. Audited Financial Statements" required>
        </div>
        <div class="col-md-6">
            <label class="form-label small fw-bold text-secondary">Upload File</label>
            <input type="file" name="custom_attachments[]" class="form-control border-success" required>
        </div>
        <div class="col-md-1 d-flex align-items-end">
            <button type="button" class="btn btn-outline-danger remove-attachment-row w-100" title="Delete Row">
                <i class="fas fa-trash"></i>
            </button>
        </div>`;
    container.appendChild(newRow);
};

// Shared global click element interceptor
document.addEventListener('click', function(e){
    if(e.target.closest('.remove-row')) {
        e.target.closest('.facility-row').remove();
    }
    if(e.target.closest('.remove-attachment-row')) {
        e.target.closest('.alignment-row').remove();
    }
});

// Initialize amount listeners on page load
document.addEventListener('DOMContentLoaded', function() {
    attachAmountListeners();
});
</script>
<?php
include 'footer.php';
?>
</body>
</html>