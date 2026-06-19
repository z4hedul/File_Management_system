<?php
session_start();
include 'db.php';
include 'header.php';

if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

$status_message = '';

// 1. Capture the explicit File ID parameter context passed from the URL parameters string
if (isset($_GET['file_id'])) {
    $preset_file_id = intval($_GET['file_id']);
} elseif (isset($_GET['id'])) {
    $preset_file_id = intval($_GET['id']);
} else {
    $preset_file_id = 0;
}

$preset_client_name  = '';
$preset_file_no      = '';
$preset_division     = '';
$preset_branch_name  = '';
$preset_cabinet_name = '';
$preset_shelf_name   = '';

// 2. Automatically retrieve target office file master profile context metrics
if ($preset_file_id > 0) {
    $client_stmt = $conn->prepare("SELECT client, file_no, division, branch_name, cabinet_name, shelf_name FROM office_files WHERE id = ? AND is_deleted = 0");
    $client_stmt->bind_param("i", $preset_file_id);
    $client_stmt->execute();
    $client_data = $client_stmt->get_result()->fetch_assoc();
    
    if ($client_data) {
        $preset_client_name  = $client_data['client'];
        $preset_file_no      = $client_data['file_no'];
        $preset_division     = $client_data['division'];
        $preset_branch_name  = $client_data['branch_name'];
        $preset_cabinet_name = $client_data['cabinet_name'];
        $preset_shelf_name   = $client_data['shelf_name'];
    }
}

// 3. Fetch Dynamic Facility Options from lookup database reference table
$facility_options = [];
$lookup_res = $conn->query("SELECT facility_name AS facility_type, facility_group FROM facilities_type WHERE is_active = 1 ORDER BY facility_group ASC, facility_name ASC");
if ($lookup_res && $lookup_res->num_rows > 0) {
    while ($row = $lookup_res->fetch_assoc()) {
        $facility_options[] = $row;
    }
}

// 4. Handle Form Post Commit Payload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_proposal'])) {
    
    // Debug output - Remove after testing
    // echo '<pre>POST DATA: '; print_r($_POST); echo '</pre>';
    // echo '<pre>FILES DATA: '; print_r($_FILES); echo '</pre>';
    // exit;
    
    $file_id             = intval($_POST['file_id'] ?? 0);
    $user_id             = intval($_POST['user_id'] ?? 0);
    $proposal_status     = 'Proposal In Preparation'; 
    $proposal_ref_suffix = isset($_POST['proposal_ref_suffix']) ? trim($_POST['proposal_ref_suffix']) : '';
    
    // Arrays containing repeated facilities datasets
    $proposal_types        = $_POST['proposal_type'] ?? [];
    $proposal_types_other  = $_POST['proposal_type_other'] ?? [];
    $proposal_groups_other = $_POST['proposal_group_other'] ?? [];
    $proposal_amounts      = $_POST['proposal_amount'] ?? [];

    if (empty($file_id) || empty($user_id) || empty($proposal_ref_suffix) || empty($proposal_types)) {
        $status_message = '
        <div class="alert alert-danger alert-dismissible fade show shadow-sm border-start border-danger border-3" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i> <strong>Validation Failure:</strong> Please verify that assignment fields and parameters have been completed.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>';
    } else {
        try {
            // Retrieve dynamic routing parameters
            $file_stmt = $conn->prepare("SELECT branch_code FROM office_files WHERE id = ? AND is_deleted = 0");
            $file_stmt->bind_param("i", $file_id);
            $file_stmt->execute();
            $file_res = $file_stmt->get_result(); 
            
            if ($file_res->num_rows === 0) {
                throw new Exception("Target master storage folder profile context missing or retracted.");
            }
            
            $file_row    = $file_res->fetch_assoc();
            $branch_code = $file_row['branch_code'];
            $currentYear = date('Y');
            $proposal_ref = "Branch/" . $branch_code . "/" . $currentYear . "/" . $proposal_ref_suffix; 

            // Validate strict uniqueness of the constructed proposal reference string sequence
            $dup_check = $conn->prepare("SELECT id FROM proposal_assignments WHERE proposal_ref = ?");
            $dup_check->bind_param("s", $proposal_ref);
            $dup_check->execute();
            if ($dup_check->get_result()->num_rows > 0) {
                throw new Exception("The explicit reference sequence identifier [ " . $proposal_ref . " ] is already registered within our assignment tracking system index rows.");
            }
            $dup_check->close();

            // Start transaction
            $conn->begin_transaction();

            // Loop through each submitted facility item row 
            $inserted_count = 0;
            foreach ($proposal_types as $index => $p_type) {
                $proposal_type = trim($p_type);
                $proposal_amount = isset($proposal_amounts[$index]) ? floatval($proposal_amounts[$index]) : 0.00;
                $facility_group = 'General';

                if (empty($proposal_type) || $proposal_amount <= 0) {
                    continue; // Skip faulty iterations
                }

                // Handle "Others" branch flow per specific index
                if ($proposal_type === 'Others') {
                    // Get the custom values from the other arrays
                    $p_type_other  = isset($proposal_types_other[$index]) ? trim($proposal_types_other[$index]) : '';
                    $p_group_other = isset($proposal_groups_other[$index]) ? trim($proposal_groups_other[$index]) : '';

                    if (!empty($p_type_other) && !empty($p_group_other)) {
                        $proposal_type = $p_type_other;
                        $facility_group = $p_group_other;

                        // Check if the custom facility type already exists in facilities_type table
                        $chk_lookup = $conn->prepare("SELECT id FROM facilities_type WHERE facility_name = ?");
                        $chk_lookup->bind_param("s", $proposal_type);
                        $chk_lookup->execute();
                        $lookup_result = $chk_lookup->get_result();
                        
                        if ($lookup_result->num_rows === 0) {
                            // Insert new facility type into facilities_type table
                            $ins_lookup = $conn->prepare("INSERT INTO facilities_type (facility_name, facility_group, is_active) VALUES (?, ?, 1)");
                            $ins_lookup->bind_param("ss", $proposal_type, $facility_group);
                            if (!$ins_lookup->execute()) {
                                throw new Exception("Failed to insert facility type: " . $ins_lookup->error);
                            }
                            $ins_lookup->close();
                        }
                        $chk_lookup->close();
                    } else {
                        continue; // Skip invalid dynamic values
                    }
                } else {
                    // Match standard structural groupings configuration dynamically
                    foreach ($facility_options as $opt) {
                        if ($opt['facility_type'] === $proposal_type) {
                            $facility_group = $opt['facility_group'];
                            break;
                        }
                    }
                }

                // Log the record to the assignments ledger - INSERT INTO proposal_assignments
                $stmt = $conn->prepare("INSERT INTO proposal_assignments (file_id, user_id, proposal_status, proposal_type, facility_group, proposal_amount, proposal_ref) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iisssds", $file_id, $user_id, $proposal_status, $proposal_type, $facility_group, $proposal_amount, $proposal_ref);
                
                if ($stmt->execute()) {
                    $inserted_count++;
                } else {
                    throw new Exception("Failed to insert into proposal_assignments: " . $stmt->error);
                }
                $stmt->close();
            }

            // Commit transaction if all inserts were successful
            if ($inserted_count > 0) {
                $conn->commit();
                $status_message = '
                <div class="alert alert-success alert-dismissible fade show shadow-sm border-start border-success border-3" role="alert">
                    <i class="fas fa-check-circle me-2"></i> <strong>Assignment Logged:</strong> ' . $inserted_count . ' facility tracking records initialized under reference signature: <span class="font-monospace fw-bold text-dark">' . htmlspecialchars($proposal_ref) . '</span>
                    <br><small class="text-muted"><i class="fas fa-spinner fa-spin mt-2 me-1"></i> Redirecting to assignments panel in 2 seconds...</small>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>';
                
                $status_message .= '
                <script type="text/javascript">
                    setTimeout(function() {
                        window.location.href = "proposal_assignments.php";
                    }, 2000);
                </script>';
                
                // Clear state values
                $preset_file_id = 0;
                $preset_client_name = $preset_file_no = $preset_division = $preset_branch_name = $preset_cabinet_name = $preset_shelf_name = '';
            } else {
                $conn->rollback();
                throw new Exception("No valid allocation structural item fields were completely evaluated or written onto database layers.");
            }

        } catch (Exception $e) {
            $conn->rollback();
            $status_message = '
            <div class="alert alert-danger alert-dismissible fade show shadow-sm border-start border-danger border-3" role="alert">
                <i class="fas fa-ban me-2"></i> <strong>Exception Encountered:</strong> ' . htmlspecialchars($e->getMessage()) . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Proposal Context Interface</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        .card { border-radius: 12px; }
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15);
        }
        .text-purple { color: #6f42c1; }
        .remove-facility-btn { transition: all 0.2s ease-in-out; }
        .remove-facility-btn:hover { background-color: #dc3545 !important; color: white !important; }
        .amount-word-display {
            font-size: 0.75rem;
            background: #f8f9fa;
            padding: 6px 10px;
            border-radius: 6px;
            margin-top: 5px;
            color: #28a745;
            font-weight: 500;
            border-left: 3px solid #28a745;
        }
        .amount-word-display i {
            margin-right: 5px;
            color: #28a745;
        }
    </style>
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-xl-10 col-lg-11">
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold text-dark mb-1">Dispatch Proposal Tracking Assignment</h3>
                    <p class="text-muted small mb-0">Bind physical location items directly to user terminal rows with full validation profiles.</p>
                </div>
                <a href="proposal_assignments.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                    <i class="fas fa-arrow-left me-1"></i> Go to Assignments List
                </a>
            </div>

            <?= $status_message; ?>

            <div class="card shadow border-0 mb-4">
                <div class="card-header bg-dark text-white p-3 d-flex align-items-center">
                    <i class="fas fa-file-signature text-warning me-2 fa-lg"></i>
                    <h5 class="mb-0 fw-bold">Assignment Parameters Configuration Mapping</h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="" id="assignForm">
                        
                        <input type="hidden" name="file_id" id="file_id" value="<?= intval($preset_file_id); ?>">

                        <div class="row g-3 mb-4">
                            <div class="col-md-12">
                                <label for="user_id" class="form-label fw-bold small text-secondary">Assign To (Target Desktop Handling Officer)</label>
                                <select name="user_id" id="user_id" class="form-select border-primary" required>
                                    <option value="" disabled selected>-- Select Designated Processing Officer --</option>
                                    <?php
                                    $users_query = $conn->query("SELECT id, full_name, employee_id, designation, role FROM users ORDER BY full_name ASC");
                                    while ($u = $users_query->fetch_assoc()) {
                                        echo '<option value="' . intval($u['id']) . '">' . htmlspecialchars($u['full_name']) . ' (' . htmlspecialchars(strtoupper($u['employee_id'])) . ') [' . htmlspecialchars(strtoupper($u['designation'])) . ']</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div class="bg-light p-3 rounded-3 border mb-4">
                            <h6 class="text-uppercase tracking-wider font-monospace text-muted small fw-bold mb-3"><i class="fas fa-info-circle me-1 text-primary"></i>Linked Physical Storage Location Properties</h6>
                            <div class="row g-3 text-dark small">
                                <div class="col-sm-4 col-6">
                                    <span class="text-secondary d-block font-monospace" style="font-size:0.75rem;">CLIENT ENTITY DIRECTORY</span>
                                    <strong id="meta_client_name" class="text-primary"><?= htmlspecialchars($preset_client_name ?: 'No directory file context assigned'); ?></strong>
                                </div>
                                <div class="col-sm-4 col-6">
                                    <span class="text-secondary d-block font-monospace" style="font-size:0.75rem;">FILE NO REGISTRY VALUE</span>
                                    <strong id="meta_file_no" class="text-dark"><?= htmlspecialchars($preset_file_no ?: 'N/A'); ?></strong>
                                </div>
                                <div class="col-sm-4 col-6">
                                    <span class="text-secondary d-block font-monospace" style="font-size:0.75rem;">DEPARTMENT DIVISION</span>
                                    <strong id="meta_division" class="text-purple"><?= htmlspecialchars($preset_division ?: 'N/A'); ?></strong>
                                </div>
                                <div class="col-sm-4 col-6">
                                    <span class="text-secondary d-block font-monospace" style="font-size:0.75rem;">BRANCH OFFICE LOCATION</span>
                                    <strong id="meta_branch_name" class="text-secondary"><?= htmlspecialchars($preset_branch_name ?: 'N/A'); ?></strong>
                                </div>
                                <div class="col-sm-4 col-6">
                                    <span class="text-secondary d-block font-monospace" style="font-size:0.75rem;">CABINET LOCATION INDICATOR</span>
                                    <strong id="meta_cabinet_name" class="text-info"><?= htmlspecialchars($preset_cabinet_name ?: 'N/A'); ?></strong>
                                </div>
                                <div class="col-sm-4 col-6">
                                    <span class="text-secondary d-block font-monospace" style="font-size:0.75rem;">SHELF LAYER PLACEMENT INDEX</span>
                                    <strong id="meta_shelf_name" class="text-success"><?= htmlspecialchars($preset_shelf_name ?: 'N/A'); ?></strong>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                                <label class="form-label fw-bold text-dark mb-0"><i class="fas fa-boxes text-primary me-1"></i>Requested Facility Item & Limit Allocation</label>
                                <button type="button" id="add_facility_row_btn" class="btn btn-sm btn-success rounded-circle shadow-sm" title="Add Another Facility Layout">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                            
                            <div id="facilities_master_container">
                                <div class="row g-3 facility-row-entry mb-3 align-items-start">
                                    <div class="col-md-5">
                                        <select name="proposal_type[]" class="form-select border-primary proposal-type-selector" required>
                                            <option value="" disabled selected>-- Select Dynamic Facility Variant --</option>
                                            <?php if (!empty($facility_options)): ?>
                                                <?php foreach ($facility_options as $opt): ?>
                                                    <option value="<?= htmlspecialchars($opt['facility_type'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <?= htmlspecialchars($opt['facility_type']); ?> (<?= htmlspecialchars($opt['facility_group']); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <option value="Continuous Loan">Continuous Loan</option>
                                                <option value="Demand Loan">Demand Loan</option>
                                                <option value="Term Loan">Term Loan</option>
                                            <?php endif; ?>
                                            <option value="Others">Others</option>
                                        </select>
                                        
                                        <div class="others-container mt-2 border p-2 bg-light rounded shadow-sm" style="display:none;">
                                            <label class="small fw-bold text-muted mb-1 font-monospace text-uppercase" style="font-size:10px;">Custom Lookup Mapping Parameters</label>
                                            <input type="text" name="proposal_type_other[]" class="form-control border-primary mb-2 proposal-type-other" placeholder="Facility Name (e.g. Work Capital Loan)">
                                            <select name="proposal_group_other[]" class="form-select border-primary proposal-group-other">
                                                <option value="" disabled selected>-- Select Facility Mode --</option>
                                                <option value="Funded">Funded</option>
                                                <option value="Non-Funded">Non-Funded</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-5">
                                        <div class="input-group">
                                            <span class="input-group-text bg-light font-monospace small">BDT</span>
                                            <input type="number" step="0.01" name="proposal_amount[]" class="form-control border-primary font-monospace fw-bold amount-input" placeholder="0.00" required>
                                        </div>
                                        <div class="amount-word-display" style="display:none;">
                                            <i class="fas fa-language"></i> <span class="amount-words">Zero Taka Only</span>
                                        </div>
                                    </div>

                                    <div class="col-md-2 text-end">
                                        <button type="button" class="btn btn-outline-secondary btn-sm remove-facility-btn rounded-pill d-none px-3">
                                            <i class="fas fa-trash-alt me-1"></i> Remove
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="proposal_ref_suffix" class="form-label fw-bold text-secondary">Branch Proposal Reference Sequence Suffix</label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark text-warning font-monospace small fw-bold" id="prefix_preview">Branch/---/<?= date('Y') ?>/</span>
                                <input type="text" name="proposal_ref_suffix" id="proposal_ref_suffix" class="form-control border-dark" placeholder="e.g. 042, PROP-99" required autocomplete="off">
                            </div>
                        </div>

                        <div class="d-grid mt-4">
                            <button type="submit" name="assign_proposal" class="btn btn-primary p-3 fw-bold text-uppercase shadow-sm">
                                <i class="fas fa-paper-plane me-2"></i>Dispatch & Assign Proposal Record
                            </button>
                        </div>

                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
// Amount to Words Conversion Function
function numberToWords(num) {
    if (num === 0) return 'Zero';
    
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
    const row = inputElement.closest('.facility-row-entry');
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

// Add event listeners to all amount inputs
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

// Function to ensure Others fields are properly named with correct indices
function fixOthersIndices() {
    const rows = document.querySelectorAll('.facility-row-entry');
    rows.forEach((row, idx) => {
        const typeSelect = row.querySelector('.proposal-type-selector');
        const othersContainer = row.querySelector('.others-container');
        const otherTypeInput = row.querySelector('.proposal-type-other');
        const otherGroupSelect = row.querySelector('.proposal-group-other');
        
        if (typeSelect && typeSelect.value === 'Others') {
            if (othersContainer) othersContainer.style.display = 'block';
            if (otherTypeInput) {
                otherTypeInput.setAttribute('required', 'required');
                otherTypeInput.name = `proposal_type_other[${idx}]`;
            }
            if (otherGroupSelect) {
                otherGroupSelect.setAttribute('required', 'required');
                otherGroupSelect.name = `proposal_group_other[${idx}]`;
            }
        } else {
            if (othersContainer) othersContainer.style.display = 'none';
            if (otherTypeInput) {
                otherTypeInput.removeAttribute('required');
                otherTypeInput.name = `proposal_type_other[${idx}]`;
                otherTypeInput.value = '';
            }
            if (otherGroupSelect) {
                otherGroupSelect.removeAttribute('required');
                otherGroupSelect.name = `proposal_group_other[${idx}]`;
                otherGroupSelect.value = '';
            }
        }
    });
}

document.addEventListener("DOMContentLoaded", function() {
    const fileIdInput     = document.getElementById("file_id");
    const prefixPreview   = document.getElementById("prefix_preview");
    const currentYear     = "<?= date('Y'); ?>";
    const masterContainer = document.getElementById("facilities_master_container");
    const addRowBtn       = document.getElementById("add_facility_row_btn");

    // Initial attachment of amount listeners
    attachAmountListeners();

    // Fetch dynamic prefix tracking metrics
    const initialFileId = fileIdInput.value;
    if (initialFileId && initialFileId !== "0") {
        fetch(`get_file_meta.php?id=${initialFileId}`)
            .then(response => response.json())
            .then(data => {
                if (!data.error && data.branch_code) {
                    prefixPreview.textContent = `Branch/${data.branch_code}/${currentYear}/`;
                }
            }).catch(err => { console.log("Prefix metadata hook tracking ready."); });
    }

    // Delegation handler for custom inputs setup
    masterContainer.addEventListener("change", function(e) {
        if (e.target && e.target.classList.contains("proposal-type-selector")) {
            const parentRow = e.target.closest(".facility-row-entry");
            const othersBox = parentRow.querySelector(".others-container");
            const otherText = parentRow.querySelector(".proposal-type-other");
            const otherGroup = parentRow.querySelector(".proposal-group-other");
            
            // Get the current row index
            const rows = document.querySelectorAll('.facility-row-entry');
            const currentIndex = Array.from(rows).indexOf(parentRow);

            if (e.target.value === "Others") {
                othersBox.style.display = "block";
                otherText.setAttribute("required", "required");
                otherGroup.setAttribute("required", "required");
                // Set proper name with index
                otherText.name = `proposal_type_other[${currentIndex}]`;
                otherGroup.name = `proposal_group_other[${currentIndex}]`;
            } else {
                othersBox.style.display = "none";
                otherText.removeAttribute("required");
                otherGroup.removeAttribute("required");
                otherText.value = "";
                otherGroup.value = "";
                otherText.name = `proposal_type_other[${currentIndex}]`;
                otherGroup.name = `proposal_group_other[${currentIndex}]`;
            }
        }
    });

    // Add new facility row
    addRowBtn.addEventListener("click", function() {
        const structuralRows = masterContainer.querySelectorAll(".facility-row-entry");
        const baseTargetRow = structuralRows[0];
        const clonedRow = baseTargetRow.cloneNode(true);
        
        // Clear all input values
        const typeSelector = clonedRow.querySelector(".proposal-type-selector");
        if (typeSelector) typeSelector.value = "";
        
        const amountInput = clonedRow.querySelector(".amount-input");
        if (amountInput) amountInput.value = "";
        
        // Reset amount display
        const amountDisplay = clonedRow.querySelector(".amount-word-display");
        if (amountDisplay) amountDisplay.style.display = "none";
        
        const amountWordsSpan = clonedRow.querySelector(".amount-words");
        if (amountWordsSpan) amountWordsSpan.textContent = "Zero Taka Only";
        
        // Reset others container
        const othersBox = clonedRow.querySelector(".others-container");
        if (othersBox) othersBox.style.display = "none";
        
        const otherText = clonedRow.querySelector(".proposal-type-other");
        const otherGroup = clonedRow.querySelector(".proposal-group-other");
        if (otherText) {
            otherText.removeAttribute("required");
            otherText.value = "";
        }
        if (otherGroup) {
            otherGroup.removeAttribute("required");
            otherGroup.value = "";
        }

        // Show remove button
        const deleteBtn = clonedRow.querySelector(".remove-facility-btn");
        if (deleteBtn) deleteBtn.classList.remove("d-none");

        masterContainer.appendChild(clonedRow);
        
        // Fix all indices after adding new row
        fixOthersIndices();
        
        // Attach event listener to new amount input
        if (amountInput) {
            amountInput.addEventListener('input', function() {
                updateAmountWords(this);
            });
        }
    });

    // Remove facility row
    masterContainer.addEventListener("click", function(e) {
        if (e.target && e.target.closest(".remove-facility-btn")) {
            const functionalRows = masterContainer.querySelectorAll(".facility-row-entry");
            if (functionalRows.length > 1) {
                e.target.closest(".facility-row-entry").remove();
                // Fix indices after removal
                fixOthersIndices();
            }
        }
    });
    
    // Initialize all Others indices on page load
    fixOthersIndices();
});
</script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>